<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finance-operations-lib.php';
require_once __DIR__ . '/finance-schema-lib.php';

function app_bonus_ensure_schema(PDO $db): void
{
    app_bonus_require_schema($db);
}

/**
 * @deprecated Schema changes and default rows belong to explicit CLI migration/seed commands.
 */
function app_bonus_create_schema_legacy(PDO $db): void
{
    app_finance_create_operational_schema_legacy($db);
    $db->exec("CREATE TABLE IF NOT EXISTS bonus_rule_settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(96) NOT NULL,
        setting_value VARCHAR(255) NOT NULL,
        value_type ENUM('string','decimal','integer','boolean','json') NOT NULL DEFAULT 'string',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        note VARCHAR(255) DEFAULT NULL,
        updated_by_account_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bonus_rule_setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS bonus_base_salary_tiers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        min_customers INT UNSIGNED NOT NULL DEFAULT 0,
        max_customers INT UNSIGNED DEFAULT NULL,
        salary_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        area_quality_percent DECIMAL(6,2) NOT NULL DEFAULT 100.00,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_bonus_salary_tier_range (is_active, min_customers, max_customers)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS bonus_active_day_brackets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        min_days INT UNSIGNED NOT NULL DEFAULT 0,
        max_days INT UNSIGNED NOT NULL DEFAULT 0,
        active_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_bonus_active_days (is_active, min_days, max_days)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS bonus_quality_rules (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        problem_key VARCHAR(96) NOT NULL,
        problem_label VARCHAR(191) NOT NULL,
        problem_source ENUM('employee','network','customer','unknown','none') NOT NULL DEFAULT 'unknown',
        severity VARCHAR(32) NOT NULL DEFAULT 'normal',
        quality_percent DECIMAL(6,2) NOT NULL DEFAULT 100.00,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bonus_quality_rule_key (problem_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS customer_employee_assignments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_account_id BIGINT UNSIGNED NOT NULL,
        finder_employee_id BIGINT UNSIGNED DEFAULT NULL,
        installer_employee_id BIGINT UNSIGNED DEFAULT NULL,
        area_responsible_employee_id BIGINT UNSIGNED DEFAULT NULL,
        assigned_area_id VARCHAR(128) DEFAULT NULL,
        bonus_owner_employee_id BIGINT UNSIGNED DEFAULT NULL,
        split_mode VARCHAR(64) NOT NULL DEFAULT 'finder_100',
        split_json LONGTEXT DEFAULT NULL,
        assignment_source VARCHAR(64) NOT NULL DEFAULT 'manual',
        effective_from DATE DEFAULT NULL,
        effective_to DATE DEFAULT NULL,
        status ENUM('active','transferred','stopped','inactive') NOT NULL DEFAULT 'active',
        note TEXT DEFAULT NULL,
        created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_bonus_customer_assignment_customer (customer_account_id, status),
        KEY idx_bonus_customer_assignment_finder (finder_employee_id, status),
        KEY idx_bonus_customer_assignment_installer (installer_employee_id, status),
        KEY idx_bonus_customer_assignment_area_owner (area_responsible_employee_id, status),
        KEY idx_bonus_customer_assignment_bonus_owner (bonus_owner_employee_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS bonus_periods (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        period_start DATE NOT NULL,
        period_end DATE NOT NULL,
        calculation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status ENUM('draft','calculated','pending_approval','approved','paid','cancelled','recalculated') NOT NULL DEFAULT 'draft',
        employee_count INT UNSIGNED NOT NULL DEFAULT 0,
        total_base_salary DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        total_new_connection_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        total_recurring_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        total_quality_deduction DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        total_manual_adjustment DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        total_payable DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        incomplete_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
        ownerless_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
        approved_by_account_id BIGINT UNSIGNED DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        paid_by_account_id BIGINT UNSIGNED DEFAULT NULL,
        paid_at DATETIME DEFAULT NULL,
        finance_entry_id BIGINT UNSIGNED DEFAULT NULL,
        metadata_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bonus_period_range (period_start, period_end),
        KEY idx_bonus_period_status (status, period_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS employee_bonus_summaries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        period_id BIGINT UNSIGNED NOT NULL,
        employee_id BIGINT UNSIGNED NOT NULL,
        employee_name VARCHAR(191) NOT NULL DEFAULT '',
        employee_role VARCHAR(96) DEFAULT NULL,
        assigned_area_text VARCHAR(255) DEFAULT NULL,
        active_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
        inactive_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
        problem_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
        late_payment_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
        bonus_eligible_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
        base_salary DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        area_quality_percent DECIMAL(6,2) NOT NULL DEFAULT 100.00,
        new_connection_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        recurring_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        quality_deduction DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        manual_adjustment DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        total_payable DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        status ENUM('draft','calculated','approved','paid','cancelled') NOT NULL DEFAULT 'calculated',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bonus_summary_employee_period (period_id, employee_id),
        KEY idx_bonus_summary_employee (employee_id, period_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS employee_bonus_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        period_id BIGINT UNSIGNED NOT NULL,
        employee_id BIGINT UNSIGNED NOT NULL,
        customer_account_id BIGINT UNSIGNED DEFAULT NULL,
        subscriber_code VARCHAR(64) DEFAULT NULL,
        customer_name VARCHAR(191) DEFAULT NULL,
        item_type ENUM('base_salary','new_connection','recurring_active_payment','quality_deduction','manual_adjustment','info') NOT NULL DEFAULT 'info',
        base_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        active_days INT UNSIGNED NOT NULL DEFAULT 0,
        active_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        quality_percent DECIMAL(6,2) NOT NULL DEFAULT 100.00,
        bonus_month_no INT UNSIGNED DEFAULT NULL,
        bonus_month_limit INT UNSIGNED DEFAULT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        status ENUM('payable','pending','excluded','info') NOT NULL DEFAULT 'payable',
        reason VARCHAR(255) DEFAULT NULL,
        metadata_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bonus_item_period_employee (period_id, employee_id),
        KEY idx_bonus_item_customer (customer_account_id, period_id),
        KEY idx_bonus_item_type_status (item_type, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS employee_bonus_adjustments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        period_id BIGINT UNSIGNED NOT NULL,
        employee_id BIGINT UNSIGNED NOT NULL,
        adjustment_type ENUM('bonus','penalty') NOT NULL DEFAULT 'bonus',
        amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        reason VARCHAR(255) NOT NULL,
        status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
        created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
        approved_by_account_id BIGINT UNSIGNED DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_bonus_adjustment_period_employee (period_id, employee_id),
        KEY idx_bonus_adjustment_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS employee_bonus_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT UNSIGNED NOT NULL,
        customer_account_id BIGINT UNSIGNED DEFAULT NULL,
        period_id BIGINT UNSIGNED DEFAULT NULL,
        notification_type VARCHAR(64) NOT NULL,
        status ENUM('new','seen','in_progress','resolved','ignored') NOT NULL DEFAULT 'new',
        message VARCHAR(255) NOT NULL,
        due_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_bonus_notification_employee_status (employee_id, status, due_at),
        KEY idx_bonus_notification_customer (customer_account_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS bonus_audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        action_type VARCHAR(96) NOT NULL,
        entity_type VARCHAR(96) NOT NULL,
        entity_id VARCHAR(64) DEFAULT NULL,
        actor_account_id BIGINT UNSIGNED DEFAULT NULL,
        actor_name VARCHAR(191) DEFAULT NULL,
        summary VARCHAR(255) NOT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        before_json LONGTEXT DEFAULT NULL,
        after_json LONGTEXT DEFAULT NULL,
        payload_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bonus_audit_entity (entity_type, entity_id),
        KEY idx_bonus_audit_action (action_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    app_bonus_seed_defaults($db);
}

function app_bonus_seed_defaults(PDO $db): void
{
    $settings = [
        ['base_salary_customer_method', 'rolling_3_month_average', 'string', 'Default: son 3 ay orta aktiv say; snapshot yoxdursa cari aktiv say fallback.'],
        ['new_connection_bonus_amount', '5', 'decimal', 'Yeni qosuntu ucun default bonus.'],
        ['new_connection_payout_policy', 'after_30_days', 'string', 'immediate veya after_30_days.'],
        ['recurring_monthly_bonus_amount', '1', 'decimal', '12 ayliq aktiv odenis ucun ayliq bonus.'],
        ['recurring_bonus_month_limit', '12', 'integer', 'Yeni musteri ucun maksimum bonus ayi.'],
        ['active_day_method', 'bracket', 'string', 'bracket veya exact.'],
        ['new_connection_finder_percent', '100', 'decimal', 'Yeni qosuntu finder bolgusu.'],
        ['new_connection_installer_percent', '0', 'decimal', 'Yeni qosuntu installer bolgusu.'],
        ['recurring_finder_percent', '100', 'decimal', '12 ayliq bonus finder bolgusu.'],
        ['recurring_installer_percent', '0', 'decimal', '12 ayliq bonus installer bolgusu.'],
        ['inactive_employee_bonus_policy', 'stop', 'string', 'stop, transfer_to_area_owner veya manual.'],
        ['area_quality_default_percent', '100', 'decimal', 'Erazi keyfiyyet fallback faizi.'],
    ];
    $statement = $db->prepare('INSERT IGNORE INTO bonus_rule_settings (setting_key, setting_value, value_type, note) VALUES (:setting_key, :setting_value, :value_type, :note)');
    foreach ($settings as $row) {
        $statement->execute(['setting_key' => $row[0], 'setting_value' => $row[1], 'value_type' => $row[2], 'note' => $row[3]]);
    }
    if ((int) $db->query('SELECT COUNT(*) FROM bonus_base_salary_tiers')->fetchColumn() === 0) {
        $tier = $db->prepare('INSERT INTO bonus_base_salary_tiers (min_customers, max_customers, salary_amount) VALUES (:min_customers, :max_customers, :salary_amount)');
        foreach ([[0, 300, 450], [301, 500, 500], [501, 700, 550], [701, 900, 600], [901, null, 650]] as $row) {
            $tier->execute(['min_customers' => $row[0], 'max_customers' => $row[1], 'salary_amount' => $row[2]]);
        }
    }
    if ((int) $db->query('SELECT COUNT(*) FROM bonus_active_day_brackets')->fetchColumn() === 0) {
        $bracket = $db->prepare('INSERT INTO bonus_active_day_brackets (min_days, max_days, active_percent) VALUES (:min_days, :max_days, :active_percent)');
        foreach ([[27, 31, 100], [21, 26, 80], [15, 20, 60], [8, 14, 40], [1, 7, 20], [0, 0, 0]] as $row) {
            $bracket->execute(['min_days' => $row[0], 'max_days' => $row[1], 'active_percent' => $row[2]]);
        }
    }
    if ((int) $db->query('SELECT COUNT(*) FROM bonus_quality_rules')->fetchColumn() === 0) {
        $quality = $db->prepare('INSERT INTO bonus_quality_rules (problem_key, problem_label, problem_source, severity, quality_percent) VALUES (:problem_key, :problem_label, :problem_source, :severity, :quality_percent)');
        foreach ([['none', 'Problemsiz musteri', 'none', 'normal', 100], ['network_problem', 'Sirket/sebeke sebebli problem', 'network', 'normal', 100], ['customer_problem', 'Musteri sebebli problem', 'customer', 'normal', 100], ['employee_small_problem', 'Emekdas sehvi ile kicik problem', 'employee', 'minor', 70], ['employee_repeat_problem', 'Emekdas sehvi ile tekrar problem', 'employee', 'major', 50], ['employee_fake_or_discount', 'Yanlis melumat / saxta qosuntu / qeyri-resmi endirim', 'employee', 'critical', 0], ['cancelled_in_30_days', '30 gun icinde legv', 'employee', 'critical', 0], ['unknown_problem', 'Namum problem', 'unknown', 'normal', 100]] as $row) {
            $quality->execute(['problem_key' => $row[0], 'problem_label' => $row[1], 'problem_source' => $row[2], 'severity' => $row[3], 'quality_percent' => $row[4]]);
        }
    }
}

function app_bonus_json(mixed $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : '{}';
}

function app_bonus_actor_can_view_all(array $actor): bool
{
    return app_portal_is_admin($actor) || strtolower((string) ($actor['employee_role'] ?? '')) === 'finance';
}

function app_bonus_actor_can_manage_rules(array $actor): bool
{
    return function_exists('app_portal_is_super_admin') && app_portal_is_super_admin($actor);
}

function app_bonus_actor_can_calculate(array $actor): bool
{
    return app_bonus_actor_can_view_all($actor);
}

function app_bonus_setting(PDO $db, string $key, string $default = ''): string
{
    $statement = $db->prepare('SELECT setting_value FROM bonus_rule_settings WHERE setting_key = :setting_key AND is_active = 1 LIMIT 1');
    $statement->execute(['setting_key' => $key]);
    $value = $statement->fetchColumn();
    return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
}

function app_bonus_decimal_setting(PDO $db, string $key, float $default): float
{
    return (float) app_bonus_setting($db, $key, (string) $default);
}

function app_bonus_int_setting(PDO $db, string $key, int $default): int
{
    return max(0, (int) app_bonus_setting($db, $key, (string) $default));
}

function app_bonus_record_audit(PDO $db, string $actionType, string $entityType, string $entityId, array $actor, string $summary, array $payload = [], string $reason = '', ?array $before = null, ?array $after = null): void
{
    $statement = $db->prepare('INSERT INTO bonus_audit_logs (action_type, entity_type, entity_id, actor_account_id, actor_name, summary, reason, before_json, after_json, payload_json)
        VALUES (:action_type, :entity_type, :entity_id, :actor_account_id, :actor_name, :summary, :reason, :before_json, :after_json, :payload_json)');
    $statement->execute([
        'action_type' => mb_substr($actionType, 0, 96),
        'entity_type' => mb_substr($entityType, 0, 96),
        'entity_id' => mb_substr($entityId, 0, 64),
        'actor_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'bonus')),
        'summary' => mb_substr(clean_text($summary), 0, 255),
        'reason' => clean_text($reason) ?: null,
        'before_json' => $before !== null ? app_bonus_json($before) : null,
        'after_json' => $after !== null ? app_bonus_json($after) : null,
        'payload_json' => app_bonus_json($payload),
    ]);
    if (function_exists('app_finance_record_audit')) {
        app_finance_record_audit($db, $actionType, $entityId, $actor, $summary, $payload, $reason, $before, $after, 'bonus');
    }
}

function app_bonus_month_period(?string $month = null): array
{
    $month = trim((string) $month);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = (new DateTimeImmutable('first day of last month'))->format('Y-m');
    }
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') ?: new DateTimeImmutable('first day of last month');
    $end = $start->modify('last day of this month');
    return ['month' => $start->format('Y-m'), 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
}

function app_bonus_date_value(mixed $value, string $fallback): string
{
    $date = clean_text((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $fallback;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date ? $date : $fallback;
}

function app_bonus_period_label(array $period): string
{
    return (string) ($period['period_start'] ?? '') . ' - ' . (string) ($period['period_end'] ?? '');
}

function app_bonus_employee_rows(PDO $db): array
{
    $rows = $db->query("SELECT id, full_name, login, account_type, employee_role, area, is_active FROM portal_accounts WHERE role = 'employee' AND is_active = 1 ORDER BY full_name, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_values(array_filter($rows, static function (array $row): bool {
        $haystack = strtolower(implode(' ', [(string) ($row['full_name'] ?? ''), (string) ($row['login'] ?? ''), (string) ($row['employee_role'] ?? '')]));
        foreach (['smoke', 'test ', 'telegram smoke', 'role agent', 'copilot texnik'] as $marker) {
            if (str_contains($haystack, $marker)) {
                return false;
            }
        }
        return true;
    }));
}

function app_bonus_employee_areas(PDO $db, int $employeeId, array $employee = []): array
{
    $areas = [];
    try {
        $statement = $db->prepare('SELECT area FROM portal_account_areas WHERE account_id = :account_id ORDER BY area');
        $statement->execute(['account_id' => $employeeId]);
        $areas = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable) {
        $areas = [];
    }
    $fallback = clean_text((string) ($employee['area'] ?? ''));
    if ($fallback !== '') {
        $areas[] = $fallback;
    }
    return array_values(array_unique(array_filter($areas, static fn (string $area): bool => $area !== '')));
}

function app_bonus_employee_customer_count(PDO $db, int $employeeId, array $areas, bool $active): int
{
    $ids = [];
    $statement = $db->prepare('SELECT customer_account_id FROM customer_employee_assignments WHERE status = "active" AND area_responsible_employee_id = :employee_id');
    $statement->execute(['employee_id' => $employeeId]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        $ids[(int) $id] = true;
    }
    if ($areas !== []) {
        $placeholders = [];
        $params = ['role' => 'customer', 'active_value' => $active ? 1 : 0];
        foreach ($areas as $index => $area) {
            $key = 'area' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $area;
        }
        $sql = 'SELECT id FROM portal_accounts WHERE role = :role AND is_active = :active_value AND area IN (' . implode(',', $placeholders) . ')';
        $areaStatement = $db->prepare($sql);
        $areaStatement->execute($params);
        foreach ($areaStatement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $ids[(int) $id] = true;
        }
    }
    return count($ids);
}

function app_bonus_base_salary_for_count(PDO $db, int $activeCustomers): array
{
    $statement = $db->prepare('SELECT salary_amount, area_quality_percent FROM bonus_base_salary_tiers WHERE is_active = 1 AND min_customers <= :count_value AND (max_customers IS NULL OR max_customers >= :count_value) ORDER BY min_customers DESC LIMIT 1');
    $statement->execute(['count_value' => $activeCustomers]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['salary' => (float) ($row['salary_amount'] ?? 0), 'quality_percent' => (float) ($row['area_quality_percent'] ?? app_bonus_decimal_setting($db, 'area_quality_default_percent', 100.0))];
}

function app_bonus_active_percent(PDO $db, int $activeDays, int $daysInPeriod): float
{
    if (app_bonus_setting($db, 'active_day_method', 'bracket') === 'exact') {
        return $daysInPeriod > 0 ? round(($activeDays / $daysInPeriod) * 100, 2) : 0.0;
    }
    $statement = $db->prepare('SELECT active_percent FROM bonus_active_day_brackets WHERE is_active = 1 AND min_days <= :days AND max_days >= :days ORDER BY min_days DESC LIMIT 1');
    $statement->execute(['days' => $activeDays]);
    $value = $statement->fetchColumn();
    return is_scalar($value) ? (float) $value : 0.0;
}

function app_bonus_first_payment(PDO $db, int $customerId, string $subscriberCode): ?string
{
    $statement = $db->prepare('SELECT MIN(created_at) FROM billing_payments WHERE account_id = :account_id OR subscriber_code = :subscriber_code');
    $statement->execute(['account_id' => $customerId, 'subscriber_code' => $subscriberCode]);
    $value = $statement->fetchColumn();
    return is_scalar($value) && (string) $value !== '' ? substr((string) $value, 0, 10) : null;
}

function app_bonus_customer_active_days(PDO $db, int $customerId, string $subscriberCode, string $periodStart, string $periodEnd): int
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
    if (!$start || !$end) {
        return 0;
    }
    $statement = $db->prepare('SELECT created_at FROM billing_payments WHERE (account_id = :account_id OR subscriber_code = :subscriber_code) AND created_at >= :lookback AND created_at < :end_exclusive ORDER BY created_at');
    $statement->execute(['account_id' => $customerId, 'subscriber_code' => $subscriberCode, 'lookback' => $start->modify('-30 days')->format('Y-m-d') . ' 00:00:00', 'end_exclusive' => $end->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
    $active = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $createdAt) {
        $paymentDay = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $createdAt, 0, 10));
        if (!$paymentDay) {
            continue;
        }
        for ($i = 0; $i < 30; $i++) {
            $day = $paymentDay->modify('+' . $i . ' days');
            if ($day >= $start && $day <= $end) {
                $active[$day->format('Y-m-d')] = true;
            }
        }
    }
    return count($active);
}

function app_bonus_period_days(string $periodStart, string $periodEnd): int
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
    return $start && $end && $end >= $start ? ((int) $start->diff($end)->format('%a')) + 1 : 0;
}

function app_bonus_quality_for_customer(PDO $db, int $customerId, string $subscriberCode, string $periodStart, string $periodEnd): array
{
    $statement = $db->prepare('SELECT subject, message, resolution_reason FROM portal_tickets WHERE (customer_account_id = :customer_id OR subscriber_code = :subscriber_code) AND COALESCE(opened_at, created_at) >= :start_at AND COALESCE(opened_at, created_at) < :end_at ORDER BY COALESCE(opened_at, created_at) DESC');
    $statement->execute(['customer_id' => $customerId, 'subscriber_code' => $subscriberCode, 'start_at' => $periodStart . ' 00:00:00', 'end_at' => date('Y-m-d 00:00:00', strtotime($periodEnd . ' +1 day'))]);
    $tickets = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($tickets === []) {
        return ['quality_percent' => 100.0, 'problem_key' => 'none', 'problem_count' => 0];
    }
    $selectedKey = 'unknown_problem';
    foreach ($tickets as $ticket) {
        $text = mb_strtolower(implode(' ', [(string) ($ticket['subject'] ?? ''), (string) ($ticket['message'] ?? ''), (string) ($ticket['resolution_reason'] ?? '')]), 'UTF-8');
        if (preg_match('/saxta|yanlis|endirim|qeyri-resmi|fake|discount/u', $text)) {
            $selectedKey = 'employee_fake_or_discount';
            break;
        }
        if (preg_match('/qosuntu|connector|kabel|qaynaq|modem|ont|gecik/u', $text)) {
            $selectedKey = 'employee_small_problem';
        } elseif (preg_match('/olt|uplink|core|router|elektrik|sebeke|network/u', $text) && $selectedKey !== 'employee_small_problem') {
            $selectedKey = 'network_problem';
        } elseif (preg_match('/musteri|odenis|evde yox|imtina/u', $text) && $selectedKey === 'unknown_problem') {
            $selectedKey = 'customer_problem';
        }
    }
    $rule = $db->prepare('SELECT quality_percent FROM bonus_quality_rules WHERE problem_key = :problem_key AND is_active = 1 LIMIT 1');
    $rule->execute(['problem_key' => $selectedKey]);
    $percent = $rule->fetchColumn();
    return ['quality_percent' => is_scalar($percent) ? (float) $percent : 100.0, 'problem_key' => $selectedKey, 'problem_count' => count($tickets)];
}

function app_bonus_employee_share(PDO $db, array $assignment, int $employeeId, string $kind): float
{
    $owner = (int) ($assignment['bonus_owner_employee_id'] ?? 0);
    if ($owner > 0) {
        return $owner === $employeeId ? 100.0 : 0.0;
    }
    $finderPercent = app_bonus_decimal_setting($db, $kind === 'new_connection' ? 'new_connection_finder_percent' : 'recurring_finder_percent', 100.0);
    $installerPercent = app_bonus_decimal_setting($db, $kind === 'new_connection' ? 'new_connection_installer_percent' : 'recurring_installer_percent', 0.0);
    $share = 0.0;
    if ((int) ($assignment['finder_employee_id'] ?? 0) === $employeeId) {
        $share += $finderPercent;
    }
    if ((int) ($assignment['installer_employee_id'] ?? 0) === $employeeId) {
        $share += $installerPercent;
    }
    return max(0.0, min(100.0, $share));
}

function app_bonus_assignment_customers_for_employee(PDO $db, int $employeeId): array
{
    $statement = $db->prepare('SELECT a.*, c.full_name AS customer_name, c.subscriber_code, c.area, c.plan, c.created_at AS customer_created_at, c.is_active FROM customer_employee_assignments a INNER JOIN portal_accounts c ON c.id = a.customer_account_id WHERE a.status = "active" AND (a.finder_employee_id = :employee_id OR a.installer_employee_id = :employee_id OR a.area_responsible_employee_id = :employee_id OR a.bonus_owner_employee_id = :employee_id) ORDER BY c.subscriber_code, a.id DESC');
    $statement->execute(['employee_id' => $employeeId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_bonus_manual_adjustment_total(PDO $db, int $periodId, int $employeeId): float
{
    $statement = $db->prepare('SELECT COALESCE(SUM(CASE WHEN adjustment_type = "penalty" THEN -amount ELSE amount END),0) FROM employee_bonus_adjustments WHERE period_id = :period_id AND employee_id = :employee_id AND status = "approved"');
    $statement->execute(['period_id' => $periodId, 'employee_id' => $employeeId]);
    return (float) $statement->fetchColumn();
}

function app_bonus_count_ownerless_customers(PDO $db, string $periodStart, string $periodEnd): int
{
    $statement = $db->prepare('SELECT COUNT(DISTINCT p.account_id) FROM billing_payments p LEFT JOIN customer_employee_assignments a ON a.customer_account_id = p.account_id AND a.status = "active" WHERE p.created_at >= :start_at AND p.created_at < :end_at AND a.id IS NULL');
    $statement->execute(['start_at' => $periodStart . ' 00:00:00', 'end_at' => date('Y-m-d 00:00:00', strtotime($periodEnd . ' +1 day'))]);
    return (int) $statement->fetchColumn();
}

function app_bonus_period_by_id(PDO $db, int $periodId): ?array
{
    $statement = $db->prepare('SELECT * FROM bonus_periods WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $periodId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function app_bonus_latest_period(PDO $db): ?array
{
    $row = $db->query('SELECT * FROM bonus_periods ORDER BY period_start DESC, id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function app_bonus_find_or_create_period(PDO $db, string $periodStart, string $periodEnd, array $actor): int
{
    $statement = $db->prepare('SELECT id FROM bonus_periods WHERE period_start = :period_start AND period_end = :period_end LIMIT 1');
    $statement->execute(['period_start' => $periodStart, 'period_end' => $periodEnd]);
    $existingId = (int) ($statement->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return $existingId;
    }
    $insert = $db->prepare('INSERT INTO bonus_periods (period_start, period_end, status, created_by_account_id) VALUES (:period_start, :period_end, "draft", :actor_id)');
    $insert->execute(['period_start' => $periodStart, 'period_end' => $periodEnd, 'actor_id' => (int) ($actor['id'] ?? 0) ?: null]);
    return (int) $db->lastInsertId();
}

function app_bonus_calculate_period(PDO $db, string $periodStart, string $periodEnd, array $actor): array
{
    if (!app_bonus_actor_can_calculate($actor)) {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    app_bonus_ensure_schema($db);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
    if (!$start || !$end || $end < $start) {
        return ['ok' => false, 'error' => 'invalid_period'];
    }
    try {
        $db->beginTransaction();
        $periodId = app_bonus_find_or_create_period($db, $periodStart, $periodEnd, $actor);
        $period = app_bonus_period_by_id($db, $periodId) ?? [];
        if (($period['status'] ?? '') === 'paid' && !app_bonus_actor_can_manage_rules($actor)) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'period_paid_locked'];
        }
        $db->prepare('DELETE FROM employee_bonus_items WHERE period_id = :period_id')->execute(['period_id' => $periodId]);
        $db->prepare('DELETE FROM employee_bonus_summaries WHERE period_id = :period_id')->execute(['period_id' => $periodId]);
        $daysInPeriod = app_bonus_period_days($periodStart, $periodEnd);
        $newAmount = app_bonus_decimal_setting($db, 'new_connection_bonus_amount', 5.0);
        $recurringAmount = app_bonus_decimal_setting($db, 'recurring_monthly_bonus_amount', 1.0);
        $monthLimit = max(1, app_bonus_int_setting($db, 'recurring_bonus_month_limit', 12));
        $holdPolicy = app_bonus_setting($db, 'new_connection_payout_policy', 'after_30_days');
        $itemInsert = $db->prepare('INSERT INTO employee_bonus_items (period_id, employee_id, customer_account_id, subscriber_code, customer_name, item_type, base_amount, active_days, active_percent, quality_percent, bonus_month_no, bonus_month_limit, amount, status, reason, metadata_json) VALUES (:period_id, :employee_id, :customer_account_id, :subscriber_code, :customer_name, :item_type, :base_amount, :active_days, :active_percent, :quality_percent, :bonus_month_no, :bonus_month_limit, :amount, :status, :reason, :metadata_json)');
        $summaryInsert = $db->prepare('INSERT INTO employee_bonus_summaries (period_id, employee_id, employee_name, employee_role, assigned_area_text, active_customer_count, inactive_customer_count, problem_customer_count, late_payment_customer_count, bonus_eligible_customer_count, base_salary, area_quality_percent, new_connection_bonus, recurring_bonus, quality_deduction, manual_adjustment, total_payable, status) VALUES (:period_id, :employee_id, :employee_name, :employee_role, :assigned_area_text, :active_customer_count, :inactive_customer_count, :problem_customer_count, :late_payment_customer_count, :bonus_eligible_customer_count, :base_salary, :area_quality_percent, :new_connection_bonus, :recurring_bonus, :quality_deduction, :manual_adjustment, :total_payable, "calculated")');
        $totals = ['employee_count' => 0, 'total_base_salary' => 0.0, 'total_new_connection_bonus' => 0.0, 'total_recurring_bonus' => 0.0, 'total_quality_deduction' => 0.0, 'total_manual_adjustment' => 0.0, 'total_payable' => 0.0];
        foreach (app_bonus_employee_rows($db) as $employee) {
            $employeeId = (int) ($employee['id'] ?? 0);
            if ($employeeId <= 0) {
                continue;
            }
            $areas = app_bonus_employee_areas($db, $employeeId, $employee);
            $activeCount = app_bonus_employee_customer_count($db, $employeeId, $areas, true);
            $inactiveCount = app_bonus_employee_customer_count($db, $employeeId, $areas, false);
            $salaryInfo = app_bonus_base_salary_for_count($db, $activeCount);
            $baseSalary = round(((float) $salaryInfo['salary']) * ((float) $salaryInfo['quality_percent'] / 100), 2);
            $itemInsert->execute(['period_id' => $periodId, 'employee_id' => $employeeId, 'customer_account_id' => null, 'subscriber_code' => null, 'customer_name' => null, 'item_type' => 'base_salary', 'base_amount' => (float) $salaryInfo['salary'], 'active_days' => 0, 'active_percent' => 100, 'quality_percent' => (float) $salaryInfo['quality_percent'], 'bonus_month_no' => null, 'bonus_month_limit' => null, 'amount' => $baseSalary, 'status' => 'payable', 'reason' => 'Active customer tier: ' . $activeCount, 'metadata_json' => app_bonus_json(['areas' => $areas, 'active_customers' => $activeCount])]);
            $newBonus = 0.0;
            $recurringBonus = 0.0;
            $qualityDeduction = 0.0;
            $problemCustomers = [];
            $lateCustomers = 0;
            $eligibleCustomers = 0;
            foreach (app_bonus_assignment_customers_for_employee($db, $employeeId) as $assignment) {
                $customerId = (int) ($assignment['customer_account_id'] ?? 0);
                $subscriberCode = (string) ($assignment['subscriber_code'] ?? '');
                if ($customerId <= 0 || $subscriberCode === '') {
                    continue;
                }
                $firstPayment = app_bonus_first_payment($db, $customerId, $subscriberCode);
                if ($firstPayment === null) {
                    continue;
                }
                $quality = app_bonus_quality_for_customer($db, $customerId, $subscriberCode, $periodStart, $periodEnd);
                if ((int) ($quality['problem_count'] ?? 0) > 0) {
                    $problemCustomers[$customerId] = true;
                }
                $firstPaymentDate = DateTimeImmutable::createFromFormat('!Y-m-d', $firstPayment);
                $periodStartDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
                $periodEndDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
                if (!$firstPaymentDate || !$periodStartDate || !$periodEndDate) {
                    continue;
                }
                $newShare = app_bonus_employee_share($db, $assignment, $employeeId, 'new_connection') / 100;
                if ($newShare > 0 && $firstPaymentDate >= $periodStartDate && $firstPaymentDate <= $periodEndDate) {
                    $createdDate = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) ($assignment['customer_created_at'] ?? $firstPayment), 0, 10)) ?: $firstPaymentDate;
                    $pending = $holdPolicy === 'after_30_days' && (int) $createdDate->diff($periodEndDate)->format('%a') < 30;
                    $amount = $pending ? 0.0 : round($newAmount * $newShare, 2);
                    $newBonus += $amount;
                    $itemInsert->execute(['period_id' => $periodId, 'employee_id' => $employeeId, 'customer_account_id' => $customerId, 'subscriber_code' => $subscriberCode, 'customer_name' => (string) ($assignment['customer_name'] ?? ''), 'item_type' => 'new_connection', 'base_amount' => $newAmount, 'active_days' => 0, 'active_percent' => 100, 'quality_percent' => (float) ($quality['quality_percent'] ?? 100), 'bonus_month_no' => 1, 'bonus_month_limit' => $monthLimit, 'amount' => $amount, 'status' => $pending ? 'pending' : 'payable', 'reason' => $pending ? '30 gun yoxlama tamamlanmayib' : 'Ilk odenis perioddadir', 'metadata_json' => app_bonus_json(['share_percent' => $newShare * 100, 'first_payment' => $firstPayment])]);
                }
                $recurringShare = app_bonus_employee_share($db, $assignment, $employeeId, 'recurring') / 100;
                if ($recurringShare <= 0) {
                    continue;
                }
                $monthsSinceFirst = (((int) $periodStartDate->format('Y')) - ((int) $firstPaymentDate->format('Y'))) * 12 + ((int) $periodStartDate->format('m')) - ((int) $firstPaymentDate->format('m')) + 1;
                if ($monthsSinceFirst < 1 || $monthsSinceFirst > $monthLimit) {
                    continue;
                }
                $activeDays = app_bonus_customer_active_days($db, $customerId, $subscriberCode, $periodStart, $periodEnd);
                if ($activeDays <= 0) {
                    $lateCustomers++;
                    continue;
                }
                $activePercent = app_bonus_active_percent($db, $activeDays, $daysInPeriod);
                $qualityPercent = (float) ($quality['quality_percent'] ?? 100.0);
                $potential = round($recurringAmount * ($activePercent / 100) * $recurringShare, 2);
                $amount = round($potential * ($qualityPercent / 100), 2);
                $qualityDeduction += max(0.0, $potential - $amount);
                $recurringBonus += $amount;
                $eligibleCustomers++;
                $itemInsert->execute(['period_id' => $periodId, 'employee_id' => $employeeId, 'customer_account_id' => $customerId, 'subscriber_code' => $subscriberCode, 'customer_name' => (string) ($assignment['customer_name'] ?? ''), 'item_type' => 'recurring_active_payment', 'base_amount' => $recurringAmount, 'active_days' => $activeDays, 'active_percent' => $activePercent, 'quality_percent' => $qualityPercent, 'bonus_month_no' => $monthsSinceFirst, 'bonus_month_limit' => $monthLimit, 'amount' => $amount, 'status' => $amount > 0 ? 'payable' : 'excluded', 'reason' => (string) ($quality['problem_key'] ?? 'none'), 'metadata_json' => app_bonus_json(['share_percent' => $recurringShare * 100, 'first_payment' => $firstPayment])]);
            }
            $manualAdjustment = app_bonus_manual_adjustment_total($db, $periodId, $employeeId);
            $totalPayable = round($baseSalary + $newBonus + $recurringBonus + $manualAdjustment, 2);
            $summaryInsert->execute(['period_id' => $periodId, 'employee_id' => $employeeId, 'employee_name' => (string) ($employee['full_name'] ?? ''), 'employee_role' => (string) ($employee['employee_role'] ?? $employee['account_type'] ?? ''), 'assigned_area_text' => implode(', ', $areas), 'active_customer_count' => $activeCount, 'inactive_customer_count' => $inactiveCount, 'problem_customer_count' => count($problemCustomers), 'late_payment_customer_count' => $lateCustomers, 'bonus_eligible_customer_count' => $eligibleCustomers, 'base_salary' => $baseSalary, 'area_quality_percent' => (float) $salaryInfo['quality_percent'], 'new_connection_bonus' => $newBonus, 'recurring_bonus' => $recurringBonus, 'quality_deduction' => round($qualityDeduction, 2), 'manual_adjustment' => $manualAdjustment, 'total_payable' => $totalPayable]);
            $totals['employee_count']++;
            $totals['total_base_salary'] += $baseSalary;
            $totals['total_new_connection_bonus'] += $newBonus;
            $totals['total_recurring_bonus'] += $recurringBonus;
            $totals['total_quality_deduction'] += $qualityDeduction;
            $totals['total_manual_adjustment'] += $manualAdjustment;
            $totals['total_payable'] += $totalPayable;
        }
        $ownerless = app_bonus_count_ownerless_customers($db, $periodStart, $periodEnd);
        $status = ($period['status'] ?? '') === 'paid' ? 'recalculated' : 'calculated';
        $update = $db->prepare('UPDATE bonus_periods SET calculation_date = NOW(), status = :status, employee_count = :employee_count, total_base_salary = :total_base_salary, total_new_connection_bonus = :total_new_connection_bonus, total_recurring_bonus = :total_recurring_bonus, total_quality_deduction = :total_quality_deduction, total_manual_adjustment = :total_manual_adjustment, total_payable = :total_payable, incomplete_customer_count = :incomplete_customer_count, ownerless_customer_count = :ownerless_customer_count, metadata_json = :metadata_json WHERE id = :id');
        $update->execute(['status' => $status, 'employee_count' => $totals['employee_count'], 'total_base_salary' => round($totals['total_base_salary'], 2), 'total_new_connection_bonus' => round($totals['total_new_connection_bonus'], 2), 'total_recurring_bonus' => round($totals['total_recurring_bonus'], 2), 'total_quality_deduction' => round($totals['total_quality_deduction'], 2), 'total_manual_adjustment' => round($totals['total_manual_adjustment'], 2), 'total_payable' => round($totals['total_payable'], 2), 'incomplete_customer_count' => $ownerless, 'ownerless_customer_count' => $ownerless, 'metadata_json' => app_bonus_json(['active_day_method' => app_bonus_setting($db, 'active_day_method', 'bracket')]), 'id' => $periodId]);
        $notificationCount = app_bonus_generate_period_notifications($db, $periodId, $periodStart, $periodEnd);
        app_bonus_record_audit($db, 'bonus_period_calculated', 'bonus_period', (string) $periodId, $actor, 'Employee bonus period calculated', ['period_start' => $periodStart, 'period_end' => $periodEnd, 'total_payable' => round($totals['total_payable'], 2), 'notifications' => $notificationCount]);
        $db->commit();
        return ['ok' => true, 'period_id' => $periodId, 'status' => $status, 'notifications' => $notificationCount];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'bonus_calculation_failed', 'detail' => $exception->getMessage()];
    }
}

function app_bonus_update_period_status(PDO $db, int $periodId, string $nextStatus, array $actor, string $reason = ''): array
{
    app_bonus_ensure_schema($db);
    if (!in_array($nextStatus, ['pending_approval', 'approved', 'paid', 'cancelled'], true)) {
        return ['ok' => false, 'error' => 'invalid_status'];
    }
    $period = app_bonus_period_by_id($db, $periodId);
    if ($period === null) {
        return ['ok' => false, 'error' => 'period_not_found'];
    }
    $current = (string) ($period['status'] ?? 'draft');
    if ($nextStatus === 'approved' && !app_bonus_actor_can_manage_rules($actor)) {
        return ['ok' => false, 'error' => 'super_admin_required'];
    }
    if ($nextStatus === 'paid' && !app_bonus_actor_can_calculate($actor)) {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    $validTransition = match ($nextStatus) {
        'pending_approval' => in_array($current, ['calculated', 'recalculated'], true),
        'approved' => $current === 'pending_approval',
        'paid' => $current === 'approved',
        'cancelled' => $current !== 'paid',
        default => false,
    };
    if (!$validTransition) {
        return ['ok' => false, 'error' => 'invalid_transition'];
    }
    try {
        $db->beginTransaction();
        $financeEntryId = (int) ($period['finance_entry_id'] ?? 0);
        if ($nextStatus === 'paid') {
            $reference = 'BONUS-' . $periodId;
            $check = $db->prepare('SELECT id FROM finance_entries WHERE reference_no = :reference_no LIMIT 1');
            $check->execute(['reference_no' => $reference]);
            $financeEntryId = (int) ($check->fetchColumn() ?: 0);
            if ($financeEntryId <= 0) {
                $insertEntry = $db->prepare('INSERT INTO finance_entries (entry_type,title,category,amount,currency,transaction_date,payment_source,reference_no,note,status,created_by_account_id,created_by_name)
                    VALUES ("expense", :title, "employee_bonus", :amount, "AZN", :transaction_date, "bonus", :reference_no, :note, "posted", :actor_id, :actor_name)');
                $insertEntry->execute([
                    'title' => 'Emekdas bonuslari ' . app_bonus_period_label($period),
                    'amount' => (float) ($period['total_payable'] ?? 0),
                    'transaction_date' => date('Y-m-d'),
                    'reference_no' => $reference,
                    'note' => 'Approved employee bonus payout',
                    'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
                    'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? 'bonus'),
                ]);
                $financeEntryId = (int) $db->lastInsertId();
            }
        }
        $sql = 'UPDATE bonus_periods SET status = :status, finance_entry_id = :finance_entry_id';
        $params = ['status' => $nextStatus, 'finance_entry_id' => $financeEntryId ?: null, 'id' => $periodId];
        if ($nextStatus === 'approved') {
            $sql .= ', approved_by_account_id = :actor_id, approved_at = NOW()';
            $params['actor_id'] = (int) ($actor['id'] ?? 0) ?: null;
        } elseif ($nextStatus === 'paid') {
            $sql .= ', paid_by_account_id = :actor_id, paid_at = NOW()';
            $params['actor_id'] = (int) ($actor['id'] ?? 0) ?: null;
        }
        $db->prepare($sql . ' WHERE id = :id')->execute($params);
        if (in_array($nextStatus, ['approved', 'paid'], true)) {
            $db->prepare('UPDATE employee_bonus_summaries SET status = :status WHERE period_id = :period_id')->execute(['status' => $nextStatus, 'period_id' => $periodId]);
        }
        app_bonus_record_audit($db, 'bonus_period_status_changed', 'bonus_period', (string) $periodId, $actor, 'Bonus period status changed: ' . $current . ' -> ' . $nextStatus, ['from' => $current, 'to' => $nextStatus, 'finance_entry_id' => $financeEntryId], $reason, $period, app_bonus_period_by_id($db, $periodId));
        $db->commit();
        return ['ok' => true, 'period_id' => $periodId, 'status' => $nextStatus];
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'status_update_failed'];
    }
}

function app_bonus_save_setting(PDO $db, array $input, array $actor): array
{
    if (!app_bonus_actor_can_manage_rules($actor)) {
        return ['ok' => false, 'error' => 'super_admin_required'];
    }
    app_bonus_ensure_schema($db);
    $key = clean_text((string) ($input['setting_key'] ?? ''));
    $value = clean_text((string) ($input['setting_value'] ?? ''));
    if ($key === '' || !preg_match('/^[a-z0-9_]+$/', $key)) {
        return ['ok' => false, 'error' => 'invalid_setting'];
    }
    $beforeStatement = $db->prepare('SELECT * FROM bonus_rule_settings WHERE setting_key = :setting_key LIMIT 1');
    $beforeStatement->execute(['setting_key' => $key]);
    $before = $beforeStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    $statement = $db->prepare('INSERT INTO bonus_rule_settings (setting_key, setting_value, value_type, updated_by_account_id) VALUES (:setting_key, :setting_value, :value_type, :actor_id) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_by_account_id = VALUES(updated_by_account_id), is_active = 1');
    $statement->execute(['setting_key' => $key, 'setting_value' => $value, 'value_type' => clean_text((string) ($input['value_type'] ?? 'string')) ?: 'string', 'actor_id' => (int) ($actor['id'] ?? 0) ?: null]);
    $afterStatement = $db->prepare('SELECT * FROM bonus_rule_settings WHERE setting_key = :setting_key LIMIT 1');
    $afterStatement->execute(['setting_key' => $key]);
    app_bonus_record_audit($db, 'bonus_setting_saved', 'bonus_rule_setting', $key, $actor, 'Bonus setting saved: ' . $key, ['value' => $value], (string) ($input['reason'] ?? ''), $before, $afterStatement->fetch(PDO::FETCH_ASSOC) ?: null);
    return ['ok' => true];
}

function app_bonus_save_salary_tier(PDO $db, array $input, array $actor): array
{
    if (!app_bonus_actor_can_manage_rules($actor)) {
        return ['ok' => false, 'error' => 'super_admin_required'];
    }
    app_bonus_ensure_schema($db);
    $id = max(0, (int) ($input['tier_id'] ?? 0));
    $min = max(0, (int) ($input['min_customers'] ?? 0));
    $maxRaw = trim((string) ($input['max_customers'] ?? ''));
    $max = $maxRaw === '' ? null : max(0, (int) $maxRaw);
    $salary = round((float) ($input['salary_amount'] ?? 0), 2);
    $quality = max(0.0, min(100.0, round((float) ($input['area_quality_percent'] ?? 100), 2)));
    if ($salary < 0 || ($max !== null && $max < $min)) {
        return ['ok' => false, 'error' => 'invalid_tier'];
    }
    $before = null;
    if ($id > 0) {
        $beforeStatement = $db->prepare('SELECT * FROM bonus_base_salary_tiers WHERE id = :id LIMIT 1');
        $beforeStatement->execute(['id' => $id]);
        $before = $beforeStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($id > 0 && $before !== null) {
        $statement = $db->prepare('UPDATE bonus_base_salary_tiers SET min_customers = :min_customers, max_customers = :max_customers, salary_amount = :salary_amount, area_quality_percent = :area_quality_percent, is_active = :is_active WHERE id = :id');
        $statement->execute(['min_customers' => $min, 'max_customers' => $max, 'salary_amount' => $salary, 'area_quality_percent' => $quality, 'is_active' => empty($input['is_active']) ? 0 : 1, 'id' => $id]);
    } else {
        $statement = $db->prepare('INSERT INTO bonus_base_salary_tiers (min_customers, max_customers, salary_amount, area_quality_percent, is_active) VALUES (:min_customers, :max_customers, :salary_amount, :area_quality_percent, 1)');
        $statement->execute(['min_customers' => $min, 'max_customers' => $max, 'salary_amount' => $salary, 'area_quality_percent' => $quality]);
        $id = (int) $db->lastInsertId();
    }
    $afterStatement = $db->prepare('SELECT * FROM bonus_base_salary_tiers WHERE id = :id LIMIT 1');
    $afterStatement->execute(['id' => $id]);
    app_bonus_record_audit($db, 'bonus_salary_tier_saved', 'bonus_base_salary_tier', (string) $id, $actor, 'Bonus salary tier saved', ['min_customers' => $min, 'max_customers' => $max, 'salary_amount' => $salary], (string) ($input['reason'] ?? ''), $before, $afterStatement->fetch(PDO::FETCH_ASSOC) ?: null);
    return ['ok' => true, 'id' => $id];
}

function app_bonus_save_active_day_bracket(PDO $db, array $input, array $actor): array
{
    if (!app_bonus_actor_can_manage_rules($actor)) {
        return ['ok' => false, 'error' => 'super_admin_required'];
    }
    app_bonus_ensure_schema($db);
    $id = max(0, (int) ($input['bracket_id'] ?? 0));
    $min = max(0, (int) ($input['min_days'] ?? 0));
    $max = max(0, (int) ($input['max_days'] ?? 0));
    $percent = max(0.0, min(100.0, round((float) ($input['active_percent'] ?? 0), 2)));
    if ($max < $min) {
        return ['ok' => false, 'error' => 'invalid_bracket'];
    }
    $before = null;
    if ($id > 0) {
        $beforeStatement = $db->prepare('SELECT * FROM bonus_active_day_brackets WHERE id = :id LIMIT 1');
        $beforeStatement->execute(['id' => $id]);
        $before = $beforeStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($id > 0 && $before !== null) {
        $statement = $db->prepare('UPDATE bonus_active_day_brackets SET min_days = :min_days, max_days = :max_days, active_percent = :active_percent, is_active = :is_active WHERE id = :id');
        $statement->execute(['min_days' => $min, 'max_days' => $max, 'active_percent' => $percent, 'is_active' => empty($input['is_active']) ? 0 : 1, 'id' => $id]);
    } else {
        $statement = $db->prepare('INSERT INTO bonus_active_day_brackets (min_days, max_days, active_percent, is_active) VALUES (:min_days, :max_days, :active_percent, 1)');
        $statement->execute(['min_days' => $min, 'max_days' => $max, 'active_percent' => $percent]);
        $id = (int) $db->lastInsertId();
    }
    $afterStatement = $db->prepare('SELECT * FROM bonus_active_day_brackets WHERE id = :id LIMIT 1');
    $afterStatement->execute(['id' => $id]);
    app_bonus_record_audit($db, 'bonus_active_day_bracket_saved', 'bonus_active_day_bracket', (string) $id, $actor, 'Bonus active day bracket saved', ['min_days' => $min, 'max_days' => $max, 'active_percent' => $percent], (string) ($input['reason'] ?? ''), $before, $afterStatement->fetch(PDO::FETCH_ASSOC) ?: null);
    return ['ok' => true, 'id' => $id];
}

function app_bonus_save_quality_rule(PDO $db, array $input, array $actor): array
{
    if (!app_bonus_actor_can_manage_rules($actor)) {
        return ['ok' => false, 'error' => 'super_admin_required'];
    }
    app_bonus_ensure_schema($db);
    $id = max(0, (int) ($input['quality_rule_id'] ?? 0));
    $key = clean_text((string) ($input['problem_key'] ?? ''));
    $label = clean_text((string) ($input['problem_label'] ?? ''));
    $source = clean_text((string) ($input['problem_source'] ?? 'unknown'));
    $severity = clean_text((string) ($input['severity'] ?? 'normal')) ?: 'normal';
    $percent = max(0.0, min(100.0, round((float) ($input['quality_percent'] ?? 100), 2)));
    if ($key === '' || $label === '' || !preg_match('/^[a-z0-9_]+$/', $key) || !in_array($source, ['employee', 'network', 'customer', 'unknown', 'none'], true)) {
        return ['ok' => false, 'error' => 'invalid_quality_rule'];
    }
    $before = null;
    if ($id > 0) {
        $beforeStatement = $db->prepare('SELECT * FROM bonus_quality_rules WHERE id = :id LIMIT 1');
        $beforeStatement->execute(['id' => $id]);
        $before = $beforeStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($id > 0 && $before !== null) {
        $statement = $db->prepare('UPDATE bonus_quality_rules SET problem_key = :problem_key, problem_label = :problem_label, problem_source = :problem_source, severity = :severity, quality_percent = :quality_percent, is_active = :is_active WHERE id = :id');
        $statement->execute(['problem_key' => $key, 'problem_label' => $label, 'problem_source' => $source, 'severity' => $severity, 'quality_percent' => $percent, 'is_active' => empty($input['is_active']) ? 0 : 1, 'id' => $id]);
    } else {
        $statement = $db->prepare('INSERT INTO bonus_quality_rules (problem_key, problem_label, problem_source, severity, quality_percent, is_active) VALUES (:problem_key, :problem_label, :problem_source, :severity, :quality_percent, 1) ON DUPLICATE KEY UPDATE problem_label = VALUES(problem_label), problem_source = VALUES(problem_source), severity = VALUES(severity), quality_percent = VALUES(quality_percent), is_active = 1');
        $statement->execute(['problem_key' => $key, 'problem_label' => $label, 'problem_source' => $source, 'severity' => $severity, 'quality_percent' => $percent]);
        $lookup = $db->prepare('SELECT id FROM bonus_quality_rules WHERE problem_key = :problem_key LIMIT 1');
        $lookup->execute(['problem_key' => $key]);
        $id = (int) $lookup->fetchColumn();
    }
    $afterStatement = $db->prepare('SELECT * FROM bonus_quality_rules WHERE id = :id LIMIT 1');
    $afterStatement->execute(['id' => $id]);
    app_bonus_record_audit($db, 'bonus_quality_rule_saved', 'bonus_quality_rule', (string) $id, $actor, 'Bonus quality rule saved: ' . $key, ['problem_key' => $key, 'quality_percent' => $percent], (string) ($input['reason'] ?? ''), $before, $afterStatement->fetch(PDO::FETCH_ASSOC) ?: null);
    return ['ok' => true, 'id' => $id];
}

function app_bonus_save_assignment(PDO $db, array $input, array $actor): array
{
    if (!app_bonus_actor_can_calculate($actor)) {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    app_bonus_ensure_schema($db);
    $subscriberCode = clean_text((string) ($input['subscriber_code'] ?? ''));
    $customerId = max(0, (int) ($input['customer_account_id'] ?? 0));
    $customer = $customerId > 0 ? app_db_account_by_id($customerId) : ($subscriberCode !== '' ? app_db_account_by_subscriber_code($subscriberCode) : null);
    if (!is_array($customer) || ($customer['role'] ?? '') !== 'customer') {
        return ['ok' => false, 'error' => 'customer_not_found'];
    }
    $finder = max(0, (int) ($input['finder_employee_id'] ?? 0)) ?: null;
    $installer = max(0, (int) ($input['installer_employee_id'] ?? 0)) ?: null;
    $areaOwner = max(0, (int) ($input['area_responsible_employee_id'] ?? 0)) ?: null;
    $bonusOwner = max(0, (int) ($input['bonus_owner_employee_id'] ?? 0)) ?: $finder;
    if ($finder === null && $installer === null && $areaOwner === null && $bonusOwner === null) {
        return ['ok' => false, 'error' => 'employee_required'];
    }
    try {
        $db->beginTransaction();
        $db->prepare('UPDATE customer_employee_assignments SET status = "transferred", effective_to = COALESCE(effective_to, CURDATE()) WHERE customer_account_id = :customer_id AND status = "active"')->execute(['customer_id' => (int) $customer['id']]);
        $statement = $db->prepare('INSERT INTO customer_employee_assignments (customer_account_id, finder_employee_id, installer_employee_id, area_responsible_employee_id, assigned_area_id, bonus_owner_employee_id, split_mode, assignment_source, effective_from, note, created_by_account_id) VALUES (:customer_account_id, :finder_employee_id, :installer_employee_id, :area_responsible_employee_id, :assigned_area_id, :bonus_owner_employee_id, :split_mode, "manual", :effective_from, :note, :actor_id)');
        $statement->execute(['customer_account_id' => (int) $customer['id'], 'finder_employee_id' => $finder, 'installer_employee_id' => $installer, 'area_responsible_employee_id' => $areaOwner, 'assigned_area_id' => clean_text((string) ($input['assigned_area_id'] ?? ($customer['area'] ?? ''))) ?: null, 'bonus_owner_employee_id' => $bonusOwner, 'split_mode' => clean_text((string) ($input['split_mode'] ?? 'finder_100')) ?: 'finder_100', 'effective_from' => app_bonus_date_value($input['effective_from'] ?? date('Y-m-d'), date('Y-m-d')), 'note' => clean_text((string) ($input['note'] ?? '')) ?: null, 'actor_id' => (int) ($actor['id'] ?? 0) ?: null]);
        $assignmentId = (int) $db->lastInsertId();
        app_bonus_record_audit($db, 'bonus_customer_assignment_saved', 'customer_employee_assignment', (string) $assignmentId, $actor, 'Customer bonus assignment saved: ' . (string) ($customer['subscriber_code'] ?? $customer['login'] ?? $customer['id']), ['customer_id' => (int) $customer['id'], 'finder_employee_id' => $finder, 'installer_employee_id' => $installer, 'area_responsible_employee_id' => $areaOwner, 'bonus_owner_employee_id' => $bonusOwner]);
        $db->commit();
        return ['ok' => true, 'id' => $assignmentId];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'assignment_save_failed', 'detail' => $exception->getMessage()];
    }
}

function app_bonus_save_adjustment(PDO $db, array $input, array $actor): array
{
    if (!app_bonus_actor_can_calculate($actor)) {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    app_bonus_ensure_schema($db);
    $periodId = max(0, (int) ($input['period_id'] ?? 0));
    $employeeId = max(0, (int) ($input['employee_id'] ?? 0));
    $amount = round((float) ($input['amount'] ?? 0), 2);
    $reason = mb_substr(clean_text((string) ($input['reason'] ?? '')), 0, 255);
    $type = (string) ($input['adjustment_type'] ?? 'bonus') === 'penalty' ? 'penalty' : 'bonus';
    if ($periodId <= 0 || $employeeId <= 0 || $amount <= 0 || $reason === '') {
        return ['ok' => false, 'error' => 'invalid_adjustment'];
    }
    $status = app_bonus_actor_can_manage_rules($actor) ? 'approved' : 'pending';
    $statement = $db->prepare('INSERT INTO employee_bonus_adjustments (period_id, employee_id, adjustment_type, amount, reason, status, created_by_account_id, approved_by_account_id, approved_at) VALUES (:period_id, :employee_id, :adjustment_type, :amount, :reason, :status, :actor_id, :approved_by, :approved_at)');
    $statement->execute(['period_id' => $periodId, 'employee_id' => $employeeId, 'adjustment_type' => $type, 'amount' => $amount, 'reason' => $reason, 'status' => $status, 'actor_id' => (int) ($actor['id'] ?? 0) ?: null, 'approved_by' => $status === 'approved' ? ((int) ($actor['id'] ?? 0) ?: null) : null, 'approved_at' => $status === 'approved' ? date('Y-m-d H:i:s') : null]);
    $id = (int) $db->lastInsertId();
    app_bonus_record_audit($db, 'bonus_adjustment_saved', 'employee_bonus_adjustment', (string) $id, $actor, 'Bonus adjustment saved', ['period_id' => $periodId, 'employee_id' => $employeeId, 'type' => $type, 'amount' => $amount, 'status' => $status], $reason);
    return ['ok' => true, 'id' => $id, 'status' => $status];
}

function app_bonus_update_adjustment_status(PDO $db, int $adjustmentId, string $status, array $actor, string $reason = ''): array
{
    if (!app_bonus_actor_can_manage_rules($actor)) {
        return ['ok' => false, 'error' => 'super_admin_required'];
    }
    if (!in_array($status, ['approved', 'rejected', 'cancelled'], true)) {
        return ['ok' => false, 'error' => 'invalid_status'];
    }
    app_bonus_ensure_schema($db);
    $before = $db->prepare('SELECT * FROM employee_bonus_adjustments WHERE id = :id LIMIT 1');
    $before->execute(['id' => $adjustmentId]);
    $row = $before->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'adjustment_not_found'];
    }
    $db->prepare('UPDATE employee_bonus_adjustments SET status = :status, approved_by_account_id = :actor_id, approved_at = NOW() WHERE id = :id')->execute(['status' => $status, 'actor_id' => (int) ($actor['id'] ?? 0) ?: null, 'id' => $adjustmentId]);
    $after = $db->prepare('SELECT * FROM employee_bonus_adjustments WHERE id = :id LIMIT 1');
    $after->execute(['id' => $adjustmentId]);
    app_bonus_record_audit($db, 'bonus_adjustment_status_changed', 'employee_bonus_adjustment', (string) $adjustmentId, $actor, 'Bonus adjustment status changed to ' . $status, ['status' => $status], $reason, $row, $after->fetch(PDO::FETCH_ASSOC) ?: null);
    return ['ok' => true];
}

function app_bonus_create_notification(PDO $db, int $employeeId, ?int $customerId, int $periodId, string $type, string $message, ?string $dueAt = null): bool
{
    if ($employeeId <= 0 || $periodId <= 0 || $type === '' || $message === '') {
        return false;
    }
    $check = $db->prepare('SELECT id FROM employee_bonus_notifications WHERE employee_id = :employee_id AND (customer_account_id <=> :customer_id) AND period_id = :period_id AND notification_type = :notification_type AND status IN ("new","seen","in_progress") LIMIT 1');
    $check->execute([
        'employee_id' => $employeeId,
        'customer_id' => $customerId,
        'period_id' => $periodId,
        'notification_type' => mb_substr($type, 0, 64),
    ]);
    if ((int) ($check->fetchColumn() ?: 0) > 0) {
        return false;
    }
    $insert = $db->prepare('INSERT INTO employee_bonus_notifications (employee_id, customer_account_id, period_id, notification_type, message, due_at) VALUES (:employee_id, :customer_id, :period_id, :notification_type, :message, :due_at)');
    $insert->execute([
        'employee_id' => $employeeId,
        'customer_id' => $customerId,
        'period_id' => $periodId,
        'notification_type' => mb_substr($type, 0, 64),
        'message' => mb_substr(clean_text($message), 0, 255),
        'due_at' => $dueAt,
    ]);
    return true;
}

function app_bonus_generate_period_notifications(PDO $db, int $periodId, string $periodStart, string $periodEnd): int
{
    $created = 0;
    $periodEndDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd) ?: new DateTimeImmutable('today');
    $dueAt = $periodEndDate->modify('+1 day')->format('Y-m-d 10:00:00');

    $ending = $db->prepare('SELECT employee_id, customer_account_id, subscriber_code, customer_name, bonus_month_no, bonus_month_limit FROM employee_bonus_items WHERE period_id = :period_id AND item_type = "recurring_active_payment" AND bonus_month_limit IS NOT NULL AND bonus_month_no IS NOT NULL AND (bonus_month_limit - bonus_month_no) <= 1');
    $ending->execute(['period_id' => $periodId]);
    foreach ($ending->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $message = sprintf('%s ucun 12 ayliq bonus penceresi bitir: %s/%s ay.', (string) ($row['subscriber_code'] ?? '-'), (string) ($row['bonus_month_no'] ?? '-'), (string) ($row['bonus_month_limit'] ?? '-'));
        $created += app_bonus_create_notification($db, (int) ($row['employee_id'] ?? 0), (int) ($row['customer_account_id'] ?? 0) ?: null, $periodId, 'bonus_window_ending', $message, $dueAt) ? 1 : 0;
    }

    $problem = $db->prepare('SELECT employee_id, customer_account_id, subscriber_code, customer_name, reason, quality_percent FROM employee_bonus_items WHERE period_id = :period_id AND item_type = "recurring_active_payment" AND (quality_percent < 100 OR COALESCE(reason,"") NOT IN ("","none"))');
    $problem->execute(['period_id' => $periodId]);
    foreach ($problem->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $message = sprintf('%s bonus keyfiyyet yoxlamasinda problem: %s, faiz %s%%.', (string) ($row['subscriber_code'] ?? '-'), (string) ($row['reason'] ?? 'unknown'), (string) ($row['quality_percent'] ?? '100'));
        $created += app_bonus_create_notification($db, (int) ($row['employee_id'] ?? 0), (int) ($row['customer_account_id'] ?? 0) ?: null, $periodId, 'problem_customer', $message, $dueAt) ? 1 : 0;
    }

    $inactive = $db->query('SELECT a.bonus_owner_employee_id, a.finder_employee_id, a.installer_employee_id, a.area_responsible_employee_id, c.id AS customer_id, c.subscriber_code, c.full_name FROM customer_employee_assignments a INNER JOIN portal_accounts c ON c.id = a.customer_account_id WHERE a.status = "active" AND COALESCE(c.is_active,0) = 0')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($inactive as $row) {
        $employeeId = (int) ($row['bonus_owner_employee_id'] ?? 0) ?: (int) ($row['finder_employee_id'] ?? 0) ?: (int) ($row['installer_employee_id'] ?? 0) ?: (int) ($row['area_responsible_employee_id'] ?? 0);
        $message = sprintf('%s bonus izinde aktivdir, amma musteri passiv/deaktiv gorunur.', (string) ($row['subscriber_code'] ?? '-'));
        $created += app_bonus_create_notification($db, $employeeId, (int) ($row['customer_id'] ?? 0) ?: null, $periodId, 'customer_deactivated', $message, $dueAt) ? 1 : 0;
    }

    $noPayment = $db->prepare('SELECT a.bonus_owner_employee_id, a.finder_employee_id, a.installer_employee_id, a.area_responsible_employee_id, c.id AS customer_id, c.subscriber_code, c.full_name, c.created_at
        FROM customer_employee_assignments a
        INNER JOIN portal_accounts c ON c.id = a.customer_account_id
        LEFT JOIN billing_payments p ON p.account_id = c.id
        WHERE a.status = "active" AND c.role = "customer" AND c.created_at <= :created_before
        GROUP BY a.id, c.id
        HAVING COUNT(p.id) = 0');
    $noPayment->execute(['created_before' => $periodEndDate->modify('-7 days')->format('Y-m-d 23:59:59')]);
    foreach ($noPayment->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $employeeId = (int) ($row['bonus_owner_employee_id'] ?? 0) ?: (int) ($row['finder_employee_id'] ?? 0) ?: (int) ($row['installer_employee_id'] ?? 0) ?: (int) ($row['area_responsible_employee_id'] ?? 0);
        $message = sprintf('%s yaradilib, 7 gunden cox kecib, hele odenis qeydi yoxdur.', (string) ($row['subscriber_code'] ?? '-'));
        $created += app_bonus_create_notification($db, $employeeId, (int) ($row['customer_id'] ?? 0) ?: null, $periodId, 'new_customer_no_payment_7_days', $message, $dueAt) ? 1 : 0;
    }

    $expiring = $db->prepare('SELECT a.bonus_owner_employee_id, a.finder_employee_id, a.installer_employee_id, a.area_responsible_employee_id, c.id AS customer_id, c.subscriber_code, s.expires_at
        FROM customer_employee_assignments a
        INNER JOIN portal_accounts c ON c.id = a.customer_account_id
        INNER JOIN billing_radius_services s ON s.account_id = c.id
        WHERE a.status = "active" AND s.status = "active" AND s.expires_at >= :period_end AND s.expires_at < :window_end');
    $expiring->execute([
        'period_end' => $periodEndDate->format('Y-m-d 00:00:00'),
        'window_end' => $periodEndDate->modify('+3 days')->format('Y-m-d 23:59:59'),
    ]);
    foreach ($expiring->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $employeeId = (int) ($row['bonus_owner_employee_id'] ?? 0) ?: (int) ($row['finder_employee_id'] ?? 0) ?: (int) ($row['installer_employee_id'] ?? 0) ?: (int) ($row['area_responsible_employee_id'] ?? 0);
        $message = sprintf('%s xidmet aktivliyi 3 gun erzinde bitir: %s.', (string) ($row['subscriber_code'] ?? '-'), (string) ($row['expires_at'] ?? '-'));
        $created += app_bonus_create_notification($db, $employeeId, (int) ($row['customer_id'] ?? 0) ?: null, $periodId, 'activity_expiring_3_days', $message, $dueAt) ? 1 : 0;
    }

    return $created;
}

function app_bonus_export_csv(PDO $db, int $periodId, string $type, array $actor, int $employeeId = 0): array
{
    app_bonus_ensure_schema($db);
    $period = app_bonus_period_by_id($db, $periodId);
    if ($period === null) {
        return ['ok' => false, 'error' => 'period_not_found'];
    }
    $canViewAll = app_bonus_actor_can_view_all($actor);
    $actorId = (int) ($actor['id'] ?? 0);
    if (!$canViewAll) {
        $employeeId = $actorId;
    }
    if ($type === 'items' && $employeeId <= 0 && !$canViewAll) {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    if ($type === 'summary' && $employeeId <= 0 && !$canViewAll) {
        return ['ok' => false, 'error' => 'forbidden'];
    }

    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        return ['ok' => false, 'error' => 'csv_open_failed'];
    }
    fwrite($handle, "\xEF\xBB\xBF");

    if ($type === 'items') {
        $params = ['period_id' => $periodId];
        $where = 'period_id = :period_id';
        if ($employeeId > 0) {
            $where .= ' AND employee_id = :employee_id';
            $params['employee_id'] = $employeeId;
        }
        fputcsv($handle, ['period', 'employee_id', 'item_type', 'subscriber_code', 'customer_name', 'active_days', 'active_percent', 'quality_percent', 'bonus_month', 'status', 'amount', 'reason']);
        $statement = $db->prepare('SELECT * FROM employee_bonus_items WHERE ' . $where . ' ORDER BY employee_id, subscriber_code, id');
        $statement->execute($params);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            fputcsv($handle, [
                app_bonus_period_label($period),
                (string) ($row['employee_id'] ?? ''),
                (string) ($row['item_type'] ?? ''),
                (string) ($row['subscriber_code'] ?? ''),
                (string) ($row['customer_name'] ?? ''),
                (string) ($row['active_days'] ?? ''),
                (string) ($row['active_percent'] ?? ''),
                (string) ($row['quality_percent'] ?? ''),
                (string) ($row['bonus_month_no'] ?? '') . '/' . (string) ($row['bonus_month_limit'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['amount'] ?? '0'),
                (string) ($row['reason'] ?? ''),
            ]);
        }
    } else {
        $params = ['period_id' => $periodId];
        $where = 'period_id = :period_id';
        if ($employeeId > 0) {
            $where .= ' AND employee_id = :employee_id';
            $params['employee_id'] = $employeeId;
        }
        fputcsv($handle, ['period', 'employee_id', 'employee_name', 'employee_role', 'areas', 'active_customers', 'problem_customers', 'late_payment_customers', 'eligible_customers', 'base_salary', 'new_connection_bonus', 'recurring_bonus', 'quality_deduction', 'manual_adjustment', 'total_payable', 'status']);
        $statement = $db->prepare('SELECT * FROM employee_bonus_summaries WHERE ' . $where . ' ORDER BY total_payable DESC, employee_name');
        $statement->execute($params);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            fputcsv($handle, [
                app_bonus_period_label($period),
                (string) ($row['employee_id'] ?? ''),
                (string) ($row['employee_name'] ?? ''),
                (string) ($row['employee_role'] ?? ''),
                (string) ($row['assigned_area_text'] ?? ''),
                (string) ($row['active_customer_count'] ?? '0'),
                (string) ($row['problem_customer_count'] ?? '0'),
                (string) ($row['late_payment_customer_count'] ?? '0'),
                (string) ($row['bonus_eligible_customer_count'] ?? '0'),
                (string) ($row['base_salary'] ?? '0'),
                (string) ($row['new_connection_bonus'] ?? '0'),
                (string) ($row['recurring_bonus'] ?? '0'),
                (string) ($row['quality_deduction'] ?? '0'),
                (string) ($row['manual_adjustment'] ?? '0'),
                (string) ($row['total_payable'] ?? '0'),
                (string) ($row['status'] ?? ''),
            ]);
        }
        $type = 'summary';
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return [
        'ok' => true,
        'filename' => 'employee-bonus-' . $type . '-' . (string) ($period['period_start'] ?? 'period') . '-' . (string) ($period['period_end'] ?? '') . '.csv',
        'csv' => is_string($csv) ? $csv : '',
    ];
}

function app_bonus_query_page(PDO $db, string $countSql, string $rowsSql, array $params, array $query): array
{
    return app_finance_query_page($db, $countSql, $rowsSql, $params, $query);
}

function app_bonus_dashboard(PDO $db): array
{
    app_bonus_ensure_schema($db);
    $latest = app_bonus_latest_period($db);
    $periodId = (int) ($latest['id'] ?? 0);
    $topRows = [];
    if ($periodId > 0) {
        $statement = $db->prepare('SELECT * FROM employee_bonus_summaries WHERE period_id = :period_id ORDER BY total_payable DESC LIMIT 8');
        $statement->execute(['period_id' => $periodId]);
        $topRows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    return ['latest_period' => $latest, 'top_rows' => $topRows, 'settings_count' => (int) $db->query('SELECT COUNT(*) FROM bonus_rule_settings WHERE is_active = 1')->fetchColumn(), 'pending_adjustments' => (int) $db->query('SELECT COUNT(*) FROM employee_bonus_adjustments WHERE status = "pending"')->fetchColumn(), 'open_notifications' => (int) $db->query('SELECT COUNT(*) FROM employee_bonus_notifications WHERE status IN ("new","seen","in_progress")')->fetchColumn()];
}

function app_bonus_period_page(PDO $db, array $query): array
{
    app_bonus_ensure_schema($db);
    return app_bonus_query_page($db, 'SELECT COUNT(*) FROM bonus_periods', 'SELECT * FROM bonus_periods ORDER BY period_start DESC, id DESC', [], $query);
}

function app_bonus_summary_page(PDO $db, int $periodId, array $query): array
{
    app_bonus_ensure_schema($db);
    return app_bonus_query_page($db, 'SELECT COUNT(*) FROM employee_bonus_summaries WHERE period_id = :period_id', 'SELECT * FROM employee_bonus_summaries WHERE period_id = :period_id ORDER BY total_payable DESC, employee_name', ['period_id' => $periodId], $query);
}

function app_bonus_items_page(PDO $db, int $periodId, int $employeeId, array $query): array
{
    app_bonus_ensure_schema($db);
    return app_bonus_query_page($db, 'SELECT COUNT(*) FROM employee_bonus_items WHERE period_id = :period_id AND employee_id = :employee_id', 'SELECT * FROM employee_bonus_items WHERE period_id = :period_id AND employee_id = :employee_id ORDER BY FIELD(item_type,"base_salary","new_connection","recurring_active_payment","quality_deduction","manual_adjustment","info"), id', ['period_id' => $periodId, 'employee_id' => $employeeId], $query);
}

function app_bonus_items_range_page(PDO $db, string $periodStart, string $periodEnd, int $employeeId, array $query): array
{
    app_bonus_ensure_schema($db);
    $params = ['period_start' => $periodStart, 'period_end' => $periodEnd, 'employee_id' => $employeeId];
    $from = ' FROM employee_bonus_items i INNER JOIN bonus_periods p ON p.id = i.period_id WHERE i.employee_id = :employee_id AND p.period_end >= :period_start AND p.period_start <= :period_end';
    return app_bonus_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT i.*, p.period_start, p.period_end' . $from . ' ORDER BY p.period_start DESC, FIELD(i.item_type,"base_salary","new_connection","recurring_active_payment","quality_deduction","manual_adjustment","info"), i.id',
        $params,
        $query
    );
}

function app_bonus_employee_range_summary(PDO $db, string $periodStart, string $periodEnd, int $employeeId): ?array
{
    app_bonus_ensure_schema($db);
    $statement = $db->prepare(
        'SELECT s.employee_id, MAX(s.employee_name) AS employee_name,
                SUM(s.base_salary) AS base_salary,
                SUM(s.new_connection_bonus) AS new_connection_bonus,
                SUM(s.recurring_bonus) AS recurring_bonus,
                SUM(s.manual_adjustment) AS manual_adjustment,
                SUM(s.total_payable) AS total_payable,
                SUM(s.active_customer_count) AS active_customer_count
         FROM employee_bonus_summaries s
         INNER JOIN bonus_periods p ON p.id = s.period_id
         WHERE s.employee_id = :employee_id
           AND p.period_end >= :period_start
           AND p.period_start <= :period_end
         GROUP BY s.employee_id'
    );
    $statement->execute(['employee_id' => $employeeId, 'period_start' => $periodStart, 'period_end' => $periodEnd]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function app_bonus_assignments_page(PDO $db, array $query): array
{
    app_bonus_ensure_schema($db);
    $params = [];
    $where = ' WHERE 1=1';
    $search = clean_text((string) ($query['search'] ?? ''));
    if ($search !== '') {
        $where .= ' AND (c.subscriber_code LIKE :search OR c.full_name LIKE :search OR e.full_name LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    $from = ' FROM customer_employee_assignments a INNER JOIN portal_accounts c ON c.id = a.customer_account_id LEFT JOIN portal_accounts e ON e.id = a.bonus_owner_employee_id' . $where;
    return app_bonus_query_page($db, 'SELECT COUNT(*)' . $from, 'SELECT a.*, c.subscriber_code, c.full_name AS customer_name, c.area, c.plan, e.full_name AS bonus_owner_name' . $from . ' ORDER BY a.updated_at DESC, a.id DESC', $params, $query);
}

function app_bonus_adjustments_page(PDO $db, array $query): array
{
    app_bonus_ensure_schema($db);
    return app_bonus_query_page($db, 'SELECT COUNT(*) FROM employee_bonus_adjustments', 'SELECT a.*, e.full_name AS employee_name, p.period_start, p.period_end FROM employee_bonus_adjustments a LEFT JOIN portal_accounts e ON e.id = a.employee_id LEFT JOIN bonus_periods p ON p.id = a.period_id ORDER BY a.created_at DESC, a.id DESC', [], $query);
}

function app_bonus_audit_page(PDO $db, array $query): array
{
    app_bonus_ensure_schema($db);
    return app_bonus_query_page($db, 'SELECT COUNT(*) FROM bonus_audit_logs', 'SELECT * FROM bonus_audit_logs ORDER BY created_at DESC, id DESC', [], $query);
}

function app_bonus_notifications_page(PDO $db, array $query, int $employeeId = 0): array
{
    app_bonus_ensure_schema($db);
    $params = [];
    $where = ' WHERE n.status IN ("new","seen","in_progress")';
    if ($employeeId > 0) {
        $where .= ' AND n.employee_id = :employee_id';
        $params['employee_id'] = $employeeId;
    }
    $from = ' FROM employee_bonus_notifications n LEFT JOIN portal_accounts e ON e.id = n.employee_id LEFT JOIN portal_accounts c ON c.id = n.customer_account_id LEFT JOIN bonus_periods p ON p.id = n.period_id' . $where;
    return app_bonus_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT n.*, e.full_name AS employee_name, c.subscriber_code, c.full_name AS customer_name, p.period_start, p.period_end' . $from . ' ORDER BY FIELD(n.status,"new","seen","in_progress"), COALESCE(n.due_at,n.created_at), n.id DESC',
        $params,
        $query
    );
}

function app_bonus_rules_context(PDO $db): array
{
    app_bonus_ensure_schema($db);
    return ['settings' => $db->query('SELECT * FROM bonus_rule_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_ASSOC) ?: [], 'salary_tiers' => $db->query('SELECT * FROM bonus_base_salary_tiers ORDER BY min_customers')->fetchAll(PDO::FETCH_ASSOC) ?: [], 'active_day_brackets' => $db->query('SELECT * FROM bonus_active_day_brackets ORDER BY min_days DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [], 'quality_rules' => $db->query('SELECT * FROM bonus_quality_rules ORDER BY problem_source, problem_key')->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

function app_bonus_context(PDO $db, array $user, array $query): array
{
    app_bonus_ensure_schema($db);
    $view = clean_text((string) ($query['bonus_view'] ?? 'dashboard'));
    $allowed = ['dashboard', 'calculations', 'employee-detail', 'customer-traces', 'rules', 'adjustments', 'payout', 'audit'];
    if (!in_array($view, $allowed, true)) {
        $view = 'dashboard';
    }
    if (!app_bonus_actor_can_view_all($user)) {
        $view = 'employee-detail';
        $query['employee_id'] = (int) ($user['id'] ?? 0);
    }
    $month = app_bonus_month_period((string) ($query['bonus_month'] ?? ''));
    $latest = app_bonus_latest_period($db);
    $periodId = max(0, (int) ($query['period_id'] ?? ($latest['id'] ?? 0)));
    $periodStart = trim((string) ($query['period_start'] ?? ''));
    $periodEnd = trim((string) ($query['period_end'] ?? ''));
    $validDate = static function (string $value): bool {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    };
    $hasDateRange = $validDate($periodStart) && $validDate($periodEnd);
    if ($hasDateRange && $periodStart > $periodEnd) {
        [$periodStart, $periodEnd] = [$periodEnd, $periodStart];
    }
    if ($hasDateRange) {
        $exactPeriod = $db->prepare('SELECT * FROM bonus_periods WHERE period_start = :period_start AND period_end = :period_end ORDER BY id DESC LIMIT 1');
        $exactPeriod->execute(['period_start' => $periodStart, 'period_end' => $periodEnd]);
        $exactRow = $exactPeriod->fetch(PDO::FETCH_ASSOC);
        $periodId = is_array($exactRow) ? (int) ($exactRow['id'] ?? 0) : 0;
    }
    $employeeId = max(0, (int) ($query['employee_id'] ?? ((int) ($user['id'] ?? 0))));
    $selectedPeriod = $periodId > 0 ? app_bonus_period_by_id($db, $periodId) : null;
    if (!$hasDateRange) {
        $periodStart = (string) ($selectedPeriod['period_start'] ?? $latest['period_start'] ?? date('Y-m-01'));
        $periodEnd = (string) ($selectedPeriod['period_end'] ?? $latest['period_end'] ?? date('Y-m-t'));
    }
    $data = ['view' => $view, 'month' => $month, 'latest_period' => $latest, 'selected_period' => $selectedPeriod, 'selected_period_start' => $periodStart, 'selected_period_end' => $periodEnd, 'employees' => app_bonus_employee_rows($db), 'can_manage_rules' => app_bonus_actor_can_manage_rules($user), 'can_calculate' => app_bonus_actor_can_calculate($user)];
    if ($view === 'dashboard') {
        $data += app_bonus_dashboard($db);
        $data['notifications_page'] = app_bonus_notifications_page($db, ['page' => 1, 'limit' => 8]);
    } elseif ($view === 'calculations' || $view === 'payout') {
        $data['period_page'] = app_bonus_period_page($db, $query);
        if ($periodId > 0) {
            $data['summary_page'] = app_bonus_summary_page($db, $periodId, $query);
        }
    } elseif ($view === 'employee-detail') {
        if (!app_bonus_actor_can_view_all($user)) {
            $employeeId = (int) ($user['id'] ?? 0);
        }
        $data['employee_id'] = $employeeId;
        $data['items_page'] = $employeeId > 0
            ? app_bonus_items_range_page($db, $periodStart, $periodEnd, $employeeId, $query)
            : ['rows' => []];
        $summary = null;
        if ($employeeId > 0) {
            $summary = app_bonus_employee_range_summary($db, $periodStart, $periodEnd, $employeeId);
        }
        $data['employee_summary'] = $summary;
        $data['notifications_page'] = $employeeId > 0 ? app_bonus_notifications_page($db, ['page' => 1, 'limit' => 8], $employeeId) : ['rows' => []];
    } elseif ($view === 'customer-traces') {
        $data['assignments_page'] = app_bonus_assignments_page($db, $query);
    } elseif ($view === 'rules') {
        $data += app_bonus_rules_context($db);
    } elseif ($view === 'adjustments') {
        $data['adjustments_page'] = app_bonus_adjustments_page($db, $query);
        $data['period_page'] = app_bonus_period_page($db, ['page' => 1, 'limit' => 50]);
    } elseif ($view === 'audit') {
        $data['audit_page'] = app_bonus_audit_page($db, $query);
    }
    return $data;
}
