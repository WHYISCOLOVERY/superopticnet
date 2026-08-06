<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$args = array_slice($_SERVER['argv'] ?? [], 1);
$apply = in_array('--apply', $args, true);
$limit = 5;
$backupType = 'profile_snapshot';

foreach ($args as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    }
    if (str_starts_with($arg, '--type=')) {
        $backupType = trim(substr($arg, strlen('--type=')));
    }
}

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode([
        'status' => 'failed',
        'reason' => 'db_unavailable',
        'generated_at' => date(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

$result = app_mgs_run_backup_worker(
    $db,
    $apply,
    $limit,
    $backupType,
    app_noc_system_actor('device-backup-worker')
);
$status = !empty($result['ok']) ? 'ok' : 'failed';

echo json_encode([
    'status' => $status,
    'mode' => $apply ? 'apply' : 'dry-run',
    'note' => $apply
        ? 'Backup metadata/content was written only when hash changed.'
        : 'Dry-run does not write device_config_backups rows.',
    'result' => $result,
    'generated_at' => date(DATE_ATOM),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($status === 'ok' ? 0 : 3);
