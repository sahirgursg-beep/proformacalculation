<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ
require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

// Kategori ID kontrolü
$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($category_id === 0) {
    header("Location: categories.php");
    exit;
}

// Güncelleme İşlemi (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_category'])) {
    $category_name = trim($_POST['category_name']);
    $parent_id = !empty($_POST['parent_category_id']) ? $_POST['parent_category_id'] : null;
    $start = trim($_POST['stock_code_start']);
    $end = trim($_POST['stock_code_end']);

    // Bir kategori kendi kendisinin ebeveyni olamaz kontrolü
    if ($parent_id == $category_id) {
        $error_message = "A category cannot be its own parent.";
    } else {
        $stmt = $conn->prepare("UPDATE categories SET category_name = ?, parent_category_id = ?, stock_code_start = ?, stock_code_end = ? WHERE category_id = ?");
        if ($stmt->execute([$category_name, $parent_id, $start, $end, $category_id])) {
            $success_message = "Category updated successfully.";
        } else {
            $error_message = "Failed to update category.";
        }
    }
}

// Mevcut Kategori Bilgilerini Çek
$stmt = $conn->prepare("SELECT * FROM categories WHERE category_id = ?");
$stmt->execute([$category_id]);
$currentCategory = $stmt->fetch();

if (!$currentCategory) {
    header("Location: categories.php");
    exit;
}

// Üst Kategori (Parent) seçimi için ağaç yapısını tekrar çekiyoruz
function getCategoryTree($conn, $parent_id = null, $spacing = '', &$tree = []) {
    $stmt = $conn->prepare($parent_id === null ? "SELECT * FROM categories WHERE parent_category_id IS NULL" : "SELECT * FROM categories WHERE parent_category_id = ?");
    $stmt->execute($parent_id === null ? [] : [$parent_id]);
    foreach ($stmt->fetchAll() as $cat) {
        $cat['display_name'] = $spacing . $cat['category_name'];
        $tree[] = $cat;
        getCategoryTree($conn, $cat['category_id'], $spacing . '&nbsp;&nbsp;&nbsp;&nbsp;— ', $tree);
    }
    return $tree;
}
$categoryList = getCategoryTree($conn);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Edit Category: <?php echo htmlspecialchars($currentCategory['category_name']); ?></h2>
        <a href="categories.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Categories</a>
    </div>
    
    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="card p-4 shadow-sm" style="max-width: 600px;">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Category Name *</label>
                <input type="text" name="category_name" class="form-control" value="<?php echo htmlspecialchars($currentCategory['category_name']); ?>" required>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Parent Category</label>
                <select name="parent_category_id" class="form-select">
                    <option value="">None (Main Category)</option>
                    <?php foreach ($categoryList as $cat): ?>
                        <option value="<?php echo e($cat['category_id']); ?>" 
                            <?php echo ($currentCategory['parent_category_id'] == $cat['category_id']) ? 'selected' : ''; ?>>
                            <?php echo strip_tags($cat['display_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Stock Code Start</label>
                    <input type="text" name="stock_code_start" class="form-control" value="<?php echo htmlspecialchars($currentCategory['stock_code_start']); ?>" placeholder="e.g. ST10000">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Stock Code End</label>
                    <input type="text" name="stock_code_end" class="form-control" value="<?php echo htmlspecialchars($currentCategory['stock_code_end']); ?>" placeholder="e.g. ST19999">
                </div>
            </div>
            
            <button type="submit" name="update_category" class="btn btn-primary w-100"><i class="fa-solid fa-save"></i> Update Category</button>
        </form>
    </div>
</main>

<?php include 'includes/footer.php'; ?>