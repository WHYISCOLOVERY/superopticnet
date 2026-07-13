<?php
declare(strict_types=1);

require dirname(__DIR__) . '/copilot-agent.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function copilot_agent_smoke_out(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    copilot_agent_smoke_out(['status' => 'skipped', 'reason' => 'db_unavailable']);
}

$stamp = date('His') . random_int(100, 999);
$customerCode = 'CP-SMOKE-' . $stamp;
$employeeLogin = 'cp-tech-' . $stamp;
$sku = 'CP-MODEM-' . $stamp;
$accountId = 0;
$employeeId = 0;
$itemId = 0;
$createdActionIds = [];
$ticketPrefix = 'CP-';

try {
    $insertCustomer = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, phone, area, address, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", "Copilot Agent Customer", :subscriber_code, "0500000002", "Smoke zona", "Smoke unvan", "Smoke 100", "Aktiv", 1, :payload_json
         )'
    );
    $insertCustomer->execute([
        'login' => $customerCode,
        'password_hash' => password_hash('smoke-pass', PASSWORD_DEFAULT),
        'subscriber_code' => $customerCode,
        'payload_json' => app_db_json_encode([
            'billing' => ['pppoe_username' => $customerCode, 'status' => 'Aktiv', 'balance' => '0 AZN'],
            'ont' => ['serial' => 'CPONT' . $stamp, 'rx_power' => '-28.5', 'temperature' => '69'],
        ]),
    ]);
    $accountId = (int) $db->lastInsertId();

    $insertEmployee = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, employee_role, full_name, status, is_active
         ) VALUES (
            :login, :password_hash, "employee", "technician", "technician", "Copilot Texnik ' . $stamp . '", "Aktiv", 1
         )'
    );
    $insertEmployee->execute([
        'login' => $employeeLogin,
        'password_hash' => password_hash('smoke-pass', PASSWORD_DEFAULT),
    ]);
    $employeeId = (int) $db->lastInsertId();
    $employee = app_db_account_by_id($employeeId);
    if (!is_array($employee)) {
        throw new RuntimeException('employee_create_failed');
    }

    $db->prepare(
        'INSERT INTO inventory_stock_items (sku, item_name, category, unit, quantity_on_hand, minimum_quantity)
         VALUES (:sku, "Copilot smoke modem", "modem", "eded", 5, 0)'
    )->execute(['sku' => $sku]);
    $itemId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO inventory_stock_movements (
            item_id, movement_type, quantity, customer_account_id, technician_account_id, actor_name, note, status, metadata_json
         ) VALUES (
            :item_id, "issue", 1, :customer_id, :employee_id, "smoke", :note, "posted", :metadata_json
         )'
    )->execute([
        'item_id' => $itemId,
        'customer_id' => $accountId,
        'employee_id' => $employeeId,
        'note' => 'SN=' . $sku . ' MAC=AA:BB:CC:DD:EE:FF',
        'metadata_json' => app_db_json_encode(['serialNumber' => $sku, 'macAddress' => 'AA:BB:CC:DD:EE:FF']),
    ]);

    $actor = [
        'id' => $employeeId,
        'full_name' => (string) ($employee['full_name'] ?? 'Copilot Texnik'),
        'telegram_user_id' => 'smoke-user-' . $stamp,
        'telegram_username' => 'smoke',
        'telegram_chat_id' => 'smoke-chat',
    ];

    $customerAnswer = app_copilot_agent_handle_message($customerCode . ' internet get gel problemi var', $actor, $db);
    $trafficAnswer = app_copilot_agent_handle_message('bos trafik saatlari ve traffic monetization kampaniya teklifleri hansilardir?', $actor, $db);
    $inventoryAnswer = app_copilot_agent_handle_message($sku . ' kimdedir', $actor, $db);
    $employeeInventoryAnswer = app_copilot_agent_handle_message('Copilot Texnik ' . $stamp . ' adinda hansi mallar var', $actor, $db);
    $helpPrompts = [
        's' . "\u{0259}" . 'n hans' . "\u{0131}" . ' suallara cavab verirs' . "\u{0259}" . 'n?',
        'Sualla cavab verirs' . "\u{0259}" . 'n',
        'n' . "\u{0259}" . ' ed' . "\u{0259}" . ' bilirs' . "\u{0259}" . 'n',
    ];
    $helpAnswers = [];
    foreach ($helpPrompts as $helpPrompt) {
        $helpAnswers[] = app_copilot_agent_handle_message($helpPrompt, $actor, $db);
    }

    $ticketPending = app_copilot_agent_handle_message($customerCode . ' ucun bilet ac internet yoxdur', $actor, $db);
    $createdActionIds[] = (int) ($ticketPending['action_id'] ?? 0);
    $ticketConfirm = app_copilot_agent_handle_message('TESDIQ ' . (int) ($ticketPending['action_id'] ?? 0), $actor, $db);

    $radiusPending = app_copilot_agent_handle_message($customerCode . ' radius yenile', $actor, $db);
    $createdActionIds[] = (int) ($radiusPending['action_id'] ?? 0);
    $radiusConfirm = app_copilot_agent_handle_message('TESDIQ ' . (int) ($radiusPending['action_id'] ?? 0), $actor, $db);

    $inventoryPending = app_copilot_agent_handle_message($sku . ' mal ver Copilot Texnik ' . $stamp, $actor, $db);
    $createdActionIds[] = (int) ($inventoryPending['action_id'] ?? 0);
    $inventoryCancel = app_copilot_agent_handle_message('LEGV ' . (int) ($inventoryPending['action_id'] ?? 0), $actor, $db);

    $ontPending = app_copilot_agent_handle_message('kurdexani 21 PON03 ONT032 restart et', $actor, $db);
    $createdActionIds[] = (int) ($ontPending['action_id'] ?? 0);
    $ontCancel = app_copilot_agent_handle_message('LEGV ' . (int) ($ontPending['action_id'] ?? 0), $actor, $db);

    $ticketCount = (int) $db->query('SELECT COUNT(*) FROM portal_tickets WHERE customer_account_id = ' . $accountId . ' AND ticket_no LIKE "CP-%"')->fetchColumn();
    $radiusActionCount = (int) $db->query('SELECT COUNT(*) FROM radius_session_actions WHERE account_id = ' . $accountId)->fetchColumn();

    $ok = !empty($customerAnswer['ok'])
        && str_contains((string) ($customerAnswer['text'] ?? ''), 'Customer Activity Intelligence')
        && !empty($trafficAnswer['ok'])
        && str_contains((string) ($trafficAnswer['text'] ?? ''), 'AI Revenue Optimization')
        && !empty($inventoryAnswer['ok'])
        && str_contains((string) ($inventoryAnswer['text'] ?? ''), 'Copilot Texnik')
        && !empty($employeeInventoryAnswer['ok'])
        && count(array_filter($helpAnswers, static fn(array $answer): bool => !empty($answer['ok'])
            && str_contains((string) ($answer['text'] ?? ''), 'Anbar ve emekdas')
            && str_contains((string) ($answer['text'] ?? ''), 'NOC ve analiz'))) === count($helpPrompts)
        && !empty($ticketPending['ok'])
        && !empty($ticketConfirm['ok'])
        && $ticketCount >= 1
        && !empty($radiusPending['ok'])
        && !empty($radiusConfirm['ok'])
        && $radiusActionCount >= 1
        && !empty($inventoryPending['ok'])
        && !empty($inventoryCancel['ok'])
        && !empty($ontPending['ok'])
        && !empty($ontCancel['ok']);

    copilot_agent_smoke_out([
        'status' => $ok ? 'ok' : 'failed',
        'customer_code' => $customerCode,
        'customer_intelligence_ok' => str_contains((string) ($customerAnswer['text'] ?? ''), 'Customer Activity Intelligence'),
        'traffic_monetization_ok' => !empty($trafficAnswer['ok']),
        'inventory_query_ok' => !empty($inventoryAnswer['ok']),
        'employee_inventory_ok' => !empty($employeeInventoryAnswer['ok']),
        'help_answer_ok' => count(array_filter($helpAnswers, static fn(array $answer): bool => !empty($answer['ok']))) === count($helpPrompts),
        'help_prompt_count' => count($helpPrompts),
        'ticket_action' => [
            'pending_id' => (int) ($ticketPending['action_id'] ?? 0),
            'confirmed' => !empty($ticketConfirm['ok']),
            'ticket_count' => $ticketCount,
        ],
        'radius_action' => [
            'pending_id' => (int) ($radiusPending['action_id'] ?? 0),
            'confirmed' => !empty($radiusConfirm['ok']),
            'queue_count' => $radiusActionCount,
        ],
        'inventory_action' => [
            'pending_id' => (int) ($inventoryPending['action_id'] ?? 0),
            'cancelled' => !empty($inventoryCancel['ok']),
        ],
        'ont_control_action' => [
            'pending_id' => (int) ($ontPending['action_id'] ?? 0),
            'cancelled' => !empty($ontCancel['ok']),
        ],
    ], $ok ? 0 : 4);
} catch (Throwable $exception) {
    copilot_agent_smoke_out(['status' => 'failed', 'reason' => $exception->getMessage()], 2);
} finally {
    foreach (array_filter($createdActionIds) as $actionId) {
        $db->prepare('DELETE FROM mgs_operation_actions WHERE id = :id')->execute(['id' => (int) $actionId]);
    }
    if ($accountId > 0) {
        $ticketRows = $db->prepare('SELECT id FROM portal_tickets WHERE customer_account_id = :account_id AND ticket_no LIKE :prefix');
        $ticketRows->execute(['account_id' => $accountId, 'prefix' => $ticketPrefix . '%']);
        foreach ($ticketRows->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ticketId) {
            $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :ticket_id')->execute(['ticket_id' => (int) $ticketId]);
            $db->prepare('DELETE FROM portal_tickets WHERE id = :id')->execute(['id' => (int) $ticketId]);
        }
        $db->prepare('DELETE FROM radius_session_actions WHERE account_id = :account_id')->execute(['account_id' => $accountId]);
    }
    if ($itemId > 0) {
        $db->prepare('DELETE FROM inventory_stock_movements WHERE item_id = :item_id')->execute(['item_id' => $itemId]);
        $db->prepare('DELETE FROM inventory_stock_items WHERE id = :id')->execute(['id' => $itemId]);
    }
    if ($accountId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
    if ($employeeId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $employeeId]);
    }
}
