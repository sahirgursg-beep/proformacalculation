<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

$project_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($project_id === 0) {
    header("Location: projects.php");
    exit;
}

// --- GÜNCELLEME İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_project'])) {
    $customer_id = (int)$_POST['customer_id'];
    $project_name = trim($_POST['project_name']);
    $project_status = $_POST['project_status'];
    $project_description = trim($_POST['project_description']);

    if ($customer_id > 0 && !empty($project_name)) {
        try {
            $stmt = $conn->prepare("UPDATE projects SET customer_id = ?, project_name = ?, project_status = ?, project_description = ? WHERE project_id = ?");
            if ($stmt->execute([$customer_id, $project_name, $project_status, $project_description, $project_id])) {
                $success_message = "Project updated successfully.";
            } else {
                $error_message = "Failed to update project.";
            }
        } catch (PDOException $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    } else {
        $error_message = "Customer and Project Name are required.";
    }
}

// Mevcut Projeyi Çek
$stmt = $conn->prepare("SELECT * FROM projects WHERE project_id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    header("Location: projects.php");
    exit;
}

// Müşterileri Çek (Dropdown için)
$customers = $conn->query("SELECT customer_id, customer_name FROM customers ORDER BY customer_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Select2 Kütüphaneleri -->

<style>
    .select2-container .select2-selection--single { height: 38px !important; display: flex; align-items: center; border-color: var(--fr-line); }
</style>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Edit Project: <?php echo htmlspecialchars($project['project_name']); ?></h2>
        <a href="projects.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Projects</a>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="card shadow-sm" style="max-width: 600px;">
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Customer *</label>
                    <select name="customer_id" id="customerSelect" class="form-select" required>
                        <option value="">-- Select Customer --</option>
                        <?php foreach($customers as $c): ?>
                            <option value="<?php echo e($c['customer_id']); ?>" <?php echo $c['customer_id'] == $project['customer_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['customer_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Project Name *</label>
                    <input type="text" name="project_name" class="form-control" value="<?php echo htmlspecialchars($project['project_name']); ?>" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="project_status" class="form-select">
                        <option value="PLANNING" <?php echo $project['project_status'] == 'PLANNING' ? 'selected' : ''; ?>>Planning</option>
                        <option value="ONGOING" <?php echo $project['project_status'] == 'ONGOING' ? 'selected' : ''; ?>>Ongoing</option>
                        <option value="COMPLETED" <?php echo $project['project_status'] == 'COMPLETED' ? 'selected' : ''; ?>>Completed</option>
                        <option value="CANCELLED" <?php echo $project['project_status'] == 'CANCELLED' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label class="form-label">Description / Notes</label>
                    <textarea name="project_description" class="form-control" rows="4"><?php echo htmlspecialchars($project['project_description']); ?></textarea>
                </div>
                
                <button type="submit" name="update_project" class="btn btn-primary w-100 fs-5 py-2">
                    <i class="fa-solid fa-save me-2"></i> Update Project
                </button>
            </form>
        </div>
    </div>
</main>

<script>
$(document).ready(function() {
    $('#customerSelect').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });
});
</script>

<?php include 'includes/footer.php'; ?>