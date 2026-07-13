<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function network_olt_worker_has_flag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function network_olt_worker_option(array $argv, string $name, mixed $default = null): mixed
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }
    return $default;
}

function network_olt_worker_out(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

$argv = $_SERVER['argv'] ?? [];
$apply = network_olt_worker_has_flag($argv, '--apply');
$ignoreManagementPath = network_olt_worker_has_flag($argv, '--ignore-management-path');
$noQueue = network_olt_worker_has_flag($argv, '--no-queue');
$threshold = (int) network_olt_worker_option($argv, 'mass-pon-min', 0);
$startedAt = microtime(true);

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    network_olt_worker_out([
        'status' => 'failed',
        'reason' => 'db_unavailable',
        'generated_at' => date(DATE_ATOM),
    ], 2);
}

app_mgs_seed_device_learning_profiles($db);

$options = [
    'test_mode' => !$apply,
    'create_tickets' => $apply,
    'queue_messages' => $apply && !$noQueue,
    'require_management_path' => !$ignoreManagementPath,
];
if ($threshold > 0) {
    $options['olt_mass_pon_min_affected'] = $threshold;
}

$result = app_mgs_analyze_olt_ticket_rules($options, $db);
$status = !empty($result['ok']) ? 'ok' : 'failed';
if (($result['status'] ?? '') === 'skipped') {
    $status = 'skipped';
}

network_olt_worker_out([
    'status' => $status,
    'mode' => $apply ? 'apply' : 'dry-run',
    'management_path_required' => !$ignoreManagementPath,
    'devices_scanned' => (int) ($result['devices_scanned'] ?? 0),
    'olt_devices_scanned' => (int) ($result['olt_devices_scanned'] ?? 0),
    'onts_checked' => (int) ($result['onts_checked'] ?? 0),
    'alerts' => (int) ($result['alerts'] ?? 0),
    'tickets_created' => (int) ($result['tickets_created'] ?? 0),
    'tickets_duplicate' => (int) ($result['tickets_duplicate'] ?? 0),
    'reason' => (string) ($result['reason'] ?? ''),
    'results' => array_slice((array) ($result['results'] ?? []), 0, 20),
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'generated_at' => date(DATE_ATOM),
], $status === 'failed' ? 1 : 0);
