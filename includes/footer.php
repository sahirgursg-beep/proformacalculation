<footer class="fr-footer no-print">
    <span>© <?php echo date('Y'); ?> Furniture Republic · Fırat Building Materials Ltd.</span>
    <span>FR Calculation v<?php echo FR_VERSION; ?></span>
</footer>

<script src="<?php echo vendor_url('bootstrap_js'); ?>"></script>
<script>
// Tüm jQuery AJAX POST isteklerine CSRF anahtarı ekle
(function () {
    var t = document.querySelector('meta[name="csrf-token"]');
    if (window.jQuery && t) jQuery.ajaxSetup({ headers: { 'X-CSRF-Token': t.content } });
})();
// Eski sayfalardaki .select2-search kutuları
window.addEventListener('load', function () {
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('.select2-search').each(function () {
            if (!jQuery(this).hasClass('select2-hidden-accessible')) jQuery(this).select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'Search…' });
        });
    }
});
</script>
</body>
</html>
