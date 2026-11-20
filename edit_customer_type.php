<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

$type_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($type_id === 0) { header("Location: customer_types.php"); exit; }

// Güncelleme İşlemi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_type'])) {
    $type_name = trim($_POST['type_name']);
    $discount_rate = (float)$_POST['discount_rate'];

    if (!empty($type_name)) {
        $stmt = $conn->prepare("UPDATE customer_types SET type_name = ?, discount_rate = ? WHERE type_id = ?");
        if ($stmt->execute([$type_name, $discount_rate, $type_id])) {
            $success_message = "Customer type updated successfully.";
        } else {
            $error_message = "Failed to update.";
        }
    }
}

// Mevcut veriyi çek
$stmt = $conn->prepare("SELECT * FROM customer_types WHERE type_id = ?");
$stmt->execute([$type_id]);
$currentType = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentType) { header("Location: customer_types.php"); exit; }

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Edit Customer Type</h2>
        <a href="customer_types.php" class="btn btn-secondary">Back to Types</a>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="card shadow-sm" style="max-width: 500px;">
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Type Name *</label>
                    <input type="text" name="type_name" class="form-control" value="<?php echo htmlspecialchars($currentType['type_name']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Default Discount Rate (%)</label>
                    <input type="number" step="0.01" name="discount_rate" class="form-control" value="<?php echo floatval($currentType['discount_rate']); ?>">
                </div>
                <button type="submit" name="update_type" class="btn btn-primary w-100">
                    <i class="fa-solid fa-save"></i> Update Type
                </button>
            </form>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>