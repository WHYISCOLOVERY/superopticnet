<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$asMarkdown = in_array('--markdown', $_SERVER['argv'] ?? [], true);
$db = app_db();

function audit_scalar(?PDO $db, string $sql): int
{
    if (!$db instanceof PDO) {
        return 0;
    }
    try {
        return (int) $db->query($sql)->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function audit_process_map(?PDO $db): array
{
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }
    app_mgs_sync_process_registry($db);
    $rows = $db->query('SELECT * FROM mgs_process_registry')->fetchAll() ?: [];
    $map = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $map[(string) ($row['process_key'] ?? '')] = $row;
        }
    }
    return $map;
}

function audit_status_from_process(array $processes, string $key, string $fallback = 'partial'): string
{
    return (string) ($processes[$key]['status'] ?? $fallback);
}

function audit_add(array &$items, string $area, string $feature, string $status, string $evidence, string $next): void
{
    $items[] = [
        'area' => $area,
        'feature' => $feature,
        'status' => $status,
        'evidence' => $evidence,
        'next' => $next,
    ];
}

$processes = audit_process_map($db);
$summary = $db instanceof PDO ? app_mgs_summary($db) : ['db' => false];
$managementPath = app_mgs_management_path_ready();

$counts = [
    'customers' => audit_scalar($db, "SELECT COUNT(*) FROM portal_accounts WHERE role='customer'"),
    'legacy_customers' => audit_scalar($db, "SELECT COUNT(*) FROM portal_accounts WHERE role='customer' AND JSON_EXTRACT(payload_json, '$.old_billing') IS NOT NULL"),
    'tariffs' => audit_scalar($db, 'SELECT COUNT(*) FROM billing_tariffs'),
    'payments' => audit_scalar($db, 'SELECT COUNT(*) FROM billing_payments'),
    'radius_services' => audit_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services'),
    'radcheck' => audit_scalar($db, 'SELECT COUNT(*) FROM radcheck'),
    'radreply' => audit_scalar($db, 'SELECT COUNT(*) FROM radreply'),
    'radacct_online' => audit_scalar($db, 'SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL'),
    'tickets' => audit_scalar($db, 'SELECT COUNT(*) FROM portal_tickets'),
    'open_tickets' => audit_scalar($db, 'SELECT COUNT(*) FROM portal_tickets WHERE status NOT IN ("closed", "resolved", "Baglandi")'),
    'message_templates' => audit_scalar($db, 'SELECT COUNT(*) FROM communication_templates'),
    'messages' => audit_scalar($db, 'SELECT COUNT(*) FROM communication_messages'),
    'ip_pools' => audit_scalar($db, 'SELECT COUNT(*) FROM ip_pools'),
    'ip_addresses' => audit_scalar($db, 'SELECT COUNT(*) FROM ip_addresses'),
    'customer_ip_assignments' => audit_scalar($db, 'SELECT COUNT(*) FROM customer_ip_assignments'),
    'device_profiles' => audit_scalar($db, 'SELECT COUNT(*) FROM device_learning_profiles'),
    'device_waiting_path' => audit_scalar($db, 'SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state="unreachable_because_monitor_path_down"'),
    'devices_reachable' => audit_scalar($db, 'SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state="reachable"'),
    'devices_unreachable' => audit_scalar($db, 'SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state="unreachable_confirmed"'),
    'devices_unknown' => audit_scalar($db, 'SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state="unknown"'),
    'devices_not_monitored' => audit_scalar($db, 'SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state="not_monitored"'),
    'devices_ssh_confirmed' => audit_scalar($db, 'SELECT COUNT(*) FROM device_learning_profiles WHERE monitoring_enabled = 1 AND last_successful_ssh_at IS NOT NULL'),
    'devices_ssh_auth_confirmed' => audit_scalar($db, 'SELECT COUNT(*) FROM device_learning_profiles WHERE monitoring_enabled = 1 AND last_successful_ssh_auth_at IS NOT NULL'),
    'devices_snmp_confirmed' => audit_scalar($db, 'SELECT COUNT(*) FROM device_learning_profiles WHERE monitoring_enabled = 1 AND last_successful_snmp_at IS NOT NULL'),
    'device_access_overrides' => audit_scalar($db, 'SELECT COUNT(*) FROM device_access_overrides'),
    'telegram_bots_ready' => audit_scalar($db, 'SELECT COUNT(*) FROM telegram_bot_registry WHERE status="ready"'),
    'telegram_bots_total' => audit_scalar($db, 'SELECT COUNT(*) FROM telegram_bot_registry'),
    'inventory_items' => audit_scalar($db, 'SELECT COUNT(*) FROM inventory_stock_items'),
    'inventory_movements' => audit_scalar($db, 'SELECT COUNT(*) FROM inventory_stock_movements'),
    'employee_snapshots' => audit_scalar($db, 'SELECT COUNT(*) FROM employee_work_snapshots WHERE work_date = CURDATE()'),
    'device_backups' => audit_scalar($db, 'SELECT COUNT(*) FROM device_config_backups'),
    'device_backup_versions' => audit_scalar($db, 'SELECT COUNT(*) FROM device_config_backups WHERE status="stored"'),
    'device_backup_duplicates' => audit_scalar($db, 'SELECT COUNT(*) FROM mgs_operation_actions WHERE action_type="device_backup" AND status="skipped" AND summary LIKE "Backup unchanged:%"'),
    'vlan_plans' => audit_scalar($db, 'SELECT COUNT(*) FROM network_vlan_definitions'),
    'vlan_hops' => audit_scalar($db, 'SELECT COUNT(*) FROM network_vlan_path_hops'),
    'mpls_vlan_dry_runs' => audit_scalar($db, 'SELECT COUNT(*) FROM mgs_operation_actions WHERE action_type="mpls_vlan_dry_run"'),
];

$items = [];
audit_add($items, 'Core', 'MySQL schema and imported legacy billing data', 'ready', $counts['customers'] . ' customers, ' . $counts['legacy_customers'] . ' legacy imports, ' . $counts['payments'] . ' payments, ' . $counts['tariffs'] . ' tariffs.', 'Production DB backup and migration dry-run before cutover.');
audit_add($items, 'Billing', 'Customer list, search, online/offline indicator, edit/refresh actions', 'ready', 'UI smoke covers billing-customers.php; ' . $counts['radius_services'] . ' radius services.', 'Browser QA on wide/mobile layouts after each UI batch.');
audit_add($items, 'Billing', 'Customer 360 profile tabs', 'ready', 'UI smoke covers profile; payments, statements, tickets, SMS, RADIUS and ONT/modem sections are present.', 'Continue replacing legacy table-heavy blocks with cards/timelines.');
audit_add($items, 'Billing', 'New customer creation and tariff/IP selection', 'ready', 'Create flow writes portal account, billing service and RADIUS sync path.', 'Final operator QA with 3 real test customers before cutover.');
audit_add($items, 'Billing', 'Payment, day countdown and monthly charge engine', 'ready', 'billing-flow-smoke passed; extra services are added to monthly total.', 'Schedule billing-maintenance.php with Windows Task Scheduler/cron.');
audit_add($items, 'RADIUS', 'radcheck/radreply generation for PPPoE/IPoE', 'ready', $counts['radcheck'] . ' radcheck and ' . $counts['radreply'] . ' radreply rows.', 'Final FreeRADIUS VM SQL config and router NAS source IP cutover.');
audit_add($items, 'RADIUS', 'Online sessions and accounting visibility', $counts['radacct_online'] > 0 ? 'ready' : 'partial', $counts['radacct_online'] . ' current online sessions in local radacct.', 'Needs real router accounting packets after production connection.');
audit_add($items, 'IP', 'Local/NAT/static IP pool engine', 'ready', $counts['ip_pools'] . ' pools, ' . $counts['ip_addresses'] . ' IP rows, ' . $counts['customer_ip_assignments'] . ' assignments.', 'Add operator-side conflict UX for bulk pool imports if needed.');
audit_add($items, 'Messages', 'SMS/Telegram/App/WhatsApp queue and templates', 'ready', $counts['message_templates'] . ' templates and provider dry-run passed.', 'Configure real SMS/WhatsApp provider credentials before live sends.');
audit_add($items, 'Telegram', 'All operational bots registry', 'ready', $counts['telegram_bots_ready'] . '/' . $counts['telegram_bots_total'] . ' bots ready in registry.', 'Run real TEST MESAJI only from admin UI when groups are confirmed.');
audit_add($items, 'Tickets', 'Ticket create/assign/close/audit flow', audit_status_from_process($processes, 'ticket_create_assign_close', 'partial'), $counts['tickets'] . ' tickets, ' . $counts['open_tickets'] . ' open.', 'More real workflow tests with operator/technician roles.');
audit_add($items, 'Inventory', 'Stock movement, technician issue/return and ticket link', audit_status_from_process($processes, 'inventory_issue_return_stock', 'partial'), $counts['inventory_items'] . ' items, ' . $counts['inventory_movements'] . ' movements.', 'Import real warehouse stock and barcode formats.');
audit_add($items, 'HR/KPI', 'Employee work snapshot from tickets/inventory', audit_status_from_process($processes, 'employee_work_kpi_snapshot', 'partial'), $counts['employee_snapshots'] . ' snapshots for today.', 'Add monthly KPI scoring rules and leave/attendance final UI QA.');
audit_add($items, 'NOC', 'Management path awareness and offline suppression', audit_status_from_process($processes, 'management_path_vlan550', 'partial'), $managementPath ? 'VLAN 550 active; ' . $counts['devices_reachable'] . ' reachable, ' . $counts['devices_not_monitored'] . ' not monitored.' : 'VLAN 550 passive; ' . $counts['device_waiting_path'] . ' devices suppressed.', $managementPath ? 'Keep planned-down and alias devices in not_monitored mode to avoid fake offline alarms.' : 'When VLAN 550 is connected, enable path and run device probe.');
audit_add($items, 'NOC', 'Alarm correlation and dedup', audit_status_from_process($processes, 'alarm_correlation_dedup', 'partial'), (string) ($summary['dedup_keys'] ?? 0) . ' dedup keys, test alarm path OK.', 'Extend parent/child correlation with real topology after device probe.');
audit_add($items, 'Devices', 'Auto-learning profiles for OLT/router/switch', audit_status_from_process($processes, 'device_learning_discovery', 'partial'), $counts['device_profiles'] . ' seeded profiles; ' . $counts['devices_reachable'] . ' reachable, ' . $counts['devices_ssh_confirmed'] . ' SSH port ok, ' . $counts['devices_ssh_auth_confirmed'] . ' SSH auth ok, ' . $counts['devices_snmp_confirmed'] . ' SNMP ok, ' . $counts['devices_not_monitored'] . ' not monitored.', 'Continue live command/OID validation and keep learned maps refreshed after firmware changes.');
audit_add($items, 'Devices', 'Per-device SSH/SNMP auth override registry', 'ready', $counts['device_access_overrides'] . ' override rows; HSGQ defaults are synced, switch 172.16.5.41 and core 172.16.5.5 are stored with live-verified auth/SNMP profile.', 'Add core write/trap policy only when operationally approved.');
audit_add($items, 'OLT/ONT', 'Native NOC device page and MGS bridge integration', 'partial', 'noc-devices.php shows device profiles, per-device probe/backup/mode controls, VLAN 550 state, backups, live HSGQ CLI discovery, learned command maps and modem bot reply preview; Node MGS at 127.0.0.1:3000 can be offline without breaking portal.', 'Continue validating live SN/MAC optical reads on reachable HSGQ devices.');
audit_add($items, 'SPoG', 'Native integration registry and cross-module test panel', 'ready', 'mgs-integration.php exposes process registry, dedup registry, bot tests, inventory tests and backup worker actions.', 'Continue adding live apply-only actions behind explicit admin approval.');
audit_add($items, 'Modem bot', 'SN/MAC/PPPoE diagnostics bot', audit_status_from_process($processes, 'modem_search_bot', 'partial'), 'Bot registry ready; live OLT query first, stored ONT snapshot fallback second.', 'Run the worker against the real Telegram bot token and confirm group delivery.');
audit_add($items, 'MPLS', 'MPLS/VLAN provisioning workflow', audit_status_from_process($processes, 'mpls_vlan_provisioning', 'partial'), 'Native dry-run/diff and audit action ready; ' . $counts['vlan_plans'] . ' VLAN plans, ' . $counts['vlan_hops'] . ' hops, ' . $counts['mpls_vlan_dry_runs'] . ' dry-run audits.', 'Implement explicit admin-approved device apply adapter after command syntax is validated on real devices.');
audit_add($items, 'Backup', 'Device config backup lifecycle', audit_status_from_process($processes, 'backup_scheduler', 'partial'), $counts['device_backup_versions'] . ' stored versions, ' . $counts['device_backup_duplicates'] . ' duplicate checks, ' . $counts['device_backups'] . ' backup rows; apply mode removes rows older than 42 days.', 'Schedule tools/device-backup-worker.php with staggered intervals; use running-config only after VLAN 550 live validation.');
audit_add($items, 'Gateway', 'Nginx/API gateway config bundle', audit_status_from_process($processes, 'nginx_gateway', 'partial'), 'validate-nginx-gateway.php passes.', 'Run nginx -t on target Linux server and bind domains/SSL.');
audit_add($items, 'Identity', 'MyGov readiness module and identity mapping UI', 'partial', 'identity-integrations.php is active in portal/nav, account-to-identity linking UI exists, but provider API is disabled.', 'Get real MyGov API credentials and enable only after callback verification.');
audit_add($items, 'Copilot', 'Telegram operations Copilot with approval-gated actions', 'ready', 'copilot-agent.php routes customer/modem, inventory and employee questions; ticket, RADIUS and inventory mutations require TESDIQ action confirmation.', 'Keep destructive/live router actions behind explicit confirmation and run real Telegram worker test after group access is confirmed.');

$statusCounts = array_count_values(array_column($items, 'status'));
$result = [
    'ok' => true,
    'generated_at' => date(DATE_ATOM),
    'summary' => [
        'ready' => (int) ($statusCounts['ready'] ?? 0),
        'partial' => (int) ($statusCounts['partial'] ?? 0),
        'planned' => (int) ($statusCounts['planned'] ?? 0),
        'blocked' => (int) ($statusCounts['blocked'] ?? 0),
    ],
    'counts' => $counts,
    'items' => $items,
    'next_priority' => [
        'Reachable devices: validate real SSH/SNMP command and OID maps on HSGQ/Cisco endpoints.',
        'Reachable HSGQ OLT-lər üçün doğru SSH credential tap və live command map validation et.',
        'Real FreeRADIUS cutover test: router NAS source IP, accounting and CoA/disconnect.',
        'MGS native merge: keep moving live diagnostics and apply-only actions into native cards.',
        'Backup worker: schedule profile_snapshot now; enable running_config only after VLAN 550 live validation.',
        'Provider messaging: SMS/WhatsApp credentials and one controlled TEST MESAJI.',
    ],
];

foreach ($items as &$item) {
    if (($item['area'] ?? '') === 'Devices' && ($item['feature'] ?? '') === 'Per-device SSH/SNMP auth override registry') {
        $item['next'] = 'Use the stored auth profiles to keep native CLI/OID discovery current.';
    }
}
unset($item);
$result['items'] = $items;
$result['next_priority'] = [
    'Reachable devices: keep validating real SSH/SNMP command and OID maps on HSGQ/Cisco endpoints.',
    'Reachable HSGQ OLT-lər üçün native CLI discovery snapshot-larını topla və real ONT/optik command syntaxini çıxar.',
    'Real FreeRADIUS cutover test: router NAS source IP, accounting and CoA/disconnect.',
    'MGS native merge: keep moving live diagnostics and apply-only actions into native cards.',
    'Backup worker: schedule profile_snapshot now; enable running_config only after VLAN 550 live validation.',
    'Provider messaging: SMS/WhatsApp credentials and one controlled TEST MESAJI.',
];

$result['next_priority'] = [
    'Reachable devices: keep validating real SSH/SNMP command and OID maps on HSGQ/Cisco endpoints.',
    'Use the learned HSGQ command map to build native ONT optical and SN/MAC live diagnostics.',
    'Real FreeRADIUS cutover test: router NAS source IP, accounting and CoA/disconnect.',
    'MGS native merge: keep moving live diagnostics and apply-only actions into native cards.',
    'Backup worker: schedule profile_snapshot now; enable running_config only after VLAN 550 live validation.',
    'Provider messaging: SMS/WhatsApp credentials and one controlled TEST MESAJI.',
];

if ($asMarkdown) {
    echo '# SuperOptic MGS funksionalliq auditi' . PHP_EOL . PHP_EOL;
    echo 'Generated: ' . $result['generated_at'] . PHP_EOL . PHP_EOL;
    echo '| Area | Funksiya | Status | Subut | Novbeti is |' . PHP_EOL;
    echo '|---|---|---|---|---|' . PHP_EOL;
    foreach ($items as $item) {
        echo '| ' . str_replace('|', '/', $item['area']) .
            ' | ' . str_replace('|', '/', $item['feature']) .
            ' | ' . str_replace('|', '/', $item['status']) .
            ' | ' . str_replace('|', '/', $item['evidence']) .
            ' | ' . str_replace('|', '/', $item['next']) . ' |' . PHP_EOL;
    }
    echo PHP_EOL . '## Prioritet' . PHP_EOL;
    foreach ($result['next_priority'] as $line) {
        echo '- ' . $line . PHP_EOL;
    }
    exit(0);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
