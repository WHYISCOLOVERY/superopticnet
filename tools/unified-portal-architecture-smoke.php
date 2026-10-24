<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$installer = (string) file_get_contents($root . '/deploy/ubuntu-install-superoptic-mgs.sh');
$config = (string) file_get_contents($root . '/config.php');
$integration = (string) file_get_contents($root . '/mgs-integration-lib.php');
$preflight = (string) file_get_contents($root . '/deploy/ubuntu-production-preflight.sh');
$health = (string) file_get_contents($root . '/api/health.php');
$billingMaintenance = (string) file_get_contents($root . '/tools/billing-maintenance.php');
$securityAnomaly = (string) file_get_contents($root . '/security-anomaly-lib.php');
$securityMigration = (string) file_get_contents($root . '/db/migrations/20260728_080_security_anomaly_schema.php');
$modemWorker = (string) file_get_contents($root . '/tools/process-telegram-modem-search-bot.php');
$optimizer = (string) file_get_contents($root . '/tools/system-maintenance-optimizer.php');
$indexMigration = (string) file_get_contents($root . '/db/migrations/20260728_090_runtime_performance_indexes.php');
$communicationProvider = (string) file_get_contents($root . '/communication-provider.php');
$whatsappGuardMigration = (string) file_get_contents($root . '/db/migrations/20260728_100_whatsapp_delivery_guard_schema.php');

$checks = [
    'legacy_node_opt_in' => str_contains($installer, 'SKIP_MGS_NPM_INSTALL="${SKIP_MGS_NPM_INSTALL:-1}"'),
    'canonical_schema_bootstrap' => str_contains($installer, 'app_mgs_ensure_schema')
        && str_contains($installer, 'canonical PHP/MySQL schema'),
    'runtime_schema_is_read_only' => str_contains(
        $integration,
        "app_setting('db_bootstrap_mode', 'cached')) === 'off'"
    ) && str_contains($integration, 'return app_mgs_schema_ready($db);'),
    'native_noc_scan' => str_contains($integration, 'function app_mgs_scan_management_network')
        && str_contains($integration, 'invalid_or_too_large_private_scan_target'),
    'native_device_create' => str_contains($integration, 'function app_mgs_create_monitor_device')
        && str_contains($integration, 'INSERT INTO device_learning_profiles'),
    'native_attendance_profile' => str_contains($integration, 'function app_mgs_save_hr_attendance_profile')
        && str_contains($integration, 'INSERT INTO hr_attendance_profiles'),
    'native_technical_task' => str_contains($integration, 'function app_mgs_create_technical_task')
        && str_contains($integration, 'app_db_insert_ticket'),
    'mock_write_success_removed' => !str_contains($config, 'Operation recorded by native MySQL fallback')
        && !str_contains($config, 'Attendance profile saved by native MySQL fallback'),
    'preflight_accepts_canonical_portal' => str_contains(
        $preflight,
        'Legacy MGS Node service is intentionally not installed'
    ),
    'health_uses_portal_identity' => str_contains($health, "'service' => 'superoptic-portal'"),
    'tls_follows_install_mode' => str_contains($installer, '"portal_force_https" "1"')
        && str_contains($installer, '"portal_force_https" "0"'),
    'billing_notice_query_is_mysql8_safe' => !str_contains($billingMaintenance, 'SELECT DISTINCT a.*')
        && str_contains($billingMaintenance, 'MIN(expires_at) AS next_expires_at'),
    'security_schema_is_migration_only' => !str_contains($securityAnomaly, 'CREATE TABLE IF NOT EXISTS security_anomaly_events')
        && str_contains($securityMigration, 'CREATE TABLE IF NOT EXISTS security_anomaly_events'),
    'modem_worker_skips_missing_provider' => str_contains($integration, 'function app_mgs_telegram_token_is_valid')
        && str_contains($modemWorker, "'status' => 'skipped'")
        && str_contains($modemWorker, "'bot_not_configured', 'channel_disabled'"),
    'optimizer_runtime_has_no_index_ddl' => !str_contains($optimizer, 'CREATE INDEX')
        && str_contains($optimizer, "'status' => 'migration_required'")
        && str_contains($indexMigration, 'CREATE INDEX'),
    'whatsapp_guard_schema_is_migration_only' => !str_contains(
        $communicationProvider,
        'CREATE TABLE IF NOT EXISTS whatsapp_delivery_guard'
    ) && str_contains($communicationProvider, 'whatsapp_guard_schema_not_migrated')
        && str_contains($whatsappGuardMigration, 'CREATE TABLE IF NOT EXISTS whatsapp_delivery_guard'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
