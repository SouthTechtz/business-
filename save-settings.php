<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_settings');

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['company_email'] ?? '');
$phone = trim($_POST['company_phone'] ?? '');
$address = trim($_POST['company_address'] ?? '');
$currency = trim($_POST['default_currency'] ?? 'TZS');
$invoice_prefix = trim($_POST['invoice_prefix'] ?? 'INV-');
$invoice_next_number = (int)($_POST['invoice_next_number'] ?? 1001);
$apply_tax = !empty($_POST['apply_tax']) ? 1 : 0;

if ($name === '') {
    flash_set('danger', 'Company name is required.');
    redirect('settings.php');
}

$conn->begin_transaction();
try {
    $settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ?", $current_user->tenant_id);
    $logo_path = $settings['logo_path'] ?? null;

    if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
        if ($logo_path && str_starts_with($logo_path, 'uploads/') && file_exists($logo_path)) {
            @unlink($logo_path);
        }
        $logo_path = null;
    } elseif (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp', 'image/gif'];
        if (!in_array($_FILES['logo']['type'], $allowed) || $_FILES['logo']['size'] > 2 * 1024 * 1024) {
            $conn->rollback();
            flash_set('danger', 'Logo must be a PNG, JPG, SVG, WEBP or GIF image under 2 MB.');
            redirect('settings.php');
        }
        $ext = match ($_FILES['logo']['type']) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/svg+xml' => 'svg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        };
        if (!is_dir('uploads')) {
            mkdir('uploads', 0777, true);
        }
        $filename = 'logo_' . $current_user->tenant_id . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($_FILES['logo']['tmp_name'], 'uploads/' . $filename)) {
            $conn->rollback();
            flash_set('danger', 'Could not save the logo file.');
            redirect('settings.php');
        }
        if ($logo_path && str_starts_with($logo_path, 'uploads/') && file_exists($logo_path)) {
            @unlink($logo_path);
        }
        $logo_path = 'uploads/' . $filename;
    }

    if ($settings) {
        $stmt = $conn->prepare(
            "UPDATE system_settings SET company_name = ?, company_email = ?, company_phone = ?, company_address = ?, default_currency = ?, invoice_prefix = ?, invoice_next_number = ?, apply_tax = ?, logo_path = ? WHERE tenant_id = ?"
        );
        $stmt->bind_param('ssssssiisi', $name, $email, $phone, $address, $currency, $invoice_prefix, $invoice_next_number, $apply_tax, $logo_path, $current_user->tenant_id);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO system_settings (tenant_id, company_name, company_email, company_phone, company_address, default_currency, invoice_prefix, invoice_next_number, apply_tax, logo_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issssssiis', $current_user->tenant_id, $name, $email, $phone, $address, $currency, $invoice_prefix, $invoice_next_number, $apply_tax, $logo_path);
        $stmt->execute();
    }
    $stmt = $conn->prepare("UPDATE tenants SET name = ?, email = ?, phone = ?, address = ?, currency_code = ? WHERE tenant_id = ?");
    $stmt->bind_param('sssssi', $name, $email, $phone, $address, $currency, $current_user->tenant_id);
    $stmt->execute();
    $conn->commit();
    flash_set('success', 'Settings saved successfully.');
} catch (Exception $ex) {
    $conn->rollback();
    flash_set('danger', 'Could not save settings: ' . $ex->getMessage());
}
redirect('settings.php');
