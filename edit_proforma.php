<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/classes/ProformaService.php';
$conn = (new Database())->getConnection();
$page_title = 'Edit proforma';

$proforma_id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM proformas WHERE proforma_id = ?");
$stmt->execute([$proforma_id]);
$proforma = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$proforma) { header('Location: proformas.php'); exit; }

$svc = new ProformaService($conn);
$is_edit = true;
$error_message = '';
$form = $proforma;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_proforma'])) {
    $data = $svc->parse($_POST);
    if (!empty($data['errors'])) {
        $error_message = implode('<br>', array_map('e', $data['errors']));
    } else {
        try {
            $svc->save($data, $proforma_id);
            header('Location: view_proforma.php?id=' . $proforma_id);
            exit;
        } catch (InvalidArgumentException $ex) {
            $error_message = '<strong>Some prices are below the minimum.</strong><br>' . nl2br(e($ex->getMessage()));
        } catch (Throwable $ex) {
            error_log('Proforma update error: ' . $ex->getMessage());
            $error_message = 'The proforma could not be saved: ' . e($ex->getMessage());
        }
    }
    $form = array_merge($form, array_intersect_key($data, $form));
    [$form_items, $form_services] = $svc->formRows($data);
} else {
    $st = $conn->prepare("SELECT product_id, quantity, unit_price FROM proforma_items WHERE proforma_id = ? ORDER BY item_id");
    $st->execute([$proforma_id]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $conn->prepare("SELECT service_id, service_amount AS amount FROM proforma_services WHERE proforma_id = ? ORDER BY id");
    $st->execute([$proforma_id]);
    $srv = $st->fetchAll(PDO::FETCH_ASSOC);
    [$form_items, $form_services] = $svc->formRows(['currency' => $proforma['currency'], 'items' => $items, 'services' => $srv]);
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/proforma_form.php';
include __DIR__ . '/includes/footer.php';
