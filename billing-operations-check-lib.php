<?php
declare(strict_types=1);

function billing_ops_check_add(array &$checks, string $group, string $name, string $level, bool $ok, string $message, array $meta = []): void
{
    $checks[] = [
        'group' => $group,
        'name' => $name,
        'level' => in_array($level, ['critical', 'warning', 'info'], true) ? $level : 'info',
        'ok' => $ok,
        'message' => $message,
        'meta' => $meta,
    ];
}

function billing_ops_table_count(PDO $db, string $table, string $where = ''): ?int
{
    try {
        $sql = 'SELECT COUNT(*) FROM ' . $table . ($where !== '' ? ' WHERE ' . $where : '');
        return (int) $db->query($sql)->fetchColumn();
    } catch (Throwable) {
        return null;
    }
}

function billing_ops_first_row(PDO $db, string $sql): array
{
    try {
        $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (Throwable) {
        return [];
    }
}

function billing_ops_scalar(PDO $db, string $sql): mixed
{
    try {
        return $db->query($sql)->fetchColumn();
    } catch (Throwable) {
        return null;
    }
}

function billing_ops_check_page(string $path): bool
{
    return is_file(__DIR__ . DIRECTORY_SEPARATOR . $path) && filesize(__DIR__ . DIRECTORY_SEPARATOR . $path) > 100;
}

function billing_ops_index_exists(PDO $db, string $table, string $index): bool
{
    try {
        $statement = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND INDEX_NAME = :index_name'
        );
        $statement->execute([
            'table_name' => $table,
            'index_name' => $index,
        ]);

        return (int) $statement->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function billing_ops_run_checks(?PDO $db): array
{
    $checks = [];
    $summary = [
        'customers' => 0,
        'active_customers' => 0,
        'tariffs' => 0,
        'radius_services' => 0,
        'payments' => 0,
        'open_tickets' => 0,
        'queued_messages' => 0,
        'last_maintenance_at' => '',
    ];

    if (!$db instanceof PDO) {
        billing_ops_check_add($checks, 'core', 'database_connection', 'critical', false, 'MySQL/PDO connection is not ready.');
        return [
            'status' => 'failed',
            'summary' => $summary,
            'checks' => $checks,
            'generated_at' => date(DATE_ATOM),
        ];
    }

    billing_ops_check_add($checks, 'core', 'database_connection', 'critical', true, 'MySQL connection OK.');

    $requiredTables = [
        'portal_accounts',
        'billing_tariffs',
        'billing_ip_pools',
        'billing_radius_services',
        'billing_payments',
        'radcheck',
        'radreply',
        'radusergroup',
        'radacct',
        'radpostauth',
        'communication_templates',
        'communication_messages',
        'billing_maintenance_runs',
        'portal_tickets',
    ];
    foreach ($requiredTables as $table) {
        $count = billing_ops_table_count($db, $table);
        billing_ops_check_add(
            $checks,
            'schema',
            'table:' . $table,
            'critical',
            $count !== null,
            $count !== null ? $count . ' rows' : 'Required table unavailable.'
        );
    }

    $summary['customers'] = billing_ops_table_count($db, 'portal_accounts', "role = 'customer'") ?? 0;
    $summary['active_customers'] = billing_ops_table_count($db, 'portal_accounts', "role = 'customer' AND is_active = 1") ?? 0;
    $summary['tariffs'] = billing_ops_table_count($db, 'billing_tariffs', "status = 'active'") ?? 0;
    $summary['radius_services'] = billing_ops_table_count($db, 'billing_radius_services') ?? 0;
    $summary['payments'] = billing_ops_table_count($db, 'billing_payments') ?? 0;
    $summary['open_tickets'] = billing_ops_table_count($db, 'portal_tickets', "status <> 'Baglandi'") ?? 0;
    $summary['queued_messages'] = billing_ops_table_count($db, 'communication_messages', "status = 'queued'") ?? 0;

    billing_ops_check_add($checks, 'customers', 'customer_base', 'critical', $summary['customers'] > 0, $summary['customers'] . ' customer rows.');
    billing_ops_check_add($checks, 'customers', 'active_customer_base', 'warning', $summary['active_customers'] > 0, $summary['active_customers'] . ' active customers.');

    $activeTariff = billing_ops_first_row(
        $db,
        "SELECT id, name, service_type, monthly_fee
         FROM billing_tariffs
         WHERE status = 'active'
           AND service_type IN ('pppoe', 'ipoe')
           AND monthly_fee > 0
         ORDER BY service_type, monthly_fee, id
         LIMIT 1"
    );
    billing_ops_check_add(
        $checks,
        'billing',
        'active_tariff_with_price',
        'critical',
        $activeTariff !== [],
        $activeTariff !== []
            ? 'Tariff ready: ' . (string) ($activeTariff['name'] ?? '-') . ' / ' . (string) ($activeTariff['monthly_fee'] ?? 0) . ' AZN'
            : 'No active tariff with monthly_fee > 0.'
    );

    $activePools = $db->query(
        "SELECT id, name, service_type, pool_type, range_start, range_end
         FROM billing_ip_pools
         WHERE status = 'active'
         ORDER BY service_type, pool_type, id"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $activePool = is_array($activePools[0] ?? null) ? $activePools[0] : [];
    billing_ops_check_add(
        $checks,
        'ip',
        'active_radius_pool',
        'critical',
        $activePool !== [],
        $activePool !== [] ? count($activePools) . ' active pool rows.' : 'No active billing_ip_pools row.'
    );

    $availableIp = null;
    $availablePool = [];
    if ($activePools !== [] && function_exists('app_billing_next_available_ip')) {
        foreach ($activePools as $poolRow) {
            if (!is_array($poolRow)) {
                continue;
            }
            $candidateIp = app_billing_next_available_ip((int) ($poolRow['id'] ?? 0));
            if ($candidateIp !== null) {
                $availableIp = $candidateIp;
                $availablePool = $poolRow;
                break;
            }
        }
    }
    billing_ops_check_add(
        $checks,
        'ip',
        'pool_has_available_ip_or_pool_mode',
        'warning',
        $activePool !== [] && ($availableIp !== null || array_filter($activePools, static fn(array $pool): bool => (string) ($pool['pool_type'] ?? '') === 'dynamic') !== []),
        $availableIp !== null
            ? 'Next available IP: ' . $availableIp . ' from ' . (string) ($availablePool['name'] ?? '-')
            : 'No concrete free IP found; dynamic Framed-Pool mode can still work if router/RADIUS pool is used.'
    );

    $sampleAccount = billing_ops_first_row(
        $db,
        "SELECT *
         FROM portal_accounts
         WHERE role = 'customer'
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $samplePayload = $sampleAccount !== [] ? app_db_account_payload($sampleAccount) : [];
    $sampleBilling = is_array($samplePayload['billing'] ?? null) ? $samplePayload['billing'] : [];
    $sampleTariff = $sampleAccount !== [] ? app_billing_account_tariff($db, $sampleAccount, $sampleBilling) : null;
    $sampleAmount = $sampleAccount !== [] ? app_billing_account_default_amount($sampleAccount, $sampleBilling, is_array($sampleTariff) ? $sampleTariff : null) : 0.0;
    billing_ops_check_add(
        $checks,
        'payments',
        'payment_amount_resolution',
        'critical',
        $sampleAccount !== [] && $sampleAmount > 0,
        $sampleAccount !== []
            ? 'Sample ' . (string) ($sampleAccount['subscriber_code'] ?? '-') . ' monthly amount: ' . number_format($sampleAmount, 2, '.', '') . ' AZN'
            : 'No customer available for payment amount test.'
    );

    $radcheckCount = (int) (billing_ops_scalar($db, 'SELECT COUNT(*) FROM radcheck WHERE username <> ""') ?? 0);
    $radreplyCount = (int) (billing_ops_scalar($db, 'SELECT COUNT(*) FROM radreply WHERE username <> ""') ?? 0);
    $activeRadiusServices = billing_ops_table_count($db, 'billing_radius_services', "status = 'active'") ?? 0;
    billing_ops_check_add($checks, 'radius', 'radcheck_rows', 'critical', $radcheckCount > 0, $radcheckCount . ' radcheck rows.');
    billing_ops_check_add($checks, 'radius', 'radreply_rows', 'critical', $radreplyCount > 0, $radreplyCount . ' radreply rows.');
    billing_ops_check_add($checks, 'radius', 'active_radius_services', 'warning', $activeRadiusServices > 0, $activeRadiusServices . ' active billing radius services.');

    $onlineSessions = (int) (billing_ops_scalar($db, "SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)") ?? 0);
    billing_ops_check_add($checks, 'radius', 'accounting_visibility', 'info', $onlineSessions >= 0, $onlineSessions . ' current online sessions in radacct.');

    $templateChecks = [
        'payment_received' => ['payment_received'],
        'service_expiring_1d' => ['service_expiring_1d'],
        'service_expired' => ['service_expired'],
        'modem_warning' => ['modem_overheat', 'modem_flap', 'modem_heat_warning'],
        'retention_60d' => ['retention_60d'],
    ];
    foreach ($templateChecks as $templateName => $codes) {
        $quotedCodes = implode(',', array_map(static fn (string $code): string => $db->quote($code), $codes));
        $templateExists = (int) (billing_ops_scalar($db, "SELECT COUNT(*) FROM communication_templates WHERE code IN ({$quotedCodes})") ?? 0);
        billing_ops_check_add(
            $checks,
            'messages',
            'template:' . $templateName,
            $templateName === 'payment_received' || str_starts_with($templateName, 'service_') ? 'critical' : 'warning',
            $templateExists > 0,
            $templateExists > 0 ? 'Template exists: ' . implode(' / ', $codes) : 'Template is missing: ' . implode(' / ', $codes)
        );
    }

    $lastMaintenance = billing_ops_first_row(
        $db,
        "SELECT *
         FROM billing_maintenance_runs
         ORDER BY finished_at DESC, id DESC
         LIMIT 1"
    );
    $summary['last_maintenance_at'] = (string) ($lastMaintenance['finished_at'] ?? '');
    $maintenanceRecent = $lastMaintenance !== [] && strtotime((string) ($lastMaintenance['finished_at'] ?? '')) !== false
        && strtotime((string) ($lastMaintenance['finished_at'] ?? '')) >= strtotime('-36 hours');
    billing_ops_check_add(
        $checks,
        'automation',
        'billing_maintenance_recent',
        'warning',
        $maintenanceRecent,
        $lastMaintenance !== []
            ? 'Last maintenance: ' . (string) ($lastMaintenance['finished_at'] ?? '-') . ' / mode=' . (string) ($lastMaintenance['mode'] ?? '-')
            : 'No real billing maintenance run logged yet.'
    );

    $pageMap = [
        'billing-dashboard.php',
        'billing-customers.php',
        'billing-customer-profile.php',
        'billing-cash-payment.php',
        'billing-payments.php',
        'billing-statistics.php',
        'radius-active.php',
        'radius-logs.php',
        'radius-nas.php',
        'message-center.php',
        'ip-pools.php',
    ];
    foreach ($pageMap as $page) {
        billing_ops_check_add($checks, 'ui', 'page:' . $page, 'critical', billing_ops_check_page($page), billing_ops_check_page($page) ? 'Page file exists.' : 'Page file missing.');
    }

    $performanceIndexes = [
        'portal_accounts' => ['idx_portal_accounts_role_updated_id', 'idx_portal_accounts_role_active_status'],
        'billing_radius_services' => ['idx_billing_radius_services_account_status_id', 'idx_billing_radius_services_updated'],
        'billing_payments' => ['idx_billing_payments_created_id', 'idx_billing_payments_method_created'],
        'communication_messages' => ['idx_communication_messages_status_created'],
        'portal_tickets' => ['idx_portal_tickets_status_opened'],
        'radacct' => ['idx_radacct_online_recent', 'idx_radacct_update_recent'],
        'radpostauth' => ['idx_radpostauth_reply_authdate'],
    ];
    foreach ($performanceIndexes as $table => $indexes) {
        foreach ($indexes as $index) {
            $exists = billing_ops_index_exists($db, $table, $index);
            billing_ops_check_add(
                $checks,
                'performance',
                'index:' . $index,
                'warning',
                $exists,
                $exists ? 'Performance index exists on ' . $table . '.' : 'Run tools/optimize-billing-performance.php --apply'
            );
        }
    }

    $bootstrapStampPath = __DIR__ . '/data/db-bootstrap-ok.json';
    $bootstrapStampReady = is_file($bootstrapStampPath) && filesize($bootstrapStampPath) > 20;
    billing_ops_check_add(
        $checks,
        'performance',
        'db_bootstrap_cache',
        'warning',
        $bootstrapStampReady,
        $bootstrapStampReady
            ? 'DB bootstrap cache stamp exists; normal page requests skip heavy schema migration checks.'
            : 'Open one billing page or run a smoke test after deployment to warm the DB bootstrap cache.'
    );

    $criticalFailed = array_values(array_filter($checks, static fn (array $check): bool => ($check['level'] ?? '') === 'critical' && empty($check['ok'])));
    $warningFailed = array_values(array_filter($checks, static fn (array $check): bool => ($check['level'] ?? '') === 'warning' && empty($check['ok'])));

    return [
        'status' => $criticalFailed === [] ? ($warningFailed === [] ? 'ok' : 'warning') : 'failed',
        'summary' => $summary,
        'checks' => $checks,
        'critical_failed' => count($criticalFailed),
        'warnings_failed' => count($warningFailed),
        'generated_at' => date(DATE_ATOM),
    ];
}
