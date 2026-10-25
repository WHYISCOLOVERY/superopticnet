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

$settingKey = 'monitor_management_vlan_connected';
$previous = null;
$previousExists = false;
$maxActionId = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM mgs_operation_actions')->fetchColumn();
$ticketBefore = (int) $db->query('SELECT COUNT(*) FROM portal_tickets')->fetchColumn();
$messageBefore = (int) $db->query('SELECT COUNT(*) FROM communication_messages')->fetchColumn();
$nocActor = app_noc_system_actor('olt-rule-worker');

try {
    $statement = $db->prepare('SELECT setting_value FROM mgs_runtime_settings WHERE setting_key = :setting_key LIMIT 1');
    $statement->execute(['setting_key' => $settingKey]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $previousExists = true;
        $previous = (string) ($row['setting_value'] ?? '0');
    }

    $db->prepare(
        'INSERT INTO mgs_runtime_settings (setting_key, setting_value, updated_by_name)
         VALUES (:setting_key, "0", "smoke-test")
         ON DUPLICATE KEY UPDATE setting_value = "0", updated_by_name = "smoke-test"'
    )->execute(['setting_key' => $settingKey]);

    $result = app_mgs_analyze_olt_ticket_rules([
        'test_mode' => false,
        'create_tickets' => true,
        'queue_messages' => true,
        'require_management_path' => true,
    ], $db, $nocActor);

    $ticketAfter = (int) $db->query('SELECT COUNT(*) FROM portal_tickets')->fetchColumn();
    $messageAfter = (int) $db->query('SELECT COUNT(*) FROM communication_messages')->fetchColumn();
    $ok = (string) ($result['mode'] ?? '') === 'skipped'
        && (string) ($result['reason'] ?? '') === 'management_path_down'
        && $ticketAfter === $ticketBefore
        && $messageAfter === $messageBefore;

    echo json_encode([
        'status' => $ok ? 'ok' : 'failed',
        'mode' => $result['mode'] ?? null,
        'reason' => $result['reason'] ?? null,
        'tickets_delta' => $ticketAfter - $ticketBefore,
        'messages_delta' => $messageAfter - $messageBefore,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($ok ? 0 : 3);
} finally {
    if ($previousExists) {
        $db->prepare(
            'INSERT INTO mgs_runtime_settings (setting_key, setting_value, updated_by_name)
             VALUES (:setting_key, :setting_value, "smoke-restore")
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_name = "smoke-restore"'
        )->execute(['setting_key' => $settingKey, 'setting_value' => $previous]);
    } else {
        $db->prepare('DELETE FROM mgs_runtime_settings WHERE setting_key = :setting_key')->execute(['setting_key' => $settingKey]);
    }

    $db->prepare(
        'DELETE FROM mgs_operation_actions
         WHERE id > :max_id AND action_type = "olt_ticket_rule_scan"'
    )->execute(['max_id' => $maxActionId]);
}
