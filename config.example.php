<?php
// ============================================================
// Business Management System — Configuration
// ============================================================

define('DB_HOST', '');
define('DB_PORT', );
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', '');

define('BASE_URL', '');
define('APP_NAME', 'BUSINESS SYSTEM');

date_default_timezone_set('East africa Time');

ob_start();
session_start();

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
$conn->set_charset('utf8mb4');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/User.php';
