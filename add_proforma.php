<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/classes/ProformaService.php';
$conn = (new Database())->getConnection();
$page_title = 'New proforma';

$svc = new ProformaService($conn);
$is_edit = false;
$error_message = '';

// Varsayılanlar (müşteri/proje sayfasından gelinirse önceden seçili)
$form = [
    'proforma_id' => null, 'proforma_no' => '', 'proforma_name' => '',
    'customer_id' => (int)($_GET['customer_id'] ?? 0), 'project_id' => (int)($_GET['project_id'] ?? 0) ?: null,
    'proforma_date' => date('Y-m-d'), 'currency' => 'NGN', 'discount_rate' => 0, 'status' => 'DRAFT', 'notes' => '',
];
$form_items = [];
$form_services = [];

// Kopyalama: ?copy=ID → kaynak proformanın müşteri, proje, ürün ve hizmetleri dolu gelir (hepsi değiştirilebilir)
$copy_from = null;
$copyId = (int)($_GET['copy'] ?? $_POST['copy_from'] ?? 0);
if ($copyId) {
    $st = $conn->prepare("SELECT * FROM proformas WHERE proforma_id = ?");
    $st->execute([$copyId]);
    $copy_from = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($copy_from && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $page_title = 'Copy of ' . $copy_from['proforma_no'];
    $form = array_merge($form, [
        'customer_id'   => (int)$copy_from['customer_id'],
        'project_id'    => $copy_from['project_id'] ? (int)$copy_from['project_id'] : null,
        'proforma_name' => trim(($copy_from['proforma_name'] ?: $copy_from['proforma_no']) . ' (copy)'),
        'currency'      => $copy_from['currency'] === 'USD' ? 'USD' : 'NGN',
        'discount_rate' => (float)$copy_from['discount_rate'],
        'notes'         => (string)$copy_from['notes'],
    ]);
    $st = $conn->prepare("SELECT product_id, quantity, unit_price FROM proforma_items WHERE proforma_id = ? ORDER BY item_id");
    $st->execute([$copyId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $conn->prepare("SELECT service_id, service_amount AS amount FROM proforma_services WHERE proforma_id = ? ORDER BY id");
    $st->execute([$copyId]);
    [$form_items, $form_services] = $svc->formRows(['currency' => $form['currency'], 'items' => $items, 'services' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if (!$copy_from && $form['customer_id'] && !$form['discount_rate']) {
    $st = $conn->prepare("SELECT ct.discount_rate FROM customers c LEFT JOIN customer_types ct ON c.customer_type_id = ct.type_id WHERE c.customer_id = ?");
    $st->execute([$form['customer_id']]);
    $form['discount_rate'] = (float)$st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_proforma'])) {
    $data = $svc->parse($_POST);
    if (!empty($data['errors'])) {
        $error_message = implode('<br>', array_map('e', $data['errors']));
    } else {
        try {
            $saved_id = $svc->save($data);
            header('Location: view_proforma.php?id=' . $saved_id);
            exit;
        } catch (InvalidArgumentException $ex) {
            $error_message = '<strong>Some prices are below the minimum.</strong><br>' . nl2br(e($ex->getMessage()));
        } catch (Throwable $ex) {
            error_log('Proforma save error: ' . $ex->getMessage());
            $error_message = 'The proforma could not be saved: ' . e($ex->getMessage());
        }
    }
    // Hata varsa girilen veriler kaybolmasın
    $form = array_merge($form, array_intersect_key($data, $form));
    [$form_items, $form_services] = $svc->formRows($data);
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/proforma_form.php';
include __DIR__ . '/includes/footer.php';
