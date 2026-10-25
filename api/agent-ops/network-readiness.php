<?php
declare(strict_types=1);
/** One registered-device diagnostic. Service actor RBAC; no config changes or telemetry writes. */
require dirname(__DIR__, 2) . '/mgs-integration-lib.php';
require_once dirname(__DIR__, 2) . '/crew-service-auth-lib.php';
app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function network_probe_fail(int $status, string $reason): never {
    http_response_code($status);
    echo json_encode(['ok'=>false,'reason'=>$reason]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { network_probe_fail(405, 'method_not_allowed'); }
$input = json_decode((string)file_get_contents('php://input'), true);
$ip = (string)($input['ip'] ?? '');
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { network_probe_fail(400, 'invalid_ipv4'); }
$db = app_db();
if (!$db instanceof PDO) { network_probe_fail(503, 'database_unavailable'); }
$actor = app_crew_service_actor($db, app_crew_service_bearer());
if ($actor === null) { network_probe_fail(401, 'auth_required'); }
if (!app_portal_can_access_module($actor, 'network')) { network_probe_fail(403, 'module_denied'); }
try {
    $q = $db->prepare('SELECT * FROM device_learning_profiles WHERE ip_address=? LIMIT 2');
    $q->execute([$ip]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { network_probe_fail(404, 'device_not_registered'); }
    if (count($rows) !== 1) { network_probe_fail(409, 'ambiguous_device_registration'); }
    $device = $rows[0];
    $decision = app_noc_authorize($actor, 'probe_device', $device, $db);
    if (empty($decision['ok'])) { network_probe_fail(403, (string)($decision['reason'] ?? 'probe_denied')); }
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $profile = app_mgs_device_access_profile($device, $db, $actor, 'probe_device', false);
    $result = ['ip'=>$ip, 'registered'=>true, 'checked_at'=>date(DATE_ATOM),
        'vendor'=>(string)$device['vendor'], 'stored_status'=>(string)$device['status'],
        'stored_reachability'=>(string)$device['reachability_state']];
    $q = $db->prepare('SELECT setting_value FROM mgs_runtime_settings WHERE setting_key=?');
    $q->execute(['monitor_management_vlan_connected']);
    $result['management_path_enabled'] = in_array(strtolower((string)$q->fetchColumn()), ['1','true','yes','on'], true);
    $healthProfile = strtoupper((string)$device['vendor']) === 'CISCO' ? 'cisco_health' : '';
    $ssh = app_mgs_probe_ssh_auth($ip, (string)($profile['ssh_username'] ?? ''), (string)($profile['ssh_password'] ?? ''), (int)($profile['ssh_port'] ?? 22), 2000, $actor, $device, $db, 'probe_device', $healthProfile);
    $result['health'] = $ssh['health'] ?? ['error'=>'vendor_health_profile_not_available'];
    $result['ssh_auth'] = !empty($ssh['reachable']);
    // Never return banners, usernames, passwords, exception URLs or raw helper stderr.
    $result['ssh_error'] = $result['ssh_auth'] ? null : (empty($profile['ssh_username']) || empty($profile['ssh_password']) ? 'missing_ssh_credentials' : 'ssh_auth_or_transport_failed');
    if (!$result['ssh_auth']) {
        foreach (['paramiko_import_failed','ssh_auth_helper_unavailable','ssh_auth_invalid_response','AuthenticationException','IncompatiblePeer','TimeoutError','PermissionError','FileNotFoundError','missing_required_arguments'] as $code) {
            if (str_contains((string)($ssh['error'] ?? ''), $code)) { $result['ssh_error'] = $code; break; }
        }
    }
    $community = (string)($profile['read_community'] ?? '');
    $snmp = $community !== '' ? app_mgs_snmp_get_raw($ip, $community, (int)($profile['port'] ?? 161), '1.3.6.1.2.1.1.1.0', 500) : ['ok'=>false,'error'=>'missing_snmp_config'];
    $result['snmp_get'] = !empty($snmp['ok']);
    $result['snmp_error'] = $result['snmp_get'] ? null : 'snmp_unavailable';
    $result['if_mib_sample_count'] = 0;
    if ($result['snmp_get']) {
        $walk = app_mgs_snmp_walk_column($ip, $community, (int)($profile['port'] ?? 161), '1.3.6.1.2.1.2.2.1.2', 500, 4);
        $result['if_mib_sample_count'] = count($walk);
    }
    $result['traffic'] = ['series' => [], 'interfaces' => [], 'source' => 'device_interface_traffic_samples'];
    try {
        $series = $db->prepare(
            'SELECT sampled_at, COALESCE(SUM(in_bps), 0) AS in_bps, COALESCE(SUM(out_bps), 0) AS out_bps
               FROM device_interface_traffic_samples
              WHERE device_key = :device_key AND sampled_at >= (NOW() - INTERVAL 24 HOUR)
              GROUP BY sampled_at ORDER BY sampled_at DESC LIMIT 36'
        );
        $series->execute(['device_key' => (string) $device['device_key']]);
        $trafficSeries = array_reverse($series->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $result['traffic']['series'] = array_map(static fn (array $row): array => [
            'at' => (string) ($row['sampled_at'] ?? ''),
            'in_bps' => (int) ($row['in_bps'] ?? 0),
            'out_bps' => (int) ($row['out_bps'] ?? 0),
        ], $trafficSeries);

        $latest = $db->prepare(
            'SELECT samples.if_index, interfaces.if_descr, samples.sampled_at, samples.in_bps, samples.out_bps
               FROM device_interface_traffic_samples samples
               JOIN (
                    SELECT if_index, MAX(sampled_at) AS sampled_at
                      FROM device_interface_traffic_samples
                     WHERE device_key = :latest_key
                     GROUP BY if_index
               ) newest ON newest.if_index = samples.if_index AND newest.sampled_at = samples.sampled_at
               LEFT JOIN device_interfaces interfaces
                 ON interfaces.device_key = samples.device_key AND interfaces.if_index = samples.if_index
              WHERE samples.device_key = :device_key
              ORDER BY (COALESCE(samples.in_bps, 0) + COALESCE(samples.out_bps, 0)) DESC LIMIT 12'
        );
        $latest->execute(['latest_key' => (string) $device['device_key'], 'device_key' => (string) $device['device_key']]);
        $result['traffic']['interfaces'] = array_map(static fn (array $row): array => [
            'if_index' => (int) ($row['if_index'] ?? 0),
            'name' => (string) (($row['if_descr'] ?? '') !== '' ? $row['if_descr'] : ('ifIndex ' . ($row['if_index'] ?? ''))),
            'sampled_at' => (string) ($row['sampled_at'] ?? ''),
            'in_bps' => isset($row['in_bps']) ? (int) $row['in_bps'] : null,
            'out_bps' => isset($row['out_bps']) ? (int) $row['out_bps'] : null,
        ], $latest->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable) {
        // Older installations can run before the telemetry migration.  The
        // live SSH/SNMP result remains valid and explicitly has no trend data.
        $result['traffic']['reason'] = 'traffic_history_unavailable';
    }
    $db->rollBack();
    echo json_encode(['ok'=>true,'device'=>$result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    if ($db->inTransaction()) { $db->rollBack(); }
    network_probe_fail(500, 'network_diagnostic_failed');
}
