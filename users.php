<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK

// SADECE SUPER_ADMIN GİREBİLİR!
if ($_SESSION['role'] !== 'SUPER_ADMIN') {
    die("<h2 style='color:red; text-align:center; margin-top:50px;'>ACCESS DENIED! You do not have permission to view this page.</h2>");
}

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

// --- KULLANICI SİLME / PASİFE ALMA İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    $isLastAdmin = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'SUPER_ADMIN' AND status = 1")->fetchColumn() <= 1
        && $conn->query("SELECT role FROM users WHERE user_id = " . $del_id)->fetchColumn() === 'SUPER_ADMIN';
    if ($isLastAdmin) {
        $_SESSION['error_msg'] = "This is the last active Super Admin. Create another Super Admin before deleting this one.";
    } elseif ($del_id !== (int)$_SESSION['user_id']) {
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([$del_id]);
        if ($stmt->rowCount() > 0) $_SESSION['success_msg'] = "User deleted successfully.";
        else $_SESSION['error_msg'] = "User not found.";
    } else {
        $_SESSION['error_msg'] = "You cannot delete your own account!";
    }
    header("Location: users.php");
    exit;
}
if (isset($_SESSION['success_msg'])) { $success_message = $_SESSION['success_msg']; unset($_SESSION['success_msg']); }
if (isset($_SESSION['error_msg'])) { $error_message = $_SESSION['error_msg']; unset($_SESSION['error_msg']); }

// --- YENİ KULLANICI EKLEME ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role = in_array($_POST['role'] ?? '', FR_ROLES, true) ? $_POST['role'] : '';
    $assigned_categories = array_map('intval', (array)($_POST['categories'] ?? []));

    if ($role === '') {
        $error_message = "Please choose a valid role.";
    } elseif (mb_strlen($password) < 8) {
        $error_message = "The password must be at least 8 characters.";
    } elseif (!preg_match('/^[A-Za-z0-9._@-]{3,50}$/', $username)) {
        $error_message = "Username: 3–50 characters, letters, numbers and . _ @ - only.";
    } elseif (!empty($full_name) && !empty($username) && !empty($password)) {
        try {
            $conn->beginTransaction();
            
            // Şifreyi şifrele
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$full_name, $username, $hashed_password, $role]);
            $new_user_id = $conn->lastInsertId();

            // Eğer Rol CATEGORY_SALES ise, seçilen kategorileri user_categories tablosuna ekle
            if ($role === 'CATEGORY_SALES' && count($assigned_categories) > 0) {
                $stmtCat = $conn->prepare("INSERT INTO user_categories (user_id, category_id) VALUES (?, ?)");
                foreach ($assigned_categories as $cat_id) {
                    $stmtCat->execute([$new_user_id, $cat_id]);
                }
            }
            
            $conn->commit();
            $success_message = "User account created successfully.";
        } catch (PDOException $e) {
            $conn->rollBack();
            if ($e->getCode() == 23000) {
                $error_message = "This username is already taken. Please choose another one.";
            } else {
                $error_message = "Error: " . $e->getMessage();
            }
        }
    } else {
        $error_message = "Please fill all required fields.";
    }
}

// Kullanıcıları Çek
$stmt = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// KATEGORİLERİ ÇEK VE HİYERARŞİK DÜZLEŞTİR (FLAT TREE)
// ==========================================
$stmtCat = $conn->query("SELECT * FROM categories ORDER BY parent_category_id ASC, category_name ASC");
$all_cats = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

$cats_by_parent = [];
foreach ($all_cats as $c) {
    $cats_by_parent[$c['parent_category_id']][] = $c;
}

$flat_tree = [];
function buildFlatTree($cats_by_parent, $parent_id = 0, $level = 0) {
    global $flat_tree;
    if (!isset($cats_by_parent[$parent_id])) return;
    foreach ($cats_by_parent[$parent_id] as $cat) {
        $cat['level'] = $level;
        $flat_tree[] = $cat;
        buildFlatTree($cats_by_parent, $cat['category_id'], $level + 1);
    }
}
buildFlatTree($cats_by_parent, 0, 0);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>User Management & Roles</h2>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="row">
        <!-- Kullanıcı Ekleme Formu -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title mb-0">Create New User</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="userForm">
                        <div class="mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-control" placeholder="e.g. John Doe" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" placeholder="johndoe" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role *</label>
                            <select name="role" id="roleSelect" class="form-select" required>
                                <option value="SUPER_ADMIN">Super Admin (Full Access)</option>
                                <option value="ARCHITECT">Architect</option>
                                <option value="SALES">Sales (All Products)</option>
                                <option value="CATEGORY_SALES">Category Sales (Restricted)</option>
                            </select>
                        </div>
                        
                        <!-- YENİ ALTERNATİF: Hiyerarşik Checkbox Listesi -->
                        <div class="mb-4" id="categorySelectionDiv" style="display: none;">
                            <label class="form-label fw-bold text-danger">Assign Categories *</label>
                            <div class="border rounded p-3 bg-light shadow-inner" style="max-height: 250px; overflow-y: auto; border-color: var(--fr-line-2) !important;">
                                <?php if(count($flat_tree) > 0): ?>
                                    <?php foreach($flat_tree as $cat): ?>
                                        <div class="form-check mb-1" style="margin-left: <?php echo $cat['level'] * 20; ?>px;">
                                            <input class="form-check-input category-checkbox" type="checkbox" name="categories[]" value="<?php echo e($cat['category_id']); ?>" id="cat_<?php echo e($cat['category_id']); ?>">
                                            <label class="form-check-label <?php echo $cat['level'] == 0 ? 'fw-bold text-dark' : 'text-secondary'; ?>" for="cat_<?php echo e($cat['category_id']); ?>">
                                                <?php echo ($cat['level'] > 0 ? '<i class="fa-solid fa-turn-up fa-rotate-90 me-1" style="font-size: 0.7rem; opacity:0.5;"></i>' : '') . htmlspecialchars($cat['category_name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-muted small">No categories found in the system.</div>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted d-block mt-2">Check the boxes of the categories this user should have access to.</small>
                        </div>

                        <button type="submit" name="add_user" class="btn btn-primary w-100">
                            <i class="fa-solid fa-user-plus me-2"></i> Create User
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sistem Kullanıcıları Listesi -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>User Info</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): 
                                $rClass = 'bg-secondary';
                                if($u['role'] == 'SUPER_ADMIN') $rClass = 'bg-danger';
                                if($u['role'] == 'ARCHITECT') $rClass = 'bg-primary';
                                if($u['role'] == 'CATEGORY_SALES') $rClass = 'bg-info text-dark';
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px;">
                                                <i class="fa-solid fa-user text-secondary"></i>
                                            </div>
                                            <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                        </div>
                                    </td>
                                    <td><span class="text-muted">@<?php echo htmlspecialchars($u['username']); ?></span></td>
                                    <td><span class="badge <?php echo $rClass; ?>"><?php echo str_replace('_', ' ', $u['role']); ?></span></td>
                                    <td>
                                        <?php if($u['status'] == 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Suspended</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                    	<a href="edit_user.php?id=<?php echo e($u['user_id']); ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-edit"></i></a>
                                        <?php if((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                            <input type="hidden" name="delete_id" value="<?php echo e($u['user_id']); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Account"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
$(document).ready(function() {
    // Rol Seçimine Göre Kategorileri Aç/Kapa
    $('#roleSelect').on('change', function() {
        if ($(this).val() === 'CATEGORY_SALES') {
            $('#categorySelectionDiv').slideDown();
        } else {
            $('#categorySelectionDiv').slideUp();
            $('.category-checkbox').prop('checked', false); // Kapatırken işaretleri kaldır
        }
    });

    // Form Gönderilirken Doğrulama (En az 1 kategori seçilmeli)
    $('#userForm').on('submit', function(e) {
        if ($('#roleSelect').val() === 'CATEGORY_SALES') {
            if ($('.category-checkbox:checked').length === 0) {
                e.preventDefault(); // Formun gitmesini engelle
                alert('Please select at least one category for the Category Sales role.');
            }
        }
    });
});
</script>
<?php include 'includes/footer.php'; ?>