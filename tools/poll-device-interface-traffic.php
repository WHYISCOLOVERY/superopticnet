<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$options = getopt('', [
    'apply',
    'device::',
    'retain-days::',
    'skip-rollup',
    'skip-prune',
    'summary',
    'help',
]);

if (isset($options['help'])) {
    echo implode(PHP_EOL, [
        'Usage:',
        '  php tools/poll-device-interface-traffic.php [--apply] [--device=<device_key>] [--retain-days=30]',
        '',
        'Default mode is dry-run: polls every interface in device_interfaces and reports the',
        'bps rate that would be computed/stored, without writing anything.',
        '--apply       Store samples, re-run hourly/daily rollups and prune old raw samples.',
        '--device      Limit polling to one device_key instead of every known device.',
        '--retain-days Raw sample retention window for --apply. Default: 30.',
        '--skip-rollup Skip the hourly/daily rollup step (only meaningful with --apply).',
        '--skip-prune  Skip pruning old raw samples (only meaningful with --apply).',
        '--summary     Omit per-interface details; intended for supervisors/cron logs.',
        '',
    ]) . PHP_EOL;
    exit(0);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL/PDO connection is not ready.\n");
    exit(2);
}

$apply = isset($options['apply']);
$deviceKey = isset($options['device']) && $options['device'] !== '' ? (string) $options['device'] : null;
$retainDays = isset($options['retain-days']) ? max(1, (int) $options['retain-days']) : 30;

$actor = ['id' => 0, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'full_name' => 'Interface Traffic Poller (cron)'];

$pollResult = app_mgs_poll_device_interface_traffic($db, $actor, $apply, $deviceKey);
$result = ['poll' => $pollResult];

if ($apply && !empty($pollResult['ok'])) {
    if (!isset($options['skip-rollup'])) {
        $result['rollup_hourly'] = app_mgs_rollup_device_interface_traffic_hourly($db);
        $result['rollup_daily'] = app_mgs_rollup_device_interface_traffic_daily($db);
    }
    if (!isset($options['skip-prune'])) {
        $result['prune'] = app_mgs_prune_device_interface_traffic_samples($db, $retainDays);
    }
}

$output = $result;
if (isset($options['summary']) && isset($output['poll']['details'])) {
    unset($output['poll']['details']);
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(empty($pollResult['ok']) ? 1 : 0);
