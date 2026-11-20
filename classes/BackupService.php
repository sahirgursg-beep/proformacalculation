<?php
/**
 * BackupService — veritabanı ve resim yedekleri.
 *
 * Yedekler storage/backups/ klasöründe tutulur (.htaccess ile web erişimine kapalı).
 * Dosya adı: frcalc_db_YYYYmmdd_HHMMSS_<tür>_<rastgele>.sql.gz  |  frcalc_images_..._<rastgele>.zip
 * Türler: manual, auto, pre-restore, setup
 */
class BackupService {
    const NAME_RE = '/^frcalc_(db|images)_(\d{8}_\d{6})_(manual|auto|pre-restore|setup|uploaded)_[a-f0-9]{8}\.(sql\.gz|sql|zip)$/';
    const DEFAULTS = ['auto' => true, 'keep_days' => 30, 'keep_min' => 7];

    private $db;
    private $dir;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->dir = realpath(__DIR__ . '/..') . '/storage/backups';
        if (!is_dir($this->dir)) @mkdir($this->dir, 0755, true);
        $root = dirname($this->dir);
        if (!file_exists("$root/.htaccess")) @file_put_contents("$root/.htaccess", "Require all denied\n");
        if (!file_exists("$this->dir/.htaccess")) @file_put_contents("$this->dir/.htaccess", "Require all denied\n");
        if (!file_exists("$this->dir/index.html")) @file_put_contents("$this->dir/index.html", '');
    }

    public function dir() { return $this->dir; }
    private function driver() { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME); }

    /* ---------------- Ayarlar ---------------- */
    public function settings() {
        $f = "$this->dir/settings.json";
        $s = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
        return array_merge(self::DEFAULTS, $s);
    }
    public function saveSettings(array $s) {
        $s = ['auto' => !empty($s['auto']), 'keep_days' => max(3, min(365, (int)($s['keep_days'] ?? 30))), 'keep_min' => max(3, min(100, (int)($s['keep_min'] ?? 7)))];
        file_put_contents("$this->dir/settings.json", json_encode($s, JSON_PRETTY_PRINT), LOCK_EX);
        return $s;
    }

    /* ---------------- Liste ---------------- */
    public function all() {
        $out = [];
        foreach (scandir($this->dir) ?: [] as $f) {
            if (!preg_match(self::NAME_RE, $f, $m)) continue;
            $out[] = ['name' => $f, 'kind' => $m[1], 'trigger' => $m[3], 'size' => filesize("$this->dir/$f"),
                      'time' => DateTime::createFromFormat('Ymd_His', $m[2])->getTimestamp()];
        }
        usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }
    public function path($name) {
        $name = basename((string)$name);
        if (!preg_match(self::NAME_RE, $name) || !is_file("$this->dir/$name")) throw new InvalidArgumentException('Backup not found.');
        return "$this->dir/$name";
    }
    public function lastDbBackup() {
        foreach ($this->all() as $b) if ($b['kind'] === 'db') return $b;
        return null;
    }

    private function newName($kind, $trigger, $ext) {
        return sprintf('frcalc_%s_%s_%s_%s.%s', $kind, date('Ymd_His'), $trigger, bin2hex(random_bytes(4)), $ext);
    }

    /* ---------------- Veritabanı yedeği ---------------- */
    public function createDb($trigger = 'manual') {
        @set_time_limit(600);
        $gz = function_exists('gzopen');
        $name = $this->newName('db', $trigger, $gz ? 'sql.gz' : 'sql');
        $tmp = "$this->dir/.$name.part";
        $fh = $gz ? gzopen($tmp, 'wb6') : fopen($tmp, 'wb');
        $w = $gz ? fn($s) => gzwrite($fh, $s) : fn($s) => fwrite($fh, $s);

        $isMysql = $this->driver() === 'mysql';
        $tables = $isMysql
            ? array_column($this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM), 0)
            : $this->db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

        $w("-- FRCALC database backup\n-- Created: " . date('Y-m-d H:i:s') . " | App version: " . (defined('FR_VERSION') ? FR_VERSION : '?') . " | Driver: " . $this->driver() . " | Tables: " . count($tables) . "\n-- Restore: FR Calculation > Backups > Restore, or phpMyAdmin > Import.\n\n");
        if ($isMysql) $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $rowsTotal = 0;
        if ($isMysql) $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            foreach ($tables as $t) {
                $q = $this->q($t);
                if ($isMysql) { $sc = $this->db->query("SHOW CREATE TABLE $q"); $create = $sc->fetch(PDO::FETCH_NUM)[1]; $sc->closeCursor(); }
                else { $create = $this->db->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . $this->db->quote($t))->fetchColumn(); }
                $w("DROP TABLE IF EXISTS $q;\n$create;\n");
                $st = $this->db->query("SELECT * FROM $q");
                $batch = [];
                while ($row = $st->fetch(PDO::FETCH_NUM)) {
                    $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? $v : $this->db->quote((string)$v)), $row)) . ')';
                    if (count($batch) >= 250) { $w("INSERT INTO $q VALUES\n" . implode(",\n", $batch) . ";\n"); $rowsTotal += count($batch); $batch = []; }
                }
                if ($batch) { $w("INSERT INTO $q VALUES\n" . implode(",\n", $batch) . ";\n"); $rowsTotal += count($batch); }
                $st->closeCursor();
                $w("\n");
            }
        } finally {
            if ($isMysql) $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        if ($isMysql) $w("SET FOREIGN_KEY_CHECKS = 1;\n");
        $w("-- End of backup: $rowsTotal rows\n");
        $gz ? gzclose($fh) : fclose($fh);
        rename($tmp, "$this->dir/$name");
        return ['name' => $name, 'rows' => $rowsTotal, 'tables' => count($tables), 'size' => filesize("$this->dir/$name")];
    }

    /* ---------------- Resim yedeği ---------------- */
    public function createImages($trigger = 'manual') {
        if (!class_exists('ZipArchive')) throw new RuntimeException('The PHP zip extension is not available on this server.');
        @set_time_limit(600);
        $root = realpath(__DIR__ . '/..') . '/uploads';
        $name = $this->newName('images', $trigger, 'zip');
        $zip = new ZipArchive();
        if ($zip->open("$this->dir/$name", ZipArchive::CREATE) !== true) throw new RuntimeException('Could not create the zip file.');
        $n = 0;
        if (is_dir($root)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || !preg_match('/\.(jpe?g|png|webp|gif)$/i', $file->getFilename())) continue;
                $zip->addFile($file->getPathname(), 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)));
                $n++;
            }
        }
        if ($n === 0) $zip->addFromString('uploads/README.txt', 'No images were found when this backup was made.');
        $zip->close();
        return ['name' => $name, 'files' => $n, 'size' => filesize("$this->dir/$name")];
    }

    public function delete($name) { return @unlink($this->path($name)); }

    /* ---------------- Otomatik yedek ve temizlik ---------------- */
    /** Son veritabanı yedeği 24 saatten eskiyse yeni bir 'auto' yedek alır. Aynı anda iki kez çalışmaz. */
    public function autoIfDue() {
        $s = $this->settings();
        if (!$s['auto']) return null;
        $last = $this->lastDbBackup();
        if ($last && $last['time'] > time() - 86400) return null;
        $lock = fopen("$this->dir/.auto.lock", 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return null;
        try {
            $last = $this->lastDbBackup();   // kilitten sonra tekrar kontrol
            if ($last && $last['time'] > time() - 86400) return null;
            $r = $this->createDb('auto');
            $this->prune();
            return $r;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Saklama süresinden eski 'auto' yedekleri siler; en az keep_min adet yedek her zaman kalır. Elle alınanlar silinmez. */
    public function prune() {
        $s = $this->settings();
        $auto = array_values(array_filter($this->all(), fn($b) => $b['kind'] === 'db' && $b['trigger'] === 'auto'));
        $removed = 0;
        foreach ($auto as $i => $b) {
            if ($i < $s['keep_min']) continue;
            if ($b['time'] < time() - $s['keep_days'] * 86400) { @unlink("$this->dir/{$b['name']}"); $removed++; }
        }
        return $removed;
    }

    /* ---------------- Geri yükleme ---------------- */
    /**
     * Bir yedeği geri yükler. Önce mevcut durumun 'pre-restore' yedeği alınır.
     * Döner: ['statements' => n, 'safety' => yedek adı]
     */
    public function restore($file) {
        @set_time_limit(900);
        $sql = $this->readSql($file);
        if (!preg_match('/CREATE TABLE/i', $sql) || !preg_match('/\b(products|proformas)\b/i', $sql)) {
            throw new RuntimeException('This file does not look like an FR Calculation database backup.');
        }
        $safety = $this->createDb('pre-restore');
        $isMysql = $this->driver() === 'mysql';
        $n = 0;
        if ($isMysql) $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($this->splitSql($sql, $isMysql) as $stmt) {
                if (!$isMysql && preg_match('/^\s*(SET |\/\*!|START TRANSACTION|COMMIT|LOCK TABLES|UNLOCK TABLES)/i', $stmt)) continue;
                try { $this->db->exec($stmt); $n++; }
                catch (PDOException $e) {
                    throw new RuntimeException('Restore stopped at statement ' . ($n + 1) . ': ' . $e->getMessage() . '. Your data before the restore is saved in ' . $safety['name'] . '.');
                }
            }
        } finally {
            if ($isMysql) $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        return ['statements' => $n, 'safety' => $safety['name']];
    }

    private function readSql($file) {
        if (str_ends_with($file, '.gz')) {
            $fh = gzopen($file, 'rb'); $sql = '';
            while (!gzeof($fh)) $sql .= gzread($fh, 1 << 20);
            gzclose($fh);
        } else $sql = (string)file_get_contents($file);
        if (strncmp($sql, "\xEF\xBB\xBF", 3) === 0) $sql = substr($sql, 3);
        return $sql;
    }

    /** SQL metnini ifadelere böler; tırnak içindeki ; ve satır sonlarını dikkate alır. */
    public function splitSql($sql, $backslashEscapes = true) {
        $out = []; $buf = ''; $len = strlen($sql); $q = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($q !== null) {
                $buf .= $c;
                if ($backslashEscapes && $c === '\\' && $i + 1 < $len) { $buf .= $sql[++$i]; continue; }
                if ($c === $q) {
                    if ($i + 1 < $len && $sql[$i + 1] === $q) { $buf .= $sql[++$i]; continue; }   // '' kaçışı
                    $q = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $buf .= $c; continue; }
            $isDashComment = $c === '-' && ($sql[$i + 1] ?? '') === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]));
            if ($isDashComment || ($c === '#' && trim($buf) === '')) {   // satır yorumu (-- , --\n, #)
                $nl = strpos($sql, "\n", $i); $i = $nl === false ? $len : $nl; continue;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*' && ($sql[$i + 2] ?? '') !== '!') {   // /* yorum */ (/*! hariç)
                $end = strpos($sql, '*/', $i + 2); $i = $end === false ? $len : $end + 1; continue;
            }
            if ($c === ';') { if (trim($buf) !== '') $out[] = trim($buf); $buf = ''; continue; }
            $buf .= $c;
        }
        if (trim($buf) !== '') $out[] = trim($buf);
        return $out;
    }

    private function q($name) {
        return $this->driver() === 'mysql' ? '`' . str_replace('`', '``', $name) . '`' : '"' . str_replace('"', '""', $name) . '"';
    }

    public static function size($b) {
        foreach (['B', 'KB', 'MB', 'GB'] as $u) { if ($b < 1024) return round($b, $u === 'B' ? 0 : 1) . ' ' . $u; $b /= 1024; }
        return round($b, 1) . ' TB';
    }
}
