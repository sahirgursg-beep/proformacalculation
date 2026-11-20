<?php
require_once __DIR__ . '/includes/auth.php';
if ($_SESSION['role'] !== 'SUPER_ADMIN') { die("Access Denied."); }

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Güncelleme İşlemi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    $full_name = trim($_POST['full_name']);
    $role = in_array($_POST['role'] ?? '', FR_ROLES, true) ? $_POST['role'] : '';
    $assigned_categories = array_map('intval', (array)($_POST['categories'] ?? []));

    $stCur = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
    $stCur->execute([$user_id]);
    $currentRole = $stCur->fetchColumn();
    $admins = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'SUPER_ADMIN' AND status = 1")->fetchColumn();
    $edit_error = '';
    if ($role === '') $edit_error = 'Please choose a valid role.';
    elseif ($currentRole === 'SUPER_ADMIN' && $role !== 'SUPER_ADMIN' && $admins <= 1) $edit_error = 'This is the last Super Admin. Make another user Super Admin first.';
    elseif ($user_id === (int)$_SESSION['user_id'] && $role !== 'SUPER_ADMIN') $edit_error = 'You cannot remove your own Super Admin role. Ask another Super Admin.';
    elseif (!empty($_POST['password']) && mb_strlen($_POST['password']) < 8) $edit_error = 'The password must be at least 8 characters.';
    if ($edit_error) {
        $_SESSION['error_msg'] = $edit_error;
        header("Location: edit_user.php?id=" . $user_id);
        exit;
    }

    $sql = "UPDATE users SET full_name = ?, role = ? WHERE user_id = ?";
    $params = [$full_name, $role, $user_id];
    
    // Şifre güncellenecekse
    if (!empty($_POST['password'])) {
        $sql = "UPDATE users SET full_name = ?, role = ?, password_hash = ? WHERE user_id = ?";
        $params = [$full_name, $role, password_hash($_POST['password'], PASSWORD_DEFAULT), $user_id];
    }

    $conn->beginTransaction();
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    // Kategori yetkilerini sıfırla ve yeniden ekle
    $conn->prepare("DELETE FROM user_categories WHERE user_id = ?")->execute([$user_id]);
    if ($role === 'CATEGORY_SALES') {
        $stmtCat = $conn->prepare("INSERT INTO user_categories (user_id, category_id) VALUES (?, ?)");
        foreach ($assigned_categories as $cat_id) {
            $stmtCat->execute([$user_id, $cat_id]);
        }
    }
    $conn->commit();
    header("Location: users.php?success=1");
    exit;
}

// Kullanıcıyı Çek
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location: users.php"); exit; }

// Yetkili kategorileri çek
$stmtCats = $conn->prepare("SELECT category_id FROM user_categories WHERE user_id = ?");
$stmtCats->execute([$user_id]);
$current_cats = $stmtCats->fetchAll(PDO::FETCH_COLUMN);

$categories = $conn->query("SELECT * FROM categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <?php if (!empty($_SESSION['error_msg'])): ?><div class="alert alert-danger"><?php echo e($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div><?php endif; ?>
    <h2>Edit User: <?php echo htmlspecialchars($user['full_name']); ?></h2>
    <div class="card shadow-sm mt-3" style="max-width: 500px;">
        <div class="card-body">
            <form method="POST">
                <div class="mb-3"><label class="form-label">Full Name</label><input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required></div>
                <div class="mb-3"><label class="form-label">New Password (Leave blank to keep current)</label><input type="password" name="password" class="form-control"></div>
                <div class="mb-3">
                    <label class="form-label">Role</label>
                    <select name="role" id="roleSelect" class="form-select">
                        <option value="SUPER_ADMIN" <?php echo $user['role']=='SUPER_ADMIN'?'selected':''; ?>>Super Admin</option>
                        <option value="ARCHITECT" <?php echo $user['role']=='ARCHITECT'?'selected':''; ?>>Architect</option>
                        <option value="SALES" <?php echo $user['role']=='SALES'?'selected':''; ?>>Sales</option>
                        <option value="CATEGORY_SALES" <?php echo $user['role']=='CATEGORY_SALES'?'selected':''; ?>>Category Sales</option>
                    </select>
                </div>
                <div class="mb-3" id="categoryDiv" style="display:<?php echo $user['role']=='CATEGORY_SALES'?'block':'none'; ?>;">
                    <label class="form-label text-danger">Assigned Categories</label>
                    <select name="categories[]" class="form-select" id="catSelect" multiple>
                        <?php foreach($categories as $c): ?>
                            <option value="<?php echo e($c['category_id']); ?>" <?php echo in_array($c['category_id'], $current_cats)?'selected':''; ?>><?php echo e($c['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="update_user" class="btn btn-primary w-100">Update User</button>
            </form>
        </div>
    </div>
</main>
<script>
    $('#roleSelect').on('change', function(){
        if($(this).val() == 'CATEGORY_SALES') $('#categoryDiv').show();
        else $('#categoryDiv').hide();
    });
</script>
<?php include 'includes/footer.php'; ?>