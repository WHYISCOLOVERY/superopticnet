<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
function option(string $name, string $default = ''): string { foreach ($_SERVER['argv'] ?? [] as $arg) if (str_starts_with((string) $arg, '--' . $name . '=')) return substr((string) $arg, strlen($name) + 3); return $default; }
function flag(string $name): bool { return in_array('--' . $name, $_SERVER['argv'] ?? [], true); }
function out(array $value, int $code = 0): never { echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL; exit($code); }

$deviceKey = trim(option('device-key'));
$singlePon = trim(option('pon'));
$all = flag('all');
$apply = flag('apply');
$maxPons = max(1, min(16, (int) option('max-pons', $singlePon !== '' ? '1' : '8')));
$maxDevices = max(1, min(32, (int) option('max-devices', $all ? '32' : '1')));
if (!$all && $deviceKey === '') out(['status' => 'failed', 'reason' => 'device_key_or_all_required'], 2);

$db = app_db(); $actor = app_noc_system_actor('olt-inventory-worker');
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) out(['status' => 'failed', 'reason' => 'db_unavailable'], 2);

if ($all) {
    $statement = $db->query('SELECT * FROM device_learning_profiles WHERE device_type = "olt" AND vendor = "HSGQ" AND monitoring_enabled = 1 ORDER BY device_key LIMIT ' . $maxDevices);
    $devices = $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $devices = app_noc_filter_objects($devices, $actor, $db);
} else {
    $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
    $devices = is_array($device) ? [$device] : [];
}
if ($devices === []) out(['status' => 'failed', 'reason' => 'hsgq_olt_not_found'], 2);

$upsert = $db->prepare('INSERT INTO live_olt_ont_inventory (device_key, pon, ont_id, serial_number, ont_name, status, discovered_at, last_seen_at, raw_source) VALUES (:device_key,:pon,:ont_id,:serial_number,:ont_name,:status,NOW(),NOW(),"cli") ON DUPLICATE KEY UPDATE serial_number=VALUES(serial_number), ont_name=VALUES(ont_name), status=VALUES(status), last_seen_at=NOW(), raw_source="cli"');
$summary = ['status' => 'ok', 'mode' => $apply ? 'apply' : 'dry_run', 'devices' => [], 'found' => 0, 'written' => 0, 'failed' => 0];
foreach ($devices as $device) {
    if (strtoupper((string) ($device['vendor'] ?? '')) !== 'HSGQ') continue;
    $access = app_mgs_device_access_profile($device, $db, $actor, 'read_ont_live');
    if (trim((string) ($access['ssh_username'] ?? '')) === '' || (string) ($access['ssh_password'] ?? '') === '') { $summary['failed']++; $summary['devices'][] = ['device_key' => $device['device_key'], 'reason' => 'missing_ssh_credentials']; continue; }
    $deviceResult = ['device_key' => (string) $device['device_key'], 'pons' => [], 'found' => 0, 'written' => 0];
    $pons = $singlePon !== '' ? [max(1, min(64, (int) $singlePon))] : range(1, $maxPons);
    foreach ($pons as $pon) {
        $run = app_mgs_hsgq_exec_commands((string) $device['ip_address'], (string) $access['ssh_username'], (string) $access['ssh_password'], ['enable', 'configure', 'interface gpon ' . $pon, 'show ont-info all'], (int) ($access['ssh_port'] ?? 22), 12000, $actor, $device, $db, 'read_ont_live');
        if (empty($run['ok'])) { $summary['failed']++; $deviceResult['pons'][] = ['pon' => $pon, 'reason' => (string) ($run['reason'] ?? 'olt_command_failed')]; continue; }
        $rows = app_mgs_parse_hsgq_ont_info_all_rows((string) ($run['outputs']['show ont-info all'] ?? ''));
        $written = 0;
        if ($apply) foreach ($rows as $row) {
            $ontId = trim((string) ($row['ont_id'] ?? '')); if ($ontId === '') continue;
            $runState = strtolower(trim((string) ($row['run_state'] ?? ''))); $adminState = strtolower(trim((string) ($row['state'] ?? '')));
            $status = $runState === 'online' ? 'online' : ($adminState === 'inactive' ? 'disabled' : 'offline');
            $upsert->execute(['device_key' => (string) $device['device_key'], 'pon' => (string) ($row['pon'] ?? $pon), 'ont_id' => $ontId, 'serial_number' => trim((string) ($row['serial'] ?? '')), 'ont_name' => trim((string) ($row['ont_name'] ?? '')), 'status' => $status]); $written++;
        }
        $deviceResult['pons'][] = ['pon' => $pon, 'found' => count($rows), 'written' => $written]; $deviceResult['found'] += count($rows); $deviceResult['written'] += $written;
    }
    $summary['found'] += $deviceResult['found']; $summary['written'] += $deviceResult['written']; $summary['devices'][] = $deviceResult;
}
out($summary, $summary['failed'] > 0 && $summary['written'] === 0 ? 3 : 0);
