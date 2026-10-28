<?php
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <title><?php echo isset($page_title) ? e($page_title) . ' — ' . e(APP_NAME) : e(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/fonts.css?v=<?php echo filemtime('assets/css/fonts.css'); ?>">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css?v=<?php echo filemtime('assets/css/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=<?php echo filemtime('assets/css/font-awesome.min.css'); ?>">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap4.min.css?v=<?php echo filemtime('assets/css/dataTables.bootstrap4.min.css'); ?>">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime('assets/css/style.css'); ?>">
</head>
<body>
<script src="assets/js/jquery-3.2.1.min.js?v=<?php echo filemtime('assets/js/jquery-3.2.1.min.js'); ?>"></script>
<script src="assets/js/popper.min.js?v=<?php echo filemtime('assets/js/popper.min.js'); ?>"></script>
<script src="assets/js/bootstrap.min.js?v=<?php echo filemtime('assets/js/bootstrap.min.js'); ?>"></script>
<script src="assets/js/jquery.dataTables.min.js?v=<?php echo filemtime('assets/js/jquery.dataTables.min.js'); ?>"></script>
<script src="assets/js/dataTables.bootstrap4.min.js?v=<?php echo filemtime('assets/js/dataTables.bootstrap4.min.js'); ?>"></script>
<div class="app-shell">
