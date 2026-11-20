<?php
require_once __DIR__ . '/includes/auth.php';

// SADECE SUPER_ADMIN VE ARCHITECT GİREBİLİR
if (!in_array($_SESSION['role'], ['SUPER_ADMIN', 'ARCHITECT'])) {
    die("Access Denied.");
}

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

// ==========================================
// 1. GÜVENLİ SİLME İŞLEMİ
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    try {
        $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
        $stmt->execute([$del_id]);
        if ($stmt->rowCount() > 0) $_SESSION['success_msg'] = "Category deleted successfully.";
        else $_SESSION['error_msg'] = "Category not found.";
    } catch (PDOException $e) {
        $_SESSION['error_msg'] = "Cannot delete this category. It is being used by products or has sub-categories.";
    }
    header("Location: categories.php");
    exit;
}

// ==========================================
// 2. KATEGORİ EKLEME İŞLEMİ (MANUEL)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_category'])) {
    $category_name = trim($_POST['category_name']);
    $parent_category_id = (int)$_POST['parent_category_id'];
    $stock_start = isset($_POST['stock_code_start']) ? trim($_POST['stock_code_start']) : null;
    $stock_end = isset($_POST['stock_code_end']) ? trim($_POST['stock_code_end']) : null;
    
    if (!empty($category_name)) {
        try {
            $stmt = $conn->prepare("INSERT INTO categories (category_name, parent_category_id, stock_code_start, stock_code_end) VALUES (?, ?, ?, ?)");
            $stmt->execute([$category_name, $parent_category_id, $stock_start, $stock_end]);
            $_SESSION['success_msg'] = "Category added successfully.";
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Failed to add category: " . $e->getMessage();
        }
        header("Location: categories.php");
        exit;
    }
}

// ==========================================
// 3. EXPORT EXCEL (NATIVE PHP METHOD - NO EXTERNAL LIB REQUIRED)
// ==========================================
// Excel: dışa aktarma / şablon / içe aktarma ortak sayfada (import.php)
if (isset($_GET['export'])) {
    header('Location: import.php?type=categories&' . ($_GET['export'] === 'template' ? 'template=1' : 'export=1'));
    exit;
}

if (isset($_SESSION['success_msg'])) { $success_message = $_SESSION['success_msg']; unset($_SESSION['success_msg']); }
if (isset($_SESSION['error_msg'])) { $error_message = $_SESSION['error_msg']; unset($_SESSION['error_msg']); }

// ==========================================
// 6. KATEGORİLERİ ÇEK VE HİYERARŞİK DÜZLEŞTİR (FLAT TREE)
// ==========================================
$stmt = $conn->query("SELECT * FROM categories ORDER BY parent_category_id ASC, category_name ASC");
$all_cats = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cats_by_parent = [];
foreach ($all_cats as $c) {
    $cats_by_parent[$c['parent_category_id']][] = $c;
}

$flat_tree = [];
function buildFlatTree($cats_by_parent, $parent_id = 0, $level = 0) {
    global $flat_tree;
    if (!isset($cats_by_parent[$parent_id])) return;
    foreach ($cats_by_parent[$parent_id] as $cat) {
        $cat['level'] = $level;
        $cat['has_children'] = isset($cats_by_parent[$cat['category_id']]);
        $flat_tree[] = $cat;
        buildFlatTree($cats_by_parent, $cat['category_id'], $level + 1);
    }
}
buildFlatTree($cats_by_parent, 0, 0);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Select2 CSS/JS for Searchable Dropdown -->

<style>
    .cat-row { transition: background-color 0.1s; }
    .cat-row:hover { background-color: #F6F1E8; }
    .toggle-icon { width: 24px; text-align: center; color: #adb5bd; cursor: pointer; transition: transform 0.2s; }
    .toggle-icon.open { transform: rotate(90deg); color: var(--fr-walnut); }
    .level-0 { font-weight: bold; color: var(--fr-walnut); }
    .level-1 { color: var(--fr-ink); }
    .level-2 { color: var(--fr-oak); }
    .import-box { border: 2px dashed var(--fr-line-2); padding: 15px; border-radius: 8px; text-align: center; background-color: #F6F1E8; }
</style>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Product Categories</h2>
        
        <!-- EXCEL EXPORT BUTONU -->
        <div>
            <a href="categories.php?export=excel" class="btn btn-outline-success shadow-sm">
                <i class="fa-solid fa-file-excel me-2"></i> Export to Excel
            </a>
        </div>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success shadow-sm'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger shadow-sm'>" . e($error_message) . "</div>"; ?>

    <div class="row">
        <!-- SOL PANEL: EKLEME & İÇE AKTARMA -->
        <div class="col-md-4">
            
            <!-- Manuel Ekleme Kartı -->
            <div class="card shadow-sm mb-4 border-primary">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="fa-solid fa-folder-plus me-2"></i>Add New Category</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Category Name *</label>
                            <input type="text" name="category_name" class="form-control" placeholder="e.g. Sofa Sets" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Parent Category</label>
                            <select name="parent_category_id" class="form-select select2-init" required>
                                <option value="0">-- CREATE AS MAIN CATEGORY --</option>
                                <?php foreach($flat_tree as $cat): ?>
                                    <option value="<?php echo e($cat['category_id']); ?>">
                                        <?php echo str_repeat('&nbsp;&nbsp;&nbsp;', $cat['level']) . ($cat['level'] > 0 ? '└ ' : '') . htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-6">
                                <label class="form-label text-muted small fw-bold mb-1">Code Start</label>
                                <input type="text" name="stock_code_start" class="form-control form-control-sm" placeholder="ST100">
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small fw-bold mb-1">Code End</label>
                                <input type="text" name="stock_code_end" class="form-control form-control-sm" placeholder="ST199">
                            </div>
                        </div>

                        <button type="submit" name="add_category" class="btn btn-primary w-100 fw-bold">
                            Save Category
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- EXCEL İçe Aktarma (Import) Kartı -->
            <div class="card shadow-sm border-success">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0"><i class="fa-solid fa-file-import me-2"></i>Import from Excel</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted">Add or update many categories at once. You will see a preview before anything changes.</p>
                    <a href="import.php?type=categories" class="btn btn-success w-100 mb-2">Import categories from Excel</a>
                    <div class="text-center"><a href="import.php?type=categories&amp;template=1" class="small"><i class="fa-solid fa-download me-1"></i> Download template</a></div>
                </div>
            </div>

        </div>

        <!-- SAĞ PANEL: KATEGORİ LİSTESİ -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Category List</h5>
                    <button type="button" class="btn btn-sm btn-outline-secondary fw-bold shadow-sm" id="expandAllBtn">Expand All</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="75%">Category Name & Stock Codes</th>
                                    <th width="25%" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($flat_tree) > 0): ?>
                                    <?php foreach ($flat_tree as $cat): 
                                        $padding = $cat['level'] * 2;
                                        $is_hidden = $cat['level'] > 0 ? 'display: none;' : ''; 
                                    ?>
                                        <tr class="cat-row parent-<?php echo e($cat['parent_category_id']); ?>" data-id="<?php echo e($cat['category_id']); ?>" style="<?php echo $is_hidden; ?>">
                                            <td style="padding-left: <?php echo $padding + 1; ?>rem;">
                                                
                                                <?php if ($cat['has_children']): ?>
                                                    <i class="fa-solid fa-chevron-right toggle-icon me-1" onclick="toggleChildren(<?php echo e($cat['category_id']); ?>, this)"></i>
                                                <?php else: ?>
                                                    <?php if($cat['level'] > 0): ?>
                                                        <i class="fa-solid fa-turn-up fa-rotate-90 text-muted me-2" style="font-size: 0.8rem; opacity: 0.5;"></i>
                                                    <?php else: ?>
                                                        <span style="display:inline-block; width: 24px;"></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <span class="level-<?php echo e($cat['level']); ?> fs-6 align-middle">
                                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                                </span>

                                                <?php if (!empty($cat['stock_code_start'])): ?>
                                                    <small class="text-muted ms-2 align-middle" style="font-size: 0.75rem; letter-spacing: 0.5px; background-color: #F6F1E8; padding: 2px 6px; border-radius: 4px; border: 1px solid #eee;">
                                                        <i class="fa-solid fa-barcode me-1 opacity-75"></i> 
                                                        <?php 
                                                            echo htmlspecialchars($cat['stock_code_start']);
                                                            if (!empty($cat['stock_code_end'])) {
                                                                echo ' - ' . htmlspecialchars($cat['stock_code_end']);
                                                            }
                                                        ?>
                                                    </small>
                                                <?php endif; ?>

                                            </td>
                                            <td class="text-end">
                                                <a href="edit_category.php?id=<?php echo e($cat['category_id']); ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-edit"></i></a>
                                                <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this category?');">
                                                    <input type="hidden" name="delete_id" value="<?php echo e($cat['category_id']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="2" class="text-center text-muted py-5"><i class="fa-solid fa-folder-open fa-2x mb-2 opacity-50"></i><br>No categories found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
$(document).ready(function() {
    $('.select2-init').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: "Search and select a category..."
    });

    let expanded = false;
    $('#expandAllBtn').click(function() {
        if (!expanded) {
            $('.cat-row').show();
            $('.toggle-icon').addClass('open');
            $(this).text('Collapse All');
            expanded = true;
        } else {
            $('.cat-row').hide();
            $('.parent-0').show();
            $('.toggle-icon').removeClass('open');
            $(this).text('Expand All');
            expanded = false;
        }
    });
});

function toggleChildren(parentId, iconElement) {
    const childrenRows = document.querySelectorAll('.parent-' + parentId);
    iconElement.classList.toggle('open');
    const isOpening = iconElement.classList.contains('open');

    childrenRows.forEach(row => {
        if (isOpening) {
            row.style.display = 'table-row';
        } else {
            row.style.display = 'none';
            const rowId = row.getAttribute('data-id');
            const subIcon = row.querySelector('.toggle-icon');
            if (subIcon && subIcon.classList.contains('open')) {
                toggleChildren(rowId, subIcon);
            }
        }
    });
}
</script>
<?php include 'includes/footer.php'; ?>