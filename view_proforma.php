<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

$proforma_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($proforma_id === 0) {
    header("Location: proformas.php"); exit;
}

// 1. Proforma ve Müşteri Bilgilerini Çek
$stmtProf = $conn->prepare("
    SELECT p.*, c.customer_name, c.contact_person, c.phone, c.email, c.address 
    FROM proformas p 
    LEFT JOIN customers c ON p.customer_id = c.customer_id 
    WHERE p.proforma_id = ?
");
$stmtProf->execute([$proforma_id]);
$proforma = $stmtProf->fetch(PDO::FETCH_ASSOC);

if (!$proforma) {
    http_response_code(404); die("Proforma not found.");
}

// 2. Ürün Kalemlerini Çek
$stmtItems = $conn->prepare("
    SELECT pi.*, pr.stock_code, pr.stock_name, pr.image, pr.image_path 
    FROM proforma_items pi 
    LEFT JOIN products pr ON pi.product_id = pr.product_id 
    WHERE pi.proforma_id = ?
    ORDER BY pi.item_id
");
$stmtItems->execute([$proforma_id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// 3. Ekstra Servisleri Çek
$stmtSrv = $conn->prepare("
    SELECT ps.*, s.service_name 
    FROM proforma_services ps 
    LEFT JOIN additional_services s ON ps.service_id = s.service_id 
    WHERE ps.proforma_id = ?
");
$stmtSrv->execute([$proforma_id]);
$services = $stmtSrv->fetchAll(PDO::FETCH_ASSOC);

$stmtSet = $conn->query("SELECT vat_rate FROM system_settings WHERE setting_id = 1");
$vat_rate = (float)$stmtSet->fetchColumn();

$vat_amount = $proforma['grand_total'] - ($proforma['grand_total'] / (1 + ($vat_rate / 100)));

$currencySymbol = $proforma['currency'] == 'USD' ? '$' : '₦';
$decimals = $proforma['currency'] == 'USD' ? 2 : 0;

$subtotal = 0;
foreach($items as $item) {
    $subtotal += ($item['quantity'] * $item['unit_price']);
}

$services_total = 0;
foreach($services as $srv) {
    $services_total += (float)$srv['service_amount'];
}
$net_total = $proforma['grand_total'] - $vat_amount;
$fmt = fn($v) => $currencySymbol . number_format((float)$v, $decimals);
$status = strtoupper($proforma['status'] ?? 'DRAFT');
$issuer = $_SESSION['full_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($proforma['proforma_no']); ?> · <?php echo e($proforma['customer_name']); ?></title>
    <link rel="stylesheet" href="<?php echo asset('assets/vendor/fontawesome/css/all.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/proforma.css'); ?>">
</head>
<body>
    <div class="pf-toolbar no-print">
        <a href="proformas.php" class="pf-btn"><i class="fa-solid fa-arrow-left"></i> Proformas</a>
        <div class="pf-toolbar-right">
            <span class="pf-state pf-state-<?php echo e(strtolower($status)); ?>"><?php echo e(ucfirst(strtolower($status === 'REVIEW' ? 'In review' : $status))); ?></span>
            <a href="edit_proforma.php?id=<?php echo (int)$proforma_id; ?>" class="pf-btn"><i class="fa-solid fa-pen"></i> Edit</a>
            <a href="add_proforma.php?copy=<?php echo (int)$proforma_id; ?>" class="pf-btn"><i class="fa-solid fa-copy"></i> Copy</a>
            <button type="button" onclick="window.print()" class="pf-btn pf-btn-dark"><i class="fa-solid fa-print"></i> Print or save as PDF</button>
        </div>
    </div>

    <article class="pf-sheet">
        <?php if (in_array($status, ['DRAFT', 'CANCELLED'], true)): ?>
            <div class="pf-watermark" aria-hidden="true"><?php echo $status === 'DRAFT' ? 'Draft' : 'Cancelled'; ?></div>
        <?php endif; ?>

        <header class="pf-head">
            <div>
                <div class="pf-brand">Furniture Republic</div>
                <div class="pf-brand-sub">by Fırat Building Materials Ltd.</div>
            </div>
            <div class="pf-title">
                <h1>Proforma invoice</h1>
                <div class="pf-no"><?php echo e($proforma['proforma_no']); ?></div>
            </div>
        </header>

        <section class="pf-parties">
            <div>
                <div class="pf-label">Prepared for</div>
                <div class="pf-party-name"><?php echo e($proforma['customer_name']); ?></div>
                <?php if (!empty($proforma['contact_person'])): ?><div><?php echo e($proforma['contact_person']); ?></div><?php endif; ?>
                <?php if (!empty($proforma['address'])): ?><div class="pf-muted"><?php echo nl2br(e($proforma['address'])); ?></div><?php endif; ?>
                <?php if (!empty($proforma['phone'])): ?><div class="pf-muted"><?php echo e($proforma['phone']); ?></div><?php endif; ?>
                <?php if (!empty($proforma['email'])): ?><div class="pf-muted"><?php echo e($proforma['email']); ?></div><?php endif; ?>
            </div>
            <div>
                <div class="pf-label">From</div>
                <div class="pf-party-name">Furniture Republic</div>
                <div class="pf-muted">Plot 267, Cadastral Zone B01<br>Ahmadu Bello Way, Kado<br>Abuja, FCT, Nigeria<br>08179645568</div>
            </div>
            <dl class="pf-meta">
                <dt>Date</dt><dd><?php echo e(date('j F Y', strtotime($proforma['proforma_date']))); ?></dd>
                <?php if (!empty($proforma['proforma_name'])): ?><dt>Reference</dt><dd><?php echo e($proforma['proforma_name']); ?></dd><?php endif; ?>
                <dt>Currency</dt><dd><?php echo e($proforma['currency']); ?></dd>
                <?php if ($proforma['currency'] === 'NGN' && (float)$proforma['ngn_rate'] > 1): ?><dt>Rate</dt><dd>₦<?php echo number_format((float)$proforma['ngn_rate'], 0); ?> / USD</dd><?php endif; ?>
            </dl>
        </section>

        <table class="pf-items">
            <thead>
                <tr>
                    <th class="pf-col-img"></th>
                    <th>Item</th>
                    <th class="pf-num">Qty</th>
                    <th class="pf-num">Unit price</th>
                    <th class="pf-num">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item):
                $img = '';
                foreach (['image', 'image_path'] as $k) {
                    if (!empty($item[$k]) && is_file(__DIR__ . '/' . ltrim($item[$k], '/'))) { $img = $item[$k]; break; }
                }
            ?>
                <tr>
                    <td class="pf-col-img"><?php if ($img): ?><img src="<?php echo e($img); ?>" alt=""><?php else: ?><span class="pf-noimg"></span><?php endif; ?></td>
                    <td>
                        <div class="pf-item-name"><?php echo e($item['stock_name']); ?></div>
                        <div class="pf-item-code"><?php echo e($item['stock_code']); ?></div>
                    </td>
                    <td class="pf-num"><?php echo e(rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ','), '0'), '.')); ?></td>
                    <td class="pf-num"><?php echo $fmt($item['unit_price']); ?></td>
                    <td class="pf-num pf-strong"><?php echo $fmt($item['quantity'] * $item['unit_price']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($services as $srv): ?>
                <tr class="pf-service">
                    <td class="pf-col-img"></td>
                    <td><div class="pf-item-name"><?php echo e($srv['service_name']); ?></div><div class="pf-item-code">Service</div></td>
                    <td class="pf-num"></td>
                    <td class="pf-num"></td>
                    <td class="pf-num pf-strong"><?php echo $fmt($srv['service_amount']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <section class="pf-bottom">
            <div class="pf-notes">
                <?php if (!empty(trim($proforma['notes'] ?? ''))): ?>
                    <div class="pf-label">Terms and notes</div>
                    <p><?php echo nl2br(e($proforma['notes'])); ?></p>
                <?php endif; ?>
                <p class="pf-muted pf-small">Prices include VAT at <?php echo e(rtrim(rtrim(number_format($vat_rate, 2), '0'), '.')); ?>%. This proforma is an offer and not a tax invoice.</p>
            </div>
            <div class="pf-totals">
                <div class="pf-trow"><span>Products</span><span><?php echo $fmt($subtotal); ?></span></div>
                <?php if ($services): ?><div class="pf-trow"><span>Services</span><span><?php echo $fmt($services_total); ?></span></div><?php endif; ?>
                <div class="pf-trow pf-muted"><span>Total before VAT</span><span><?php echo $fmt($net_total); ?></span></div>
                <div class="pf-trow pf-muted"><span>VAT <?php echo e(rtrim(rtrim(number_format($vat_rate, 2), '0'), '.')); ?>% (included)</span><span><?php echo $fmt($vat_amount); ?></span></div>
                <div class="pf-grand"><span>Total</span><span><?php echo $fmt($proforma['grand_total']); ?></span></div>
            </div>
        </section>

        <footer class="pf-sign">
            <div><div class="pf-line"></div>Issued by<?php echo $issuer ? ' · ' . e($issuer) : ''; ?></div>
            <div><div class="pf-line"></div>Accepted by customer · signature and date</div>
        </footer>
    </article>
</body>
</html>
