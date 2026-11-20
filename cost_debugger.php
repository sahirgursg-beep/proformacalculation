<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/classes/CostEngine.php';
$conn = (new Database())->getConnection();
$page_title = 'Cost debugger';

$settings = $conn->query("SELECT safe_rate, list_markup, vat_rate, waste_rate FROM system_settings WHERE setting_id = 1")->fetch(PDO::FETCH_ASSOC)
    ?: ['safe_rate' => 0, 'list_markup' => 0, 'vat_rate' => 0, 'waste_rate' => 0];
$rate = $conn->query("SELECT buy_rate, sale_rate FROM rate_exchange_history WHERE currency_code = 'NGN' ORDER BY created_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)
    ?: ['buy_rate' => 0, 'sale_rate' => 0];
$customerTypes = $conn->query("SELECT type_name, discount_rate FROM customer_types ORDER BY discount_rate ASC")->fetchAll(PDO::FETCH_ASSOC);

$selected_id = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
$product = null; $prices = null; $b = null; $log = [];
if ($selected_id > 0) {
    $st = $conn->prepare("SELECT product_id, stock_code, stock_name, unit_cost_usd, production_margin, sales_margin, service_margin_type, service_margin_value FROM products WHERE product_id = ?");
    $st->execute([$selected_id]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if ($product) {
        $engine = new CostEngine($conn);
        $engine->enableLog(true);
        $prices = $engine->calculatePrices($selected_id);
        $b = $prices['breakdown'] ?? null;
        $log = $engine->calculationLog;
    }
}

$pct = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') . '%';
$usd = fn($v) => '$' . number_format((float)$v, 2);
$ngn = fn($v) => '₦' . number_format((float)$v, 0);
$has = (bool)$b;
$val = fn($key) => $has ? $usd($b[$key]) : '';

// Hizmet marjı satırı açıklaması
$srvText = 'Service margin from the product card (percent, USD or NGN)';
if ($has) {
    if ($b['srv_value'] > 0 && $b['srv_type'] === 'PERCENT') $srvText = $usd($b['step1']) . ' × (1 + ' . $pct($b['srv_value']) . ')';
    elseif ($b['srv_value'] > 0 && $b['srv_type'] === 'USD') $srvText = $usd($b['step1']) . ' + $' . number_format($b['srv_value'], 2);
    elseif ($b['srv_value'] > 0 && $b['srv_type'] === 'NGN') $srvText = $usd($b['step1']) . ' + ₦' . number_format($b['srv_value'], 0) . ' ÷ ' . number_format($b['ngn_buy_rate'], 0) . ' (buy rate)';
    else $srvText = 'No service margin on this product';
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<style>
    .fr-ladder { list-style: none; margin: 0; padding: 0; counter-reset: step; }
    .fr-ladder li { display: grid; grid-template-columns: 34px minmax(0, 1fr) 150px; gap: 16px; align-items: start; padding: 12px 0; position: relative; }
    .fr-ladder li + li { border-top: 1px dashed var(--fr-line); }
    .fr-ladder .n { counter-increment: step; width: 28px; height: 28px; border-radius: 50%; border: 1px solid var(--fr-line-2); display: grid; place-items: center; font-size: .78rem; color: var(--fr-oak); background: #fff; }
    .fr-ladder .n::before { content: counter(step); }
    .fr-ladder .t { font-weight: 600; color: var(--fr-ink); }
    .fr-ladder .f { font-size: .82rem; color: var(--fr-oak); margin-top: 2px; }
    .fr-ladder .f code { font-family: var(--fr-sans); color: var(--fr-walnut); background: #F3EDE2; padding: 1px 5px; border-radius: 3px; font-size: .8rem; }
    .fr-ladder .v { text-align: right; font-weight: 650; font-size: .95rem; color: var(--fr-ink); padding-top: 3px; }
    .fr-ladder li.key { background: linear-gradient(90deg, rgba(166,132,74,.09), transparent 70%); border-left: 3px solid var(--fr-brass); padding-left: 10px; margin-left: -13px; }
    .fr-ladder li.key .n { background: var(--fr-walnut); border-color: var(--fr-walnut); color: #F4ECDF; }
    .fr-ladder li.key .v { font-family: var(--fr-serif); font-size: 1.35rem; font-weight: 400; color: var(--fr-walnut); padding-top: 0; }
    .fr-ladder li.branch { margin-left: 50px; grid-template-columns: 34px minmax(0, 1fr) 150px; }
    .fr-ladder li.branch .n { border-style: dashed; }
    .fr-ladder li.branch .n::before { content: "↳"; }
    .fr-ladder li.branch .n { counter-increment: none; }
    .fr-note { font-size: .8rem; color: var(--fr-oak); }
    .fr-log { max-height: 520px; overflow-y: auto; background: var(--fr-paper); border: 1px solid var(--fr-line); border-radius: 6px; padding: 16px 20px; font-size: .84rem; line-height: 1.6; }
    .fr-log p { margin: 0 0 4px; }
    .fr-log .text-success, .fr-log .text-primary { color: var(--fr-olive) !important; }
    details > summary { cursor: pointer; font-weight: 600; color: var(--fr-walnut); }
</style>

<main class="fr-main">
    <div class="fr-page-head">
        <div>
            <h1>Cost debugger</h1>
            <p>Follow every step from material cost to the price on a proforma. Pick a product to fill the steps with its real numbers, then check the arithmetic by hand.</p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="POST" class="d-flex gap-2 align-items-start">
                <div class="flex-grow-1">
                    <select name="product_id" class="form-select js-product-picker" data-placeholder="Type a code or product name" required>
                        <?php if ($product): ?><option value="<?php echo (int)$product['product_id']; ?>" selected><?php echo e($product['stock_code'] . ' · ' . $product['stock_name']); ?></option><?php endif; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary px-4">Trace price</button>
            </form>
            <?php if ($selected_id && !$product): ?><div class="alert alert-warning mt-3 mb-0">Product #<?php echo $selected_id; ?> was not found.</div><?php endif; ?>
            <?php if ($prices && !empty($prices['circular'])): ?><div class="alert alert-danger mt-3 mb-0">This product’s recipe contains itself (circular recipe). Fix the recipe before trusting this price.</div><?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="card-title">How the price is built</h2>
                    <span class="small text-muted"><?php echo $has ? e($product['stock_code']) : 'Template with current settings'; ?></span>
                </div>
                <div class="card-body pt-2">
                    <ol class="fr-ladder">
                        <li>
                            <span class="n"></span>
                            <div>
                                <div class="t"><?php echo $has && $b['is_bom'] ? 'Material cost (recipe)' : 'Unit cost'; ?></div>
                                <div class="f"><?php if ($has && $b['is_bom']): ?>Sum of each component: <code>qty × component base cost</code> (÷ unit conversion factor if the recipe uses another unit). Details in the log below.
                                    <?php elseif ($has): ?>Unit cost (USD) from the product card.
                                    <?php else: ?>Raw material: unit cost from the product card. Manufactured: sum of <code>qty × component base cost</code>.<?php endif; ?></div>
                            </div>
                            <span class="v"><?php echo $val('material_base'); ?></span>
                        </li>
                        <li>
                            <span class="n"></span>
                            <div>
                                <div class="t">Production cost</div>
                                <div class="f">Each component gets its own production margin: <code>qty × base × (1 + component production margin)</code>. Raw materials: same as step 1.</div>
                            </div>
                            <span class="v"><?php echo $val('material_prod'); ?></span>
                        </li>
                        <li>
                            <span class="n"></span>
                            <div>
                                <div class="t">Freight (landed cost)</div>
                                <div class="f">Only when shipment is switched on for the product. Air: <code>chargeable kg × cost per kg × (1 + margin)</code>. Sea: <code>CBM × container cost ÷ net CBM × (1 + margin)</code>. Added to steps 1 and 2.</div>
                            </div>
                            <span class="v"><?php echo $has ? ($b['freight'] > 0 ? '+ ' . $usd($b['freight']) : '$0.00') : ''; ?></span>
                        </li>
                        <li>
                            <span class="n"></span>
                            <div>
                                <div class="t">Plus service margin</div>
                                <div class="f"><?php echo e($srvText); ?></div>
                            </div>
                            <span class="v"><?php echo $val('step2'); ?></span>
                        </li>
                        <li class="key">
                            <span class="n"></span>
                            <div>
                                <div class="t">Sales price</div>
                                <div class="f"><code>step 4 × (1 + sales margin)</code><?php if ($has): ?> = <?php echo $usd($b['step2']); ?> × (1 + <?php echo $pct($b['sales_margin']); ?>)<?php endif; ?></div>
                            </div>
                            <span class="v"><?php echo $val('step3'); ?></span>
                        </li>
                        <li class="branch">
                            <span class="n"></span>
                            <div>
                                <div class="t">Minimum price on a proforma</div>
                                <div class="f"><code>sales price × (1 + VAT <?php echo $pct($settings['vat_rate']); ?>)</code>. Sales reps cannot go below this, whatever the discount.</div>
                            </div>
                            <span class="v"><?php echo $has ? $usd($prices['min_price']) : ''; ?></span>
                        </li>
                        <li>
                            <span class="n"></span>
                            <div>
                                <div class="t">Plus waste and safety allowance</div>
                                <div class="f"><code>sales price × (1 + waste <?php echo $pct($settings['waste_rate']); ?> + safety <?php echo $pct($settings['safe_rate']); ?>)</code></div>
                            </div>
                            <span class="v"><?php echo $val('step4'); ?></span>
                        </li>
                        <li>
                            <span class="n"></span>
                            <div>
                                <div class="t">Plus VAT</div>
                                <div class="f"><code>step 6 × (1 + VAT <?php echo $pct($settings['vat_rate']); ?>)</code></div>
                            </div>
                            <span class="v"><?php echo $val('step5'); ?></span>
                        </li>
                        <li class="key">
                            <span class="n"></span>
                            <div>
                                <div class="t">List price (USD)</div>
                                <div class="f"><code>step 7 × (1 + list markup <?php echo $pct($settings['list_markup']); ?>)</code>. Shown in the price list.</div>
                            </div>
                            <span class="v"><?php echo $val('list_price'); ?></span>
                        </li>
                        <li>
                            <span class="n"></span>
                            <div>
                                <div class="t">Proforma unit price</div>
                                <div class="f"><code>list price × (1 − customer type discount)</code>. Naira: <code>× selling rate <?php echo $rate['sale_rate'] ? number_format((float)$rate['sale_rate'], 0) : '—'; ?></code>, rounded to the nearest ₦1,000. If the result is below the minimum price, the minimum is used. See the table on the right.</div>
                            </div>
                            <span class="v"></span>
                        </li>
                    </ol>
                    <p class="fr-note mt-3 mb-0">USD costs are converted from naira with the <strong>buying</strong> rate (₦<?php echo number_format((float)$rate['buy_rate'], 0); ?>). Customer prices in naira use the <strong>selling</strong> rate (₦<?php echo number_format((float)$rate['sale_rate'], 0); ?>).</p>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card mb-4">
                <div class="card-header"><h2 class="card-title">Price by customer type</h2></div>
                <?php if ($has): ?>
                <table class="table align-middle">
                    <thead><tr><th>Customer type</th><th class="text-end">USD</th><th class="text-end">NGN</th></tr></thead>
                    <tbody>
                    <?php
                    $types = $customerTypes ?: [['type_name' => 'No discount', 'discount_rate' => 0]];
                    foreach ($types as $t):
                        $qU = $engine->quote($selected_id, $t['discount_rate'], 'USD', 1);
                        $qN = $engine->quote($selected_id, $t['discount_rate'], 'NGN', (float)$rate['sale_rate']);
                        $floored = $qN['price'] <= $qN['min_price'];
                    ?>
                        <tr>
                            <td><?php echo e($t['type_name']); ?> <span class="text-muted small">−<?php echo $pct($t['discount_rate']); ?></span>
                                <?php if ($floored): ?><div class="small text-danger">Held at minimum price</div><?php endif; ?></td>
                            <td class="text-end"><?php echo $usd($qU['price']); ?></td>
                            <td class="text-end fw-semibold"><?php echo $ngn($qN['price']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr class="table-warning"><td>Minimum price</td><td class="text-end"><?php echo $usd($prices['min_price']); ?></td><td class="text-end fw-semibold"><?php echo $ngn(round($prices['min_price'] * (float)$rate['sale_rate'] / 1000) * 1000); ?></td></tr>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="fr-empty">Pick a product to see its price for each customer type.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Settings used</h2></div>
                <table class="table mb-0">
                    <tbody>
                        <?php if ($product): ?>
                        <tr><td>Production margin (this product as a component)</td><td class="text-end"><?php echo $pct($product['production_margin']); ?></td></tr>
                        <tr><td>Sales margin</td><td class="text-end"><?php echo $pct($product['sales_margin']); ?></td></tr>
                        <tr><td>Service margin</td><td class="text-end"><?php echo $product['service_margin_type'] === 'PERCENT' ? $pct($product['service_margin_value']) : e($product['service_margin_type'] . ' ' . number_format((float)$product['service_margin_value'], 2)); ?></td></tr>
                        <?php endif; ?>
                        <tr><td>Waste / safety</td><td class="text-end"><?php echo $pct($settings['waste_rate']); ?> / <?php echo $pct($settings['safe_rate']); ?></td></tr>
                        <tr><td>VAT</td><td class="text-end"><?php echo $pct($settings['vat_rate']); ?></td></tr>
                        <tr><td>List markup</td><td class="text-end"><?php echo $pct($settings['list_markup']); ?></td></tr>
                        <tr><td>NGN buy / sell</td><td class="text-end"><?php echo $ngn($rate['buy_rate']); ?> / <?php echo $ngn($rate['sale_rate']); ?></td></tr>
                    </tbody>
                </table>
                <div class="card-footer small">Change these in <a href="settings.php">Pricing settings</a> and <a href="exchange_rates.php">Exchange rates</a>.</div>
            </div>
        </div>
    </div>

    <?php if ($log): ?>
    <div class="card mt-4">
        <div class="card-body">
            <details <?php echo $has && !$b['is_bom'] ? '' : 'open'; ?>>
                <summary>Step-by-step log<?php echo $has && $b['is_bom'] ? ' (every component in the recipe)' : ''; ?></summary>
                <div class="fr-log mt-3">
                    <?php foreach ($log as $line): ?><p><?php echo $line; ?></p><?php endforeach; ?>
                </div>
            </details>
        </div>
    </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
