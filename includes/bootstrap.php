<?php
/**
 * Ortak başlangıç dosyası: oturum, güvenlik, yetki ve yardımcı fonksiyonlar.
 * includes/auth.php bu dosyayı çağırır; tüm korumalı sayfalar auth.php ile başlar.
 */
if (defined('FR_BOOTSTRAPPED')) return;
define('FR_BOOTSTRAPPED', true);
define('FR_VERSION', '2.6');   // her pakette artırılır; alt bilgide görünür

require_once __DIR__ . '/../config/database.php';

/* ---------- Oturum ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('FRCALCSESS');
    session_start();
}

/* ---------- Güvenlik başlıkları ---------- */
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/* ---------- Yardımcılar ---------- */

/** HTML çıktısı için güvenli kaçış */
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function current_role() { return $_SESSION['role'] ?? ''; }
function is_admin() { return current_role() === 'SUPER_ADMIN'; }

/** Belirtilen rollerden biri değilse 403 */
function require_role(array $roles) {
    if (!in_array(current_role(), $roles, true)) {
        http_response_code(403);
        if (is_ajax_request()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied']);
        } else {
            echo '<!DOCTYPE html><meta charset="utf-8"><title>Access denied</title>'
               . '<div style="font-family:system-ui;max-width:420px;margin:15vh auto;text-align:center">'
               . '<h2>Access denied</h2><p>You do not have permission to open this page.</p>'
               . '<a href="index.php">Back to dashboard</a></div>';
        }
        exit;
    }
}

function is_ajax_request() {
    return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || preg_match('/^(api_|get_)/', basename($_SERVER['SCRIPT_NAME'] ?? ''));
}

/* ---------- CSRF ---------- */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}
function csrf_verify() {
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        die('<!DOCTYPE html><meta charset="utf-8"><div style="font-family:system-ui;max-width:420px;margin:15vh auto;text-align:center">'
          . '<h2>Session expired</h2><p>The form has expired for security reasons. Please go back, refresh the page and try again.</p>'
          . '<a href="javascript:history.back()">Go back</a></div>');
    }
}

/**
 * Tüm POST formlarına CSRF alanını otomatik ekler (her formu tek tek düzenlemeye gerek kalmaz).
 */
function csrf_autoinject_start() {
    ob_start(function ($html) {
        if (stripos($html, '<form') === false) return $html;
        $field = csrf_field();
        return preg_replace_callback('/<form\b[^>]*>/i', function ($m) use ($field) {
            return preg_match('/method\s*=\s*["\']?post/i', $m[0]) ? $m[0] . $field : $m[0];
        }, $html);
    });
}

/* ---------- Sayfa erişim haritası ---------- */
// Listede olmayan sayfalar: giriş yapmış herkes erişebilir.
const FR_PAGE_ROLES = [
    // Sadece Super Admin
    'products.php' => ['SUPER_ADMIN'], 'add_product.php' => ['SUPER_ADMIN'], 'edit_product.php' => ['SUPER_ADMIN'],
    'recipes.php' => ['SUPER_ADMIN'], 'add_recipe.php' => ['SUPER_ADMIN'], 'edit_recipe.php' => ['SUPER_ADMIN'],
    'import_products.php' => ['SUPER_ADMIN'], 'import_recipes.php' => ['SUPER_ADMIN'],
    'export_products.php' => ['SUPER_ADMIN'], 'export_recipes.php' => ['SUPER_ADMIN'],
    'cost_debugger.php' => ['SUPER_ADMIN'], 'kurulum.php' => ['SUPER_ADMIN'], 'import.php' => ['SUPER_ADMIN', 'ARCHITECT'], 'backups.php' => ['SUPER_ADMIN'], 'users.php' => ['SUPER_ADMIN'], 'edit_user.php' => ['SUPER_ADMIN'],
    'settings.php' => ['SUPER_ADMIN'], 'exchange_rates.php' => ['SUPER_ADMIN'], 'shipment_settings.php' => ['SUPER_ADMIN'],
    'units.php' => ['SUPER_ADMIN'], 'additional_services.php' => ['SUPER_ADMIN'], 'edit_service.php' => ['SUPER_ADMIN'],
    'customer_types.php' => ['SUPER_ADMIN'], 'edit_customer_type.php' => ['SUPER_ADMIN'],
    // Super Admin + Mimar
    'categories.php' => ['SUPER_ADMIN', 'ARCHITECT'], 'edit_category.php' => ['SUPER_ADMIN', 'ARCHITECT'],
    'wizards.php' => ['SUPER_ADMIN', 'ARCHITECT'], 'wizard_steps.php' => ['SUPER_ADMIN', 'ARCHITECT'],
    'run_wizard.php' => ['SUPER_ADMIN', 'ARCHITECT'],
];

/* Satış görevlileri yalnızca satış fiyatlarını görür; proforma, müşteri ve proje işleri mimardadır. */
const FR_SALES_ROLES = ['SALES', 'CATEGORY_SALES'];
const FR_ROLES = ['SUPER_ADMIN', 'ARCHITECT', 'SALES', 'CATEGORY_SALES'];
const FR_SALES_PAGES = ['index.php', 'price_lists.php'];

function is_sales_role() { return in_array(current_role(), FR_SALES_ROLES, true); }

/** Giriş + sayfa yetkisi + CSRF kontrolü. auth.php tarafından çağrılır. */
function fr_guard() {
    if (empty($_SESSION['user_id'])) {
        if (is_ajax_request()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Not logged in']);
        } else {
            header('Location: login.php');
        }
        exit;
    }
    // Rol ve hesap durumu her istekte veritabanından okunur:
    // rolü değişen ya da askıya alınan/silinen kullanıcı çıkış yapmayı beklemeden etkilenir.
    try {
        $st = (new Database())->getConnection()->prepare("SELECT role, status, full_name FROM users WHERE user_id = ?");
        $st->execute([(int)$_SESSION['user_id']]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $u = null; }
    if (!$u || (int)$u['status'] !== 1) {
        $_SESSION = [];
        session_destroy();
        if (is_ajax_request()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Signed out']); }
        else header('Location: login.php');
        exit;
    }
    $_SESSION['role'] = $u['role'];
    $_SESSION['full_name'] = $u['full_name'];

    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (is_sales_role() && !in_array($page, FR_SALES_PAGES, true)) {
        require_role([]); // satış rolü: listede olmayan her sayfa kapalı
    }
    if (isset(FR_PAGE_ROLES[$page])) require_role(FR_PAGE_ROLES[$page]);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') csrf_verify();
    csrf_autoinject_start();
}

/* ---------- Varlıklar (CSS/JS) ---------- */
/** Dosya değiştiğinde tarayıcı önbelleğini otomatik yeniler */
function asset($path) {
    $file = __DIR__ . '/../' . $path;
    return e($path . (file_exists($file) ? '?v=' . filemtime($file) : ''));
}

/** Harici kütüphaneler. 'vendor_local' => true ise (internetsiz test) yerel kopyalar kullanılır. */
function vendor_url($name) {
    $local = !empty(app_config()['vendor_local']);
    $map = [
        'bootstrap_css' => [$local ? 'assets/vendor/bootstrap.min.css' : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'],
        'bootstrap_js'  => [$local ? 'assets/vendor/bootstrap.bundle.min.js' : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'],
        'select2_css'   => ['https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'],
        'select2_theme' => ['https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css'],
        'select2_js'    => ['https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'],
    ];
    $u = $map[$name][0];
    return str_starts_with($u, 'http') ? $u : asset($u);
}

/** Proforma durum etiketi */
function status_badge($status) {
    $labels = ['DRAFT' => 'Draft', 'REVIEW' => 'In review', 'CONFIRMED' => 'Confirmed', 'CANCELLED' => 'Cancelled'];
    $s = strtoupper((string)$status);
    return '<span class="fr-status fr-status-' . e(strtolower($s)) . '">' . e($labels[$s] ?? ucfirst(strtolower($s))) . '</span>';
}

/**
 * Güvenli resim yükleme: sadece gerçek JPG/PNG/WEBP/GIF kabul edilir, dosya adı rastgele üretilir.
 * Başarılıysa kaydedilen yolu (ör. uploads/products/abc.jpg), değilse null döner.
 */
function save_uploaded_image(array $file, $dir) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 8 * 1024 * 1024) return null;
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) return null;

    $dir = trim($dir, '/') . '/';
    $abs = __DIR__ . '/../' . $dir;
    if (!is_dir($abs)) mkdir($abs, 0755, true);
    if (!file_exists($abs . '.htaccess')) {
        // Yükleme klasöründe PHP vb. çalıştırılmasın
        file_put_contents($abs . '.htaccess', "RemoveHandler .php .phtml .php5 .php7 .php8\n<FilesMatch \"\\.(php|phtml|php\\d|phar)$\">\n    Require all denied\n</FilesMatch>\n");
    }
    $name = bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
    return move_uploaded_file($file['tmp_name'], $abs . $name) ? $dir . $name : null;
}

/**
 * Verilen kategoriler + tüm alt kategorileri (her derinlikte).
 * Wizard adımları ve kategori bazlı satış yetkisi alt kategorileri de kapsasın diye.
 */
function category_with_descendants(PDO $conn, array $ids) {
    static $children = null;
    if ($children === null) {
        $children = [];
        foreach ($conn->query("SELECT category_id, parent_category_id FROM categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if ($c['parent_category_id'] !== null) $children[(int)$c['parent_category_id']][] = (int)$c['category_id'];
        }
    }
    $out = []; $stack = array_map('intval', $ids);
    while ($stack) {
        $id = array_pop($stack);
        if (isset($out[$id])) continue;   // döngüye karşı koruma
        $out[$id] = true;
        foreach ($children[$id] ?? [] as $ch) $stack[] = $ch;
    }
    return array_keys($out);
}

/* ---------- Giriş denemesi sınırı (kaba kuvvet saldırısına karşı) ---------- */
// Aynı kullanıcı adı + IP için 15 dakikada 5 hatalı deneme → 15 dakika kilit.
function login_throttle_file($username) {
    $dir = __DIR__ . '/../storage/throttle';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!file_exists(__DIR__ . '/../storage/.htaccess')) @file_put_contents(__DIR__ . '/../storage/.htaccess', "Require all denied\n");
    return $dir . '/' . sha1(mb_strtolower(trim($username)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
}
function login_locked_minutes($username) {
    $f = login_throttle_file($username);
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!$d) return 0;
    $recent = array_filter($d, fn($t) => $t > time() - 900);
    return count($recent) >= 5 ? (int)ceil((min($recent) + 900 - time()) / 60) : 0;
}
function login_failed($username) {
    $f = login_throttle_file($username);
    $d = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $d = array_values(array_filter($d, fn($t) => $t > time() - 900));
    $d[] = time();
    @file_put_contents($f, json_encode($d), LOCK_EX);
}
function login_succeeded($username) { @unlink(login_throttle_file($username)); }
