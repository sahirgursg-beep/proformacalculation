<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
$conn = (new Database())->getConnection();

$productId = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("
    SELECT u.unit_id, u.unit_name 
    FROM units u
    JOIN products p ON p.unit_id = u.unit_id WHERE p.product_id = ?
    UNION
    SELECT u.unit_id, u.unit_name
    FROM units u
    JOIN product_unit_conversions puc ON puc.unit_id = u.unit_id WHERE puc.product_id = ?
");
$stmt->execute([$productId, $productId]);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($units as $u) {
    echo '<option value="' . (int)$u['unit_id'] . '">' . e($u['unit_name']) . '</option>';
}