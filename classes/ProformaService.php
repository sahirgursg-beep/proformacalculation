<?php
require_once __DIR__ . '/CostEngine.php';

/**
 * Proforma kaydetme ve doğrulama (add_proforma ve edit_proforma ortak kullanır).
 * - Fiyatlar sunucuda yeniden kontrol edilir: taban fiyatın altındaki satırlar reddedilir.
 * - Tüm kayıt tek transaction içinde yapılır.
 */
class ProformaService {
    const STATUSES = ['DRAFT', 'REVIEW', 'CONFIRMED', 'CANCELLED'];

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function currentNgnRate() {
        return (float)$this->db->query("SELECT sale_rate FROM rate_exchange_history WHERE currency_code = 'NGN' ORDER BY created_at DESC, id DESC LIMIT 1")->fetchColumn();
    }

    /** Formdan gelen veriyi temizler. Hata varsa ['errors'=>[...]] döner. */
    public function parse(array $post) {
        $d = [
            'customer_id'   => (int)($post['customer_id'] ?? 0),
            'project_id'    => !empty($post['project_id']) ? (int)$post['project_id'] : null,
            'proforma_name' => trim((string)($post['proforma_name'] ?? '')),
            'proforma_date' => (string)($post['proforma_date'] ?? ''),
            'currency'      => ($post['currency'] ?? 'USD') === 'NGN' ? 'NGN' : 'USD',
            'discount_rate' => max(0, min(100, (float)($post['discount_rate'] ?? 0))),
            'status'        => in_array($post['status'] ?? '', self::STATUSES, true) ? $post['status'] : 'DRAFT',
            'notes'         => (string)($post['notes'] ?? ''),
            'items'         => [],
            'services'      => [],
        ];
        $errors = [];

        $dt = DateTime::createFromFormat('Y-m-d', $d['proforma_date']);
        if (!$dt || $dt->format('Y-m-d') !== $d['proforma_date']) $errors[] = 'Please enter a valid date.';
        if ($d['customer_id'] <= 0) $errors[] = 'Please select a customer.';

        $products = (array)($post['products'] ?? []);
        $qtys     = (array)($post['quantities'] ?? []);
        $prices   = (array)($post['unit_prices'] ?? []);
        foreach ($products as $i => $pid) {
            $pid = (int)$pid; $qty = (float)($qtys[$i] ?? 0); $price = (float)($prices[$i] ?? 0);
            if ($pid > 0 && $qty > 0) $d['items'][] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $price];
        }
        if (!$d['items']) $errors[] = 'Please add at least one product.';

        $services = (array)($post['services'] ?? []);
        $amounts  = (array)($post['service_amounts'] ?? []);
        foreach ($services as $i => $sid) {
            $sid = (int)$sid; $amt = (float)($amounts[$i] ?? 0);
            if ($sid > 0 && $amt > 0) $d['services'][] = ['service_id' => $sid, 'amount' => $amt];
        }

        if ($errors) $d['errors'] = $errors;
        return $d;
    }

    /** Taban fiyat kontrolü. Altında kalan satırlar için hata mesajı listesi döner. */
    public function validatePrices(array $d, $ngn_rate) {
        $engine = new CostEngine($this->db);
        $engine->preload(array_column($d['items'], 'product_id'));

        $names = [];
        $ids = array_unique(array_column($d['items'], 'product_id'));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $this->db->prepare("SELECT product_id, stock_code FROM products WHERE product_id IN ($in)");
            $st->execute(array_values($ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $names[$r['product_id']] = $r['stock_code'];
        }

        $errors = [];
        foreach ($d['items'] as $it) {
            if (!isset($names[$it['product_id']])) { $errors[] = "Product #{$it['product_id']} does not exist."; continue; }
            $q = $engine->quote($it['product_id'], 0, $d['currency'], $ngn_rate);
            // Küçük yuvarlama farklarına tolerans
            if ($it['unit_price'] + 0.005 < $q['min_price']) {
                $errors[] = sprintf('%s: price %s is below the minimum price %s.',
                    $names[$it['product_id']],
                    number_format($it['unit_price'], 2),
                    number_format($q['min_price'], 2) . ' ' . $d['currency']);
            }
        }
        return $errors;
    }

    /**
     * Form için satırları hazırlar: ürün kodu/adı ve güncel taban fiyat eklenir.
     * Döner: [items, services]
     */
    public function formRows(array $d) {
        $items = $d['items'] ?? [];
        $ids = array_values(array_unique(array_map('intval', array_column($items, 'product_id'))));
        $info = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $this->db->prepare("SELECT product_id, stock_code, stock_name FROM products WHERE product_id IN ($in)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $info[(int)$r['product_id']] = $r;
        }
        $currency = ($d['currency'] ?? 'USD') === 'NGN' ? 'NGN' : 'USD';
        $rate = $currency === 'NGN' ? $this->currentNgnRate() : 1;
        $engine = new CostEngine($this->db);
        $engine->preload($ids);
        $rows = [];
        foreach ($items as $it) {
            $pid = (int)$it['product_id'];
            $rows[] = [
                'product_id' => $pid,
                'stock_code' => $info[$pid]['stock_code'] ?? ('#' . $pid),
                'stock_name' => $info[$pid]['stock_name'] ?? 'Deleted product',
                'quantity'   => (float)$it['quantity'],
                'unit_price' => (float)$it['unit_price'],
                'min_price'  => isset($info[$pid]) ? $engine->quote($pid, 0, $currency, $rate)['min_price'] : 0,
            ];
        }
        $services = array_map(fn($s) => ['service_id' => (int)$s['service_id'], 'amount' => (float)($s['amount'] ?? 0)], $d['services'] ?? []);
        return [$rows, $services];
    }

    /** Yeni proforma oluşturur veya mevcut olanı günceller. Proforma ID döner. */
    public function save(array $d, $proforma_id = null) {
        $ngn_rate = $d['currency'] === 'NGN' ? $this->currentNgnRate() : 1.0;
        if ($d['currency'] === 'NGN' && $ngn_rate <= 0) throw new RuntimeException('NGN exchange rate is not set. Please add an exchange rate first.');

        $errors = $this->validatePrices($d, $ngn_rate);
        if ($errors) throw new InvalidArgumentException(implode("\n", $errors));

        $subtotal = 0; $services_total = 0;
        foreach ($d['items'] as $it) $subtotal += $it['quantity'] * $it['unit_price'];
        foreach ($d['services'] as $s) $services_total += $s['amount'];
        $grand_total = $subtotal + $services_total;

        $this->db->beginTransaction();
        try {
            if ($proforma_id) {
                $this->db->prepare("UPDATE proformas SET customer_id=?, project_id=?, proforma_name=?, proforma_date=?, currency=?, ngn_rate=?, discount_rate=?, status=?, notes=?, subtotal=?, services_total=?, grand_total=? WHERE proforma_id=?")
                    ->execute([$d['customer_id'], $d['project_id'], $d['proforma_name'], $d['proforma_date'], $d['currency'], $ngn_rate, $d['discount_rate'], $d['status'], $d['notes'], $subtotal, $services_total, $grand_total, $proforma_id]);
                $this->db->prepare("DELETE FROM proforma_items WHERE proforma_id = ?")->execute([$proforma_id]);
                $this->db->prepare("DELETE FROM proforma_services WHERE proforma_id = ?")->execute([$proforma_id]);
            } else {
                $proforma_id = $this->insertWithNumber($d, $ngn_rate, $subtotal, $services_total, $grand_total);
            }

            $stItem = $this->db->prepare("INSERT INTO proforma_items (proforma_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)");
            foreach ($d['items'] as $it) {
                $stItem->execute([$proforma_id, $it['product_id'], $it['quantity'], $it['unit_price'], $it['quantity'] * $it['unit_price']]);
            }
            $stSrv = $this->db->prepare("INSERT INTO proforma_services (proforma_id, service_id, service_amount) VALUES (?, ?, ?)");
            foreach ($d['services'] as $s) $stSrv->execute([$proforma_id, $s['service_id'], $s['amount']]);

            $this->db->commit();
            return (int)$proforma_id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** PRF-YYYYMM-0001 numarası üretir; aynı anda kayıt olursa (UNIQUE çakışması) tekrar dener. */
    private function insertWithNumber(array $d, $ngn_rate, $subtotal, $services_total, $grand_total) {
        $prefix = 'PRF-' . date('Ym', strtotime($d['proforma_date'])) . '-';
        $stLast = $this->db->prepare("SELECT proforma_no FROM proformas WHERE proforma_no LIKE ? ORDER BY proforma_no DESC LIMIT 1");
        $stIns = $this->db->prepare("INSERT INTO proformas (proforma_no, proforma_name, customer_id, project_id, proforma_date, currency, ngn_rate, discount_rate, status, notes, subtotal, services_total, grand_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $stLast->execute([$prefix . '%']);
            $last = $stLast->fetchColumn();
            $seq = ($last ? (int)substr($last, strlen($prefix)) : 0) + 1 + $attempt;
            $no = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
            try {
                $stIns->execute([$no, $d['proforma_name'], $d['customer_id'], $d['project_id'], $d['proforma_date'], $d['currency'], $ngn_rate, $d['discount_rate'], $d['status'], $d['notes'], $subtotal, $services_total, $grand_total]);
                return (int)$this->db->lastInsertId();
            } catch (PDOException $e) {
                // 23000 = UNIQUE ihlali → numara alınmış, tekrar dene
                if ($e->getCode() != 23000 && stripos($e->getMessage(), 'UNIQUE') === false) throw $e;
            }
        }
        throw new RuntimeException('Could not generate a unique proforma number. Please try again.');
    }
}
