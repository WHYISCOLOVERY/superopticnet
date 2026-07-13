<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$workerPath = dirname(__DIR__) . '/tools/process-radius-session-actions.php';
if (!is_file($workerPath)) {
    fwrite(STDERR, "worker_script_missing\n");
    exit(3);
}

$username = 'SMOKE-DISCONNECT-' . date('YmdHis');
$insert = $db->prepare(
    'INSERT INTO radius_session_actions (
        action_type, status, account_id, username, framed_ip, nas_ip, nas_port_id, calling_station_id, reason, metadata_json
     ) VALUES (
        "disconnect_request", "pending", NULL, :username, :framed_ip, :nas_ip, :nas_port_id, :calling_station_id, :reason, :metadata_json
     )'
);
$insert->execute([
    'username' => $username,
    'framed_ip' => '10.99.99.99',
    'nas_ip' => '10.99.99.1',
    'nas_port_id' => 'smoke-port',
    'calling_station_id' => '00-11-22-33-44-55',
    'reason' => 'smoke disconnect worker',
    'metadata_json' => app_db_json_encode([
        'session' => [
            'acct_session_id' => 'smoke-session-id',
            'nas_identifier' => 'smoke-nas',
        ],
    ]),
]);
$actionId = (int) $db->lastInsertId();

try {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath) . ' --id=' . $actionId . ' --dry-run --json';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "worker_exit:" . $exitCode . "\n" . implode("\n", $output) . "\n");
        exit(4);
    }

    $json = trim(implode("\n", $output));
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "worker_invalid_json\n" . $json . "\n");
        exit(5);
    }

    if (empty($decoded['dry_run'])) {
        fwrite(STDERR, "worker_not_dry_run\n");
        exit(6);
    }

    $actions = is_array($decoded['actions'] ?? null) ? $decoded['actions'] : [];
    if ($actions === []) {
        fwrite(STDERR, "worker_no_actions\n");
        exit(7);
    }

    $first = $actions[0];
    if ((int) ($first['id'] ?? 0) !== $actionId) {
        fwrite(STDERR, "worker_wrong_action\n");
        exit(8);
    }

    $payload = is_array($first['payload'] ?? null) ? $first['payload'] : [];
    if ((string) ($payload['target'] ?? '') === '') {
        fwrite(STDERR, "worker_missing_target\n");
        exit(9);
    }

    $attributes = (string) ($payload['attributes'] ?? '');
    foreach (['User-Name = "', 'NAS-IP-Address = 10.99.99.1', 'Acct-Session-Id = "smoke-session-id"'] as $marker) {
        if (!str_contains($attributes, $marker)) {
            fwrite(STDERR, 'worker_missing_attribute:' . $marker . "\n");
            exit(10);
        }
    }

    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $db->prepare('DELETE FROM radius_session_actions WHERE id = :id')->execute(['id' => $actionId]);
}
