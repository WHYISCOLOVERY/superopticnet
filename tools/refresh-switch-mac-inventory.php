<?php
declare(strict_types=1);
require dirname(__DIR__) . '/mgs-integration-lib.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
function sw_option(string $name, string $default = ''): string { foreach ($_SERVER['argv'] ?? [] as $arg) if (str_starts_with((string) $arg, '--' . $name . '=')) return substr((string) $arg, strlen($name) + 3); return $default; }
function sw_out(array $value, int $code = 0): never { echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL; exit($code); }
function sw_mac_from_oid_suffix(string $suffix): string { $parts = array_map('intval', explode('.', $suffix)); if (count($parts) < 6) return ''; $parts = array_slice($parts, -6); foreach ($parts as $part) if ($part < 0 || $part > 255) return ''; return implode(':', array_map(static fn(int $part): string => str_pad(dechex($part), 2, '0', STR_PAD_LEFT), $parts)); }

$deviceKey = trim(sw_option('device-key'));
$apply = in_array('--apply', $_SERVER['argv'] ?? [], true);
if ($deviceKey === '') sw_out(['status' => 'failed', 'reason' => 'device_key_required'], 2);
$db = app_db(); $actor = app_noc_system_actor('switch-inventory-worker');
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) sw_out(['status' => 'failed', 'reason' => 'db_unavailable'], 2);
$device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
if (!is_array($device) || (string) ($device['device_type'] ?? '') !== 'switch') sw_out(['status' => 'failed', 'reason' => 'switch_not_found'], 2);
$access = app_mgs_device_access_profile($device, $db, $actor, 'read_device_sensitive');
$community = (string) ($access['read_community'] ?? '');
if ($community === '') sw_out(['status' => 'failed', 'reason' => 'missing_snmp_credentials'], 2);
$ip = (string) $device['ip_address']; $port = (int) ($access['port'] ?? 161); $timeout = 1800;
$fdbBase = '1.3.6.1.2.1.17.4.3.1.2'; $bridgeBase = '1.3.6.1.2.1.17.1.4.1.2'; $ifDescrBase = '1.3.6.1.2.1.2.2.1.2';
$probe = app_mgs_snmp_getnext_raw($ip, $community, $port, $fdbBase, $timeout);
if (empty($probe['ok'])) sw_out(['status' => 'failed', 'reason' => (string) ($probe['error'] ?? 'snmp_fdb_unavailable')], 3);
$fdb = app_mgs_snmp_walk_column($ip, $community, $port, $fdbBase, $timeout, 10000, (int) ($probe['version'] ?? 0));
$bridge = app_mgs_snmp_walk_column($ip, $community, $port, $bridgeBase, $timeout, 1024, (int) ($probe['version'] ?? 0));
$ifDescr = app_mgs_snmp_walk_column($ip, $community, $port, $ifDescrBase, $timeout, 2048, (int) ($probe['version'] ?? 0));
$bridgeMap = []; foreach ($bridge as $oid => $row) { $suffix = app_mgs_snmp_oid_index_suffix((string) $oid, $bridgeBase); if ($suffix !== null) $bridgeMap[(int) $suffix] = (int) ($row['value'] ?? 0); }
$descrMap = []; foreach ($ifDescr as $oid => $row) { $suffix = app_mgs_snmp_oid_index_suffix((string) $oid, $ifDescrBase); if ($suffix !== null) $descrMap[(int) $suffix] = substr((string) ($row['value'] ?? ''), 0, 191); }
$written = 0; $seen = 0;
if ($apply) $upsert = $db->prepare('INSERT INTO live_switch_mac_inventory (device_key,mac_address,bridge_port,if_index,if_descr,status,discovered_at,last_seen_at,raw_source) VALUES (:device_key,:mac_address,:bridge_port,:if_index,:if_descr,"learned",NOW(),NOW(),"snmp_bridge") ON DUPLICATE KEY UPDATE bridge_port=VALUES(bridge_port),if_index=VALUES(if_index),if_descr=VALUES(if_descr),status="learned",last_seen_at=NOW()');
foreach ($fdb as $oid => $row) { $suffix = app_mgs_snmp_oid_index_suffix((string) $oid, $fdbBase); if ($suffix === null) continue; $mac = sw_mac_from_oid_suffix($suffix); $bridgePort = (int) ($row['value'] ?? 0); if ($mac === '' || $bridgePort <= 0) continue; $ifIndex = (int) ($bridgeMap[$bridgePort] ?? 0); $seen++; if ($apply) { $upsert->execute(['device_key'=>$deviceKey,'mac_address'=>$mac,'bridge_port'=>$bridgePort,'if_index'=>$ifIndex,'if_descr'=>(string)($descrMap[$ifIndex] ?? '')]); $written++; } }
sw_out(['status'=>'ok','mode'=>$apply?'apply':'dry_run','device_key'=>$deviceKey,'macs_found'=>$seen,'written'=>$written,'bridge_ports'=>count($bridgeMap)]);
