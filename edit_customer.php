<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ

require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($customer_id === 0) { header("Location: customers.php"); exit; }

// --- GÜNCELLEME İŞLEMİ (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_customer'])) {
    $customer_name = trim($_POST['customer_name']);
    $customer_type_id = !empty($_POST['customer_type_id']) ? (int)$_POST['customer_type_id'] : null;
    $contact_person = trim($_POST['contact_person']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);

    if (!empty($customer_name)) {
        $stmt = $conn->prepare("
            UPDATE customers 
            SET customer_name = ?, customer_type_id = ?, contact_person = ?, email = ?, phone = ?, address = ? 
            WHERE customer_id = ?
        ");
        if ($stmt->execute([$customer_name, $customer_type_id, $contact_person, $email, $phone, $address, $customer_id])) {
            $success_message = "Customer updated successfully.";
        } else {
            $error_message = "Failed to update customer.";
        }
    } else {
        $error_message = "Company/Customer Name is required.";
    }
}

// --- MEVCUT MÜŞTERİYİ ÇEKME ---
$stmt = $conn->prepare("SELECT * FROM customers WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$currentCustomer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentCustomer) { header("Location: customers.php"); exit; }

// Tüm Müşteri Tiplerini Çek (Dropdown için)
$typesStmt = $conn->query("SELECT * FROM customer_types ORDER BY type_name ASC");
$customerTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Edit Customer</h2>
        <a href="customers.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Customers</a>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="card shadow-sm" style="max-width: 600px;">
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Company / Customer Name *</label>
                    <input type="text" name="customer_name" class="form-control" value="<?php echo htmlspecialchars($currentCustomer['customer_name']); ?>" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Customer Type</label>
                    <select name="customer_type_id" class="form-select">
                        <option value="">-- Select Type --</option>
                        <?php foreach ($customerTypes as $type): ?>
                            <option value="<?php echo e($type['type_id']); ?>" <?php echo $currentCustomer['customer_type_id'] == $type['type_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['type_name']); ?> 
                                (<?php echo floatval($type['discount_rate']); ?>% Off)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control" value="<?php echo htmlspecialchars($currentCustomer['contact_person']); ?>">
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($currentCustomer['phone']); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($currentCustomer['email']); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Full Address</label>
                    <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($currentCustomer['address']); ?></textarea>
                </div>

                <button type="submit" name="update_customer" class="btn btn-primary w-100 fs-5 py-2">
                    <i class="fa-solid fa-save me-2"></i> Update Customer
                </button>
            </form>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>