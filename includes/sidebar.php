<?php
require_once __DIR__ . '/bootstrap.php';
$user_role = $_SESSION['role'] ?? 'SALES';
$user_name = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User');
$fr_page = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Aktif sayfa için ilgili alt sayfalar da menüde seçili görünsün
$fr_aliases = [
    'add_proforma.php' => 'proformas.php', 'edit_proforma.php' => 'proformas.php', 'view_proforma.php' => 'proformas.php',
    'edit_project.php' => 'projects.php', 'view_project.php' => 'projects.php',
    'edit_customer.php' => 'customers.php', 'customer_details.php' => 'customers.php',
    'add_product.php' => 'products.php', 'edit_product.php' => 'products.php', 'import_products.php' => 'products.php',
    'add_recipe.php' => 'recipes.php', 'edit_recipe.php' => 'recipes.php', 'import_recipes.php' => 'recipes.php',
    'edit_category.php' => 'categories.php', 'wizard_steps.php' => 'wizards.php', 'edit_service.php' => 'additional_services.php',
    'edit_customer_type.php' => 'customer_types.php', 'edit_user.php' => 'users.php',
];
$fr_active = $fr_aliases[$fr_page] ?? $fr_page;
$fr_wizard_id = $fr_page === 'run_wizard.php' ? (int)($_GET['id'] ?? 0) : 0;

$active_wizards = [];
if (isset($conn) && in_array($user_role, ['SUPER_ADMIN', 'ARCHITECT'], true)) {
    try {
        $active_wizards = $conn->query("SELECT wizard_id, wizard_name FROM wizard_templates WHERE status = 'ACTIVE' ORDER BY wizard_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $active_wizards = []; }
}

// Üst barda güncel NGN kuru
$fr_rate = null;
if (isset($conn)) {
    try { $fr_rate = $conn->query("SELECT buy_rate, sale_rate FROM rate_exchange_history WHERE currency_code = 'NGN' ORDER BY created_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (PDOException $e) {}
}

function fr_link($href, $icon, $label, $active, $extra = '') {
    $cls = $active ? ' class="active" aria-current="page"' : '';
    return '<a href="' . e($href) . '"' . $cls . $extra . '><i class="fa-solid ' . e($icon) . '" aria-hidden="true"></i><span>' . e($label) . '</span></a>';
}
$is = fn($p) => $fr_active === $p;
$initial = mb_strtoupper(mb_substr(trim($user_name) ?: 'U', 0, 1));
?>
<nav class="fr-sidebar" id="frSidebar" aria-label="Main navigation">
    <a class="fr-brand" href="index.php">
        <span class="fr-brand-name">Furniture<br><span>Republic</span></span>
        <span class="fr-brand-sub">Costing &amp; proforma</span>
    </a>

    <div class="fr-nav">
        <?php if (is_sales_role()): ?>
            <?php echo fr_link('price_lists.php', 'fa-tags', 'Price list', $is('price_lists.php')); ?>
        <?php else: ?>
        <?php echo fr_link('index.php', 'fa-house', 'Dashboard', $is('index.php')); ?>
        <?php echo fr_link('price_lists.php', 'fa-tags', 'Price list', $is('price_lists.php')); ?>

        <div class="fr-nav-group">Sales</div>
        <?php echo fr_link('proformas.php', 'fa-file-invoice', 'Proformas', $is('proformas.php')); ?>
        <?php echo fr_link('customers.php', 'fa-user-group', 'Customers', $is('customers.php')); ?>
        <?php echo fr_link('projects.php', 'fa-compass-drafting', 'Projects', $is('projects.php')); ?>
        <?php foreach ($active_wizards as $wz): ?>
            <?php echo str_replace('<a ', '<a class="fr-sub' . ($fr_wizard_id === (int)$wz['wizard_id'] ? ' active' : '') . '" ', fr_link('run_wizard.php?id=' . (int)$wz['wizard_id'], 'fa-wand-magic-sparkles', $wz['wizard_name'], false)); ?>
        <?php endforeach; ?>

        <?php if ($user_role === 'SUPER_ADMIN'): ?>
            <div class="fr-nav-group">Catalogue</div>
            <?php echo fr_link('products.php', 'fa-couch', 'Products', $is('products.php')); ?>
            <?php echo fr_link('recipes.php', 'fa-layer-group', 'Recipes (BOM)', $is('recipes.php')); ?>
        <?php endif; ?>
        <?php if (in_array($user_role, ['SUPER_ADMIN', 'ARCHITECT'], true)): ?>
            <?php echo fr_link('categories.php', 'fa-folder-tree', 'Categories', $is('categories.php')); ?>
        <?php endif; ?>
        <?php if ($user_role === 'SUPER_ADMIN'): ?>
            <?php echo fr_link('units.php', 'fa-ruler-combined', 'Units', $is('units.php')); ?>
        <?php endif; ?>

        <?php if (in_array($user_role, ['SUPER_ADMIN', 'ARCHITECT'], true)): ?>
            <div class="fr-nav-group">Setup</div>
            <?php echo fr_link('wizards.php', 'fa-list-check', 'Wizard builder', $is('wizards.php')); ?>
        <?php endif; ?>
        <?php if ($user_role === 'SUPER_ADMIN'): ?>
            <?php echo fr_link('exchange_rates.php', 'fa-money-bill-transfer', 'Exchange rates', $is('exchange_rates.php')); ?>
            <?php echo fr_link('customer_types.php', 'fa-percent', 'Customer types', $is('customer_types.php')); ?>
            <?php echo fr_link('additional_services.php', 'fa-truck', 'Additional services', $is('additional_services.php')); ?>
            <?php echo fr_link('shipment_settings.php', 'fa-ship', 'Shipment', $is('shipment_settings.php')); ?>
            <?php echo fr_link('settings.php', 'fa-sliders', 'Pricing settings', $is('settings.php')); ?>
            <?php echo fr_link('users.php', 'fa-user-shield', 'Users & roles', $is('users.php')); ?>
            <?php echo fr_link('backups.php', 'fa-box-archive', 'Backups', $is('backups.php')); ?>
            <?php echo fr_link('cost_debugger.php', 'fa-magnifying-glass-dollar', 'Cost debugger', $is('cost_debugger.php')); ?>
        <?php endif; ?>
        <?php endif; /* satış rolü değil */ ?>
    </div>

    <div class="fr-user">
        <div class="fr-avatar" aria-hidden="true"><?php echo e($initial); ?></div>
        <div>
            <div class="fr-user-name"><?php echo e($user_name); ?></div>
            <div class="fr-user-role"><?php echo e(strtolower(str_replace('_', ' ', $user_role))); ?></div>
        </div>
        <a class="fr-logout" href="logout.php" title="Sign out" aria-label="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
    </div>
</nav>

<header class="fr-topbar">
    <button type="button" class="fr-menu-btn" aria-label="Open menu" onclick="document.body.classList.toggle('fr-nav-open')"><i class="fa-solid fa-bars"></i></button>
    <div class="d-flex align-items-center gap-4">
        <?php if ($fr_rate): ?>
            <span class="fr-rate" title="NGN rate used for prices">USD/NGN &nbsp;buy <b><?php echo number_format((float)$fr_rate['buy_rate'], 0); ?></b> &nbsp; sell <b><?php echo number_format((float)$fr_rate['sale_rate'], 0); ?></b></span>
        <?php endif; ?>
        <span><?php echo date('l, j F Y'); ?></span>
    </div>
</header>
