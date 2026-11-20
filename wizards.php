<?php
require_once __DIR__ . '/includes/auth.php';
if ($_SESSION['role'] !== 'SUPER_ADMIN' && $_SESSION['role'] !== 'ARCHITECT') { 
    die("Access Denied."); 
}


require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

// ==========================================
// 1. SİLME İŞLEMİ (PRG PATTERN)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    try {
        // Sihirbazın adımları da silinir (eskiden sahipsiz kalıyordu)
        $conn->beginTransaction();
        $conn->prepare("DELETE FROM wizard_steps WHERE wizard_id = ?")->execute([$del_id]);
        $stmt = $conn->prepare("DELETE FROM wizard_templates WHERE wizard_id = ?");
        $stmt->execute([$del_id]);
        $conn->commit();
        if ($stmt->rowCount() > 0) {
            $_SESSION['success_msg'] = "Wizard deleted successfully.";
        } else {
            $_SESSION['error_msg'] = "Wizard not found.";
        }
    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $_SESSION['error_msg'] = "Cannot delete this wizard.";
    }
    header("Location: wizards.php");
    exit;
}

// ==========================================
// 2. YENİ SİHİRBAZ EKLEME (PRG PATTERN)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_wizard'])) {
    $wizard_name = trim($_POST['wizard_name']);
    $description = trim($_POST['description']);

    if (!empty($wizard_name)) {
        $stmt = $conn->prepare("INSERT INTO wizard_templates (wizard_name, description) VALUES (?, ?)");
        if ($stmt->execute([$wizard_name, $description])) {
            $_SESSION['success_msg'] = "Wizard created successfully. Now you can add steps to it!";
        } else {
            $_SESSION['error_msg'] = "Failed to create wizard.";
        }
    }
    header("Location: wizards.php");
    exit;
}

// Session Mesajlarını Yakala
if (isset($_SESSION['success_msg'])) { $success_message = $_SESSION['success_msg']; unset($_SESSION['success_msg']); }
if (isset($_SESSION['error_msg'])) { $error_message = $_SESSION['error_msg']; unset($_SESSION['error_msg']); }

// Sihirbazları Çek (İçindeki Adım Sayısıyla Birlikte)
$query = "
    SELECT w.*, 
           (SELECT COUNT(*) FROM wizard_steps s WHERE s.wizard_id = w.wizard_id) as step_count 
    FROM wizard_templates w 
    ORDER BY w.created_at DESC
";
$wizards = $conn->query($query)->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 style="color: var(--fr-walnut); font-weight: bold;">Wizard Builder</h2>
        <span class="badge bg-primary fs-6"><i class="fa-solid fa-wand-magic-sparkles me-2"></i>Dynamic Wizards</span>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success shadow-sm'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger shadow-sm'>" . e($error_message) . "</div>"; ?>

    <div class="row">
        <!-- Sihirbaz Ekleme Formu -->
        <div class="col-md-4 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-dark text-white py-3">
                    <h5 class="card-title mb-0"><i class="fa-solid fa-plus me-2"></i>Create New Wizard</h5>
                </div>
                <div class="card-body p-4 bg-white">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary">Wizard Name *</label>
                            <input type="text" name="wizard_name" class="form-control shadow-sm" placeholder="e.g. Kitchen Cabinet Wizard" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-bold text-secondary">Description</label>
                            <textarea name="description" class="form-control shadow-sm" rows="3" placeholder="Brief info about what this wizard configures..."></textarea>
                        </div>
                        <button type="submit" name="add_wizard" class="btn btn-primary w-100 fw-bold shadow-sm">
                            <i class="fa-solid fa-wand-magic-sparkles me-2"></i> Create Wizard
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sihirbaz Listesi -->
        <div class="col-md-8 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="card-title mb-0" style="color: var(--fr-walnut);"><i class="fa-solid fa-list-check me-2"></i>Available Wizards</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Wizard Name & Info</th>
                                    <th class="text-center">Steps</th>
                                    <th>Status</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($wizards) > 0): ?>
                                    <?php foreach ($wizards as $w): ?>
                                        <tr>
                                            <td class="ps-4">
                                                <strong class="text-dark fs-6"><?php echo htmlspecialchars($w['wizard_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($w['description']); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-info text-dark fs-6 border border-info rounded-circle p-2 px-3"><?php echo e($w['step_count']); ?></span>
                                            </td>
                                            <td>
                                                <?php if($w['status'] == 'ACTIVE'): ?>
                                                    <span class="badge bg-success shadow-sm">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary shadow-sm">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <!-- ADIMLARI YÖNET BUTONU -->
                                                <a href="wizard_steps.php?id=<?php echo e($w['wizard_id']); ?>" class="btn btn-sm btn-outline-primary fw-bold me-1" title="Manage Steps">
                                                    <i class="fa-solid fa-layer-group me-1"></i> Manage Steps
                                                </a>
                                                
                                                <!-- SİLME BUTONU -->
                                                <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this wizard and ALL its steps?');">
                                                    <input type="hidden" name="delete_id" value="<?php echo e($w['wizard_id']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Wizard"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center text-muted py-5">No wizards created yet. Let's build your first one!</td></tr>
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