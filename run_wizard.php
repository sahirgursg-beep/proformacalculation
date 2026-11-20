<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/classes/CostEngine.php';
require_once __DIR__ . '/classes/ProformaService.php';
$conn = (new Database())->getConnection();
$costEngine = new CostEngine($conn);

/* ---------- Sihirbaz ---------- */
$wizard_id = (int)($_GET['id'] ?? 0);
$stmtW = $conn->prepare("SELECT * FROM wizard_templates WHERE wizard_id = ? AND status = 'ACTIVE'");
$stmtW->execute([$wizard_id]);
$wizard = $stmtW->fetch(PDO::FETCH_ASSOC);
if (!$wizard) { header('Location: wizards.php'); exit; }
$page_title = $wizard['wizard_name'];

$svc = new ProformaService($conn);
$ngn_sale_rate = $svc->currentNgnRate();

$stmtSteps = $conn->prepare("SELECT * FROM wizard_steps WHERE wizard_id = ? ORDER BY step_order ASC, step_id ASC");
$stmtSteps->execute([$wizard_id]);
$steps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Müşteri (fiyatlar müşteri tipinin indirimiyle gösterilir) ---------- */
$customer_id = (int)($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);
$customer = null;
if ($customer_id) {
    $st = $conn->prepare("SELECT c.customer_id, c.customer_name, COALESCE(ct.type_name, '') AS type_name, COALESCE(ct.discount_rate, 0) AS discount_rate
        FROM customers c LEFT JOIN customer_types ct ON c.customer_type_id = ct.type_id WHERE c.customer_id = ?");
    $st->execute([$customer_id]);
    $customer = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$discount = $customer ? (float)$customer['discount_rate'] : 0;

$projects = [];
if ($customer) {
    $st = $conn->prepare("SELECT project_id, project_name FROM projects WHERE customer_id = ? AND project_status != 'CANCELLED' ORDER BY project_name");
    $st->execute([$customer['customer_id']]);
    $projects = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------- Proforma oluştur ---------- */
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_proforma'])) {
    $project_id = (int)($_POST['project_id'] ?? 0) ?: null;
    if ($project_id && !in_array($project_id, array_map('intval', array_column($projects, 'project_id')), true)) $project_id = null;
    $proforma_name = trim((string)($_POST['proforma_name'] ?? '')) ?: $wizard['wizard_name'];

    $items = [];
    foreach ((array)($_POST['qty'] ?? []) as $pid => $qty) {
        $qty = round((float)$qty, 2);
        if ($qty > 0) $items[(int)$pid] = $qty;   // aynı ürün iki adımda olsa da tek satır
    }

    if (!$customer) {
        $error_message = 'Select a customer first.';
    } elseif (!$items) {
        $error_message = 'Add at least one product before creating the proforma.';
    } elseif ($ngn_sale_rate <= 0) {
        $error_message = 'No NGN exchange rate is set. Add one in Exchange rates first.';
    } else {
        try {
            $costEngine->preload(array_keys($items));
            $data = [
                'customer_id' => $customer['customer_id'], 'project_id' => $project_id, 'proforma_name' => $proforma_name,
                'proforma_date' => date('Y-m-d'), 'currency' => 'NGN', 'discount_rate' => $discount, 'status' => 'DRAFT',
                'notes' => '', 'items' => [], 'services' => [],
            ];
            foreach ($items as $pid => $qty) {
                $q = $costEngine->quote($pid, $discount, 'NGN', $ngn_sale_rate);
                $data['items'][] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $q['price']];
            }
            $proforma_id = $svc->save($data);
            header('Location: view_proforma.php?id=' . $proforma_id);
            exit;
        } catch (Throwable $e) {
            error_log('Wizard proforma error: ' . $e->getMessage());
            $error_message = 'The proforma could not be created: ' . e($e->getMessage());
        }
    }
}

/* ---------- Adım ürünleri (alt kategoriler dahil) ---------- */
$stepProducts = [];
if ($customer) {
    foreach ($steps as $step) {
        $cats = category_with_descendants($conn, [(int)$step['category_id']]);
        $in = implode(',', array_fill(0, count($cats), '?'));
        $st = $conn->prepare("SELECT product_id, stock_code, stock_name, image FROM products WHERE category_id IN ($in) ORDER BY stock_code ASC");
        $st->execute($cats);
        $stepProducts[$step['step_id']] = $st->fetchAll(PDO::FETCH_ASSOC);
        $costEngine->preload(array_column($stepProducts[$step['step_id']], 'product_id'));
    }
}
$postedQty = array_map('floatval', (array)($_POST['qty'] ?? []));

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<style>
    .wz-steps { display: flex; gap: 0; border-bottom: 1px solid var(--fr-line); overflow-x: auto; }
    .wz-steps button { border: 0; background: none; padding: 14px 18px; color: var(--fr-oak); font-weight: 600; font-size: .86rem; border-bottom: 2px solid transparent; white-space: nowrap; }
    .wz-steps button .c { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; border: 1px solid var(--fr-line-2); font-size: .72rem; margin-right: 7px; }
    .wz-steps button.active { color: var(--fr-walnut); border-bottom-color: var(--fr-walnut); }
    .wz-steps button.active .c { background: var(--fr-walnut); border-color: var(--fr-walnut); color: #F4ECDF; }
    .wz-steps button.done .c { background: rgba(93,106,58,.15); border-color: transparent; color: var(--fr-olive); }
    .wz-pane { display: none; } .wz-pane.active { display: block; }
    .wz-tablewrap { overflow-x: auto; }
    .wz-table { table-layout: fixed; width: 100%; min-width: 320px; }
    .wz-table col.wz-c-price { width: 7.5rem; }
    .wz-table col.wz-c-qty { width: 9.5rem; }
    .wz-table > :not(caption) > * > .wz-qcell { padding-left: .4rem; padding-right: .6rem; }
    .wz-table td.wz-price { white-space: nowrap; font-size: .82rem; padding-left: .3rem; }
    .wz-table td.wz-name { overflow-wrap: anywhere; }
    .wz-name .wz-pname { font-size: .8rem; font-weight: 600; line-height: 1.3; }
    .wz-name .wz-pcode { font-size: .72rem; color: var(--fr-oak); margin-top: 2px; }
    .wz-qty { display: flex; align-items: center; justify-content: flex-end; gap: 4px; flex-wrap: nowrap; }
    .wz-qty input { width: 52px; min-width: 0; flex: 0 0 52px; text-align: center; font-weight: 600; padding-left: .2rem; padding-right: .2rem; }
    .wz-qty button { width: 28px; height: 30px; padding: 0; flex: 0 0 28px; }
    tr.wz-selected > td { background: rgba(93,106,58,.07); }
    .wz-banner { width: 100%; border: 1px solid var(--fr-line); border-radius: 6px; background: #fff; object-fit: contain; }
    @media (min-width: 1400px) { .wz-banner { position: sticky; top: 70px; } }
    .wz-cart { position: sticky; top: 70px; }
    .wz-cart-list { max-height: calc(100vh - 330px); overflow-y: auto; }
    .wz-cart-row { display: flex; justify-content: space-between; gap: 12px; padding: 10px 20px; border-bottom: 1px solid var(--fr-line); font-size: .84rem; }
    .wz-cart-row:last-child { border-bottom: 0; }
</style>

<main class="fr-main">
    <div class="fr-page-head">
        <div>
            <h1><?php echo e($wizard['wizard_name']); ?></h1>
            <p><?php if ($customer): ?>For <strong><?php echo e($customer['customer_name']); ?></strong><?php echo $customer['type_name'] !== '' ? ' · ' . e($customer['type_name']) : ''; ?><?php echo $discount > 0 ? ' · prices include the ' . e(rtrim(rtrim(number_format($discount, 2), '0'), '.')) . '% customer discount' : ''; ?>.
               <?php else: ?><?php echo e($wizard['description'] ?: 'Build a quotation step by step.'); ?><?php endif; ?></p>
        </div>
        <div class="fr-actions">
            <?php if ($customer): ?><a href="run_wizard.php?id=<?php echo $wizard_id; ?>" class="btn btn-secondary" onclick="return !wzHasItems() || confirm('Change customer? Quantities you entered will be cleared.');">Change customer</a><?php endif; ?>
            <a href="index.php" class="btn btn-secondary">Close</a>
        </div>
    </div>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>

    <?php if (!$customer): ?>
        <!-- 1. Müşteri seçimi -->
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Who is this quotation for?</h2></div>
                    <div class="card-body">
                        <form method="GET">
                            <input type="hidden" name="id" value="<?php echo $wizard_id; ?>">
                            <label class="form-label" for="wzCustomer">Customer</label>
                            <select id="wzCustomer" name="customer_id" class="form-select mb-2" required>
                                <option value="">Select a customer</option>
                                <?php foreach ($conn->query("SELECT c.customer_id, c.customer_name, COALESCE(ct.discount_rate,0) d FROM customers c LEFT JOIN customer_types ct ON c.customer_type_id = ct.type_id ORDER BY c.customer_name") as $c): ?>
                                    <option value="<?php echo (int)$c['customer_id']; ?>"><?php echo e($c['customer_name']); ?><?php echo $c['d'] > 0 ? ' (−' . e(rtrim(rtrim(number_format($c['d'], 2), '0'), '.')) . '%)' : ''; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="small text-muted">Prices in the following steps use this customer’s discount. A project can be chosen at the end (optional).</p>
                            <button type="submit" class="btn btn-primary w-100">Start</button>
                        </form>
                        <p class="small text-muted mt-3 mb-0">Customer not in the list? <a href="customers.php">Add the customer</a> first.</p>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif (!$steps): ?>
        <div class="card"><div class="fr-empty">This wizard has no steps yet. <a href="wizard_steps.php?id=<?php echo $wizard_id; ?>">Add steps</a>.</div></div>
    <?php else: ?>
    <form method="POST" id="wizardForm" action="run_wizard.php?id=<?php echo $wizard_id; ?>&customer_id=<?php echo (int)$customer['customer_id']; ?>">
        <input type="hidden" name="customer_id" value="<?php echo (int)$customer['customer_id']; ?>">
        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card">
                    <nav class="wz-steps" aria-label="Wizard steps">
                        <?php foreach ($steps as $i => $step): ?>
                            <button type="button" class="<?php echo $i === 0 ? 'active' : ''; ?>" data-step="<?php echo $i; ?>"><span class="c"><?php echo $i + 1; ?></span><?php echo e($step['step_title']); ?></button>
                        <?php endforeach; ?>
                        <button type="button" data-step="<?php echo count($steps); ?>"><span class="c"><i class="fa-solid fa-check"></i></span>Finish</button>
                    </nav>

                    <?php foreach ($steps as $i => $step):
                        $products = $stepProducts[$step['step_id']] ?? [];
                        $isGrid = $step['view_type'] === 'IMAGE_GRID';
                    ?>
                    <section class="wz-pane card-body <?php echo $i === 0 ? 'active' : ''; ?>" data-pane="<?php echo $i; ?>">
                        <input type="search" class="form-control mb-3 wz-search" placeholder="Search this step by code or name" aria-label="Search products in <?php echo e($step['step_title']); ?>">
                        <div class="row g-3">
                            <?php if ($isGrid): ?>
                            <div class="col-xxl-4">
                                <?php if (!empty($step['step_banner_image']) && is_file(__DIR__ . '/' . $step['step_banner_image'])): ?>
                                    <img src="<?php echo e($step['step_banner_image']); ?>" class="wz-banner" alt="<?php echo e($step['step_title']); ?> layout">
                                <?php else: ?>
                                    <div class="fr-empty border rounded">No layout image for this step.</div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <div class="<?php echo $isGrid ? 'col-xxl-8' : 'col-12'; ?>">
                                <?php if ($products): ?>
                                <div class="border rounded wz-tablewrap">
                                    <table class="table align-middle mb-0 wz-table">
                                        <colgroup><col><col class="wz-c-price"><col class="wz-c-qty"></colgroup>
                                        <thead><tr><th>Product</th><th class="text-end">Unit price</th><th class="text-end wz-qcell">Quantity</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($products as $p):
                                            $price = $costEngine->quote((int)$p['product_id'], $discount, 'NGN', $ngn_sale_rate)['price'];
                                            $pid = (int)$p['product_id'];
                                            $q = $postedQty[$pid] ?? 0;
                                        ?>
                                            <tr class="wz-row" data-pid="<?php echo $pid; ?>">
                                                <td class="wz-name"><div class="wz-pname"><?php echo e($p['stock_name']); ?></div><div class="wz-pcode"><?php echo e($p['stock_code']); ?></div></td>
                                                <td class="text-end fw-semibold wz-price">₦<?php echo number_format($price, 0); ?></td>
                                                <td class="wz-qcell">
                                                    <div class="wz-qty">
                                                        <button type="button" class="btn btn-outline-secondary" data-d="-1" aria-label="Less">−</button>
                                                        <input type="number" name="qty[<?php echo $pid; ?>]" class="form-control form-control-sm wz-q" value="<?php echo e($q ? rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.') : 0); ?>" min="0" step="any" inputmode="decimal"
                                                               data-pid="<?php echo $pid; ?>" data-price="<?php echo (float)$price; ?>" data-name="<?php echo e($p['stock_code'] . ' · ' . $p['stock_name']); ?>" aria-label="Quantity for <?php echo e($p['stock_code']); ?>">
                                                        <button type="button" class="btn btn-outline-secondary" data-d="1" aria-label="More">+</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                    <div class="fr-empty border rounded">No products in this step’s category (or its sub-categories).</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between mt-4">
                            <?php if ($i > 0): ?><button type="button" class="btn btn-secondary wz-go" data-go="<?php echo $i - 1; ?>">Previous</button><?php else: ?><span></span><?php endif; ?>
                            <button type="button" class="btn btn-primary wz-go" data-go="<?php echo $i + 1; ?>"><?php echo $i + 1 < count($steps) ? 'Next: ' . e($steps[$i + 1]['step_title']) : 'Review and finish'; ?></button>
                        </div>
                    </section>
                    <?php endforeach; ?>

                    <section class="wz-pane card-body" data-pane="<?php echo count($steps); ?>">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label" for="wzName">Reference</label>
                                <input type="text" id="wzName" name="proforma_name" class="form-control" maxlength="255" placeholder="<?php echo e($wizard['wizard_name']); ?>" value="<?php echo e($_POST['proforma_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="wzProject">Project (optional)</label>
                                <select id="wzProject" name="project_id" class="form-select">
                                    <option value="">No project</option>
                                    <?php foreach ($projects as $pj): ?><option value="<?php echo (int)$pj['project_id']; ?>"><?php echo e($pj['project_name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <p class="small text-muted mt-3 mb-0">The proforma is created as a draft in naira at ₦<?php echo number_format($ngn_sale_rate, 0); ?> per dollar. You can add services, notes and change prices afterwards.</p>
                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-secondary wz-go" data-go="<?php echo count($steps) - 1; ?>">Previous</button>
                            <button type="submit" name="create_proforma" value="1" class="btn btn-primary" id="wzCreate">Create proforma</button>
                        </div>
                    </section>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card wz-cart">
                    <div class="card-header d-flex justify-content-between align-items-center"><h2 class="card-title">Selected</h2><span class="small text-muted" id="wzCount">0 items</span></div>
                    <div class="wz-cart-list" id="wzCart"><div class="fr-empty">Nothing selected yet. Use + or type a quantity.</div></div>
                    <div class="fr-totals border-0 border-top rounded-0">
                        <div class="fr-totals-row fr-totals-grand mt-0 border-0 pt-0"><span>Total</span><b id="wzTotal">₦0</b></div>
                        <div class="small text-muted">Prices include VAT<?php echo $discount > 0 ? ' and the customer discount' : ''; ?>.</div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</main>

<script>
function wzHasItems() { return [...document.querySelectorAll('.wz-q')].some(i => parseFloat(i.value) > 0); }
(function () {
    const form = document.getElementById('wizardForm'); if (!form) return;
    const fmt = v => '₦' + Math.round(v).toLocaleString('en-US');
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const tabs = [...document.querySelectorAll('.wz-steps button')], panes = [...document.querySelectorAll('.wz-pane')];

    function go(n) {
        tabs.forEach((t, i) => { t.classList.toggle('active', i === n); t.classList.toggle('done', i < n); });
        panes.forEach((p, i) => p.classList.toggle('active', i === n));
        window.scrollTo({ top: 0 });
    }
    tabs.forEach((t, i) => t.addEventListener('click', () => go(i)));
    form.addEventListener('click', e => { const b = e.target.closest('.wz-go'); if (b) go(+b.dataset.go); });

    // Aynı ürün birden fazla adımda varsa tüm kutular senkron tutulur
    function setQty(pid, v, source) {
        v = Math.max(0, Math.round((parseFloat(v) || 0) * 100) / 100);
        form.querySelectorAll('.wz-q[data-pid="' + pid + '"]').forEach(inp => {
            if (inp !== source) inp.value = v;
            inp.closest('tr').classList.toggle('wz-selected', v > 0);
        });
        render();
    }
    form.addEventListener('click', e => {
        const b = e.target.closest('.wz-qty button'); if (!b) return;
        const inp = b.parentElement.querySelector('.wz-q');
        inp.value = Math.max(0, (parseFloat(inp.value) || 0) + (+b.dataset.d));
        setQty(inp.dataset.pid, inp.value, inp);
    });
    form.addEventListener('input', e => {
        if (e.target.classList.contains('wz-q')) setQty(e.target.dataset.pid, e.target.value, e.target);
        if (e.target.classList.contains('wz-search')) {
            const q = e.target.value.toLowerCase();
            e.target.closest('.wz-pane').querySelectorAll('.wz-row').forEach(r => r.hidden = q && !r.textContent.toLowerCase().includes(q));
        }
    });

    function render() {
        const seen = {}; let total = 0, n = 0, html = '';
        form.querySelectorAll('.wz-q').forEach(inp => {
            const q = parseFloat(inp.value) || 0, pid = inp.dataset.pid;
            if (q <= 0 || seen[pid]) return;
            seen[pid] = 1;
            const line = q * parseFloat(inp.dataset.price);
            total += line; n++;
            html += '<div class="wz-cart-row"><div><div class="fw-semibold">' + esc(inp.dataset.name) + '</div><div class="text-muted">' + q + ' × ' + fmt(+inp.dataset.price) + '</div></div><div class="fw-semibold text-nowrap">' + fmt(line) + '</div></div>';
        });
        document.getElementById('wzCart').innerHTML = html || '<div class="fr-empty">Nothing selected yet. Use + or type a quantity.</div>';
        document.getElementById('wzTotal').textContent = fmt(total);
        document.getElementById('wzCount').textContent = n + (n === 1 ? ' item' : ' items');
    }
    form.addEventListener('submit', e => {
        if (!wzHasItems()) { e.preventDefault(); alert('Add at least one product before creating the proforma.'); return; }
        // Aynı ürünün tekrarlayan kutularını gönderme (tek satır)
        const seen = {};
        form.querySelectorAll('.wz-q').forEach(inp => { if (seen[inp.dataset.pid]) inp.disabled = true; seen[inp.dataset.pid] = 1; });
    });
    form.querySelectorAll('.wz-q').forEach(inp => { if (parseFloat(inp.value) > 0) inp.closest('tr').classList.add('wz-selected'); });
    render();
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
