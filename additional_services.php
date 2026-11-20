<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

// ==========================================
// 1. SİLME İŞLEMİ (PRG PATTERN)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    try {
        $stmt = $conn->prepare("DELETE FROM additional_services WHERE service_id = ?");
        $stmt->execute([$del_id]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['success_msg'] = "Service deleted successfully.";
        } else {
            $_SESSION['error_msg'] = "Service not found or already deleted.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_msg'] = "Cannot delete this service. It might be used in existing proformas.";
    }
    header("Location: additional_services.php");
    exit;
}

// ==========================================
// 2. EKLEME İŞLEMİ (PRG PATTERN)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_service'])) {
    $service_name = trim($_POST['service_name']);
    $price_type = $_POST['price_type'];
    $default_price = (float)$_POST['default_price'];

    if (!empty($service_name)) {
        $stmt = $conn->prepare("INSERT INTO additional_services (service_name, price_type, default_price) VALUES (?, ?, ?)");
        if ($stmt->execute([$service_name, $price_type, $default_price])) {
            $_SESSION['success_msg'] = "Service added successfully.";
        } else {
            $_SESSION['error_msg'] = "Failed to add service.";
        }
    } else {
        $_SESSION['error_msg'] = "Service name is required.";
    }
    header("Location: additional_services.php");
    exit;
}

// ==========================================
// 3. YÖNLENDİRME SONRASI MESAJLARI YAKALA
// ==========================================
if (isset($_SESSION['success_msg'])) { 
    $success_message = $_SESSION['success_msg']; 
    unset($_SESSION['success_msg']); 
}
if (isset($_SESSION['error_msg'])) { 
    $error_message = $_SESSION['error_msg']; 
    unset($_SESSION['error_msg']); 
}

// Tüm Servisleri Çek
$stmt = $conn->query("SELECT * FROM additional_services ORDER BY service_name ASC");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 style="color: var(--fr-walnut); font-weight: bold;">Additional Services</h2>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success shadow-sm'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger shadow-sm'>" . e($error_message) . "</div>"; ?>

    <div class="row">
        <!-- Servis Ekleme Formu -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title mb-0"><i class="fa-solid fa-plus me-2"></i>Add New Service</h5>
                </div>
                <div class="card-body bg-white p-4">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary">Service Name *</label>
                            <input type="text" name="service_name" class="form-control shadow-sm" placeholder="e.g. Installation, Delivery" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary">Price Type</label>
                            <select name="price_type" class="form-select shadow-sm">
                                <option value="FIXED_USD">Fixed Amount (USD)</option>
                                <option value="FIXED_NGN">Fixed Amount (NGN)</option>
                                <option value="PERCENT">Percentage (%) of Total</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-bold text-secondary">Default Price / Rate</label>
                            <input type="number" step="0.01" name="default_price" class="form-control shadow-sm" value="0.00" required>
                            <small class="text-muted mt-1 d-block">Enter a fixed value or percentage (e.g. 5 for 5%). You can change this while creating a proforma.</small>
                        </div>
                        <button type="submit" name="add_service" class="btn btn-primary w-100 fw-bold shadow-sm">
                            <i class="fa-solid fa-save me-2"></i> Save Service
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Servis Listesi -->
        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="card-title mb-0" style="color: var(--fr-walnut);"><i class="fa-solid fa-list-check me-2"></i>Available Services</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Service Name</th>
                                    <th>Pricing Type</th>
                                    <th>Default Value</th>
                                    <th class="text-end pe-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($services) > 0): ?>
                                    <?php foreach ($services as $srv): ?>
                                        <tr>
                                            <td class="ps-3"><strong class="text-dark"><?php echo htmlspecialchars($srv['service_name']); ?></strong></td>
                                            <td>
                                                <?php 
                                                if ($srv['price_type'] == 'FIXED_USD') echo '<span class="badge border bg-success text-white">Fixed USD</span>';
                                                elseif ($srv['price_type'] == 'FIXED_NGN') echo '<span class="badge border bg-secondary text-white">Fixed NGN</span>';
                                                elseif ($srv['price_type'] == 'PERCENT') echo '<span class="badge border bg-info text-dark">Percentage (%)</span>';
                                                ?>
                                            </td>
                                            <td class="fw-bold text-primary">
                                                <?php 
                                                if ($srv['price_type'] == 'PERCENT') echo floatval($srv['default_price']) . '%';
                                                elseif ($srv['price_type'] == 'FIXED_USD') echo '$' . number_format($srv['default_price'], 2);
                                                elseif ($srv['price_type'] == 'FIXED_NGN') echo '₦' . number_format($srv['default_price'], 2);
                                                ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="edit_service.php?id=<?php echo e($srv['service_id']); ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-edit"></i></a>
                                                <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this service?');">
                                                    <input type="hidden" name="delete_id" value="<?php echo e($srv['service_id']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">No services defined yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>