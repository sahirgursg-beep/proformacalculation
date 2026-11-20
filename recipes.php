<?php
require_once __DIR__ . '/includes/auth.php';
if ($_SESSION['role'] !== 'SUPER_ADMIN' && $_SESSION['role'] !== 'ARCHITECT_SALES') { 
    die("Access Denied."); 
}

require_once __DIR__ . '/config/database.php';
// YENİ: Maliyetlerin listede hatasız (Çoklu Birim & Multi-Level) görünmesi için CostEngine'i dahil ediyoruz
require_once __DIR__ . '/classes/CostEngine.php';

$conn = (new Database())->getConnection();
$costEngine = new CostEngine($conn);

// ==========================================
// 1. GÜVENLİ SİLME İŞLEMİ (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    try {
        $stmt = $conn->prepare("DELETE FROM product_recipes WHERE recipe_id = ?");
        $stmt->execute([$del_id]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['success_msg'] = "Recipe deleted successfully.";
        } else {
            $_SESSION['error_msg'] = "Recipe could not be deleted or already removed.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_msg'] = "Cannot delete this recipe. It might be in use.";
    }
    
    header("Location: recipes.php");
    exit;
}

if (isset($_SESSION['success_msg'])) {
    $success_message = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}
if (isset($_SESSION['error_msg'])) {
    $error_message = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// ==========================================
// 2. ARAMA FİLTRELERİ
// ==========================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$whereSql = "";
$params = [];
if (!empty($search)) {
    $whereSql = " WHERE p.stock_name LIKE ? OR pr.recipe_name LIKE ? OR p.stock_code LIKE ?";
    $searchWildcard = "%$search%";
    $params = [$searchWildcard, $searchWildcard, $searchWildcard];
}

// ==========================================
// 3. EXCEL EXPORT (İÇE AKTARMA ŞABLONUNA %100 UYGUN & BİRİM SÜTUNU EKLENDİ)
// ==========================================
if (isset($_GET['export'])) {   // gerçek .xlsx; içe aktarma ile aynı sütunlar
    require_once __DIR__ . '/classes/ImportService.php';
    @set_time_limit(300);
    (new ImportService($conn))->export('recipes', $search ?? '');
}

// ==========================================
// 4. DİNAMİK LİSTELEME VE SAYFALAMA
// ==========================================
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
$allowed_limits = [10, 25, 50, 100, 250, 500];
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $allowed_limits) ? (int)$_GET['limit'] : 25;
$offset = ($page - 1) * $limit;

$countStmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM product_recipes pr
    LEFT JOIN products p ON pr.product_id = p.product_id
    $whereSql
");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$query = "
    SELECT pr.recipe_id, pr.recipe_name, p.product_id, p.stock_code, p.stock_name, p.image, p.production_margin 
    FROM product_recipes pr
    LEFT JOIN products p ON pr.product_id = p.product_id
    $whereSql
    ORDER BY p.stock_code ASC
    LIMIT $limit OFFSET $offset
";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$costEngine->preload(array_column($recipes, 'product_id'));

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 style="color: var(--fr-walnut); font-weight: bold;">Product Recipes (BOM)</h2>
        
        <?php if ($_SESSION['role'] === 'SUPER_ADMIN'): ?>
        <div class="d-flex gap-2">
            <?php 
                $exportUrl = "?export=xlsx";
                if(!empty($search)) { $exportUrl .= "&search=" . urlencode($search); }
            ?>
            <a href="<?php echo $exportUrl; ?>" class="btn btn-success shadow-sm fw-bold">
                <i class="fa-solid fa-file-excel me-1"></i> Export Template
            </a>
            
            <a href="import.php?type=recipes" class="btn btn-warning shadow-sm fw-bold">
                <i class="fa-solid fa-upload me-1"></i> Import Recipes
            </a>
            <a href="add_recipe.php" class="btn btn-primary shadow-sm">
                <i class="fa-solid fa-plus me-1"></i> Add Recipe
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success shadow-sm'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger shadow-sm'>" . e($error_message) . "</div>"; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center border-bottom py-3">
            <h5 class="card-title mb-0" style="color: var(--fr-walnut);">All Recipes (<?php echo number_format($totalRows); ?> found)</h5>
            
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
                    <input type="text" name="search" class="form-control" placeholder="Search Product or Recipe..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-outline-secondary"><i class="fa-solid fa-search"></i></button>
                    <?php if($search): ?>
                        <a href="recipes.php" class="btn btn-outline-danger" title="Clear Search"><i class="fa-solid fa-times"></i></a>
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
                            <th width="140">Parent Code</th>
                            <th>Parent Name</th>
                            <th>Recipe Details</th>
                            <th>BOM Base Cost</th>
                            <th>Total Prod. Cost</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recipes) > 0): ?>
                            <?php foreach ($recipes as $r): 
                                $itemCountStmt = $conn->prepare("SELECT COUNT(*) FROM product_recipe_items WHERE recipe_id = ?");
                                $itemCountStmt->execute([$r['recipe_id']]);
                                $itemCount = $itemCountStmt->fetchColumn();

                                // YENİ: Maliyeti artık SQL SUM ile DEĞİL, doğrudan CostEngine sınıfımız üzerinden hesaplatıyoruz.
                                // Bu sayede Çoklu Seviye (Multi-Level BOM) ve Çoklu Birim (Multi-UoM) hataları sıfırlanıyor.
                                $prices = $costEngine->calculatePrices($r['product_id']);
                                $bom_base_cost = $prices['base_cost'];
                                $total_prod_cost = $prices['production_cost'];
                                $prod_margin_percent = (float)$r['production_margin'];
                            ?>
                                <tr>
                                    <td class="text-center p-2">
                                        <?php if(!empty($r['image']) && file_exists($r['image'])): ?>
                                            <img src="<?php echo htmlspecialchars($r['image']); ?>" alt="Thumbnail" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px; border: 1px solid var(--fr-line);">
                                        <?php else: ?>
                                            <div class="bg-light text-muted d-inline-flex align-items-center justify-content-center" style="width: 50px; height: 50px; border-radius: 6px; border: 1px solid var(--fr-line);">
                                                <i class="fa-solid fa-layer-group fs-4"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <span class="badge border bg-light text-dark fw-bold" style="font-size: 0.9rem; letter-spacing: 0.5px;">
                                            <?php echo htmlspecialchars($r['stock_code']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="text-dark fw-semibold" style="font-size: 1rem;"><?php echo htmlspecialchars($r['stock_name']); ?></span>
                                    </td>
                                    
                                    <td>
                                        <span class="badge bg-info text-dark border mb-1 d-inline-block"><i class="fa-solid fa-tag me-1"></i> <?php echo htmlspecialchars($r['recipe_name']); ?></span><br>
                                        <span class="text-muted small"><i class="fa-solid fa-cubes me-1"></i><?php echo $itemCount; ?> Materials</span>
                                    </td>
                                    
                                    <td class="text-danger fw-bold">
                                        $<?php echo number_format($bom_base_cost, 2); ?>
                                    </td>

                                    <td>
                                        <strong class="text-success" style="font-size: 1.1rem;">$<?php echo number_format($total_prod_cost, 2); ?></strong>
                                        <?php if($prod_margin_percent > 0): ?>
                                            <br><small class="text-muted" style="font-size: 0.75rem;">+ %<?php echo $prod_margin_percent; ?> Margin</small>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="text-end">
                                        <a href="edit_recipe.php?id=<?php echo e($r['recipe_id']); ?>" class="btn btn-sm btn-outline-primary" title="View/Edit Recipe"><i class="fa-solid fa-list-check"></i> Details</a>
                                        
                                        <?php if ($_SESSION['role'] === 'SUPER_ADMIN'): ?>
                                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this item?');">
                                            <input type="hidden" name="delete_id" value="<?php echo e($r['recipe_id']); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No recipes found. Start by adding one or importing!</td></tr>
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