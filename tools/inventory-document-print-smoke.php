<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/mgs-integration-lib.php';
require_once dirname(__DIR__) . '/inventory-operations-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['status' => 'failed', 'errors' => ['db_unavailable']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$errors = [];
$itemId = 0;
$documentId = 0;
$documentNo = '';
$run = date('YmdHis') . random_int(10, 99);
$actor = ['id' => 1, 'role' => 'employee', 'employee_role' => 'warehouse', 'full_name' => 'Document Smoke'];

try {
    app_inventory_ensure_operational_schema($db);
    $insert = $db->prepare(
        'INSERT INTO inventory_stock_items(sku,item_name,category,unit,quantity_on_hand,minimum_quantity,status)
         VALUES(:sku,:name,"other","eded",0,0,"active")'
    );
    $insert->execute(['sku' => 'DOC-' . $run, 'name' => 'Document Print Smoke ' . $run]);
    $itemId = (int) $db->lastInsertId();

    app_inventory_post_intake($db, [
        'item_id' => $itemId,
        'quantity' => 2,
        'to_location_type' => 'warehouse',
        'to_location_id' => 'MAIN',
    ], $actor);

    $request = app_inventory_create_external_request($db, [
        'source_location_type' => 'warehouse',
        'source_location_id' => 'MAIN',
        'destination_type' => 'company',
        'destination_name' => 'Supplier Smoke ' . $run,
        'reason' => 'defective',
        'disposition' => 'return_to_supplier',
        'items' => [[
            'item_id' => $itemId,
            'quantity' => 1,
            'unit_price' => 12.5,
            'serial_number' => 'SN' . $run,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'note' => 'Print smoke line',
        ]],
    ], $actor);
    $documentId = (int) ($request['id'] ?? 0);
    $documentNo = (string) ($request['document_no'] ?? '');
    if (empty($request['ok']) || $documentId <= 0 || $documentNo === '') {
        $errors[] = 'document_create_failed';
    }

    $employee = $db->query(
        "SELECT *
         FROM portal_accounts
         WHERE role = 'employee' AND is_active = 1
         ORDER BY FIELD(account_type, 'admin', 'operator', 'technician') ASC, id
         LIMIT 1"
    )?->fetch(PDO::FETCH_ASSOC);
    if (!is_array($employee)) {
        $errors[] = 'employee_unavailable';
    }

    if ($errors === []) {
        app_session_boot();
        $_SESSION['portal_user'] = app_db_user_session_payload($employee);
        $_GET = ['id' => (string) $documentId];
        $_POST = [];
        $_REQUEST = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = '127.0.0.1';
        $_SERVER['REQUEST_URI'] = '/superopticnet/inventory-document.php?id=' . $documentId;
        $_SERVER['SCRIPT_NAME'] = '/superopticnet/inventory-document.php';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        ob_start();
        include dirname(__DIR__) . '/inventory-document.php';
        $html = (string) ob_get_clean();

        foreach ([
            'Tehvil-teslim qaytarma akti',
            $documentNo,
            'Tesdiq gozleyir',
            'Supplier Smoke ' . $run,
            'AA:BB:CC:DD:EE:FF / SN' . $run,
            'Tehvil veren',
            'Tehvil alan',
            'Ad/Soyad',
            'Imza',
            'Cap et',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $errors[] = 'print_marker_missing:' . $marker;
            }
        }
    }
} finally {
    if ($documentNo !== '') {
        $db->prepare('DELETE FROM mgs_operation_actions WHERE entity_id = :document_no')->execute(['document_no' => $documentNo]);
    }
    if ($documentId > 0) {
        $db->prepare('DELETE FROM inventory_document_items WHERE document_id = :id')->execute(['id' => $documentId]);
        $db->prepare('DELETE FROM inventory_documents WHERE id = :id')->execute(['id' => $documentId]);
    }
    if ($itemId > 0) {
        $db->prepare('DELETE FROM inventory_document_items WHERE item_id = :id')->execute(['id' => $itemId]);
        $db->prepare('DELETE FROM inventory_documents WHERE entity_item_id = :id')->execute(['id' => $itemId]);
        $db->prepare('DELETE FROM inventory_location_balances WHERE item_id = :id')->execute(['id' => $itemId]);
        $db->prepare('DELETE FROM inventory_lifecycle_movements WHERE product_id = :id')->execute(['id' => $itemId]);
        $db->prepare('DELETE FROM inventory_stock_movements WHERE item_id = :id')->execute(['id' => $itemId]);
        $db->prepare('DELETE FROM inventory_stock_items WHERE id = :id')->execute(['id' => $itemId]);
    }
}

echo json_encode(['status' => $errors === [] ? 'passed' : 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
