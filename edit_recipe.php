<?php
require_once __DIR__ . '/includes/auth.php';
if ($_SESSION['role'] !== 'SUPER_ADMIN') { die("Access Denied."); }

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: recipes.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
$conn = (new Database())->getConnection();
$recipe_id = (int)$_GET['id'];

// ==========================================
// 1. AJAX: BİRİMLERİ DİNAMİK GETİRME BÖLÜMÜ
// ==========================================
if (isset($_GET['action']) && $_GET['action'] == 'get_units') {
    ob_clean();
    $productId = (int)$_GET['id'];
    
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

    echo "<option value=''>-- Select Unit --</option>";
    foreach ($units as $u) {
        echo '<option value="' . (int)$u['unit_id'] . '">' . e($u['unit_name']) . '</option>';
    }
    exit;
}
// ==========================================

$success_message = '';
$error_message = '';

// Form Gönderildiğinde (Güncelleme)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $parent_product_id = (int)$_POST['parent_product_id'];
    $recipe_name = trim($_POST['recipe_name']);
    $materials = $_POST['material_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $units = $_POST['unit_id'] ?? []; // YENİ: Seçilen birimler

    if ($parent_product_id > 0 && !empty($recipe_name) && count($materials) > 0) {
        try {
            $conn->beginTransaction();

            // 1. Ana Reçeteyi Güncelle
            $stmtUpdate = $conn->prepare("UPDATE product_recipes SET product_id = ?, recipe_name = ? WHERE recipe_id = ?");
            $stmtUpdate->execute([$parent_product_id, $recipe_name, $recipe_id]);

            // 2. Eski malzemeleri temizle
            $conn->prepare("DELETE FROM product_recipe_items WHERE recipe_id = ?")->execute([$recipe_id]);

            // 3. Yeni malzemeleri birimleri ile birlikte ekle
            $stmtItem = $conn->prepare("INSERT INTO product_recipe_items (recipe_id, material_product_id, quantity, unit_id) VALUES (?, ?, ?, ?)");
            for ($i = 0; $i < count($materials); $i++) {
                $m_id = (int)$materials[$i];
                $qty = (float)$quantities[$i];
                $u_id = (int)($units[$i] ?? 0);
                
                if ($m_id > 0 && $qty > 0 && $u_id > 0) {
                    $stmtItem->execute([$recipe_id, $m_id, $qty, $u_id]);
                }
            }

            $conn->commit();
            $success_message = "Recipe updated successfully!";
        } catch (Exception $e) {
            $conn->rollBack();
            $error_message = "Error updating recipe: " . $e->getMessage();
        }
    } else {
        $error_message = "Please fill all required fields and add at least one material with unit.";
    }
}

// Mevcut Reçeteyi Çek
$stmt = $conn->prepare("SELECT * FROM product_recipes WHERE recipe_id = ?");
$stmt->execute([$recipe_id]);
$recipe = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recipe) { die("Recipe not found."); }

// Mevcut Malzemeleri Çek
$stmtItems = $conn->prepare("SELECT * FROM product_recipe_items WHERE recipe_id = ?");
$stmtItems->execute([$recipe_id]);
$current_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Sadece seçili ürünlerin adları çekilir (12.000 ürünü sayfaya basmak yerine)
$needIds = array_unique(array_merge([(int)$recipe['product_id']], array_map('intval', array_column($current_items, 'material_product_id'))));
$productNames = [];
if ($needIds) {
    $in = implode(',', array_fill(0, count($needIds), '?'));
    $st = $conn->prepare("SELECT product_id, stock_code, stock_name FROM products WHERE product_id IN ($in)");
    $st->execute(array_values($needIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $productNames[(int)$p['product_id']] = $p['stock_code'] . ' · ' . $p['stock_name'];
}
$optionsHTML = '<option value=""></option>';

function getProductOptions($names, $selected_id) {
    $selected_id = (int)$selected_id;
    if (!$selected_id) return '<option value=""></option>';
    return '<option value="' . $selected_id . '" selected>' . e($names[$selected_id] ?? ('#' . $selected_id)) . '</option>';
}

// Bir malzemenin birimlerini 'Selected' olarak getiren yardımcı fonksiyon
function getUnitOptions($conn, $productId, $selected_unit_id) {
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

    $html = '<option value="">-- Select Unit --</option>';
    foreach ($units as $u) {
        $sel = ($u['unit_id'] == $selected_unit_id) ? 'selected' : '';
        $html .= '<option value="'.$u['unit_id'].'" '.$sel.'>'.htmlspecialchars($u['unit_name']).'</option>';
    }
    return $html;
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- JQUERY VE SELECT2 KÜTÜPHANELERİ -->


<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 style="color: var(--fr-walnut); font-weight: bold;">Edit Recipe</h2>
        <a href="recipes.php" class="btn btn-secondary shadow-sm"><i class="fa-solid fa-arrow-left me-1"></i> Back to Recipes</a>
    </div>

    <?php if ($success_message) echo "<div class='alert alert-success shadow-sm'>" . e($success_message) . "</div>"; ?>
    <?php if ($error_message) echo "<div class='alert alert-danger shadow-sm'>" . e($error_message) . "</div>"; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form method="POST" id="recipeForm">
                <div class="row mb-4">
                    <div class="col-md-8">
                        <label class="form-label fw-bold text-secondary">Parent Product *</label>
                        <select name="parent_product_id" id="parentProductSelect" class="form-select js-product-picker" required>
                            <?php echo getProductOptions($productNames, $recipe['product_id']); ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-secondary">Recipe Name/Code *</label>
                        <input type="text" name="recipe_name" class="form-control shadow-sm" value="<?php echo htmlspecialchars($recipe['recipe_name']); ?>" required>
                    </div>
                </div>

                <hr class="text-muted mb-4">
                
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0" style="color: var(--fr-walnut);"><i class="fa-solid fa-boxes-stacked me-2"></i>Bill of Materials (BOM)</h5>
                    <button type="button" class="btn btn-sm btn-success fw-bold shadow-sm" onclick="addMaterialRow()">
                        <i class="fa-solid fa-plus me-1"></i> Add Material
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle" id="materialsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Material / Raw Product</th>
                                <th width="150">Quantity</th>
                                <th width="200">Unit</th>
                                <th width="80" class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- MEVCUT MALZEMELER -->
                            <?php foreach ($current_items as $item): ?>
                                <tr>
                                    <td>
                                        <select name="material_id[]" class="form-select material-select js-product-picker" required>
                                            <?php echo getProductOptions($productNames, $item['material_product_id']); ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" step="0.0001" min="0.0001" name="quantity[]" class="form-control text-center" value="<?php echo (float)$item['quantity']; ?>" required>
                                    </td>
                                    <td>
                                        <select name="unit_id[]" class="form-select unit-select" required>
                                            <?php echo getUnitOptions($conn, $item['material_product_id'], $item['unit_id']); ?>
                                        </select>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-danger shadow-sm" onclick="removeRow(this)"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="text-end mt-4">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold shadow-sm px-5">
                        <i class="fa-solid fa-save me-2"></i> Update Recipe
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<template id="rowTemplate">
    <tr>
        <td>
            <select name="material_id[]" class="form-select material-select js-product-picker" required>
                <?php echo $optionsHTML; ?>
            </select>
        </td>
        <td>
            <input type="number" step="0.0001" min="0.0001" name="quantity[]" class="form-control text-center" placeholder="Qty" required>
        </td>
        <td>
            <select name="unit_id[]" class="form-select unit-select" required>
                <option value="">-- Select Material First --</option>
            </select>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-danger shadow-sm" onclick="removeRow(this)"><i class="fa-solid fa-trash"></i></button>
        </td>
    </tr>
</template>

<script>
    $(document).ready(function() {

        // Mevcut satırlardaki ürün değişikliklerini dinle
        $(document).on('change', '.material-select', function() {
            let pid = $(this).val();
            let unitSelect = $(this).closest('tr').find('.unit-select');
            
            if(pid) {
                $.get('edit_recipe.php?action=get_units&id=' + pid, function(data) {
                    unitSelect.html(data);
                });
            } else {
                unitSelect.html('<option value="">-- Select Material First --</option>');
            }
        });
    });

    function addMaterialRow() {
        const tbody = document.querySelector('#materialsTable tbody');
        const template = document.getElementById('rowTemplate');
        const clone = template.content.cloneNode(true);
        
        tbody.appendChild(clone);
        
        FrProductPicker.attach(document.querySelector('#materialsTable tbody tr:last-child .material-select'));
    }

    function removeRow(btn) {
        $(btn).closest('tr').remove();
    }
</script>

<?php include 'includes/footer.php'; ?>