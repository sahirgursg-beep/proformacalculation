<?php
// Eski adres. Artık içe aktarma ile aynı sütunlarda gerçek .xlsx üretir.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/classes/ImportService.php';
@set_time_limit(300);
(new ImportService((new Database())->getConnection()))->export('recipes');
