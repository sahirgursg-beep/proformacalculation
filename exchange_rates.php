<?php
require_once __DIR__ . '/includes/auth.php'; // GÜVENLİK VE OTURUM KONTROLÜ
require_once __DIR__ . '/config/database.php';
$dbClass = new Database();
$conn = $dbClass->getConnection();

// Yeni Kur Ekleme İşlemi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_rate'])) {
    $currency_code = trim($_POST['currency_code']);
    $buy_rate = (float)$_POST['buy_rate'];
    $sale_rate = (float)$_POST['sale_rate'];

    if (!empty($currency_code) && $buy_rate > 0) {
        $stmt = $conn->prepare("INSERT INTO rate_exchange_history (currency_code, buy_rate, sale_rate) VALUES (?, ?, ?)");
        if ($stmt->execute([$currency_code, $buy_rate, $sale_rate])) {
            $success_message = "Exchange rate updated successfully.";
        } else {
            $error_message = "Failed to update exchange rate.";
        }
    } else {
        $error_message = "Please fill in all fields correctly.";
    }
}

// En Güncel (Aktif) Kurları Çekme (Her para birimi için son eklenen kayıt)
$latestRatesQuery = "
    SELECT r1.* 
    FROM rate_exchange_history r1
    INNER JOIN (
        SELECT currency_code, MAX(created_at) as max_date 
        FROM rate_exchange_history 
        GROUP BY currency_code
    ) r2 ON r1.currency_code = r2.currency_code AND r1.created_at = r2.max_date
    ORDER BY r1.currency_code ASC
";
$stmtLatest = $conn->prepare($latestRatesQuery);
$stmtLatest->execute();
$latestRates = $stmtLatest->fetchAll();

// Son 10 Kur Geçmişini Çekme
$historyQuery = "SELECT * FROM rate_exchange_history ORDER BY created_at DESC LIMIT 10";
$stmtHistory = $conn->prepare($historyQuery);
$stmtHistory->execute();
$rateHistory = $stmtHistory->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Exchange Rates Management</h2>
    </div>

    <?php if (isset($success_message)) echo "<div class='alert alert-success'>" . e($success_message) . "</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='alert alert-danger'>" . e($error_message) . "</div>"; ?>

    <div class="row">
        <!-- Kur Güncelleme Formu -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title mb-0">Update Currency Rate</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Currency Code</label>
                            <select name="currency_code" class="form-select" required>
                                <option value="NGN">NGN - Nigerian Naira</option>
                                <option value="TRY">TRY - Turkish Lira</option>
                                <option value="EUR">EUR - Euro</option>
                                <option value="GBP">GBP - British Pound</option>
                            </select>
                            <small class="text-muted">Base currency is assumed as 1 USD.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Buy Rate (Against 1 USD) *</label>
                            <input type="number" step="0.0001" name="buy_rate" class="form-control" placeholder="e.g., 1550.50" required>
                            <small class="text-muted">Used for purchasing & cost calculations.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sale Rate (Against 1 USD) *</label>
                            <input type="number" step="0.0001" name="sale_rate" class="form-control" placeholder="e.g., 1600.00" required>
                            <small class="text-muted">Used for sales & proformas.</small>
                        </div>
                        <button type="submit" name="add_rate" class="btn btn-primary w-100">
                            <i class="fa-solid fa-sync"></i> Update Rate
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <!-- Güncel Aktif Kurlar -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">Current Active Rates (1 USD =)</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Currency</th>
                                <th>Buy Rate</th>
                                <th>Sale Rate</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($latestRates) > 0): ?>
                                <?php foreach ($latestRates as $rate): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($rate['currency_code']); ?></strong></td>
                                        <td class="text-success"><?php echo number_format($rate['buy_rate'], 4); ?></td>
                                        <td class="text-primary"><?php echo number_format($rate['sale_rate'], 4); ?></td>
                                        <td><?php echo date('d M Y, H:i', strtotime($rate['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted">No active rates found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Geçmiş Kur Hareketleri (History) -->
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="card-title mb-0 text-muted">Recent Rate History</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0" style="font-size: 0.9em;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Currency</th>
                                <th>Buy</th>
                                <th>Sale</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rateHistory as $hist): ?>
                                <tr>
                                    <td><?php echo date('d M Y, H:i', strtotime($hist['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($hist['currency_code']); ?></td>
                                    <td><?php echo number_format($hist['buy_rate'], 4); ?></td>
                                    <td><?php echo number_format($hist['sale_rate'], 4); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>