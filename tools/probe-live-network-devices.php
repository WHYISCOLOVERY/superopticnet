<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$actor = [
    'role' => 'employee',
    'account_type' => 'admin',
    'employee_role' => 'admin',
    'is_active' => 1,
    'name' => 'Live network device probe',
    'login' => 'system:live-network-device-probe',
];
$rows = $db->query(
    'SELECT device_key, device_name, ip_address, device_type
       FROM device_learning_profiles
      WHERE monitoring_enabled = 1 AND expected_reachability = 1
      ORDER BY INET_ATON(ip_address), device_key'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$results = [];
foreach ($rows as $row) {
    $result = app_mgs_probe_single_device((string) $row['device_key'], $db, $actor);
    $results[] = [
        'device_key' => (string) $row['device_key'],
        'device_name' => (string) $row['device_name'],
        'ip_address' => (string) $row['ip_address'],
        'device_type' => (string) $row['device_type'],
        'state' => (string) ($result['state'] ?? $result['reason'] ?? 'unknown'),
        'reachable' => (bool) ($result['reachable'] ?? false),
        'ssh' => (bool) ($result['ssh'] ?? false),
        'ssh_auth' => (bool) ($result['ssh_auth'] ?? false),
        'snmp' => (bool) ($result['snmp'] ?? false),
        'error' => (string) ($result['error'] ?? ''),
    ];
}

echo json_encode([
    'status' => 'ok',
    'count' => count($results),
    'reachable' => count(array_filter($results, static fn (array $row): bool => $row['reachable'])),
    'snmp_ready' => count(array_filter($results, static fn (array $row): bool => $row['snmp'])),
    'ssh_auth_ready' => count(array_filter($results, static fn (array $row): bool => $row['ssh_auth'])),
    'results' => $results,
    'generated_at' => date(DATE_ATOM),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
