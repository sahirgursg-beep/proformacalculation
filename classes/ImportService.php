<?php
require_once __DIR__ . '/ExcelIO.php';

/**
 * Excel ile toplu içe aktarma: ürünler, reçeteler, kategoriler.
 *
 * analyze(): dosyayı doğrular, veritabanına YAZMAZ. Döner:
 *   ['actions' => [...], 'errors' => [[satır, mesaj]], 'warnings' => [[satır, mesaj]], 'summary' => [...], 'columns' => [...]]
 * apply():   analyze() sonucunu tek transaction içinde yazar (hata olursa hiçbir şey yazılmaz).
 *
 * Kural: hatalı satır/kayıt yazılmaz; hatasız olanlar yazılır. Önizlemede hepsi gösterilir.
 */
class ImportService {
    const TYPES = [
        'products'   => ['label' => 'Products',   'roles' => ['SUPER_ADMIN']],
        'recipes'    => ['label' => 'Recipes',    'roles' => ['SUPER_ADMIN']],
        'categories' => ['label' => 'Categories', 'roles' => ['SUPER_ADMIN', 'ARCHITECT']],
    ];

    /* Sütun tanımları: alan => kabul edilen başlıklar (ilk olan şablondaki başlıktır) */
    const FIELDS = [
        'products' => [
            'stock_code'           => ['Stock Code', 'Code', 'Product Code', 'Stok Kodu'],
            'stock_name'           => ['Stock Name', 'Product Name', 'Name', 'Description', 'Stok Adı'],
            'category_id'          => ['Category ID', 'CategoryID', 'Kategori ID'],
            'unit_id'              => ['Unit ID', 'UnitID', 'Birim ID'],
            'unit_name'            => ['Unit', 'Unit Name', 'Birim'],
            'unit_cost_usd'        => ['Unit Cost USD', 'Unit Cost', 'Cost', 'Cost Price USD', 'CostPriceUSD', 'Maliyet'],
            'production_margin'    => ['Production Margin', 'Prod Margin', 'Üretim Marjı'],
            'sales_margin'         => ['Sales Margin', 'Sale Margin', 'Satış Marjı'],
            'service_margin_type'  => ['Service Margin Type', 'Srv Margin Type', 'Service Unit Type', 'Service Type'],
            'service_margin_value' => ['Service Margin Value', 'Srv Margin Value', 'Service Value'],
            'currency'             => ['Currency'],
        ],
        'recipes' => [
            'target_code'   => ['Target Code', 'Parent Code', 'Product Code', 'Target'],
            'recipe_name'   => ['Recipe Name', 'Recipe', 'BOM Name'],
            'material_code' => ['Material Code', 'Component Code', 'Material', 'Component'],
            'quantity'      => ['Quantity', 'Qty', 'Miktar'],
            'unit_name'     => ['Unit Name', 'Unit', 'Birim'],
        ],
        'categories' => [
            'category_id'        => ['Category ID', 'category_id', 'ID'],
            'category_name'      => ['Category Name', 'category_name', 'Name', 'Kategori'],
            'parent_category_id' => ['Parent Category ID', 'parent_category_id', 'Parent ID'],
            'parent_name'        => ['Parent Category', 'Parent Category Name', 'Parent', 'Üst Kategori'],
            'stock_code_start'   => ['Stock Code Start', 'stock_code_start', 'Code Start'],
            'stock_code_end'     => ['Stock Code End', 'stock_code_end', 'Code End'],
        ],
    ];

    /* Başlık yoksa eski şablonların sütun sırası (geriye uyumluluk) */
    const LEGACY_ORDER = [
        'products'   => ['stock_code', 'stock_name', 'category_id', 'unit_id', 'unit_cost_usd', 'production_margin', 'sales_margin', 'service_margin_type', 'service_margin_value'],
        'recipes'    => ['target_code', 'recipe_name', 'material_code', 'quantity', 'unit_name'],
        'categories' => ['category_name', 'parent_category_id', 'stock_code_start', 'stock_code_end'],
    ];

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /* ---------------- ortak ---------------- */

    private function columns($type, array $parsed) {
        $m = ExcelIO::mapHeaders($parsed['headers'], self::FIELDS[$type]);
        $warnings = [];
        if (count($m['map']) < 2) {
            // Başlık tanınmadı: ilk satır veri olabilir → eski sütun sırası
            $map = array_flip(self::LEGACY_ORDER[$type]);
            array_unshift($parsed['rows'], $parsed['headers']);
            $warnings[] = [0, 'Column headers were not recognised, so the old fixed column order was used: ' . implode(', ', array_map(fn($k) => self::FIELDS[$type][$k][0], self::LEGACY_ORDER[$type])) . '. Download the template to use named columns.'];
            return [$map, $parsed['rows'], $warnings, 2 - 1];
        }
        foreach ($m['unknown'] as $u) $warnings[] = [1, "Column “{$u}” is not used and was ignored."];
        return [$m['map'], $parsed['rows'], $warnings, 2];
    }

    private static function cell(array $row, array $map, $field) {
        if (!isset($map[$field])) return null;          // sütun yok
        $v = $row[$map[$field]] ?? '';
        $v = is_string($v) ? trim($v) : $v;
        return $v === '' ? '' : $v;                      // '' = boş hücre
    }

    public function analyze($type, array $parsed) {
        if (!isset(self::TYPES[$type])) throw new InvalidArgumentException('Unknown import type');
        $fn = 'analyze' . ucfirst($type);
        $res = $this->$fn($parsed);
        usort($res['errors'], fn($a, $b) => $a[0] <=> $b[0]);
        return $res;
    }

    public function apply($type, array $analysis) {
        $fn = 'apply' . ucfirst($type);
        $this->db->beginTransaction();
        try {
            $r = $this->$fn($analysis);
            $this->db->commit();
            return $r;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /* ================= ÜRÜNLER ================= */

    private function analyzeProducts(array $parsed) {
        [$map, $rows, $warnings, $first] = $this->columns('products', $parsed);
        $errors = []; $actions = [];
        if (!isset($map['stock_code'])) return ['actions' => [], 'errors' => [[1, 'A “Stock Code” column is required.']], 'warnings' => $warnings, 'summary' => [], 'columns' => array_keys($map)];

        $existing = [];
        foreach ($this->db->query("SELECT product_id, stock_code, stock_name, category_id, unit_id, unit_cost_usd, production_margin, sales_margin, service_margin_type, service_margin_value FROM products") as $p) $existing[mb_strtoupper($p['stock_code'])] = $p;
        $cats = array_flip(array_map('intval', $this->db->query("SELECT category_id FROM categories")->fetchAll(PDO::FETCH_COLUMN)));
        $units = []; $unitNames = [];
        foreach ($this->db->query("SELECT unit_id, unit_name FROM units") as $u) { $units[(int)$u['unit_id']] = $u['unit_name']; $unitNames[mb_strtolower(trim($u['unit_name']))] = (int)$u['unit_id']; }

        $seen = [];
        $num = function ($v, $label, $min, $max, $line, &$errs) {
            if ($v === null || $v === '') return null;
            $n = ExcelIO::num($v);
            if ($n === null) { $errs[] = [$line, "$label “{$v}” is not a number."]; return false; }
            if ($n < $min || $n > $max) { $errs[] = [$line, "$label {$n} is out of range ($min to $max)."]; return false; }
            return round($n, 4);
        };

        foreach ($rows as $i => $row) {
            $line = $i + $first;
            $rowErr = [];
            $code = (string)self::cell($row, $map, 'stock_code');
            if ($code === '') { $rowErr[] = [$line, 'Stock code is empty.']; $errors = array_merge($errors, $rowErr); continue; }
            if (mb_strlen($code) > 50) $rowErr[] = [$line, "Stock code “{$code}” is longer than 50 characters."];
            $key = mb_strtoupper($code);
            if (isset($seen[$key])) { $errors[] = [$line, "Stock code {$code} appears twice in the file (first on row {$seen[$key]}). Only the first row is used."]; continue; }
            $seen[$key] = $line;

            $cur = self::cell($row, $map, 'currency');
            if ($cur !== null && $cur !== '' && strtoupper((string)$cur) !== 'USD') $rowErr[] = [$line, "Currency “{$cur}”: costs must be in USD."];

            $set = [];
            $name = self::cell($row, $map, 'stock_name');
            if ($name !== null && $name !== '') { if (mb_strlen($name) > 255) $rowErr[] = [$line, 'Product name is longer than 255 characters.']; $set['stock_name'] = (string)$name; }

            $cat = self::cell($row, $map, 'category_id');
            if ($cat !== null && $cat !== '') {
                $c = ExcelIO::num($cat);
                if ($c === null || !isset($cats[(int)$c])) $rowErr[] = [$line, "Category ID “{$cat}” does not exist."]; else $set['category_id'] = (int)$c;
            }
            $uid = self::cell($row, $map, 'unit_id'); $uname = self::cell($row, $map, 'unit_name');
            if ($uid !== null && $uid !== '') {
                $u = ExcelIO::num($uid);
                if ($u === null || !isset($units[(int)$u])) $rowErr[] = [$line, "Unit ID “{$uid}” does not exist."]; else $set['unit_id'] = (int)$u;
            } elseif ($uname !== null && $uname !== '') {
                $u = $unitNames[mb_strtolower(trim($uname))] ?? null;
                if (!$u) $rowErr[] = [$line, "Unit “{$uname}” does not exist. Known units: " . implode(', ', array_slice($units, 0, 12)) . '…']; else $set['unit_id'] = $u;
            }
            foreach ([['unit_cost_usd', 'Unit cost', 0, 999999999], ['production_margin', 'Production margin', -100, 999.99], ['sales_margin', 'Sales margin', -100, 999.99], ['service_margin_value', 'Service margin value', 0, 99999999]] as [$f, $label, $min, $max]) {
                $v = $num(self::cell($row, $map, $f), $label, $min, $max, $line, $rowErr);
                if ($v !== null && $v !== false) $set[$f] = $v;
            }
            $st = self::cell($row, $map, 'service_margin_type');
            if ($st !== null && $st !== '') {
                $n = strtoupper(str_replace(' ', '', (string)$st));
                $t = in_array($n, ['PERCENT', 'PERCENTAGE', '%', 'PCT', 'YUZDE'], true) ? 'PERCENT' : (in_array($n, ['USD', '$', 'DOLLAR'], true) ? 'USD' : (in_array($n, ['NGN', '₦', 'NAIRA'], true) ? 'NGN' : null));
                if (!$t) $rowErr[] = [$line, "Service margin type “{$st}” must be PERCENT, USD or NGN."]; else $set['service_margin_type'] = $t;
            }

            if ($rowErr) { $errors = array_merge($errors, $rowErr); continue; }

            $old = $existing[$key] ?? null;
            if (!$old) {
                $missing = [];
                if (!isset($set['stock_name'])) $missing[] = 'name';
                if (!isset($set['category_id'])) $missing[] = 'category';
                if (!isset($set['unit_id'])) $missing[] = 'unit';
                if (!isset($set['unit_cost_usd'])) $missing[] = 'unit cost';
                if ($missing) { $errors[] = [$line, "New product {$code} needs: " . implode(', ', $missing) . '.']; continue; }
                $actions[] = ['op' => 'insert', 'line' => $line, 'code' => $code, 'set' => $set + ['production_margin' => 0, 'sales_margin' => 0, 'service_margin_type' => 'PERCENT', 'service_margin_value' => 0]];
            } else {
                // Boş hücreler mevcut değeri korur; sadece değişenler yazılır
                $diff = [];
                foreach ($set as $f => $v) {
                    $o = $old[$f];
                    $same = is_numeric($v) && is_numeric($o) ? abs((float)$v - (float)$o) < 0.00005 : trim((string)$v) === trim((string)$o);   // sadece boşluk farkı = aynı
                    if (!$same) $diff[$f] = $v;
                }
                if ($diff) $actions[] = ['op' => 'update', 'line' => $line, 'code' => $code, 'id' => (int)$old['product_id'], 'set' => $diff, 'old' => array_intersect_key($old, $diff)];
                else $actions[] = ['op' => 'same', 'line' => $line, 'code' => $code];
            }
        }
        $count = fn($op) => count(array_filter($actions, fn($a) => $a['op'] === $op));
        return ['actions' => $actions, 'errors' => $errors, 'warnings' => $warnings, 'columns' => array_keys($map),
            'summary' => ['New products' => $count('insert'), 'Products to update' => $count('update'), 'Unchanged' => $count('same'), 'Rows with errors' => count(array_unique(array_column($errors, 0)))]];
    }

    private function applyProducts(array $a) {
        $ins = $this->db->prepare("INSERT INTO products (stock_code, stock_name, category_id, unit_id, unit_cost_usd, production_margin, sales_margin, service_margin_type, service_margin_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $n = ['insert' => 0, 'update' => 0];
        foreach ($a['actions'] as $act) {
            if ($act['op'] === 'insert') {
                $s = $act['set'];
                $ins->execute([$act['code'], $s['stock_name'], $s['category_id'], $s['unit_id'], $s['unit_cost_usd'], $s['production_margin'], $s['sales_margin'], $s['service_margin_type'], $s['service_margin_value']]);
                $n['insert']++;
            } elseif ($act['op'] === 'update') {
                $cols = implode(', ', array_map(fn($f) => "$f = ?", array_keys($act['set'])));   // alan adları sabit listeden gelir
                $this->db->prepare("UPDATE products SET $cols WHERE product_id = ?")->execute([...array_values($act['set']), $act['id']]);
                $n['update']++;
            }
        }
        return "{$n['insert']} products added, {$n['update']} updated.";
    }

    /* ================= REÇETELER ================= */

    private function analyzeRecipes(array $parsed) {
        [$map, $rows, $warnings, $first] = $this->columns('recipes', $parsed);
        $errors = [];
        foreach (['target_code' => 'Target Code', 'material_code' => 'Material Code', 'quantity' => 'Quantity'] as $f => $label) {
            if (!isset($map[$f])) $errors[] = [1, "A “{$label}” column is required."];
        }
        if ($errors) return ['actions' => [], 'errors' => $errors, 'warnings' => $warnings, 'summary' => [], 'columns' => array_keys($map)];

        $prod = [];
        foreach ($this->db->query("SELECT product_id, stock_code, unit_id FROM products") as $p) $prod[mb_strtoupper($p['stock_code'])] = $p;
        $unitNames = [];
        foreach ($this->db->query("SELECT unit_id, unit_name FROM units") as $u) $unitNames[mb_strtolower(trim($u['unit_name']))] = (int)$u['unit_id'];
        $conv = [];
        foreach ($this->db->query("SELECT product_id, unit_id FROM product_unit_conversions") as $c) $conv[(int)$c['product_id']][(int)$c['unit_id']] = true;
        $existingRecipe = [];
        foreach ($this->db->query("SELECT recipe_id, product_id, recipe_name FROM product_recipes") as $r) $existingRecipe[(int)$r['product_id']] = $r;
        // Mevcut reçete içerikleri (değişmeyenleri atlamak için)
        $existingItems = [];
        foreach ($this->db->query("SELECT recipe_id, material_product_id, quantity, unit_id FROM product_recipe_items") as $it) {
            $existingItems[(int)$it['recipe_id']][] = (int)$it['material_product_id'] . ':' . (int)$it['unit_id'] . ':' . rtrim(rtrim(number_format((float)$it['quantity'], 4, '.', ''), '0'), '.');
        }
        $sig = function (array $list) { sort($list); return implode('|', $list); };

        // Satırları hedef ürüne göre grupla
        $groups = [];
        foreach ($rows as $i => $row) {
            $line = $i + $first;
            $t = mb_strtoupper((string)self::cell($row, $map, 'target_code'));
            if ($t === '') { $errors[] = [$line, 'Target code is empty.']; continue; }
            $groups[$t]['lines'][] = $line;
            $groups[$t]['rows'][] = [$line, $row];
            $rn = self::cell($row, $map, 'recipe_name');
            if ($rn !== null && $rn !== '' && empty($groups[$t]['name'])) $groups[$t]['name'] = mb_substr((string)$rn, 0, 255);
        }

        $actions = []; $skipped = 0;
        foreach ($groups as $t => $g) {
            $gErr = [];
            $target = $prod[$t] ?? null;
            if (!$target) { $errors[] = [$g['lines'][0], "Target product {$t} does not exist. Recipe skipped."]; $skipped++; continue; }
            $items = []; $seenMat = [];
            foreach ($g['rows'] as [$line, $row]) {
                $mc = mb_strtoupper((string)self::cell($row, $map, 'material_code'));
                $q = ExcelIO::num(self::cell($row, $map, 'quantity'));
                if ($mc === '') { $gErr[] = [$line, 'Material code is empty.']; continue; }
                $mat = $prod[$mc] ?? null;
                if (!$mat) { $gErr[] = [$line, "Material {$mc} does not exist."]; continue; }
                if ($mc === $t) { $gErr[] = [$line, "{$t} cannot contain itself."]; continue; }
                if ($q === null || $q <= 0) { $gErr[] = [$line, "Quantity for {$mc} must be a number above 0."]; continue; }
                $un = self::cell($row, $map, 'unit_name');
                $unitId = (int)$mat['unit_id'];
                if ($un !== null && $un !== '') {
                    $unitId = $unitNames[mb_strtolower(trim($un))] ?? 0;
                    if (!$unitId) { $gErr[] = [$line, "Unit “{$un}” does not exist."]; continue; }
                    if ($unitId !== (int)$mat['unit_id'] && empty($conv[(int)$mat['product_id']][$unitId])) {
                        $gErr[] = [$line, "{$mc} has no conversion for unit “{$un}”. Add the conversion on the product card or use its main unit."]; continue;
                    }
                }
                $k = (int)$mat['product_id'] . ':' . $unitId;
                if (isset($seenMat[$k])) $warnings[] = [$line, "{$mc} is listed more than once in {$t} (also row {$seenMat[$k]}). Both lines are kept and counted."];
                $seenMat[$k] = $line;
                $items[] = ['material_id' => (int)$mat['product_id'], 'quantity' => $q, 'unit_id' => $unitId, 'code' => $mc];
            }
            if ($gErr) {
                $errors = array_merge($errors, $gErr);
                $errors[] = [$g['lines'][0], "Recipe for {$t} was skipped because some of its rows have errors (listed here). Nothing in it was changed."];
                $skipped++; continue;
            }
            $old = $existingRecipe[(int)$target['product_id']] ?? null;
            $name = $g['name'] ?? ($old['recipe_name'] ?? 'Standard BOM');
            if ($old && $name === (string)$old['recipe_name']
                && $sig(array_map(fn($i) => $i['material_id'] . ':' . $i['unit_id'] . ':' . rtrim(rtrim(number_format((float)$i['quantity'], 4, '.', ''), '0'), '.'), array_values($items))) === $sig($existingItems[(int)$old['recipe_id']] ?? [])) {
                $actions[] = ['op' => 'same', 'line' => $g['lines'][0], 'code' => $t, 'product_id' => (int)$target['product_id'], 'items' => array_values($items)];
                continue;
            }
            $actions[] = ['op' => $old ? 'replace' : 'insert', 'line' => $g['lines'][0], 'code' => $t, 'product_id' => (int)$target['product_id'],
                'recipe_id' => $old ? (int)$old['recipe_id'] : null, 'name' => $g['name'] ?? ($old['recipe_name'] ?? 'Standard BOM'), 'items' => array_values($items)];
        }

        // Döngü kontrolü: içe aktarılan reçeteler mevcut reçetelerle birlikte kendini içeriyor mu?
        $graph = [];
        foreach ($this->db->query("SELECT r.product_id, i.material_product_id FROM product_recipes r JOIN product_recipe_items i ON i.recipe_id = r.recipe_id") as $e) $graph[(int)$e['product_id']][(int)$e['material_product_id']] = true;
        foreach ($actions as $a) $graph[$a['product_id']] = array_fill_keys(array_column($a['items'], 'material_id'), true);
        $bad = [];
        foreach ($actions as $idx => $a) {
            $stack = array_keys($graph[$a['product_id']]); $seen = [];
            while ($stack) {
                $n = array_pop($stack);
                if ($n === $a['product_id']) { $bad[$idx] = true; break; }
                if (isset($seen[$n])) continue;
                $seen[$n] = true;
                foreach (array_keys($graph[$n] ?? []) as $m) $stack[] = $m;
            }
        }
        foreach (array_keys($bad) as $idx) {
            $errors[] = [$actions[$idx]['line'], "Recipe for {$actions[$idx]['code']} would contain itself through its sub-recipes (circular recipe). Skipped."];
            unset($actions[$idx]); $skipped++;
        }
        $actions = array_values($actions);
        $count = fn($op) => count(array_filter($actions, fn($a) => $a['op'] === $op));
        $changing = array_filter($actions, fn($a) => $a['op'] !== 'same');
        return ['actions' => $actions, 'errors' => $errors, 'warnings' => $warnings, 'columns' => array_keys($map),
            'summary' => ['New recipes' => $count('insert'), 'Recipes to replace' => $count('replace'), 'Unchanged' => $count('same'), 'Recipes skipped' => $skipped]];
    }

    private function applyRecipes(array $a) {
        $insR = $this->db->prepare("INSERT INTO product_recipes (product_id, recipe_name) VALUES (?, ?)");
        $updR = $this->db->prepare("UPDATE product_recipes SET recipe_name = ? WHERE recipe_id = ?");
        $delI = $this->db->prepare("DELETE FROM product_recipe_items WHERE recipe_id = ?");
        $insI = $this->db->prepare("INSERT INTO product_recipe_items (recipe_id, material_product_id, quantity, unit_id) VALUES (?, ?, ?, ?)");
        $n = ['insert' => 0, 'replace' => 0];
        foreach ($a['actions'] as $act) {
            if ($act['op'] === 'same') continue;
            if ($act['op'] === 'insert') { $insR->execute([$act['product_id'], $act['name']]); $rid = (int)$this->db->lastInsertId(); }
            else { $rid = $act['recipe_id']; $updR->execute([$act['name'], $rid]); $delI->execute([$rid]); }
            foreach ($act['items'] as $it) $insI->execute([$rid, $it['material_id'], $it['quantity'], $it['unit_id']]);
            $n[$act['op']]++;
        }
        return "{$n['insert']} recipes added, {$n['replace']} replaced.";
    }

    /* ================= KATEGORİLER ================= */

    private function analyzeCategories(array $parsed) {
        [$map, $rows, $warnings, $first] = $this->columns('categories', $parsed);
        if (!isset($map['category_name'])) return ['actions' => [], 'errors' => [[1, 'A “Category Name” column is required.']], 'warnings' => $warnings, 'summary' => [], 'columns' => array_keys($map)];

        $cats = $this->db->query("SELECT category_id, category_name, parent_category_id, stock_code_start, stock_code_end FROM categories")->fetchAll(PDO::FETCH_ASSOC);
        $byId = []; $byName = []; $byKey = [];
        foreach ($cats as $c) {
            $id = (int)$c['category_id']; $p = (int)$c['parent_category_id'];
            $byId[$id] = $c;
            $byName[mb_strtolower(trim($c['category_name']))][] = $id;
            $byKey[$p . '|' . mb_strtolower(trim($c['category_name']))] = $id;
        }
        $errors = []; $actions = []; $pending = [];
        foreach ($rows as $i => $row) $pending[] = [$i + $first, $row];

        // Üst kategori aynı dosyada olabilir: birkaç tur dene
        $newKeys = []; $seenKey = []; $seenId = [];
        for ($pass = 0; $pending && $pass < 20; $pass++) {
            $next = [];
            foreach ($pending as [$line, $row]) {
                $name = trim((string)self::cell($row, $map, 'category_name'));
                if ($name === '') { $errors[] = [$line, 'Category name is empty.']; continue; }
                if (mb_strlen($name) > 100) { $errors[] = [$line, 'Category name is longer than 100 characters.']; continue; }
                $pid = 0; $parentRef = null;
                $pidCell = self::cell($row, $map, 'parent_category_id'); $pname = trim((string)self::cell($row, $map, 'parent_name'));
                if ($pidCell !== null && $pidCell !== '' && (int)ExcelIO::num($pidCell) !== 0) {
                    $pid = (int)ExcelIO::num($pidCell);
                    if (!isset($byId[$pid])) { $errors[] = [$line, "Parent category ID {$pidCell} does not exist."]; continue; }
                } elseif ($pname !== '') {
                    $pk = mb_strtolower($pname);
                    $hits = $byName[$pk] ?? [];
                    if (isset($newKeys[$pk])) { $pid = null; $parentRef = $pk; }            // üstü bu dosyada ekleniyor
                    elseif (count($hits) === 1) { $pid = $hits[0]; }
                    elseif (count($hits) > 1) { $errors[] = [$line, "Parent category name “{$pname}” is used by more than one category. Use Parent Category ID instead."]; continue; }
                    else { $next[] = [$line, $row]; continue; }                              // belki dosyada sonra geliyor
                }
                // Category ID varsa: o kategori güncellenir (ad, üst kategori, kod aralığı)
                $cidCell = self::cell($row, $map, 'category_id');
                if ($cidCell !== null && $cidCell !== '') {
                    $cid = (int)ExcelIO::num($cidCell);
                    if (!isset($byId[$cid])) { $errors[] = [$line, "Category ID {$cidCell} does not exist. Leave the ID empty to add a new category."]; continue; }
                    if ($pid !== null && $pid) {   // döngü: kendisinin altına / alt kategorisinin altına taşınamaz
                        for ($up = $pid, $guard = 0; $up && $guard < 100; $up = (int)($byId[$up]['parent_category_id'] ?? 0), $guard++) {
                            if ($up === $cid) { $errors[] = [$line, "“{$name}” cannot be moved under itself or one of its own sub-categories."]; continue 2; }
                        }
                    }
                    if (isset($seenId[$cid])) { $errors[] = [$line, "Category ID {$cid} appears twice (first on row {$seenId[$cid]})."]; continue; }
                    $seenId[$cid] = $line;
                    $old = $byId[$cid]; $set = [];
                    if (trim($name) !== trim($old['category_name'])) $set['category_name'] = $name;
                    // Üst kategori sütunları dosyada varsa (boş = ana kategori) uygulanır; yoksa dokunulmaz
                    $hasParentCols = isset($map['parent_category_id']) || isset($map['parent_name']);
                    if ($pid === null) $set['parent_ref'] = $parentRef;
                    elseif ($hasParentCols && (int)$pid !== (int)$old['parent_category_id']) $set['parent_category_id'] = (int)$pid;
                    $start = self::cell($row, $map, 'stock_code_start'); $end = self::cell($row, $map, 'stock_code_end');
                    if ($start !== null && $start !== '' && (string)$start !== (string)$old['stock_code_start']) $set['stock_code_start'] = mb_substr((string)$start, 0, 20);
                    if ($end !== null && $end !== '' && (string)$end !== (string)$old['stock_code_end']) $set['stock_code_end'] = mb_substr((string)$end, 0, 20);
                    $actions[] = $set ? ['op' => 'update', 'line' => $line, 'id' => $cid, 'name' => $name, 'set' => $set] : ['op' => 'same', 'line' => $line, 'name' => $name];
                    continue;
                }
                $key = ($pid === null ? 'new:' . $parentRef : $pid) . '|' . mb_strtolower($name);
                if (isset($seenKey[$key])) { $errors[] = [$line, "“{$name}” appears twice under the same parent (first on row {$seenKey[$key]})."]; continue; }
                $seenKey[$key] = $line;
                $start = self::cell($row, $map, 'stock_code_start'); $end = self::cell($row, $map, 'stock_code_end');
                if ($pid !== null && isset($byKey[$key])) {
                    $old = $byId[$byKey[$key]]; $set = [];
                    if ($start !== null && $start !== '' && (string)$start !== (string)$old['stock_code_start']) $set['stock_code_start'] = mb_substr((string)$start, 0, 20);
                    if ($end !== null && $end !== '' && (string)$end !== (string)$old['stock_code_end']) $set['stock_code_end'] = mb_substr((string)$end, 0, 20);
                    $actions[] = $set ? ['op' => 'update', 'line' => $line, 'id' => $byKey[$key], 'name' => $name, 'set' => $set] : ['op' => 'same', 'line' => $line, 'name' => $name];
                } else {
                    $actions[] = ['op' => 'insert', 'line' => $line, 'name' => $name, 'parent_id' => (int)$pid, 'parent_ref' => $parentRef,
                        'start' => (string)($start ?? ''), 'end' => (string)($end ?? '')];
                    $newKeys[mb_strtolower($name)] = true;
                }
            }
            if (count($next) === count($pending)) { $pending = $next; break; }   // ilerleme yok
            $pending = $next;
        }
        foreach ($pending as [$line, $row]) $errors[] = [$line, 'Parent category “' . trim((string)self::cell($row, $map, 'parent_name')) . '” does not exist.'];
        $count = fn($op) => count(array_filter($actions, fn($a) => $a['op'] === $op));
        return ['actions' => $actions, 'errors' => $errors, 'warnings' => $warnings, 'columns' => array_keys($map),
            'summary' => ['New categories' => $count('insert'), 'Categories to update' => $count('update'), 'Unchanged' => $count('same'), 'Rows with errors' => count(array_unique(array_column($errors, 0)))]];
    }

    private function applyCategories(array $a) {
        $ins = $this->db->prepare("INSERT INTO categories (category_name, parent_category_id, stock_code_start, stock_code_end) VALUES (?, ?, ?, ?)");
        $newIds = []; $n = ['insert' => 0, 'update' => 0];
        foreach ($a['actions'] as $act) {          // 1) yeni kategoriler (üstler önce gelir)
            if ($act['op'] !== 'insert') continue;
            $pid = $act['parent_ref'] !== null ? (int)($newIds[$act['parent_ref']] ?? 0) : (int)$act['parent_id'];
            $ins->execute([$act['name'], $pid, $act['start'], $act['end']]);
            $newIds[mb_strtolower($act['name'])] = (int)$this->db->lastInsertId();
            $n['insert']++;
        }
        $allowed = ['category_name', 'parent_category_id', 'stock_code_start', 'stock_code_end'];
        foreach ($a['actions'] as $act) {          // 2) güncellemeler
            if ($act['op'] !== 'update') continue;
            $set = $act['set'];
            if (array_key_exists('parent_ref', $set)) { $set['parent_category_id'] = (int)($newIds[$set['parent_ref']] ?? 0); unset($set['parent_ref']); }
            $set = array_intersect_key($set, array_flip($allowed));
            if (!$set) continue;
            $cols = implode(', ', array_map(fn($f) => "$f = ?", array_keys($set)));
            $this->db->prepare("UPDATE categories SET $cols WHERE category_id = ?")->execute([...array_values($set), $act['id']]);
            $n['update']++;
        }
        return "{$n['insert']} categories added, {$n['update']} updated.";
    }

    /* ================= ŞABLON / DIŞA AKTARMA ================= */

    /** Dışa aktarılan dosya, içe aktarmanın beklediği sütunlarla birebir aynıdır (düzenleyip geri yüklenebilir). */
    public function export($type, $search = '') {
        $like = '%' . $search . '%';
        $date = date('Ymd_Hi');
        if ($type === 'products') {
            $rows = function () use ($search, $like) {
                $st = $this->db->prepare("SELECT p.stock_code, p.stock_name, p.category_id, c.category_name, p.unit_id, u.unit_name, p.unit_cost_usd, p.production_margin, p.sales_margin, p.service_margin_type, p.service_margin_value
                    FROM products p LEFT JOIN categories c ON c.category_id = p.category_id LEFT JOIN units u ON u.unit_id = p.unit_id " . ($search !== '' ? "WHERE p.stock_code LIKE ? OR p.stock_name LIKE ? " : '') . "ORDER BY p.stock_code");
                if ($search !== '') $st->execute([$like, $like]); else $st->execute();
                while ($r = $st->fetch(PDO::FETCH_NUM)) yield $r;
            };
            ExcelIO::download("products_$date.xlsx",
                ['Stock Code', 'Stock Name', 'Category ID', 'Category (info)', 'Unit ID', 'Unit (info)', 'Unit Cost USD', 'Production Margin', 'Sales Margin', 'Service Margin Type', 'Service Margin Value'],
                $rows(), ['sheet' => 'Products', 'widths' => [12, 42, 11, 26, 8, 10, 13, 11, 11, 12, 12], 'numeric' => [2 => 1, 4 => 1, 6 => 1, 7 => 1, 8 => 1, 10 => 1]]);
        }
        if ($type === 'recipes') {
            $rows = function () use ($search, $like) {
                $st = $this->db->prepare("SELECT t.stock_code, r.recipe_name, m.stock_code, i.quantity, u.unit_name, t.stock_name, m.stock_name
                    FROM product_recipes r JOIN products t ON t.product_id = r.product_id JOIN product_recipe_items i ON i.recipe_id = r.recipe_id
                    JOIN products m ON m.product_id = i.material_product_id LEFT JOIN units u ON u.unit_id = i.unit_id " . ($search !== '' ? "WHERE t.stock_code LIKE ? OR t.stock_name LIKE ? OR r.recipe_name LIKE ? " : '') . "ORDER BY t.stock_code, i.item_id");
                if ($search !== '') $st->execute([$like, $like, $like]); else $st->execute();
                while ($r = $st->fetch(PDO::FETCH_NUM)) yield $r;
            };
            ExcelIO::download("recipes_$date.xlsx", ['Target Code', 'Recipe Name', 'Material Code', 'Quantity', 'Unit Name', 'Target Name (info)', 'Material Name (info)'],
                $rows(), ['sheet' => 'Recipes', 'widths' => [12, 30, 13, 10, 10, 40, 40], 'numeric' => [3 => 1]]);
        }
        if ($type === 'categories') {
            $rows = function () {
                $st = $this->db->query("SELECT c.category_id, c.category_name, c.parent_category_id, p.category_name, c.stock_code_start, c.stock_code_end
                    FROM categories c LEFT JOIN categories p ON p.category_id = c.parent_category_id ORDER BY c.category_id");
                while ($r = $st->fetch(PDO::FETCH_NUM)) yield $r;
            };
            ExcelIO::download("categories_$date.xlsx", ['Category ID', 'Category Name', 'Parent Category ID', 'Parent Category (info)', 'Stock Code Start', 'Stock Code End'],
                $rows(), ['sheet' => 'Categories', 'widths' => [10, 34, 12, 34, 14, 14], 'numeric' => [0 => 1, 2 => 1]]);
        }
        throw new InvalidArgumentException('Unknown export');
    }

    public static function template($type) {
        $t = [
            'products' => [['Stock Code', 'Stock Name', 'Category ID', 'Unit ID', 'Unit Cost USD', 'Production Margin', 'Sales Margin', 'Service Margin Type', 'Service Margin Value'],
                           [['ST99001', 'Example sofa 3 seater', 58, 1, 450, 50, 100, 'PERCENT', 0]], [2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 8 => 1]],
            'recipes'  => [['Target Code', 'Recipe Name', 'Material Code', 'Quantity', 'Unit Name'],
                           [['ST99001', 'Standard BOM', 'ST00100', 2, ''], ['ST99001', 'Standard BOM', 'ST00101', 1, '']], [3 => 1]],
            'categories' => [['Category ID', 'Category Name', 'Parent Category ID', 'Parent Category', 'Stock Code Start', 'Stock Code End'],
                           [['', 'Example main category', '', '', 'ST90000', 'ST90999'], ['', 'Example sub category', '', 'Example main category', 'ST90100', 'ST90199']], [0 => 1, 2 => 1]],
        ][$type];
        ExcelIO::download("{$type}_import_template.xlsx", $t[0], $t[1], ['sheet' => ucfirst($type), 'numeric' => $t[2]]);
    }
}
