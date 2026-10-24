<?php
declare(strict_types=1);

require dirname(__DIR__) . '/whatsapp-monitor-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['status' => 'failed', 'errors' => ['db_unavailable']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$run = date('YmdHis') . random_int(100, 999);
$employeeId = 0;
$customerId = 0;
$numberId = 0;
$deviceId = 0;
$caseId = 0;
$ticketId = 0;
$pairingId = 0;
$ticketNo = '';
$deviceCode = 'WA-SMOKE-' . $run;
$subscriberCode = 'WASM' . $run;
$phone = '+99450' . substr($run, -7);
$errors = [];

$superActor = [
    'id' => 1,
    'role' => 'employee',
    'account_type' => 'admin',
    'employee_role' => 'super_admin',
    'full_name' => 'WhatsApp Smoke Super',
    'login' => 's.abdullayev@superoptic.net',
];
$operatorActor = [
    'id' => 0,
    'role' => 'employee',
    'account_type' => 'operator',
    'employee_role' => 'operator',
    'full_name' => 'WhatsApp Smoke Operator',
    'login' => 'wa.operator.' . $run . '@qa.local',
];

$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) {
        $errors[] = $error;
    }
};

try {
    whatsapp_monitor_ensure_schema($db);

    $hash = password_hash('SmokePass123!', PASSWORD_DEFAULT);
    $employeeInsert = $db->prepare(
        'INSERT INTO portal_accounts (login,password_hash,role,account_type,employee_role,full_name,area,status,is_active,created_at)
         VALUES (:login,:password_hash,"employee","operator","operator",:full_name,"Smoke","active",1,NOW())'
    );
    $employeeInsert->execute([
        'login' => (string) $operatorActor['login'],
        'password_hash' => $hash,
        'full_name' => (string) $operatorActor['full_name'],
    ]);
    $employeeId = (int) $db->lastInsertId();
    $operatorActor['id'] = $employeeId;

    $customerInsert = $db->prepare(
        'INSERT INTO portal_accounts (login,password_hash,role,account_type,employee_role,full_name,subscriber_code,contract_no,phone,address,area,plan,status,is_active,created_at)
         VALUES (:login,:password_hash,"customer","customer","",:full_name,:subscriber_code,:contract_no,:phone,:address,"Smoke","Smoke 50","active",1,NOW())'
    );
    $customerInsert->execute([
        'login' => 'wa.customer.' . $run . '@qa.local',
        'password_hash' => $hash,
        'full_name' => 'WhatsApp Smoke Customer ' . $run,
        'subscriber_code' => $subscriberCode,
        'contract_no' => 'WA-C-' . $run,
        'phone' => $phone,
        'address' => 'Smoke test address',
    ]);
    $customerId = (int) $db->lastInsertId();

    $expect(whatsapp_monitor_can_manage($superActor), 'super_admin_cannot_manage');
    $expect(!whatsapp_monitor_can_manage($operatorActor), 'operator_can_manage_admin_tools');
    $expect(whatsapp_monitor_can_view_all($superActor), 'super_admin_cannot_view_all');
    $expect(!whatsapp_monitor_can_view_all($operatorActor), 'operator_can_view_all');

    $tabs = whatsapp_monitor_tabs();
    $expect(isset($tabs['screen-wall'], $tabs['audit']), 'tabs_missing_screen_or_audit');
    $expect(str_contains(whatsapp_monitor_url('devices'), 'whatsapp-monitor.php?view=devices'), 'url_builder_wrong');

    $settings = whatsapp_monitor_settings($db);
    $expect(isset($settings['unanswered_warning_minutes'], $settings['stream_refresh_seconds']), 'default_settings_missing');
    $expect(isset($settings['pairing_qr_ttl_seconds'], $settings['worker_api_token']), 'qr_settings_missing');

    $numberResult = whatsapp_monitor_save_number($db, [
        'employee_id' => $employeeId,
        'phone_number' => $phone,
        'whatsapp_account_name' => 'Smoke WhatsApp',
        'assigned_area_id' => 'Smoke',
        'status' => 'active',
        'is_company_owned' => '1',
        'monitoring_policy_accepted' => '1',
        'policy_version' => '2026-07',
        'notes' => 'smoke number',
    ], $superActor);
    $numberId = (int) ($numberResult['id'] ?? 0);
    $expect(!empty($numberResult['ok']) && $numberId > 0, 'number_save_failed:' . (string) ($numberResult['error'] ?? ''));

    $deniedNumber = whatsapp_monitor_save_number($db, [
        'employee_id' => $employeeId,
        'phone_number' => '+994501234567',
    ], $operatorActor);
    $expect(($deniedNumber['error'] ?? '') === 'permission_denied', 'operator_can_save_number');

    $deviceResult = whatsapp_monitor_save_device($db, [
        'device_name' => 'Smoke Android',
        'device_code' => $deviceCode,
        'employee_id' => $employeeId,
        'phone_number_id' => $numberId,
        'area_id' => 'Smoke',
        'device_type' => 'android',
        'connection_type' => 'stream_url',
        'stream_url' => 'https://example.test/wa-smoke',
        'status' => 'online',
        'monitoring_enabled' => '1',
        'last_error' => '',
        'notes' => 'smoke device',
    ], $superActor);
    $deviceId = (int) ($deviceResult['id'] ?? 0);
    $expect(!empty($deviceResult['ok']) && $deviceId > 0, 'device_save_failed:' . (string) ($deviceResult['error'] ?? ''));

    $pairingResult = whatsapp_monitor_start_pairing($db, $numberId, $deviceId, $superActor);
    $pairingId = (int) ($pairingResult['id'] ?? 0);
    $sessionKey = (string) ($pairingResult['session_key'] ?? '');
    $expect(!empty($pairingResult['ok']) && $pairingId > 0 && $sessionKey !== '', 'pairing_start_failed:' . (string) ($pairingResult['error'] ?? ''));

    $deniedPairing = whatsapp_monitor_start_pairing($db, $numberId, $deviceId, $operatorActor);
    $expect(($deniedPairing['error'] ?? '') === 'permission_denied', 'operator_can_start_pairing');

    $queueBeforeToken = whatsapp_monitor_worker_authorized($db, '');
    $expect(!$queueBeforeToken, 'empty_worker_token_authorized');
    $db->prepare('UPDATE whatsapp_monitor_settings SET setting_value = :token WHERE setting_key = "worker_api_token"')
        ->execute(['token' => 'wa-smoke-token-' . $run]);
    $expect(whatsapp_monitor_worker_authorized($db, 'wa-smoke-token-' . $run), 'worker_token_not_authorized');
    $queue = whatsapp_monitor_pairing_queue($db, 5);
    $queueSessionKeys = array_column($queue, 'session_key');
    $expect(in_array($sessionKey, $queueSessionKeys, true), 'pairing_queue_missing_session');

    $qrReady = whatsapp_monitor_worker_update_pairing($db, [
        'session_key' => $sessionKey,
        'status' => 'qr_ready',
        'qr_payload' => 'smoke-qr-payload',
        'qr_image_data_uri' => 'data:image/png;base64,c21va2U=',
        'worker_id' => 'wa-smoke-worker',
    ]);
    $expect(!empty($qrReady['ok']), 'pairing_qr_ready_failed:' . (string) ($qrReady['error'] ?? ''));
    $connected = whatsapp_monitor_worker_update_pairing($db, [
        'session_key' => $sessionKey,
        'status' => 'connected',
        'worker_id' => 'wa-smoke-worker',
    ]);
    $expect(!empty($connected['ok']), 'pairing_connected_failed:' . (string) ($connected['error'] ?? ''));
    $pairingRows = whatsapp_monitor_pairings($db, $superActor, ['number_id' => $numberId]);
    $expect($pairingRows !== [] && (string) ($pairingRows[0]['pairing_status'] ?? '') === 'connected', 'pairing_status_not_connected');
    $numberQrStatement = $db->prepare('SELECT qr_status, qr_connected_at FROM whatsapp_employee_numbers WHERE id = :id LIMIT 1');
    $numberQrStatement->execute(['id' => $numberId]);
    $numberQr = $numberQrStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $expect(($numberQr['qr_status'] ?? '') === 'connected' && !empty($numberQr['qr_connected_at']), 'number_qr_not_connected');

    $deniedDevice = whatsapp_monitor_save_device($db, [
        'device_name' => 'Denied Android',
        'device_code' => $deviceCode . '-DENIED',
    ], $operatorActor);
    $expect(($deniedDevice['error'] ?? '') === 'permission_denied', 'operator_can_save_device');

    $visibleToOperator = array_filter(
        whatsapp_monitor_devices($db, $operatorActor),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === $deviceId
    );
    $expect(count($visibleToOperator) === 1, 'operator_cannot_see_own_device');

    $caseResult = whatsapp_monitor_create_case($db, [
        'device_id' => $deviceId,
        'case_type' => 'technical',
        'priority' => 'high',
        'notes' => 'Smoke unanswered message',
    ], $operatorActor);
    $caseId = (int) ($caseResult['id'] ?? 0);
    $expect(!empty($caseResult['ok']) && $caseId > 0, 'case_create_failed:' . (string) ($caseResult['error'] ?? ''));

    $statusResult = whatsapp_monitor_update_case_status($db, $caseId, 'replied', $operatorActor);
    $expect(!empty($statusResult['ok']), 'case_status_update_failed:' . (string) ($statusResult['error'] ?? ''));

    $linkResult = whatsapp_monitor_link_customer($db, $caseId, $subscriberCode, $operatorActor);
    $expect(!empty($linkResult['ok']) && (int) ($linkResult['customer']['id'] ?? 0) === $customerId, 'customer_link_failed:' . (string) ($linkResult['error'] ?? ''));

    $ticketResult = whatsapp_monitor_create_ticket($db, $caseId, $operatorActor);
    $ticketNo = (string) ($ticketResult['ticket_no'] ?? '');
    $expect(!empty($ticketResult['ok']) && $ticketNo !== '', 'ticket_create_failed:' . (string) ($ticketResult['error'] ?? ''));

    if ($ticketNo !== '') {
        $ticketStatement = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
        $ticketStatement->execute(['ticket_no' => $ticketNo]);
        $ticketId = (int) ($ticketStatement->fetchColumn() ?: 0);
    }
    $expect($ticketId > 0, 'ticket_row_missing');

    $caseStatement = $db->prepare('SELECT status, response_time_minutes, customer_id, ticket_id FROM whatsapp_monitor_cases WHERE id = :id LIMIT 1');
    $caseStatement->execute(['id' => $caseId]);
    $caseRow = $caseStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $expect(($caseRow['status'] ?? '') === 'replied', 'case_status_not_replied');
    $expect(array_key_exists('response_time_minutes', $caseRow) && $caseRow['response_time_minutes'] !== null, 'case_response_time_missing');
    $expect((int) ($caseRow['customer_id'] ?? 0) === $customerId, 'case_customer_id_wrong');
    $expect((int) ($caseRow['ticket_id'] ?? 0) === $ticketId, 'case_ticket_id_wrong');

    $metrics = whatsapp_monitor_metrics($db, $superActor);
    $expect((int) ($metrics['online_devices'] ?? 0) >= 1, 'metrics_online_devices_missing');
    $expect((int) ($metrics['open_cases'] ?? 0) >= 1, 'metrics_open_cases_missing');

    $auditRows = whatsapp_monitor_audit_rows($db, $superActor);
    $expect($auditRows !== [], 'audit_rows_missing');

    $deniedSettings = whatsapp_monitor_save_settings($db, ['stream_refresh_seconds' => '20'], $operatorActor);
    $expect(($deniedSettings['error'] ?? '') === 'permission_denied', 'operator_can_save_settings');

    $returnResult = whatsapp_monitor_return_number($db, $numberId, $superActor);
    $expect(!empty($returnResult['ok']), 'number_return_failed:' . (string) ($returnResult['error'] ?? ''));
    $revokeResult = whatsapp_monitor_revoke_pairing($db, $numberId, $superActor);
    $expect(!empty($revokeResult['ok']), 'pairing_revoke_failed:' . (string) ($revokeResult['error'] ?? ''));
    $disableResult = whatsapp_monitor_disable_device($db, $deviceId, $superActor);
    $expect(!empty($disableResult['ok']), 'device_disable_failed:' . (string) ($disableResult['error'] ?? ''));

    $configSource = (string) file_get_contents(dirname(__DIR__) . '/config.php');
    $pageSource = (string) file_get_contents(dirname(__DIR__) . '/whatsapp-monitor.php');
    $moduleSource = (string) file_get_contents(dirname(__DIR__) . '/views/whatsapp-monitor/module.php');
    foreach ([
        [$configSource, "'whatsapp_monitor'"],
        [$configSource, "'whatsapp-monitor.php' => 'whatsapp_monitor'"],
        [$pageSource, "billing_require_employee(false, 'whatsapp_monitor')"],
        [$moduleSource, 'data-whatsapp-monitor-module'],
        [$moduleSource, 'Canlı monitor'],
        [$moduleSource, "app_portal_can_access_module(\$user, 'messaging')"],
        [$moduleSource, 'href="message-center.php'],
        [$moduleSource, '<span>Mesaj Mərkəzi</span>'],
        [(string) file_get_contents(dirname(__DIR__) . '/views/whatsapp-monitor/pairing.php'), 'QR ilə WhatsApp bağlantısı'],
        [(string) file_get_contents(dirname(__DIR__) . '/api/whatsapp-monitor/pairing-update.php'), 'whatsapp_monitor_worker_update_pairing'],
    ] as [$source, $marker]) {
        $expect(str_contains($source, $marker), 'source_marker_missing:' . $marker);
    }
} catch (Throwable $exception) {
    $errors[] = 'exception:' . $exception->getMessage();
} finally {
    if ($ticketId > 0) {
        $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :ticket_id')->execute(['ticket_id' => $ticketId]);
        $db->prepare('DELETE FROM portal_tickets WHERE id = :id')->execute(['id' => $ticketId]);
    }
    if ($caseId > 0) {
        $db->prepare('DELETE FROM whatsapp_monitor_cases WHERE id = :id')->execute(['id' => $caseId]);
        $db->prepare('DELETE FROM whatsapp_monitor_audit_logs WHERE entity_type = "case" AND entity_id = :id')->execute(['id' => (string) $caseId]);
    }
    if ($pairingId > 0) {
        $db->prepare('DELETE FROM whatsapp_monitor_pairings WHERE id = :id')->execute(['id' => $pairingId]);
    }
    if ($deviceId > 0) {
        $db->prepare('DELETE FROM whatsapp_monitor_events WHERE device_id = :device_id')->execute(['device_id' => $deviceId]);
        $db->prepare('DELETE FROM whatsapp_monitor_devices WHERE id = :id')->execute(['id' => $deviceId]);
        $db->prepare('DELETE FROM whatsapp_monitor_audit_logs WHERE entity_type = "device" AND entity_id = :id')->execute(['id' => (string) $deviceId]);
    }
    if ($numberId > 0) {
        $db->prepare('DELETE FROM whatsapp_employee_numbers WHERE id = :id')->execute(['id' => $numberId]);
        $db->prepare('DELETE FROM whatsapp_monitor_audit_logs WHERE entity_type = "number" AND entity_id = :id')->execute(['id' => (string) $numberId]);
    }
    if ($employeeId > 0) {
        $db->prepare('DELETE FROM whatsapp_monitor_events WHERE employee_id = :employee_id OR created_by = :employee_id')->execute(['employee_id' => $employeeId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $employeeId]);
    }
    if ($customerId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $customerId]);
    }
    $db->prepare('DELETE FROM whatsapp_monitor_audit_logs WHERE user_id IN (1, :employee_id) AND action LIKE "%_created"')->execute(['employee_id' => $employeeId]);
}

echo json_encode([
    'status' => $errors === [] ? 'passed' : 'failed',
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
