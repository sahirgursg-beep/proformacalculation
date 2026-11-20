<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/CostEngine.php';

$dbClass = new Database();
$conn = $dbClass->getConnection();
$costEngine = new CostEngine($conn);

// NGN Çevirisi için Satış Kurunu Çek
$stmtRate = $conn->query("SELECT sale_rate FROM rate_exchange_history WHERE currency_code = 'NGN' ORDER BY created_at DESC LIMIT 1");
$ngn_sale_rate = (float)$stmtRate->fetchColumn();

// Müşteri Tiplerini Çek (Dinamik Sütunlar İçin)
$stmtTypes = $conn->query("SELECT * FROM customer_types ORDER BY discount_rate ASC");
$customerTypes = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Arama Filtreleri
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$whereSql = " WHERE 1=1 ";
$params = [];

// Arama Şartı (Kod, İsim veya Kategori Adına göre)
if (!empty($search)) {
    $whereSql .= " AND (p.stock_code LIKE ? OR p.stock_name LIKE ? OR c.category_name LIKE ?)";
    $searchWildcard = "%$search%";
    $params[] = $searchWildcard;
    $params[] = $searchWildcard;
    $params[] = $searchWildcard;
}

// KULLANICI YETKİ KONTROLÜ
if ($user_role === 'CATEGORY_SALES') {
    // Atanan kategoriler ve tüm alt kategorileri
    $stUc = $conn->prepare("SELECT category_id FROM user_categories WHERE user_id = ?");
    $stUc->execute([$user_id]);
    $allowedCats = category_with_descendants($conn, $stUc->fetchAll(PDO::FETCH_COLUMN)) ?: [0];
    $whereSql .= " AND p.category_id IN (" . implode(',', array_map('intval', $allowedCats)) . ")";
}

// ==========================================
// 1. EXCEL EXPORT (DIŞA AKTAR) - TARİH HATASI ÇÖZÜLDÜ
// ==========================================
if (isset($_GET['export']) && $_GET['export'] == 'xlsx') {   // gerçek .xlsx (eski HTML-.xls yerine)
    require_once __DIR__ . '/classes/ExcelIO.php';
    @set_time_limit(300);
    $headers = ['Stock Code', 'Stock Name', 'Category', 'List Price USD'];
    foreach ($customerTypes as $type) $headers[] = $type['type_name'] . ' (-' . floatval($type['discount_rate']) . '%) USD';
    $headers[] = 'List Price NGN';
    foreach ($customerTypes as $type) $headers[] = $type['type_name'] . ' (-' . floatval($type['discount_rate']) . '%) NGN';

    $expStmt = $conn->prepare("SELECT p.product_id, p.stock_code, p.stock_name, c.category_name FROM products p LEFT JOIN categories c ON p.category_id = c.category_id $whereSql ORDER BY p.stock_code ASC");
    $expStmt->execute($params);
    $costEngine->preloadAll();
    $rows = function () use ($expStmt, $costEngine, $customerTypes, $ngn_sale_rate) {
        while ($row = $expStmt->fetch(PDO::FETCH_ASSOC)) {
            $usd = $costEngine->calculatePrices($row['product_id'])['list_price'];
            $out = [$row['stock_code'], $row['stock_name'], $row['category_name'], round($usd, 2)];
            foreach ($customerTypes as $t) $out[] = round($usd * (1 - $t['discount_rate'] / 100), 2);
            $out[] = round($usd * $ngn_sale_rate / 1000) * 1000;
            foreach ($customerTypes as $t) $out[] = round($usd * (1 - $t['discount_rate'] / 100) * $ngn_sale_rate / 1000) * 1000;
            yield $out;
        }
    };
    $n = count($headers);
    ExcelIO::download('price_list_' . date('Ymd') . '.xlsx', $headers, $rows(), [
        'sheet' => 'Price list',
        'widths' => array_merge([12, 42, 24], array_fill(0, $n - 3, 16)),
        'numeric' => array_fill_keys(range(3, $n - 1), 1),
    ]);
}

// ==========================================
// 2. SAYFALAMA VE VERİ ÇEKME
// ==========================================
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
$limit = 50;
$offset = ($page - 1) * $limit;

$countQuery = "SELECT COUNT(p.product_id) FROM products p LEFT JOIN categories c ON p.category_id = c.category_id" . $whereSql;
$countStmt = $conn->prepare($countQuery);
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$dataQuery = "SELECT p.product_id, p.stock_code, p.stock_name, c.category_name 
              FROM products p 
              LEFT JOIN categories c ON p.category_id = c.category_id 
              $whereSql 
              ORDER BY p.stock_code ASC 
              LIMIT $limit OFFSET $offset";
$stmt = $conn->prepare($dataQuery);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$costEngine->preload(array_column($products, 'product_id'));

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 style="color: var(--fr-walnut); font-weight: bold;">Dynamic Price Lists</h2>
        
        <div class="d-flex align-items-center gap-3">
            <span class="badge bg-secondary fs-6 shadow-sm"><i class="fa-solid fa-money-bill-transfer me-1"></i> NGN Sale Rate: ₦<?php echo number_format($ngn_sale_rate, 2); ?></span>
            
            <?php 
                $exportUrl = "?export=xlsx";
                if(!empty($search)) { $exportUrl .= "&search=" . urlencode($search); }
            ?>
            <a href="<?php echo $exportUrl; ?>" class="btn btn-success shadow-sm fw-bold">
                <i class="fa-solid fa-file-excel me-1"></i> Export Excel
            </a>
        </div>
    </div>

    <?php if (!$ngn_sale_rate): ?>
        <div class="alert alert-danger shadow-sm"><i class="fa-solid fa-triangle-exclamation"></i> Warning: NGN Sale Rate is missing! Please update Exchange Rates.</div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center border-bottom py-3">
            <h5 class="card-title mb-0" style="color: var(--fr-walnut);">Search Products (<?php echo number_format($totalRows); ?> found)</h5>
            <form method="GET" class="d-flex">
                <input type="text" name="search" class="form-control form-control-sm me-2" placeholder="Search code, name, category..." value="<?php echo htmlspecialchars($search); ?>" style="width: 250px;">
                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-search"></i></button>
                <?php if($search): ?>
                    <a href="price_lists.php" class="btn btn-sm btn-outline-danger ms-1" title="Clear Search"><i class="fa-solid fa-times"></i></a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <ul class="nav nav-tabs" id="priceTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold text-success px-4" id="usd-tab" data-bs-toggle="tab" data-bs-target="#usd-list" type="button" role="tab"><i class="fa-solid fa-dollar-sign me-1"></i> USD Price List</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-primary px-4" id="ngn-tab" data-bs-toggle="tab" data-bs-target="#ngn-list" type="button" role="tab"><i class="fa-solid fa-naira-sign me-1"></i> NGN Price List</button>
        </li>
    </ul>

    <div class="tab-content bg-white border border-top-0 p-0 shadow-sm" id="priceTabsContent">
        
        <!-- USD TABLOSU -->
        <div class="tab-pane fade show active" id="usd-list" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.9em;">
                    <thead class="table-light">
                        <tr>
                            <th width="140">Stock Code</th>
                            <th>Stock Name</th>
                            <th>Category</th>
                            <th class="bg-success text-white fw-bold">List Price (USD)</th>
                            <?php foreach ($customerTypes as $type): ?>
                                <th class="text-muted"><?php echo htmlspecialchars($type['type_name']); ?><br><small>-<?php echo floatval($type['discount_rate']); ?>%</small></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($products) > 0): ?>
                            <?php foreach ($products as $p): 
                                $calc = $costEngine->calculatePrices($p['product_id']);
                                $usd_list_price = $calc['list_price'];
                            ?>
                                <tr>
                                    <td>
                                        <span class="badge border bg-light text-dark fw-bold" style="font-size: 0.9rem; letter-spacing: 0.5px;">
                                            <?php echo htmlspecialchars($p['stock_code']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-dark fw-semibold" style="font-size: 1rem;"><?php echo htmlspecialchars($p['stock_name']); ?></span>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($p['category_name']); ?></span></td>
                                    <td class="bg-light fw-bold text-success fs-6">$<?php echo number_format($usd_list_price, 2); ?></td>
                                    
                                    <?php foreach ($customerTypes as $type): 
                                        $discounted_usd = $usd_list_price * (1 - ($type['discount_rate'] / 100));
                                    ?>
                                        <td class="text-muted fw-semibold">$<?php echo number_format($discounted_usd, 2); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="<?php echo count($customerTypes) + 4; ?>" class="text-center text-muted py-4">No products found matching your search.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- NGN TABLOSU -->
        <div class="tab-pane fade" id="ngn-list" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.9em;">
                    <thead class="table-light">
                        <tr>
                            <th width="140">Stock Code</th>
                            <th>Stock Name</th>
                            <th>Category</th>
                            <th class="bg-primary text-white fw-bold">List Price (NGN)</th>
                            <?php foreach ($customerTypes as $type): ?>
                                <th class="text-muted"><?php echo htmlspecialchars($type['type_name']); ?><br><small>-<?php echo floatval($type['discount_rate']); ?>%</small></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($products) > 0): ?>
                            <?php foreach ($products as $p): 
                                $calc = $costEngine->calculatePrices($p['product_id']);
                                $usd_list_price = $calc['list_price'];
                                
                                $ngn_raw = $usd_list_price * $ngn_sale_rate;
                                $ngn_list_price = round($ngn_raw / 1000) * 1000;
                            ?>
                                <tr>
                                    <td>
                                        <span class="badge border bg-light text-dark fw-bold" style="font-size: 0.9rem; letter-spacing: 0.5px;">
                                            <?php echo htmlspecialchars($p['stock_code']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-dark fw-semibold" style="font-size: 1rem;"><?php echo htmlspecialchars($p['stock_name']); ?></span>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($p['category_name']); ?></span></td>
                                    <td class="bg-light fw-bold text-primary fs-6">₦<?php echo number_format($ngn_list_price, 0); ?></td>
                                    
                                    <?php foreach ($customerTypes as $type): 
                                        $discounted_usd = $usd_list_price * (1 - ($type['discount_rate'] / 100));
                                        $discounted_ngn_raw = $discounted_usd * $ngn_sale_rate;
                                        $discounted_ngn = round($discounted_ngn_raw / 1000) * 1000;
                                    ?>
                                        <td class="text-muted fw-semibold">₦<?php echo number_format($discounted_ngn, 0); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="<?php echo count($customerTypes) + 4; ?>" class="text-center text-muted py-4">No products found matching your search.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Sayfalama (Pagination) -->
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white py-3 border border-top-0 rounded-bottom shadow-sm">
        <nav>
            <ul class="pagination justify-content-center mb-0 pagination-sm">
                <?php
                    $searchParam = !empty($search) ? '&search=' . urlencode($search) : '';
                    $adjacents = 2; 
                    
                    echo '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="?page=1' . $searchParam . '">First</a></li>';
                    echo '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="?page=' . ($page - 1) . $searchParam . '">&laquo;</a></li>';

                    for ($i = 1; $i <= $totalPages; $i++) {
                        if ($i == 1 || $i == $totalPages || ($i >= $page - $adjacents && $i <= $page + $adjacents)) {
                            echo '<li class="page-item ' . ($i == $page ? 'active' : '') . '"><a class="page-link" href="?page=' . $i . $searchParam . '">' . $i . '</a></li>';
                        } elseif ($i == $page - $adjacents - 1 || $i == $page + $adjacents + 1) {
                            echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                        }
                    }

                    echo '<li class="page-item ' . ($page >= $totalPages ? 'disabled' : '') . '"><a class="page-link" href="?page=' . ($page + 1) . $searchParam . '">&raquo;</a></li>';
                    echo '<li class="page-item ' . ($page >= $totalPages ? 'disabled' : '') . '"><a class="page-link" href="?page=' . $totalPages . $searchParam . '">Last</a></li>';
                ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>