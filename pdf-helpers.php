<?php
/**
 * Minimal PDF writer (A4, Helvetica) shared by printable/downloadable documents.
 * No external library required.
 */

function pdf_num($value): string
{
    $out = number_format((float)$value, 2, '.', '');
    $out = rtrim(rtrim($out, '0'), '.');
    return $out === '' || $out === '-0' ? '0' : $out;
}

function pdf_escape(string $text): string
{
    $text = str_replace(["\r", "\n"], ' ', $text);
    $text = preg_replace('/[^\x20-\x7E]/', '?', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string)$text);
}

function pdf_text($x, $top, $text, $size = 10, $bold = false, $align = 'left'): string
{
    $text = pdf_escape((string)$text);
    $width = strlen($text) * $size * 0.5;
    if ($align === 'right') {
        $x -= $width;
    } elseif ($align === 'center') {
        $x -= $width / 2;
    }
    $y = 842 - $top;
    return 'BT /' . ($bold ? 'F2' : 'F1') . ' ' . pdf_num($size) . ' Tf '
        . pdf_num($x) . ' ' . pdf_num($y) . ' Td (' . $text . ') Tj ET' . "\n";
}

function pdf_line($x1, $top, $x2, $gray = 0.82): string
{
    $y = 842 - $top;
    return pdf_num($gray) . ' G ' . pdf_num($x1) . ' ' . pdf_num($y) . ' m '
        . pdf_num($x2) . ' ' . pdf_num($y) . ' l S' . "\n";
}

function pdf_image($x, $top, $w, $h): string
{
    $y = 842 - $top - $h;
    return 'q ' . pdf_num($w) . ' 0 0 ' . pdf_num($h) . ' ' . pdf_num($x) . ' ' . pdf_num($y) . " cm /Logo Do Q\n";
}

function pdf_wrap(string $text, int $max_chars = 92): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $out = [];
    foreach (explode("\n", $text) as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            $out[] = '';
            continue;
        }
        $line = '';
        foreach (preg_split('/\s+/', $paragraph) as $word) {
            if ($line === '') {
                $line = $word;
            } elseif (strlen($line) + 1 + strlen($word) <= $max_chars) {
                $line .= ' ' . $word;
            } else {
                $out[] = $line;
                $line = $word;
            }
        }
        if ($line !== '') $out[] = $line;
    }
    return $out;
}

function pdf_document(array $pages, string $title, string $producer, ?string $logoData = null, int $logoW = 0, int $logoH = 0): string
{
    $count = count($pages);
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    for ($i = 0; $i < $count; $i++) {
        $kids[] = (5 + $i * 2) . ' 0 R';
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $count . ' >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

    $image_obj = $logoData !== null ? (5 + $count * 2) : null;
    $info_obj = 5 + $count * 2 + ($logoData !== null ? 1 : 0);

    for ($i = 0; $i < $count; $i++) {
        $page_obj = 5 + $i * 2;
        $content_obj = 6 + $i * 2;
        $resources = '/Font << /F1 3 0 R /F2 4 0 R >>';
        if ($image_obj !== null) {
            $resources .= ' /XObject << /Logo ' . $image_obj . ' 0 R >>';
        }
        $objects[$page_obj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
            . '/Resources << ' . $resources . ' >> /Contents ' . $content_obj . ' 0 R >>';
        $stream = $pages[$i];
        $objects[$content_obj] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
    }

    if ($logoData !== null) {
        $objects[$image_obj] = '<< /Type /XObject /Subtype /Image /Width ' . $logoW . ' /Height ' . $logoH
            . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($logoData)
            . " >>\nstream\n" . $logoData . "\nendstream";
    }

    $objects[$info_obj] = '<< /Title (' . pdf_escape($title) . ') /Producer (' . pdf_escape($producer) . ') >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $size = count($objects) + 1;
    $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
    for ($number = 1; $number < $size; $number++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
    }
    $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R /Info " . $info_obj . " 0 R >>\n";
    $pdf .= "startxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

/**
 * Convert the tenant logo to JPEG bytes for embedding in a PDF.
 *
 * @return array{data:?string,width:int,height:int,draw_w:float,draw_h:float}
 */
function pdf_logo_data(int $tenant_id): array
{
    $result = ['data' => null, 'width' => 0, 'height' => 0, 'draw_w' => 0.0, 'draw_h' => 0.0];
    $logo_file = system_logo_path($tenant_id);
    if ($logo_file === '') return $result;

    $logo_abs = __DIR__ . '/' . ltrim($logo_file, '/');
    $image_info = @getimagesize($logo_abs);
    if (!$image_info) return $result;

    $raw = (string)file_get_contents($logo_abs);
    $data = null;
    if ($image_info[2] === IMAGETYPE_JPEG) {
        $data = $raw;
    } elseif (function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring($raw);
        if ($im) {
            ob_start();
            imagejpeg($im, null, 88);
            $data = ob_get_clean();
            imagedestroy($im);
        }
    }
    if ($data === null) return $result;

    $width = (int)$image_info[0];
    $height = (int)$image_info[1];
    $scale = min(72 / max(1, $width), 52 / max(1, $height));
    return [
        'data' => $data,
        'width' => $width,
        'height' => $height,
        'draw_w' => $width * $scale,
        'draw_h' => $height * $scale,
    ];
}
