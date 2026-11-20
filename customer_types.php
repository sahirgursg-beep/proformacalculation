<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

// --- SİLME İŞLEMİ ---
// --- SİLME İŞLEMİ (PRG) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    try {
        $stmt = $conn->prepare("DELETE FROM customer_types WHERE type_id = ?");
        $stmt->execute([$del_id]);
        if ($stmt->rowCount() > 0) $_SESSION['success_msg'] = "Customer type deleted successfully.";
        else $_SESSION['error_msg'] = "Type not found.";
    } catch (PDOException $e) {
        $_SESSION['error_msg'] = "Cannot delete this type because there are customers assigned to it.";
    }
    header("Location: customer_types.php");
    exit;
}
if (isset($_SESSION['success_msg'])) { $success_message = $_SESSION['success_msg']; unset($_SESSION['success_msg']); }
if (isset($_SESSION['error_msg'])) { $error_message = $_SESSION['error_msg']; unset($_SESSION['error_msg']); }
// --- EKLEME İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_type'])) {
    $type_name = trim($_POST['type_name']);
    $discount_rate = (float)$_POST['discount_rate'];

    if (!empty($type_name)) {
        $stmt = $conn->prepare("INSERT INTO customer_types (type_name, discount_rate) VALUES (?, ?)");
        if ($stmt->execute([$type_name, $discount_rate])) {
            $success_message = "Customer type added successfully.";
        } else {
            $error_message = "Failed to add customer type.";
        }
    }
}

// Mevcut Tipleri Çek
$stmt = $conn->query("SELECT * FROM customer_types ORDER BY type_name ASC");
$types = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Customer Types & Discounts</h2>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="row">
        <!-- Ekleme Formu -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title mb-0">Add New Type</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Type Name *</label>
                            <input type="text" name="type_name" class="form-control" placeholder="e.g. Wholesaler" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Default Discount Rate (%)</label>
                            <input type="number" step="0.01" name="discount_rate" class="form-control" value="0.00">
                            <small class="text-muted">This discount is automatically applied to their proformas.</small>
                        </div>
                        <button type="submit" name="add_type" class="btn btn-primary w-100">
                            <i class="fa-solid fa-save me-2"></i> Save Type
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Liste -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="card-title mb-0">Existing Types</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Type Name</th>
                                <th>Default Discount</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($types) > 0): ?>
                                <?php foreach ($types as $t): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($t['type_name']); ?></strong></td>
                                        <td class="text-success"><?php echo floatval($t['discount_rate']); ?>%</td>
                                        <td class="text-end">
                                            <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure?');">
    <input type="hidden" name="delete_id" value="<?php echo e($t['type_id']); ?>">
    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
</form>
                                            <a href="edit_customer_type.php?id=<?php echo e($t['type_id']); ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-edit"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">No customer types found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>