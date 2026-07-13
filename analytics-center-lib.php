<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feature-flags-lib.php';

function app_analytics_table_exists(PDO $db, string $table): bool
{
    try {
        $statement = $db->prepare('SHOW TABLES LIKE :table_name');
        $statement->execute(['table_name' => $table]);
        return (bool) $statement->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function app_analytics_scalar(PDO $db, string $sql, array $params = []): float
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return (float) $statement->fetchColumn();
    } catch (Throwable) {
        return 0.0;
    }
}

function app_analytics_rows(PDO $db, string $sql, array $params = [], int $limit = 12): array
{
    try {
        $statement = $db->prepare($sql . ' LIMIT ' . max(1, min(50, $limit)));
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_analytics_money(float|int|string $value): string
{
    return number_format((float) $value, 2, '.', ' ') . ' AZN';
}

function app_analytics_center_context(PDO $db, array $user = []): array
{
    $tables = [
        'billing_payments' => ['domain' => 'Billing', 'task' => 'Gelir, provider, terminal ve aktiv odeyen analizi'],
        'portal_accounts' => ['domain' => 'CRM', 'task' => 'Musteri artimi, FIN/vesiqe tamligi, rol segmentleri'],
        'billing_radius_services' => ['domain' => 'RADIUS', 'task' => 'Aktiv xidmet, bitme tarixi ve churn riski'],
        'customer_ip_assignments' => ['domain' => 'IPAM', 'task' => 'IP teyinati ve konflikt auditi'],
        'ip_addresses' => ['domain' => 'Network', 'task' => 'Pool doluluq ve rezerv veziyyeti'],
        'billing_tariffs' => ['domain' => 'Paketler', 'task' => 'Paket performansi ve support xerc baglantisi'],
        'legacy_billing_profile_sections' => ['domain' => 'Migration', 'task' => 'Kohne billing data keyfiyyeti'],
        'employee_bonus_summaries' => ['domain' => 'Maliyye / HR', 'task' => 'Bonus periodleri ve emekdas neticeleri'],
        'communication_messages' => ['domain' => 'Mesaj', 'task' => 'Kanal paylanmasi ve campaign neticesi'],
    ];

    $tableRows = [];
    foreach ($tables as $table => $meta) {
        $exists = app_analytics_table_exists($db, $table);
        $tableRows[] = [
            'table' => $table,
            'domain' => $meta['domain'],
            'rows' => $exists ? (int) app_analytics_scalar($db, 'SELECT COUNT(*) FROM ' . $table) : 0,
            'status' => $exists ? 'ready' : 'missing',
            'task' => $meta['task'],
        ];
    }
    usort($tableRows, static fn (array $a, array $b): int => ((int) $b['rows']) <=> ((int) $a['rows']));

    $monthlyRevenue = app_analytics_rows(
        $db,
        'SELECT DATE_FORMAT(created_at, "%Y-%m") AS label, COALESCE(SUM(amount),0) AS value_total, COUNT(*) AS row_count
         FROM billing_payments
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         GROUP BY DATE_FORMAT(created_at, "%Y-%m")
         ORDER BY label DESC',
        [],
        12
    );
    $providerRevenue = app_analytics_rows(
        $db,
        'SELECT payment_method AS label, COALESCE(SUM(amount),0) AS value_total, COUNT(*) AS row_count
         FROM billing_payments
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
         GROUP BY payment_method
         ORDER BY value_total DESC',
        [],
        8
    );
    $radiusRisk = app_analytics_rows(
        $db,
        'SELECT status AS label, COUNT(*) AS row_count
         FROM billing_radius_services
         GROUP BY status
         ORDER BY row_count DESC',
        [],
        8
    );
    $bonusPeriods = app_analytics_table_exists($db, 'employee_bonus_summaries')
        ? app_analytics_rows(
            $db,
            'SELECT p.period_start AS label, COALESCE(SUM(s.total_payable),0) AS value_total, COUNT(*) AS row_count
             FROM employee_bonus_summaries s
             LEFT JOIN bonus_periods p ON p.id = s.period_id
             GROUP BY p.period_start
             ORDER BY p.period_start DESC',
            [],
            6
        )
        : [];
    $messageChannels = app_analytics_table_exists($db, 'communication_messages')
        ? app_analytics_rows(
            $db,
            'SELECT channel AS label, COUNT(*) AS row_count
             FROM communication_messages
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
             GROUP BY channel
             ORDER BY row_count DESC',
            [],
            8
        )
        : [];

    return [
        'generated_at' => date(DATE_ATOM),
        'feature_flags' => app_feature_flags_for_user($db, $user),
        'tables' => $tableRows,
        'kpis' => [
            ['label' => 'Odenis qeydi', 'value' => (int) app_analytics_scalar($db, 'SELECT COUNT(*) FROM billing_payments'), 'kind' => 'count'],
            ['label' => 'Son 30 gun gelir', 'value' => app_analytics_scalar($db, 'SELECT COALESCE(SUM(amount),0) FROM billing_payments WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'), 'kind' => 'money'],
            ['label' => 'Musteri hesabi', 'value' => (int) app_analytics_scalar($db, 'SELECT COUNT(*) FROM portal_accounts WHERE role = "customer"'), 'kind' => 'count'],
            ['label' => 'Aktiv xidmet', 'value' => (int) app_analytics_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services WHERE status = "active"'), 'kind' => 'count'],
            ['label' => '3 gune biten xidmet', 'value' => (int) app_analytics_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services WHERE status = "active" AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)'), 'kind' => 'count'],
            ['label' => 'Aciq bonus bildiris', 'value' => app_analytics_table_exists($db, 'employee_bonus_notifications') ? (int) app_analytics_scalar($db, 'SELECT COUNT(*) FROM employee_bonus_notifications WHERE status IN ("new","seen","in_progress")') : 0, 'kind' => 'count'],
        ],
        'charts' => [
            'monthly_revenue' => array_reverse($monthlyRevenue),
            'provider_revenue' => $providerRevenue,
            'radius_risk' => $radiusRisk,
            'bonus_periods' => $bonusPeriods,
            'message_channels' => $messageChannels,
        ],
        'source_notes' => [
            'Billing odenisleri billing_payments cedvelinden oxunur.',
            'RADIUS riski billing_radius_services status ve expires_at sahesi ile hesablanir.',
            'Bonus xulasesi employee_bonus_summaries ve bonus_periods cedvellerinden gelir.',
            'Bu sehife yalniz bounded summary query-lerden istifade edir; agir tarixi scan yoxdur.',
        ],
    ];
}
