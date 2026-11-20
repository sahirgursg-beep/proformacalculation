<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

// --- SİLME (sadece admin) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_id'])) {
    if (!is_admin()) {
        $_SESSION['error_msg'] = "Only an administrator can delete proformas. Use Cancel instead.";
    } else {
        $del_id = (int)$_POST['delete_id'];
        try {
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM proforma_items WHERE proforma_id = ?")->execute([$del_id]);
            $conn->prepare("DELETE FROM proforma_services WHERE proforma_id = ?")->execute([$del_id]);
            $stmt = $conn->prepare("DELETE FROM proformas WHERE proforma_id = ?");
            $stmt->execute([$del_id]);
            $conn->commit();
            if ($stmt->rowCount() > 0) $_SESSION['success_msg'] = "Proforma deleted.";
            else $_SESSION['error_msg'] = "Proforma not found.";
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['error_msg'] = "Cannot delete this proforma.";
        }
    }
    header("Location: proformas.php");
    exit;
}

// --- İPTAL (mimar ve admin) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_id'])) {
    $stmt = $conn->prepare("UPDATE proformas SET status = 'CANCELLED' WHERE proforma_id = ? AND status != 'CANCELLED'");
    $stmt->execute([(int)$_POST['cancel_id']]);
    $_SESSION[$stmt->rowCount() ? 'success_msg' : 'error_msg'] = $stmt->rowCount() ? "Proforma cancelled." : "This proforma is already cancelled.";
    header("Location: proformas.php");
    exit;
}
if (isset($_SESSION['success_msg'])) { $success_message = $_SESSION['success_msg']; unset($_SESSION['success_msg']); }
if (isset($_SESSION['error_msg'])) { $error_message = $_SESSION['error_msg']; unset($_SESSION['error_msg']); }

// Arama ve Sayfalama
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
$limit = 20;
$offset = ($page - 1) * $limit;

$whereSql = "";
$params = [];
if (!empty($search)) {
    $whereSql = " WHERE p.proforma_no LIKE ? OR p.proforma_name LIKE ? OR c.customer_name LIKE ?";
    $searchWildcard = "%$search%";
    $params = [$searchWildcard, $searchWildcard, $searchWildcard];
}

$countStmt = $conn->prepare("SELECT COUNT(*) FROM proformas p LEFT JOIN customers c ON p.customer_id = c.customer_id" . $whereSql);
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$query = "SELECT p.*, c.customer_name 
          FROM proformas p 
          LEFT JOIN customers c ON p.customer_id = c.customer_id 
          $whereSql 
          ORDER BY p.created_at DESC 
          LIMIT $limit OFFSET $offset";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$proformas = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Proformas (Quotations)</h2>
        <a href="add_proforma.php" class="btn btn-primary">
            <i class="fa-solid fa-plus me-2"></i> Create New Proforma
        </a>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">All Proformas (<?php echo number_format($totalRows); ?> found)</h5>
            <form method="GET" class="d-flex">
                <input type="text" name="search" class="form-control form-control-sm me-2" placeholder="Search No, Name or Customer..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-search"></i></button>
                <?php if($search): ?>
                    <a href="proformas.php" class="btn btn-sm btn-outline-danger ms-1" title="Clear Search"><i class="fa-solid fa-times"></i></a>
                <?php endif; ?>
            </form>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Proforma No / Title</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Total Amount</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($proformas) > 0): ?>
                        <?php foreach ($proformas as $prof): 
                            $badgeClass = 'bg-secondary';
                            if ($prof['status'] == 'SENT') $badgeClass = 'bg-info text-dark';
                            if ($prof['status'] == 'CONFIRMED') $badgeClass = 'bg-success';
                            if ($prof['status'] == 'CANCELLED') $badgeClass = 'bg-danger';
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($prof['proforma_no']); ?></strong>
                                    <?php if(!empty($prof['proforma_name'])): ?>
                                        <br><span class="text-muted" style="font-size: 0.85rem;"><?php echo htmlspecialchars($prof['proforma_name']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d M Y', strtotime($prof['proforma_date'])); ?></td>
                                <td><?php echo htmlspecialchars($prof['customer_name']); ?></td>
                                <td class="fw-bold">
                                    <?php 
                                        $symbol = $prof['currency'] == 'USD' ? '$' : '₦';
                                        $decimals = $prof['currency'] == 'USD' ? 2 : 0;
                                        echo $symbol . number_format($prof['grand_total'], $decimals); 
                                    ?>
                                </td>
                                <td><?php echo status_badge($prof['status']); ?></td>
                                <td class="text-end">
                                    <a href="view_proforma.php?id=<?php echo e($prof['proforma_id']); ?>" class="btn btn-sm btn-outline-success" title="View / Print PDF" target="_blank"><i class="fa-solid fa-print"></i></a>
                                    <a href="edit_proforma.php?id=<?php echo e($prof['proforma_id']); ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-edit"></i></a>
                                    <a href="add_proforma.php?copy=<?php echo (int)$prof['proforma_id']; ?>" class="btn btn-sm btn-outline-secondary" title="Copy to a new proforma"><i class="fa-solid fa-copy"></i></a>
                                    <?php if ($prof['status'] !== 'CANCELLED'): ?>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Cancel proforma <?php echo e($prof['proforma_no']); ?>? It stays in the list marked as cancelled.');">
                                        <input type="hidden" name="cancel_id" value="<?php echo e($prof['proforma_id']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Cancel proforma"><i class="fa-solid fa-ban"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (is_admin()): ?>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete proforma <?php echo e($prof['proforma_no']); ?> permanently? This cannot be undone.');">
                                        <input type="hidden" name="delete_id" value="<?php echo e($prof['proforma_id']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete permanently (admin)"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No proformas created yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>