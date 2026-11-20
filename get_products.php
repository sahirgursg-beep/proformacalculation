<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

$conn = (new Database())->getConnection();
$term = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];
// Her kelime kodda veya isimde geçmeli ("kitchen 15" → ikisini de içeren ürünler)
foreach (array_slice(preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY), 0, 5) as $w) {
    $where[] = '(stock_code LIKE ? OR stock_name LIKE ?)';
    $params[] = "%$w%"; $params[] = "%$w%";
}
// Kategori satış temsilcisi sadece kendi kategorilerini görür
if (current_role() === 'CATEGORY_SALES') {
    $where[] = 'category_id IN (SELECT category_id FROM user_categories WHERE user_id = ?)';
    $params[] = (int)$_SESSION['user_id'];
}
$sql = "SELECT product_id, stock_code, stock_name FROM products"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY (stock_code = ?) DESC, stock_code ASC LIMIT 30";
$params[] = $term;

$stmt = $conn->prepare($sql);
$stmt->execute($params);

$results = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $results[] = [
        'id'   => (int)$p['product_id'],
        'text' => $p['stock_code'] . ' · ' . $p['stock_name'],
        'code' => $p['stock_code'],
        'name' => $p['stock_name'],
    ];
}
echo json_encode(['results' => $results]);
