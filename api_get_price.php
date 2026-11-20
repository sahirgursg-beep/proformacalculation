<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/classes/CostEngine.php';
header('Content-Type: application/json');

$conn = (new Database())->getConnection();

$product_id  = (int)($_GET['product_id'] ?? 0);
$customer_id = (int)($_GET['customer_id'] ?? 0);
$currency    = ($_GET['currency'] ?? 'USD') === 'NGN' ? 'NGN' : 'USD';

if ($product_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Product ID missing']);
    exit;
}

// İndirim: gönderilmediyse müşteri tipinden al
if (isset($_GET['discount']) && $_GET['discount'] !== '') {
    $discount_rate = max(0, min(100, (float)$_GET['discount']));
} else {
    $discount_rate = 0;
    if ($customer_id > 0) {
        $stmt = $conn->prepare("SELECT ct.discount_rate FROM customers c LEFT JOIN customer_types ct ON c.customer_type_id = ct.type_id WHERE c.customer_id = ?");
        $stmt->execute([$customer_id]);
        $discount_rate = (float)$stmt->fetchColumn();
    }
}

$ngn_rate = 1;
if ($currency === 'NGN') {
    $ngn_rate = (float)$conn->query("SELECT sale_rate FROM rate_exchange_history WHERE currency_code = 'NGN' ORDER BY created_at DESC, id DESC LIMIT 1")->fetchColumn();
}

$engine = new CostEngine($conn);
$q = $engine->quote($product_id, $discount_rate, $currency, $ngn_rate);

echo json_encode([
    'success'          => true,
    'price'            => $q['price'],
    'min_price'        => $q['min_price'],
    'currency'         => $currency,
    'discount_applied' => $discount_rate,
]);
