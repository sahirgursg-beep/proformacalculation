<?php
/**
 * Excel toplu içe aktarma: yükle → önizle (hiçbir şey yazılmaz) → onayla (tek transaction).
 * import.php?type=products|recipes|categories   (&template=1 şablon, &export=1 mevcut veri)
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/classes/ImportService.php';
$conn = (new Database())->getConnection();

$type = $_GET['type'] ?? $_POST['type'] ?? '';
if (!isset(ImportService::TYPES[$type])) { header('Location: index.php'); exit; }
require_role(ImportService::TYPES[$type]['roles']);
$label = ImportService::TYPES[$type]['label'];
$svc = new ImportService($conn);
$backTo = ['products' => 'products.php', 'recipes' => 'recipes.php', 'categories' => 'categories.php'][$type];

if (!empty($_GET['template'])) ImportService::template($type);
if (!empty($_GET['export'])) { @set_time_limit(300); $svc->export($type); }

$dir = __DIR__ . '/storage/imports';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
if (!file_exists(__DIR__ . '/storage/.htaccess')) @file_put_contents(__DIR__ . '/storage/.htaccess', "Require all denied\n");
foreach (glob($dir . '/*') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);   // 1 günden eski bekleyenleri temizle

$error = ''; $analysis = null; $token = null; $result = null;
@set_time_limit(300);

/* ---------- 1) Yükle ve önizle ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    $f = $_FILES['file'] ?? null;
    $codes = [UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows.', UPLOAD_ERR_FORM_SIZE => 'The file is too large.', UPLOAD_ERR_NO_FILE => 'Choose a file to upload.', UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.'];
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) $error = $codes[$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'The upload failed.';
    elseif ($f['size'] > 10 * 1024 * 1024) $error = 'The file is larger than 10 MB.';
    else {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv', 'xls'], true)) $error = 'Please upload an .xlsx or .csv file.';
        else {
            $token = bin2hex(random_bytes(16));
            $path = "$dir/$token.$ext";
            if (!move_uploaded_file($f['tmp_name'], $path)) $error = 'The file could not be stored on the server.';
            else {
                try {
                    $analysis = $svc->analyze($type, ExcelIO::read($path, $f['name']));
                    $_SESSION['imports'][$token] = ['path' => $path, 'name' => $f['name'], 'type' => $type, 'time' => time()];
                } catch (Throwable $e) { $error = $e->getMessage(); @unlink($path); $token = null; }
            }
        }
    }
}

/* ---------- 2) Onayla ve uygula ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    $token = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? ''));
    $job = $_SESSION['imports'][$token] ?? null;
    if (!$job || $job['type'] !== $type || !is_file($job['path'])) {
        $error = 'This preview has expired. Upload the file again.';
    } else {
        try {
            // Dosya yeniden doğrulanır (önizlemeden sonra veri değişmiş olabilir)
            $analysis = $svc->analyze($type, ExcelIO::read($job['path'], $job['name']));
            $result = $svc->apply($type, $analysis);
            $_SESSION['import_result'] = ['type' => $type, 'msg' => $result, 'errors' => count($analysis['errors']), 'file' => $job['name']];
            @unlink($job['path']); unset($_SESSION['imports'][$token]);
            header('Location: import.php?type=' . $type . '&done=1');
            exit;
        } catch (Throwable $e) {
            error_log('Import error: ' . $e->getMessage());
            $error = 'Nothing was changed. The import stopped with this error: ' . $e->getMessage();
        }
    }
}
$done = !empty($_GET['done']) ? ($_SESSION['import_result'] ?? null) : null;
unset($_SESSION['import_result']);

$fieldHelp = [
    'products' => ['Stock Code' => 'required; existing code = update, new code = new product', 'Stock Name' => 'required for new products', 'Category ID' => 'required for new products', 'Unit ID or Unit' => 'unit id, or unit name (e.g. QTY)', 'Unit Cost USD' => 'required for new products', 'Production Margin, Sales Margin' => 'percent, e.g. 50', 'Service Margin Type' => 'PERCENT, USD or NGN', 'Service Margin Value' => 'number'],
    'recipes' => ['Target Code' => 'product the recipe makes', 'Recipe Name' => 'optional', 'Material Code' => 'one row per material', 'Quantity' => 'above 0', 'Unit Name' => 'optional; empty = material’s main unit'],
    'categories' => ['Category Name' => 'required', 'Parent Category ID or Parent Category' => 'empty = main category; parent can be in the same file', 'Stock Code Start / End' => 'optional range'],
][$type];
$replaceNote = [
    'products' => 'Empty cells keep the current value. Columns that are not in the file are not touched.',
    'recipes' => 'A recipe in the file replaces that product’s whole recipe. Products not in the file are not touched.',
    'categories' => 'Existing categories (same name under the same parent) get their code range updated; nothing is deleted.',
][$type];

$page_title = "Import $label";
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
$fmtVal = fn($v) => is_float($v) ? rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') : (string)$v;
?>
<main class="fr-main">
    <div class="fr-page-head">
        <div>
            <h1>Import <?php echo e(strtolower($label)); ?> from Excel</h1>
            <p>Upload a file, check the preview, then confirm. Nothing changes until you confirm.</p>
        </div>
        <div class="fr-actions">
            <a href="import.php?type=<?php echo e($type); ?>&amp;template=1" class="btn btn-secondary"><i class="fa-solid fa-file-arrow-down me-1"></i> Template</a>
            <a href="import.php?type=<?php echo e($type); ?>&amp;export=1" class="btn btn-secondary"><i class="fa-solid fa-file-excel me-1"></i> Export current <?php echo e(strtolower($label)); ?></a>
            <a href="<?php echo e($backTo); ?>" class="btn btn-secondary">Back</a>
        </div>
    </div>

    <?php if ($done): ?>
        <div class="alert alert-success"><strong>Import complete.</strong> <?php echo e($done['msg']); ?><?php echo $done['errors'] ? ' ' . (int)$done['errors'] . ' rows with errors were skipped.' : ''; ?> <a href="<?php echo e($backTo); ?>">Open <?php echo e(strtolower($label)); ?></a></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

    <?php if (!$analysis): ?>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Upload file</h2></div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="preview">
                        <input type="hidden" name="type" value="<?php echo e($type); ?>">
                        <input type="file" name="file" class="form-control mb-3" accept=".xlsx,.csv" required>
                        <button type="submit" class="btn btn-primary">Check file</button>
                    </form>
                    <p class="small text-muted mt-3 mb-0">.xlsx or .csv, up to 10 MB. Tip: use “Export current <?php echo e(strtolower($label)); ?>”, edit the file in Excel and upload it here.</p>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Columns</h2></div>
                <table class="table mb-0">
                    <tbody><?php foreach ($fieldHelp as $k => $v): ?><tr><td class="fw-semibold" style="width:42%"><?php echo e($k); ?></td><td class="text-muted"><?php echo e($v); ?></td></tr><?php endforeach; ?></tbody>
                </table>
                <div class="card-footer small">Column order does not matter; columns are matched by their heading. Columns marked “(info)” are ignored. <?php echo e($replaceNote); ?></div>
            </div>
        </div>
    </div>
    <?php else:
        $act = array_filter($analysis['actions'], fn($a) => $a['op'] !== 'same');
    ?>
    <div class="fr-figures" style="grid-template-columns: repeat(<?php echo max(1, count($analysis['summary'])); ?>, 1fr)">
        <?php foreach ($analysis['summary'] as $k => $v): ?>
            <div class="fr-figure"><div class="fr-figure-label"><?php echo e($k); ?></div><div class="fr-figure-value" style="<?php echo (str_contains($k, 'error') || str_contains($k, 'skipped')) && $v ? 'color:var(--fr-oxblood)' : ''; ?>"><?php echo number_format((int)$v); ?></div></div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <?php if ($act): ?>
                    <strong><?php echo count($act); ?> changes are ready.</strong>
                    <?php echo $analysis['errors'] ? 'Rows with errors will be skipped; fix them in the file and import again if needed.' : 'No errors found.'; ?>
                    <div class="small text-muted"><?php echo e($replaceNote); ?> All changes are written together; if anything fails, nothing is written.</div>
                <?php else: ?>
                    <strong>Nothing to change.</strong> <?php echo $analysis['errors'] ? 'Fix the errors below and upload the file again.' : 'The file matches what is already in the system.'; ?>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <a href="import.php?type=<?php echo e($type); ?>" class="btn btn-secondary">Upload another file</a>
                <?php if ($act): ?>
                <form method="POST" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Importing…';">
                    <input type="hidden" name="action" value="apply">
                    <input type="hidden" name="type" value="<?php echo e($type); ?>">
                    <input type="hidden" name="token" value="<?php echo e($token); ?>">
                    <button type="submit" class="btn btn-primary">Apply <?php echo count($act); ?> changes</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($analysis['errors']): ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between"><h2 class="card-title">Errors (<?php echo count($analysis['errors']); ?>)</h2><span class="small text-muted">These rows will not be imported</span></div>
        <div class="table-responsive" style="max-height:420px">
            <table class="table table-sm mb-0"><thead><tr><th style="width:80px">Row</th><th>Problem</th></tr></thead><tbody>
            <?php foreach (array_slice($analysis['errors'], 0, 500) as [$ln, $msg]): ?><tr class="table-danger"><td><?php echo (int)$ln ?: '—'; ?></td><td><?php echo e($msg); ?></td></tr><?php endforeach; ?>
            <?php if (count($analysis['errors']) > 500): ?><tr><td></td><td class="text-muted">… and <?php echo count($analysis['errors']) - 500; ?> more.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($analysis['warnings']): ?>
    <div class="card mb-4">
        <div class="card-header"><h2 class="card-title">Notes (<?php echo count($analysis['warnings']); ?>)</h2></div>
        <ul class="list-group list-group-flush">
            <?php foreach (array_slice($analysis['warnings'], 0, 100) as [$ln, $msg]): ?><li class="list-group-item small"><?php echo $ln ? 'Row ' . (int)$ln . ': ' : ''; ?><?php echo e($msg); ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($act): ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between"><h2 class="card-title">Changes</h2><span class="small text-muted"><?php echo count($act) > 300 ? 'First 300 shown' : ''; ?></span></div>
        <div class="table-responsive" style="max-height:520px">
            <table class="table table-sm align-top mb-0">
                <thead><tr><th style="width:70px">Row</th><th style="width:90px">Action</th><th>Item</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($act, 0, 300) as $a): ?>
                    <tr>
                        <td><?php echo (int)$a['line']; ?></td>
                        <td><span class="badge <?php echo $a['op'] === 'insert' ? 'bg-success' : 'bg-warning'; ?>"><?php echo $a['op'] === 'insert' ? 'New' : ($a['op'] === 'replace' ? 'Replace' : 'Update'); ?></span></td>
                        <td class="fw-semibold"><?php echo e($a['code'] ?? $a['name'] ?? ''); ?></td>
                        <td class="small">
                            <?php if ($type === 'recipes'): ?>
                                <?php echo e($a['name']); ?> · <?php echo count($a['items']); ?> materials: <?php echo e(implode(', ', array_map(fn($i) => $i['code'] . ' × ' . $fmtVal((float)$i['quantity']), array_slice($a['items'], 0, 6)))); ?><?php echo count($a['items']) > 6 ? '…' : ''; ?>
                            <?php elseif ($a['op'] === 'update'): ?>
                                <?php foreach ($a['set'] as $f => $v): ?><div><span class="text-muted"><?php echo e(str_replace('_', ' ', $f)); ?>:</span> <?php echo isset($a['old'][$f]) ? '<del class="text-muted">' . e($fmtVal(is_numeric($a['old'][$f]) ? (float)$a['old'][$f] : $a['old'][$f])) . '</del> → ' : ''; ?><strong><?php echo e($fmtVal($v)); ?></strong></div><?php endforeach; ?>
                            <?php elseif ($type === 'products'): ?>
                                <?php echo e($a['set']['stock_name']); ?> · cost $<?php echo e($fmtVal($a['set']['unit_cost_usd'])); ?>
                            <?php else: ?>
                                <?php echo $a['parent_ref'] ? 'under “' . e($a['parent_ref']) . '” (new)' : ($a['parent_id'] ? 'under category #' . (int)$a['parent_id'] : 'main category'); ?><?php echo $a['start'] !== '' ? ' · ' . e($a['start'] . '–' . $a['end']) : ''; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
