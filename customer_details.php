<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($customer_id === 0) {
    header("Location: customers.php");
    exit;
}

// 1. Müşteri Genel Bilgilerini Çek (Tipi ve İndirimiyle Beraber)
$stmtCust = $conn->prepare("
    SELECT c.*, ct.type_name, ct.discount_rate 
    FROM customers c 
    LEFT JOIN customer_types ct ON c.customer_type_id = ct.type_id 
    WHERE c.customer_id = ?
");
$stmtCust->execute([$customer_id]);
$customer = $stmtCust->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    header("Location: customers.php");
    exit;
}

// 2. Müşterinin Projelerini Çek
$stmtProjects = $conn->prepare("SELECT * FROM projects WHERE customer_id = ? ORDER BY created_at DESC");
$stmtProjects->execute([$customer_id]);
$projects = $stmtProjects->fetchAll(PDO::FETCH_ASSOC);

// 3. Müşterinin Proformalarını Çek (Hangi projeye ait oldukları bilgisiyle)
$stmtProformas = $conn->prepare("
    SELECT p.*, pr.project_name 
    FROM proformas p 
    LEFT JOIN projects pr ON p.project_id = pr.project_id 
    WHERE p.customer_id = ? 
    ORDER BY p.proforma_date DESC, p.created_at DESC
");
$stmtProformas->execute([$customer_id]);
$proformas = $stmtProformas->fetchAll(PDO::FETCH_ASSOC);

// İstatistikler
$total_projects = count($projects);
$total_proformas = count($proformas);
$total_volume_usd = 0;

foreach ($proformas as $prof) {
    if ($prof['status'] !== 'CANCELLED' && $prof['currency'] == 'USD') {
        $total_volume_usd += $prof['grand_total'];
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Customer Profile: <?php echo htmlspecialchars($customer['customer_name']); ?></h2>
        <div>
            <a href="edit_customer.php?id=<?php echo $customer_id; ?>" class="btn btn-primary me-2"><i class="fa-solid fa-edit"></i> Edit Customer</a>
            <a href="customers.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        </div>
    </div>

    <div class="row">
        <!-- SOL KOLON: Müşteri Kartı -->
        <div class="col-md-4 mb-4">
            <div class="card shadow-sm border-0 border-top border-primary border-4 h-100">
                <div class="card-body">
                    <div class="text-center mb-4 mt-3">
                        <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                            <i class="fa-solid fa-building fs-1 text-primary"></i>
                        </div>
                        <h4 class="card-title fw-bold text-dark"><?php echo htmlspecialchars($customer['customer_name']); ?></h4>
                        <span class="badge bg-info text-dark fs-6"><?php echo htmlspecialchars($customer['type_name']); ?> (<?php echo floatval($customer['discount_rate']); ?>% Discount)</span>
                    </div>

                    <ul class="list-group list-group-flush mt-4">
                        <li class="list-group-item px-0 d-flex align-items-center">
                            <i class="fa-regular fa-user text-muted me-3 fs-5" style="width: 25px;"></i>
                            <div>
                                <small class="text-muted d-block">Contact Person</small>
                                <strong><?php echo htmlspecialchars($customer['contact_person']) ?: '-'; ?></strong>
                            </div>
                        </li>
                        <li class="list-group-item px-0 d-flex align-items-center">
                            <i class="fa-solid fa-phone text-muted me-3 fs-5" style="width: 25px;"></i>
                            <div>
                                <small class="text-muted d-block">Phone</small>
                                <strong><?php echo htmlspecialchars($customer['phone']) ?: '-'; ?></strong>
                            </div>
                        </li>
                        <li class="list-group-item px-0 d-flex align-items-center">
                            <i class="fa-regular fa-envelope text-muted me-3 fs-5" style="width: 25px;"></i>
                            <div>
                                <small class="text-muted d-block">Email</small>
                                <strong><?php echo htmlspecialchars($customer['email']) ?: '-'; ?></strong>
                            </div>
                        </li>
                        <li class="list-group-item px-0 d-flex align-items-center border-bottom-0">
                            <i class="fa-solid fa-location-dot text-muted me-3 fs-5" style="width: 25px;"></i>
                            <div>
                                <small class="text-muted d-block">Address</small>
                                <strong><?php echo nl2br(htmlspecialchars($customer['address'])) ?: '-'; ?></strong>
                            </div>
                        </li>
                    </ul>
                </div>
                <div class="card-footer bg-light p-3 border-0 d-flex justify-content-around text-center">
                    <div>
                        <span class="d-block fw-bold fs-4 text-dark"><?php echo $total_projects; ?></span>
                        <small class="text-muted text-uppercase">Projects</small>
                    </div>
                    <div>
                        <span class="d-block fw-bold fs-4 text-dark"><?php echo $total_proformas; ?></span>
                        <small class="text-muted text-uppercase">Quotations</small>
                    </div>
                    <div>
                        <span class="d-block fw-bold fs-4 text-success">$<?php echo number_format($total_volume_usd, 0); ?></span>
                        <small class="text-muted text-uppercase">Active USD Vol.</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- SAĞ KOLON: Projeler ve Proformalar -->
        <div class="col-md-8">
            
            <!-- PROJELER KARTI -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="card-title mb-0 fw-bold"><i class="fa-solid fa-diagram-project text-secondary me-2"></i> Projects</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Project Name</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($total_projects > 0): ?>
                                <?php foreach ($projects as $p): 
                                    $pClass = 'bg-secondary';
                                    if ($p['project_status'] == 'ONGOING') $pClass = 'bg-primary';
                                    if ($p['project_status'] == 'COMPLETED') $pClass = 'bg-success';
                                    if ($p['project_status'] == 'CANCELLED') $pClass = 'bg-danger';
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($p['project_name']); ?></strong></td>
                                        <td><span class="badge <?php echo $pClass; ?>"><?php echo e($p['project_status']); ?></span></td>
                                        <td><?php echo date('d M Y', strtotime($p['created_at'])); ?></td>
                                        <td class="text-end">
                                            <a href="view_project.php?id=<?php echo e($p['project_id']); ?>" class="btn btn-sm btn-outline-success" target="_blank" title="View Summary"><i class="fa-solid fa-print"></i></a>
                                            <a href="edit_project.php?id=<?php echo e($p['project_id']); ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-edit"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">No projects found for this customer.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PROFORMALAR KARTI -->
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="card-title mb-0 fw-bold"><i class="fa-solid fa-file-invoice-dollar text-secondary me-2"></i> Quotations (Proformas)</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Proforma No</th>
                                <th>Linked Project</th>
                                <th>Date</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($total_proformas > 0): ?>
                                <?php foreach ($proformas as $prof): 
                                    $bClass = 'bg-secondary';
                                    if ($prof['status'] == 'REVIEW') $bClass = 'bg-info text-dark';
                                    if ($prof['status'] == 'CONFIRMED') $bClass = 'bg-success';
                                    if ($prof['status'] == 'CANCELLED') $bClass = 'bg-danger';
                                    $sym = $prof['currency'] == 'USD' ? '$' : '₦';
                                    $dec = $prof['currency'] == 'USD' ? 2 : 0;
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($prof['proforma_no']); ?></strong></td>
                                        <td>
                                            <?php if($prof['project_name']): ?>
                                                <small class="text-muted"><i class="fa-solid fa-diagram-project me-1"></i> <?php echo htmlspecialchars($prof['project_name']); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">- General -</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('d M Y', strtotime($prof['proforma_date'])); ?></td>
                                        <td class="fw-bold"><?php echo $sym . number_format($prof['grand_total'], $dec); ?></td>
                                        <td><span class="badge <?php echo $bClass; ?>"><?php echo e($prof['status']); ?></span></td>
                                        <td class="text-end">
                                            <a href="view_proforma.php?id=<?php echo e($prof['proforma_id']); ?>" class="btn btn-sm btn-outline-success" target="_blank"><i class="fa-solid fa-print"></i></a>
                                            <a href="edit_proforma.php?id=<?php echo e($prof['proforma_id']); ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-edit"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No proformas found for this customer.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>