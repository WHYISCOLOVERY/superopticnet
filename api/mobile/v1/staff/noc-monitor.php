<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__) . '/lib/mobile-alert-feed-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, [
    'admin', 'manager', 'super_admin', 'director', 'team_lead',
    'operator', 'night_operator', 'support', 'engineer', 'noc', 'network_engineer', 'noc_monitor',
]);

$db = app_db();
$rows = $db->query(
    "SELECT device_key,device_name,ip_address,device_type,vendor,model,
            expected_reachability,monitoring_enabled,reachability_state,status,
            last_successful_ping_at,last_successful_snmp_at,last_probe_at,
            last_probe_error,last_probe_method,updated_at
       FROM device_learning_profiles
      ORDER BY FIELD(reachability_state,'unreachable_confirmed','unreachable_because_monitor_path_down','unknown','reachable'),
               COALESCE(last_probe_at,updated_at) DESC,device_name"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$trafficByDevice = [];
try {
    $trafficRows = $db->query(
        "SELECT s.device_key,MAX(s.sampled_at) AS sampled_at,
                SUM(COALESCE(s.in_bps,0)) AS in_bps,
                SUM(COALESCE(s.out_bps,0)) AS out_bps,
                COUNT(*) AS interface_count
           FROM device_interface_traffic_samples s
           JOIN (
                SELECT device_key,MAX(sampled_at) AS sampled_at
                  FROM device_interface_traffic_samples
                 GROUP BY device_key
           ) latest ON latest.device_key=s.device_key AND latest.sampled_at=s.sampled_at
          GROUP BY s.device_key"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($trafficRows as $traffic) {
        $trafficByDevice[(string) $traffic['device_key']] = [
            'sampled_at' => (string) ($traffic['sampled_at'] ?? ''),
            'in_bps' => (int) ($traffic['in_bps'] ?? 0),
            'out_bps' => (int) ($traffic['out_bps'] ?? 0),
            'interface_count' => (int) ($traffic['interface_count'] ?? 0),
        ];
    }
} catch (Throwable) {
    $trafficByDevice = [];
}

$summary = [
    'total' => 0,
    'online' => 0,
    'offline' => 0,
    'stale' => 0,
    'unknown' => 0,
    'disabled' => 0,
];
$devices = [];
$now = time();
foreach ($rows as $row) {
    $summary['total']++;
    $lastProbe = trim((string) ($row['last_probe_at'] ?? ''));
    $probeAge = $lastProbe !== '' ? max(0, $now - (int) strtotime($lastProbe)) : null;
    $rawState = strtolower(trim((string) ($row['reachability_state'] ?? 'unknown')));
    if ((int) ($row['monitoring_enabled'] ?? 0) !== 1) {
        $state = 'disabled';
    } elseif ($rawState === 'reachable') {
        $state = 'online';
    } elseif ($rawState === 'unreachable_confirmed') {
        $state = 'offline';
    } else {
        $state = 'unknown';
    }
    $summary[$state]++;
    $freshness = (int) ($row['monitoring_enabled'] ?? 0) !== 1
        ? 'disabled'
        : (($lastProbe === '' || $probeAge === null)
            ? 'no_data'
            : ($probeAge > 1800 ? 'stale' : 'fresh'));
    // Freshness is an additional warning, not a reachability state. A device
    // can be last known online while its most recent measurement is stale.
    if ($freshness === 'stale') {
        $summary['stale']++;
    }
    $devices[] = [
        'device_key' => (string) $row['device_key'],
        'name' => (string) ($row['device_name'] ?: $row['device_key']),
        'ip_address' => (string) ($row['ip_address'] ?? ''),
        'device_type' => (string) ($row['device_type'] ?? ''),
        'vendor' => (string) ($row['vendor'] ?? ''),
        'model' => (string) ($row['model'] ?? ''),
        'state' => $state,
        'state_source' => $rawState,
        'freshness' => $freshness,
        'last_probe_at' => $lastProbe !== '' ? $lastProbe : null,
        'probe_age_seconds' => $probeAge,
        'last_probe_method' => (string) ($row['last_probe_method'] ?? ''),
        'last_probe_error' => (string) ($row['last_probe_error'] ?? ''),
        'last_successful_ping_at' => $row['last_successful_ping_at'] ?? null,
        'last_successful_snmp_at' => $row['last_successful_snmp_at'] ?? null,
        'traffic' => $trafficByDevice[(string) $row['device_key']] ?? null,
    ];
}

$historyRows = [];
try {
    $historyRows = $db->query(
        "SELECT h.id,h.dedup_key,h.from_status,h.to_status,h.from_severity,h.to_severity,
                h.seen_count,h.created_at,a.title,a.message,a.object_id
           FROM mgs_alarm_state_events h
           LEFT JOIN mgs_alarm_events a ON a.id=h.alarm_event_id
          WHERE h.test_mode=0
          ORDER BY h.id DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {
    $historyRows = [];
}

$syslogRows = [];
$syslogPath = '/var/log/superoptic/network-devices.log';
if (is_readable($syslogPath)) {
    $handle = fopen($syslogPath, 'rb');
    if (is_resource($handle)) {
        fseek($handle, 0, SEEK_END);
        $size = ftell($handle);
        $readSize = min(max(0, (int) $size), 262144);
        if ($readSize > 0) {
            fseek($handle, -$readSize, SEEK_END);
            $chunk = (string) fread($handle, $readSize);
            $lines = preg_split('/\r?\n/', $chunk) ?: [];
            if ((int) $size > $readSize) {
                array_shift($lines);
            }
            foreach (array_reverse(array_filter($lines, 'strlen')) as $line) {
                if (count($syslogRows) >= 100) {
                    break;
                }
                $line = mb_substr(trim((string) $line), 0, 2000);
                if ($line === '' || str_contains($line, 'SUPEROPTIC-TEST')) {
                    continue;
                }
                $match = [];
                preg_match('/^(\S+)\s+(\S+)\s+(\S+)\s+(.*)$/u', $line, $match);
                $syslogRows[] = [
                    'occurred_at' => (string) ($match[1] ?? ''),
                    'source_ip' => (string) ($match[2] ?? ''),
                    'hostname' => (string) ($match[3] ?? ''),
                    'message' => (string) ($match[4] ?? $line),
                ];
            }
        }
        fclose($handle);
    }
}
$summary['syslog_events'] = count($syslogRows);
$summary['syslog_receiver'] = is_readable($syslogPath);

app_mobile_json_response([
    'summary' => $summary,
    'freshness_seconds' => 1800,
    'devices' => $devices,
    'alarms' => app_mobile_actionable_alarm_feed($db, 50),
    'history' => array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['seen_count'] = (int) $row['seen_count'];
        return $row;
    }, $historyRows),
    'syslog' => $syslogRows,
], 200);
