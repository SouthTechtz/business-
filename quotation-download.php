<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_invoices');

$tenant_id = (int)$current_user->tenant_id;
$id = (int)($_GET['id'] ?? 0);
$quotation = fetch_one(
    "SELECT q.*, c.first_name, c.last_name, c.company_name, c.email, c.phone, c.address, c.tax_id,
            w.name store_name, w.code store_code, s.name shop_name
       FROM quotations q
       LEFT JOIN contacts c ON c.contact_id = q.contact_id
       LEFT JOIN warehouses w ON w.warehouse_id = q.warehouse_id
       LEFT JOIN shops s ON s.shop_id = w.shop_id
      WHERE q.quotation_id = ? AND q.tenant_id = ?",
    $id,
    $tenant_id
);
if (!$quotation) {
    http_response_code(404);
    die('Quotation not found.');
}
if (!quotation_is_visible($quotation)) {
    http_response_code(403);
    die('You do not have access to the store for this quotation.');
}

$items = fetch_all(
    "SELECT qi.*, p.code FROM quotation_items qi
      LEFT JOIN products p ON p.product_id = qi.product_id AND p.tenant_id = qi.tenant_id
      WHERE qi.quotation_id = ? AND qi.tenant_id = ?
      ORDER BY qi.quotation_item_id",
    $id,
    $tenant_id
);
$settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ?", $tenant_id);
$company_name = $settings['company_name'] ?? system_title($tenant_id);
$company_address = $settings['company_address'] ?? '';
$company_email = $settings['company_email'] ?? '';
$company_phone = $settings['company_phone'] ?? '';
$cur = tenant_currency();

$display_status = (string)$quotation['status'];
if ($display_status !== 'converted' && !empty($quotation['valid_until']) && $quotation['valid_until'] < date('Y-m-d')) {
    $display_status = 'expired';
}

require_once __DIR__ . '/pdf-helpers.php';

$customer_name = trim(($quotation['first_name'] ?? '') . ' ' . ($quotation['last_name'] ?? '')) ?: ($quotation['company_name'] ?? 'Customer');
$store_name = $quotation['store_name'] ? trim(($quotation['shop_name'] ?? '') . ' / ' . $quotation['store_name'], ' /') : '—';

$chunks = array_chunk($items, 20);
if (!$chunks) {
    $chunks = [[]];
}
$last_page_index = count($chunks) - 1;
$pages = [];

// Prepare the logo for PDF embedding (converted to JPEG bytes).
$logo_data = null;
$logo_w = 0;
$logo_h = 0;
$logo_draw_w = 0;
$logo_draw_h = 0;
$logo_file = system_logo_path($tenant_id);
if ($logo_file !== '') {
    $logo_abs = __DIR__ . '/' . ltrim($logo_file, '/');
    $image_info = @getimagesize($logo_abs);
    if ($image_info) {
        $raw = (string)file_get_contents($logo_abs);
        if ($image_info[2] === IMAGETYPE_JPEG) {
            $logo_data = $raw;
        } elseif (function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring($raw);
            if ($im) {
                ob_start();
                imagejpeg($im, null, 88);
                $logo_data = ob_get_clean();
                imagedestroy($im);
            }
        }
        if ($logo_data !== null) {
            $logo_w = (int)$image_info[0];
            $logo_h = (int)$image_info[1];
            $scale = min(72 / max(1, $logo_w), 52 / max(1, $logo_h));
            $logo_draw_w = $logo_w * $scale;
            $logo_draw_h = $logo_h * $scale;
        }
    }
}
$header_x = $logo_data !== null ? 140 : 50;

foreach ($chunks as $page_index => $chunk) {
    $c = '';

    if ($page_index === 0) {
        if ($logo_data !== null) {
            $c .= pdf_image(50, 28, $logo_draw_w, $logo_draw_h);
        }
        $c .= pdf_text($header_x, 55, $company_name, 16, true);
        if ($company_address !== '') $c .= pdf_text($header_x, 72, $company_address, 9);
        $contact_line = trim($company_email . '  ' . $company_phone);
        if ($contact_line !== '') $c .= pdf_text($header_x, 86, $contact_line, 9);
        $c .= pdf_text(545, 55, 'QUOTATION', 18, true, 'right');
        $c .= pdf_text(545, 78, $quotation['quotation_number'], 12, true, 'right');
        $c .= pdf_text(545, 94, 'Issue Date: ' . date('M d, Y', strtotime($quotation['issue_date'])), 9, false, 'right');
        $c .= pdf_text(545, 107, 'Valid Until: ' . (!empty($quotation['valid_until']) ? date('M d, Y', strtotime($quotation['valid_until'])) : '—'), 9, false, 'right');
        $c .= pdf_text(545, 120, 'Status: ' . quotation_status_label($display_status), 9, false, 'right');
        $c .= pdf_line(50, 132, 545);

        $c .= pdf_text(50, 152, 'PREPARED FOR', 9, true);
        $c .= pdf_text(50, 168, $customer_name, 11, true);
        if (!empty($quotation['email'])) $c .= pdf_text(50, 182, $quotation['email'], 9);
        if (!empty($quotation['phone'])) $c .= pdf_text(50, 195, $quotation['phone'], 9);
        if (!empty($quotation['address'])) $c .= pdf_text(50, 208, $quotation['address'], 9);
        $c .= pdf_text(545, 152, is_godown_manager() ? 'GODOWN' : 'STORE', 9, true, 'right');
        $c .= pdf_text(545, 168, $store_name, 9, false, 'right');

        $table_top = 235;
    } else {
        $c .= pdf_text(50, 55, $company_name . ' — Quotation ' . $quotation['quotation_number'] . ' (continued)', 10, true);
        $table_top = 90;
    }

    // Table header
    $c .= pdf_line(50, $table_top, 545);
    $c .= pdf_text(52, $table_top - 13, '#', 9, true);
    $c .= pdf_text(70, $table_top - 13, 'ITEM', 9, true);
    $c .= pdf_text(330, $table_top - 13, 'QTY', 9, true, 'right');
    $c .= pdf_text(405, $table_top - 13, 'UNIT PRICE', 9, true, 'right');
    $c .= pdf_text(475, $table_top - 13, 'TAX', 9, true, 'right');
    $c .= pdf_text(545, $table_top - 13, 'TOTAL', 9, true, 'right');
    $c .= pdf_line(50, $table_top - 19, 545);

    $row_top = $table_top + 3;
    $line_no = $page_index * 20;
    foreach ($chunk as $item) {
        $line_no++;
        $description = (string)$item['description'];
        if (strlen($description) > 52) {
            $description = substr($description, 0, 49) . '...';
        }
        $c .= pdf_text(52, $row_top, (string)$line_no, 9);
        $c .= pdf_text(70, $row_top, $description, 9);
        $c .= pdf_text(330, $row_top, format_quantity($item['quantity']), 9, false, 'right');
        $c .= pdf_text(405, $row_top, money($item['unit_price'], $cur), 9, false, 'right');
        $c .= pdf_text(475, $row_top, money($item['tax_amount'], $cur), 9, false, 'right');
        $c .= pdf_text(545, $row_top, money($item['total'], $cur), 9, false, 'right');
        $row_top += 16;
    }
    $c .= pdf_line(50, $row_top, 545);

    if ($page_index === $last_page_index) {
        $total_top = $row_top + 22;
        $c .= pdf_text(380, $total_top, 'Subtotal', 10);
        $c .= pdf_text(545, $total_top, money($quotation['subtotal'], $cur), 10, false, 'right');
        if ((float)$quotation['tax_amount'] > 0) {
            $total_top += 16;
            $c .= pdf_text(380, $total_top, 'Tax', 10);
            $c .= pdf_text(545, $total_top, money($quotation['tax_amount'], $cur), 10, false, 'right');
        }
        $total_top += 18;
        $c .= pdf_line(380, $total_top - 9, 545, 0.6);
        $c .= pdf_text(380, $total_top + 4, 'Total', 11, true);
        $c .= pdf_text(545, $total_top + 4, money($quotation['total'], $cur), 11, true, 'right');

        $note_top = $total_top + 34;
        if (!empty($quotation['notes'])) {
            $c .= pdf_text(50, $note_top, 'Notes', 9, true);
            $note_top += 14;
            foreach (pdf_wrap((string)$quotation['notes']) as $line) {
                $c .= pdf_text(50, $note_top, $line, 9);
                $note_top += 12;
            }
            $note_top += 6;
        }
        if (!empty($quotation['terms'])) {
            $c .= pdf_text(50, $note_top, 'Terms', 9, true);
            $note_top += 14;
            foreach (pdf_wrap((string)$quotation['terms']) as $line) {
                $c .= pdf_text(50, $note_top, $line, 9);
                $note_top += 12;
            }
        }
        $c .= pdf_text(297, 805, 'This quotation is an offer and does not reserve stock.', 8, false, 'center');
    }

    $pages[] = $c;
}

$pdf = pdf_document($pages, 'Quotation ' . $quotation['quotation_number'], $company_name, $logo_data, $logo_w, $logo_h);

while (ob_get_level() > 0) {
    ob_end_clean();
}
$filename = 'quotation-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$quotation['quotation_number']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $pdf;
exit;
