<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function operational_e2e_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    operational_e2e_fail('Database unavailable', 2);
}

$employee = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'employee' AND is_active = 1
     ORDER BY FIELD(account_type, 'admin', 'operator', 'technician'), id ASC
     LIMIT 1"
)?->fetch();
if (!is_array($employee)) {
    operational_e2e_fail('No active employee account', 3);
}

$tariff = $db->query(
    'SELECT *
     FROM billing_tariffs
     WHERE status = "active" AND service_type IN ("pppoe", "ipoe")
     ORDER BY monthly_fee, id
     LIMIT 1'
)?->fetch();
if (!is_array($tariff)) {
    operational_e2e_fail('No active billing tariff', 4);
}

$suffix = date('His') . '-' . strtolower(bin2hex(random_bytes(2)));
$subscriberCode = 'SMOKE-OPS-' . $suffix;
$sku = 'SMOKE-SKU-' . $suffix;
$ticketNo = 'SMOKE-TICKET-' . $suffix;
$password = 'OpsSmoke!42';
$accountId = 0;
$actionId = 0;

try {
    $payload = [
        'profile' => [
            'subscriber_code' => $subscriberCode,
            'contract_no' => 'SO-MQ-' . $subscriberCode,
            'full_name' => 'Operational E2E Smoke',
            'phone' => '+994509990000',
            'area' => 'Smoke zona',
            'address' => 'Smoke unvan',
        ],
        'billing' => [
            'service_type' => (string) ($tariff['service_type'] ?? 'pppoe'),
            'tariff' => (string) ($tariff['name'] ?? ''),
            'pppoe_username' => $subscriberCode,
            'pppoe_password' => $password,
            'status' => 'Aktiv',
            'radius_status' => 'Aktiv',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'default_days' => app_billing_tariff_default_days($tariff),
        ],
        'internet_services' => [[
            'service_type' => (string) ($tariff['service_type'] ?? 'pppoe'),
            'username' => $subscriberCode,
            'password' => $password,
            'tariff' => (string) ($tariff['name'] ?? ''),
            'status' => 'active',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]],
    ];

    $created = app_db_upsert_account([
        'login' => strtolower($subscriberCode),
        'password' => $password,
        'role' => 'customer',
        'account_type' => 'customer',
        'full_name' => 'Operational E2E Smoke',
        'subscriber_code' => $subscriberCode,
        'contract_no' => 'SO-MQ-' . $subscriberCode,
        'phone' => '+994509990000',
        'area' => 'Smoke zona',
        'address' => 'Smoke unvan',
        'plan' => (string) ($tariff['name'] ?? ''),
        'status' => 'Aktiv',
        'is_active' => 1,
        'payload' => $payload,
    ], false);
    if (!is_array($created)) {
        operational_e2e_fail('Customer create failed', 5);
    }
    $accountId = (int) ($created['id'] ?? 0);

    $loginOk = is_array(app_portal_login('customer', strtolower($subscriberCode), $password));
    $updated = app_db_upsert_account([
        'id' => $accountId,
        'login' => strtolower($subscriberCode),
        'role' => 'customer',
        'account_type' => 'customer',
        'full_name' => 'Operational E2E Smoke Updated',
        'subscriber_code' => $subscriberCode,
        'contract_no' => 'SO-MQ-' . $subscriberCode,
        'phone' => '+994509990001',
        'area' => 'Smoke zona 2',
        'address' => 'Smoke unvan update',
        'plan' => (string) ($tariff['name'] ?? ''),
        'status' => 'Aktiv',
        'is_active' => 1,
        'payload' => $payload + ['updated_by_smoke' => true],
    ], false);
    if (!is_array($updated) || (string) ($updated['full_name'] ?? '') !== 'Operational E2E Smoke Updated') {
        operational_e2e_fail('Customer update failed', 6);
    }

    if (!app_billing_sync_radius_account($updated, $password)) {
        operational_e2e_fail('RADIUS sync failed', 7);
    }
    $radcheckStatement = $db->prepare('SELECT COUNT(*) FROM radcheck WHERE username = :username');
    $radcheckStatement->execute(['username' => $subscriberCode]);
    $radcheckRows = (int) $radcheckStatement->fetchColumn();
    if ($radcheckRows <= 0) {
        operational_e2e_fail('RADIUS radcheck rows missing', 8);
    }

    $ticketInserted = app_db_insert_ticket([
        'ticket_no' => $ticketNo,
        'customer_account_id' => $accountId,
        'subscriber_code' => $subscriberCode,
        'contract_no' => 'SO-MQ-' . $subscriberCode,
        'customer_name' => 'Operational E2E Smoke Updated',
        'phone' => '+994509990001',
        'address' => 'Smoke unvan update',
        'area' => 'Smoke zona 2',
        'subject' => 'Internet sureti problemi',
        'message' => 'Operational E2E smoke ticket yaradildi.',
        'status' => 'Aciqdir',
        'opened_at' => app_db_now(),
        'updated_at' => app_db_now(),
        'assigned_technician_account_id' => (int) ($employee['id'] ?? 0),
        'assigned_technician_name' => (string) ($employee['full_name'] ?? 'Smoke technician'),
        'created_by_account_id' => (int) ($employee['id'] ?? 0),
        'created_by_role' => 'employee',
        'created_by_name' => (string) ($employee['full_name'] ?? 'Smoke operator'),
        'metadata_json' => app_db_json_encode(['smoke' => true]),
    ]);
    if (!$ticketInserted) {
        operational_e2e_fail('Ticket create failed', 9);
    }

    $stockIn = app_mgs_record_inventory_movement([
        'sku' => $sku,
        'item_name' => 'Smoke Test Modem',
        'category' => 'modem',
        'unit' => 'eded',
        'quantity' => 5,
        'movement_type' => 'in',
        'ticket_no' => $ticketNo,
        'actor_account_id' => (int) ($employee['id'] ?? 0),
        'actor_name' => (string) ($employee['full_name'] ?? 'Smoke operator'),
        'note' => 'Smoke medaxil',
    ], true);
    $stockIssue = app_mgs_record_inventory_movement([
        'sku' => $sku,
        'item_name' => 'Smoke Test Modem',
        'category' => 'modem',
        'unit' => 'eded',
        'quantity' => 2,
        'movement_type' => 'issue',
        'ticket_no' => $ticketNo,
        'customer_account_id' => $accountId,
        'technician_account_id' => (int) ($employee['id'] ?? 0),
        'actor_account_id' => (int) ($employee['id'] ?? 0),
        'actor_name' => (string) ($employee['full_name'] ?? 'Smoke operator'),
        'note' => 'Smoke texnike verildi',
    ], true);
    $stockReturn = app_mgs_record_inventory_movement([
        'sku' => $sku,
        'item_name' => 'Smoke Test Modem',
        'category' => 'modem',
        'unit' => 'eded',
        'quantity' => 1,
        'movement_type' => 'return',
        'ticket_no' => $ticketNo,
        'customer_account_id' => $accountId,
        'technician_account_id' => (int) ($employee['id'] ?? 0),
        'actor_account_id' => (int) ($employee['id'] ?? 0),
        'actor_name' => (string) ($employee['full_name'] ?? 'Smoke operator'),
        'note' => 'Smoke geri alindi',
    ], true);
    if (empty($stockIn['ok']) || empty($stockIssue['ok']) || empty($stockReturn['ok'])) {
        operational_e2e_fail('Inventory in/issue/return failed', 10);
    }

    $stockStatement = $db->prepare('SELECT id, quantity_on_hand FROM inventory_stock_items WHERE sku = :sku LIMIT 1');
    $stockStatement->execute(['sku' => $sku]);
    $stockItem = $stockStatement->fetch();
    if (!is_array($stockItem) || (float) ($stockItem['quantity_on_hand'] ?? 0) !== 4.0) {
        operational_e2e_fail('Inventory balance mismatch', 11);
    }
    $itemId = (int) ($stockItem['id'] ?? 0);

    $closed = app_db_close_ticket($ticketNo, 'Smoke hell edildi', 'Operational E2E smoke ticket baglandi.');
    if (!$closed) {
        operational_e2e_fail('Ticket close failed', 12);
    }
    $closedTicket = app_db_ticket_find($ticketNo);
    if (!is_array($closedTicket) || (string) ($closedTicket['status'] ?? '') !== 'Baglandi') {
        operational_e2e_fail('Ticket close status mismatch', 13);
    }

    $actionId = app_radius_request_disconnect($db, [
        'username' => $subscriberCode,
        'framed_ip' => '10.250.250.10',
        'nas_ip' => '172.16.5.5',
        'nas_port_id' => 'smoke-nas-port',
        'calling_station_id' => '00-0C-29-AA-BB-CC',
        'acct_session_id' => 'smoke-acct-session',
    ], $updated, 'operational e2e refresh/disconnect');
    if ($actionId <= 0) {
        operational_e2e_fail('RADIUS disconnect queue failed', 14);
    }
    $actionStatement = $db->prepare('SELECT * FROM radius_session_actions WHERE id = :id LIMIT 1');
    $actionStatement->execute(['id' => $actionId]);
    $actionRow = $actionStatement->fetch();
    if (!is_array($actionRow) || (string) ($actionRow['status'] ?? '') !== 'pending') {
        operational_e2e_fail('RADIUS action pending status mismatch', 15);
    }
    $payloadCoa = app_radius_disconnect_payload($actionRow);
    if (
        trim((string) ($payloadCoa['target'] ?? '')) === ''
        || !str_contains((string) ($payloadCoa['attributes'] ?? ''), 'User-Name = "' . $subscriberCode . '"')
        || !str_contains((string) ($payloadCoa['attributes'] ?? ''), 'Acct-Session-Id = "smoke-acct-session"')
    ) {
        operational_e2e_fail('RADIUS disconnect payload invalid', 16);
    }

    $messageQueued = app_message_queue_for_account($updated, 'payment_received', 'sms', 'Smoke mesaj: {{customer_code}} {{customer_name}}', [
        'id' => (int) ($employee['id'] ?? 0),
        'name' => (string) ($employee['full_name'] ?? 'Smoke operator'),
    ], true);
    if (!$messageQueued) {
        operational_e2e_fail('Message queue test failed', 17);
    }

    $snapshot = app_mgs_rebuild_employee_work_snapshots($db);
    if (empty($snapshot['ok'])) {
        operational_e2e_fail('Employee KPI snapshot rebuild failed', 18);
    }

    $ticketIdStatement = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
    $ticketIdStatement->execute(['ticket_no' => $ticketNo]);
    $ticketId = (int) ($ticketIdStatement->fetchColumn() ?: 0);
    if ($ticketId > 0) {
        $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :id')->execute(['id' => $ticketId]);
        $db->prepare('DELETE FROM portal_tickets WHERE id = :id')->execute(['id' => $ticketId]);
    }
    $db->prepare('DELETE FROM inventory_stock_movements WHERE item_id = :item_id')->execute(['item_id' => $itemId]);
    $db->prepare('DELETE FROM inventory_stock_items WHERE id = :id')->execute(['id' => $itemId]);
    $db->prepare('DELETE FROM radius_session_actions WHERE id = :id')->execute(['id' => $actionId]);
    $db->prepare('DELETE FROM radcheck WHERE username = :username')->execute(['username' => $subscriberCode]);
    $db->prepare('DELETE FROM radreply WHERE username = :username')->execute(['username' => $subscriberCode]);
    $db->prepare('DELETE FROM radusergroup WHERE username = :username')->execute(['username' => $subscriberCode]);
    $deleted = app_db_delete_account($accountId);
    if (!$deleted || app_db_account_by_id($accountId) !== null) {
        operational_e2e_fail('Customer delete failed', 19);
    }
    $accountId = 0;

    echo json_encode([
        'status' => 'ok',
        'checks' => [
            'customer_create' => true,
            'customer_login' => $loginOk,
            'customer_update' => true,
            'customer_delete' => true,
            'ticket_create_close' => true,
            'inventory_create_in_issue_return' => true,
            'radius_sync_and_disconnect_payload' => true,
            'message_queue_test_mode' => true,
            'employee_kpi_snapshot' => true,
        ],
        'subscriber_code' => $subscriberCode,
        'ticket_no' => $ticketNo,
        'sku' => $sku,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $ticketId = 0;
    if ($ticketNo !== '') {
        $statement = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
        $statement->execute(['ticket_no' => $ticketNo]);
        $ticketId = (int) ($statement->fetchColumn() ?: 0);
    }
    if ($ticketId > 0) {
        $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :id')->execute(['id' => $ticketId]);
        $db->prepare('DELETE FROM portal_tickets WHERE id = :id')->execute(['id' => $ticketId]);
    }
    $itemStatement = $db->prepare('SELECT id FROM inventory_stock_items WHERE sku = :sku LIMIT 1');
    $itemStatement->execute(['sku' => $sku]);
    $itemId = (int) ($itemStatement->fetchColumn() ?: 0);
    if ($itemId > 0) {
        $db->prepare('DELETE FROM inventory_stock_movements WHERE item_id = :item_id')->execute(['item_id' => $itemId]);
        $db->prepare('DELETE FROM inventory_stock_items WHERE id = :id')->execute(['id' => $itemId]);
    }
    if ($actionId > 0) {
        $db->prepare('DELETE FROM radius_session_actions WHERE id = :id')->execute(['id' => $actionId]);
    }
    $db->prepare('DELETE FROM radcheck WHERE username = :username')->execute(['username' => $subscriberCode]);
    $db->prepare('DELETE FROM radreply WHERE username = :username')->execute(['username' => $subscriberCode]);
    $db->prepare('DELETE FROM radusergroup WHERE username = :username')->execute(['username' => $subscriberCode]);
    if ($accountId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}
