<?php
require_once __DIR__ . '/includes/auth.php';
if ($_SESSION['role'] !== 'SUPER_ADMIN') { die("Access Denied."); }

require_once __DIR__ . '/config/database.php';
$conn = (new Database())->getConnection();

$success_message = '';
$error_message = '';

// --- AIR CARGO GÜNCELLEMESİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_air'])) {
    $desi_divisor = (float)$_POST['desi_divisor'];
    $turkey_local_usd = (float)$_POST['turkey_local_usd'];
    $air_freight_usd = (float)$_POST['air_freight_usd'];
    $clearance_ngn = (float)$_POST['clearance_ngn'];
    $abuja_local_ngn = (float)$_POST['abuja_local_ngn'];
    $profit_margin = (float)$_POST['profit_margin'];

    try {
        $stmt = $conn->prepare("UPDATE shipment_air_settings SET desi_divisor=?, turkey_local_usd=?, air_freight_usd=?, clearance_ngn=?, abuja_local_ngn=?, profit_margin=? WHERE id=1");
        $stmt->execute([$desi_divisor, $turkey_local_usd, $air_freight_usd, $clearance_ngn, $abuja_local_ngn, $profit_margin]);
        $success_message = "Air Cargo settings updated successfully.";
    } catch (PDOException $e) {
        $error_message = "Error updating Air Cargo: " . $e->getMessage();
    }
}

// --- SEA FREIGHT (KONTEYNER) GÜNCELLEMESİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_sea'])) {
    $container_id = (int)$_POST['container_id'];
    $theoretical_cbm = (float)$_POST['theoretical_cbm'];
    $volume_loss_percent = (float)$_POST['volume_loss_percent'];
    $turkey_cost_usd = (float)$_POST['turkey_cost_usd'];
    $sea_freight_usd = (float)$_POST['sea_freight_usd'];
    $clearance_ngn = (float)$_POST['sea_clearance_ngn'];
    $abuja_local_ngn = (float)$_POST['sea_abuja_local_ngn'];
    $profit_margin = (float)$_POST['sea_profit_margin'];

    try {
        $stmt = $conn->prepare("UPDATE shipment_sea_containers SET theoretical_cbm=?, volume_loss_percent=?, turkey_cost_usd=?, sea_freight_usd=?, clearance_ngn=?, abuja_local_ngn=?, profit_margin=? WHERE id=?");
        $stmt->execute([$theoretical_cbm, $volume_loss_percent, $turkey_cost_usd, $sea_freight_usd, $clearance_ngn, $abuja_local_ngn, $profit_margin, $container_id]);
        $success_message = "Container settings updated successfully.";
    } catch (PDOException $e) {
        $error_message = "Error updating Container: " . $e->getMessage();
    }
}

// Mevcut Verileri Çek
$air_settings = $conn->query("SELECT * FROM shipment_air_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$sea_containers = $conn->query("SELECT * FROM shipment_sea_containers ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 style="color: var(--fr-walnut); font-weight: bold;"><i class="fa-solid fa-ship me-2"></i>Shipment & Freight Configurations</h2>
    </div>

    <?php if ($success_message) echo "<div class='alert alert-success shadow-sm'>" . e($success_message) . "</div>"; ?>
    <?php if ($error_message) echo "<div class='alert alert-danger shadow-sm'>" . e($error_message) . "</div>"; ?>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white pt-3 pb-0 border-bottom">
            <ul class="nav nav-tabs border-0" id="shipmentTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold px-4" id="air-tab" data-bs-toggle="tab" data-bs-target="#air" type="button" role="tab"><i class="fa-solid fa-plane text-primary me-2"></i> Air Cargo</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold px-4" id="sea-tab" data-bs-toggle="tab" data-bs-target="#sea" type="button" role="tab"><i class="fa-solid fa-ship text-success me-2"></i> Sea Freight (Containers)</button>
                </li>
            </ul>
        </div>
        
        <div class="card-body p-4">
            <div class="tab-content" id="shipmentTabsContent">
                
                <!-- AIR CARGO TAB -->
                <div class="tab-pane fade show active" id="air" role="tabpanel">
                    <div class="row">
                        <div class="col-lg-6">
                            <h5 class="text-primary mb-3"><i class="fa-solid fa-cloud me-2"></i>Air Freight Pricing Parameters</h5>
                            <p class="text-muted small mb-4">Set the per Kg / Desi costs for air shipments. The system will calculate chargeable weight (Gross Weight vs Volumetric Weight) automatically.</p>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-secondary">Volumetric Divisor (Desi)</label>
                                    <div class="input-group">
                                        <input type="number" step="0.01" name="desi_divisor" class="form-control" value="<?= (float)$air_settings['desi_divisor'] ?>" required>
                                        <span class="input-group-text bg-light text-muted">Usually 6000</span>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold small text-secondary">Turkey Local Transport (per Kg)</label>
                                        <div class="input-group"><span class="input-group-text">$</span><input type="number" step="0.01" name="turkey_local_usd" class="form-control" value="<?= (float)$air_settings['turkey_local_usd'] ?>" required></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold small text-secondary">Air Freight Cost (per Kg)</label>
                                        <div class="input-group"><span class="input-group-text">$</span><input type="number" step="0.01" name="air_freight_usd" class="form-control" value="<?= (float)$air_settings['air_freight_usd'] ?>" required></div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold small text-secondary">Nigeria Clearance (per Kg)</label>
                                        <div class="input-group"><span class="input-group-text">₦</span><input type="number" step="0.01" name="clearance_ngn" class="form-control" value="<?= (float)$air_settings['clearance_ngn'] ?>" required></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold small text-secondary">Abuja Delivery (per Kg)</label>
                                        <div class="input-group"><span class="input-group-text">₦</span><input type="number" step="0.01" name="abuja_local_ngn" class="form-control" value="<?= (float)$air_settings['abuja_local_ngn'] ?>" required></div>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-secondary">Profit Margin on Shipment</label>
                                    <div class="input-group"><input type="number" step="0.01" name="profit_margin" class="form-control" value="<?= (float)$air_settings['profit_margin'] ?>"><span class="input-group-text">%</span></div>
                                </div>
                                <button type="submit" name="update_air" class="btn btn-primary px-4 fw-bold shadow-sm"><i class="fa-solid fa-save me-2"></i>Save Air Settings</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- SEA FREIGHT TAB -->
                <div class="tab-pane fade" id="sea" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="text-success mb-0"><i class="fa-solid fa-boxes-stacked me-2"></i>Container Capacity & Costs</h5>
                        <small class="text-muted">Edit costs per total container. Cost per CBM will be derived automatically.</small>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light text-center">
                                <tr>
                                    <th rowspan="2" class="align-middle">Container Type</th>
                                    <th colspan="3">Capacity & Dimensions</th>
                                    <th colspan="2">Origin Costs (USD)</th>
                                    <th colspan="2">Destination Costs (NGN)</th>
                                    <th rowspan="2" class="align-middle">Margin</th>
                                    <th rowspan="2" class="align-middle">Edit</th>
                                </tr>
                                <tr>
                                    <th>Theo. CBM</th>
                                    <th class="text-danger">Loss % (Gap)</th>
                                    <th class="text-success">Net CBM</th>
                                    <th>Turkey Local</th>
                                    <th>Sea Freight</th>
                                    <th>Clearance</th>
                                    <th>Abuja Local</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sea_containers as $c): 
                                    // Matematiksel Net CBM Hesabı
                                    $net_cbm = $c['theoretical_cbm'] * (1 - ($c['volume_loss_percent'] / 100));
                                ?>
                                <tr class="text-center">
                                    <td class="fw-bold text-start text-dark"><?= htmlspecialchars($c['container_type']) ?></td>
                                    <td><?= (float)$c['theoretical_cbm'] ?> m³</td>
                                    <td class="text-danger fw-bold">% <?= (float)$c['volume_loss_percent'] ?></td>
                                    <td class="text-success fw-bold"><?= number_format($net_cbm, 2) ?> m³</td>
                                    <td>$<?= number_format($c['turkey_cost_usd'], 2) ?></td>
                                    <td>$<?= number_format($c['sea_freight_usd'], 2) ?></td>
                                    <td>₦<?= number_format($c['clearance_ngn'], 2) ?></td>
                                    <td>₦<?= number_format($c['abuja_local_ngn'], 2) ?></td>
                                    <td>% <?= (float)$c['profit_margin'] ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-success" onclick='editContainer(<?= json_encode($c) ?>)' data-bs-toggle="modal" data-bs-target="#editContainerModal">
                                            <i class="fa-solid fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</main>

<!-- Sea Freight Düzenleme Modalı -->
<div class="modal fade" id="editContainerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fa-solid fa-ship me-2"></i>Edit Container: <span id="modal_container_name"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <input type="hidden" name="container_id" id="modal_container_id">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-secondary">Theoretical CBM (m³)</label>
                            <input type="number" step="0.01" name="theoretical_cbm" id="modal_cbm" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-danger">Volume Loss / Gap (%)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="volume_loss_percent" id="modal_loss" class="form-control" required>
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-secondary">Turkey Local Cost (Total USD)</label>
                            <input type="number" step="0.01" name="turkey_cost_usd" id="modal_turkey" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-secondary">Sea Freight Cost (Total USD)</label>
                            <input type="number" step="0.01" name="sea_freight_usd" id="modal_freight" class="form-control" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-secondary">Nigeria Clearance (Total NGN)</label>
                            <input type="number" step="0.01" name="sea_clearance_ngn" id="modal_clearance" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-secondary">Abuja Delivery (Total NGN)</label>
                            <input type="number" step="0.01" name="sea_abuja_local_ngn" id="modal_abuja" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-bold small text-secondary">Profit Margin (%)</label>
                        <input type="number" step="0.01" name="sea_profit_margin" id="modal_margin" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="update_sea" class="btn btn-success fw-bold px-4">Save Configuration</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editContainer(data) {
    document.getElementById('modal_container_name').textContent = data.container_type;
    document.getElementById('modal_container_id').value = data.id;
    document.getElementById('modal_cbm').value = data.theoretical_cbm;
    document.getElementById('modal_loss').value = data.volume_loss_percent;
    document.getElementById('modal_turkey').value = data.turkey_cost_usd;
    document.getElementById('modal_freight').value = data.sea_freight_usd;
    document.getElementById('modal_clearance').value = data.clearance_ngn;
    document.getElementById('modal_abuja').value = data.abuja_local_ngn;
    document.getElementById('modal_margin').value = data.profit_margin;
}
</script>

<?php include 'includes/footer.php'; ?>