<?php
require_once __DIR__ . '/includes/auth.php';
// Satış görevlilerinin ana sayfası fiyat listesidir
if (is_sales_role()) { header('Location: price_lists.php'); exit; }
$conn = (new Database())->getConnection();
$page_title = 'Dashboard';

$rate = $conn->query("SELECT buy_rate, sale_rate FROM rate_exchange_history WHERE currency_code = 'NGN' ORDER BY created_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$sale_rate = $rate ? (float)$rate['sale_rate'] : 0;

// Proforma özetleri (NGN cinsinden; USD proformalar güncel satış kuruyla çevrilir)
$monthStart = date('Y-m-01');
$summary = $conn->prepare("
    SELECT
        SUM(CASE WHEN status IN ('DRAFT','REVIEW') THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN status IN ('DRAFT','REVIEW') THEN (CASE WHEN currency = 'USD' THEN grand_total * ? ELSE grand_total END) ELSE 0 END) AS open_value,
        SUM(CASE WHEN status = 'CONFIRMED' AND proforma_date >= ? THEN 1 ELSE 0 END) AS conf_count,
        SUM(CASE WHEN status = 'CONFIRMED' AND proforma_date >= ? THEN (CASE WHEN currency = 'USD' THEN grand_total * ? ELSE grand_total END) ELSE 0 END) AS conf_value
    FROM proformas");
$summary->execute([$sale_rate, $monthStart, $monthStart, $sale_rate]);
$sum = $summary->fetch(PDO::FETCH_ASSOC);

$totalProducts = (int)$conn->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalRecipes  = (int)$conn->query("SELECT COUNT(*) FROM product_recipes")->fetchColumn();

$recentProformas = $conn->query("
    SELECT p.proforma_id, p.proforma_no, p.proforma_name, p.grand_total, p.currency, p.status, p.proforma_date, c.customer_name
    FROM proformas p LEFT JOIN customers c ON p.customer_id = c.customer_id
    ORDER BY p.created_at DESC, p.proforma_id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

$recentProjects = $conn->query("
    SELECT p.project_id, p.project_name, p.project_status, p.created_at, c.customer_name
    FROM projects p LEFT JOIN customers c ON p.customer_id = c.customer_id
    ORDER BY p.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

function ngn_short($v) {
    $v = (float)$v;
    if ($v >= 1e9) return '₦' . number_format($v / 1e9, 2) . 'bn';
    if ($v >= 1e6) return '₦' . number_format($v / 1e6, 1) . 'm';
    return '₦' . number_format($v, 0);
}
$first = explode(' ', trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''))[0];

// Otomatik günlük yedek: sayfa kullanıcıya gönderildikten sonra arka planda çalışır
require_once __DIR__ . '/classes/BackupService.php';
$backup = new BackupService($conn);
$lastBackup = $backup->lastDbBackup();
register_shutdown_function(function () use ($backup) {
    while (ob_get_level()) ob_end_flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    ignore_user_abort(true);
    try { $backup->autoIfDue(); } catch (Throwable $e) { error_log('Auto backup failed: ' . $e->getMessage()); }
});

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="fr-main">
    <div class="fr-page-head">
        <div>
            <h1>Good <?php $h = (int)date('G'); echo $h < 12 ? 'morning' : ($h < 17 ? 'afternoon' : 'evening'); ?><?php echo $first ? ', ' . e($first) : ''; ?></h1>
            <p>Prices on this system use the latest exchange rate<?php echo $rate ? ' (₦' . number_format($sale_rate, 0) . ' per dollar)' : ''; ?>.</p>
        </div>
        <div class="fr-actions">
            <a href="price_lists.php" class="btn btn-secondary"><i class="fa-solid fa-tags me-1"></i> Price list</a>
            <a href="add_proforma.php" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> New proforma</a>
        </div>
    </div>

    <?php if (is_admin() && (!$lastBackup || $lastBackup['time'] < time() - 7 * 86400)): ?>
        <div class="alert alert-warning">The last database backup is <?php echo $lastBackup ? 'older than a week' : 'missing'; ?>. <a href="backups.php">Open Backups</a> to create one and download a copy.</div>
    <?php endif; ?>
    <?php if (!$rate): ?>
        <div class="alert alert-warning">No exchange rate has been entered yet. Naira prices cannot be calculated until one is added in <a href="exchange_rates.php">Exchange rates</a>.</div>
    <?php endif; ?>

    <div class="fr-figures">
        <a class="fr-figure" href="proformas.php">
            <div class="fr-figure-label">Open proformas</div>
            <div class="fr-figure-value"><?php echo ngn_short($sum['open_value'] ?? 0); ?></div>
            <div class="fr-figure-note"><?php echo (int)($sum['open_count'] ?? 0); ?> in draft or review</div>
        </a>
        <a class="fr-figure" href="proformas.php">
            <div class="fr-figure-label">Confirmed in <?php echo date('F'); ?></div>
            <div class="fr-figure-value"><?php echo ngn_short($sum['conf_value'] ?? 0); ?></div>
            <div class="fr-figure-note"><?php echo (int)($sum['conf_count'] ?? 0); ?> proformas</div>
        </a>
        <a class="fr-figure" href="price_lists.php">
            <div class="fr-figure-label">Products in catalogue</div>
            <div class="fr-figure-value"><?php echo number_format($totalProducts); ?></div>
            <div class="fr-figure-note"><?php echo number_format($totalRecipes); ?> built from recipes</div>
        </a>
        <div class="fr-figure">
            <div class="fr-figure-label">Exchange rate (USD → NGN)</div>
            <div class="fr-figure-value"><?php echo $rate ? '₦' . number_format($sale_rate, 0) : '—'; ?></div>
            <div class="fr-figure-note"><?php echo $rate ? 'Selling · buying ₦' . number_format((float)$rate['buy_rate'], 0) : 'Not set'; ?></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="card-title">Latest proformas</h2>
                    <a href="proformas.php" class="btn btn-sm btn-outline-secondary">All proformas</a>
                </div>
                <?php if ($recentProformas): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>Number</th><th>Customer</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentProformas as $p): ?>
                            <tr>
                                <td><a href="view_proforma.php?id=<?php echo (int)$p['proforma_id']; ?>" class="fw-semibold text-decoration-none"><?php echo e($p['proforma_no']); ?></a>
                                    <?php if (!empty($p['proforma_name'])): ?><div class="small text-muted"><?php echo e($p['proforma_name']); ?></div><?php endif; ?></td>
                                <td><?php echo e($p['customer_name'] ?? '—'); ?></td>
                                <td><?php echo status_badge($p['status']); ?></td>
                                <td class="text-end fw-semibold"><?php echo ($p['currency'] === 'USD' ? '$' . number_format((float)$p['grand_total'], 2) : '₦' . number_format((float)$p['grand_total'], 0)); ?></td>
                                <td class="text-end text-muted"><?php echo e(date('j M Y', strtotime($p['proforma_date']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="fr-empty">No proformas yet. <a href="add_proforma.php">Create the first one</a>.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="card-title">Recent projects</h2>
                    <a href="projects.php" class="btn btn-sm btn-outline-secondary">All projects</a>
                </div>
                <?php if ($recentProjects): ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($recentProjects as $pr): ?>
                        <li class="list-group-item px-4 py-3">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <a href="view_project.php?id=<?php echo (int)$pr['project_id']; ?>" class="fw-semibold text-decoration-none" style="color:var(--fr-ink)"><?php echo e($pr['project_name']); ?></a>
                                    <div class="small text-muted"><?php echo e($pr['customer_name'] ?? '—'); ?></div>
                                </div>
                                <span class="badge bg-secondary"><?php echo e(ucfirst(strtolower($pr['project_status']))); ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="fr-empty">No projects yet. <a href="projects.php">Add a project</a>.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
