<?php
require_once __DIR__ . '/includes/auth.php';
if ($_SESSION['role'] !== 'SUPER_ADMIN' && $_SESSION['role'] !== 'ARCHITECT') { 
    die("Access Denied."); 
}


require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

// Hangi Sihirbazın adımlarını düzenliyoruz?
$wizard_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($wizard_id === 0) {
    header("Location: wizards.php");
    exit;
}

$stmtW = $conn->prepare("SELECT wizard_name FROM wizard_templates WHERE wizard_id = ?");
$stmtW->execute([$wizard_id]);
$wizard = $stmtW->fetch(PDO::FETCH_ASSOC);
if (!$wizard) {
    die("Wizard not found.");
}

// ==========================================
// 1. ADIM SİLME İŞLEMİ (PRG PATTERN)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    try {
        $stmtImg = $conn->prepare("SELECT step_banner_image FROM wizard_steps WHERE step_id = ?");
        $stmtImg->execute([$del_id]);
        $img = $stmtImg->fetchColumn();
        if ($img && file_exists($img)) {
            unlink($img);
        }

        $stmt = $conn->prepare("DELETE FROM wizard_steps WHERE step_id = ?");
        $stmt->execute([$del_id]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['success_msg'] = "Step deleted successfully.";
        } else {
            $_SESSION['error_msg'] = "Step not found.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_msg'] = "Cannot delete this step.";
    }
    header("Location: wizard_steps.php?id=" . $wizard_id);
    exit;
}

// ==========================================
// 2. YENİ ADIM EKLEME (PRG PATTERN)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_step'])) {
    $step_title = trim($_POST['step_title']);
    $category_id = (int)$_POST['category_id'];
    $view_type = $_POST['view_type'];
    $step_order = (int)$_POST['step_order'];
    $banner_path = null;

    if (isset($_FILES['step_banner']) && $_FILES['step_banner']['error'] == 0) {
        $banner_path = save_uploaded_image($_FILES['step_banner'], 'uploads/wizards');
        if ($banner_path === null) {
            $_SESSION['error_msg'] = "Invalid image format. Only JPG, PNG, WEBP allowed.";
            header("Location: wizard_steps.php?id=" . $wizard_id);
            exit;
        }
    }

    if (!empty($step_title) && $category_id > 0) {
        $stmt = $conn->prepare("INSERT INTO wizard_steps (wizard_id, step_order, step_title, category_id, view_type, step_banner_image) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$wizard_id, $step_order, $step_title, $category_id, $view_type, $banner_path])) {
            $_SESSION['success_msg'] = "Step added successfully.";
        } else {
            $_SESSION['error_msg'] = "Failed to add step.";
        }
    } else {
        $_SESSION['error_msg'] = "Step Title and Category are required.";
    }
    header("Location: wizard_steps.php?id=" . $wizard_id);
    exit;
}

if (isset($_SESSION['success_msg'])) { $success_message = $_SESSION['success_msg']; unset($_SESSION['success_msg']); }
if (isset($_SESSION['error_msg'])) { $error_message = $_SESSION['error_msg']; unset($_SESSION['error_msg']); }

// ==========================================
// KATEGORİLERİ ÇEK VE HİYERARŞİK DÜZLEŞTİR (FLAT TREE)
// ==========================================
$stmtCat = $conn->query("SELECT * FROM categories ORDER BY parent_category_id ASC, category_name ASC");
$all_cats = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

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
        $flat_tree[] = $cat;
        buildFlatTree($cats_by_parent, $cat['category_id'], $level + 1);
    }
}
buildFlatTree($cats_by_parent, 0, 0);

// Bu Sihirbaza Ait Adımları Çek
$stmtSteps = $conn->prepare("
    SELECT s.*, c.category_name 
    FROM wizard_steps s 
    LEFT JOIN categories c ON s.category_id = c.category_id 
    WHERE s.wizard_id = ? 
    ORDER BY s.step_order ASC
");
$stmtSteps->execute([$wizard_id]);
$steps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);

$next_order = (count($steps) > 0) ? (end($steps)['step_order'] + 1) : 1;

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Select2 -->

<style>
    .banner-preview { width: 100px; height: 50px; object-fit: cover; border-radius: 4px; border: 1px solid var(--fr-line); }
</style>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 style="color: var(--fr-walnut); font-weight: bold;">Manage Steps</h2>
            <p class="text-muted mb-0">Configuring: <strong class="text-primary"><?php echo htmlspecialchars($wizard['wizard_name']); ?></strong></p>
        </div>
        <a href="wizards.php" class="btn btn-secondary shadow-sm fw-bold">
            <i class="fa-solid fa-arrow-left me-2"></i>Back to Wizards
        </a>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success shadow-sm'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger shadow-sm'>" . e($error_message) . "</div>"; ?>

    <div class="row">
        <!-- Adım Ekleme Formu -->
        <div class="col-md-4 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-dark text-white py-3">
                    <h5 class="card-title mb-0"><i class="fa-solid fa-plus me-2"></i>Add Wizard Step</h5>
                </div>
                <div class="card-body p-4 bg-white">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row mb-3">
                            <div class="col-4">
                                <label class="form-label fw-bold text-secondary small">Step Order</label>
                                <input type="number" name="step_order" class="form-control text-center fw-bold" value="<?php echo $next_order; ?>" required>
                            </div>
                            <div class="col-8">
                                <label class="form-label fw-bold text-secondary small">Step Title *</label>
                                <input type="text" name="step_title" class="form-control" placeholder="e.g. Select Cabinet Cases" required>
                            </div>
                        </div>

                        <!-- YENİ: Hiyerarşik Kategori Seçimi -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary small">Product Category *</label>
                            <select name="category_id" class="form-select select2-init" required>
                                <option value="">-- Choose Category --</option>
                                <?php foreach($flat_tree as $cat): ?>
                                    <option value="<?php echo e($cat['category_id']); ?>">
                                        <?php echo str_repeat('&nbsp;&nbsp;&nbsp;', $cat['level']) . ($cat['level'] > 0 ? '└ ' : '') . htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted d-block mt-1">Products from this category will be listed in this step.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary small">View Mode</label>
                            <select name="view_type" class="form-select">
                                <option value="IMAGE_GRID">Image Grid (Cards with thumbnails)</option>
                                <option value="TABLE">Standard Table (List view)</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-secondary small">Banner / Blueprint Image (Optional)</label>
                            <input type="file" name="step_banner" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp">
                            <small class="text-muted d-block mt-1">Shows at the top of the step to guide the user (e.g. a drawing highlighting the lower cabinets).</small>
                        </div>

                        <button type="submit" name="add_step" class="btn btn-primary w-100 fw-bold shadow-sm">
                            <i class="fa-solid fa-save me-2"></i> Save Step
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Adımlar Listesi -->
        <div class="col-md-8 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="card-title mb-0" style="color: var(--fr-walnut);"><i class="fa-solid fa-list-ol me-2"></i>Configured Steps</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4" width="80">Order</th>
                                    <th>Banner</th>
                                    <th>Step Title</th>
                                    <th>Source Category</th>
                                    <th>View Mode</th>
                                    <th class="text-end pe-4">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($steps) > 0): ?>
                                    <?php foreach ($steps as $s): ?>
                                        <tr>
                                            <td class="ps-4 text-center">
                                                <span class="badge bg-secondary rounded-pill fs-6"><?php echo e($s['step_order']); ?></span>
                                            </td>
                                            <td>
                                                <?php if(!empty($s['step_banner_image']) && file_exists($s['step_banner_image'])): ?>
                                                    <a href="<?php echo htmlspecialchars($s['step_banner_image']); ?>" target="_blank">
                                                        <img src="<?php echo htmlspecialchars($s['step_banner_image']); ?>" class="banner-preview" alt="Banner">
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted small border p-1 rounded bg-light">No Banner</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><strong class="text-dark fs-6"><?php echo htmlspecialchars($s['step_title']); ?></strong></td>
                                            <td><i class="fa-solid fa-tags text-primary me-1"></i><?php echo htmlspecialchars($s['category_name']); ?></td>
                                            <td>
                                                <?php if($s['view_type'] == 'IMAGE_GRID'): ?>
                                                    <span class="badge bg-info text-dark"><i class="fa-solid fa-border-all me-1"></i>Grid</span>
                                                <?php else: ?>
                                                    <span class="badge border bg-light text-dark"><i class="fa-solid fa-list me-1"></i>Table</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this step?');">
                                                    <input type="hidden" name="delete_id" value="<?php echo e($s['step_id']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Step"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-5">No steps configured yet. Create Step 1 from the left panel.</td></tr>
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
        escapeMarkup: function(markup) { return markup; } // Select2'de boşlukların (nbsp) düzgün gözükmesi için
    });
});
</script>

<?php include 'includes/footer.php'; ?>