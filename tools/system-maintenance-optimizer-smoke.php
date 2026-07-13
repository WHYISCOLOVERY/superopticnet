<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function maintenance_smoke_out(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    maintenance_smoke_out(['status' => 'skipped', 'reason' => 'db_unavailable']);
}

$marker = 'maintenance-smoke-' . date('His') . random_int(100, 999);
$root = dirname(__DIR__);
$tool = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'system-maintenance-optimizer.php';

try {
    $db->prepare(
        'INSERT INTO communication_messages (
            recipient_name, destination, channel, fallback_chain, template_code, message_body, status, metadata_json, created_at
         ) VALUES (
            "Maintenance Smoke", "994500000000", "sms", "sms", "maintenance_smoke", :body, "test", :metadata, DATE_SUB(NOW(), INTERVAL 400 DAY)
         )'
    )->execute([
        'body' => $marker,
        'metadata' => app_db_json_encode(['maintenance_smoke' => $marker]),
    ]);

    app_mgs_record_operation_action([
        'action_type' => 'maintenance_smoke',
        'entity_type' => 'test',
        'entity_id' => $marker,
        'status' => 'dry_run',
        'summary' => 'old maintenance smoke row',
        'payload' => ['maintenance_smoke' => $marker],
    ]);
    $db->prepare('UPDATE mgs_operation_actions SET created_at = DATE_SUB(NOW(), INTERVAL 400 DAY) WHERE action_type = "maintenance_smoke" AND entity_id = :marker')
        ->execute(['marker' => $marker]);

    if (!is_file($tool)) {
        maintenance_smoke_out(['status' => 'failed', 'reason' => 'optimizer_tool_missing', 'tool' => $tool], 3);
    }

    $dryCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' --json';
    exec($dryCommand . ' 2>&1', $dryOutput, $dryCode);
    $dryJson = json_decode(implode("\n", $dryOutput), true);
    if ($dryCode !== 0 || !is_array($dryJson) || ($dryJson['status'] ?? '') !== 'ok') {
        maintenance_smoke_out(['status' => 'failed', 'reason' => 'dry_run_failed', 'output' => $dryOutput, 'code' => $dryCode], 4);
    }

    $messageStillThere = (int) $db->query('SELECT COUNT(*) FROM communication_messages WHERE message_body = ' . $db->quote($marker))->fetchColumn();
    $actionStillThere = (int) $db->query('SELECT COUNT(*) FROM mgs_operation_actions WHERE action_type = "maintenance_smoke" AND entity_id = ' . $db->quote($marker))->fetchColumn();
    if ($messageStillThere !== 1 || $actionStillThere !== 1) {
        maintenance_smoke_out(['status' => 'failed', 'reason' => 'dry_run_deleted_data'], 5);
    }

    $applyCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' --json --apply';
    exec($applyCommand . ' 2>&1', $applyOutput, $applyCode);
    $applyJson = json_decode(implode("\n", $applyOutput), true);
    if ($applyCode !== 0 || !is_array($applyJson) || ($applyJson['status'] ?? '') !== 'ok') {
        maintenance_smoke_out(['status' => 'failed', 'reason' => 'apply_failed', 'output' => $applyOutput, 'code' => $applyCode], 6);
    }

    $messageDeleted = (int) $db->query('SELECT COUNT(*) FROM communication_messages WHERE message_body = ' . $db->quote($marker))->fetchColumn() === 0;
    $actionDeleted = (int) $db->query('SELECT COUNT(*) FROM mgs_operation_actions WHERE action_type = "maintenance_smoke" AND entity_id = ' . $db->quote($marker))->fetchColumn() === 0;
    $indexChecks = (int) ($applyJson['summary']['index_checks'] ?? 0);

    $ok = $messageDeleted && $actionDeleted && $indexChecks >= 5;
    maintenance_smoke_out([
        'status' => $ok ? 'ok' : 'failed',
        'marker' => $marker,
        'dry_run_mode' => (string) ($dryJson['mode'] ?? ''),
        'apply_mode' => (string) ($applyJson['mode'] ?? ''),
        'message_deleted' => $messageDeleted,
        'action_deleted' => $actionDeleted,
        'index_checks' => $indexChecks,
        'retention_deleted' => $applyJson['summary']['retention_deleted'] ?? null,
    ], $ok ? 0 : 7);
} finally {
    $db->prepare('DELETE FROM communication_messages WHERE message_body = :marker')->execute(['marker' => $marker]);
    $db->prepare('DELETE FROM mgs_operation_actions WHERE action_type = "maintenance_smoke" AND entity_id = :marker')->execute(['marker' => $marker]);
}
