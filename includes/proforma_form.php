<?php
/**
 * Proforma formu (add_proforma.php ve edit_proforma.php ortak kullanır).
 * Beklenen değişkenler:
 *   $conn, $form (proforma alanları), $form_items (product_id, stock_code, stock_name, quantity, unit_price, min_price),
 *   $form_services (service_id, amount), $is_edit (bool), $error_message (opsiyonel)
 */
require_once __DIR__ . '/bootstrap.php';

$customers = $conn->query("SELECT c.customer_id, c.customer_name, COALESCE(ct.discount_rate, 0) AS discount_rate
    FROM customers c LEFT JOIN customer_types ct ON c.customer_type_id = ct.type_id ORDER BY c.customer_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$projects = $conn->query("SELECT project_id, customer_id, project_name FROM projects WHERE project_status != 'CANCELLED' ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$services = $conn->query("SELECT service_id, service_name, price_type, default_price FROM additional_services ORDER BY service_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$sym = $form['currency'] === 'NGN' ? '₦' : '$';
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<main class="fr-main">
    <div class="fr-page-head">
        <div>
            <h1><?php echo $is_edit ? 'Edit proforma' : (!empty($copy_from) ? 'Copy of ' . e($copy_from['proforma_no']) : 'New proforma'); ?></h1>
            <p><?php echo !empty($copy_from) && !$is_edit
                ? 'Customer, project, products and services are copied from ' . e($copy_from['proforma_no']) . '. Change anything you need; the original is not touched. Prices are the original ones until you press “Refresh to current prices”.'
                : ($is_edit
                ? e($form['proforma_no']) . ' · prices are checked again against today’s minimum when you save.'
                : 'Choose a customer, then add products. Prices fill in from the price list and the customer’s discount.'); ?></p>
        </div>
        <div class="fr-actions">
            <?php if ($is_edit): ?>
                <a href="add_proforma.php?copy=<?php echo (int)$form['proforma_id']; ?>" class="btn btn-secondary"><i class="fa-solid fa-copy me-1"></i> Copy</a>
                <a href="view_proforma.php?id=<?php echo (int)$form['proforma_id']; ?>" class="btn btn-secondary">View proforma</a>
            <?php endif; ?>
            <a href="proformas.php" class="btn btn-secondary">Cancel</a>
        </div>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger" role="alert"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <form method="POST" id="proformaForm" novalidate>
        <?php if (!empty($copy_from) && !$is_edit): ?><input type="hidden" name="copy_from" value="<?php echo (int)$copy_from['proforma_id']; ?>"><?php endif; ?>
        <div class="card mb-4">
            <div class="card-header"><h2 class="card-title">Details</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label" for="customerSelect">Customer</label>
                        <select name="customer_id" id="customerSelect" class="form-select" required>
                            <option value="" data-discount="0">Select a customer</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo (int)$c['customer_id']; ?>" data-discount="<?php echo (float)$c['discount_rate']; ?>" <?php echo (int)$form['customer_id'] === (int)$c['customer_id'] ? 'selected' : ''; ?>><?php echo e($c['customer_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label" for="projectSelect">Project</label>
                        <select name="project_id" id="projectSelect" class="form-select"><option value="">No project</option></select>
                    </div>
                    <div class="col-lg-5">
                        <label class="form-label" for="pfName">Reference</label>
                        <input type="text" id="pfName" name="proforma_name" class="form-control" maxlength="255" value="<?php echo e($form['proforma_name']); ?>" placeholder="e.g. Kitchen, option 1">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="pfDate">Date</label>
                        <input type="date" id="pfDate" name="proforma_date" class="form-control" value="<?php echo e($form['proforma_date']); ?>" required>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="currencySelect">Currency</label>
                        <select name="currency" id="currencySelect" class="form-select">
                            <option value="NGN" <?php echo $form['currency'] === 'NGN' ? 'selected' : ''; ?>>Naira (₦)</option>
                            <option value="USD" <?php echo $form['currency'] === 'USD' ? 'selected' : ''; ?>>US dollar ($)</option>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="globalDiscount">Discount</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100" id="globalDiscount" name="discount_rate" class="form-control" value="<?php echo e(rtrim(rtrim(number_format((float)$form['discount_rate'], 2, '.', ''), '0'), '.')); ?>">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <?php if ($is_edit): ?>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="pfStatus">Status</label>
                        <select name="status" id="pfStatus" class="form-select">
                            <?php foreach (['DRAFT' => 'Draft', 'REVIEW' => 'In review', 'CONFIRMED' => 'Confirmed', 'CANCELLED' => 'Cancelled'] as $k => $v): ?>
                                <option value="<?php echo $k; ?>" <?php echo $form['status'] === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title">Products</h2>
                <div class="d-flex gap-2">
                    <?php if ($is_edit || !empty($copy_from)): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="refreshPricesBtn" title="Recalculate every line with today’s prices, exchange rate and the discount above"><i class="fa-solid fa-rotate-right me-1"></i> Refresh to current prices</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="addProductBtn"><i class="fa-solid fa-plus me-1"></i> Add product</button>
                </div>
            </div>
            <div>
                <table class="table align-top mb-0" id="productsTable">
                    <thead><tr>
                        <th style="width:34px" aria-label="Order"></th>
                        <th style="width:44%">Product</th>
                        <th style="width:11%">Qty</th>
                        <th style="width:19%">Unit price</th>
                        <th style="width:18%" class="text-end">Amount</th>
                        <th style="width:6%"></th>
                    </tr></thead>
                    <tbody id="productsBody"></tbody>
                </table>
            </div>
            <div class="px-3 py-2 small text-muted border-top" id="noCustomerHint">Select a customer first so prices can be filled in.</div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h2 class="card-title">Additional services</h2>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="addServiceBtn" <?php echo $services ? '' : 'disabled'; ?>><i class="fa-solid fa-plus me-1"></i> Add service</button>
                    </div>
                    <table class="table align-middle mb-0"><tbody id="servicesBody"></tbody></table>
                    <div class="fr-empty py-3 small" id="noServices">No services added. Delivery and installation can be added here.</div>
                </div>
                <div class="card">
                    <div class="card-body">
                        <label class="form-label" for="pfNotes">Terms and notes</label>
                        <textarea id="pfNotes" name="notes" class="form-control" rows="4" placeholder="Payment terms, delivery time, validity…"><?php echo e($form['notes']); ?></textarea>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="fr-totals">
                    <div class="fr-totals-row"><span>Products</span><b><span class="cur-symbol"><?php echo $sym; ?></span><span id="displaySubtotal">0</span></b></div>
                    <div class="fr-totals-row"><span>Services</span><b><span class="cur-symbol"><?php echo $sym; ?></span><span id="displayServices">0</span></b></div>
                    <div class="fr-totals-row fr-totals-grand"><span>Total</span><b><span class="cur-symbol"><?php echo $sym; ?></span><span id="displayGrandTotal">0</span></b></div>
                    <div class="small text-muted mt-1">Prices include VAT.</div>
                </div>
                <button type="submit" name="<?php echo $is_edit ? 'update_proforma' : 'save_proforma'; ?>" value="1" class="btn btn-primary btn-lg w-100 mt-3" id="saveBtn">
                    <?php echo $is_edit ? 'Save changes' : 'Create proforma'; ?>
                </button>
            </div>
        </div>
    </form>
</main>

<script>
(function () {
    const servicesData = <?php echo json_encode($services, $jsonFlags); ?>;
    const allProjects  = <?php echo json_encode($projects, $jsonFlags); ?>;
    const initialItems = <?php echo json_encode(array_values($form_items), $jsonFlags); ?>;
    const initialServices = <?php echo json_encode(array_values($form_services), $jsonFlags); ?>;
    const initialProject = <?php echo json_encode($form['project_id'] ? (string)$form['project_id'] : ''); ?>;

    const $customer = $('#customerSelect'), $project = $('#projectSelect');
    const currencySel = document.getElementById('currencySelect');
    const discountInput = document.getElementById('globalDiscount');
    const productsBody = document.getElementById('productsBody');
    const servicesBody = document.getElementById('servicesBody');

    const sym = () => currencySel.value === 'USD' ? '$' : '₦';
    const decimals = () => currencySel.value === 'USD' ? 2 : 0;
    const fmt = v => (Number(v) || 0).toLocaleString('en-US', { minimumFractionDigits: decimals(), maximumFractionDigits: decimals() });
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    if ($.fn.select2) {
        $customer.select2({ theme: 'bootstrap-5', width: '100%' });
        $project.select2({ theme: 'bootstrap-5', width: '100%' });
    }

    function fillProjects(keep) {
        const cid = $customer.val();
        $project.empty().append(new Option('No project', ''));
        allProjects.filter(p => String(p.customer_id) === String(cid)).forEach(p => $project.append(new Option(p.project_name, p.project_id)));
        $project.val(keep && $project.find('option[value="' + keep + '"]').length ? keep : '').trigger('change.select2');
    }

    /* ---------- Ürün satırları ---------- */
    function addProductRow(item) {
        const tr = document.createElement('tr');
        tr.className = 'product-row';
        tr.innerHTML = `
            <td class="fr-drag-cell"><span class="fr-drag" role="button" tabindex="0" title="Drag to reorder (or use arrow keys)" aria-label="Move row. Use arrow up and down keys."><i class="fa-solid fa-grip-vertical"></i></span></td>
            <td><div class="fr-combo">
                <input type="hidden" name="products[]" class="product-id">
                <input type="text" class="form-control product-search" placeholder="Type a code or product name" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list">
                <ul class="fr-combo-list" role="listbox" hidden></ul>
            </div></td>
            <td><input type="number" step="0.01" min="0.01" name="quantities[]" class="form-control qty-input" value="${esc(item ? Number(item.quantity) : 1)}" required></td>
            <td>
                <div class="input-group"><span class="input-group-text cur-symbol">${sym()}</span>
                <input type="number" step="0.01" min="0" name="unit_prices[]" class="form-control price-input" value="${item ? esc(item.unit_price) : ''}" required></div>
                <div class="fr-min-hint"></div>
            </td>
            <td class="text-end pt-3 fw-semibold"><span class="cur-symbol">${sym()}</span><span class="line-total">0</span></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-product" aria-label="Remove product"><i class="fa-solid fa-xmark"></i></button></td>`;
        productsBody.appendChild(tr);

        const idInput = tr.querySelector('.product-id'), search = tr.querySelector('.product-search');
        if (item && item.product_id) {
            idInput.value = item.product_id;
            search.value = item.stock_code + ' · ' + item.stock_name;
            setMin(tr, item.min_price);
        }
        initCombo(tr);
        calcRow(tr);
        return tr;
    }

    /* Ürün arama kutusu: yazdıkça get_products.php'den arar */
    function initCombo(tr) {
        const box = tr.querySelector('.fr-combo'), input = box.querySelector('.product-search'),
              idInput = box.querySelector('.product-id'), list = box.querySelector('.fr-combo-list');
        const listId = 'pl' + Math.random().toString(36).slice(2, 9);
        list.id = listId; input.setAttribute('aria-controls', listId);
        let timer = null, results = [], active = -1, ctrl = null, chosenText = input.value;

        const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; };
        const render = () => {
            list.innerHTML = '';
            if (!results.length) { list.innerHTML = '<li class="fr-combo-empty">No products match “' + esc(input.value) + '”</li>'; }
            results.forEach((r, i) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option'); li.id = listId + '-' + i;
                li.className = i === active ? 'is-active' : '';
                li.innerHTML = '<span class="fr-opt-code">' + esc(r.code) + '</span>' + esc(r.name);
                li.addEventListener('mousedown', ev => { ev.preventDefault(); choose(i); });
                list.appendChild(li);
            });
            input.setAttribute('aria-activedescendant', active >= 0 ? listId + '-' + active : '');
            list.hidden = false; input.setAttribute('aria-expanded', 'true');
            const a = list.querySelector('.is-active'); if (a) a.scrollIntoView({ block: 'nearest' });
        };
        const choose = i => {
            const r = results[i]; if (!r) return;
            idInput.value = r.id; input.value = chosenText = r.code + ' · ' + r.name;
            input.classList.remove('is-invalid'); close(); fetchPrice(tr);
            tr.querySelector('.qty-input').focus(); tr.querySelector('.qty-input').select();
        };
        const search = () => {
            const q = input.value.trim();
            if (q.length < 2) { close(); return; }
            if (ctrl) ctrl.abort();
            ctrl = new AbortController();
            list.innerHTML = '<li class="fr-combo-empty">Searching…</li>'; list.hidden = false;
            fetch('get_products.php?q=' + encodeURIComponent(q), { signal: ctrl.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json()).then(d => { results = d.results || []; active = results.length ? 0 : -1; render(); })
                .catch(() => {});
        };
        input.addEventListener('input', () => {
            if (input.value !== chosenText) { idInput.value = ''; setMin(tr, 0); }
            clearTimeout(timer); timer = setTimeout(search, 220);
        });
        input.addEventListener('focus', () => { if (!idInput.value) input.select(); });
        input.addEventListener('keydown', ev => {
            if (list.hidden && ev.key === 'ArrowDown') { search(); return; }
            if (list.hidden) return;
            if (ev.key === 'ArrowDown') { ev.preventDefault(); active = Math.min(active + 1, results.length - 1); render(); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); active = Math.max(active - 1, 0); render(); }
            else if (ev.key === 'Enter') { ev.preventDefault(); if (active >= 0) choose(active); }
            else if (ev.key === 'Escape') { close(); }
        });
        input.addEventListener('blur', () => setTimeout(() => {
            close();
            if (!idInput.value && input.value.trim()) input.classList.add('is-invalid');
            else if (idInput.value && input.value !== chosenText) input.value = chosenText; // seçili ürünü geri yaz
        }, 120));
    }

    function setMin(tr, min) {
        const input = tr.querySelector('.price-input'), hint = tr.querySelector('.fr-min-hint');
        input.dataset.min = min || 0;
        hint.textContent = min > 0 ? 'Minimum ' + sym() + fmt(min) : '';
        checkMin(tr);
    }
    function checkMin(tr) {
        const input = tr.querySelector('.price-input'), hint = tr.querySelector('.fr-min-hint');
        const min = parseFloat(input.dataset.min) || 0, val = parseFloat(input.value) || 0;
        const low = min > 0 && val + 0.005 < min;
        input.classList.toggle('is-invalid', low);
        hint.classList.toggle('is-low', low);
        if (min > 0) hint.textContent = (low ? 'Below minimum ' : 'Minimum ') + sym() + fmt(min);
        return !low;
    }

    function fetchPrice(tr) {
        const pid = tr.querySelector('.product-id').value;
        if (!pid) return;
        if (!$customer.val()) { document.getElementById('noCustomerHint').classList.add('text-danger'); return; }
        const params = new URLSearchParams({ product_id: pid, customer_id: $customer.val(), currency: currencySel.value, discount: discountInput.value || 0 });
        tr.querySelector('.price-input').placeholder = 'Loading…';
        return fetch('api_get_price.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(d => {
                if (!d.success) return;
                tr.querySelector('.price-input').value = d.price;
                setMin(tr, d.min_price);
                calcRow(tr); calcTotals();
            })
            .finally(() => { tr.querySelector('.price-input').placeholder = ''; });
    }
    function reloadAllPrices() {
        return Promise.all([...productsBody.querySelectorAll('.product-row')].filter(tr => tr.querySelector('.product-id').value).map(tr => fetchPrice(tr)));
    }

    /* ---------- Fiyatları güncelle (düzenleme ekranı) ---------- */
    const refreshBtn = document.getElementById('refreshPricesBtn');
    if (refreshBtn) refreshBtn.addEventListener('click', () => {
        if (!$customer.val()) { alert('Select a customer first.'); return; }
        if (!confirm('Update every product price in this proforma to today’s price list, exchange rate and the ' + (discountInput.value || 0) + '% discount? Prices you typed by hand will be replaced.')) return;
        const rows = [...productsBody.querySelectorAll('.product-row')].filter(tr => tr.querySelector('.product-id').value);
        const before = rows.map(tr => parseFloat(tr.querySelector('.price-input').value) || 0);
        const label = refreshBtn.innerHTML;
        refreshBtn.disabled = true; refreshBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Updating…';
        reloadAllPrices().then(() => {
            let changed = 0;
            rows.forEach((tr, i) => {
                const now = parseFloat(tr.querySelector('.price-input').value) || 0;
                const diff = now - before[i];
                const hint = tr.querySelector('.fr-min-hint');
                tr.querySelector('.fr-price-change')?.remove();
                if (Math.abs(diff) > 0.004) {
                    changed++;
                    const tag = document.createElement('div');
                    tag.className = 'fr-price-change ' + (diff > 0 ? 'up' : 'down');
                    tag.textContent = (diff > 0 ? '▲ ' : '▼ ') + sym() + fmt(Math.abs(diff)) + ' (was ' + sym() + fmt(before[i]) + ')';
                    hint.after(tag);
                    tr.classList.add('is-moved'); setTimeout(() => tr.classList.remove('is-moved'), 900);
                }
            });
            calcTotals();
            let box = document.getElementById('refreshNote');
            if (!box) { box = document.createElement('div'); box.id = 'refreshNote'; box.className = 'alert alert-info mx-3 my-3'; box.setAttribute('role', 'status'); refreshBtn.closest('.card').appendChild(box); }
            box.textContent = changed
                ? changed + ' of ' + rows.length + ' prices changed. Review them, then save to keep the new prices.'
                : 'All prices are already up to date.';
        }).finally(() => { refreshBtn.disabled = false; refreshBtn.innerHTML = label; });
    });

    function calcRow(tr) {
        const q = parseFloat(tr.querySelector('.qty-input').value) || 0, p = parseFloat(tr.querySelector('.price-input').value) || 0;
        tr.querySelector('.line-total').textContent = fmt(q * p);
        tr.dataset.total = q * p;
    }
    const subtotal = () => [...productsBody.querySelectorAll('.product-row')].reduce((s, tr) => s + (parseFloat(tr.dataset.total) || 0), 0);

    /* ---------- Satırları sürükleyerek sıralama ---------- */
    // Sıra, formdaki satır sırasıyla kaydedilir; PDF de bu sırayı kullanır.
    let dragRow = null;
    productsBody.addEventListener('pointerdown', e => {
        const h = e.target.closest('.fr-drag'); if (!h) return;
        const tr = h.closest('tr'); tr.draggable = true;
    });
    productsBody.addEventListener('dragstart', e => {
        dragRow = e.target.closest('tr.product-row'); if (!dragRow) return;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
        requestAnimationFrame(() => dragRow && dragRow.classList.add('is-dragging'));
    });
    productsBody.addEventListener('dragover', e => {
        if (!dragRow) return;
        e.preventDefault();
        const over = e.target.closest('tr.product-row');
        if (!over || over === dragRow) return;
        const r = over.getBoundingClientRect();
        const after = e.clientY > r.top + r.height / 2;
        productsBody.insertBefore(dragRow, after ? over.nextSibling : over);
    });
    const endDrag = () => {
        if (!dragRow) return;
        dragRow.classList.remove('is-dragging'); dragRow.draggable = false;
        const moved = dragRow; dragRow = null;
        moved.classList.add('is-moved'); setTimeout(() => moved.classList.remove('is-moved'), 600);
    };
    productsBody.addEventListener('dragend', endDrag);
    productsBody.addEventListener('drop', e => { e.preventDefault(); endDrag(); });
    document.addEventListener('pointerup', () => productsBody.querySelectorAll('tr[draggable="true"]').forEach(t => { if (t !== dragRow) t.draggable = false; }));
    // Klavye: tutamaç seçiliyken yukarı/aşağı ok
    productsBody.addEventListener('keydown', e => {
        const h = e.target.closest('.fr-drag'); if (!h) return;
        const tr = h.closest('tr');
        if (e.key === 'ArrowUp' && tr.previousElementSibling) { e.preventDefault(); productsBody.insertBefore(tr, tr.previousElementSibling); h.focus(); }
        if (e.key === 'ArrowDown' && tr.nextElementSibling) { e.preventDefault(); productsBody.insertBefore(tr.nextElementSibling, tr); h.focus(); }
    });

    /* ---------- Hizmetler ---------- */
    function addServiceRow(srv) {
        const tr = document.createElement('tr');
        tr.className = 'service-row';
        let opts = '<option value="">Choose a service</option>';
        servicesData.forEach(s => {
            const label = s.price_type === 'PERCENT' ? ` (${Number(s.default_price)}% of products)` : '';
            opts += `<option value="${esc(s.service_id)}" data-type="${esc(s.price_type)}" data-price="${esc(s.default_price)}" ${srv && String(srv.service_id) === String(s.service_id) ? 'selected' : ''}>${esc(s.service_name + label)}</option>`;
        });
        tr.innerHTML = `
            <td style="width:58%" class="ps-3"><select name="services[]" class="form-select service-select" required>${opts}</select></td>
            <td><div class="input-group"><span class="input-group-text cur-symbol">${sym()}</span><input type="number" step="0.01" min="0" name="service_amounts[]" class="form-control service-amount" value="${srv ? esc(srv.amount) : ''}" required></div></td>
            <td class="text-end pe-3" style="width:8%"><button type="button" class="btn btn-sm btn-outline-danger remove-service" aria-label="Remove service"><i class="fa-solid fa-xmark"></i></button></td>`;
        servicesBody.appendChild(tr);
        document.getElementById('noServices').hidden = true;
    }
    function serviceAmount(tr) {
        const o = tr.querySelector('.service-select').selectedOptions[0];
        if (!o || !o.value) return;
        const type = o.dataset.type, price = parseFloat(o.dataset.price) || 0;
        let amt = null;
        if (type === 'PERCENT') amt = subtotal() * price / 100;
        else if (type === 'FIXED_USD' && currencySel.value === 'USD') amt = price;
        else if (type === 'FIXED_NGN' && currencySel.value === 'NGN') amt = price;
        if (amt !== null) tr.querySelector('.service-amount').value = amt.toFixed(decimals());
    }

    function calcTotals() {
        const sub = subtotal();
        let srv = 0;
        servicesBody.querySelectorAll('.service-row').forEach(tr => {
            const o = tr.querySelector('.service-select').selectedOptions[0];
            if (o && o.dataset.type === 'PERCENT') serviceAmount(tr);
            srv += parseFloat(tr.querySelector('.service-amount').value) || 0;
        });
        document.getElementById('displaySubtotal').textContent = fmt(sub);
        document.getElementById('displayServices').textContent = fmt(srv);
        document.getElementById('displayGrandTotal').textContent = fmt(sub + srv);
        document.getElementById('noCustomerHint').hidden = !!$customer.val();
    }

    /* ---------- Olaylar ---------- */
    $customer.on('change', function () {
        const o = this.selectedOptions[0];
        discountInput.value = parseFloat(o ? o.dataset.discount : 0) || 0;
        fillProjects('');
        reloadAllPrices(); calcTotals();
    });
    discountInput.addEventListener('change', reloadAllPrices);
    currencySel.addEventListener('change', function () {
        document.querySelectorAll('.cur-symbol').forEach(el => el.textContent = sym());
        servicesBody.querySelectorAll('.service-row').forEach(serviceAmount);
        reloadAllPrices(); calcTotals();
    });
    document.getElementById('addProductBtn').addEventListener('click', () => {
        const tr = addProductRow();
        tr.querySelector('.product-search').focus();
    });
    document.getElementById('addServiceBtn').addEventListener('click', () => addServiceRow());

    productsBody.addEventListener('click', e => {
        const b = e.target.closest('.remove-product'); if (!b) return;
        if (productsBody.children.length > 1) b.closest('tr').remove();
        else { const tr = b.closest('tr'); tr.querySelector('.product-id').value = ''; tr.querySelector('.product-search').value = ''; tr.querySelector('.price-input').value = ''; setMin(tr, 0); calcRow(tr); }
        calcTotals();
    });
    productsBody.addEventListener('input', e => {
        const tr = e.target.closest('.product-row'); if (!tr) return;
        if (e.target.classList.contains('price-input')) checkMin(tr);
        calcRow(tr); calcTotals();
    });
    servicesBody.addEventListener('change', e => { if (e.target.classList.contains('service-select')) { serviceAmount(e.target.closest('tr')); calcTotals(); } });
    servicesBody.addEventListener('input', calcTotals);
    servicesBody.addEventListener('click', e => {
        const b = e.target.closest('.remove-service'); if (!b) return;
        b.closest('tr').remove();
        document.getElementById('noServices').hidden = servicesBody.children.length > 0;
        calcTotals();
    });

    document.getElementById('proformaForm').addEventListener('submit', e => {
        let ok = true;
        productsBody.querySelectorAll('.product-row').forEach(tr => { if (!checkMin(tr)) ok = false; });
        const hasProduct = [...productsBody.querySelectorAll('.product-id')].some(s => s.value);
        let msg = '';
        productsBody.querySelectorAll('.product-row').forEach(tr => tr.querySelector('.product-search').classList.toggle('is-invalid', !tr.querySelector('.product-id').value && !!tr.querySelector('.product-search').value));
        if (!$customer.val()) msg = 'Select a customer before saving.';
        else if (!hasProduct) msg = 'Add at least one product before saving.';
        else if (!ok) msg = 'Some prices are below the minimum. Raise them to the minimum shown under each price.';
        if (msg) {
            e.preventDefault();
            let box = document.getElementById('formError');
            if (!box) { box = document.createElement('div'); box.id = 'formError'; box.className = 'alert alert-danger'; box.setAttribute('role', 'alert'); document.getElementById('proformaForm').before(box); }
            box.textContent = msg; box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            document.getElementById('saveBtn').disabled = true;
            // disabled butonun adı gönderilmez; gizli alan ekle
            const h = document.createElement('input'); h.type = 'hidden'; h.name = document.getElementById('saveBtn').name; h.value = '1'; e.target.appendChild(h);
        }
    });

    /* ---------- Başlangıç ---------- */
    fillProjects(initialProject);
    if (initialItems.length) initialItems.forEach(addProductRow); else addProductRow();
    initialServices.forEach(addServiceRow);
    calcTotals();
})();
</script>
