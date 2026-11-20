<?php
// Eski adres: yeni içe aktarma sayfasına yönlendirir (önizleme + onay)
require_once __DIR__ . '/includes/auth.php';
header('Location: import.php?type=products');
exit;
