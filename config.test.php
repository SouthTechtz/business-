<?php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'u839560293_agensupply_test');
define('BASE_URL', 'http://localhost/business/');
define('APP_NAME', 'DEZL BUSINESS SYSTEM TEST');
define('TEST_MODE', true);
date_default_timezone_set('Africa/Dar_es_Salaam');
ob_start();
session_start();
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
$conn->set_charset('utf8mb4');
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/User.php';
