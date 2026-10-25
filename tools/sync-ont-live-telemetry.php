<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function ont_telemetry_sync_has_flag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function ont_telemetry_sync_option(array $argv, string $name, mixed $default = null): mixed
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }
    return $default;
}

function ont_telemetry_sync_out(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

$argv = $_SERVER['argv'] ?? [];
$apply = ont_telemetry_sync_has_flag($argv, '--apply');
$limit = max(1, (int) ont_telemetry_sync_option($argv, 'limit', 20));
$staleMinutes = max(1, (int) ont_telemetry_sync_option($argv, 'stale-minutes', 15));
$startedAt = microtime(true);

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    ont_telemetry_sync_out([
        'status' => 'failed',
        'reason' => 'db_unavailable',
        'generated_at' => date(DATE_ATOM),
    ], 2);
}

$actor = app_noc_system_actor('ont-telemetry-sync-worker');

if (!app_mgs_management_path_ready()) {
    ont_telemetry_sync_out([
        'status' => 'skipped',
        'reason' => 'management_path_down',
        'candidates_selected' => 0,
        'synced_ok' => 0,
        'synced_failed' => 0,
        'generated_at' => date(DATE_ATOM),
    ]);
}

$allOnts = app_mgs_collect_customer_ont_rows($db);
$now = time();
$candidates = [];
foreach ($allOnts as $ont) {
    $serial = trim((string) app_mgs_search_profile_field($ont, ['serial', 'sn'], ''));
    $mac = trim((string) app_mgs_search_profile_field($ont, ['mac'], ''));
    $pon = trim((string) app_mgs_search_profile_field($ont, ['pon', 'pon_port'], ''));
    $ontId = trim((string) app_mgs_search_profile_field($ont, ['ont_id', 'ont'], ''));
    $olt = trim((string) app_mgs_search_profile_field($ont, ['olt', 'olt_name', 'device_name'], ''));
    $hasIdentifier = $serial !== '' || $mac !== '' || ($pon !== '' && $ontId !== '' && $olt !== '');
    if (!$hasIdentifier) {
        continue;
    }

    $syncedAt = trim((string) ($ont['optical_synced_at'] ?? ''));
    $staleSeconds = $syncedAt !== '' ? ($now - (int) (strtotime($syncedAt) ?: 0)) : PHP_INT_MAX;
    if ($staleSeconds < $staleMinutes * 60) {
        continue;
    }

    $ont['_stale_seconds'] = $staleSeconds;
    $ont['_serial'] = $serial;
    $ont['_mac'] = $mac;
    $ont['_pon'] = $pon;
    $ont['_ont_id'] = $ontId;
    $ont['_olt'] = $olt;
    $candidates[] = $ont;
}

usort($candidates, static fn (array $a, array $b): int => ($b['_stale_seconds'] ?? 0) <=> ($a['_stale_seconds'] ?? 0));
$candidates = array_slice($candidates, 0, $limit);

$deviceIndex = null;
$resolveDeviceIndex = static function () use (&$deviceIndex, $db, $actor): array {
    if ($deviceIndex !== null) {
        return $deviceIndex;
    }
    $allDevices = app_mgs_monitored_network_devices($db, $actor);
    $oltDevices = array_values(array_filter(
        $allDevices,
        static fn (array $device): bool => (string) ($device['device_type'] ?? '') === 'olt'
    ));
    $deviceIndex = app_mgs_olt_device_index($oltDevices);
    return $deviceIndex;
};

$syncedOk = 0;
$syncedFailed = 0;
$results = [];

foreach ($candidates as $ont) {
    $accountId = (int) ($ont['account_id'] ?? 0);
    $profileIndex = array_key_exists('_profile_index', $ont) ? (int) $ont['_profile_index'] : null;
    $subscriberCode = (string) ($ont['subscriber_code'] ?? '');
    $serial = (string) ($ont['_serial'] ?? '');
    $mac = (string) ($ont['_mac'] ?? '');
    $pon = (string) ($ont['_pon'] ?? '');
    $ontId = (string) ($ont['_ont_id'] ?? '');
    $olt = (string) ($ont['_olt'] ?? '');

    if ($accountId <= 0) {
        continue;
    }

    try {
        $live = null;
        if ($pon !== '' && $ontId !== '' && $olt !== '') {
            $device = app_mgs_resolve_olt_device_from_text($olt, $resolveDeviceIndex());
            $live = is_array($device)
                ? app_mgs_hsgq_live_ont_snapshot($device, $pon, $ontId, $actor, $db)
                : ['ok' => false, 'reason' => 'device_not_found'];
        } else {
            $needle = $serial !== '' ? $serial : $mac;
            $found = app_mgs_hsgq_live_find_ont_by_query($needle, $db, 8, $actor);
            $live = is_array($found) ? (['ok' => true] + $found) : ['ok' => false, 'reason' => 'not_found'];
        }

        $reason = (string) ($live['reason'] ?? '');
        $ontData = is_array($live['ont'] ?? null) ? $live['ont'] : [];

        if (!empty($live['ok'])) {
            $update = [
                'pon' => (string) ($live['pon'] ?? $pon),
                'ont_id' => (string) ($live['ont_id'] ?? $ontId),
                'olt' => (string) ($live['device_name'] ?? $olt),
                'temperature' => (string) ($ontData['temperature'] ?? ''),
                'ont_rx' => (string) ($ontData['ont_rx'] ?? ''),
                'ont_tx' => (string) ($ontData['ont_tx'] ?? ''),
                'olt_rx' => (string) ($ontData['olt_rx'] ?? ''),
                'olt_tx' => (string) ($ontData['olt_tx'] ?? ''),
                'optical_synced_at' => date(DATE_ATOM),
                'optical_sync_reason' => '',
            ];
            $saved = !$apply || app_mgs_apply_ont_live_sync($db, $accountId, $profileIndex, $serial, $pon, $ontId, $update);
            if ($saved) {
                $syncedOk++;
                $results[] = ['account_id' => $accountId, 'subscriber_code' => $subscriberCode, 'status' => 'ok'];
            } else {
                $syncedFailed++;
                $results[] = ['account_id' => $accountId, 'subscriber_code' => $subscriberCode, 'status' => 'save_failed'];
            }
        } else {
            $syncedFailed++;
            if ($apply) {
                app_mgs_apply_ont_live_sync($db, $accountId, $profileIndex, $serial, $pon, $ontId, [
                    'optical_synced_at' => date(DATE_ATOM),
                    'optical_sync_reason' => $reason !== '' ? $reason : 'live_query_failed',
                ]);
            }
            $results[] = ['account_id' => $accountId, 'subscriber_code' => $subscriberCode, 'status' => 'failed', 'reason' => $reason];
        }
    } catch (Throwable $exception) {
        $syncedFailed++;
        app_runtime_error_reference('ont_live_telemetry_sync', $exception);
        $results[] = ['account_id' => $accountId, 'subscriber_code' => $subscriberCode, 'status' => 'exception'];
    }
}

ont_telemetry_sync_out([
    'status' => 'ok',
    'mode' => $apply ? 'apply' : 'dry-run',
    'candidates_total' => count($allOnts),
    'candidates_selected' => count($candidates),
    'synced_ok' => $syncedOk,
    'synced_failed' => $syncedFailed,
    'results' => array_slice($results, 0, 25),
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'generated_at' => date(DATE_ATOM),
]);
