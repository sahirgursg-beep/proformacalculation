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
        $stmt = $conn->prepare("DELETE FROM units WHERE unit_id = ?");
        $stmt->execute([$del_id]);
        if ($stmt->rowCount() > 0) $_SESSION['success_msg'] = "Unit deleted successfully.";
        else $_SESSION['error_msg'] = "Unit not found.";
    } catch (PDOException $e) {
        $_SESSION['error_msg'] = "Cannot delete this unit. It is currently assigned to products.";
    }
    header("Location: units.php");
    exit;
}
if (isset($_SESSION['success_msg'])) { $success_message = $_SESSION['success_msg']; unset($_SESSION['success_msg']); }
if (isset($_SESSION['error_msg'])) { $error_message = $_SESSION['error_msg']; unset($_SESSION['error_msg']); }

// --- EKLEME İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_unit'])) {
    $unit_name = trim($_POST['unit_name']);
    if (!empty($unit_name)) {
        $stmt = $conn->prepare("INSERT INTO units (unit_name) VALUES (?)");
        if ($stmt->execute([$unit_name])) {
            $success_message = "Unit added successfully.";
        } else {
            $error_message = "Failed to add unit.";
        }
    }
}

// Birimleri Çek
$stmt = $conn->query("SELECT * FROM units ORDER BY unit_name ASC");
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Units of Measure</h2>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="row">
        <!-- Ekleme Formu -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title mb-0">Add New Unit</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Unit Name *</label>
                            <input type="text" name="unit_name" class="form-control" placeholder="e.g. Box, Ton, Sqm" required>
                        </div>
                        <button type="submit" name="add_unit" class="btn btn-primary w-100">
                            <i class="fa-solid fa-plus me-2"></i> Save Unit
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Liste -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Unit Name</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($units) > 0): ?>
                                <?php foreach ($units as $u): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($u['unit_name']); ?></strong></td>
                                        <td class="text-end">
                                           <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure?');">
    <input type="hidden" name="delete_id" value="<?php echo e($u['unit_id']); ?>">
    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
</form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="2" class="text-center text-muted py-3">No units found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>