<?php
require_once __DIR__ . '/includes/auth.php';
if ($_SESSION['role'] !== 'SUPER_ADMIN') { die("Access Denied."); }

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/CostEngine.php'; 

$conn = (new Database())->getConnection();
$costEngine = new CostEngine($conn);

// ==========================================
// 1. EXCEL EXPORT (DIŞA AKTAR)
// ==========================================
if (isset($_GET['export'])) {   // gerçek .xlsx; içe aktarma ile aynı sütunlar
    require_once __DIR__ . '/classes/ImportService.php';
    @set_time_limit(300);
    (new ImportService($conn))->export('products', trim($_GET['search'] ?? ''));
}

// ==========================================
// 2. GÜVENLİ SİLME İŞLEMİ (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    try {
        $stmtImg = $conn->prepare("SELECT image FROM products WHERE product_id = ?");
        $stmtImg->execute([$del_id]);
        $img = $stmtImg->fetchColumn();
        if ($img && file_exists($img)) unlink($img);
        
        $stmt = $conn->prepare("DELETE FROM products WHERE product_id = ?");
        $stmt->execute([$del_id]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['success_msg'] = "Product deleted successfully.";
        } else {
            $_SESSION['error_msg'] = "Product could not be deleted or already removed.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_msg'] = "Cannot delete this product. It is being used in recipes or proformas.";
    }
    
    // SİHİRLİ DOKUNUŞ: Sayfayı temiz bir şekilde kendine yönlendir!
    header("Location: products.php");
    exit;
}

// Yönlendirme sonrası mesajları Session'dan al ve temizle
if (isset($_SESSION['success_msg'])) {
    $success_message = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}
if (isset($_SESSION['error_msg'])) {
    $error_message = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}
// ==========================================
// 3. DİNAMİK LİSTELEME VE SAYFALAMA (Stok Koduna Göre Sıralı)
// ==========================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));

$allowed_limits = [10, 25, 50, 100, 250, 500];
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $allowed_limits) ? (int)$_GET['limit'] : 25;
$offset = ($page - 1) * $limit;

$whereSql = "";
$params = [];
if (!empty($search)) {
    $whereSql = " WHERE p.stock_code LIKE ? OR p.stock_name LIKE ?";
    $searchWildcard = "%$search%";
    $params = [$searchWildcard, $searchWildcard];
}

$countStmt = $conn->prepare("SELECT COUNT(*) FROM products p" . $whereSql);
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Sıralama stok koduna (p.stock_code ASC) çevrildi
$query = "SELECT p.*, c.category_name, u.unit_name 
          FROM products p 
          LEFT JOIN categories c ON p.category_id = c.category_id 
          LEFT JOIN units u ON p.unit_id = u.unit_id 
          $whereSql 
          ORDER BY p.stock_code ASC 
          LIMIT $limit OFFSET $offset";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$costEngine->preload(array_column($products, 'product_id'));

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 style="color: var(--fr-walnut); font-weight: bold;">Products (Master Costs)</h2>
        
        <div class="d-flex gap-2">
            <a href="?export=xlsx" class="btn btn-success shadow-sm">
                <i class="fa-solid fa-file-excel me-1"></i> Export Excel
            </a>
            <a href="import.php?type=products" class="btn btn-warning shadow-sm fw-bold">
                <i class="fa-solid fa-upload me-1"></i> Import Excel
            </a>
            <a href="add_product.php" class="btn btn-primary shadow-sm">
                <i class="fa-solid fa-plus me-1"></i> Add Product
            </a>
        </div>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success shadow-sm'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger shadow-sm'>" . e($error_message) . "</div>"; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center border-bottom py-3">
            <h5 class="card-title mb-0" style="color: var(--fr-walnut);">All Products (<?php echo number_format($totalRows); ?> found)</h5>
            
            <form method="GET" class="d-flex align-items-center gap-2">
                <div class="d-flex align-items-center">
                    <span class="text-muted small me-2 text-nowrap">Show:</span>
                    <select name="limit" class="form-select form-select-sm" style="width: 75px;" onchange="this.form.submit()">
                        <?php foreach($allowed_limits as $l): ?>
                            <option value="<?php echo $l; ?>" <?php echo $limit == $l ? 'selected' : ''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="input-group input-group-sm" style="width: 250px;">
                    <input type="text" name="search" class="form-control" placeholder="Search Code or Name..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-outline-secondary"><i class="fa-solid fa-search"></i></button>
                    <?php if($search): ?>
                        <a href="products.php" class="btn btn-outline-danger" title="Clear Search"><i class="fa-solid fa-times"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="80" class="text-center">Image</th>
                            <th width="140">Stock Code</th>
                            <th>Stock Name</th>
                            <th>Category</th>
                            <th>Unit</th>
                            <th>Base Cost</th>
                            <th>List Price</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($products) > 0): ?>
                            <?php foreach ($products as $p): 
                                $calc = $costEngine->calculatePrices($p['product_id']);
                            ?>
                                <tr>
                                    <td class="text-center p-2">
                                        <?php if(!empty($p['image']) && file_exists($p['image'])): ?>
                                            <img src="<?php echo htmlspecialchars($p['image']); ?>" alt="Thumbnail" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px; border: 1px solid var(--fr-line);">
                                        <?php else: ?>
                                            <div class="bg-light text-muted d-inline-flex align-items-center justify-content-center" style="width: 50px; height: 50px; border-radius: 6px; border: 1px solid var(--fr-line);">
                                                <i class="fa-solid fa-box fs-4"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- STOK KODU AYRI SÜTUN -->
                                    <td>
                                        <span class="badge border bg-light text-dark fw-bold" style="font-size: 0.9rem; letter-spacing: 0.5px;">
                                            <?php echo htmlspecialchars($p['stock_code']); ?>
                                        </span>
                                    </td>

                                    <!-- ÜRÜN ADI AYRI SÜTUN -->
                                    <td>
                                        <span class="text-dark fw-semibold" style="font-size: 1rem;"><?php echo htmlspecialchars($p['stock_name']); ?></span>
                                    </td>
                                    
                                    <td><?php echo htmlspecialchars($p['category_name'] ?? '-'); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($p['unit_name'] ?? '-'); ?></span></td>
                                    
                                    <td class="text-danger fw-bold">$<?php echo number_format($calc['base_cost'], 2); ?></td>
                                    <td class="text-success fw-bold">$<?php echo number_format($calc['list_price'], 2); ?></td>
                                    
                                    <td class="text-end">
                                        <a href="edit_product.php?id=<?php echo e($p['product_id']); ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-edit"></i></a>
                                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this item?');">
    <input type="hidden" name="delete_id" value="<?php echo e($p['product_id']); ?>">
    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
</form>
                                          </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No products found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white py-3">
            <nav>
                <ul class="pagination justify-content-center mb-0 pagination-sm">
                    <?php
                        $urlParams = (!empty($search) ? '&search=' . urlencode($search) : '') . '&limit=' . $limit;
                        $adjacents = 2; 
                        
                        echo '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="?page=1' . $urlParams . '">First</a></li>';
                        echo '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="?page=' . ($page - 1) . $urlParams . '">&laquo;</a></li>';

                        for ($i = 1; $i <= $totalPages; $i++) {
                            if ($i == 1 || $i == $totalPages || ($i >= $page - $adjacents && $i <= $page + $adjacents)) {
                                echo '<li class="page-item ' . ($i == $page ? 'active' : '') . '"><a class="page-link" href="?page=' . $i . $urlParams . '">' . $i . '</a></li>';
                            } elseif ($i == $page - $adjacents - 1 || $i == $page + $adjacents + 1) {
                                echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                            }
                        }

                        echo '<li class="page-item ' . ($page >= $totalPages ? 'disabled' : '') . '"><a class="page-link" href="?page=' . ($page + 1) . $urlParams . '">&raquo;</a></li>';
                        echo '<li class="page-item ' . ($page >= $totalPages ? 'disabled' : '') . '"><a class="page-link" href="?page=' . $totalPages . $urlParams . '">Last</a></li>';
                    ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include 'includes/footer.php'; ?>