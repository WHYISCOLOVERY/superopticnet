<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$root = dirname(__DIR__);
$php = PHP_BINARY;
$startedAt = microtime(true);

/**
 * @return array<string,mixed>
 */
function regression_run_command(string $name, array $args, int $timeoutSeconds = 120): array
{
    global $root, $php;

    $command = array_merge([$php], $args);
    $display = implode(' ', array_map(static fn (string $part): string => str_contains($part, ' ') ? '"' . $part . '"' : $part, $command));
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $started = microtime(true);
    $process = proc_open($command, $descriptorSpec, $pipes, $root);
    if (!is_resource($process)) {
        return [
            'name' => $name,
            'status' => 'fail',
            'command' => $display,
            'exit_code' => null,
            'duration_ms' => 0,
            'stdout' => '',
            'stderr' => 'proc_open_failed',
        ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $timedOut = false;

    while (true) {
        $status = proc_get_status($process);
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        if (!$status['running']) {
            break;
        }

        if ((microtime(true) - $started) > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process);
            usleep(200000);
            break;
        }

        usleep(100000);
    }

    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $durationMs = (int) round((microtime(true) - $started) * 1000);
    $status = (!$timedOut && $exitCode === 0) ? 'pass' : 'fail';

    return [
        'name' => $name,
        'status' => $timedOut ? 'timeout' : $status,
        'command' => $display,
        'exit_code' => $timedOut ? null : $exitCode,
        'duration_ms' => $durationMs,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
}

/**
 * @return array<string,mixed>
 */
function regression_skip(string $name, string $reason): array
{
    return [
        'name' => $name,
        'status' => 'skip',
        'reason' => $reason,
    ];
}

$tests = [
    ['php_lint_core', ['-l', 'config.php'], 30],
    ['php_lint_db', ['-l', 'db.php'], 30],
    ['php_lint_billing_customers', ['-l', 'billing-customers.php'], 30],
    ['php_lint_area_code_prefixes', ['-l', 'area-code-prefixes.php'], 30],
    ['php_lint_customer_profile', ['-l', 'billing-customer-profile.php'], 30],
    ['php_lint_operations_center', ['-l', 'operations-center.php'], 30],
    ['php_lint_feature_flags_lib', ['-l', 'feature-flags-lib.php'], 30],
    ['php_lint_analytics_center_lib', ['-l', 'analytics-center-lib.php'], 30],
    ['php_lint_ui_language_lib', ['-l', 'ui-language-lib.php'], 30],
    ['php_lint_finance_bonus_lib', ['-l', 'finance-bonus-lib.php'], 30],
    ['php_lint_finance_bonus_view', ['-l', 'views/finance/employee-bonuses.php'], 30],
    ['php_lint_admin_users', ['-l', 'admin-users.php'], 30],
    ['php_lint_copilot_widget', ['-l', 'copilot-widget.php'], 30],
    ['php_lint_copilot_agent_api', ['-l', 'api/copilot/agent-chat.php'], 30],
    ['php_lint_noc_devices', ['-l', 'noc-devices.php'], 30],
    ['php_lint_noc_security', ['-l', 'noc-security.php'], 30],
    ['php_lint_copilot_agent', ['-l', 'copilot-agent.php'], 30],
    ['php_lint_role_agent_lib', ['-l', 'role-agent-lib.php'], 30],
    ['php_lint_security_anomaly_lib', ['-l', 'security-anomaly-lib.php'], 30],
    ['php_lint_audit_log_lib', ['-l', 'audit-log-lib.php'], 30],
    ['php_lint_communication_provider', ['-l', 'communication-provider.php'], 30],
    ['php_lint_message_center', ['-l', 'message-center.php'], 30],
    ['php_lint_whatsapp_monitor_lib', ['-l', 'whatsapp-monitor-lib.php'], 30],
    ['php_lint_whatsapp_monitor_page', ['-l', 'whatsapp-monitor.php'], 30],
    ['php_lint_whatsapp_monitor_module', ['-l', 'views/whatsapp-monitor/module.php'], 30],
    ['php_lint_whatsapp_monitor_overview_view', ['-l', 'views/whatsapp-monitor/overview.php'], 30],
    ['php_lint_whatsapp_monitor_screen_wall_view', ['-l', 'views/whatsapp-monitor/screen-wall.php'], 30],
    ['php_lint_whatsapp_monitor_numbers_view', ['-l', 'views/whatsapp-monitor/numbers.php'], 30],
    ['php_lint_whatsapp_monitor_devices_view', ['-l', 'views/whatsapp-monitor/devices.php'], 30],
    ['php_lint_whatsapp_monitor_cases_view', ['-l', 'views/whatsapp-monitor/cases.php'], 30],
    ['php_lint_whatsapp_monitor_performance_view', ['-l', 'views/whatsapp-monitor/performance.php'], 30],
    ['php_lint_whatsapp_monitor_settings_view', ['-l', 'views/whatsapp-monitor/settings.php'], 30],
    ['php_lint_whatsapp_monitor_audit_view', ['-l', 'views/whatsapp-monitor/audit.php'], 30],
    ['php_lint_whatsapp_monitor_chats_view', ['-l', 'views/whatsapp-monitor/chats.php'], 30],
    ['php_lint_whatsapp_monitor_pairing_view', ['-l', 'views/whatsapp-monitor/pairing.php'], 30],
    ['php_lint_whatsapp_pairing_queue_api', ['-l', 'api/whatsapp-monitor/pairing-queue.php'], 30],
    ['php_lint_whatsapp_pairing_update_api', ['-l', 'api/whatsapp-monitor/pairing-update.php'], 30],
    ['php_lint_whatsapp_pairing_status_api', ['-l', 'api/whatsapp-monitor/pairing-status.php'], 30],
    ['php_lint_whatsapp_message_ingest_api', ['-l', 'api/whatsapp-monitor/message-ingest.php'], 30],
    ['php_lint_whatsapp_chat_messages_api', ['-l', 'api/whatsapp-monitor/chat-messages.php'], 30],
    ['php_lint_whatsapp_chat_list_api', ['-l', 'api/whatsapp-monitor/chat-list.php'], 30],
    ['php_lint_operation_logs', ['-l', 'operation-logs.php'], 30],
    ['php_lint_operation_logs_export', ['-l', 'operation-logs-export.php'], 30],
    ['php_lint_system_maintenance_optimizer', ['-l', 'tools/system-maintenance-optimizer.php'], 30],
    ['php_lint_network_olt_ticket_worker', ['-l', 'tools/process-network-olt-ticket-rules.php'], 30],
    ['portal_login_security', ['tools/portal-login-security-smoke.php'], 120],
    ['portal_login_password', ['tools/portal-login-password-smoke.php'], 120],
    ['portal_login_public_safety', ['tools/portal-login-public-safety-smoke.php'], 120],
    ['security_injection_and_rate_limit', ['tools/security-injection-smoke.php'], 120],
    ['audit_ledger', ['tools/audit-ledger-smoke.php'], 120],
    ['audit_permissions', ['tools/audit-permission-smoke.php'], 120],
    ['employee_login_compat', ['tools/employee-login-compat-smoke.php'], 120],
    ['operational_e2e_customer_ticket_inventory_radius', ['tools/operational-e2e-smoke.php'], 120],
    ['role_agent_e2e_real_workflows', ['tools/role-agent-e2e-smoke.php'], 120],
    ['customer_portal_flow', ['tools/customer-portal-flow-smoke.php'], 120],
    ['billing_flow_create_pay_radius_cleanup', ['tools/billing-flow-smoke.php'], 120],
    ['radius_billing_cycle_bulk', ['tools/radius-docker-billing-cycle-smoke.php', '--count=12'], 180],
    ['radius_wsl_customer_lifecycle', ['tools/radius-wsl-customer-lifecycle-smoke.php'], 180],
    ['billing_customer_edit', ['tools/billing-customer-edit-smoke.php'], 120],
    ['billing_customer_identity', ['tools/billing-customer-identity-smoke.php'], 120],
    ['billing_stable_create', ['tools/billing-stable-create-smoke.php'], 120],
    ['billing_stable_day_count', ['tools/billing-stable-day-smoke.php'], 120],
    ['billing_stable_add_service', ['tools/billing-stable-add-service-smoke.php'], 120],
    ['billing_stable_payment', ['tools/billing-stable-payment-smoke.php'], 120],
    ['billing_partial_payment_guard', ['tools/billing-partial-payment-smoke.php'], 120],
    ['billing_customer_list_order', ['tools/billing-customer-list-order-smoke.php'], 120],
    ['billing_statistics_selector_ajax', ['tools/billing-statistics-selector-smoke.php'], 120],
    ['performance_indexes', ['tools/performance-index-smoke.php'], 120],
    ['concurrent_operator_render', ['tools/concurrency-smoke.php'], 120],
    ['customer_activity_revenue_intelligence', ['tools/customer-intelligence-smoke.php'], 120],
    ['area_customer_code_generation', ['tools/area-customer-code-smoke.php'], 120],
    ['admin_inventory_copilot_ui', ['tools/admin-inventory-copilot-ui-smoke.php'], 120],
    ['feature_flags_local_fallback', ['tools/feature-flags-smoke.php'], 120],
    ['analytics_center_context', ['tools/analytics-center-smoke.php'], 120],
    ['ui_language_shell_standard', ['tools/ui-language-shell-smoke.php'], 120],
    ['portal_workflow_guide', ['tools/portal-workflow-guide-smoke.php'], 120],
    ['finance_bonus_module', ['tools/finance-bonus-module-smoke.php'], 120],
    ['finance_bonus_filter_and_rules_ui', ['tools/finance-bonus-filter-smoke.php'], 120],
    ['inventory_lifecycle_acceptance', ['tools/inventory-lifecycle-smoke.php'], 120],
    ['inventory_document_print', ['tools/inventory-document-print-smoke.php'], 120],
    ['inventory_equipment_kpi_flow', ['tools/inventory-equipment-kpi-flow-smoke.php'], 120],
    ['customer_profile_equipment_lifecycle', ['tools/customer-profile-equipment-lifecycle-smoke.php'], 120],
    ['customer_profile_radius_refresh', ['tools/customer-profile-radius-refresh-smoke.php'], 120],
    ['provider_api_lookup_payment_duplicate', ['tools/provider-api-smoke.php'], 120],
    ['copilot_customer_analysis', ['tools/copilot-customer-analysis-smoke.php'], 120],
    ['copilot_agent_actions', ['tools/copilot-agent-smoke.php'], 120],
    ['security_anomaly_engine', ['tools/security-anomaly-smoke.php'], 120],
    ['telegram_command_actions', ['tools/telegram-command-actions-smoke.php'], 120],
    ['telegram_bot_routing', ['tools/telegram-bot-routing-smoke.php'], 120],
    ['message_center_channel_chart', ['tools/message-center-channel-chart-smoke.php'], 120],
    ['sms_provider_config', ['tools/sms-provider-config-smoke.php'], 120],
    ['message_network_customer_separation', ['tools/message-network-customer-separation-smoke.php'], 120],
    ['portal_module_access', ['tools/portal-module-access-smoke.php'], 120],
    ['operations_module', ['tools/operations-module-smoke.php'], 120],
    ['operations_reports_ui', ['tools/operations-reports-ui-smoke.php'], 120],
    ['whatsapp_monitor_module', ['tools/whatsapp-monitor-module-smoke.php'], 120],
    ['whatsapp_chat_ingest_and_render', ['tools/whatsapp-chat-ingest-smoke.php'], 120],
    ['crm_customer_prefill', ['tools/crm-customer-prefill-smoke.php'], 120],
    ['native_network_monitor', ['tools/native-network-monitor-smoke.php'], 120],
    ['network_olt_ticket_rules', ['tools/network-olt-ticket-rules-smoke.php'], 120],
    ['network_management_path_guard', ['tools/network-management-path-guard-smoke.php'], 120],
    ['noc_security_ui', ['tools/noc-security-ui-smoke.php'], 120],
    ['mgs_bridge_modules', ['tools/mgs-bridge-modules-smoke.php'], 120],
    ['mgs_integration', ['tools/test-mgs-integration.php'], 120],
    ['radius_control_plan', ['tools/radius-control-plan-smoke.php'], 120],
    ['radius_disconnect_worker_dry_run', ['tools/radius-disconnect-worker-smoke.php'], 120],
    ['modem_search_bot', ['tools/modem-search-bot-smoke.php'], 120],
    ['telegram_bots_dry_run', ['tools/test-telegram-bots.php'], 120],
    ['sms_provider_dry_run', ['tools/test-communication-provider.php', '--channel=sms', '--destination=994501234567', '--message=FULL REGRESSION DRY RUN'], 120],
    ['system_maintenance_optimizer', ['tools/system-maintenance-optimizer-smoke.php'], 120],
    ['backup_center', ['tools/backup-center-smoke.php'], 120],
    ['nginx_gateway_validate', ['tools/validate-nginx-gateway.php'], 120],
    ['live_cutover_checklist', ['tools/live-cutover-checklist.php'], 120],
    ['production_readiness', ['tools/production-readiness-check.php'], 120],
    ['system_functionality_audit', ['tools/system-functionality-audit.php', '--markdown'], 120],
];

$results = [];
foreach ($tests as [$name, $args, $timeout]) {
    $results[] = regression_run_command((string) $name, $args, (int) $timeout);
}

$python = trim((string) shell_exec('where python 2>NUL'));
if ($python !== '') {
    $pythonBinary = preg_split('/\R+/', $python)[0] ?? 'python';
    $oldPhp = $php;
    $php = $pythonBinary;
    $results[] = regression_run_command('http_admin_api_csrf', ['tools/admin-api-csrf-smoke.py'], 120);
    $results[] = regression_run_command('http_customer_search_rate_limit', ['tools/customer-search-rate-limit-smoke.py'], 120);
    $php = $oldPhp;
} else {
    $results[] = regression_skip('http_admin_api_csrf', 'Skipped: python executable not found for HTTP security smoke.');
    $results[] = regression_skip('http_customer_search_rate_limit', 'Skipped: python executable not found for HTTP rate-limit smoke.');
}

$results[] = regression_skip('real_router_coa_disconnect', 'Skipped: production router/session impact. Use an explicitly selected test PPPoE/IPoE session before live CoA.');
$results[] = regression_skip('real_olt_ont_restart', 'Skipped: VLAN/live OLT reachability and target ONT confirmation required before restart.');
$results[] = regression_skip('real_sms_send', 'Skipped: no approved destination phone for paid SMS send; dry-run provider config was tested.');
$results[] = regression_skip('telegram_live_polling_apply', 'Skipped: live bot polling can answer old updates; dry-run bot tests were executed only.');

$summary = [
    'pass' => 0,
    'fail' => 0,
    'timeout' => 0,
    'skip' => 0,
];
foreach ($results as $result) {
    $status = (string) ($result['status'] ?? 'fail');
    $summary[$status] = ($summary[$status] ?? 0) + 1;
}

$report = [
    'status' => (($summary['fail'] ?? 0) === 0 && ($summary['timeout'] ?? 0) === 0) ? 'ok' : 'failed',
    'generated_at' => date(DATE_ATOM),
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'summary' => $summary,
    'results' => $results,
];

$tmpDir = $root . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}
$reportPath = $tmpDir . DIRECTORY_SEPARATOR . 'full-system-regression-' . date('Ymd-His') . '.json';
file_put_contents($reportPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

$compact = [
    'status' => $report['status'],
    'summary' => $summary,
    'report_path' => $reportPath,
    'failed' => array_values(array_map(
        static fn (array $result): array => [
            'name' => $result['name'] ?? '',
            'status' => $result['status'] ?? '',
            'exit_code' => $result['exit_code'] ?? null,
            'stderr' => $result['stderr'] ?? '',
            'stdout_excerpt' => mb_substr((string) ($result['stdout'] ?? ''), 0, 700),
        ],
        array_filter($results, static fn (array $result): bool => in_array((string) ($result['status'] ?? ''), ['fail', 'timeout'], true))
    )),
];

echo json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($report['status'] === 'ok' ? 0 : 1);
