<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

$project_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($project_id === 0) {
    header("Location: projects.php"); exit;
}

// 1. Proje ve Müşteri Bilgilerini Çek
$stmtProj = $conn->prepare("
    SELECT p.*, c.customer_name, c.contact_person, c.phone, c.email, c.address
    FROM projects p
    LEFT JOIN customers c ON p.customer_id = c.customer_id
    WHERE p.project_id = ?
");
$stmtProj->execute([$project_id]);
$project = $stmtProj->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    http_response_code(404); die("Project not found.");
}

// 2. Projeye Ait Tüm Proformaları Çek
$stmtProformas = $conn->prepare("
    SELECT proforma_id, proforma_no, proforma_name, proforma_date, currency, grand_total, status
    FROM proformas
    WHERE project_id = ?
    ORDER BY proforma_date ASC, proforma_id ASC
");
$stmtProformas->execute([$project_id]);
$proformas = $stmtProformas->fetchAll(PDO::FETCH_ASSOC);

// Toplamları Hesapla (İptal edilenler hariç)
$total_usd = 0;
$total_ngn = 0;

foreach ($proformas as $prof) {
    if ($prof['status'] !== 'CANCELLED') {
        if ($prof['currency'] == 'USD') {
            $total_usd += $prof['grand_total'];
        } else if ($prof['currency'] == 'NGN') {
            $total_ngn += $prof['grand_total'];
        }
    }
}

// Ayrıntılı görünüm: her proformanın kalemleri ve hizmetleri
$detail = !empty($_GET['detail']);
$itemsBy = []; $servicesBy = [];
$ids = array_column($proformas, 'proforma_id');
if ($detail && $ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $conn->prepare("SELECT pi.proforma_id, pi.quantity, pi.unit_price, pr.stock_code, pr.stock_name
        FROM proforma_items pi LEFT JOIN products pr ON pi.product_id = pr.product_id
        WHERE pi.proforma_id IN ($in) ORDER BY pi.proforma_id, pi.item_id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $itemsBy[$r['proforma_id']][] = $r;
    $st = $conn->prepare("SELECT ps.proforma_id, ps.service_amount, s.service_name
        FROM proforma_services ps LEFT JOIN additional_services s ON ps.service_id = s.service_id
        WHERE ps.proforma_id IN ($in) ORDER BY ps.proforma_id, ps.id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $servicesBy[$r['proforma_id']][] = $r;
}

$vat_rate = (float)$conn->query("SELECT vat_rate FROM system_settings WHERE setting_id = 1")->fetchColumn();
$money = fn($cur, $v) => $cur === 'USD' ? '$' . number_format((float)$v, 2) : '₦' . number_format((float)$v, 0);
$qtyFmt = fn($q) => rtrim(rtrim(number_format((float)$q, 2, '.', ','), '0'), '.');
$statusLabel = ['DRAFT' => 'Draft', 'REVIEW' => 'In review', 'CONFIRMED' => 'Confirmed', 'CANCELLED' => 'Cancelled'];
$active = array_filter($proformas, fn($p) => $p['status'] !== 'CANCELLED');
$fmtVat = rtrim(rtrim(number_format($vat_rate, 2), '0'), '.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master quotation · <?php echo e($project['project_name']); ?></title>
    <link rel="stylesheet" href="<?php echo asset('assets/vendor/fontawesome/css/all.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/proforma.css'); ?>">
    <style>
        .pm-toggle { display: inline-flex; border: 1px solid #D3C6B3; border-radius: 5px; overflow: hidden; }
        .pm-toggle a { padding: 7px 14px; font: 600 13px "DM Sans", sans-serif; color: var(--walnut); text-decoration: none; background: #FBF8F2; }
        .pm-toggle a.on { background: var(--walnut); color: #F7EFE3; }
        .pm-num { width: 9mm; color: var(--oak); }
        .pm-cancelled td { color: #A89884; }
        .pm-cancelled .pm-amt { text-decoration: line-through; }
        .pm-status { font-size: 7.5pt; font-weight: 600; color: var(--oak); }
        .pm-status-CONFIRMED { color: #5D6A3A; } .pm-status-REVIEW { color: #B07A1E; } .pm-status-CANCELLED { color: #8B2F2B; }
        .pm-group { margin-top: 7mm; break-inside: auto; }
        .pm-group-head { display: flex; justify-content: space-between; align-items: baseline; padding-bottom: 5px; border-bottom: 1px solid var(--walnut); }
        .pm-group-head h2 { margin: 0; font: 400 12.5pt "Cardo", serif; color: var(--walnut); }
        .pm-group-head span { font-size: 8pt; color: var(--oak); }
        .pm-group .pf-items th { border-bottom: 1px solid var(--line); padding-top: 6px; }
        .pm-subtotal td { font-weight: 650; border-bottom: 0 !important; padding-top: 6px !important; }
        .pm-cancel-note { font-size: 8pt; color: #8B2F2B; margin: 4px 0 0; }
        .pm-empty { padding: 10mm 0; text-align: center; color: var(--oak); }
    </style>
</head>
<body>
    <div class="pf-toolbar no-print">
        <a href="projects.php" class="pf-btn"><i class="fa-solid fa-arrow-left"></i> Projects</a>
        <div class="pf-toolbar-right">
            <div class="pm-toggle" role="group" aria-label="View">
                <a href="view_project.php?id=<?php echo (int)$project_id; ?>" class="<?php echo $detail ? '' : 'on'; ?>">Summary</a>
                <a href="view_project.php?id=<?php echo (int)$project_id; ?>&amp;detail=1" class="<?php echo $detail ? 'on' : ''; ?>">With all items</a>
            </div>
            <a href="add_proforma.php?customer_id=<?php echo (int)$project['customer_id']; ?>&amp;project_id=<?php echo (int)$project_id; ?>" class="pf-btn"><i class="fa-solid fa-plus"></i> New proforma</a>
            <button type="button" onclick="window.print()" class="pf-btn pf-btn-dark"><i class="fa-solid fa-print"></i> Print or save as PDF</button>
        </div>
    </div>

    <article class="pf-sheet">
        <header class="pf-head">
            <div>
                <div class="pf-brand">Furniture Republic</div>
                <div class="pf-brand-sub">by Fırat Building Materials Ltd.</div>
            </div>
            <div class="pf-title">
                <h1>Master quotation</h1>
                <div class="pf-no"><?php echo e($project['project_name']); ?></div>
            </div>
        </header>

        <section class="pf-parties">
            <div>
                <div class="pf-label">Prepared for</div>
                <div class="pf-party-name"><?php echo e($project['customer_name']); ?></div>
                <?php if (!empty($project['contact_person'])): ?><div><?php echo e($project['contact_person']); ?></div><?php endif; ?>
                <?php if (!empty($project['address'])): ?><div class="pf-muted"><?php echo nl2br(e($project['address'])); ?></div><?php endif; ?>
                <?php if (!empty($project['phone'])): ?><div class="pf-muted"><?php echo e($project['phone']); ?></div><?php endif; ?>
                <?php if (!empty($project['email'])): ?><div class="pf-muted"><?php echo e($project['email']); ?></div><?php endif; ?>
            </div>
            <div>
                <div class="pf-label">Project</div>
                <div class="pf-party-name"><?php echo e($project['project_name']); ?></div>
                <?php if (!empty($project['project_description'])): ?><div class="pf-muted"><?php echo nl2br(e($project['project_description'])); ?></div><?php endif; ?>
            </div>
            <dl class="pf-meta">
                <dt>Date</dt><dd><?php echo date('j F Y'); ?></dd>
                <dt>Status</dt><dd><?php echo e(ucfirst(strtolower($project['project_status']))); ?></dd>
                <dt>Quotations</dt><dd><?php echo count($active); ?><?php echo count($proformas) > count($active) ? ' (+' . (count($proformas) - count($active)) . ' cancelled)' : ''; ?></dd>
            </dl>
        </section>

        <?php if (!$proformas): ?>
            <div class="pm-empty">No proformas have been created for this project yet.</div>
        <?php elseif (!$detail): ?>
            <!-- Özet: her proforma bir satır -->
            <table class="pf-items">
                <thead><tr><th class="pm-num">#</th><th>Quotation</th><th>Date</th><th>Status</th><th class="pf-num">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($proformas as $i => $p): $c = $p['status'] === 'CANCELLED'; ?>
                    <tr class="<?php echo $c ? 'pm-cancelled' : ''; ?>">
                        <td class="pm-num"><?php echo $i + 1; ?></td>
                        <td><div class="pf-item-name"><?php echo e($p['proforma_name'] ?: $p['proforma_no']); ?></div><div class="pf-item-code"><?php echo e($p['proforma_no']); ?></div></td>
                        <td><?php echo e(date('j M Y', strtotime($p['proforma_date']))); ?></td>
                        <td><span class="pm-status pm-status-<?php echo e($p['status']); ?>"><?php echo e($statusLabel[$p['status']] ?? $p['status']); ?></span></td>
                        <td class="pf-num pf-strong pm-amt"><?php echo $money($p['currency'], $p['grand_total']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <!-- Ayrıntılı: her proforma kendi kalemleriyle -->
            <?php foreach ($proformas as $i => $p): $c = $p['status'] === 'CANCELLED'; $cur = $p['currency']; ?>
            <section class="pm-group <?php echo $c ? 'pm-cancelled' : ''; ?>">
                <div class="pm-group-head">
                    <h2><?php echo ($i + 1) . '. ' . e($p['proforma_name'] ?: $p['proforma_no']); ?></h2>
                    <span><?php echo e($p['proforma_no']); ?> · <?php echo e(date('j M Y', strtotime($p['proforma_date']))); ?> · <span class="pm-status pm-status-<?php echo e($p['status']); ?>"><?php echo e($statusLabel[$p['status']] ?? $p['status']); ?></span></span>
                </div>
                <?php if ($c): ?><p class="pm-cancel-note">Cancelled. Not included in the project total.</p><?php endif; ?>
                <table class="pf-items">
                    <thead><tr><th>Item</th><th class="pf-num">Qty</th><th class="pf-num">Unit price</th><th class="pf-num">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($itemsBy[$p['proforma_id']] ?? [] as $it): ?>
                        <tr>
                            <td><div class="pf-item-name"><?php echo e($it['stock_name'] ?? 'Deleted product'); ?></div><div class="pf-item-code"><?php echo e($it['stock_code'] ?? ''); ?></div></td>
                            <td class="pf-num"><?php echo e($qtyFmt($it['quantity'])); ?></td>
                            <td class="pf-num"><?php echo $money($cur, $it['unit_price']); ?></td>
                            <td class="pf-num pf-strong"><?php echo $money($cur, $it['quantity'] * $it['unit_price']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($servicesBy[$p['proforma_id']] ?? [] as $sv): ?>
                        <tr class="pf-service">
                            <td><div class="pf-item-name"><?php echo e($sv['service_name']); ?></div><div class="pf-item-code">Service</div></td>
                            <td class="pf-num"></td><td class="pf-num"></td>
                            <td class="pf-num pf-strong"><?php echo $money($cur, $sv['service_amount']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr class="pm-subtotal"><td colspan="3" class="pf-num">Quotation total</td><td class="pf-num pm-amt"><?php echo $money($cur, $p['grand_total']); ?></td></tr>
                    </tbody>
                </table>
            </section>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($proformas): ?>
        <section class="pf-bottom">
            <div class="pf-notes">
                <p class="pf-muted pf-small">Cancelled quotations are not included in the total. Prices include VAT at <?php echo e($fmtVat); ?>%.
                <?php if (!$detail): ?>For the item breakdown, see each proforma by its number.<?php endif; ?></p>
            </div>
            <div class="pf-totals">
                <?php
                $byCur = ['NGN' => 0, 'USD' => 0];
                foreach ($active as $p) $byCur[$p['currency'] === 'USD' ? 'USD' : 'NGN'] += (float)$p['grand_total'];
                $shown = array_filter($byCur);
                foreach ($shown as $cur => $tot):
                    $vat = $tot - $tot / (1 + $vat_rate / 100);
                ?>
                    <?php if (count($shown) > 1): ?><div class="pf-label" style="margin-top:8px"><?php echo $cur === 'USD' ? 'US dollar quotations' : 'Naira quotations'; ?></div><?php endif; ?>
                    <div class="pf-trow pf-muted"><span>Total before VAT</span><span><?php echo $money($cur, $tot - $vat); ?></span></div>
                    <div class="pf-trow pf-muted"><span>VAT <?php echo e($fmtVat); ?>% (included)</span><span><?php echo $money($cur, $vat); ?></span></div>
                    <div class="pf-grand"><span>Project total</span><span><?php echo $money($cur, $tot); ?></span></div>
                <?php endforeach; ?>
                <?php if (!$shown): ?><div class="pf-trow pf-muted"><span>All quotations are cancelled.</span><span></span></div><?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <footer class="pf-sign">
            <div><div class="pf-line"></div>Project manager · Furniture Republic</div>
            <div><div class="pf-line"></div>Accepted by client · signature and date</div>
        </footer>
    </article>
</body>
</html>
