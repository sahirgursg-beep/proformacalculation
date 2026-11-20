<?php
/**
 * Günlük yedek (cPanel Cron Jobs için). Sadece komut satırından çalışır:
 *   php /home/KULLANICI/public_html/KLASOR/cron_backup.php
 * Otomatik yedek ayarı kapalı olsa da çalışır (cron kurduysanız istiyorsunuz demektir).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['SCRIPT_NAME'] = 'cron_backup.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/BackupService.php';
try {
    $bk = new BackupService((new Database())->getConnection());
    $r = $bk->createDb('auto');
    $removed = $bk->prune();
    echo date('c') . " backup ok: {$r['name']} ({$r['rows']} rows, " . BackupService::size($r['size']) . "), old removed: $removed\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' backup FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
