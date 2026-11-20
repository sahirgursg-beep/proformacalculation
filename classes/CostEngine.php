<?php
/**
 * CostEngine — ürün maliyet ve fiyat hesaplama motoru.
 *
 * Formül eski sürümle birebir aynıdır. Farklar:
 *  - Veriler toplu (katman katman) yüklenir, her ürün istek başına bir kez hesaplanır (önbellek).
 *  - Döngüsel reçetelerde sonsuz döngü yerine uyarı verilir.
 *  - Ayrıntılı log yalnızca enableLog(true) ile (Cost Debugger) üretilir.
 *  - Sonuca 'min_price' eklendi: satış fiyatı + KDV (taban fiyat, KDV dahil).
 */
class CostEngine {
    private $db;
    private $settings;
    private $ngn_rate;
    private $air_settings;
    private $sea_settings = [];

    // Önbellekler
    private $products = [];      // product_id => satır (recipe_id dahil)
    private $items = [];         // recipe_id  => [kalemler]
    private $conversions = [];   // product_id => [unit_id => factor]
    private $packages = [];      // product_id => [paketler]
    private $missing = [];       // veritabanında bulunmayan ürünler
    private $memo = [];          // product_id => hesap sonucu
    private $stack = [];         // döngü tespiti

    public $calculationLog = [];
    private $indentLevel = 0;
    private $logEnabled = false;

    public function __construct($db) {
        $this->db = $db;

        $stmt = $this->db->query("SELECT safe_rate, list_markup, vat_rate, waste_rate FROM system_settings WHERE setting_id = 1");
        $this->settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['safe_rate' => 0, 'list_markup' => 0, 'vat_rate' => 0, 'waste_rate' => 0];

        $stmtRate = $this->db->query("SELECT buy_rate FROM rate_exchange_history WHERE currency_code = 'NGN' ORDER BY created_at DESC, id DESC LIMIT 1");
        $this->ngn_rate = (float)($stmtRate->fetchColumn() ?: 1);

        $this->air_settings = $this->db->query("SELECT * FROM shipment_air_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

        foreach ($this->db->query("SELECT * FROM shipment_sea_containers")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->sea_settings[$row['id']] = $row;
        }
    }

    public function enableLog($on = true) { $this->logEnabled = (bool)$on; }
    public function getVatRate() { return (float)$this->settings['vat_rate']; }

    private function log($message) {
        if (!$this->logEnabled) return;
        $prefix = str_repeat("&nbsp;&nbsp;&nbsp;&nbsp;", $this->indentLevel);
        $this->calculationLog[] = $prefix . "<i class='fa-solid fa-angle-right text-muted me-1'></i> " . $message;
    }

    private static function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

    /* ------------------------------------------------------------------
     * Toplu yükleme
     * ------------------------------------------------------------------ */

    /** Verilen ürünleri ve tüm alt reçete ağacını katman katman yükler (2-4 sorgu / seviye). */
    public function preload(array $ids) {
        $todo = array_values(array_unique(array_filter(array_map('intval', $ids))));
        while ($todo) {
            $todo = array_values(array_filter($todo, fn($id) => !isset($this->products[$id]) && !isset($this->missing[$id])));
            if (!$todo) break;
            $next = [];
            foreach (array_chunk($todo, 500) as $chunk) {
                $next = array_merge($next, $this->loadChunk($chunk));
            }
            $todo = $next;
        }
    }

    /** Tüm ürün veritabanını tek seferde yükler (export ve toplu işlemler için). */
    public function preloadAll() {
        $rows = $this->db->query("
            SELECT p.*, (SELECT MIN(r.recipe_id) FROM product_recipes r WHERE r.product_id = p.product_id) AS recipe_id
            FROM products p")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $this->products[(int)$r['product_id']] = $r;

        $this->items = [];
        $q = $this->db->query("
            SELECT pri.recipe_id, pri.material_product_id, pri.quantity, pri.unit_id AS recipe_unit_id
            FROM product_recipe_items pri ORDER BY pri.item_id");
        while ($it = $q->fetch(PDO::FETCH_ASSOC)) $this->items[(int)$it['recipe_id']][] = $it;

        foreach ($this->db->query("SELECT product_id, unit_id, conversion_factor FROM product_unit_conversions") as $c) {
            $this->conversions[(int)$c['product_id']][(int)$c['unit_id']] = (float)$c['conversion_factor'];
        }
        foreach ($this->db->query("SELECT * FROM product_packages")->fetchAll(PDO::FETCH_ASSOC) as $pk) {
            $this->packages[(int)$pk['product_id']][] = $pk;
        }
        // preload() tekrar sorgu atmasın
        foreach ($this->products as $id => $_) {
            $this->conversions[$id] = $this->conversions[$id] ?? [];
            $this->packages[$id] = $this->packages[$id] ?? [];
        }
    }

    private function loadChunk(array $ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));

        $st = $this->db->prepare("
            SELECT p.*, (SELECT MIN(r.recipe_id) FROM product_recipes r WHERE r.product_id = p.product_id) AS recipe_id
            FROM products p WHERE p.product_id IN ($in)");
        $st->execute($ids);
        $found = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $this->products[(int)$r['product_id']] = $r;
            $this->conversions[(int)$r['product_id']] = [];
            $this->packages[(int)$r['product_id']] = [];
            $found[(int)$r['product_id']] = true;
        }
        foreach ($ids as $id) if (!isset($found[$id])) $this->missing[$id] = true;
        if (!$found) return [];

        $fids = array_keys($found);
        $fin = implode(',', array_fill(0, count($fids), '?'));

        $st = $this->db->prepare("SELECT product_id, unit_id, conversion_factor FROM product_unit_conversions WHERE product_id IN ($fin)");
        $st->execute($fids);
        foreach ($st as $c) $this->conversions[(int)$c['product_id']][(int)$c['unit_id']] = (float)$c['conversion_factor'];

        $st = $this->db->prepare("SELECT * FROM product_packages WHERE product_id IN ($fin)");
        $st->execute($fids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pk) $this->packages[(int)$pk['product_id']][] = $pk;

        $recipeIds = [];
        foreach ($fids as $id) {
            if (!empty($this->products[$id]['recipe_id'])) $recipeIds[] = (int)$this->products[$id]['recipe_id'];
        }
        $children = [];
        if ($recipeIds) {
            $rin = implode(',', array_fill(0, count($recipeIds), '?'));
            $st = $this->db->prepare("
                SELECT recipe_id, material_product_id, quantity, unit_id AS recipe_unit_id
                FROM product_recipe_items WHERE recipe_id IN ($rin) ORDER BY item_id");
            $st->execute($recipeIds);
            foreach ($recipeIds as $rid) $this->items[$rid] = $this->items[$rid] ?? [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $this->items[(int)$it['recipe_id']][] = $it;
                $children[] = (int)$it['material_product_id'];
            }
        }
        return $children;
    }

    private function getProduct($id) {
        if (!isset($this->products[$id]) && !isset($this->missing[$id])) $this->preload([$id]);
        return $this->products[$id] ?? null;
    }

    /* ------------------------------------------------------------------
     * Kargo
     * ------------------------------------------------------------------ */

    private function calculateFreightCost($product) {
        if (empty($product['include_shipment']) || $product['shipment_type'] === 'NONE') {
            $this->log("<span class='text-muted'>No shipment cost included for this product.</span>");
            return 0;
        }

        $this->log("<span class='text-primary fw-bold'>Calculating Shipment Cost (Type: " . self::h($product['shipment_type']) . ")...</span>");
        $packages = $this->packages[(int)$product['product_id']] ?? [];

        if (!$packages) {
            $this->log("<span class='text-warning'>Warning: Shipment is enabled but no packages found.</span>");
            return 0;
        }

        $total_cbm = 0; $total_kg = 0; $total_desi = 0;
        $desi_divisor = isset($this->air_settings['desi_divisor']) ? (float)$this->air_settings['desi_divisor'] : 6000;
        if ($desi_divisor <= 0) $desi_divisor = 6000;

        foreach ($packages as $pkg) {
            $l = (float)$pkg['length_cm']; $w = (float)$pkg['width_cm']; $h = (float)$pkg['height_cm'];
            $kg = (float)$pkg['weight_kg']; $qty = (int)$pkg['quantity'];

            $total_cbm += (($l * $w * $h) / 1000000) * $qty;
            $total_kg  += $kg * $qty;
            if ($product['shipment_type'] === 'AIR') $total_desi += (($l * $w * $h) / $desi_divisor) * $qty;
        }

        $rate = $this->ngn_rate > 0 ? $this->ngn_rate : 1;
        $freight_cost = 0;

        if ($product['shipment_type'] === 'AIR' && $this->air_settings) {
            $chargeable = max($total_kg, $total_desi);
            $this->log("AIR CARGO: Total Gross = <strong>{$total_kg} Kg</strong>, Total Desi = <strong>{$total_desi}</strong>. Chargeable Weight = <strong>{$chargeable} Unit</strong>");

            $unit_cost = (float)$this->air_settings['turkey_local_usd'] + (float)$this->air_settings['air_freight_usd']
                       + ((float)$this->air_settings['clearance_ngn']) / $rate
                       + ((float)$this->air_settings['abuja_local_ngn']) / $rate;
            $margin = (float)$this->air_settings['profit_margin'];
            $freight_cost = $chargeable * $unit_cost * (1 + ($margin / 100));
            $this->log("Formula: Chargeable ({$chargeable}) * Total Unit Cost ($" . number_format($unit_cost, 2) . ") + Margin ({$margin}%) = <strong>$" . number_format($freight_cost, 2) . "</strong>");

        } elseif ($product['shipment_type'] === 'SEA' && !empty($product['sea_container_id'])) {
            $c_id = $product['sea_container_id'];
            if (isset($this->sea_settings[$c_id])) {
                $container = $this->sea_settings[$c_id];
                $theo_cbm = (float)$container['theoretical_cbm'] ?: 1;
                $loss = (float)$container['volume_loss_percent'];
                $net_cbm = $theo_cbm * (1 - ($loss / 100));

                $this->log("SEA FREIGHT: Container [<strong>" . self::h($container['container_type']) . "</strong>]. Theo CBM: {$theo_cbm}, Loss: %{$loss}, Net CBM: <strong>{$net_cbm}</strong>");
                $this->log("Product Total CBM: <strong>{$total_cbm}</strong>");

                $total_container_cost = (float)$container['turkey_cost_usd'] + (float)$container['sea_freight_usd']
                                      + ((float)$container['clearance_ngn']) / $rate
                                      + ((float)$container['abuja_local_ngn']) / $rate;
                $margin = (float)$container['profit_margin'];
                $cost_per_cbm = $net_cbm > 0 ? ($total_container_cost / $net_cbm) : 0;
                $freight_cost = $total_cbm * $cost_per_cbm * (1 + ($margin / 100));
                $this->log("Formula: Total CBM ({$total_cbm}) * Cost Per CBM ($" . number_format($cost_per_cbm, 2) . ") + Margin ({$margin}%) = <strong>$" . number_format($freight_cost, 2) . "</strong>");
            }
        }
        return $freight_cost;
    }

    /* ------------------------------------------------------------------
     * Fiyat hesabı
     * ------------------------------------------------------------------ */

    public function calculatePrices($productId, $is_root = true) {
        $productId = (int)$productId;

        if ($is_root) {
            $this->calculationLog = [];
            $this->indentLevel = 0;
            $this->stack = [];
            $this->log("<strong class='text-success fs-6'>=== STARTING COST CALCULATION ===</strong>");
        }

        // Log modunda tam izi göstermek için önbelleği atla
        if (!$this->logEnabled && isset($this->memo[$productId])) {
            return $this->memo[$productId];
        }

        $product = $this->getProduct($productId);
        if (!$product) {
            $this->log("<span class='text-danger'>Product ID {$productId} not found!</span>");
            return ['base_cost' => 0, 'production_cost' => 0, 'sales_price' => 0, 'list_price' => 0, 'min_price' => 0];
        }

        if (isset($this->stack[$productId])) {
            $this->log("<span class='text-danger fw-bold'>Circular recipe detected at " . self::h($product['stock_code']) . " — component counted as 0.</span>");
            return ['base_cost' => 0, 'production_cost' => 0, 'sales_price' => 0, 'list_price' => 0, 'min_price' => 0, 'circular' => true];
        }
        $this->stack[$productId] = true;

        $this->log("Analyzing Product: <strong>" . self::h($product['stock_code'] . " - " . $product['stock_name']) . "</strong>");

        $base_cost = 0;
        $production_cost = 0;

        if (empty($product['recipe_id'])) {
            $base_cost = (float)$product['unit_cost_usd'];
            $production_cost = $base_cost;
            $this->log("Type: Raw Material. Base Cost: <strong>$" . number_format($base_cost, 4) . "</strong>");
        } else {
            $this->log("Type: Manufactured (BOM). Found Recipe ID: {$product['recipe_id']}. Iterating materials...");
            $this->indentLevel++;

            $items = $this->items[(int)$product['recipe_id']] ?? [];
            // Bileşenlerin tamamını tek seferde yükle
            $this->preload(array_column($items, 'material_product_id'));

            foreach ($items as $item) {
                $mat_id = (int)$item['material_product_id'];
                $mat = $this->products[$mat_id] ?? null;
                if (!$mat) continue; // eski sürümdeki INNER JOIN davranışı

                $qty = (float)$item['quantity'];
                $this->log("<span class='text-primary'>-> Component: " . self::h($mat['stock_code'] . " (" . $mat['stock_name'] . ")") . " | Qty: {$qty}</span>");
                $this->indentLevel++;

                $childPrices = $this->calculatePrices($mat_id, false);
                $child_base_price = $childPrices['base_cost'];

                $rec_unit_id = $item['recipe_unit_id'];
                if (!empty($rec_unit_id) && $rec_unit_id != $mat['unit_id']) {
                    $convFactor = (float)($this->conversions[$mat_id][(int)$rec_unit_id] ?? 0);
                    if ($convFactor > 0) {
                        $old_price = $child_base_price;
                        $child_base_price = $child_base_price / $convFactor;
                        $this->log("Multi-UoM Conversion applied. Factor: {$convFactor}. Price converted: $" . number_format($old_price, 4) . " => <strong>$" . number_format($child_base_price, 4) . "</strong>");
                    }
                }

                $childProdMargin = (float)$mat['production_margin'];
                $item_base_total = $qty * $child_base_price;
                $item_prod_total = $qty * $child_base_price * (1 + ($childProdMargin / 100));

                $this->log("Component Total Base: $" . number_format($item_base_total, 4) . " | Prod. Margin: %{$childProdMargin} | Total Prod: <strong>$" . number_format($item_prod_total, 4) . "</strong>");

                $base_cost += $item_base_total;
                $production_cost += $item_prod_total;
                $this->indentLevel--;
            }
            $this->indentLevel--;
            $this->log("Finished iterating recipe materials. Total BOM Base Cost: <strong>$" . number_format($base_cost, 4) . "</strong>");
        }

        $material_base = $base_cost;          // kargo öncesi malzeme/hammadde maliyeti
        $material_prod = $production_cost;    // + bileşen üretim marjları
        $freight_cost = $this->calculateFreightCost($product);
        if ($freight_cost > 0) {
            $base_cost += $freight_cost;
            $production_cost += $freight_cost;
            $this->log("<span class='text-success'>Landed Cost Update: Freight ($" . number_format($freight_cost, 2) . ") added. New Landed Base Cost: <strong>$" . number_format($base_cost, 4) . "</strong></span>");
        }

        $step1 = $production_cost;
        $srv_amount = 0;
        $srv_val = (float)$product['service_margin_value'];
        $srv_type = $product['service_margin_type'];

        if ($srv_type === 'PERCENT' && $srv_val > 0) {
            $step2 = $step1 * (1 + ($srv_val / 100));
            $srv_amount = $step2 - $step1;
            $this->log("Service Margin: %{$srv_val} applied.");
        } elseif ($srv_type === 'USD' && $srv_val > 0) {
            $step2 = $step1 + $srv_val;
            $srv_amount = $srv_val;
            $this->log("Service Margin: {$srv_val} USD applied.");
        } elseif ($srv_type === 'NGN' && $srv_val > 0) {
            $val_usd = ($this->ngn_rate > 1 ? ($srv_val / $this->ngn_rate) : 0);
            $step2 = $step1 + $val_usd;
            $srv_amount = $val_usd;
            $this->log("Service Margin: {$srv_val} NGN ($" . number_format($val_usd, 2) . ") applied.");
        } else {
            $step2 = $step1;
        }

        $sales_margin = (float)$product['sales_margin'];
        $step3 = $step2 * (1 + ($sales_margin / 100));
        if ($sales_margin > 0) $this->log("Sales Margin: %{$sales_margin} applied.");

        $step4 = $step3 * (1 + (((float)$this->settings['waste_rate'] + (float)$this->settings['safe_rate']) / 100));
        $vat_rate = (float)$this->settings['vat_rate'];
        $step5 = $step4 * (1 + ($vat_rate / 100));
        $final_list_price = $step5 * (1 + ((float)$this->settings['list_markup'] / 100));

        // Taban fiyat: satış fiyatı + KDV (proformadaki fiyatlar KDV dahil olduğu için)
        $min_price = $step3 * (1 + ($vat_rate / 100));

        if ($is_root) {
            $this->log("<strong class='text-success fs-6'>=== FINAL ALGORITHM RESULTS ===</strong>");
            $this->log("Final Base / Landed Cost: <strong>$" . number_format($base_cost, 2) . "</strong>");
            $this->log("Final Production Cost: <strong>$" . number_format($production_cost, 2) . "</strong>");
            $this->log("Final Sales Price: <strong>$" . number_format($step3, 2) . "</strong>");
            $this->log("Minimum Price (Sales Price + VAT): <strong>$" . number_format($min_price, 2) . "</strong>");
            $this->log("List Price (Inc. Waste, Safe, VAT, Markup): <strong class='text-danger'>$" . number_format($final_list_price, 2) . "</strong>");
        }

        unset($this->stack[$productId]);

        $result = [
            'base_cost' => $base_cost,
            'production_cost' => $production_cost,
            'sales_price' => $step3,
            'list_price' => $final_list_price,
            'min_price' => $min_price,
            // Cost Debugger'daki elle doğrulama diyagramı için ara adımlar
            'breakdown' => [
                'is_bom' => !empty($product['recipe_id']),
                'material_base' => $material_base,
                'material_prod' => $material_prod,
                'freight' => $freight_cost,
                'step1' => $step1,
                'srv_type' => $srv_type, 'srv_value' => $srv_val, 'srv_amount' => $srv_amount,
                'step2' => $step2,
                'sales_margin' => $sales_margin,
                'step3' => $step3,
                'waste_rate' => (float)$this->settings['waste_rate'], 'safe_rate' => (float)$this->settings['safe_rate'],
                'step4' => $step4,
                'vat_rate' => $vat_rate,
                'step5' => $step5,
                'list_markup' => (float)$this->settings['list_markup'],
                'list_price' => $final_list_price,
                'ngn_buy_rate' => $this->ngn_rate,
            ],
        ];
        $this->memo[$productId] = $result;
        return $result;
    }

    /**
     * Bir ürünün proforma birim fiyatını hesaplar (api_get_price ve sunucu tarafı kontrol için ortak).
     * $currency: 'USD' veya 'NGN'. $ngn_sale_rate NGN için satış kuru.
     */
    public function quote($productId, $discount_rate, $currency, $ngn_sale_rate) {
        $calc = $this->calculatePrices($productId);
        $discounted = $calc['list_price'] * (1 - ((float)$discount_rate / 100));

        if ($currency === 'NGN') {
            $price = round(($discounted * $ngn_sale_rate) / 1000) * 1000;
            $min = round(($calc['min_price'] * $ngn_sale_rate) / 1000) * 1000;
        } else {
            $price = round($discounted, 2);
            $min = round($calc['min_price'], 2);
        }
        if ($price < $min) $price = $min;
        return ['price' => $price, 'min_price' => $min];
    }
}
