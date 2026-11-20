<?php
require_once __DIR__ . '/includes/auth.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: products.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();
$product_id = (int)$_GET['id'];

// Kur ve Taşıma Ayarlarını Çek
$stmtRate = $conn->query("SELECT buy_rate FROM rate_exchange_history WHERE currency_code = 'NGN' ORDER BY created_at DESC LIMIT 1");
$ngn_buy_rate = (float)($stmtRate->fetchColumn() ?: 1);

$air_settings = $conn->query("SELECT * FROM shipment_air_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$sea_containers = $conn->query("SELECT * FROM shipment_sea_containers ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$success_message = '';
$error_message = '';

// ==========================================
// 1. GÜNCELLEME İŞLEMİ (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_product'])) {
    $stock_code = trim($_POST['stock_code']);
    $stock_name = trim($_POST['stock_name']);
    $category_id = (int)$_POST['category_id'];
    $main_unit_id = (int)$_POST['unit_id'];
    
    // Kur ve Temel Maliyet İşlemleri
    $input_cost = (float)$_POST['unit_cost'];
    $currency = $_POST['currency'];
    $unit_cost_usd = $input_cost;

    if ($currency === 'NGN' && $ngn_buy_rate > 0) {
        $unit_cost_usd = $input_cost / $ngn_buy_rate;
    }
    
    $production_margin = (float)$_POST['production_margin'];
    $sales_margin = (float)$_POST['sales_margin'];
    $service_margin_type = $_POST['service_margin_type'];
    $service_margin_value = (float)$_POST['service_margin_value'];

    // Taşıma Değişkenleri
    $include_shipment = isset($_POST['include_shipment']) ? 1 : 0;
    $shipment_type = 'NONE';
    $sea_container_id = null;

    if ($include_shipment && isset($_POST['shipment_method'])) {
        $method = $_POST['shipment_method'];
        if ($method === 'AIR') {
            $shipment_type = 'AIR';
        } elseif (strpos($method, 'SEA_') === 0) {
            $shipment_type = 'SEA';
            $sea_container_id = (int)str_replace('SEA_', '', $method);
        }
    }

    // Resim İşlemi
    // Mevcut resim yolu formdan değil veritabanından alınır
    $stImg = $conn->prepare("SELECT image FROM products WHERE product_id = ?");
    $stImg->execute([$product_id]);
    $image_path = $stImg->fetchColumn() ?: null;
    if (!empty($_FILES['product_image']['name'])) {
        $newImage = save_uploaded_image($_FILES['product_image'], 'uploads/products');
        if ($newImage !== null) $image_path = $newImage;
        else $error_message = 'The image was not saved: only JPG, PNG, WEBP or GIF files up to 8 MB are accepted.';
    }

    if (!empty($stock_code) && !empty($stock_name) && $category_id > 0 && $main_unit_id > 0) {
        try {
            $conn->beginTransaction();

            // Ana Ürünü Güncelle
            $stmt = $conn->prepare("
                UPDATE products 
                SET stock_code=?, stock_name=?, category_id=?, unit_id=?, unit_cost_usd=?, production_margin=?, sales_margin=?, service_margin_type=?, service_margin_value=?, image=?, include_shipment=?, shipment_type=?, sea_container_id=?
                WHERE product_id=?
            ");
            $stmt->execute([$stock_code, $stock_name, $category_id, $main_unit_id, $unit_cost_usd, $production_margin, $sales_margin, $service_margin_type, $service_margin_value, $image_path, $include_shipment, $shipment_type, $sea_container_id, $product_id]);
            
            // Alt Birimleri (Multi-UoM) Güncelle: Önce sil, sonra ekle
            $conn->prepare("DELETE FROM product_unit_conversions WHERE product_id=?")->execute([$product_id]);
            if (isset($_POST['sub_unit_id']) && is_array($_POST['sub_unit_id'])) {
                $stmtSub = $conn->prepare("INSERT INTO product_unit_conversions (product_id, unit_id, conversion_factor, unit_cost_usd) VALUES (?, ?, ?, ?)");
                for ($i = 0; $i < count($_POST['sub_unit_id']); $i++) {
                    $sub_unit_id = (int)$_POST['sub_unit_id'][$i];
                    $factor = (float)$_POST['sub_conversion_factor'][$i];
                    
                    if ($sub_unit_id > 0 && $factor > 0) {
                        $sub_cost_usd = $unit_cost_usd / $factor;
                        $stmtSub->execute([$product_id, $sub_unit_id, $factor, $sub_cost_usd]);
                    }
                }
            }

            // Paketleri (Packages) Güncelle: Önce sil, sonra ekle
            $conn->prepare("DELETE FROM product_packages WHERE product_id=?")->execute([$product_id]);
            if ($include_shipment && isset($_POST['pkg_length']) && is_array($_POST['pkg_length'])) {
                $stmtPkg = $conn->prepare("INSERT INTO product_packages (product_id, package_name, length_cm, width_cm, height_cm, weight_kg, quantity) VALUES (?, ?, ?, ?, ?, ?, ?)");
                for ($i = 0; $i < count($_POST['pkg_length']); $i++) {
                    $p_name = trim($_POST['pkg_name'][$i] ?? 'Box');
                    $p_l = (float)$_POST['pkg_length'][$i];
                    $p_w = (float)$_POST['pkg_width'][$i];
                    $p_h = (float)$_POST['pkg_height'][$i];
                    $p_kg = (float)$_POST['pkg_weight'][$i];
                    $p_qty = (int)$_POST['pkg_qty'][$i];

                    if ($p_qty > 0 && ($p_l > 0 || $p_kg > 0)) {
                        $stmtPkg->execute([$product_id, $p_name, $p_l, $p_w, $p_h, $p_kg, $p_qty]);
                    }
                }
            }

            $conn->commit();
            $success_message = "Product updated successfully.";
        } catch (PDOException $e) {
            $conn->rollBack();
            if ($e->getCode() == 23000) {
                $error_message = "Stock code already exists!";
            } else {
                $error_message = "Error: " . $e->getMessage();
            }
        }
    } else {
        $error_message = "Please fill all required fields.";
    }
}

// ==========================================
// 2. MEVCUT VERİLERİ ÇEK
// ==========================================
$stmtProd = $conn->prepare("SELECT * FROM products WHERE product_id = ?");
$stmtProd->execute([$product_id]);
$product = $stmtProd->fetch(PDO::FETCH_ASSOC);

if (!$product) { die("Product not found."); }

// Mevcut Alt Birimleri Çek
$stmtConv = $conn->prepare("SELECT * FROM product_unit_conversions WHERE product_id = ?");
$stmtConv->execute([$product_id]);
$conversions = $stmtConv->fetchAll(PDO::FETCH_ASSOC);

// Mevcut Paketleri Çek
$stmtPkgs = $conn->prepare("SELECT * FROM product_packages WHERE product_id = ?");
$stmtPkgs->execute([$product_id]);
$packages = $stmtPkgs->fetchAll(PDO::FETCH_ASSOC);

// Kategorileri ve Birimleri Çekme (Flat Tree)
$stmtCat = $conn->query("SELECT * FROM categories ORDER BY parent_category_id ASC, category_name ASC");
$all_cats = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
$cats_by_parent = [];
foreach ($all_cats as $c) { $cats_by_parent[$c['parent_category_id']][] = $c; }
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

$units = $conn->query("SELECT * FROM units ORDER BY unit_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>


<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Edit Product</h2>
        <a href="products.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Products</a>
    </div>

    <?php if ($success_message) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if ($error_message) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="card shadow-sm border-0 mb-5" style="max-width: 1000px;">
        <div class="card-body p-4">
            <form method="POST" enctype="multipart/form-data" id="productForm">
                <input type="hidden" name="existing_image" value="<?= htmlspecialchars($product['image'] ?? '') ?>">
                
                <!-- 1. TEMEL BİLGİLER -->
                <h5 class="border-bottom pb-2 mb-3 text-primary"><i class="fa-solid fa-box-open me-2"></i>Main Details</h5>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Stock Code *</label>
                        <input type="text" name="stock_code" class="form-control" value="<?= htmlspecialchars($product['stock_code']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Stock Name *</label>
                        <input type="text" name="stock_name" class="form-control" value="<?= htmlspecialchars($product['stock_name']) ?>" required>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Category *</label>
                        <select name="category_id" class="form-select select2-init" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach($flat_tree as $cat): ?>
                                <option value="<?php echo e($cat['category_id']); ?>" <?= $cat['category_id'] == $product['category_id'] ? 'selected' : '' ?>>
                                    <?= str_repeat('&nbsp;&nbsp;&nbsp;', $cat['level']) . ($cat['level'] > 0 ? '└ ' : '') . htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Main Unit of Measure *</label>
                        <select name="unit_id" id="main_unit_id" class="form-select select2-init" required>
                            <option value="">-- Select Unit --</option>
                            <?php foreach($units as $u): ?>
                                <option value="<?php echo e($u['unit_id']); ?>" <?= $u['unit_id'] == $product['unit_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['unit_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-8">
                        <label class="form-label">Purchase Price / Base Cost</label>
                        <input type="number" step="0.0001" name="unit_cost" id="main_unit_cost" class="form-control" value="<?= (float)$product['unit_cost_usd'] ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Currency</label>
                        <select name="currency" class="form-select">
                            <option value="USD" selected>USD (Saved as USD)</option>
                            <option value="NGN">NGN (Convert to USD)</option>
                        </select>
                    </div>
                </div>

                <!-- 2. ALT BİRİMLER (Multi-UoM) -->
                <div class="p-3 bg-light rounded border mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 text-dark"><i class="fa-solid fa-scale-balanced me-2"></i>Alternative Units (For BOM)</h6>
                        <button type="button" id="addSubUnitBtn" class="btn btn-sm btn-outline-success fw-bold">
                            <i class="fa-solid fa-plus me-1"></i> Add Unit
                        </button>
                    </div>
                    
                    <div id="subUnitsContainer">
                        <?php foreach($conversions as $conv): ?>
                            <div class="row mb-2 sub-unit-row align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label small">Alt Unit</label>
                                    <select name="sub_unit_id[]" class="form-select form-select-sm" required>
                                        <option value="">Select</option>
                                        <?php foreach($units as $u): ?>
                                            <option value="<?php echo e($u['unit_id']); ?>" <?= $u['unit_id'] == $conv['unit_id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['unit_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">1 Main = How Many?</label>
                                    <input type="number" step="0.0001" name="sub_conversion_factor[]" class="form-control form-control-sm calc-factor" value="<?= (float)$conv['conversion_factor'] ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted">Calc. Unit Cost</label>
                                    <input type="text" class="form-control form-control-sm calc-result bg-white" readonly value="<?= number_format($conv['unit_cost_usd'], 4, '.', '') ?>">
                                </div>
                                <div class="col-md-1 text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 3. KARGO & NAKLİYE HESAPLAYICI -->
                <h5 class="border-bottom pb-2 mb-3 text-info"><i class="fa-solid fa-ship me-2"></i>Shipment & Freight Calculator</h5>
                
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="includeShipmentSwitch" name="include_shipment" value="1" <?= $product['include_shipment'] ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="includeShipmentSwitch">Include Shipment Cost in Base Cost?</label>
                </div>

                <div id="shipmentSection" style="<?= $product['include_shipment'] ? '' : 'display: none;' ?>" class="p-3 border rounded border-info bg-white mb-4">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Select Transport Method</label>
                            <?php 
                                $currMethod = 'AIR';
                                if($product['shipment_type'] === 'SEA') $currMethod = 'SEA_' . $product['sea_container_id'];
                            ?>
                            <select name="shipment_method" id="shipmentMethodSelect" class="form-select calc-trigger">
                                <option value="AIR" <?= $currMethod === 'AIR' ? 'selected' : '' ?>>Air Cargo</option>
                                <?php foreach($sea_containers as $sc): ?>
                                    <option value="SEA_<?php echo e($sc['id']); ?>" <?= $currMethod === ('SEA_'.$sc['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sc['container_type']) ?> (Sea Freight)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 text-end">
                            <button type="button" id="addPackageBtn" class="btn btn-sm btn-primary mt-4 fw-bold">
                                <i class="fa-solid fa-box me-1"></i> Add Package
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle" id="packagesTable">
                            <thead class="table-light text-center small">
                                <tr>
                                    <th>Pkg Name</th>
                                    <th>L (cm)</th>
                                    <th>W (cm)</th>
                                    <th>H (cm)</th>
                                    <th>Weight (Kg)</th>
                                    <th>Qty</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($packages) > 0): ?>
                                    <?php foreach($packages as $pkg): ?>
                                        <tr class="package-row text-center">
                                            <td><input type="text" name="pkg_name[]" class="form-control form-control-sm" value="<?= htmlspecialchars($pkg['package_name']) ?>"></td>
                                            <td><input type="number" step="0.01" name="pkg_length[]" class="form-control form-control-sm calc-trigger" value="<?= (float)$pkg['length_cm'] ?>"></td>
                                            <td><input type="number" step="0.01" name="pkg_width[]" class="form-control form-control-sm calc-trigger" value="<?= (float)$pkg['width_cm'] ?>"></td>
                                            <td><input type="number" step="0.01" name="pkg_height[]" class="form-control form-control-sm calc-trigger" value="<?= (float)$pkg['height_cm'] ?>"></td>
                                            <td><input type="number" step="0.01" name="pkg_weight[]" class="form-control form-control-sm calc-trigger" value="<?= (float)$pkg['weight_kg'] ?>"></td>
                                            <td><input type="number" name="pkg_qty[]" class="form-control form-control-sm calc-trigger" value="<?= (int)$pkg['quantity'] ?>" min="1"></td>
                                            <td><button type="button" class="btn btn-sm btn-outline-danger remove-pkg"><i class="fa-solid fa-times"></i></button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr class="package-row text-center">
                                        <td><input type="text" name="pkg_name[]" class="form-control form-control-sm" value="Box 1"></td>
                                        <td><input type="number" step="0.01" name="pkg_length[]" class="form-control form-control-sm calc-trigger" placeholder="0"></td>
                                        <td><input type="number" step="0.01" name="pkg_width[]" class="form-control form-control-sm calc-trigger" placeholder="0"></td>
                                        <td><input type="number" step="0.01" name="pkg_height[]" class="form-control form-control-sm calc-trigger" placeholder="0"></td>
                                        <td><input type="number" step="0.01" name="pkg_weight[]" class="form-control form-control-sm calc-trigger" placeholder="0"></td>
                                        <td><input type="number" name="pkg_qty[]" class="form-control form-control-sm calc-trigger" value="1" min="1"></td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-pkg"><i class="fa-solid fa-times"></i></button></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Canlı Maliyet Özeti -->
                    <div class="alert alert-secondary mt-2 mb-0 d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-dark me-2">Total CBM: <span id="lbl_cbm">0.00</span> m³</span>
                            <span class="badge bg-dark me-2">Total Gross: <span id="lbl_gross">0.00</span> Kg</span>
                            <span class="badge bg-info text-dark">Chargeable: <span id="lbl_chargeable">0.00</span> Unit</span>
                        </div>
                        <div class="fs-5 fw-bold text-success">
                            Est. Freight: $<span id="lbl_est_freight">0.00</span>
                        </div>
                    </div>
                </div>

                <!-- 4. MARJLAR -->
                <h5 class="border-bottom pb-2 mb-3 text-primary"><i class="fa-solid fa-tags me-2"></i>Margins & Sales Price</h5>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Production Margin (%)</label>
                        <input type="number" step="0.01" name="production_margin" class="form-control" value="<?= (float)$product['production_margin'] ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Sales Margin (%)</label>
                        <input type="number" step="0.01" name="sales_margin" class="form-control" value="<?= (float)$product['sales_margin'] ?>">
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Service Margin Type</label>
                        <select name="service_margin_type" class="form-select">
                            <option value="PERCENT" <?= $product['service_margin_type'] == 'PERCENT' ? 'selected' : '' ?>>Percent (%)</option>
                            <option value="USD" <?= $product['service_margin_type'] == 'USD' ? 'selected' : '' ?>>Fixed USD</option>
                            <option value="NGN" <?= $product['service_margin_type'] == 'NGN' ? 'selected' : '' ?>>Fixed NGN</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Service Margin Value</label>
                        <input type="number" step="0.01" name="service_margin_value" class="form-control" value="<?= (float)$product['service_margin_value'] ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Product Image</label>
                    <input type="file" name="product_image" class="form-control" accept="image/*">
                    <?php if(!empty($product['image']) && file_exists($product['image'])): ?>
                        <div class="mt-2">
                            <img src="<?= htmlspecialchars($product['image']) ?>" alt="Current Image" style="max-height: 80px; border-radius: 5px;">
                            <small class="text-muted ms-2">Current Image</small>
                        </div>
                    <?php endif; ?>
                </div>

                <button type="submit" name="update_product" class="btn btn-primary w-100 fs-5 py-2 fw-bold">
                    <i class="fa-solid fa-save me-2"></i> Update Product
                </button>
            </form>
        </div>
    </div>
</main>

<script>
const ngnRate = <?= $ngn_buy_rate ?>;
const airSettings = <?= json_encode($air_settings) ?>;
const seaSettings = <?= json_encode($sea_containers) ?>;
const unitsData = <?= json_encode($units) ?>;

$(document).ready(function() {
    $('.select2-init').select2({ theme: 'bootstrap-5' });

    // Sayfa Yüklendiğinde Kargo Hesabını Çalıştır
    calculateFreight();

    $('#addSubUnitBtn').click(function() {
        let unitOptions = '<option value="">Select</option>';
        unitsData.forEach(u => unitOptions += `<option value="${u.unit_id}">${u.unit_name}</option>`);

        const rowHtml = `
            <div class="row mb-2 sub-unit-row align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Alt Unit</label>
                    <select name="sub_unit_id[]" class="form-select form-select-sm" required>${unitOptions}</select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">1 Main = How Many?</label>
                    <input type="number" step="0.0001" name="sub_conversion_factor[]" class="form-control form-control-sm calc-factor" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Calc. Unit Cost</label>
                    <input type="text" class="form-control form-control-sm calc-result bg-white" readonly value="0.0000">
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fa-solid fa-trash"></i></button>
                </div>
            </div>
        `;
        $('#subUnitsContainer').append(rowHtml);
    });

    $(document).on('click', '.remove-row, .remove-pkg', function() {
        $(this).closest('tr, .row').remove();
        calculateFreight();
    });

    $(document).on('input', '#main_unit_cost, .calc-factor', function() {
        let mainCost = parseFloat($('#main_unit_cost').val()) || 0;
        $('.sub-unit-row').each(function() {
            let factor = parseFloat($(this).find('.calc-factor').val()) || 0;
            $(this).find('.calc-result').val(factor > 0 ? (mainCost / factor).toFixed(4) : "0.0000");
        });
    });

    $('#includeShipmentSwitch').change(function() {
        if($(this).is(':checked')) {
            $('#shipmentSection').slideDown();
            calculateFreight();
        } else {
            $('#shipmentSection').slideUp();
            $('#lbl_est_freight').text("0.00");
        }
    });

    $('#addPackageBtn').click(function() {
        const tr = `
        <tr class="package-row text-center">
            <td><input type="text" name="pkg_name[]" class="form-control form-control-sm" value="Box"></td>
            <td><input type="number" step="0.01" name="pkg_length[]" class="form-control form-control-sm calc-trigger" placeholder="0"></td>
            <td><input type="number" step="0.01" name="pkg_width[]" class="form-control form-control-sm calc-trigger" placeholder="0"></td>
            <td><input type="number" step="0.01" name="pkg_height[]" class="form-control form-control-sm calc-trigger" placeholder="0"></td>
            <td><input type="number" step="0.01" name="pkg_weight[]" class="form-control form-control-sm calc-trigger" placeholder="0"></td>
            <td><input type="number" name="pkg_qty[]" class="form-control form-control-sm calc-trigger" value="1" min="1"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger remove-pkg"><i class="fa-solid fa-times"></i></button></td>
        </tr>`;
        $('#packagesTable tbody').append(tr);
    });

    $(document).on('input change', '.calc-trigger', function() {
        calculateFreight();
    });

    function calculateFreight() {
        if(!$('#includeShipmentSwitch').is(':checked')) return;

        let totalCbm = 0, totalGross = 0, totalDesi = 0;
        let method = $('#shipmentMethodSelect').val();

        $('.package-row').each(function() {
            let l = parseFloat($(this).find('input[name="pkg_length[]"]').val()) || 0;
            let w = parseFloat($(this).find('input[name="pkg_width[]"]').val()) || 0;
            let h = parseFloat($(this).find('input[name="pkg_height[]"]').val()) || 0;
            let kg = parseFloat($(this).find('input[name="pkg_weight[]"]').val()) || 0;
            let qty = parseFloat($(this).find('input[name="pkg_qty[]"]').val()) || 1;

            let cbm = (l * w * h) / 1000000;
            totalCbm += (cbm * qty);
            totalGross += (kg * qty);

            if (method === 'AIR' && airSettings) {
                let divisor = parseFloat(airSettings.desi_divisor) || 6000;
                let desi = (l * w * h) / divisor;
                totalDesi += (desi * qty);
            }
        });

        let freightCost = 0;
        let chargeable = 0;

        if (method === 'AIR' && airSettings) {
            chargeable = Math.max(totalGross, totalDesi);
            
            let turkeyUsd = parseFloat(airSettings.turkey_local_usd) || 0;
            let airUsd = parseFloat(airSettings.air_freight_usd) || 0;
            let clearUsd = (parseFloat(airSettings.clearance_ngn) || 0) / ngnRate;
            let abujaUsd = (parseFloat(airSettings.abuja_local_ngn) || 0) / ngnRate;
            let margin = parseFloat(airSettings.profit_margin) || 0;

            let unitCost = turkeyUsd + airUsd + clearUsd + abujaUsd;
            freightCost = chargeable * unitCost * (1 + (margin/100));

            $('#lbl_chargeable').text(chargeable.toFixed(2) + " Kg/Desi");

        } else if (method.startsWith('SEA_')) {
            let c_id = parseInt(method.split('_')[1]);
            let container = seaSettings.find(c => c.id === c_id);
            
            if (container) {
                chargeable = totalCbm;
                
                let theoCbm = parseFloat(container.theoretical_cbm) || 1;
                let loss = parseFloat(container.volume_loss_percent) || 0;
                let netCbm = theoCbm * (1 - (loss/100));

                let turkUsd = parseFloat(container.turkey_cost_usd) || 0;
                let seaUsd = parseFloat(container.sea_freight_usd) || 0;
                let clrUsd = (parseFloat(container.clearance_ngn) || 0) / ngnRate;
                let abjUsd = (parseFloat(container.abuja_local_ngn) || 0) / ngnRate;
                let margin = parseFloat(container.profit_margin) || 0;

                let totalContainerCost = turkUsd + seaUsd + clrUsd + abjUsd;
                let costPerCbm = totalContainerCost / netCbm;

                freightCost = chargeable * costPerCbm * (1 + (margin/100));
                $('#lbl_chargeable').text(chargeable.toFixed(3) + " m³");
            }
        }

        $('#lbl_cbm').text(totalCbm.toFixed(3));
        $('#lbl_gross').text(totalGross.toFixed(2));
        $('#lbl_est_freight').text(freightCost.toFixed(2));
    }
});
</script>

<?php include 'includes/footer.php'; ?>