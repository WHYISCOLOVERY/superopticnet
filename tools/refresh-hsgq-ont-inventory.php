<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

function option(string $name, string $default = ''): string {
    foreach ($_SERVER['argv'] ?? [] as $arg) {
        if (str_starts_with((string) $arg, '--' . $name . '=')) return substr((string) $arg, strlen($name) + 3);
    }
    return $default;
}
function out(array $value, int $code = 0): never { echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL; exit($code); }

$deviceKey = trim(option('device-key'));
$pon = max(1, min(64, (int) option('pon', '1')));
$apply = in_array('--apply', $_SERVER['argv'] ?? [], true);
if ($deviceKey === '') out(['status' => 'failed', 'reason' => 'device_key_required'], 2);

$db = app_db();
$actor = app_noc_system_actor('olt-inventory-worker');
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) out(['status' => 'failed', 'reason' => 'db_unavailable'], 2);
$device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
if (!is_array($device) || strtoupper((string) ($device['vendor'] ?? '')) !== 'HSGQ') out(['status' => 'failed', 'reason' => 'hsgq_olt_not_found'], 2);
$access = app_mgs_device_access_profile($device, $db, $actor, 'read_ont_live');
if (trim((string) ($access['ssh_username'] ?? '')) === '' || (string) ($access['ssh_password'] ?? '') === '') out(['status' => 'failed', 'reason' => 'missing_ssh_credentials'], 2);

$run = app_mgs_hsgq_exec_commands(
    (string) $device['ip_address'], (string) $access['ssh_username'], (string) $access['ssh_password'],
    ['enable', 'configure', 'interface gpon ' . $pon, 'show ont-info all'],
    (int) ($access['ssh_port'] ?? 22), 12000, $actor, $device, $db, 'read_ont_live'
);
if (empty($run['ok'])) out(['status' => 'failed', 'reason' => (string) ($run['reason'] ?? 'olt_command_failed')], 3);
$rows = app_mgs_parse_hsgq_ont_info_all_rows((string) (($run['outputs']['show ont-info all'] ?? '')));
$written = 0;
if ($apply) {
    $statement = $db->prepare('INSERT INTO live_olt_ont_inventory (device_key, pon, ont_id, serial_number, ont_name, status, discovered_at, last_seen_at, raw_source) VALUES (:device_key,:pon,:ont_id,:serial_number,:ont_name,:status,NOW(),NOW(),"cli") ON DUPLICATE KEY UPDATE serial_number=VALUES(serial_number), ont_name=VALUES(ont_name), status=VALUES(status), last_seen_at=NOW(), raw_source="cli"');
    foreach ($rows as $row) {
        $ontId = trim((string) ($row['ont_id'] ?? ''));
        if ($ontId === '') continue;
        $runState = strtolower(trim((string) ($row['run_state'] ?? '')));
        $adminState = strtolower(trim((string) ($row['state'] ?? '')));
        $status = $runState === 'online' ? 'online' : ($adminState === 'inactive' ? 'disabled' : 'offline');
        $statement->execute(['device_key' => $deviceKey, 'pon' => (string) ($row['pon'] ?? $pon), 'ont_id' => $ontId, 'serial_number' => trim((string) ($row['serial'] ?? '')), 'ont_name' => trim((string) ($row['ont_name'] ?? '')), 'status' => $status]);
        $written++;
    }
}
out(['status' => 'ok', 'mode' => $apply ? 'apply' : 'dry_run', 'device_key' => $deviceKey, 'pon' => $pon, 'found' => count($rows), 'written' => $written]);
