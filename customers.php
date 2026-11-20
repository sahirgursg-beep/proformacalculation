<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

// --- SİLME İŞLEMİ ---
// --- SİLME İŞLEMİ (PRG) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    try {
        $stmt = $conn->prepare("DELETE FROM customers WHERE customer_id = ?");
        $stmt->execute([$del_id]);
        if ($stmt->rowCount() > 0) $_SESSION['success_msg'] = "Customer deleted successfully.";
        else $_SESSION['error_msg'] = "Customer not found.";
    } catch (PDOException $e) {
        $_SESSION['error_msg'] = "Cannot delete this customer. They might have active proformas or balances.";
    }
    header("Location: customers.php");
    exit;
}
if (isset($_SESSION['success_msg'])) { $success_message = $_SESSION['success_msg']; unset($_SESSION['success_msg']); }
if (isset($_SESSION['error_msg'])) { $error_message = $_SESSION['error_msg']; unset($_SESSION['error_msg']); }

// --- EKLEME İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_customer'])) {
    $customer_name = trim($_POST['customer_name']);
    $customer_type_id = !empty($_POST['customer_type_id']) ? (int)$_POST['customer_type_id'] : null;
    $contact_person = trim($_POST['contact_person']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);

    if (!empty($customer_name)) {
        $stmt = $conn->prepare("
            INSERT INTO customers (customer_name, customer_type_id, contact_person, email, phone, address) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($stmt->execute([$customer_name, $customer_type_id, $contact_person, $email, $phone, $address])) {
            $success_message = "Customer added successfully.";
        } else {
            $error_message = "Failed to add customer.";
        }
    } else {
        $error_message = "Company/Customer Name is required.";
    }
}

// Tüm Müşteri Tiplerini Çek (Dropdown için)
$typesStmt = $conn->query("SELECT * FROM customer_types ORDER BY type_name ASC");
$customerTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

// Arama Mantığı
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$whereSql = "";
$params = [];
if (!empty($search)) {
    $whereSql = " WHERE c.customer_name LIKE ? OR c.contact_person LIKE ? OR c.phone LIKE ?";
    $searchWildcard = "%$search%";
    $params = [$searchWildcard, $searchWildcard, $searchWildcard];
}

// Müşterileri Çek
$query = "
    SELECT c.*, ct.type_name, ct.discount_rate 
    FROM customers c 
    LEFT JOIN customer_types ct ON c.customer_type_id = ct.type_id 
    $whereSql
    ORDER BY c.created_at DESC
";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Customer Management</h2>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="row">
        <!-- Müşteri Ekleme Formu -->
        <div class="col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title mb-0">Add New Customer</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Company / Customer Name *</label>
                            <input type="text" name="customer_name" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Customer Type</label>
                            <select name="customer_type_id" class="form-select">
                                <option value="">-- Select Type --</option>
                                <?php foreach ($customerTypes as $type): ?>
                                    <option value="<?php echo e($type['type_id']); ?>">
                                        <?php echo htmlspecialchars($type['type_name']); ?> 
                                        (<?php echo floatval($type['discount_rate']); ?>% Off)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control">
                        </div>

                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="form-label">Phone</label>
                                <input type="text" name="phone" class="form-control">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Full Address</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>

                        <button type="submit" name="add_customer" class="btn btn-primary w-100">
                            <i class="fa-solid fa-user-plus me-2"></i> Save Customer
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Müşteri Listesi -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Customer Directory</h5>
                    <form method="GET" class="d-flex">
                        <input type="text" name="search" class="form-control form-control-sm me-2" placeholder="Search name, phone..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-search"></i></button>
                        <?php if($search): ?>
                            <a href="customers.php" class="btn btn-sm btn-outline-danger ms-1" title="Clear"><i class="fa-solid fa-times"></i></a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Customer Info</th>
                                <th>Contact Details</th>
                                <th>Type & Discount</th>
                               
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($customers) > 0): ?>
                                <?php foreach ($customers as $c): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($c['customer_name']); ?></strong><br>
                                            <small class="text-muted"><i class="fa-solid fa-user text-secondary"></i> <?php echo htmlspecialchars($c['contact_person'] ?: 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <small><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($c['phone'] ?: '-'); ?></small><br>
                                            <small><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($c['email'] ?: '-'); ?></small>
                                        </td>
                                        <td>
                                            <?php if($c['type_name']): ?>
                                                <span class="badge bg-info text-dark"><?php echo htmlspecialchars($c['type_name']); ?></span><br>
                                                <small class="text-success"><?php echo floatval($c['discount_rate']); ?>% Off</small>
                                            <?php else: ?>
                                                <span class="text-muted small">Standard</span>
                                            <?php endif; ?>
                                        </td>

                                      <td class="text-end">
    <a href="customer_details.php?id=<?php echo e($c['customer_id']); ?>" class="btn btn-sm btn-outline-info" title="View Details"><i class="fa-solid fa-eye"></i></a>
    <a href="edit_customer.php?id=<?php echo e($c['customer_id']); ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-edit"></i></a>
    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this customer?');">
    <input type="hidden" name="delete_id" value="<?php echo e($c['customer_id']); ?>">
    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
</form>
    </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No customers found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>