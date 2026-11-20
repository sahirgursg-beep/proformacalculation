<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';

$dbClass = new Database();
$conn = $dbClass->getConnection();

// Ayarları Güncelleme İşlemi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_settings'])) {
    $safe_rate = (float)$_POST['safe_rate'];
    $list_markup = (float)$_POST['list_markup'];
    $vat_rate = (float)$_POST['vat_rate'];
    $waste_rate = (float)$_POST['waste_rate'];

    $stmt = $conn->prepare("UPDATE system_settings SET safe_rate = ?, list_markup = ?, vat_rate = ?, waste_rate = ? WHERE setting_id = 1");
    if ($stmt->execute([$safe_rate, $list_markup, $vat_rate, $waste_rate])) {
        $success_message = "System settings updated successfully.";
    } else {
        $error_message = "Failed to update settings.";
    }
}

// Mevcut Ayarları Çekme
$stmt = $conn->prepare("SELECT * FROM system_settings WHERE setting_id = 1");
$stmt->execute();
$settings = $stmt->fetch();

// Eğer veritabanında ayar satırı yoksa varsayılan değerleri ata (Hata almamak için)
if (!$settings) {
    $settings = [
        'safe_rate' => 0.00,
        'list_markup' => 0.00,
        'vat_rate' => 0.00,
        'waste_rate' => 0.00
    ];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>System Settings</h2>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="row">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title mb-0"><i class="fa-solid fa-sliders"></i> Global Rates & Margins</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Global Waste Rate (Fire Oranı) %</label>
                            <input type="number" step="0.01" name="waste_rate" class="form-control" value="<?php echo e($settings['waste_rate']); ?>" required>
                            <small class="text-muted">Applied dynamically to production costs to cover material loss.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">VAT Rate (KDV) %</label>
                            <input type="number" step="0.01" name="vat_rate" class="form-control" value="<?php echo e($settings['vat_rate']); ?>" required>
                            <small class="text-muted">Default tax rate for sales and proformas.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Safe Rate %</label>
                            <input type="number" step="0.01" name="safe_rate" class="form-control" value="<?php echo e($settings['safe_rate']); ?>" required>
                            <small class="text-muted">Buffer rate for unexpected currency fluctuations.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Default List Markup %</label>
                            <input type="number" step="0.01" name="list_markup" class="form-control" value="<?php echo e($settings['list_markup']); ?>" required>
                            <small class="text-muted">General markup applied to base costs if product-specific margin is missing.</small>
                        </div>

                        <button type="submit" name="update_settings" class="btn btn-primary w-100">
                            <i class="fa-solid fa-save me-2"></i> Save Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card shadow-sm border-info">
                <div class="card-header bg-info text-white">
                    <h5 class="card-title mb-0"><i class="fa-solid fa-circle-info"></i> How Pricing Works</h5>
                </div>
                <div class="card-body">
                    <p>The system uses a <strong>Dynamic Cost Engine</strong>. Product prices are not static; they are calculated in real-time based on your BOM (Bill of Materials) and these global settings.</p>
                    <ul>
                        <li><strong>Base Cost:</strong> Sum of all raw materials at their current USD rates.</li>
                        <li><strong>Production Cost:</strong> Base Cost + Product's Production Margin + <strong>Waste Rate</strong>.</li>
                        <li><strong>Sales Price:</strong> Production Cost + Product's Sales Margin.</li>
                    </ul>
                    <p class="mb-0 text-muted">Updating the <em>Waste Rate</em> here will instantly update the sales price of all manufactured products across the system without needing to edit them individually.</p>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>