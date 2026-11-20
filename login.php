<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Zaten giriş yapılmışsa ana sayfaya yönlendir
if (!empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$conn = (new Database())->getConnection();

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    csrf_verify();
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && ($wait = login_locked_minutes($username)) > 0) {
        $error_message = "Too many failed attempts. Try again in {$wait} minute" . ($wait > 1 ? 's' : '') . ".";
    } elseif (!empty($username) && !empty($password)) {
        // Kullanıcıyı veritabanında bul
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Hesap aktif mi? (status = 1)
            if ($user['status'] == 1) {
                // Şifre doğru mu?
                if (password_verify($password, $user['password_hash'])) {
                    // Oturum sabitleme saldırısına karşı yeni oturum kimliği
                    session_regenerate_id(true);
                    login_succeeded($username);
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    
                    header("Location: index.php");
                    exit;
                } else {
                    $error_message = "Invalid username or password!";
                    login_failed($username);
                    usleep(800000);
                }
            } else {
                $error_message = "Your account has been suspended. Please contact the administrator.";
            }
        } else {
            $error_message = "Invalid username or password!";
            login_failed($username);
            usleep(800000);
        }
    } else {
        $error_message = "Please enter both username and password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · Furniture Republic</title>
    <link rel="stylesheet" href="<?php echo vendor_url('bootstrap_css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/theme.css'); ?>">
</head>
<body>
<div class="fr-login">
    <section class="fr-login-art" aria-hidden="true">
        <div class="fr-login-brand">Furniture <span>Republic</span></div>
        <div>
            <h1>Every piece, priced with care.</h1>
            <p>Costing, price lists and proformas for the Furniture Republic showroom team.</p>
        </div>
        <p class="mb-0" style="font-size:.8rem">Fırat Building Materials Ltd. · Abuja</p>
    </section>

    <section class="fr-login-form">
        <form method="POST" novalidate>
            <?php echo csrf_field(); ?>
            <h2>Sign in</h2>
            <p class="text-muted mb-4">Use the account your administrator gave you.</p>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger" role="alert"><?php echo e($error_message); ?></div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" value="<?php echo e($_POST['username'] ?? ''); ?>" autocomplete="username" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required>
            </div>
            <button type="submit" name="login" value="1" class="btn btn-primary w-100">Sign in</button>
            <p class="small text-muted mt-4 mb-0">Forgot your password? Ask an administrator to reset it in Users &amp; roles.</p>
        </form>
    </section>
</div>
</body>
</html>
