<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function radius_live_coa_fail(string $message, array $extra = [], int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
        'extra' => $extra,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($code);
}

function radius_live_coa_option(string $name, array $argv): bool
{
    return in_array('--' . $name, $argv, true);
}

$db = app_db();
if (!$db instanceof PDO) {
    radius_live_coa_fail('db_unavailable', [], 2);
}

$settings = app_radius_settings();
$readiness = app_radius_disconnect_worker_readiness($settings);
if (empty($readiness['ok'])) {
    radius_live_coa_fail('radius_worker_not_ready', ['reason' => $readiness['reason'] ?? 'unknown'], 3);
}

$apply = radius_live_coa_option('apply', $argv);
$autoApprove = radius_live_coa_option('auto-approve', $argv);
$username = 'SMOKE-LIVE-COA-' . date('YmdHis');
$insert = $db->prepare(
    'INSERT INTO radius_session_actions (
        action_type, status, account_id, username, framed_ip, nas_ip, nas_port_id,
        calling_station_id, reason, requested_by_account_id, metadata_json
     ) VALUES (
        "disconnect_request", "pending", NULL, :username, :framed_ip, :nas_ip, :nas_port_id,
        :calling_station_id, :reason, NULL, :metadata_json
     )'
);
$insert->execute([
    'username' => $username,
    'framed_ip' => '10.255.255.250',
    'nas_ip' => (string) ($settings['core_nas_ip'] ?? '172.16.5.5'),
    'nas_port_id' => 'smoke-live-coa',
    'calling_station_id' => '00-00-5E-00-53-FA',
    'reason' => 'live fake CoA transport smoke',
    'metadata_json' => app_db_json_encode([
        'source' => 'radius-live-coa-smoke',
        'safety' => 'Fake username/session. Should not disconnect a real customer.',
        'session' => [
            'username' => $username,
            'framed_ip' => '10.255.255.250',
            'nas_ip' => (string) ($settings['core_nas_ip'] ?? '172.16.5.5'),
            'nas_port_id' => 'smoke-live-coa',
            'calling_station_id' => '00-00-5E-00-53-FA',
            'acct_session_id' => 'smoke-live-coa-session',
            'nas_identifier' => 'smoke-live-coa-nas',
        ],
    ]),
]);
$actionId = (int) $db->lastInsertId();

try {
    $worker = dirname(__DIR__) . '/tools/process-radius-session-actions.php';
    $flags = ['--id=' . $actionId, '--json'];
    if (!$apply) {
        $flags[] = '--dry-run';
    }
    if ($apply && $autoApprove) {
        $flags[] = '--auto-approve';
    }
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . implode(' ', array_map('escapeshellarg', $flags));
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    $raw = trim(implode("\n", $output));
    $decoded = json_decode($raw, true);

    $rowStatement = $db->prepare('SELECT status, result_json FROM radius_session_actions WHERE id = :id LIMIT 1');
    $rowStatement->execute(['id' => $actionId]);
    $row = $rowStatement->fetch(PDO::FETCH_ASSOC) ?: [];

    $dbStatus = (string) ($row['status'] ?? '');
    $workerActions = is_array($decoded) ? (array) ($decoded['actions'] ?? []) : [];
    $previewOk = !$apply && $exitCode === 0 && $dbStatus === 'pending' && $workerActions !== [];
    $needsApproval = $apply && !$autoApprove && $exitCode === 0 && $dbStatus === 'pending' && $workerActions === [];
    $executedOk = $apply && $autoApprove && $exitCode === 0 && $dbStatus === 'executed';
    $result = [
        'status' => $executedOk ? 'ok' : ($previewOk ? 'ok' : ($needsApproval ? 'skipped' : 'failed')),
        'mode' => $apply ? 'apply' : 'dry-run',
        'auto_approve' => $autoApprove,
        'reason' => $needsApproval ? 'live_coa_requires_auto_approve_flag' : '',
        'action_id' => $actionId,
        'username' => $username,
        'worker_exit_code' => $exitCode,
        'worker_output' => is_array($decoded) ? $decoded : $raw,
        'db_status' => $dbStatus,
        'db_result' => app_db_json_decode((string) ($row['result_json'] ?? ''), []),
        'router_target' => (string) ($settings['router_ip'] ?? '') . ':' . (string) ($settings['coa_port'] ?? ''),
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($executedOk || $previewOk || $needsApproval) ? 0 : 4);
} finally {
    $delete = $db->prepare('DELETE FROM radius_session_actions WHERE id = :id');
    $delete->execute(['id' => $actionId]);
}
