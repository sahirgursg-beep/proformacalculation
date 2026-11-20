/**
 * FrProductPicker — 12.000 ürünü sayfaya basmadan, yazdıkça arayan ürün seçici.
 * Kullanım: <select name="x" class="form-select js-product-picker"><option value="ID" selected>KOD · Ad</option></select>
 * Seçim yapılınca orijinal <select> güncellenir ve 'change' olayı tetiklenir (mevcut kodlar çalışmaya devam eder).
 */
(function (w) {
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let uid = 0;

    function attach(select) {
        if (!select || select.dataset.frPicker) return;
        select.dataset.frPicker = '1';
        const required = select.required;
        select.required = false;
        select.hidden = true;
        select.tabIndex = -1;

        const box = document.createElement('div');
        box.className = 'fr-combo';
        const listId = 'frpk' + (++uid);
        const cur = select.selectedOptions[0];
        box.innerHTML = '<input type="text" class="form-control" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="' + listId + '" placeholder="' + esc(select.dataset.placeholder || 'Type a code or product name') + '">' +
                        '<ul class="fr-combo-list" role="listbox" id="' + listId + '" hidden></ul>';
        select.after(box);
        const input = box.querySelector('input'), list = box.querySelector('ul');
        input.required = required;
        let chosen = cur && cur.value ? cur.textContent.trim() : '';
        input.value = chosen;

        let results = [], active = -1, timer = null, ctrl = null;
        const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); };
        const render = () => {
            list.innerHTML = results.length ? '' : '<li class="fr-combo-empty">No products match “' + esc(input.value) + '”</li>';
            results.forEach((r, i) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option'); li.id = listId + '-' + i;
                if (i === active) li.className = 'is-active';
                li.innerHTML = '<span class="fr-opt-code">' + esc(r.code) + '</span>' + esc(r.name);
                li.addEventListener('mousedown', e => { e.preventDefault(); choose(i); });
                list.appendChild(li);
            });
            input.setAttribute('aria-activedescendant', active >= 0 ? listId + '-' + active : '');
            list.hidden = false; input.setAttribute('aria-expanded', 'true');
            const a = list.querySelector('.is-active'); if (a) a.scrollIntoView({ block: 'nearest' });
        };
        const setValue = (id, text) => {
            select.innerHTML = '';
            const o = new Option(text, id, true, true);
            select.appendChild(o);
            select.dispatchEvent(new Event('change', { bubbles: true }));
            if (w.jQuery) w.jQuery(select).trigger('change');
        };
        const choose = i => {
            const r = results[i]; if (!r) return;
            chosen = r.code + ' · ' + r.name;
            input.value = chosen; input.classList.remove('is-invalid');
            close(); setValue(r.id, chosen);
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
        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 220); });
        input.addEventListener('focus', () => input.select());
        input.addEventListener('keydown', e => {
            if (list.hidden) { if (e.key === 'ArrowDown') search(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, results.length - 1); render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
            else if (e.key === 'Enter') { e.preventDefault(); if (active >= 0) choose(active); }
            else if (e.key === 'Escape') close();
        });
        input.addEventListener('blur', () => setTimeout(() => {
            close();
            if (!input.value.trim() && select.value) { chosen = ''; setValue('', ''); select.innerHTML = '<option value=""></option>'; }
            else if (input.value !== chosen) { input.value = chosen; }
            input.classList.toggle('is-invalid', required && !select.value);
        }, 120));
    }

    function init(root) { (root || document).querySelectorAll('select.js-product-picker').forEach(attach); }
    w.FrProductPicker = { attach, init };
    document.addEventListener('DOMContentLoaded', () => init());
})(window);
