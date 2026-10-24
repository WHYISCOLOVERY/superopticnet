<?php
declare(strict_types=1);

/**
 * Permission-scoped, read-only business analysis for the Aygun agent system.
 *
 * The public projection is aggregate-only: no customer names, phone numbers,
 * identifiers, payment references or credentials are returned. Every metric
 * carries a definition and a source so an agent cannot turn an empty queue into
 * an invented business answer.
 */

require_once __DIR__ . '/analytics-center-lib.php';
require_once __DIR__ . '/finance-module-lib.php';
require_once __DIR__ . '/inventory-module-lib.php';

function app_business_analysis_modules(): array
{
    return ['billing', 'finance', 'inventory', 'crm', 'customer'];
}

function app_business_analysis_required_modules(string $module): array
{
    return $module === 'customer' ? ['crm', 'billing'] : [$module];
}

function app_business_analysis_allowed(array $actor, string $module): bool
{
    foreach (app_business_analysis_required_modules($module) as $required) {
        if (!function_exists('app_portal_can_access_module') || !app_portal_can_access_module($actor, $required)) {
            return false;
        }
    }
    return true;
}

function app_business_nullable_scalar(PDO $db, string $sql, array $params = []): ?float
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        return $value === false ? null : (float) $value;
    } catch (Throwable) {
        return null;
    }
}

function app_business_rows(PDO $db, string $sql, array $params = [], int $limit = 12): array
{
    try {
        $statement = $db->prepare($sql . ' LIMIT ' . max(1, min(24, $limit)));
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_business_dataset_rows(PDO $db, string $sql, array $params = [], int $limit = 12): array
{
    return array_map(static fn (array $row): array => [
        'label' => (string) ($row['label'] ?? '-'),
        'value' => (float) ($row['value_total'] ?? 0),
        'count' => (int) ($row['row_count'] ?? 0),
    ], app_business_rows($db, $sql, $params, $limit));
}

function app_business_kpi(
    string $key,
    string $label,
    float|int|null $value,
    string $kind,
    string $definition,
    string $source
): array {
    return [
        'key' => $key,
        'label' => $label,
        'value' => $value,
        'kind' => $kind,
        'definition' => $definition,
        'source' => $source,
        'data_status' => $value === null ? 'unavailable' : 'verified',
    ];
}

function app_business_source_profile(PDO $db, string $table, string $latestColumn = '', int $maxAgeHours = 0): array
{
    if (!app_analytics_table_exists($db, $table)) {
        return ['table' => $table, 'status' => 'missing', 'row_count' => null, 'latest_at' => null];
    }
    $rowCount = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM ' . $table);
    $latestAt = null;
    if ($latestColumn !== '') {
        try {
            $latestAt = $db->query('SELECT MAX(' . $latestColumn . ') FROM ' . $table)->fetchColumn();
            $latestAt = $latestAt === false || $latestAt === null ? null : (string) $latestAt;
        } catch (Throwable) {
            $latestAt = null;
        }
    }
    $status = $rowCount === null ? 'unavailable' : 'ready';
    $ageHours = null;
    if ($latestAt !== null && strtotime($latestAt) !== false) {
        $ageHours = max(0, round((time() - (int) strtotime($latestAt)) / 3600, 1));
        if ($maxAgeHours > 0 && $ageHours > $maxAgeHours) {
            $status = 'stale';
        }
    }
    return [
        'table' => $table,
        'status' => $status,
        'row_count' => $rowCount === null ? null : (int) $rowCount,
        'latest_at' => $latestAt,
        'age_hours' => $ageHours,
        'freshness_limit_hours' => $maxAgeHours > 0 ? $maxAgeHours : null,
    ];
}

function app_business_source_profiles(PDO $db, array $spec): array
{
    $profiles = [];
    foreach ($spec as $table => $sourceSpec) {
        $latestColumn = is_array($sourceSpec) ? (string) ($sourceSpec['latest'] ?? '') : (string) $sourceSpec;
        $maxAgeHours = is_array($sourceSpec) ? max(0, (int) ($sourceSpec['max_age_hours'] ?? 0)) : 0;
        $profiles[] = app_business_source_profile($db, (string) $table, $latestColumn, $maxAgeHours);
    }
    return $profiles;
}

function app_business_canonical_kpis(array $rows, array $definitions): array
{
    $result = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = (string) ($row['label'] ?? ('KPI ' . ($index + 1)));
        $definition = $definitions[$label] ?? [
            'key' => 'metric_' . ($index + 1),
            'definition' => $label,
            'source' => 'canonical module read model',
        ];
        $result[] = app_business_kpi(
            (string) $definition['key'],
            $label,
            is_numeric($row['value'] ?? null) ? (float) $row['value'] : null,
            (string) ($row['kind'] ?? 'count'),
            (string) $definition['definition'],
            (string) $definition['source']
        );
    }
    return $result;
}

function app_business_dataset_projection(array $datasets): array
{
    $result = [];
    foreach ($datasets as $key => $dataset) {
        if (!is_array($dataset)) {
            continue;
        }
        $rows = array_values(array_filter((array) ($dataset['rows'] ?? []), 'is_array'));
        $result[] = [
            'key' => (string) $key,
            'title' => (string) ($dataset['title'] ?? $key),
            'kind' => (string) ($dataset['kind'] ?? 'count'),
            'rows' => array_slice($rows, 0, 24),
        ];
    }
    return $result;
}

function app_business_values(array $kpis): array
{
    $values = [];
    foreach ($kpis as $kpi) {
        if (is_array($kpi)) {
            $values[(string) ($kpi['key'] ?? '')] = $kpi['value'] ?? null;
        }
    }
    return $values;
}

function app_business_recommendation(
    string $severity,
    string $title,
    string $evidence,
    string $nextAction,
    bool $approvalRequired = true,
    array $sources = []
): array {
    return [
        'severity' => $severity,
        'title' => $title,
        'evidence' => $evidence,
        'next_action' => $nextAction,
        'approval_required' => $approvalRequired,
        'execution_state' => 'proposal_only',
        'data_status' => 'verified',
        'sources' => array_values(array_unique(array_map('strval', $sources))),
    ];
}

function app_business_billing_snapshot(PDO $db): array
{
    $stats = app_billing_statistics_context($db, ['range' => 'month']);
    $definitions = [
        'Dovr odenisi' => ['key' => 'period_revenue', 'definition' => 'Cari hesabat dövründə qeydə alınmış ödənişlərin cəmi.', 'source' => 'billing_payments'],
        'Aktiv musteri' => ['key' => 'active_customers', 'definition' => 'Hazırda aktiv olan müştəri hesabları.', 'source' => 'portal_accounts'],
        'Aktiv xidmet' => ['key' => 'active_services', 'definition' => 'Statusu active olan RADIUS xidmətləri.', 'source' => 'billing_radius_services'],
        'Aktiv paket' => ['key' => 'active_tariffs', 'definition' => 'Statusu active olan tariflər.', 'source' => 'billing_tariffs'],
    ];
    $kpis = app_business_canonical_kpis((array) ($stats['kpis'] ?? []), $definitions);
    $debtSql = app_billing_debt_sql('a');
    $debtCount = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM portal_accounts a WHERE a.role = "customer" AND ' . $debtSql . ' > 0');
    $debtTotal = app_business_nullable_scalar($db, 'SELECT COALESCE(SUM(' . $debtSql . '),0) FROM portal_accounts a WHERE a.role = "customer" AND ' . $debtSql . ' > 0');
    $expired = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services WHERE status IN ("expired","suspended","disabled")');
    $expiring = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services WHERE status = "active" AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)');
    $kpis[] = app_business_kpi('debt_customers', 'Borclu müştəri', $debtCount, 'count', 'Billing borcu sıfırdan böyük olan müştəri hesabları.', 'portal_accounts.billing_debt_amount/payload_json');
    $kpis[] = app_business_kpi('debt_total', 'Müştəri borcu', $debtTotal, 'money', 'Müsbət müştəri borclarının cəmi.', 'portal_accounts.billing_debt_amount/payload_json');
    $kpis[] = app_business_kpi('expired_services', 'Dayanmış xidmət', $expired, 'count', 'Expired, suspended və ya disabled RADIUS xidmətləri.', 'billing_radius_services');
    $kpis[] = app_business_kpi('expiring_3d', '3 günə bitən', $expiring, 'count', 'Növbəti 3 gündə bitəcək aktiv xidmətlər.', 'billing_radius_services.expires_at');

    $values = app_business_values($kpis);
    $recommendations = [];
    if (($values['debt_customers'] ?? 0) > 0) {
        $recommendations[] = app_business_recommendation('high', 'Gecikmiş ödəniş seqmentini işləyin', (int) $values['debt_customers'] . ' borclu müştəri · ' . number_format((float) ($values['debt_total'] ?? 0), 2, '.', ' ') . ' AZN', 'Müştəriləri borc yaşına görə qruplaşdırıb bildiriş proposalı hazırlayın.', true, ['portal_accounts']);
    }
    if (($values['expiring_3d'] ?? 0) > 0) {
        $recommendations[] = app_business_recommendation('medium', 'Bitən xidmətlər üçün qabaqlayıcı yoxlama', (int) $values['expiring_3d'] . ' xidmət 3 günə bitir', 'Ödəniş və xidmət statusunu yoxlayıb müştəri mesajı üçün təsdiqli proposal yaradın.', true, ['billing_radius_services']);
    }
    return [
        'kpis' => $kpis,
        'datasets' => app_business_dataset_projection((array) ($stats['datasets'] ?? [])),
        'recommendations' => $recommendations,
        'data_quality' => (array) ($stats['data_quality'] ?? []),
        'sources' => app_business_source_profiles($db, [
            'billing_payments' => ['latest' => 'created_at', 'max_age_hours' => 36],
            'portal_accounts' => ['latest' => 'updated_at', 'max_age_hours' => 36],
            'billing_radius_services' => ['latest' => 'last_radius_sync_at', 'max_age_hours' => 24],
            'billing_tariffs' => 'updated_at',
        ]),
        'deep_link' => 'billing.php?billing_section=statistics',
    ];
}

function app_business_finance_snapshot(PDO $db): array
{
    $stats = app_finance_statistics_context($db, ['range' => 'month', 'report_type' => 'overview']);
    $definitions = [
        'Gelir' => ['key' => 'income', 'definition' => 'Cari dövrdə billing ödənişləri və posted manual gəlir.', 'source' => 'billing_payments + finance_entries'],
        'Xerc' => ['key' => 'expense', 'definition' => 'Cari dövrdə posted AZN xərclər.', 'source' => 'finance_entries'],
        'Net pul axini' => ['key' => 'net_cash_flow', 'definition' => 'Gəlir minus xərc.', 'source' => 'finance statistics canonical model'],
        'Alacaq' => ['key' => 'receivable', 'definition' => 'Open və partial debitor qalığının cəmi.', 'source' => 'finance_debt_ledger'],
        'Borc' => ['key' => 'payable', 'definition' => 'Open və partial kreditor qalığının cəmi.', 'source' => 'finance_debt_ledger'],
        'Qaime' => ['key' => 'invoice_count', 'definition' => 'Cari dövrdə idxal edilmiş e-qaimə sayı.', 'source' => 'finance_e_invoice_imports'],
    ];
    $kpis = app_business_canonical_kpis((array) ($stats['kpis'] ?? []), $definitions);
    $values = app_business_values($kpis);
    $recommendations = [];
    if (($values['net_cash_flow'] ?? 0) < 0) {
        $recommendations[] = app_business_recommendation('high', 'Mənfi pul axınını araşdırın', number_format((float) $values['net_cash_flow'], 2, '.', ' ') . ' AZN net pul axını', 'Xərc kateqoriyalarını və ödəniş kanallarını müqayisə edib büdcə dəyişikliyi proposalı hazırlayın.', true, ['billing_payments', 'finance_entries']);
    }
    if (($values['receivable'] ?? 0) > 0) {
        $recommendations[] = app_business_recommendation('medium', 'Açıq alacaqları prioritetləşdirin', number_format((float) $values['receivable'], 2, '.', ' ') . ' AZN açıq alacaq', 'Alacaqları yaş və qarşı tərəf üzrə bölüb collection planını təsdiqə göndərin.', true, ['finance_debt_ledger']);
    }
    return [
        'kpis' => $kpis,
        'datasets' => app_business_dataset_projection((array) ($stats['datasets'] ?? [])),
        'recommendations' => $recommendations,
        'data_quality' => (array) ($stats['data_quality'] ?? []),
        'sources' => app_business_source_profiles($db, [
            'billing_payments' => ['latest' => 'created_at', 'max_age_hours' => 36],
            'finance_entries' => ['latest' => 'created_at', 'max_age_hours' => 168],
            'finance_debt_ledger' => 'updated_at', 'finance_e_invoice_imports' => 'created_at',
        ]),
        'deep_link' => 'operations-center.php?section=finance&finance_tab=statistics',
    ];
}

function app_business_inventory_snapshot(PDO $db): array
{
    $stats = app_inventory_statistics_context($db, ['range' => 'month']);
    $definitions = [
        'Aktiv mehsul' => ['key' => 'active_products', 'definition' => 'Aktiv və əməliyyat stokuna daxil məhsullar.', 'source' => 'inventory_stock_items'],
        'Stok vahidi' => ['key' => 'stock_units', 'definition' => 'Aktiv məhsullar üzrə mövcud stok vahidlərinin cəmi.', 'source' => 'inventory_stock_items'],
        'Kritik stok' => ['key' => 'critical_stock', 'definition' => 'Qalıq minimum həddə bərabər və ya ondan aşağı olan aktiv məhsullar.', 'source' => 'inventory_stock_items'],
        'Serialized cihaz' => ['key' => 'serialized_assets', 'definition' => 'Serial səviyyəsində qeydiyyatda olan cihazlar.', 'source' => 'inventory_assets'],
        'Musteride cihaz' => ['key' => 'customer_assets', 'definition' => 'Müştəriyə aktiv təyin edilmiş avadanlıqlar.', 'source' => 'customer_equipment_assignments'],
        'Qalan kabel' => ['key' => 'remaining_cable_m', 'definition' => 'Barabanlarda qalan kabel uzunluğu.', 'source' => 'inventory_cable_drums'],
    ];
    $kpis = app_business_canonical_kpis((array) ($stats['kpis'] ?? []), $definitions);
    $pendingInspection = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM inventory_asset_returns WHERE return_status = "received"');
    $kpis[] = app_business_kpi('pending_inspection', 'Yoxlama gözləyən qaytarma', $pendingInspection, 'count', 'Qəbul edilib texniki yoxlaması tamamlanmayan qaytarmalar.', 'inventory_asset_returns');
    $values = app_business_values($kpis);
    $recommendations = [];
    if (($values['critical_stock'] ?? 0) > 0) {
        $recommendations[] = app_business_recommendation('critical', 'Kritik stok siyahısını işləyin', (int) $values['critical_stock'] . ' məhsul minimum həddədir', 'İstehlak sürəti və lead-time ilə satınalma proposalı hazırlayın.', true, ['inventory_stock_items']);
    }
    if (($values['pending_inspection'] ?? 0) > 0) {
        $recommendations[] = app_business_recommendation('medium', 'Qaytarma yoxlamasını tamamlayın', (int) $values['pending_inspection'] . ' qaytarma yoxlama gözləyir', 'Cihaz vəziyyətini yoxlayıb stok/təmir qərarını təsdiqə göndərin.', true, ['inventory_asset_returns']);
    }
    return [
        'kpis' => $kpis,
        'datasets' => app_business_dataset_projection((array) ($stats['datasets'] ?? [])),
        'recommendations' => $recommendations,
        'data_quality' => [],
        'sources' => app_business_source_profiles($db, [
            'inventory_stock_items' => ['latest' => 'updated_at', 'max_age_hours' => 48],
            'inventory_assets' => ['latest' => 'updated_at', 'max_age_hours' => 48],
            'inventory_stock_movements' => ['latest' => 'created_at', 'max_age_hours' => 48],
            'customer_equipment_assignments' => ['latest' => 'updated_at', 'max_age_hours' => 48],
            'inventory_asset_returns' => ['latest' => 'received_at', 'max_age_hours' => 168],
        ]),
        'deep_link' => 'operations-center.php?section=inventory&inventory_tab=statistics',
    ];
}

function app_business_crm_snapshot(PDO $db): array
{
    $openWhere = "status NOT IN ('Baglandi','closed','resolved')";
    $totalCustomers = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM portal_accounts WHERE role = "customer"');
    $activeCustomers = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM portal_accounts WHERE role = "customer" AND is_active = 1');
    $openTickets = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM portal_tickets WHERE ' . $openWhere);
    $staleTickets = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM portal_tickets WHERE ' . $openWhere . ' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
    $blockedTickets = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM portal_tickets WHERE status = "Basqa_Sobede_Gozleyir"');
    $inactiveCustomers = app_business_nullable_scalar(
        $db,
        'SELECT COUNT(*) FROM portal_accounts a LEFT JOIN (SELECT account_id, MAX(created_at) AS last_payment_at FROM billing_payments GROUP BY account_id) lp ON lp.account_id = a.id WHERE a.role = "customer" AND a.is_active = 1 AND (lp.last_payment_at IS NULL OR lp.last_payment_at < DATE_SUB(NOW(), INTERVAL 60 DAY))'
    );
    $kpis = [
        app_business_kpi('total_customers', 'Müştəri hesabı', $totalCustomers, 'count', 'Bütün müştəri hesabları.', 'portal_accounts'),
        app_business_kpi('active_customers', 'Aktiv müştəri', $activeCustomers, 'count', 'Hazırda aktiv müştəri hesabları.', 'portal_accounts'),
        app_business_kpi('open_tickets', 'Açıq müraciət', $openTickets, 'count', 'Bağlanmamış bütün müştəri müraciətləri.', 'portal_tickets'),
        app_business_kpi('stale_tickets', '7+ günlük müraciət', $staleTickets, 'count', 'Yeddi gündən köhnə və hələ bağlanmamış müraciətlər.', 'portal_tickets'),
        app_business_kpi('blocked_tickets', 'Şöbə gözləyən', $blockedTickets, 'count', 'Başqa şöbədən asılı qalan müraciətlər.', 'portal_tickets'),
        app_business_kpi('inactive_60d', '60+ gün ödənişsiz', $inactiveCustomers, 'count', 'Son ödənişi 60 gündən köhnə və ya ödənişi olmayan aktiv müştərilər.', 'portal_accounts + billing_payments'),
    ];
    $datasets = [
        ['key' => 'ticket_status', 'title' => 'Müraciət statusları', 'kind' => 'count', 'rows' => app_business_dataset_rows($db, 'SELECT COALESCE(NULLIF(status,""),"unknown") AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM portal_tickets GROUP BY status ORDER BY value_total DESC')],
        ['key' => 'customer_growth', 'title' => 'Yeni müştəri trendi', 'kind' => 'count', 'rows' => app_business_dataset_rows($db, 'SELECT DATE_FORMAT(created_at,"%Y-%m") AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM portal_accounts WHERE role = "customer" AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(created_at,"%Y-%m") ORDER BY label', [], 12)],
        ['key' => 'ticket_age', 'title' => 'Açıq müraciət yaşlanması', 'kind' => 'count', 'rows' => app_business_dataset_rows($db, 'SELECT CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN "0-1 gün" WHEN created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY) THEN "2-3 gün" WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN "4-7 gün" ELSE "7+ gün" END AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM portal_tickets WHERE ' . $openWhere . ' GROUP BY label ORDER BY MIN(created_at) DESC', [], 8)],
    ];
    $values = app_business_values($kpis);
    $recommendations = [];
    if (($values['stale_tickets'] ?? 0) > 0) {
        $recommendations[] = app_business_recommendation('high', 'Köhnəlmiş müraciətləri yenidən bölüşdürün', (int) $values['stale_tickets'] . ' müraciət 7 gündən köhnədir', 'Sahibsiz və şöbə gözləyən müraciətləri qruplaşdırıb owner/son tarix proposalı hazırlayın.', true, ['portal_tickets']);
    }
    if (($values['inactive_60d'] ?? 0) > 0) {
        $recommendations[] = app_business_recommendation('medium', 'Retention seqmenti hazırlayın', (int) $values['inactive_60d'] . ' aktiv hesab 60+ gündür ödənişsizdir', 'Səbəb və xidmət statusunu yoxlayıb hədəflənmiş kampaniya proposalı yaradın.', true, ['portal_accounts', 'billing_payments']);
    }
    return [
        'kpis' => $kpis,
        'datasets' => $datasets,
        'recommendations' => $recommendations,
        'data_quality' => [],
        'sources' => app_business_source_profiles($db, [
            'portal_accounts' => ['latest' => 'updated_at', 'max_age_hours' => 36],
            'portal_tickets' => ['latest' => 'updated_at', 'max_age_hours' => 48],
            'billing_payments' => ['latest' => 'created_at', 'max_age_hours' => 36],
        ]),
        'deep_link' => 'crm.php',
    ];
}

function app_business_customer_snapshot(PDO $db): array
{
    $total = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM portal_accounts WHERE role = "customer"');
    $active = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM portal_accounts WHERE role = "customer" AND is_active = 1');
    $activeServices = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services WHERE status = "active"');
    $expiring = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services WHERE status = "active" AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)');
    $debtSql = app_billing_debt_sql('a');
    $debtCustomers = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM portal_accounts a WHERE a.role = "customer" AND ' . $debtSql . ' > 0');
    $inactive60 = app_business_nullable_scalar($db, 'SELECT COUNT(*) FROM portal_accounts a LEFT JOIN (SELECT account_id, MAX(created_at) AS last_payment_at FROM billing_payments GROUP BY account_id) lp ON lp.account_id = a.id WHERE a.role = "customer" AND a.is_active = 1 AND (lp.last_payment_at IS NULL OR lp.last_payment_at < DATE_SUB(NOW(), INTERVAL 60 DAY))');
    $kpis = [
        app_business_kpi('total_customers', 'Müştəri hesabı', $total, 'count', 'Bütün müştəri hesabları.', 'portal_accounts'),
        app_business_kpi('active_customers', 'Aktiv müştəri', $active, 'count', 'Hazırda aktiv müştəri hesabları.', 'portal_accounts'),
        app_business_kpi('active_services', 'Aktiv xidmət', $activeServices, 'count', 'Aktiv RADIUS xidmətləri.', 'billing_radius_services'),
        app_business_kpi('expiring_3d', '3 günə bitən', $expiring, 'count', 'Növbəti 3 gündə bitəcək aktiv xidmətlər.', 'billing_radius_services'),
        app_business_kpi('debt_customers', 'Borclu müştəri', $debtCustomers, 'count', 'Müsbət billing borcu olan müştərilər.', 'portal_accounts.billing_debt_amount/payload_json'),
        app_business_kpi('inactive_60d', '60+ gün ödənişsiz', $inactive60, 'count', 'Son ödənişi 60 gündən köhnə və ya ödənişi olmayan aktiv müştərilər.', 'portal_accounts + billing_payments'),
    ];
    $datasets = [
        ['key' => 'customer_status', 'title' => 'Müştəri statusu', 'kind' => 'count', 'rows' => app_business_dataset_rows($db, 'SELECT CASE WHEN is_active = 1 THEN "Aktiv" ELSE "Passiv" END AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM portal_accounts WHERE role = "customer" GROUP BY is_active ORDER BY value_total DESC')],
        ['key' => 'service_status', 'title' => 'Xidmət statusu', 'kind' => 'count', 'rows' => app_business_dataset_rows($db, 'SELECT COALESCE(NULLIF(status,""),"unknown") AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM billing_radius_services GROUP BY status ORDER BY value_total DESC')],
        ['key' => 'payment_recency', 'title' => 'Ödəniş yeniliyi seqmenti', 'kind' => 'count', 'rows' => app_business_dataset_rows($db, 'SELECT CASE WHEN lp.last_payment_at IS NULL THEN "Ödəniş yoxdur" WHEN lp.last_payment_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN "0-30 gün" WHEN lp.last_payment_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) THEN "31-60 gün" WHEN lp.last_payment_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN "61-90 gün" ELSE "90+ gün" END AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM portal_accounts a LEFT JOIN (SELECT account_id, MAX(created_at) AS last_payment_at FROM billing_payments GROUP BY account_id) lp ON lp.account_id = a.id WHERE a.role = "customer" AND a.is_active = 1 GROUP BY label ORDER BY value_total DESC', [], 8)],
        ['key' => 'customer_growth', 'title' => 'Yeni müştəri trendi', 'kind' => 'count', 'rows' => app_business_dataset_rows($db, 'SELECT DATE_FORMAT(created_at,"%Y-%m") AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM portal_accounts WHERE role = "customer" AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(created_at,"%Y-%m") ORDER BY label', [], 12)],
    ];
    $values = app_business_values($kpis);
    $recommendations = [];
    if (($values['debt_customers'] ?? 0) > 0 || ($values['inactive_60d'] ?? 0) > 0) {
        $recommendations[] = app_business_recommendation('high', 'Müştəri risk seqmentlərini yoxlayın', (int) ($values['debt_customers'] ?? 0) . ' borclu · ' . (int) ($values['inactive_60d'] ?? 0) . ' uzun müddət ödənişsiz', 'Borclu, xidmət riski və passivlik siqnallarını birləşdirib retention/collection proposalı hazırlayın.', true, ['portal_accounts', 'billing_payments']);
    }
    return [
        'kpis' => $kpis,
        'datasets' => $datasets,
        'recommendations' => $recommendations,
        'data_quality' => [],
        'sources' => app_business_source_profiles($db, [
            'portal_accounts' => ['latest' => 'updated_at', 'max_age_hours' => 36],
            'billing_payments' => ['latest' => 'created_at', 'max_age_hours' => 36],
            'billing_radius_services' => ['latest' => 'last_radius_sync_at', 'max_age_hours' => 24],
        ]),
        'deep_link' => 'billing-customers.php',
    ];
}

function app_business_analysis_snapshot(PDO $db, array $actor, string $module): array
{
    $module = strtolower(trim($module));
    if (!in_array($module, app_business_analysis_modules(), true)) {
        return ['ok' => false, 'reason' => 'invalid_module'];
    }
    if (!app_business_analysis_allowed($actor, $module)) {
        return ['ok' => false, 'reason' => 'module_denied'];
    }

    $payload = match ($module) {
        'billing' => app_business_billing_snapshot($db),
        'finance' => app_business_finance_snapshot($db),
        'inventory' => app_business_inventory_snapshot($db),
        'crm' => app_business_crm_snapshot($db),
        'customer' => app_business_customer_snapshot($db),
    };
    $sources = (array) ($payload['sources'] ?? []);
    $degraded = array_values(array_filter($sources, static fn (array $source): bool => ($source['status'] ?? '') !== 'ready'));
    $unavailableKpis = array_values(array_filter((array) ($payload['kpis'] ?? []), static fn (array $kpi): bool => ($kpi['data_status'] ?? '') !== 'verified'));
    $quality = (array) ($payload['data_quality'] ?? []);
    $quality['status'] = ($degraded === [] && $unavailableKpis === []) ? 'ready' : 'degraded';
    $quality['source_issues'] = array_map(static fn (array $source): string => (string) ($source['table'] ?? 'unknown') . ':' . (string) ($source['status'] ?? 'unavailable'), $degraded);
    $quality['unavailable_metrics'] = array_map(static fn (array $kpi): string => (string) ($kpi['key'] ?? ''), $unavailableKpis);

    $sourceIssues = [];
    foreach ($degraded as $source) {
        $sourceIssues[(string) ($source['table'] ?? 'unknown')] = (string) ($source['status'] ?? 'unavailable');
    }
    $recommendations = array_values((array) ($payload['recommendations'] ?? []));
    foreach ($recommendations as &$recommendation) {
        $blockedReasons = [];
        foreach ((array) ($recommendation['sources'] ?? []) as $sourceName) {
            if (isset($sourceIssues[(string) $sourceName])) {
                $blockedReasons[] = (string) $sourceName . ':' . $sourceIssues[(string) $sourceName];
            }
        }
        if ($blockedReasons !== []) {
            $recommendation['data_status'] = 'degraded';
            $recommendation['execution_state'] = 'blocked_by_data_quality';
            $recommendation['blocked_reasons'] = $blockedReasons;
        }
    }
    unset($recommendation);

    return [
        'ok' => true,
        'schema' => 1,
        'module' => $module,
        'generated_at' => date(DATE_ATOM),
        'data_status' => $quality['status'],
        'kpis' => array_values((array) ($payload['kpis'] ?? [])),
        'datasets' => array_values((array) ($payload['datasets'] ?? [])),
        'recommendations' => $recommendations,
        'data_quality' => $quality,
        'sources' => $sources,
        'deep_link' => (string) ($payload['deep_link'] ?? ''),
        'privacy' => 'aggregate_only',
        'writes_performed' => false,
    ];
}
