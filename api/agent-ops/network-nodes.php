<?php
declare(strict_types=1);

/** Read-only node registry for the SuperOptic agent and NOC views. */
require dirname(__DIR__, 2) . '/mgs-integration-lib.php';
require_once dirname(__DIR__, 2) . '/crew-service-auth-lib.php';
app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function network_nodes_fail(int $status, string $reason): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    network_nodes_fail(405, 'method_not_allowed');
}
$db = app_db();
if (!$db instanceof PDO) {
    network_nodes_fail(503, 'database_unavailable');
}
$actor = app_crew_service_actor($db, app_crew_service_bearer());
if ($actor === null) {
    network_nodes_fail(401, 'auth_required');
}
if (!app_portal_can_access_module($actor, 'network')) {
    network_nodes_fail(403, 'module_denied');
}

$path = dirname(__DIR__, 2) . '/config/network_nodes.json';
$registry = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
if (!is_array($registry) || !is_array($registry['nodes'] ?? null)) {
    network_nodes_fail(503, 'node_registry_unavailable');
}

$rows = $db->query(
    'SELECT device_key, device_name, ip_address, device_type, vendor, model,
            management_network, monitoring_enabled, reachability_state, status,
            last_probe_at, last_probe_error, last_successful_ssh_auth_at,
            last_successful_snmp_at, service_probe_json
     FROM device_learning_profiles
     ORDER BY ip_address, id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byIp = [];
foreach ($rows as $row) {
    $ip = trim((string) ($row['ip_address'] ?? ''));
    if ($ip !== '' && !isset($byIp[$ip])) {
        $byIp[$ip] = $row;
    }
}

$readOps = array_values(array_map('strval', $registry['read_only_operations'] ?? []));
$approvalOps = array_values(array_map('strval', $registry['approval_required_operations'] ?? []));
$nodes = [];
foreach ($registry['nodes'] as $declared) {
    if (!is_array($declared)) {
        continue;
    }
    $ip = trim((string) ($declared['ip'] ?? ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        continue;
    }
    $stored = $byIp[$ip] ?? null;
    $probeError = is_array($stored) ? trim((string) ($stored['last_probe_error'] ?? '')) : 'device_not_registered';
    $historicalSnmp = is_array($stored) && trim((string) ($stored['last_successful_snmp_at'] ?? '')) !== '';
    $snmpState = !is_array($stored)
        ? 'not_registered'
        : ($probeError === 'management_path_down'
            ? ($historicalSnmp ? 'stale_path_down' : 'unknown_path_down')
            : ($historicalSnmp ? 'confirmed_historical' : 'unknown'));
    $nodes[] = [
        'id' => (string) ($declared['id'] ?? $ip),
        'ip' => $ip,
        'name' => (string) (($stored['device_name'] ?? '') ?: ($declared['name'] ?? $ip)),
        'type' => (string) (($stored['device_type'] ?? '') ?: ($declared['type'] ?? 'unknown')),
        'vendor' => (string) (($stored['vendor'] ?? '') ?: ($declared['vendor'] ?? 'unknown')),
        'model' => (string) ($stored['model'] ?? ''),
        'management_network' => (string) (($stored['management_network'] ?? '') ?: ($declared['management_network'] ?? '')),
        'registered' => is_array($stored),
        'device_key' => is_array($stored) ? (string) ($stored['device_key'] ?? '') : null,
        'monitoring_enabled' => is_array($stored) ? (int) ($stored['monitoring_enabled'] ?? 0) === 1 : false,
        'reachability_state' => is_array($stored) ? (string) ($stored['reachability_state'] ?? 'unknown') : 'not_registered',
        'status' => is_array($stored) ? (string) ($stored['status'] ?? '') : 'not_registered',
        'last_probe_at' => is_array($stored) ? ($stored['last_probe_at'] ?: null) : null,
        'last_probe_error' => is_array($stored) ? ($stored['last_probe_error'] ?: null) : 'device_not_registered',
        'last_successful_ssh_auth_at' => is_array($stored) ? ($stored['last_successful_ssh_auth_at'] ?: null) : null,
        'last_successful_snmp_at' => is_array($stored) ? ($stored['last_successful_snmp_at'] ?: null) : null,
        'snmp_state' => $snmpState,
        'read_only_operations' => $readOps,
        'approval_required_operations' => $approvalOps,
        'next_action' => !is_array($stored)
            ? 'register_device_profile_and_access'
            : ($probeError === 'management_path_down'
                ? 'reconnect_management_path_then_revalidate'
                : ($historicalSnmp ? 'monitor_and_collect_history' : 'stage_snmp_enable_plan')),
    ];
}

echo json_encode([
    'ok' => true,
    'source' => (string) ($registry['source'] ?? 'declared_registry'),
    'checked_at' => date(DATE_ATOM),
    'nodes' => $nodes,
    'counts' => [
        'declared' => count($nodes),
        'registered' => count(array_filter($nodes, static fn(array $node): bool => $node['registered'])),
        'snmp_confirmed' => count(array_filter($nodes, static fn(array $node): bool => $node['snmp_state'] === 'confirmed_historical')),
        'snmp_stale' => count(array_filter($nodes, static fn(array $node): bool => $node['snmp_state'] === 'stale_path_down')),
        'approval_required' => count(array_filter($nodes, static fn(array $node): bool => in_array($node['next_action'], ['stage_snmp_enable_plan', 'register_device_profile_and_access'], true))),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
