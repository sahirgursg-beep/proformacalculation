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
        $stmt = $conn->prepare("DELETE FROM projects WHERE project_id = ?");
        $stmt->execute([$del_id]);
        if ($stmt->rowCount() > 0) $_SESSION['success_msg'] = "Project deleted successfully.";
        else $_SESSION['error_msg'] = "Project not found.";
    } catch (PDOException $e) {
        $_SESSION['error_msg'] = "Cannot delete this project. There are proformas linked to it.";
    }
    header("Location: projects.php");
    exit;
}
if (isset($_SESSION['success_msg'])) { $success_message = $_SESSION['success_msg']; unset($_SESSION['success_msg']); }
if (isset($_SESSION['error_msg'])) { $error_message = $_SESSION['error_msg']; unset($_SESSION['error_msg']); }
// --- EKLEME İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_project'])) {
    $customer_id = (int)$_POST['customer_id'];
    $project_name = trim($_POST['project_name']);
    $project_status = $_POST['project_status'];
    $project_description = trim($_POST['project_description']);

    if ($customer_id > 0 && !empty($project_name)) {
        $stmt = $conn->prepare("INSERT INTO projects (customer_id, project_name, project_status, project_description) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$customer_id, $project_name, $project_status, $project_description])) {
            $success_message = "Project created successfully.";
        } else {
            $error_message = "Failed to create project.";
        }
    } else {
        $error_message = "Customer and Project Name are required.";
    }
}

// Müşterileri Çek (Dropdown için)
$customers = $conn->query("SELECT customer_id, customer_name FROM customers ORDER BY customer_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Projeleri Çek (İçindeki proforma sayısıyla birlikte)
$projectsQuery = "
    SELECT p.*, c.customer_name,
           (SELECT COUNT(*) FROM proformas pr WHERE pr.project_id = p.project_id) as proforma_count
    FROM projects p
    LEFT JOIN customers c ON p.customer_id = c.customer_id
    ORDER BY p.created_at DESC
";
$projects = $conn->query($projectsQuery)->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Select2 Kütüphaneleri -->

<style>
    .select2-container .select2-selection--single { height: 38px !important; display: flex; align-items: center; border-color: var(--fr-line); }
</style>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Project Management</h2>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="row">
        <!-- PROJE EKLEME FORMU -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title mb-0">Create New Project</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Customer *</label>
                            <select name="customer_id" id="customerSelect" class="form-select" required>
                                <option value="">-- Select Customer --</option>
                                <?php foreach($customers as $c): ?>
                                    <option value="<?php echo e($c['customer_id']); ?>"><?php echo htmlspecialchars($c['customer_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Project Name *</label>
                            <input type="text" name="project_name" class="form-control" placeholder="e.g. Abuja HQ Renovation" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="project_status" class="form-select">
                                <option value="PLANNING">Planning</option>
                                <option value="ONGOING">Ongoing</option>
                                <option value="COMPLETED">Completed</option>
                                <option value="CANCELLED">Cancelled</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description / Notes</label>
                            <textarea name="project_description" class="form-control" rows="3"></textarea>
                        </div>
                        <button type="submit" name="add_project" class="btn btn-primary w-100">
                            <i class="fa-solid fa-folder-plus me-2"></i> Create Project
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- PROJE LİSTESİ -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="card-title mb-0">Project Portfolio</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Project Info</th>
                                    <th>Customer</th>
                                    <th class="text-center">Proformas</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($projects) > 0): ?>
                                    <?php foreach ($projects as $p): 
                                        $badgeClass = 'bg-secondary';
                                        if ($p['project_status'] == 'ONGOING') $badgeClass = 'bg-primary';
                                        if ($p['project_status'] == 'COMPLETED') $badgeClass = 'bg-success';
                                        if ($p['project_status'] == 'CANCELLED') $badgeClass = 'bg-danger';
                                    ?>
                                        <tr>
                                            <td>
                                                <strong style="color: var(--fr-walnut); font-size: 1.05rem;"><?php echo htmlspecialchars($p['project_name']); ?></strong><br>
                                                <small class="text-muted text-truncate d-inline-block" style="max-width: 200px;"><?php echo htmlspecialchars($p['project_description']); ?></small>
                                            </td>
                                            <td><i class="fa-solid fa-building text-muted me-1"></i> <?php echo htmlspecialchars($p['customer_name']); ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-info text-dark fs-6"><?php echo e($p['proforma_count']); ?></span>
                                            </td>
                                            <td><span class="badge <?php echo $badgeClass; ?>"><?php echo e($p['project_status']); ?></span></td>
                                            <td class="text-end">
    <a href="view_project.php?id=<?php echo e($p['project_id']); ?>" class="btn btn-sm btn-outline-success" title="Print Master Quotation" target="_blank"><i class="fa-solid fa-print"></i></a>
    <!-- YENİ: Edit Butonu -->
    <a href="edit_project.php?id=<?php echo e($p['project_id']); ?>" class="btn btn-sm btn-outline-primary" title="Edit Project"><i class="fa-solid fa-edit"></i></a>
    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this project?');">
    <input type="hidden" name="delete_id" value="<?php echo e($p['project_id']); ?>">
    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
</form>
    </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No projects created yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
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