<?php
/**
 * FRCALC — sunucu kurulum sihirbazı (cPanel).
 * Sadece SUPER_ADMIN açabilir. Kurulum bitince kendini kilitler ve silmeyi dener.
 *
 * Adımlar:  1) Kontroller   2) Yedek indir   3) Veritabanını güncelle (migration)   4) Bitir
 * Migration her adımı önce kontrol eder; tekrar çalıştırmak güvenlidir.
 */
require_once __DIR__ . '/includes/auth.php';
require_role(['SUPER_ADMIN']);

$LOCK = __DIR__ . '/config/install.lock';
if (file_exists($LOCK)) {
    http_response_code(404);
    exit('<!DOCTYPE html><meta charset="utf-8"><div style="font-family:system-ui;max-width:460px;margin:15vh auto;text-align:center"><h2>Setup already completed</h2><p>Delete <code>kurulum.php</code> from the server if it is still there.</p><a href="index.php">Open the app</a></div>');
}

$conn = (new Database())->getConnection();
$isMysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
@set_time_limit(600);

/* ---------- Tanımlar ---------- */
const FR_INDEXES = [
    ['rate_exchange_history', 'idx_currency_created', '`currency_code`, `created_at`'],
    ['product_unit_conversions', 'idx_product_unit', '`product_id`, `unit_id`'],
    ['product_recipe_items', 'idx_recipe_item', '`recipe_id`, `item_id`'],
    ['proformas', 'idx_created', '`created_at`'],
    ['proformas', 'idx_status', '`status`'],
    ['products', 'idx_category_code', '`category_id`, `stock_code`'],
    ['projects', 'idx_status', '`project_status`'],
];

function q_ident($n) { return '`' . str_replace('`', '``', $n) . '`'; }

/** Veritabanının mevcut durumu */
function db_state(PDO $c) {
    $tables = $c->query("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
    $idx = [];
    foreach ($c->query("SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()") as $r) $idx[$r['TABLE_NAME']][$r['INDEX_NAME']] = true;
    $names = array_column($tables, 'TABLE_NAME');
    $missingIdx = [];
    foreach (FR_INDEXES as [$t, $n, $cols]) if (in_array($t, $names, true) && empty($idx[$t][$n])) $missingIdx[] = "$t.$n";
    $toConvert = array_values(array_filter($tables, fn($t) => $t['ENGINE'] !== 'InnoDB' || stripos((string)$t['TABLE_COLLATION'], 'utf8mb4') !== 0));
    $badRate = in_array('proformas', $names, true) ? (int)$c->query("SELECT COUNT(*) FROM proformas WHERE currency = 'NGN' AND ngn_rate <= 1")->fetchColumn() : 0;
    return compact('tables', 'missingIdx', 'toConvert', 'badRate');
}

/* ---------- 2) Yedek indir (.sql) ---------- */
if (($_GET['action'] ?? '') === 'backup' && $isMysql) {
    $db = $conn->query("SELECT DATABASE()")->fetchColumn();
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $db . '_backup_' . date('Ymd_His') . '.sql"');
    header('Cache-Control: no-store');
    while (ob_get_level()) ob_end_clean();
    echo "-- FRCALC backup of `$db` — " . date('Y-m-d H:i:s') . "\n-- Restore with phpMyAdmin > Import.\n\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
    $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    foreach ($conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$t]) {
        $sc = $conn->query("SHOW CREATE TABLE " . q_ident($t));
        $create = $sc->fetch(PDO::FETCH_NUM)[1];
        $sc->closeCursor();   // tamponsuz sorguda bir sonraki sorgudan önce kapatılmalı
        echo "DROP TABLE IF EXISTS " . q_ident($t) . ";\n$create;\n\n";
        $st = $conn->query("SELECT * FROM " . q_ident($t));
        $batch = [];
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $conn->quote((string)$v), $row)) . ')';
            if (count($batch) >= 200) { echo "INSERT INTO " . q_ident($t) . " VALUES\n" . implode(",\n", $batch) . ";\n"; $batch = []; }
        }
        if ($batch) echo "INSERT INTO " . q_ident($t) . " VALUES\n" . implode(",\n", $batch) . ";\n";
        $st->closeCursor();
        echo "\n";
        flush();
    }
    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit;
}

/* ---------- 3) Migration ---------- */
$log = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'migrate' && $isMysql) {
    // Arrow fn değişkeni kopyalar; listeye yazabilmek için referans kullanılır
    $ok   = function ($m) use (&$log) { $log[] = ['ok', $m]; };
    $warn = function ($m) use (&$log) { $log[] = ['warn', $m]; };
    $err  = function ($m) use (&$log) { $log[] = ['err', $m]; };

    try { $conn->exec("ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $ok('Database default character set: utf8mb4'); }
    catch (Throwable $e) { $warn('Database default character set not changed (the host may not allow it). Tables are still converted below.'); }

    $state = db_state($conn);
    foreach ($state['toConvert'] as $t) {
        try {
            $conn->exec("ALTER TABLE " . q_ident($t['TABLE_NAME']) . " ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $ok("Table {$t['TABLE_NAME']}: {$t['ENGINE']} / {$t['TABLE_COLLATION']} → InnoDB / utf8mb4");
        } catch (Throwable $e) { $err("Table {$t['TABLE_NAME']}: " . $e->getMessage()); }
    }
    if (!$state['toConvert']) $ok('All tables are already InnoDB / utf8mb4.');

    $state = db_state($conn);
    foreach (FR_INDEXES as [$t, $n, $cols]) {
        if (!in_array("$t.$n", $state['missingIdx'], true)) continue;
        try { $conn->exec("ALTER TABLE " . q_ident($t) . " ADD INDEX " . q_ident($n) . " ($cols)"); $ok("Index added: $t.$n"); }
        catch (Throwable $e) { $err("Index $t.$n: " . $e->getMessage()); }
    }
    if (!$state['missingIdx']) $ok('All performance indexes already exist.');

    try {
        $n = $conn->exec("UPDATE proformas SET ngn_rate = (SELECT sale_rate FROM rate_exchange_history WHERE currency_code = 'NGN' ORDER BY created_at DESC LIMIT 1) WHERE currency = 'NGN' AND ngn_rate <= 1");
        $ok($n ? "Fixed the exchange rate on $n wizard proforma(s) saved with rate 1." : 'No proformas with a missing exchange rate.');
    } catch (Throwable $e) { $err('Exchange rate fix: ' . $e->getMessage()); }

    try { $conn->query("ANALYZE TABLE products, product_recipes, product_recipe_items, proformas, proforma_items, rate_exchange_history")->fetchAll(); $ok('Table statistics refreshed.'); }
    catch (Throwable $e) { $warn('ANALYZE TABLE skipped: ' . $e->getMessage()); }
}

/* ---------- 4) Bitir ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finish') {
    @file_put_contents($LOCK, 'Installed ' . date('c') . ' by ' . ($_SESSION['username'] ?? '?') . "\n");
    $deleted = @unlink(__FILE__);   // dosya silinse de bu istek tamamlanır
    exit('<!DOCTYPE html><meta charset="utf-8"><link rel="stylesheet" href="' . vendor_url('bootstrap_css') . '"><link rel="stylesheet" href="' . asset('assets/css/theme.css') . '"><div style="max-width:520px;margin:15vh auto;text-align:center" class="card"><div class="card-body p-5"><h1>Setup complete</h1><p class="text-muted">'
        . ($deleted ? 'The setup file has removed itself.' : 'Delete <code>kurulum.php</code> from the server with cPanel File Manager.')
        . '</p><a class="btn btn-primary" href="index.php">Open FR Calculation</a></div></div>');
}

/* ---------- 1) Kontroller ---------- */
$checks = [];
$add = function ($label, $pass, $detail = '', $level = 'err') use (&$checks) { $checks[] = [$label, $pass ? 'ok' : $level, $detail]; };
$add('PHP 8.0 or newer', version_compare(PHP_VERSION, '8.0.0', '>='), 'Running PHP ' . PHP_VERSION);
foreach (['pdo_mysql', 'mbstring', 'json'] as $ext) $add("PHP extension: $ext", extension_loaded($ext));
$add('PHP extension: zip (Excel import)', extension_loaded('zip'), 'Only needed for importing .xlsx files', 'warn');
$add('Database connection', $isMysql, $isMysql ? 'Connected to ' . $conn->query("SELECT DATABASE()")->fetchColumn() . ' (' . $conn->getAttribute(PDO::ATTR_SERVER_VERSION) . ')' : 'Not a MySQL database');
foreach (['uploads/products', 'uploads/wizards', 'storage/backups', 'storage/imports', 'config'] as $d) {
    $p = __DIR__ . '/' . $d;
    if (!is_dir($p)) @mkdir($p, 0755, true);
    $add("Folder writable: $d", is_writable($p), '', $d === 'config' ? 'warn' : 'err');
}
$add('Old reset_admin.php removed', !file_exists(__DIR__ . '/reset_admin.php') || @unlink(__DIR__ . '/reset_admin.php'), 'This file let anyone reset the admin password.');
$add('Debug mode is off', empty(app_config()['debug']), 'Set \'debug\' => false in config/db_config.php', 'warn');
$add('HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'), 'Use https:// so passwords are not sent in plain text. cPanel > SSL/TLS Status > Run AutoSSL.', 'warn');

$state = $isMysql ? db_state($conn) : null;
$needsMigration = $state && ($state['toConvert'] || $state['missingIdx'] || $state['badRate']);
$blocking = array_filter($checks, fn($c) => $c[1] === 'err');

$page_title = 'Server setup';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
$icon = ['ok' => '<i class="fa-solid fa-circle-check text-success"></i>', 'warn' => '<i class="fa-solid fa-triangle-exclamation text-warning"></i>', 'err' => '<i class="fa-solid fa-circle-xmark text-danger"></i>'];
?>
<main class="fr-main">
    <div class="fr-page-head">
        <div>
            <h1>Server setup</h1>
            <p>Run these steps once after uploading the new version. Every step is safe to repeat.</p>
        </div>
    </div>

    <?php if ($log): ?>
        <div class="card mb-4">
            <div class="card-header"><h2 class="card-title">Database update result</h2></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($log as [$lv, $m]): ?><li class="list-group-item"><?php echo $icon[$lv]; ?> <?php echo e($m); ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h2 class="card-title">1. Server check</h2></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($checks as [$label, $lv, $detail]): ?>
                        <li class="list-group-item d-flex gap-2"><span><?php echo $icon[$lv]; ?></span><span><strong class="fw-semibold"><?php echo e($label); ?></strong><?php if ($detail && $lv !== 'ok' || ($detail && str_starts_with($label, 'PHP 8') ) || ($detail && $label === 'Database connection')): ?><br><span class="small text-muted"><?php echo e($detail); ?></span><?php endif; ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card mb-4">
                <div class="card-header"><h2 class="card-title">2. Back up the database</h2></div>
                <div class="card-body">
                    <p class="mb-3">Download a full copy before changing anything. To restore it later, use phpMyAdmin → Import.</p>
                    <a href="kurulum.php?action=backup" class="btn btn-secondary <?php echo $isMysql ? '' : 'disabled'; ?>"><i class="fa-solid fa-download me-1"></i> Download backup (.sql)</a>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h2 class="card-title">3. Update the database</h2></div>
                <div class="card-body">
                    <?php if (!$isMysql): ?>
                        <p class="text-muted mb-0">Only needed on the MySQL server.</p>
                    <?php elseif (!$needsMigration): ?>
                        <p class="mb-0"><?php echo $icon['ok']; ?> The database is up to date (InnoDB, utf8mb4, all indexes).</p>
                    <?php else: ?>
                        <ul class="mb-3">
                            <?php if ($state['toConvert']): ?><li><?php echo count($state['toConvert']); ?> tables to convert to InnoDB / utf8mb4 (enables safe saving and ₦ / Turkish characters)</li><?php endif; ?>
                            <?php if ($state['missingIdx']): ?><li><?php echo count($state['missingIdx']); ?> performance indexes to add</li><?php endif; ?>
                            <?php if ($state['badRate']): ?><li><?php echo (int)$state['badRate']; ?> wizard proforma(s) with exchange rate 1 to fix</li><?php endif; ?>
                        </ul>
                        <form method="POST" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Updating… this can take a minute';">
                            <input type="hidden" name="action" value="migrate">
                            <button type="submit" class="btn btn-primary" <?php echo $blocking ? 'disabled' : ''; ?>>Update the database</button>
                        </form>
                        <p class="small text-muted mt-2 mb-0">Download the backup first. The app stays usable during the update.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">4. Finish</h2></div>
                <div class="card-body">
                    <p>Locks this setup page and removes it from the server.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="finish">
                        <button type="submit" class="btn btn-success" <?php echo ($needsMigration || $blocking) ? 'disabled' : ''; ?>>Finish setup</button>
                    </form>
                    <?php if ($needsMigration || $blocking): ?><p class="small text-muted mt-2 mb-0">Available once the checks pass and the database is updated.</p><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($state): ?>
    <div class="card mt-4">
        <div class="card-header"><h2 class="card-title">Tables</h2></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Table</th><th>Engine</th><th>Collation</th><th class="text-end">Rows (approx.)</th></tr></thead>
                <tbody>
                <?php foreach ($state['tables'] as $t): $good = $t['ENGINE'] === 'InnoDB' && stripos((string)$t['TABLE_COLLATION'], 'utf8mb4') === 0; ?>
                    <tr><td><?php echo e($t['TABLE_NAME']); ?></td><td><?php echo $good ? '' : $icon['warn'] . ' '; ?><?php echo e($t['ENGINE']); ?></td><td><?php echo e($t['TABLE_COLLATION']); ?></td><td class="text-end"><?php echo number_format((int)$t['TABLE_ROWS']); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
