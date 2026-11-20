<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

$service_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($service_id === 0) { header("Location: additional_services.php"); exit; }

// --- GÜNCELLEME İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_service'])) {
    $service_name = trim($_POST['service_name']);
    $price_type = $_POST['price_type'];
    $default_price = (float)$_POST['default_price'];

    if (!empty($service_name)) {
        $stmt = $conn->prepare("UPDATE additional_services SET service_name = ?, price_type = ?, default_price = ? WHERE service_id = ?");
        if ($stmt->execute([$service_name, $price_type, $default_price, $service_id])) {
            $success_message = "Service updated successfully.";
        } else {
            $error_message = "Failed to update service.";
        }
    }
}

// Mevcut Servisi Çek
$stmt = $conn->prepare("SELECT * FROM additional_services WHERE service_id = ?");
$stmt->execute([$service_id]);
$currentService = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentService) { header("Location: additional_services.php"); exit; }

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Edit Service</h2>
        <a href="additional_services.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Services</a>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="card shadow-sm" style="max-width: 500px;">
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Service Name *</label>
                    <input type="text" name="service_name" class="form-control" value="<?php echo htmlspecialchars($currentService['service_name']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Price Type</label>
                    <select name="price_type" class="form-select">
                        <option value="FIXED_USD" <?php echo $currentService['price_type'] == 'FIXED_USD' ? 'selected' : ''; ?>>Fixed Amount (USD)</option>
                        <option value="FIXED_NGN" <?php echo $currentService['price_type'] == 'FIXED_NGN' ? 'selected' : ''; ?>>Fixed Amount (NGN)</option>
                        <option value="PERCENT" <?php echo $currentService['price_type'] == 'PERCENT' ? 'selected' : ''; ?>>Percentage (%) of Total</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Default Price / Rate</label>
                    <input type="number" step="0.01" name="default_price" class="form-control" value="<?php echo floatval($currentService['default_price']); ?>" required>
                </div>
                <button type="submit" name="update_service" class="btn btn-primary w-100">
                    <i class="fa-solid fa-save me-2"></i> Update Service
                </button>
            </form>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>