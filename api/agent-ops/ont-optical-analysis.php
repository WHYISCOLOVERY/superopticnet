<?php
declare(strict_types=1);

/** Live, read-only ONT optical/temperature analysis for a bounded OLT set. */
require dirname(__DIR__, 2) . '/mgs-integration-lib.php';
require_once dirname(__DIR__, 2) . '/crew-service-auth-lib.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function ont_analysis_fail(int $status, string $reason): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'reason' => $reason]);
    exit;
}

function ont_analysis_number(mixed $value, float $default, float $minimum, float $maximum): float
{
    if ($value === null || $value === '') {
        return $default;
    }
    if (!is_numeric($value)) {
        ont_analysis_fail(400, 'invalid_threshold');
    }
    $number = (float) $value;
    if (!is_finite($number) || $number < $minimum || $number > $maximum) {
        ont_analysis_fail(400, 'threshold_out_of_range');
    }
    return $number;
}

function ont_analysis_comparison(mixed $value, string $default): string
{
    $comparison = strtolower(trim((string) ($value ?? $default)));
    if (!in_array($comparison, ['gt', 'gte', 'lt', 'lte'], true)) {
        ont_analysis_fail(400, 'invalid_comparison');
    }
    return $comparison;
}

function ont_analysis_matches(?float $value, float $threshold, string $comparison): bool
{
    if ($value === null) {
        return false;
    }
    return match ($comparison) {
        'gt' => $value > $threshold,
        'gte' => $value >= $threshold,
        'lt' => $value < $threshold,
        'lte' => $value <= $threshold,
    };
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ont_analysis_fail(405, 'method_not_allowed');
}
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    ont_analysis_fail(400, 'invalid_json');
}

$requestedIps = is_array($input['olt_ips'] ?? null) ? array_values(array_unique(array_map('strval', $input['olt_ips']))) : [];
if ($requestedIps === [] || count($requestedIps) > 16) {
    ont_analysis_fail(400, 'invalid_olt_targets');
}
foreach ($requestedIps as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        ont_analysis_fail(400, 'invalid_ipv4');
    }
}

$thresholds = [
    'ont_tx_threshold_dbm' => ont_analysis_number($input['ont_tx_threshold_dbm'] ?? null, -28.0, -50.0, 10.0),
    'ont_rx_threshold_dbm' => ont_analysis_number($input['ont_rx_threshold_dbm'] ?? null, -23.0, -50.0, 10.0),
    'temperature_threshold_c' => ont_analysis_number($input['temperature_threshold_c'] ?? null, 46.0, 0.0, 120.0),
];
$comparisons = [
    'ont_tx' => ont_analysis_comparison($input['ont_tx_comparison'] ?? null, 'lte'),
    'ont_rx' => ont_analysis_comparison($input['ont_rx_comparison'] ?? null, 'lte'),
    'temperature' => ont_analysis_comparison($input['temperature_comparison'] ?? null, 'gte'),
];
$limit = max(1, min(5000, (int) ($input['limit'] ?? 1500)));

$db = app_db();
if (!$db instanceof PDO) {
    ont_analysis_fail(503, 'database_unavailable');
}
$actor = app_crew_service_actor($db, app_crew_service_bearer());
if ($actor === null) {
    ont_analysis_fail(401, 'auth_required');
}
if (!app_portal_can_access_module($actor, 'network')) {
    ont_analysis_fail(403, 'module_denied');
}

$devices = app_mgs_monitored_olt_devices($db, $actor);
$byIp = [];
foreach ($devices as $device) {
    if (is_array($device) && !empty($device['ip_address'])) {
        $byIp[(string) $device['ip_address']] = $device;
    }
}

$matches = [];
$coverage = [];
$perOlt = [];
$ontsScanned = 0;
foreach ($requestedIps as $ip) {
    $device = $byIp[$ip] ?? null;
    if (!is_array($device)) {
        $coverage[] = ['ip' => $ip, 'status' => 'failed', 'reason' => 'olt_not_registered_or_denied'];
        continue;
    }
    $live = app_mgs_hsgq_live_ont_inventory($device, $actor, $db);
    if (empty($live['ok'])) {
        $coverage[] = ['ip' => $ip, 'name' => (string) ($device['device_name'] ?? $ip), 'status' => 'failed',
            'reason' => (string) ($live['reason'] ?? 'live_read_failed')];
        continue;
    }

    $rows = is_array($live['rows'] ?? null) ? $live['rows'] : [];
    $ontsScanned += count($rows);
    $counts = ['total' => count($rows), 'matched' => 0, 'ont_tx_match' => 0, 'ont_rx_match' => 0, 'temperature_match' => 0];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ontTx = app_mgs_signal_number($row['ont_tx'] ?? null);
        $ontRx = app_mgs_signal_number($row['ont_rx'] ?? null);
        $oltRx = app_mgs_signal_number($row['olt_rx'] ?? null);
        $temperature = app_mgs_signal_number($row['temperature'] ?? null);
        $reasons = [];
        if (ont_analysis_matches($ontTx, $thresholds['ont_tx_threshold_dbm'], $comparisons['ont_tx'])) {
            $reasons[] = 'ont_tx_threshold';
            $counts['ont_tx_match']++;
        }
        if (ont_analysis_matches($ontRx, $thresholds['ont_rx_threshold_dbm'], $comparisons['ont_rx'])) {
            $reasons[] = 'ont_rx_threshold';
            $counts['ont_rx_match']++;
        }
        if (ont_analysis_matches($temperature, $thresholds['temperature_threshold_c'], $comparisons['temperature'])) {
            $reasons[] = 'temperature_threshold';
            $counts['temperature_match']++;
        }
        if ($reasons === []) {
            continue;
        }
        $counts['matched']++;
        if (count($matches) < $limit) {
            $matches[] = [
                'olt_ip' => $ip,
                'olt_name' => (string) ($device['device_name'] ?? $ip),
                'pon' => (string) ($row['pon'] ?? ''),
                'ont_id' => (string) ($row['ont_id'] ?? ''),
                'serial' => (string) ($row['serial'] ?? ''),
                'status' => (string) ($row['status'] ?? 'unknown'),
                'ont_tx_dbm' => $ontTx,
                'ont_rx_dbm' => $ontRx,
                'olt_rx_dbm' => $oltRx,
                'temperature_c' => $temperature,
                'reasons' => $reasons,
            ];
        }
    }
    $perOlt[] = ['ip' => $ip, 'name' => (string) ($device['device_name'] ?? $ip)] + $counts;
    $coverage[] = ['ip' => $ip, 'name' => (string) ($device['device_name'] ?? $ip), 'status' => 'ok',
        'onts_total' => count($rows), 'optical_rows' => (int) ($live['optical_rows'] ?? 0)];
}

$scannedDevices = count(array_filter($coverage, static fn (array $row): bool => ($row['status'] ?? '') === 'ok'));
if ($scannedDevices === 0) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'reason' => 'all_olt_reads_failed',
        'checked_at' => date(DATE_ATOM),
        'devices_requested' => count($requestedIps),
        'devices_scanned' => 0,
        'coverage' => $coverage,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$totalMatches = array_sum(array_map(static fn (array $row): int => (int) ($row['matched'] ?? 0), $perOlt));
echo json_encode([
    'ok' => true,
    'complete' => $scannedDevices === count($requestedIps),
    'checked_at' => date(DATE_ATOM),
    'source' => 'live_hsgq_ssh',
    'thresholds' => $thresholds,
    'comparisons' => $comparisons,
    'threshold_semantics' => [
        'ont_tx' => 'ONT TX is compared directly with ont_tx_threshold_dbm',
        'ont_rx' => 'ONT RX is compared directly with ont_rx_threshold_dbm',
        'temperature' => 'ONT temperature is compared directly with temperature_threshold_c',
        'match_mode' => 'any',
    ],
    'devices_requested' => count($requestedIps),
    'devices_scanned' => $scannedDevices,
    'onts_scanned' => $ontsScanned,
    'matches_total' => $totalMatches,
    'matches_returned' => count($matches),
    'truncated' => $totalMatches > count($matches),
    'coverage' => $coverage,
    'per_olt' => $perOlt,
    'matches' => $matches,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
