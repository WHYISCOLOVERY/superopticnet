<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$employee = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'employee' AND is_active = 1
     ORDER BY FIELD(account_type, 'admin', 'operator', 'technician'), id ASC
     LIMIT 1"
)?->fetch(PDO::FETCH_ASSOC);
if (is_array($employee) && !app_portal_can_access_module(app_db_user_session_payload($employee), 'messaging')) {
    $employee = null;
    foreach (app_db_accounts(['role' => 'employee']) as $candidate) {
        if (is_array($candidate)
            && (int) ($candidate['is_active'] ?? 0) === 1
            && app_portal_can_access_module(app_db_user_session_payload($candidate), 'messaging')) {
            $employee = $candidate;
            break;
        }
    }
}
if (!is_array($employee)) {
    fwrite(STDERR, "missing_employee_account\n");
    exit(3);
}

$dedupKey = 'smoke-network-message-separation-' . date('His') . '-' . bin2hex(random_bytes(3));

try {
    $alarm = app_mgs_record_alarm_event([
        'dedup_key' => $dedupKey,
        'alarm_type' => 'DEVICE_OFFLINE',
        'severity' => 'critical',
        'source' => 'smoke_network',
        'object_type' => 'device',
        'object_id' => 'smoke-device',
        'device_name' => 'SMOKE NETWORK DEVICE',
        'device_ip' => '172.16.5.250',
        'title' => 'SMOKE NETWORK DEVICE offline',
        'human_problem' => 'Network-only alarm must not appear as a customer message.',
        'recommendation' => 'NOC audit bolmesinde saxla.',
        'telegram_bot_key' => 'network',
    ], false, true, app_noc_system_actor('olt-rule-worker'), $db);
    if (empty($alarm['ok'])) {
        throw new RuntimeException('alarm_not_recorded');
    }

    $recentNeedleFound = false;
    foreach (app_message_recent(20) as $message) {
        if (is_array($message) && str_contains((string) ($message['message_body'] ?? ''), 'SMOKE NETWORK DEVICE')) {
            $recentNeedleFound = true;
            break;
        }
    }

    app_session_boot();
    $_SESSION = [];
    $_SESSION['portal_user'] = app_db_user_session_payload($employee);

    $_GET = ['view' => 'history'];
    $_POST = [];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/superopticnet/message-center.php?view=history';
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/message-center.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    ob_start();
    include dirname(__DIR__) . '/message-center.php';
    $html = (string) ob_get_clean();

    $htmlNeedleFound = str_contains($html, 'SMOKE NETWORK DEVICE');
    $ok = !$recentNeedleFound && !$htmlNeedleFound;

    echo json_encode([
        'status' => $ok ? 'ok' : 'failed',
        'recent_contains_network_alarm' => $recentNeedleFound,
        'history_html_contains_network_alarm' => $htmlNeedleFound,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($ok ? 0 : 4);
} finally {
    $db->prepare('DELETE FROM communication_messages WHERE metadata_json LIKE :needle')->execute(['needle' => '%' . $dedupKey . '%']);
    $db->prepare('DELETE FROM mgs_alarm_events WHERE dedup_key = :dedup')->execute(['dedup' => $dedupKey]);
    $db->prepare('DELETE FROM mgs_dedup_registry WHERE dedup_key = :dedup')->execute(['dedup' => $dedupKey]);
    $db->prepare('DELETE FROM mgs_operation_actions WHERE entity_id = :dedup OR payload_json LIKE :needle')->execute([
        'dedup' => $dedupKey,
        'needle' => '%' . $dedupKey . '%',
    ]);
}
