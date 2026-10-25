<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
$regressionEnvironment = strtolower(app_config('app_environment', 'development'));
if (in_array($regressionEnvironment, ['development', 'dev', 'local', 'test'], true)
    && app_environment_secret('DB_PASS') === '') {
    $localDbPassword = app_config('db_pass', '');
    if ($localDbPassword !== '') {
        putenv('DB_PASS=' . $localDbPassword);
        $_ENV['DB_PASS'] = $localDbPassword;
    }
    unset($localDbPassword);
}
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
    $decodedOutput = json_decode(trim($stdout), true);
    $reportedSkip = !$timedOut && $exitCode === 0 && (
        preg_match('/^Skipped:/mi', trim($stdout)) === 1
        || (is_array($decodedOutput) && strtolower((string) ($decodedOutput['status'] ?? '')) === 'skipped')
    );
    $status = $reportedSkip ? 'skip' : ((!$timedOut && $exitCode === 0) ? 'pass' : 'fail');

    $result = [
        'name' => $name,
        'status' => $timedOut ? 'timeout' : $status,
        'command' => $display,
        'exit_code' => $timedOut ? null : $exitCode,
        'duration_ms' => $durationMs,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
    if ($reportedSkip) {
        $result['reason'] = trim($stdout);
    }

    return $result;
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

/**
 * Creates an isolated staff account and PHP HTTP server for local security tests.
 * Credentials live only in this process tree and are never written to the report.
 *
 * @return array<string,mixed>
 */
function regression_start_local_http_fixture(): array
{
    global $root, $php;

    $db = app_db();
    if (!$db instanceof PDO) {
        throw new RuntimeException('local_http_fixture_database_unavailable');
    }

    $login = app_environment_secret('SUPEROPTIC_TEST_LOGIN');
    $password = app_environment_secret('SUPEROPTIC_TEST_PASSWORD');
    $accountId = 0;
    $createdAccount = false;

    if ($login === '' || $password === '') {
        $suffix = bin2hex(random_bytes(8));
        $login = 'stage0-http-' . $suffix . '@smoke.local';
        $password = 'Stage0!' . bin2hex(random_bytes(18));
        $insert = $db->prepare(
            'INSERT INTO portal_accounts
                (login, password_hash, role, account_type, employee_role, full_name, status, is_active, payload_json)
             VALUES
                (:login, :password_hash, "employee", "admin", "admin", :full_name, "Aktiv", 1, "{}")'
        );
        $insert->execute([
            'login' => $login,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name' => 'Stage 0 HTTP Security Smoke',
        ]);
        $accountId = (int) $db->lastInsertId();
        $createdAccount = true;
        putenv('SUPEROPTIC_TEST_LOGIN=' . $login);
        putenv('SUPEROPTIC_TEST_PASSWORD=' . $password);
        $_ENV['SUPEROPTIC_TEST_LOGIN'] = $login;
        $_ENV['SUPEROPTIC_TEST_PASSWORD'] = $password;
    }

    $baseUrl = trim((string) getenv('SUPEROPTIC_TEST_BASE_URL'));
    $serverProcess = null;
    $serverStdin = null;
    $createdBaseUrl = false;

    if ($baseUrl === '') {
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        if (!is_resource($probe)) {
            throw new RuntimeException('local_http_fixture_port_reservation_failed');
        }
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $separator = strrpos($address, ':');
        $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
        if ($port < 1) {
            throw new RuntimeException('local_http_fixture_port_detection_failed');
        }

        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $nullDevice, 'a'],
            2 => ['file', $nullDevice, 'a'],
        ];
        $serverEnvironment = getenv();
        if (!is_array($serverEnvironment)) {
            $serverEnvironment = [];
        }
        unset($serverEnvironment['RECAPTCHA_SECRET']);
        $serverProcess = proc_open(
            [$php, '-S', '127.0.0.1:' . $port, '-t', $root],
            $descriptorSpec,
            $pipes,
            $root,
            $serverEnvironment
        );
        if (!is_resource($serverProcess)) {
            throw new RuntimeException('local_http_fixture_server_start_failed');
        }
        $serverStdin = $pipes[0] ?? null;
        if (is_resource($serverStdin)) {
            fclose($serverStdin);
            $serverStdin = null;
        }

        $deadline = microtime(true) + 10;
        $ready = false;
        do {
            $socket = @fsockopen('127.0.0.1', $port, $socketError, $socketErrorMessage, 0.25);
            if (is_resource($socket)) {
                fclose($socket);
                $ready = true;
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        if (!$ready) {
            proc_terminate($serverProcess);
            proc_close($serverProcess);
            throw new RuntimeException('local_http_fixture_server_not_ready');
        }

        $baseUrl = 'http://127.0.0.1:' . $port;
        putenv('SUPEROPTIC_TEST_BASE_URL=' . $baseUrl);
        $_ENV['SUPEROPTIC_TEST_BASE_URL'] = $baseUrl;
        $createdBaseUrl = true;
    }

    return [
        'db' => $db,
        'account_id' => $accountId,
        'created_account' => $createdAccount,
        'server_process' => $serverProcess,
        'created_base_url' => $createdBaseUrl,
        'base_url' => $baseUrl,
    ];
}

/** @param array<string,mixed>|null $fixture */
function regression_cleanup_local_http_fixture(?array &$fixture): array
{
    if (!is_array($fixture)) {
        return ['ok' => true, 'message' => 'not_started'];
    }

    $errors = [];
    $serverProcess = $fixture['server_process'] ?? null;
    if (is_resource($serverProcess)) {
        @proc_terminate($serverProcess);
        usleep(200000);
        @proc_close($serverProcess);
    }

    if (!empty($fixture['created_account']) && (int) ($fixture['account_id'] ?? 0) > 0) {
        try {
            $db = $fixture['db'] ?? null;
            if (!$db instanceof PDO) {
                throw new RuntimeException('cleanup_database_unavailable');
            }
            $delete = $db->prepare('DELETE FROM portal_accounts WHERE id = :id AND login LIKE "stage0-http-%@smoke.local"');
            $delete->execute(['id' => (int) $fixture['account_id']]);
            if ($delete->rowCount() !== 1) {
                $errors[] = 'temporary_account_cleanup_mismatch';
            }
        } catch (Throwable $exception) {
            $errors[] = 'temporary_account_cleanup_failed:' . $exception->getMessage();
        }
        putenv('SUPEROPTIC_TEST_LOGIN');
        putenv('SUPEROPTIC_TEST_PASSWORD');
        unset($_ENV['SUPEROPTIC_TEST_LOGIN'], $_ENV['SUPEROPTIC_TEST_PASSWORD']);
    }

    if (!empty($fixture['created_base_url'])) {
        putenv('SUPEROPTIC_TEST_BASE_URL');
        unset($_ENV['SUPEROPTIC_TEST_BASE_URL']);
    }

    $fixture = null;
    return [
        'ok' => $errors === [],
        'message' => $errors === [] ? 'temporary_http_fixture_removed' : implode(';', $errors),
    ];
}

$regressionArguments = array_slice($_SERVER['argv'] ?? [], 1);
$localFixtureAllowed = in_array($regressionEnvironment, ['development', 'dev', 'local', 'test'], true)
    && !in_array('--no-local-http-fixture', $regressionArguments, true);
$localHttpFixture = null;
$localHttpFixtureError = '';
if ($localFixtureAllowed) {
    try {
        $localHttpFixture = regression_start_local_http_fixture();
    } catch (Throwable $exception) {
        $localHttpFixtureError = $exception->getMessage();
    }
}
register_shutdown_function(static function () use (&$localHttpFixture): void {
    regression_cleanup_local_http_fixture($localHttpFixture);
});

$tests = [
    ['php_lint_core', ['-l', 'config.php'], 30],
    ['php_lint_system_settings', ['-l', 'system-settings.php'], 30],
    ['php_lint_customer_portal', ['-l', 'portal.php'], 30],
    ['php_lint_portal_actions', ['-l', 'portal-actions.php'], 30],
    ['php_lint_crm', ['-l', 'crm.php'], 30],
    ['php_lint_hr_score_ticket_waiting_fairness_smoke', ['-l', 'tools/hr-score-ticket-waiting-fairness-smoke.php'], 30],
    ['php_lint_communication_consent_lib', ['-l', 'communication-consent-lib.php'], 30],
    ['php_lint_communication_opt_outs_migration', ['-l', 'db/migrations/20260807_260_communication_opt_outs.php'], 30],
    ['php_lint_communication_opt_out_smoke', ['-l', 'tools/communication-opt-out-smoke.php'], 30],
    ['php_lint_billing_module_lib', ['-l', 'billing-module-lib.php'], 30],
    ['php_lint_campaigns_page', ['-l', 'campaigns.php'], 30],
    ['php_lint_business_rules_lib', ['-l', 'business-rules-lib.php'], 30],
    ['php_lint_business_rules_migration', ['-l', 'db/migrations/20260806_250_business_rules_engine.php'], 30],
    ['php_lint_business_rules_smoke', ['-l', 'tools/business-rules-smoke.php'], 30],
    ['php_lint_db', ['-l', 'db.php'], 30],
    ['php_lint_payment_workflow', ['-l', 'billing-payment-workflow-lib.php'], 30],
    ['php_lint_payment_outbox_worker', ['-l', 'tools/process-billing-payment-outbox.php'], 30],
    ['php_lint_payment_reconciliation', ['-l', 'tools/reconcile-billing-payment.php'], 30],
    ['php_lint_billing_customers', ['-l', 'billing-customers.php'], 30],
    ['php_lint_billing_payments', ['-l', 'billing-payments.php'], 30],
    ['php_lint_inventory_returns_inspection_view', ['-l', 'views/inventory/returns-inspection.php'], 30],
    ['php_lint_inventory_repair_rma_view', ['-l', 'views/inventory/repair-rma.php'], 30],
    ['php_lint_inventory_repair_ui_smoke', ['-l', 'tools/inventory-repair-ui-smoke.php'], 30],
    ['php_lint_inventory_operations_lib', ['-l', 'inventory-operations-lib.php'], 30],
    ['php_lint_inventory_return_approval_smoke', ['-l', 'tools/inventory-return-approval-smoke.php'], 30],
    ['php_lint_hr_finance_bridge_smoke', ['-l', 'tools/hr-finance-bridge-smoke.php'], 30],
    ['php_lint_cross_module_defect_correction_smoke', ['-l', 'tools/cross-module-defect-correction-smoke.php'], 30],
    ['php_lint_area_code_prefixes', ['-l', 'area-code-prefixes.php'], 30],
    ['php_lint_customer_profile', ['-l', 'billing-customer-profile.php'], 30],
    ['php_lint_operations_center', ['-l', 'operations-center.php'], 30],
    ['php_lint_operations_permission_lib', ['-l', 'operations-permission-lib.php'], 30],
    ['php_lint_operations_action_permission_smoke', ['-l', 'tools/operations-action-permission-smoke.php'], 30],
    ['php_lint_feature_flags_lib', ['-l', 'feature-flags-lib.php'], 30],
    ['php_lint_analytics_center_lib', ['-l', 'analytics-center-lib.php'], 30],
    ['php_lint_ai_module_lib', ['-l', 'ai-module-lib.php'], 30],
    ['php_lint_ai_provider_lib', ['-l', 'ai-provider-lib.php'], 30],
    ['php_lint_change_password', ['-l', 'change-password.php'], 30],
    ['php_lint_initial_super_admin_tool', ['-l', 'tools/create-initial-super-admin.php'], 30],
    ['php_lint_ai_module_partial', ['-l', 'ai-module-partial.php'], 30],
    ['php_lint_ai_section_page', ['-l', 'ai-section-page.php'], 30],
    ['php_lint_ai_module_view', ['-l', 'views/ai/module.php'], 30],
    ['php_lint_statistics_center_lib', ['-l', 'statistics-center-lib.php'], 30],
    ['php_lint_statistics_center_page', ['-l', 'statistics-center.php'], 30],
    ['php_lint_statistics_center_partial', ['-l', 'statistics-center-partial.php'], 30],
    ['php_lint_statistics_center_view', ['-l', 'views/statistics/module.php'], 30],
    ['php_lint_statistics_results_view', ['-l', 'views/statistics/_results.php'], 30],
    ['php_lint_ui_language_lib', ['-l', 'ui-language-lib.php'], 30],
    ['php_lint_finance_bonus_lib', ['-l', 'finance-bonus-lib.php'], 30],
    ['php_lint_finance_bonus_view', ['-l', 'views/finance/employee-bonuses.php'], 30],
    ['php_lint_finance_module_lib', ['-l', 'finance-module-lib.php'], 30],
    ['php_lint_finance_assets_view', ['-l', 'views/finance/assets.php'], 30],
    ['php_lint_finance_operations_lib', ['-l', 'finance-operations-lib.php'], 30],
    ['php_lint_finance_budget_lib', ['-l', 'finance-budget-lib.php'], 30],
    ['php_lint_finance_invoice_lib', ['-l', 'finance-invoice-lib.php'], 30],
    ['php_lint_finance_reconciliation_lib', ['-l', 'finance-reconciliation-lib.php'], 30],
    ['php_lint_finance_ai_bot_lib', ['-l', 'finance-ai-bot-lib.php'], 30],
    ['php_lint_finance_ai_assistant_view', ['-l', 'views/finance/ai-assistant.php'], 30],
    ['php_lint_finance_ai_bot_migration', ['-l', 'db/migrations/20260809_380_finance_ai_bot.php'], 30],
    ['php_lint_finance_ai_bot_smoke', ['-l', 'tools/finance-ai-bot-smoke.php'], 30],
    ['php_lint_finance_cash_reconciliation_migration', ['-l', 'db/migrations/20260809_370_finance_cash_reconciliation.php'], 30],
    ['php_lint_finance_cash_reconciliation_smoke', ['-l', 'tools/finance-cash-reconciliation-smoke.php'], 30],
    ['php_lint_finance_invoices_view', ['-l', 'views/finance/invoices.php'], 30],
    ['php_lint_finance_invoices_issued_view', ['-l', 'views/finance/_invoices-issued.php'], 30],
    ['php_lint_finance_invoice_migration', ['-l', 'db/migrations/20260809_360_finance_invoice_generation.php'], 30],
    ['php_lint_finance_invoice_generation_smoke', ['-l', 'tools/finance-invoice-generation-smoke.php'], 30],
    ['php_lint_finance_statistics_view', ['-l', 'views/finance/statistics.php'], 30],
    ['php_lint_finance_budget_migration', ['-l', 'db/migrations/20260809_350_finance_budget_vs_actual.php'], 30],
    ['php_lint_finance_budget_cashflow_smoke', ['-l', 'tools/finance-budget-cashflow-smoke.php'], 30],
    ['php_lint_finance_reconciliation_view', ['-l', 'views/finance/reconciliation.php'], 30],
    ['php_lint_finance_debt_writeoff_approval_smoke', ['-l', 'tools/finance-debt-writeoff-approval-smoke.php'], 30],
    ['php_lint_finance_expenses_view', ['-l', 'views/finance/expenses.php'], 30],
    ['php_lint_finance_operational_crud_smoke', ['-l', 'tools/finance-operational-crud-smoke.php'], 30],
    ['php_lint_sensitive_reason_smoke', ['-l', 'tools/sensitive-reason-smoke.php'], 30],
    ['php_lint_operational_integration_smoke', ['-l', 'tools/operational-integration-smoke.php'], 30],
    ['php_lint_finance_large_expense_approval_smoke', ['-l', 'tools/finance-large-expense-approval-smoke.php'], 30],
    ['php_lint_finance_inventory_assets_smoke', ['-l', 'tools/finance-inventory-assets-smoke.php'], 30],
    ['php_lint_admin_users', ['-l', 'admin-users.php'], 30],
    ['php_lint_admin_privilege_lib', ['-l', 'admin-privilege-lib.php'], 30],
    ['php_lint_admin_privilege_escalation_smoke', ['-l', 'tools/admin-privilege-escalation-smoke.php'], 30],
    ['php_lint_billing_bulk_deactivation_smoke', ['-l', 'tools/billing-bulk-deactivation-smoke.php'], 30],
    ['php_lint_copilot_widget', ['-l', 'copilot-widget.php'], 30],
    ['php_lint_copilot_agent_api', ['-l', 'api/copilot/agent-chat.php'], 30],
    ['php_lint_noc_devices', ['-l', 'noc-devices.php'], 30],
    ['php_lint_network_definitions', ['-l', 'network-definitions.php'], 30],
    ['php_lint_device_command_maker_checker_smoke', ['-l', 'tools/device-command-maker-checker-smoke.php'], 30],
    ['php_lint_hsgq_cli_discovery_smoke', ['-l', 'tools/hsgq-cli-discovery-smoke.php'], 30],
    ['php_lint_cisco_cli_discovery_smoke', ['-l', 'tools/cisco-cli-discovery-smoke.php'], 30],
    ['php_lint_cli_discovery_vendor_registry_smoke', ['-l', 'tools/cli-discovery-vendor-registry-smoke.php'], 30],
    ['php_lint_noc_security', ['-l', 'noc-security.php'], 30],
    ['php_lint_noc_authorization_lib', ['-l', 'noc-authorization-lib.php'], 30],
    ['php_lint_device_credential_vault_lib', ['-l', 'device-credential-vault-lib.php'], 30],
    ['php_lint_device_credential_vault_migration', ['-l', 'db/migrations/20260806_240_device_credential_vault.php'], 30],
    ['php_lint_device_credential_vault_smoke', ['-l', 'tools/device-credential-vault-smoke.php'], 30],
    ['php_lint_device_credential_vault_backfill', ['-l', 'tools/backfill-device-credential-vault.php'], 30],
    ['php_lint_ticket_resolution_lib', ['-l', 'ticket-resolution-lib.php'], 30],
    ['php_lint_technician_close', ['-l', 'technician-close.php'], 30],
    ['php_lint_ticket_resolution_smoke', ['-l', 'tools/ticket-resolution-workflow-smoke.php'], 30],
    ['php_lint_copilot_agent', ['-l', 'copilot-agent.php'], 30],
    ['php_lint_role_agent_lib', ['-l', 'role-agent-lib.php'], 30],
    ['php_lint_advisor_agent_lib', ['-l', 'advisor-agent-lib.php'], 30],
    ['php_lint_advisor_agent_foundation_smoke', ['-l', 'tools/advisor-agent-foundation-smoke.php'], 30],
    ['php_lint_agent_ops_queue_lib', ['-l', 'agent-ops-queue-lib.php'], 30],
    ['php_lint_agent_ops_work_queue_api', ['-l', 'api/agent-ops/work-queue.php'], 30],
    ['php_lint_agent_ops_work_queue_route', ['-l', 'ai-work-queue.php'], 30],
    ['php_lint_agent_ops_work_queue_view', ['-l', 'views/ai/work-queue.php'], 30],
    ['php_lint_agent_ops_work_queue_smoke', ['-l', 'tools/agent-ops-work-queue-smoke.php'], 30],
    ['php_lint_ai_team_lib', ['-l', 'ai-team-lib.php'], 30],
    ['php_lint_ai_team_route', ['-l', 'ai-team.php'], 30],
    ['php_lint_ai_team_respond_route', ['-l', 'ai-team-task-respond.php'], 30],
    ['php_lint_ai_team_view', ['-l', 'views/ai/team.php'], 30],
    ['php_lint_ai_module_view', ['-l', 'views/ai/module.php'], 30],
    ['php_lint_ai_team_migration', ['-l', 'db/migrations/20260809_390_ai_team.php'], 30],
    ['php_lint_ai_team_smoke', ['-l', 'tools/ai-team-smoke.php'], 30],
    ['php_lint_ai_team_daily_agenda_worker', ['-l', 'tools/ai-team-daily-agenda.php'], 30],
    ['php_lint_ai_team_daily_agenda_smoke', ['-l', 'tools/ai-team-daily-agenda-smoke.php'], 30],
    ['php_lint_ai_team_daily_agenda_migration', ['-l', 'db/migrations/20260810_400_ai_team_daily_agenda.php'], 30],
    ['php_lint_ai_team_proposal_persona_unique_migration', ['-l', 'db/migrations/20260810_410_ai_team_task_proposal_persona_unique.php'], 30],
    ['php_lint_ai_team_agenda_runs_migration', ['-l', 'db/migrations/20260811_420_ai_team_agenda_runs.php'], 30],
    ['php_lint_security_anomaly_lib', ['-l', 'security-anomaly-lib.php'], 30],
    ['php_lint_audit_log_lib', ['-l', 'audit-log-lib.php'], 30],
    ['php_lint_communication_provider', ['-l', 'communication-provider.php'], 30],
    ['php_lint_communication_campaign_lib', ['-l', 'communication-campaign-lib.php'], 30],
    ['php_lint_router_registry_lib', ['-l', 'router-registry-lib.php'], 30],
    ['php_lint_mgs_integration_page', ['-l', 'mgs-integration.php'], 30],
    ['php_lint_pg365_status_worker', ['-l', 'tools/pg365-status-worker.php'], 30],
    ['php_lint_communication_queue_worker', ['-l', 'tools/process-communication-queue.php'], 30],
    ['php_lint_secret_source_inventory', ['-l', 'tools/secret-source-inventory.php'], 30],
    ['php_lint_scrub_legacy_secrets', ['-l', 'tools/scrub-legacy-secrets.php'], 30],
    ['php_lint_secret_cutover_smoke', ['-l', 'tools/secret-cutover-smoke.php'], 30],
    ['php_lint_service_secret_visibility', ['-l', 'tools/service-secret-visibility.php'], 30],
    ['php_lint_task_secret_visibility', ['-l', 'tools/task-secret-visibility.php'], 30],
    ['php_lint_message_center', ['-l', 'message-center.php'], 30],
    ['php_lint_message_template_editing_smoke', ['-l', 'tools/message-template-editing-smoke.php'], 30],
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
    ['php_lint_mgs_integration_lib', ['-l', 'mgs-integration-lib.php'], 30],
    ['php_lint_mgs_alarm_state_events_migration', ['-l', 'db/migrations/20260819_430_mgs_alarm_state_events.php'], 30],
    ['php_lint_mgs_alarm_state_history_smoke', ['-l', 'tools/mgs-alarm-state-history-smoke.php'], 30],
    ['php_lint_device_interfaces_migration', ['-l', 'db/migrations/20260819_440_device_interfaces.php'], 30],
    ['php_lint_fake_snmp_agent_for_smoke', ['-l', 'tools/_fake-snmp-agent-for-smoke.php'], 30],
    ['php_lint_device_interface_inventory_smoke', ['-l', 'tools/mgs-device-interface-inventory-smoke.php'], 30],
    ['php_lint_device_interface_traffic_migration', ['-l', 'db/migrations/20260819_450_device_interface_traffic.php'], 30],
    ['php_lint_poll_device_interface_traffic', ['-l', 'tools/poll-device-interface-traffic.php'], 30],
    ['php_lint_device_interface_traffic_smoke', ['-l', 'tools/mgs-device-interface-traffic-smoke.php'], 30],
    ['php_lint_snmp_trap_log_migration', ['-l', 'db/migrations/20260819_460_snmp_trap_log.php'], 30],
    ['php_lint_snmp_trap_listener', ['-l', 'tools/snmp-trap-listener.php'], 30],
    ['php_lint_snmp_trap_listener_smoke', ['-l', 'tools/mgs-snmp-trap-listener-smoke.php'], 30],
    ['php_lint_device_neighbors_migration', ['-l', 'db/migrations/20260819_470_device_neighbors.php'], 30],
    ['php_lint_device_neighbors_smoke', ['-l', 'tools/mgs-device-neighbors-smoke.php'], 30],
    ['php_lint_legacy_mgs_ont_inventory_migration', ['-l', 'db/migrations/20260820_480_legacy_mgs_ont_inventory.php'], 30],
    ['php_lint_import_mgs_node_data', ['-l', 'tools/import-mgs-node-data.php'], 30],
    ['php_lint_hr_score_leave_fairness_smoke', ['-l', 'tools/hr-score-leave-fairness-smoke.php'], 30],
    ['php_lint_hr_module_lib', ['-l', 'hr-module-lib.php'], 30],
    ['php_lint_hr_performance_view', ['-l', 'views/hr/performance.php'], 30],
    ['php_lint_hr_score_fairness_appeals_smoke', ['-l', 'tools/hr-score-fairness-appeals-smoke.php'], 30],
    ['php_lint_operation_logs', ['-l', 'operation-logs.php'], 30],
    ['php_lint_operation_logs_export', ['-l', 'operation-logs-export.php'], 30],
    ['php_lint_system_maintenance_optimizer', ['-l', 'tools/system-maintenance-optimizer.php'], 30],
    ['php_lint_network_olt_ticket_worker', ['-l', 'tools/process-network-olt-ticket-rules.php'], 30],
    ['php_lint_website_lead_service', ['-l', 'website-lead-service.php'], 30],
    ['php_lint_website_lead_api', ['-l', 'api/public/website-lead.php'], 30],
    ['php_lint_website_lead_crm_ticket_smoke', ['-l', 'tools/website-lead-crm-ticket-smoke.php'], 30],
    ['php_lint_public_site_separation_smoke', ['-l', 'tools/public-site-separation-smoke.php'], 30],
    ['php_lint_operational_telegram_ticket_contract_smoke', ['-l', 'tools/operational-telegram-ticket-contract-smoke.php'], 30],
    ['php_lint_radius_session_action_worker', ['-l', 'tools/process-radius-session-actions.php'], 30],
    ['php_lint_ont_telemetry_sync_worker', ['-l', 'tools/sync-ont-live-telemetry.php'], 30],
    ['php_lint_whatsapp_heartbeat_watch', ['-l', 'tools/watch-whatsapp-heartbeat.php'], 30],
    ['php_lint_whatsapp_heartbeat_watch_smoke', ['-l', 'tools/whatsapp-heartbeat-watch-smoke.php'], 30],
    ['php_lint_ont_telemetry_sync_smoke', ['-l', 'tools/ont-live-telemetry-sync-smoke.php'], 30],
    ['php_lint_finance_daily_report_worker', ['-l', 'tools/process-finance-daily-report.php'], 30],
    ['php_lint_finance_daily_report_smoke', ['-l', 'tools/finance-daily-report-smoke.php'], 30],
    ['php_lint_statistics_ceo_kpis_smoke', ['-l', 'tools/statistics-ceo-kpis-smoke.php'], 30],
    ['portal_login_security', ['tools/portal-login-security-smoke.php'], 120],
    ['portal_token_security', ['tools/portal-token-security-smoke.php'], 120],
    ['db_secret_environment_only', ['tools/db-secret-source-smoke.php'], 120],
    ['secret_source_inventory', ['tools/secret-source-inventory.php'], 120],
    ['secret_scrub_dry_run', ['tools/scrub-legacy-secrets.php'], 120],
    ['secret_cutover_apply_contract', ['tools/secret-cutover-smoke.php'], 120],
    ['service_secret_visibility_plan', ['tools/service-secret-visibility.php', '--plan'], 120],
    ['task_secret_visibility_plan', ['tools/task-secret-visibility.php', '--plan'], 120],
    ['portal_login_password', ['tools/portal-login-password-smoke.php'], 120],
    ['portal_login_public_safety', ['tools/portal-login-public-safety-smoke.php'], 120],
    ['public_security_audit', ['tools/public-security-audit-smoke.php'], 120],
    ['security_injection_and_rate_limit', ['tools/security-injection-smoke.php'], 120],
    ['deepseek_provider_security', ['tools/deepseek-provider-smoke.php'], 120],
    ['deepseek_agent_launcher_security', ['tools/deepseek-agent-launcher-smoke.php'], 120],
    ['initial_admin_security', ['tools/initial-admin-security-smoke.php'], 120],
    ['ubuntu_installer_production_contract', ['tools/ubuntu-installer-production-contract-smoke.php'], 120],
    ['ubuntu_production_preflight_contract', ['tools/ubuntu-production-preflight-contract-smoke.php'], 120],
    ['ubuntu_rollback_contract', ['tools/ubuntu-rollback-contract-smoke.php'], 120],
    ['ubuntu_release_bundle_contract', ['tools/ubuntu-release-bundle-smoke.php'], 120],
    ['communication_campaign_approval', ['tools/communication-campaign-approval-smoke.php'], 120],
    ['communication_opt_out_enforcement', ['tools/communication-opt-out-smoke.php'], 120],
    ['advisor_agent_foundation', ['tools/advisor-agent-foundation-smoke.php'], 120],
    ['agent_ops_work_queue', ['tools/agent-ops-work-queue-smoke.php'], 120],
    ['ai_team', ['tools/ai-team-smoke.php'], 120],
    ['ai_team_daily_agenda', ['tools/ai-team-daily-agenda-smoke.php'], 120],
    ['communication_channel_switches', ['tools/communication-channel-switch-smoke.php'], 120],
    ['pg365_transport', ['tools/pg365-transport-smoke.php'], 120],
    ['communication_delivery_state', ['tools/communication-delivery-state-smoke.php'], 120],
    ['pg365_queue_claim', ['tools/pg365-queue-claim-smoke.php'], 120],
    ['notification_transition_dedup', ['tools/notification-transition-dedup-smoke.php'], 120],
    ['system_settings_allowlist', ['tools/system-settings-smoke.php'], 120],
    ['router_registry_binding', ['tools/router-registry-smoke.php'], 120],
    ['mobile_customer_modal_layout', ['tools/mobile-layout-smoke.php'], 120],
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
    ['billing_payment_outbox_recovery', ['tools/billing-payment-outbox-smoke.php'], 120],
    ['billing_customer_list_order', ['tools/billing-customer-list-order-smoke.php'], 120],
    ['billing_statistics_selector_ajax', ['tools/billing-statistics-selector-smoke.php'], 120],
    ['stage1_permission_matrix', ['tools/stage1-permission-matrix-smoke.php'], 120],
    ['stage1_service_data_contracts', ['tools/stage1-service-data-contract-smoke.php'], 180],
    ['stage1_statistics_date_contract', ['tools/stage1-statistics-date-contract-smoke.php'], 120],
    ['performance_indexes', ['tools/performance-index-smoke.php'], 120],
    ['concurrent_operator_render', ['tools/concurrency-smoke.php'], 120],
    ['customer_activity_revenue_intelligence', ['tools/customer-intelligence-smoke.php'], 120],
    ['area_customer_code_generation', ['tools/area-customer-code-smoke.php'], 120],
    ['admin_inventory_copilot_ui', ['tools/admin-inventory-copilot-ui-smoke.php'], 120],
    ['feature_flags_local_fallback', ['tools/feature-flags-smoke.php'], 120],
    ['analytics_center_context', ['tools/analytics-center-smoke.php'], 120],
    ['ai_module_role_ajax', ['tools/ai-module-separation-smoke.php'], 120],
    ['statistics_center_role_ajax', ['tools/statistics-center-smoke.php'], 120],
    ['automatic_filter_contract', ['tools/automatic-filter-contract-smoke.php'], 120],
    ['mojibake_repair', ['tools/mojibake-repair-smoke.php'], 120],
    ['ui_language_shell_standard', ['tools/ui-language-shell-smoke.php'], 120],
    ['four_module_ui_consistency', ['tools/four-module-ui-consistency-smoke.php'], 180],
    ['portal_workflow_guide', ['tools/portal-workflow-guide-smoke.php'], 120],
    ['finance_bonus_module', ['tools/finance-bonus-module-smoke.php'], 120],
    ['finance_inventory_assets', ['tools/finance-inventory-assets-smoke.php'], 120],
    ['finance_operational_crud', ['tools/finance-operational-crud-smoke.php'], 120],
    ['sensitive_reason_enforcement', ['tools/sensitive-reason-smoke.php'], 120],
    ['operational_integration', ['tools/operational-integration-smoke.php'], 120],
    ['finance_large_expense_approval', ['tools/finance-large-expense-approval-smoke.php'], 120],
    ['admin_privilege_escalation', ['tools/admin-privilege-escalation-smoke.php'], 120],
    ['finance_debt_writeoff_approval', ['tools/finance-debt-writeoff-approval-smoke.php'], 120],
    ['billing_bulk_deactivation', ['tools/billing-bulk-deactivation-smoke.php'], 120],
    ['device_command_maker_checker', ['tools/device-command-maker-checker-smoke.php'], 120],
    ['message_template_editing', ['tools/message-template-editing-smoke.php'], 120],
    ['hr_score_ticket_waiting_fairness', ['tools/hr-score-ticket-waiting-fairness-smoke.php'], 120],
    ['statistics_ceo_kpis', ['tools/statistics-ceo-kpis-smoke.php'], 120],
    ['finance_budget_cashflow', ['tools/finance-budget-cashflow-smoke.php'], 120],
    ['operations_action_permission', ['tools/operations-action-permission-smoke.php'], 120],
    ['finance_invoice_generation', ['tools/finance-invoice-generation-smoke.php'], 120],
    ['finance_cash_reconciliation', ['tools/finance-cash-reconciliation-smoke.php'], 120],
    ['finance_ai_bot', ['tools/finance-ai-bot-smoke.php'], 120],
    ['hsgq_cli_discovery', ['tools/hsgq-cli-discovery-smoke.php'], 120],
    ['cisco_cli_discovery', ['tools/cisco-cli-discovery-smoke.php'], 120],
    ['cli_discovery_vendor_registry', ['tools/cli-discovery-vendor-registry-smoke.php'], 120],
    ['finance_bonus_filter_and_rules_ui', ['tools/finance-bonus-filter-smoke.php'], 120],
    ['finance_render_readonly', ['tools/finance-render-readonly-smoke.php'], 120],
    ['finance_debt_einvoice_roundtrip', ['tools/finance-debt-einvoice-smoke.php'], 180],
    ['inventory_lifecycle_acceptance', ['tools/inventory-lifecycle-smoke.php'], 120],
    ['inventory_return_approval', ['tools/inventory-return-approval-smoke.php'], 120],
    ['inventory_repair_ui_contract', ['tools/inventory-repair-ui-smoke.php'], 120],
    ['inventory_document_print', ['tools/inventory-document-print-smoke.php'], 120],
    ['inventory_equipment_kpi_flow', ['tools/inventory-equipment-kpi-flow-smoke.php'], 120],
    ['customer_profile_equipment_lifecycle', ['tools/customer-profile-equipment-lifecycle-smoke.php'], 120],
    ['hr_finance_bridge_roundtrip', ['tools/hr-finance-bridge-smoke.php'], 120],
    ['hr_score_leave_fairness', ['tools/hr-score-leave-fairness-smoke.php'], 120],
    ['hr_score_fairness_appeals', ['tools/hr-score-fairness-appeals-smoke.php'], 120],
    ['cross_module_defect_correction_ui_contract', ['tools/cross-module-defect-correction-smoke.php'], 120],
    ['customer_profile_radius_refresh', ['tools/customer-profile-radius-refresh-smoke.php'], 120],
    ['provider_api_lookup_payment_duplicate', ['tools/provider-api-smoke.php'], 120],
    ['copilot_customer_analysis', ['tools/copilot-customer-analysis-smoke.php'], 120],
    ['copilot_agent_actions', ['tools/copilot-agent-smoke.php'], 120],
    ['security_anomaly_engine', ['tools/security-anomaly-smoke.php'], 120],
    ['telegram_command_actions', ['tools/telegram-command-actions-smoke.php'], 120],
    ['telegram_bot_routing', ['tools/telegram-bot-routing-smoke.php'], 120],
    ['message_center_channel_chart', ['tools/message-center-channel-chart-smoke.php'], 120],
    ['message_center_ui_contract', ['tools/message-center-ui-contract-smoke.php'], 120],
    ['production_fallback_guard', ['tools/production-fallback-guard-smoke.php'], 120],
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
    ['ont_live_telemetry_sync', ['tools/ont-live-telemetry-sync-smoke.php'], 120],
    ['whatsapp_heartbeat_watch', ['tools/whatsapp-heartbeat-watch-smoke.php'], 120],
    ['finance_daily_report', ['tools/finance-daily-report-smoke.php'], 120],
    ['ticket_resolution_workflow', ['tools/ticket-resolution-workflow-smoke.php'], 120],
    ['website_lead_crm_ticket', ['tools/website-lead-crm-ticket-smoke.php'], 120],
    ['public_site_separation', ['tools/public-site-separation-smoke.php'], 120],
    ['operational_telegram_ticket_contract', ['tools/operational-telegram-ticket-contract-smoke.php'], 120],
    ['network_management_path_guard', ['tools/network-management-path-guard-smoke.php'], 120],
    ['noc_security_ui', ['tools/noc-security-ui-smoke.php'], 120],
    ['noc_service_authorization', ['tools/noc-service-authorization-smoke.php'], 120],
    ['device_credential_vault', ['tools/device-credential-vault-smoke.php'], 120],
    ['business_rules_engine', ['tools/business-rules-smoke.php'], 120],
    ['mgs_bridge_modules', ['tools/mgs-bridge-modules-smoke.php'], 120],
    ['mgs_integration', ['tools/test-mgs-integration.php'], 120],
    ['mgs_alarm_state_history', ['tools/mgs-alarm-state-history-smoke.php'], 60],
    ['mgs_device_interface_inventory', ['tools/mgs-device-interface-inventory-smoke.php'], 60],
    ['mgs_device_interface_traffic', ['tools/mgs-device-interface-traffic-smoke.php'], 60],
    ['mgs_snmp_trap_listener', ['tools/mgs-snmp-trap-listener-smoke.php'], 60],
    ['mgs_device_neighbors', ['tools/mgs-device-neighbors-smoke.php'], 60],
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
if ($localFixtureAllowed) {
    $results[] = [
        'name' => 'local_http_security_fixture',
        'status' => $localHttpFixtureError === '' ? 'pass' : 'fail',
        'exit_code' => $localHttpFixtureError === '' ? 0 : 1,
        'duration_ms' => 0,
        'stdout' => $localHttpFixtureError === '' ? 'isolated_http_fixture_ready' : '',
        'stderr' => $localHttpFixtureError,
    ];
}
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
    $results[] = regression_run_command('huawei_readonly_adapter', ['tools/huawei-readonly-adapter-smoke.py'], 120);
    $php = $oldPhp;
} else {
    $results[] = regression_skip('http_admin_api_csrf', 'Skipped: python executable not found for HTTP security smoke.');
    $results[] = regression_skip('http_customer_search_rate_limit', 'Skipped: python executable not found for HTTP rate-limit smoke.');
    $results[] = regression_skip('huawei_readonly_adapter', 'Skipped: python executable not found for Huawei adapter smoke.');
}

$results[] = regression_skip('real_router_coa_disconnect', 'Skipped: production router/session impact. Use an explicitly selected test PPPoE/IPoE session before live CoA.');
$results[] = regression_skip('real_olt_ont_restart', 'Skipped: VLAN/live OLT reachability and target ONT confirmation required before restart.');
$results[] = regression_skip('real_sms_send', 'Skipped: paid external delivery is excluded from deterministic regression; run separately with an approved destination and provider credentials.');
$results[] = regression_skip('telegram_live_polling_apply', 'Skipped: live polling consumes provider updates and is a separate cutover check; dry-run routing tests were executed.');

$fixtureCleanup = regression_cleanup_local_http_fixture($localHttpFixture);
if ($localFixtureAllowed) {
    $results[] = [
        'name' => 'local_http_security_fixture_cleanup',
        'status' => !empty($fixtureCleanup['ok']) ? 'pass' : 'fail',
        'exit_code' => !empty($fixtureCleanup['ok']) ? 0 : 1,
        'duration_ms' => 0,
        'stdout' => !empty($fixtureCleanup['ok']) ? (string) $fixtureCleanup['message'] : '',
        'stderr' => !empty($fixtureCleanup['ok']) ? '' : (string) $fixtureCleanup['message'],
    ];
}

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
