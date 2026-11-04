<?php
/**
 * Minimal Code 128 (subset B) barcode renderer.
 *
 * Produces an inline SVG so barcodes work without any external library or
 * internet connection. Only printable ASCII (32-126) is supported, which
 * covers product codes such as PRD-001 or SKU style values.
 */

if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'barcode-lib.php') {
    http_response_code(404);
    exit;
}

/** The 107 Code 128 element-width patterns (values 0-106). */
function barcode_code128_patterns() {
    static $patterns = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];
    return $patterns;
}

/** Whether a value can be encoded in Code 128 subset B. */
function barcode_is_encodable($value) {
    $value = (string)$value;
    if ($value === '') return false;
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
        $ord = ord($value[$i]);
        if ($ord < 32 || $ord > 126) return false;
    }
    return true;
}

/** Encode text into a string of 1 (bar) and 0 (space) modules. */
function barcode_code128_modules($value) {
    $value = (string)$value;
    if (!barcode_is_encodable($value)) return '';

    $patterns = barcode_code128_patterns();
    $values = [104]; // START B
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
        $values[] = ord($value[$i]) - 32;
    }

    $checksum = 104;
    $count = count($values);
    for ($i = 1; $i < $count; $i++) {
        $checksum += $values[$i] * $i;
    }
    $values[] = $checksum % 103;
    $values[] = 106; // STOP

    $modules = '';
    foreach ($values as $code) {
        $pattern = $patterns[$code];
        $elements = strlen($pattern);
        for ($i = 0; $i < $elements; $i++) {
            $width = (int)$pattern[$i];
            $modules .= str_repeat(($i % 2 === 0) ? '1' : '0', $width);
        }
    }
    return $modules;
}

/**
 * Render a Code 128 barcode as an SVG string.
 *
 * @param string $value        Value encoded in the barcode.
 * @param int    $moduleWidth  Width of one module in pixels.
 * @param int    $height       Bar height in pixels.
 * @param bool   $showText     Print the human-readable value under the bars.
 * @return string SVG markup, or an empty string when the value cannot be encoded.
 */
function barcode_svg($value, $moduleWidth = 2, $height = 70, $showText = true) {
    $value = (string)$value;
    $modules = barcode_code128_modules($value);
    if ($modules === '') return '';

    $moduleWidth = max(1, (int)$moduleWidth);
    $height = max(20, (int)$height);
    $quiet = 10; // quiet zone in modules on each side
    $barsLength = strlen($modules);
    $totalModules = $barsLength + ($quiet * 2);
    $width = $totalModules * $moduleWidth;
    $textHeight = $showText ? 20 : 0;
    $totalHeight = $height + $textHeight + 6;

    $bars = '';
    $i = 0;
    while ($i < $barsLength) {
        $run = 1;
        while ($i + $run < $barsLength && $modules[$i + $run] === $modules[$i]) {
            $run++;
        }
        if ($modules[$i] === '1') {
            $x = ($quiet + $i) * $moduleWidth;
            $bars .= '<rect x="' . $x . '" y="0" width="' . ($run * $moduleWidth) . '" height="' . $height . '"/>';
        }
        $i += $run;
    }

    $label = '';
    if ($showText) {
        $label = '<text x="' . ($width / 2) . '" y="' . ($height + 16) . '" text-anchor="middle"'
            . ' font-family="ui-monospace, Menlo, Consolas, monospace" font-size="12" fill="#111">'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</text>';
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $totalHeight . '"'
        . ' viewBox="0 0 ' . $width . ' ' . $totalHeight . '" role="img"'
        . ' aria-label="Barcode ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">'
        . '<rect width="100%" height="100%" fill="#ffffff"/>'
        . '<g fill="#111111">' . $bars . '</g>'
        . $label
        . '</svg>';
}
