<?php
require_once __DIR__ . '/includes/auth.php';
if ($_SESSION['role'] !== 'SUPER_ADMIN') { die("Access Denied."); }

require_once __DIR__ . '/config/database.php';
$conn = (new Database())->getConnection();

// ==========================================
// 1. AJAX: BİRİMLERİ DİNAMİK GETİRME BÖLÜMÜ
// (Başka dosyaya gerek kalmadan kendi içinde çalışır)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] == 'get_units') {
    ob_clean(); // Olası gizli boşlukları temizle
    $productId = (int)$_GET['id'];
    
    // Ürünün hem ANA birimini hem de tanımlanan ALT birimlerini çekiyoruz
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
    exit; // İşlemi bitir ki sayfanın devamı AJAX'a karışmasın
}
// ==========================================

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $parent_product_id = (int)$_POST['parent_product_id'];
    $recipe_name = trim($_POST['recipe_name']);
    $materials = $_POST['material_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $units = $_POST['unit_id'] ?? []; // YENİ: Seçilen Birimler Geliyor

    if ($parent_product_id > 0 && !empty($recipe_name) && count($materials) > 0) {
        try {
            $conn->beginTransaction();

            $stmtRecipe = $conn->prepare("INSERT INTO product_recipes (product_id, recipe_name) VALUES (?, ?)");
            $stmtRecipe->execute([$parent_product_id, $recipe_name]);
            $new_recipe_id = $conn->lastInsertId();

            // YENİ: unit_id veritabanına ekleniyor
            $stmtItem = $conn->prepare("INSERT INTO product_recipe_items (recipe_id, material_product_id, quantity, unit_id) VALUES (?, ?, ?, ?)");
            
            for ($i = 0; $i < count($materials); $i++) {
                $m_id = (int)$materials[$i];
                $qty = (float)$quantities[$i];
                $u_id = (int)($units[$i] ?? 0);
                
                if ($m_id > 0 && $qty > 0 && $u_id > 0) {
                    $stmtItem->execute([$new_recipe_id, $m_id, $qty, $u_id]);
                }
            }

            $conn->commit();
            $success_message = "Recipe '$recipe_name' created successfully!";
        } catch (Exception $e) {
            $conn->rollBack();
            $error_message = "Error creating recipe: " . $e->getMessage();
        }
    } else {
        $error_message = "Please fill all required fields and add at least one material with unit.";
    }
}

// Ürün listesi artık sayfaya basılmıyor; seçim kutusu yazdıkça arar (assets/js/product-picker.js)
$optionsHTML = '<option value=""></option>';

include 'includes/header.php';
include 'includes/sidebar.php';
?>



<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 style="color: var(--fr-walnut); font-weight: bold;">Create New Recipe</h2>
        <a href="recipes.php" class="btn btn-secondary shadow-sm"><i class="fa-solid fa-arrow-left me-1"></i> Back to Recipes</a>
    </div>

    <?php if ($success_message) echo "<div class='alert alert-success shadow-sm'>" . e($success_message) . "</div>"; ?>
    <?php if ($error_message) echo "<div class='alert alert-danger shadow-sm'>" . e($error_message) . "</div>"; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form method="POST" id="recipeForm">
                <div class="row mb-4">
                    <div class="col-md-8">
                        <label class="form-label fw-bold text-secondary">Parent Product (Product to be produced) *</label>
                        <select name="parent_product_id" id="parentProductSelect" class="form-select js-product-picker" data-placeholder="Search the product this recipe makes" required>
                            <?php echo $optionsHTML; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-secondary">Recipe Name/Code *</label>
                        <input type="text" name="recipe_name" class="form-control shadow-sm" placeholder="e.g. SM, EP, Variant 1" required>
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
                                <!-- YENİ BİRİM SÜTUNU -->
                                <th width="200">Unit</th> 
                                <th width="80" class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Satırlar buraya eklenecek -->
                        </tbody>
                    </table>
                </div>

                <div class="text-end mt-4">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold shadow-sm px-5">
                        <i class="fa-solid fa-save me-2"></i> Save Recipe
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
            <!-- YENİ BİRİM SELECT KUTUSU -->
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
        addMaterialRow();
    });

    function addMaterialRow() {
        const tbody = document.querySelector('#materialsTable tbody');
        const template = document.getElementById('rowTemplate');
        const clone = template.content.cloneNode(true);
        
        tbody.appendChild(clone);
        
        let newSelect = $('#materialsTable tbody tr:last-child .material-select');
        FrProductPicker.attach(newSelect[0]);

        // Ürün Seçildiğinde Sadece Bu Dosyaya AJAX İsteği Atıp Birimleri Getir
        newSelect.on('change', function() {
            let pid = $(this).val();
            let unitSelect = $(this).closest('tr').find('.unit-select');
            
            if(pid) {
                // Sayfanın kendisine action parametresiyle istek atıyoruz!
                $.get('add_recipe.php?action=get_units&id=' + pid, function(data) {
                    unitSelect.html(data);
                });
            } else {
                unitSelect.html('<option value="">-- Select Material First --</option>');
            }
        });
    }

    function removeRow(btn) {
        $(btn).closest('tr').remove();
    }
</script>

<?php include 'includes/footer.php'; ?>