<?php
require_once __DIR__ . '/bootstrap.php';
$fr_title = $page_title ?? 'Furniture Republic';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($fr_title); ?> · FR Calculation</title>
    <link rel="preload" href="assets/fonts/cardo-regular.woff" as="font" type="font/woff" crossorigin>
    <link rel="preload" href="assets/fonts/dmsans-var.woff" as="font" type="font/woff" crossorigin>
    <link rel="stylesheet" href="<?php echo vendor_url('bootstrap_css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/vendor/fontawesome/css/all.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo vendor_url('select2_css'); ?>">
    <link rel="stylesheet" href="<?php echo vendor_url('select2_theme'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/theme.css'); ?>">
    <script src="<?php echo asset('assets/vendor/jquery.min.js'); ?>"></script>
    <script src="<?php echo vendor_url('select2_js'); ?>"></script>
    <script>/* Select2 yüklenemezse (CDN erişilemez) sayfa çökmesin */ if (window.jQuery && !jQuery.fn.select2) { jQuery.fn.select2 = function () { return this; }; }</script>
    <script src="<?php echo asset('assets/js/product-picker.js'); ?>"></script>
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
</head>
<body>
