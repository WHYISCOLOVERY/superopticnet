<?php
declare(strict_types=1);

require dirname(__DIR__) . '/noc-authorization-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$checks = [];
$expect = static function (bool $condition, string $name) use (&$checks): void {
    $checks[$name] = $condition;
};

$admin = [
    'id' => 1,
    'role' => 'employee',
    'account_type' => 'admin',
    'employee_role' => 'admin',
    'is_active' => 1,
];
$noc = [
    'id' => 2,
    'role' => 'employee',
    'account_type' => 'employee',
    'employee_role' => 'noc',
    'is_active' => 1,
];
$engineer = [
    'id' => 3,
    'role' => 'employee',
    'account_type' => 'employee',
    'employee_role' => 'engineer',
    'is_active' => 1,
    'responsible_areas' => ['Kurdexani'],
];
$support = [
    'id' => 4,
    'role' => 'employee',
    'account_type' => 'employee',
    'employee_role' => 'support',
    'is_active' => 1,
    'noc_device_keys' => ['olt-support-1'],
];
$operator = [
    'id' => 5,
    'role' => 'employee',
    'account_type' => 'employee',
    'employee_role' => 'operator',
    'is_active' => 1,
];
$inactiveNoc = array_replace($noc, ['is_active' => 0]);
$scopedDevice = [
    'device_key' => 'olt-kurdexani-1',
    'device_name' => 'Kurdexani OLT 1',
    'management_network' => 'vlan550',
];
$foreignDevice = [
    'device_key' => 'olt-astara-1',
    'device_name' => 'Astara OLT 1',
    'management_network' => 'vlan551',
];
$supportDevice = [
    'device_key' => 'olt-support-1',
    'device_name' => 'Scoped support OLT',
    'management_network' => 'vlan552',
];

$expect(!empty(app_noc_authorize($admin, 'update_device_access', $foreignDevice)['ok']), 'admin_sensitive_write_allowed');
$expect(!empty(app_noc_authorize($noc, 'probe_network')['ok']), 'noc_bulk_probe_allowed');
$expect(!empty(app_noc_authorize($noc, 'update_monitoring_mode', $foreignDevice)['ok']), 'noc_monitoring_write_allowed');
$expect(empty(app_noc_authorize($noc, 'update_device_access', $foreignDevice)['ok']), 'noc_credential_write_denied');
$expect(empty(app_noc_authorize($noc, 'execute_device_command', $foreignDevice)['ok']), 'noc_manual_cli_denied');
$expect(!empty(app_noc_authorize($engineer, 'probe_device', $scopedDevice)['ok']), 'engineer_scoped_probe_allowed');
$expect(empty(app_noc_authorize($engineer, 'probe_device', $foreignDevice)['ok']), 'engineer_foreign_probe_denied');
$expect(empty(app_noc_authorize($engineer, 'probe_network')['ok']), 'engineer_bulk_probe_denied');
$expect(!empty(app_noc_authorize($support, 'read_ont_live', $supportDevice)['ok']), 'support_scoped_ont_read_allowed');
$expect(empty(app_noc_authorize($support, 'read_ont_live', $foreignDevice)['ok']), 'support_foreign_ont_read_denied');
$expect(empty(app_noc_authorize($support, 'read_device_sensitive', $supportDevice)['ok']), 'support_sensitive_read_denied');
$expect(empty(app_noc_authorize($operator, 'read_device', $scopedDevice)['ok']), 'operator_network_read_denied');
$expect(empty(app_noc_authorize($inactiveNoc, 'read_device', $scopedDevice)['ok']), 'inactive_noc_denied');
$expect(empty(app_noc_authorize([], 'read_device', $scopedDevice)['ok']), 'missing_actor_denied');
$expect(empty(app_noc_authorize($admin, 'undefined_action', $scopedDevice)['ok']), 'unknown_action_denied');

$ticketEngineer = array_replace($engineer, [
    'ticket_resolution_scope' => true,
    'ticket_resolution_device' => 'Astara OLT 1',
]);
$ticketOperator = array_replace($operator, [
    'ticket_resolution_scope' => true,
    'ticket_resolution_device' => 'Astara OLT 1',
]);
$expect(!empty(app_noc_authorize($ticketEngineer, 'read_ont_live', [])['ok']), 'assigned_ticket_lookup_allowed');
$expect(!empty(app_noc_authorize($ticketEngineer, 'read_ont_live', $foreignDevice)['ok']), 'assigned_ticket_exact_device_allowed');
$expect(empty(app_noc_authorize($ticketEngineer, 'read_ont_live', $supportDevice)['ok']), 'assigned_ticket_other_device_denied');
$expect(empty(app_noc_authorize($ticketOperator, 'read_ont_live', $foreignDevice)['ok']), 'assigned_ticket_operator_denied');

$backupWorker = app_noc_system_actor('device-backup-worker');
$probeWorker = app_noc_system_actor('device-probe-worker');
$securityWorker = app_noc_system_actor('security-anomaly-worker');
$expect(!empty(app_noc_authorize($backupWorker, 'apply_device_backup', $foreignDevice)['ok']), 'backup_worker_apply_allowed');
$expect(empty(app_noc_authorize($backupWorker, 'execute_device_command', $foreignDevice)['ok']), 'backup_worker_cli_denied');
$expect(!empty(app_noc_authorize($probeWorker, 'probe_network')['ok']), 'probe_worker_bulk_probe_allowed');
$expect(empty(app_noc_authorize($probeWorker, 'apply_device_backup', $foreignDevice)['ok']), 'probe_worker_backup_denied');
$expect(!empty(app_noc_authorize($securityWorker, 'run_security_anomaly_scan')['ok']), 'security_worker_scan_allowed');
$expect(empty(app_noc_authorize($securityWorker, 'execute_device_command', $foreignDevice)['ok']), 'security_worker_cli_denied');

$sourceRoot = dirname(__DIR__);
$guardedFunctions = [
    'app_mgs_noc_service_request_json',
    'app_mgs_set_management_path',
    'app_mgs_sync_process_registry',
    'app_mgs_probe_ip',
    'app_mgs_probe_tcp_port',
    'app_mgs_probe_udp_port_nmap',
    'app_mgs_probe_ssh_auth',
    'app_mgs_probe_snmp',
    'app_mgs_probe_device_services',
    'app_mgs_hsgq_cli_discovery_probe',
    'app_mgs_hsgq_exec_commands',
    'app_mgs_hsgq_live_ont_snapshot',
    'app_mgs_hsgq_device_by_olt_name',
    'app_mgs_execute_device_command_plan',
    'app_mgs_execute_hsgq_ont_control',
    'app_mgs_hsgq_ont_control_plan',
    'app_mgs_device_command_map',
    'app_mgs_sync_device_command_map',
    'app_mgs_device_access_profile',
    'app_mgs_set_device_access_override',
    'app_mgs_apply_vendor_access_defaults',
    'app_mgs_refresh_device_reachability',
    'app_mgs_device_learning_profile_by_key',
    'app_mgs_probe_single_device',
    'app_mgs_set_device_monitoring_mode',
    'app_mgs_modem_search_candidates',
    'app_mgs_hsgq_live_find_ont_by_query',
    'app_mgs_build_modem_search_reply',
    'app_mgs_monitor_map_layout',
    'app_mgs_save_monitor_map_node',
    'app_mgs_device_learning_rows',
    'app_mgs_analyze_olt_ticket_rules',
    'app_mgs_latest_device_backup',
    'app_mgs_store_device_backup',
    'app_mgs_run_backup_worker',
    'app_mgs_record_alarm_event',
    'app_mgs_noc_recent_rows',
    'app_mgs_summary',
    'app_mgs_copilot_context',
    'router_registry_save',
    'router_registry_set_active',
    'router_registry_find',
    'router_registry_list',
    'router_registry_resolve_customer_creation_router',
    'router_registry_bind_customer',
    'router_registry_customer_router',
];

require_once $sourceRoot . '/mgs-integration-lib.php';
require_once $sourceRoot . '/router-registry-lib.php';
require_once $sourceRoot . '/security-anomaly-lib.php';

$blockedProbe = app_mgs_probe_ip('127.0.0.1', 50, $engineer, $foreignDevice);
$expect(
    empty($blockedProbe['reachable']) && ($blockedProbe['reason'] ?? '') === 'noc_object_scope_denied',
    'service_probe_foreign_scope_denied_before_io'
);
$blockedSnmp = app_mgs_probe_snmp('127.0.0.1', 'smoke', 161, 50, $operator, $scopedDevice);
$expect(
    empty($blockedSnmp['reachable']) && ($blockedSnmp['reason'] ?? '') === 'noc_module_denied',
    'service_snmp_operator_denied_before_io'
);
$blockedCli = app_mgs_hsgq_exec_commands(
    '127.0.0.1',
    'smoke',
    'smoke',
    ['show version'],
    22,
    50,
    $noc,
    $foreignDevice
);
$expect(
    empty($blockedCli['ok']) && ($blockedCli['reason'] ?? '') === 'noc_action_denied',
    'service_manual_cli_noc_denied_before_io'
);
foreach ($guardedFunctions as $functionName) {
    $reflection = new ReflectionFunction($functionName);
    $lines = file($reflection->getFileName(), FILE_IGNORE_NEW_LINES);
    $body = is_array($lines)
        ? implode("\n", array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1))
        : '';
    $hasGuard = str_contains($body, 'app_noc_authorize(')
        || str_contains($body, 'router_registry_actor_can_manage_bindings(');
    $expect($hasGuard, 'service_guard_' . $functionName);
}

foreach (['app_security_anomaly_record', 'app_security_anomaly_run'] as $functionName) {
    $reflection = new ReflectionFunction($functionName);
    $lines = file($reflection->getFileName(), FILE_IGNORE_NEW_LINES);
    $body = is_array($lines)
        ? implode("\n", array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1))
        : '';
    $expect(str_contains($body, 'app_noc_authorize('), 'service_guard_' . $functionName);
}

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
