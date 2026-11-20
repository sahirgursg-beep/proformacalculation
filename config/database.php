<?php
/**
 * Veritabanı bağlantısı.
 * Bağlantı bilgileri config/db_config.php dosyasından okunur (bu dosya paylaşılmamalı).
 * Örnek için config/db_config.example.php dosyasına bakın.
 */
class Database {
    public $conn = null;
    private static $shared = null;

    public function getConnection() {
        // Aynı istek içinde tek bağlantı kullan
        if (self::$shared instanceof PDO) {
            return $this->conn = self::$shared;
        }

        $cfg = app_config();

        $cls = !empty($cfg['profile']) ? 'FrProfiledPDO' : 'PDO';
        try {
            if (($cfg['driver'] ?? 'mysql') === 'sqlite') {
                // Yalnızca yerel test ortamı içindir
                $pdo = new $cls('sqlite:' . $cfg['path']);
                $pdo->exec('PRAGMA journal_mode = WAL;');
                $pdo->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'), 0);
                $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
            } else {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $cfg['host'], $cfg['port'] ?? 3306, $cfg['database']);
                $pdo = new $cls($dsn, $cfg['username'], $cfg['password']);
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if (!empty($cfg['profile'])) {
                // Geliştirme: sayfa başına sorgu sayısı ve süre log'a yazılır
                $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['FrProfiledStatement']);
                register_shutdown_function(function () {
                    error_log(sprintf('[profile] %s queries=%d time=%dms', $_SERVER['REQUEST_URI'] ?? 'cli',
                        FrProfiledStatement::$count, (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000));
                });
            }
        } catch (PDOException $e) {
            error_log('DB connection error: ' . $e->getMessage());
            http_response_code(500);
            die('Database connection error. Please contact the administrator.');
        }

        self::$shared = $pdo;
        return $this->conn = $pdo;
    }
}

class FrProfiledPDO extends PDO {
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        FrProfiledStatement::$count++;
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$args);
    }
    public function exec(string $statement): int|false { FrProfiledStatement::$count++; return parent::exec($statement); }
}
class FrProfiledStatement extends PDOStatement {
    public static $count = 0;
    protected function __construct() {}
    public function execute(?array $params = null): bool { self::$count++; return parent::execute($params); }
}

/** config/db_config.php dosyasını bir kez okur */
function app_config() {
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/db_config.php';
        if (!file_exists($file)) {
            http_response_code(500);
            die('Configuration missing: copy config/db_config.example.php to config/db_config.php and fill in the database credentials.');
        }
        $cfg = require $file;
    }
    return $cfg;
}

// Hata gösterimi: sadece debug açıkken ekrana bas, aksi halde log'a yaz
if (!empty(app_config()['debug'])) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}
