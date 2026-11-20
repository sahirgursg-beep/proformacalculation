<?php
/**
 * Yedekler — veritabanı ve ürün resimleri. Sadece Super Admin.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/classes/BackupService.php';
$conn = (new Database())->getConnection();
$bk = new BackupService($conn);
$page_title = 'Backups';

$flash = function ($type, $msg) { $_SESSION['bk_flash'] = [$type, $msg]; header('Location: backups.php'); exit; };

/* ---------- İndir ---------- */
if (isset($_GET['download'])) {
    try {
        $p = $bk->path($_GET['download']);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($p) . '"');
        header('Content-Length: ' . filesize($p));
        header('Cache-Control: no-store');
        readfile($p);
        exit;
    } catch (Throwable $e) { $flash('danger', $e->getMessage()); }
}

/* ---------- İşlemler ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'db') {
            $r = $bk->createDb('manual');
            $flash('success', 'Database backup created: ' . number_format($r['rows']) . ' rows in ' . $r['tables'] . ' tables (' . BackupService::size($r['size']) . ').');
        }
        if ($action === 'images') {
            $r = $bk->createImages('manual');
            $flash('success', 'Image backup created: ' . number_format($r['files']) . ' files (' . BackupService::size($r['size']) . ').');
        }
        if ($action === 'delete') {
            $bk->delete($_POST['name'] ?? '');
            $flash('success', 'Backup deleted.');
        }
        if ($action === 'settings') {
            $bk->saveSettings(['auto' => !empty($_POST['auto']), 'keep_days' => $_POST['keep_days'] ?? 30, 'keep_min' => $_POST['keep_min'] ?? 7]);
            $flash('success', 'Backup settings saved.');
        }
        if ($action === 'restore' || $action === 'restore_upload') {
            if (trim((string)($_POST['confirm'] ?? '')) !== 'RESTORE') $flash('danger', 'Type RESTORE in capital letters to confirm.');
            if ($action === 'restore') {
                $file = $bk->path($_POST['name'] ?? '');
            } else {
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) $flash('danger', 'Choose a backup file to upload.');
                if (!preg_match('/\.(sql|sql\.gz)$/i', $f['name'])) $flash('danger', 'Upload a .sql or .sql.gz file.');
                $ext = preg_match('/\.gz$/i', $f['name']) ? 'sql.gz' : 'sql';
                $file = $bk->dir() . '/' . sprintf('frcalc_db_%s_uploaded_%s.%s', date('Ymd_His'), bin2hex(random_bytes(4)), $ext);
                if (!move_uploaded_file($f['tmp_name'], $file)) $flash('danger', 'The file could not be stored on the server.');
            }
            $r = $bk->restore($file);
            $flash('success', 'Restore complete (' . number_format($r['statements']) . ' statements). The data from just before the restore was saved as ' . $r['safety'] . '.');
        }
    } catch (Throwable $e) {
        error_log('Backup error: ' . $e->getMessage());
        $flash('danger', $e->getMessage());
    }
}

$msg = $_SESSION['bk_flash'] ?? null; unset($_SESSION['bk_flash']);
$list = $bk->all();
$settings = $bk->settings();
$last = $bk->lastDbBackup();
$used = array_sum(array_column($list, 'size'));
$labels = ['manual' => 'Manual', 'auto' => 'Automatic', 'pre-restore' => 'Before restore', 'setup' => 'Setup', 'uploaded' => 'Uploaded'];
$ago = function ($t) { $d = time() - $t; return $d < 3600 ? max(1, floor($d / 60)) . ' min ago' : ($d < 86400 ? floor($d / 3600) . ' h ago' : floor($d / 86400) . ' days ago'); };
$appDir = realpath(__DIR__);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="fr-main">
    <div class="fr-page-head">
        <div>
            <h1>Backups</h1>
            <p>Copies of the database (customers, products, recipes, proformas, settings) and product images. Download a copy to your computer regularly; backups kept only on the server are lost if the server is lost.</p>
        </div>
        <div class="fr-actions">
            <form method="POST" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Backing up…';">
                <input type="hidden" name="action" value="db"><button class="btn btn-primary"><i class="fa-solid fa-database me-1"></i> Back up database now</button>
            </form>
            <?php if (class_exists('ZipArchive')): ?>
            <form method="POST" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Zipping images…';">
                <input type="hidden" name="action" value="images"><button class="btn btn-secondary"><i class="fa-solid fa-images me-1"></i> Back up images</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg): ?><div class="alert alert-<?php echo e($msg[0]); ?>"><?php echo e($msg[1]); ?></div><?php endif; ?>

    <div class="fr-figures">
        <div class="fr-figure"><div class="fr-figure-label">Last database backup</div><div class="fr-figure-value" style="<?php echo !$last || $last['time'] < time() - 7 * 86400 ? 'color:var(--fr-oxblood)' : ''; ?>"><?php echo $last ? e($ago($last['time'])) : 'Never'; ?></div><div class="fr-figure-note"><?php echo $last ? e(date('j M Y, H:i', $last['time'])) : 'Create the first backup now'; ?></div></div>
        <div class="fr-figure"><div class="fr-figure-label">Automatic daily backup</div><div class="fr-figure-value"><?php echo $settings['auto'] ? 'On' : 'Off'; ?></div><div class="fr-figure-note"><?php echo $settings['auto'] ? 'Kept for ' . (int)$settings['keep_days'] . ' days' : 'Turn on below'; ?></div></div>
        <div class="fr-figure"><div class="fr-figure-label">Backups stored</div><div class="fr-figure-value"><?php echo count($list); ?></div><div class="fr-figure-note"><?php echo e(BackupService::size($used)); ?> on the server</div></div>
        <div class="fr-figure"><div class="fr-figure-label">Free disk space</div><div class="fr-figure-value"><?php $free = @disk_free_space($bk->dir()); echo $free ? e(BackupService::size($free)) : '—'; ?></div><div class="fr-figure-note">on the backup folder’s disk</div></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Backup files</h2></div>
                <?php if ($list): ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Date</th><th>Contents</th><th>Made by</th><th class="text-end">Size</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($list as $b): ?>
                            <tr>
                                <td><?php echo e(date('j M Y, H:i', $b['time'])); ?><div class="small text-muted"><?php echo e($ago($b['time'])); ?></div></td>
                                <td><?php echo $b['kind'] === 'db' ? '<i class="fa-solid fa-database text-muted me-1"></i> Database' : '<i class="fa-solid fa-images text-muted me-1"></i> Product images'; ?></td>
                                <td><span class="badge bg-secondary"><?php echo e($labels[$b['trigger']] ?? $b['trigger']); ?></span></td>
                                <td class="text-end"><?php echo e(BackupService::size($b['size'])); ?></td>
                                <td class="text-end text-nowrap">
                                    <a href="backups.php?download=<?php echo urlencode($b['name']); ?>" class="btn btn-sm btn-outline-secondary" title="Download"><i class="fa-solid fa-download"></i></a>
                                    <?php if ($b['kind'] === 'db'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-warning" title="Restore this backup" data-bs-toggle="modal" data-bs-target="#restoreModal" data-name="<?php echo e($b['name']); ?>" data-when="<?php echo e(date('j M Y, H:i', $b['time'])); ?>"><i class="fa-solid fa-clock-rotate-left"></i></button>
                                    <?php endif; ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this backup file permanently?');">
                                        <input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?php echo e($b['name']); ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="fr-empty">No backups yet. Use “Back up database now” to create the first one.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header"><h2 class="card-title">Automatic backup</h2></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="settings">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="bkAuto" name="auto" value="1" <?php echo $settings['auto'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="bkAuto">Back up the database once a day</label>
                        </div>
                        <div class="row g-2 mb-3 align-items-end">
                            <div class="col-6"><label class="form-label" for="bkDays">Keep auto backups</label><div class="input-group"><input type="number" id="bkDays" name="keep_days" class="form-control" min="3" max="365" value="<?php echo (int)$settings['keep_days']; ?>"><span class="input-group-text">days</span></div></div>
                            <div class="col-6"><label class="form-label" for="bkMin">Keep at least</label><div class="input-group"><input type="number" id="bkMin" name="keep_min" class="form-control" min="3" max="100" value="<?php echo (int)$settings['keep_min']; ?>"><span class="input-group-text">copies</span></div></div>
                        </div>
                        <button class="btn btn-secondary">Save settings</button>
                    </form>
                    <p class="small text-muted mt-3 mb-0">The daily backup runs in the background when an admin or architect opens the dashboard and the last backup is older than 24 hours. Manual and “before restore” backups are never deleted automatically.</p>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h2 class="card-title">Restore from a file</h2></div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Replace ALL current data with this file? A backup of the current data is taken first.');">
                        <input type="hidden" name="action" value="restore_upload">
                        <input type="file" name="file" class="form-control mb-2" accept=".sql,.gz" required>
                        <label class="form-label" for="rConfirm2">Type RESTORE to confirm</label>
                        <input type="text" id="rConfirm2" name="confirm" class="form-control mb-2" autocomplete="off" required pattern="RESTORE">
                        <button class="btn btn-outline-danger w-100">Restore uploaded backup</button>
                    </form>
                    <p class="small text-muted mt-2 mb-0">.sql or .sql.gz from this page or from phpMyAdmin.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Server cron (optional)</h2></div>
                <div class="card-body small">
                    <p>For a backup at a fixed time even when nobody logs in, add this in cPanel → Cron Jobs (once a day):</p>
                    <code style="word-break:break-all;display:block;background:#F3EDE2;padding:8px;border-radius:4px">php <?php echo e($appDir); ?>/cron_backup.php</code>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Geri yükleme onayı -->
<div class="modal fade" id="restoreModal" tabindex="-1" aria-labelledby="restoreTitle" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="name" id="restoreName">
            <div class="modal-header"><h2 class="modal-title" id="restoreTitle">Restore backup</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p>All current data will be replaced with the backup from <strong id="restoreWhen"></strong>. Proformas, products and changes made after that time will be lost.</p>
                <p class="small text-muted">A backup of the current data is taken automatically first, so you can undo this.</p>
                <label class="form-label" for="rConfirm">Type RESTORE to confirm</label>
                <input type="text" id="rConfirm" name="confirm" class="form-control" autocomplete="off" required pattern="RESTORE">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger" onclick="this.form.checkValidity() && setTimeout(()=>{this.disabled=true;this.textContent='Restoring…'},0)">Restore</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('restoreModal').addEventListener('show.bs.modal', e => {
    document.getElementById('restoreName').value = e.relatedTarget.dataset.name;
    document.getElementById('restoreWhen').textContent = e.relatedTarget.dataset.when;
    document.getElementById('rConfirm').value = '';
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
