<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/workspace-lib.php';

function whatsapp_monitor_schema_ready(PDO $db): bool
{
    $tables = [
        'whatsapp_employee_numbers',
        'whatsapp_monitor_devices',
        'whatsapp_monitor_cases',
        'whatsapp_monitor_screenshots',
        'whatsapp_monitor_pairings',
        'whatsapp_monitor_messages',
        'whatsapp_monitor_settings',
        'whatsapp_monitor_events',
        'whatsapp_monitor_audit_logs',
    ];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $statement = $db->prepare(
        'SELECT COUNT(*)
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN (' . $placeholders . ')'
    );
    $statement->execute($tables);
    if ((int) $statement->fetchColumn() !== count($tables)) {
        return false;
    }

    foreach (['qr_status', 'qr_session_key', 'qr_last_requested_at', 'qr_connected_at', 'qr_last_seen_at', 'qr_last_error'] as $column) {
        if (!app_db_column_exists($db, 'whatsapp_employee_numbers', $column)) {
            return false;
        }
    }
    return app_db_index_exists($db, 'whatsapp_employee_numbers', 'idx_whatsapp_numbers_qr_status');
}

function whatsapp_monitor_ensure_schema(PDO $db): void
{
    if (!whatsapp_monitor_schema_ready($db)) {
        throw new RuntimeException('whatsapp_monitor_schema_not_migrated');
    }
}

/**
 * Legacy installer retained for explicit maintenance only. HTTP entrypoints use
 * whatsapp_monitor_ensure_schema(), which is read-only.
 */
function whatsapp_monitor_install_schema_legacy(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS whatsapp_employee_numbers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id BIGINT UNSIGNED DEFAULT NULL,
            phone_number VARCHAR(32) NOT NULL,
            normalized_phone VARCHAR(32) NOT NULL,
            whatsapp_account_name VARCHAR(191) DEFAULT NULL,
            assigned_area_id VARCHAR(128) DEFAULT NULL,
            assigned_at DATETIME DEFAULT NULL,
            returned_at DATETIME DEFAULT NULL,
            status ENUM('active','inactive','returned','lost','blocked') NOT NULL DEFAULT 'active',
            is_company_owned TINYINT(1) NOT NULL DEFAULT 1,
            monitoring_policy_accepted TINYINT(1) NOT NULL DEFAULT 0,
            policy_version VARCHAR(32) NOT NULL DEFAULT '2026-07',
            accepted_at DATETIME DEFAULT NULL,
            accepted_by BIGINT UNSIGNED DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_whatsapp_numbers_employee (employee_id, status),
            KEY idx_whatsapp_numbers_phone (normalized_phone, status),
            KEY idx_whatsapp_numbers_area (assigned_area_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS whatsapp_monitor_devices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_name VARCHAR(191) NOT NULL,
            device_code VARCHAR(64) NOT NULL,
            employee_id BIGINT UNSIGNED DEFAULT NULL,
            phone_number_id BIGINT UNSIGNED DEFAULT NULL,
            area_id VARCHAR(128) DEFAULT NULL,
            device_type ENUM('android','iphone','web','other') NOT NULL DEFAULT 'android',
            connection_type ENUM('usb','tcpip','stream_url','mdm','manual') NOT NULL DEFAULT 'stream_url',
            stream_url VARCHAR(500) DEFAULT NULL,
            status ENUM('online','offline','warning','disabled') NOT NULL DEFAULT 'offline',
            monitoring_enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_seen_at DATETIME DEFAULT NULL,
            last_stream_at DATETIME DEFAULT NULL,
            last_error VARCHAR(255) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_whatsapp_device_code (device_code),
            KEY idx_whatsapp_devices_employee (employee_id, status),
            KEY idx_whatsapp_devices_phone (phone_number_id),
            KEY idx_whatsapp_devices_area (area_id, status),
            KEY idx_whatsapp_devices_status (status, monitoring_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS whatsapp_monitor_cases (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id BIGINT UNSIGNED DEFAULT NULL,
            employee_id BIGINT UNSIGNED DEFAULT NULL,
            customer_id BIGINT UNSIGNED DEFAULT NULL,
            ticket_id BIGINT UNSIGNED DEFAULT NULL,
            ticket_no VARCHAR(32) DEFAULT NULL,
            case_type ENUM('unanswered','complaint','payment','technical','sales','unknown') NOT NULL DEFAULT 'unanswered',
            status ENUM('new','waiting_employee','in_progress','replied','escalated','resolved','ignored') NOT NULL DEFAULT 'new',
            priority ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
            opened_by BIGINT UNSIGNED DEFAULT NULL,
            assigned_to BIGINT UNSIGNED DEFAULT NULL,
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            replied_at DATETIME DEFAULT NULL,
            resolved_at DATETIME DEFAULT NULL,
            response_time_minutes INT UNSIGNED DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_whatsapp_cases_device (device_id, status),
            KEY idx_whatsapp_cases_employee (employee_id, status),
            KEY idx_whatsapp_cases_customer (customer_id, status),
            KEY idx_whatsapp_cases_assigned (assigned_to, status),
            KEY idx_whatsapp_cases_opened (opened_at, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS whatsapp_monitor_screenshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id BIGINT UNSIGNED DEFAULT NULL,
            employee_id BIGINT UNSIGNED DEFAULT NULL,
            customer_id BIGINT UNSIGNED DEFAULT NULL,
            case_id BIGINT UNSIGNED DEFAULT NULL,
            taken_by BIGINT UNSIGNED DEFAULT NULL,
            reason VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            visibility ENUM('super_admin','crm_lead','restricted') NOT NULL DEFAULT 'restricted',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_whatsapp_screenshots_case (case_id, created_at),
            KEY idx_whatsapp_screenshots_device (device_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS whatsapp_monitor_pairings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            number_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED DEFAULT NULL,
            device_id BIGINT UNSIGNED DEFAULT NULL,
            session_key VARCHAR(128) NOT NULL,
            pairing_status ENUM('requested','qr_ready','scanned','connected','expired','failed','revoked') NOT NULL DEFAULT 'requested',
            qr_payload LONGTEXT DEFAULT NULL,
            qr_image_data_uri LONGTEXT DEFAULT NULL,
            worker_id VARCHAR(128) DEFAULT NULL,
            requested_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            scanned_at DATETIME DEFAULT NULL,
            connected_at DATETIME DEFAULT NULL,
            last_seen_at DATETIME DEFAULT NULL,
            failure_reason VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_whatsapp_pairing_session (session_key),
            KEY idx_whatsapp_pairing_number (number_id, pairing_status),
            KEY idx_whatsapp_pairing_employee (employee_id, pairing_status),
            KEY idx_whatsapp_pairing_device (device_id, pairing_status),
            KEY idx_whatsapp_pairing_status (pairing_status, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS whatsapp_monitor_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            number_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED DEFAULT NULL,
            session_key VARCHAR(128) NOT NULL,
            whatsapp_message_id VARCHAR(191) NOT NULL,
            chat_id VARCHAR(191) NOT NULL,
            sender_id VARCHAR(191) DEFAULT NULL,
            recipient_id VARCHAR(191) DEFAULT NULL,
            contact_name VARCHAR(191) DEFAULT NULL,
            direction ENUM('inbound','outbound') NOT NULL,
            message_type VARCHAR(64) NOT NULL DEFAULT 'chat',
            message_body TEXT DEFAULT NULL,
            has_media TINYINT(1) NOT NULL DEFAULT 0,
            occurred_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_whatsapp_monitor_message (whatsapp_message_id),
            KEY idx_whatsapp_monitor_chat (number_id, chat_id, occurred_at),
            KEY idx_whatsapp_monitor_employee (employee_id, occurred_at),
            KEY idx_whatsapp_monitor_direction (direction, occurred_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS whatsapp_monitor_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(128) NOT NULL,
            setting_value VARCHAR(255) NOT NULL DEFAULT '',
            value_type ENUM('string','int','bool','time') NOT NULL DEFAULT 'string',
            description VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_whatsapp_setting_key (setting_key),
            KEY idx_whatsapp_settings_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS whatsapp_monitor_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id BIGINT UNSIGNED DEFAULT NULL,
            employee_id BIGINT UNSIGNED DEFAULT NULL,
            event_type VARCHAR(64) NOT NULL,
            event_payload_json LONGTEXT DEFAULT NULL,
            source ENUM('system','manual','stream','future_api','future_ocr') NOT NULL DEFAULT 'manual',
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_whatsapp_events_device (device_id, created_at),
            KEY idx_whatsapp_events_employee (employee_id, created_at),
            KEY idx_whatsapp_events_type (event_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS whatsapp_monitor_audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            action VARCHAR(64) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id VARCHAR(64) NOT NULL,
            old_values LONGTEXT DEFAULT NULL,
            new_values LONGTEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_whatsapp_audit_entity (entity_type, entity_id),
            KEY idx_whatsapp_audit_user (user_id, created_at),
            KEY idx_whatsapp_audit_action (action, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    whatsapp_monitor_ensure_runtime_columns($db);
    whatsapp_monitor_seed_settings($db);
}

function whatsapp_monitor_ensure_runtime_columns(PDO $db): void
{
    $numberColumns = [
        'qr_status' => "ALTER TABLE whatsapp_employee_numbers ADD COLUMN qr_status VARCHAR(32) NOT NULL DEFAULT 'not_connected' AFTER monitoring_policy_accepted",
        'qr_session_key' => "ALTER TABLE whatsapp_employee_numbers ADD COLUMN qr_session_key VARCHAR(128) DEFAULT NULL AFTER qr_status",
        'qr_last_requested_at' => "ALTER TABLE whatsapp_employee_numbers ADD COLUMN qr_last_requested_at DATETIME DEFAULT NULL AFTER qr_session_key",
        'qr_connected_at' => "ALTER TABLE whatsapp_employee_numbers ADD COLUMN qr_connected_at DATETIME DEFAULT NULL AFTER qr_last_requested_at",
        'qr_last_seen_at' => "ALTER TABLE whatsapp_employee_numbers ADD COLUMN qr_last_seen_at DATETIME DEFAULT NULL AFTER qr_connected_at",
        'qr_last_error' => "ALTER TABLE whatsapp_employee_numbers ADD COLUMN qr_last_error VARCHAR(255) DEFAULT NULL AFTER qr_last_seen_at",
    ];
    foreach ($numberColumns as $column => $sql) {
        if (!app_db_column_exists($db, 'whatsapp_employee_numbers', $column)) {
            $db->exec($sql);
        }
    }

    if (!app_db_index_exists($db, 'whatsapp_employee_numbers', 'idx_whatsapp_numbers_qr_status')) {
        $db->exec('CREATE INDEX idx_whatsapp_numbers_qr_status ON whatsapp_employee_numbers (qr_status, qr_last_requested_at)');
    }
}

function whatsapp_monitor_seed_settings(PDO $db): void
{
    $defaults = [
        'unanswered_warning_minutes' => ['15', 'int', 'Cavabsiz mesaj warning limiti'],
        'unanswered_critical_minutes' => ['60', 'int', 'Critical gecikme limiti'],
        'offline_warning_minutes' => ['10', 'int', 'Offline cihaz warning limiti'],
        'stream_refresh_seconds' => ['10', 'int', 'Screen Wall refresh interval'],
        'default_grid_columns' => ['4', 'int', 'Monitor grid default layout'],
        'screenshot_enabled' => ['1', 'bool', 'Screenshot sistemi aktivdir'],
        'employee_can_view_own_performance' => ['1', 'bool', 'Emekdas oz performansini gore bilir'],
        'workday_start' => ['09:00', 'time', 'Is vaxti baslangici'],
        'workday_end' => ['18:00', 'time', 'Is vaxti sonu'],
        'require_reason_for_screenshot' => ['1', 'bool', 'Screenshot ucun sebeb mecburidir'],
        'pairing_qr_ttl_seconds' => ['600', 'int', 'QR baglanti sessiyasinin aktiv qalma muddeti'],
        'worker_api_token' => ['', 'string', 'WhatsApp worker API token'],
        'heartbeat_stale_minutes' => ['10', 'int', 'Heartbeat bu qeder kohnedirse status online sayilmir'],
        'sync_stale_minutes' => ['30', 'int', 'Sinxronizasiya bu qeder gecikirse status offline sayilir'],
    ];
    $statement = $db->prepare(
        'INSERT IGNORE INTO whatsapp_monitor_settings (setting_key, setting_value, value_type, description)
         VALUES (:setting_key, :setting_value, :value_type, :description)'
    );
    foreach ($defaults as $key => [$value, $type, $description]) {
        $statement->execute([
            'setting_key' => $key,
            'setting_value' => $value,
            'value_type' => $type,
            'description' => $description,
        ]);
    }
}

function whatsapp_monitor_stage4_enabled(PDO $db, array $actor = []): bool
{
    if (!function_exists('app_feature_flag_enabled')) {
        return false;
    }
    try {
        return app_feature_flag_enabled($db, 'stage4_message_center', $actor, false);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Resolve the default WhatsApp monitor view.
 *
 * Audit section 7.1: under Stage 4 the primary view for a normal user is the
 * Live Monitor (canonical number status cards), not the KPI overview.
 */
function whatsapp_monitor_default_view(PDO $db, array $actor = []): string
{
    return whatsapp_monitor_stage4_enabled($db, $actor) ? 'live-monitor' : 'overview';
}

function whatsapp_monitor_tabs(?PDO $db = null, array $actor = []): array
{
    $stage4 = $db instanceof PDO && whatsapp_monitor_stage4_enabled($db, $actor);
    if ($stage4) {
        // Audit 7.1: Live Monitor is the primary view; the video screen wall
        // stays reachable by direct URL but leaves the menu.
        return [
            'live-monitor' => 'Canli Monitor',
            'chats' => 'Catlar',
            'cases' => 'Cavabsiz mesajlar',
            'numbers' => 'Isci nomreleri',
            'pairing' => 'QR baglantisi',
            'devices' => 'Cihazlar',
            'performance' => 'Performans',
            'settings' => 'Qaydalar',
            'audit' => 'Audit log',
        ];
    }

    return [
        'overview' => 'Umumi baxis',
        'screen-wall' => 'Canli monitor',
        'numbers' => 'Isci nomreleri',
        'pairing' => 'QR baglantisi',
        'devices' => 'Cihazlar',
        'chats' => 'Catlar',
        'cases' => 'Cavabsiz mesajlar',
        'performance' => 'Performans',
        'settings' => 'Qaydalar',
        'audit' => 'Audit log',
    ];
}

/**
 * All view keys that are reachable by direct URL (aliases), even if the flag
 * removes them from the menu. Used to validate the requested `view` param.
 *
 * @return list<string>
 */
function whatsapp_monitor_all_view_keys(): array
{
    return [
        'overview', 'live-monitor', 'screen-wall', 'numbers', 'pairing',
        'devices', 'chats', 'cases', 'performance', 'settings', 'audit',
    ];
}

function whatsapp_monitor_url(string $view, array $params = [], string $lang = 'az'): string
{
    $query = ['view' => $view] + $params;
    if ($lang !== 'az') {
        $query['lang'] = $lang;
    }
    return 'whatsapp-monitor.php?' . http_build_query($query);
}

function whatsapp_monitor_normalize_phone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
        $digits = '994' . substr($digits, 1);
    }
    if (strlen($digits) === 9 && preg_match('/^[457]\d{8}$/', $digits) === 1) {
        $digits = '994' . $digits;
    }
    return $digits;
}

function whatsapp_monitor_can_manage(array $actor): bool
{
    return app_portal_is_super_admin($actor);
}

function whatsapp_monitor_can_view_all(array $actor): bool
{
    $role = strtolower(trim((string) ($actor['employee_role'] ?? '')));
    return app_portal_is_admin($actor)
        || in_array($role, ['admin', 'super_admin', 'support', 'noc', 'crm_manager', 'operator_manager'], true);
}

function whatsapp_monitor_scope_sql(array $actor, string $alias = ''): array
{
    if (whatsapp_monitor_can_view_all($actor)) {
        return ['1=1', []];
    }
    $prefix = $alias !== '' ? $alias . '.' : '';
    return ['(' . $prefix . 'employee_id = :scope_employee_id OR ' . $prefix . 'assigned_to = :scope_employee_id)', [
        'scope_employee_id' => (int) ($actor['id'] ?? 0),
    ]];
}

function whatsapp_monitor_actor_name(array $actor): string
{
    return clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system'));
}

function whatsapp_monitor_audit(PDO $db, array $actor, string $action, string $entityType, int|string $entityId, array $old = [], array $new = []): void
{
    $statement = $db->prepare(
        'INSERT INTO whatsapp_monitor_audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
         VALUES (:user_id, :action, :entity_type, :entity_id, :old_values, :new_values, :ip_address, :user_agent)'
    );
    $statement->execute([
        'user_id' => (int) ($actor['id'] ?? 0) ?: null,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => (string) $entityId,
        'old_values' => $old === [] ? null : app_db_json_encode($old),
        'new_values' => $new === [] ? null : app_db_json_encode($new),
        'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255),
    ]);

    app_mgs_record_operation_action([
        'action_type' => $action,
        'entity_type' => 'whatsapp_' . $entityType,
        'entity_id' => (string) $entityId,
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'WhatsApp Monitor: ' . $action,
        'payload' => ['old' => $old, 'new' => $new],
    ]);
}

function whatsapp_monitor_event(PDO $db, array $actor, string $eventType, int $deviceId = 0, int $employeeId = 0, array $payload = [], string $source = 'manual'): void
{
    $statement = $db->prepare(
        'INSERT INTO whatsapp_monitor_events (device_id, employee_id, event_type, event_payload_json, source, created_by)
         VALUES (:device_id, :employee_id, :event_type, :payload, :source, :created_by)'
    );
    $statement->execute([
        'device_id' => $deviceId > 0 ? $deviceId : null,
        'employee_id' => $employeeId > 0 ? $employeeId : null,
        'event_type' => $eventType,
        'payload' => $payload === [] ? null : app_db_json_encode($payload),
        'source' => in_array($source, ['system', 'manual', 'stream', 'future_api', 'future_ocr'], true) ? $source : 'manual',
        'created_by' => (int) ($actor['id'] ?? 0) ?: null,
    ]);
}

function whatsapp_monitor_settings(PDO $db): array
{
    whatsapp_monitor_ensure_schema($db);
    $rows = $db->query('SELECT * FROM whatsapp_monitor_settings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $settings = [];
    foreach ($rows as $row) {
        $settings[(string) $row['setting_key']] = $row;
    }
    return $settings;
}

function whatsapp_monitor_setting_value(array $settings, string $key, string $default = ''): string
{
    return (string) ($settings[$key]['setting_value'] ?? $default);
}

function whatsapp_monitor_employees(PDO $db): array
{
    $rows = $db->query(
        'SELECT id, full_name, login, employee_role, account_type, area
         FROM portal_accounts
         WHERE role = "employee" AND is_active = 1
         ORDER BY full_name'
    )->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function whatsapp_monitor_numbers(PDO $db, array $actor): array
{
    $params = [];
    $where = '1=1';
    if (!whatsapp_monitor_can_view_all($actor)) {
        $where = 'n.employee_id = :employee_id';
        $params['employee_id'] = (int) ($actor['id'] ?? 0);
    }
    $statement = $db->prepare(
        'SELECT n.*, e.full_name AS employee_name, e.login AS employee_login, e.employee_role
         FROM whatsapp_employee_numbers n
         LEFT JOIN portal_accounts e ON e.id = n.employee_id
         WHERE ' . $where . '
         ORDER BY n.status = "active" DESC, n.updated_at DESC, n.id DESC
         LIMIT 250'
    );
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function whatsapp_monitor_pairing_status_label(string $status): string
{
    return [
        'not_connected' => 'QR qoşulmayıb',
        'requested' => 'QR gözləyir',
        'qr_requested' => 'QR gözləyir',
        'qr_ready' => 'QR hazırdır',
        'scanned' => 'QR oxundu',
        'connected' => 'Qoşulub',
        'expired' => 'Vaxt bitib',
        'failed' => 'Xəta var',
        'revoked' => 'Ləğv edilib',
    ][$status] ?? $status;
}

function whatsapp_monitor_pairings(PDO $db, array $actor, array $filters = []): array
{
    $where = [];
    $params = [];
    if (!whatsapp_monitor_can_view_all($actor)) {
        $where[] = 'p.employee_id = :employee_id';
        $params['employee_id'] = (int) ($actor['id'] ?? 0);
    }
    $numberId = (int) ($filters['number_id'] ?? 0);
    if ($numberId > 0) {
        $where[] = 'p.number_id = :number_id';
        $params['number_id'] = $numberId;
    }
    $status = clean_text((string) ($filters['pairing_status'] ?? ''));
    if (in_array($status, ['requested', 'qr_ready', 'scanned', 'connected', 'expired', 'failed', 'revoked'], true)) {
        $where[] = 'p.pairing_status = :pairing_status';
        $params['pairing_status'] = $status;
    }
    $sql = 'SELECT p.*, n.phone_number, n.normalized_phone, n.whatsapp_account_name,
                   n.qr_status AS number_qr_status, e.full_name AS employee_name, e.login AS employee_login,
                   d.device_name, d.device_code
            FROM whatsapp_monitor_pairings p
            LEFT JOIN whatsapp_employee_numbers n ON n.id = p.number_id
            LEFT JOIN portal_accounts e ON e.id = p.employee_id
            LEFT JOIN whatsapp_monitor_devices d ON d.id = p.device_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY p.created_at DESC, p.id DESC LIMIT 250';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function whatsapp_monitor_latest_pairing_for_number(PDO $db, int $numberId): ?array
{
    $statement = $db->prepare(
        'SELECT * FROM whatsapp_monitor_pairings
         WHERE number_id = :number_id
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    $statement->execute(['number_id' => $numberId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function whatsapp_monitor_number_for_actor(PDO $db, int $numberId, array $actor): ?array
{
    $statement = $db->prepare('SELECT * FROM whatsapp_employee_numbers WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $numberId]);
    $number = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($number)) {
        return null;
    }
    if (whatsapp_monitor_can_view_all($actor) || (int) ($number['employee_id'] ?? 0) === (int) ($actor['id'] ?? 0)) {
        return $number;
    }
    return null;
}

function whatsapp_monitor_start_pairing(PDO $db, int $numberId, int $deviceId, array $actor): array
{
    if (!whatsapp_monitor_can_manage($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    $number = whatsapp_monitor_number_for_actor($db, $numberId, $actor);
    if ($number === null) {
        return ['ok' => false, 'error' => 'number_not_found'];
    }
    if ((string) ($number['status'] ?? '') !== 'active') {
        return ['ok' => false, 'error' => 'number_not_active'];
    }
    if (empty($number['monitoring_policy_accepted'])) {
        return ['ok' => false, 'error' => 'policy_required'];
    }

    $settings = whatsapp_monitor_settings($db);
    $ttl = max(60, min(900, (int) whatsapp_monitor_setting_value($settings, 'pairing_qr_ttl_seconds', '120')));
    $sessionKey = 'wa_' . bin2hex(random_bytes(16));
    $employeeId = (int) ($number['employee_id'] ?? 0) ?: null;
    $deviceId = $deviceId > 0 ? $deviceId : 0;

    $db->prepare(
        'UPDATE whatsapp_monitor_pairings
         SET pairing_status = "expired", failure_reason = COALESCE(failure_reason, "new_pairing_requested")
         WHERE number_id = :number_id AND pairing_status IN ("requested","qr_ready","scanned")'
    )->execute(['number_id' => $numberId]);

    $statement = $db->prepare(
        'INSERT INTO whatsapp_monitor_pairings (
            number_id, employee_id, device_id, session_key, pairing_status, requested_by, expires_at
         ) VALUES (
            :number_id, :employee_id, :device_id, :session_key, "requested", :requested_by, DATE_ADD(NOW(), INTERVAL ' . $ttl . ' SECOND)
         )'
    );
    $statement->execute([
        'number_id' => $numberId,
        'employee_id' => $employeeId,
        'device_id' => $deviceId > 0 ? $deviceId : null,
        'session_key' => $sessionKey,
        'requested_by' => (int) ($actor['id'] ?? 0) ?: null,
    ]);
    $pairingId = (int) $db->lastInsertId();

    $db->prepare(
        'UPDATE whatsapp_employee_numbers
         SET qr_status = "qr_requested", qr_session_key = :session_key, qr_last_requested_at = NOW(),
             qr_last_error = NULL
         WHERE id = :number_id'
    )->execute([
        'session_key' => $sessionKey,
        'number_id' => $numberId,
    ]);

    whatsapp_monitor_audit($db, $actor, 'qr_pairing_requested', 'number', $numberId, $number, [
        'pairing_id' => $pairingId,
        'session_key' => $sessionKey,
        'device_id' => $deviceId,
    ]);
    whatsapp_monitor_event($db, $actor, 'qr_pairing_requested', $deviceId, (int) ($employeeId ?? 0), [
        'number_id' => $numberId,
        'pairing_id' => $pairingId,
    ]);

    return ['ok' => true, 'id' => $pairingId, 'session_key' => $sessionKey];
}

function whatsapp_monitor_revoke_pairing(PDO $db, int $numberId, array $actor): array
{
    if (!whatsapp_monitor_can_manage($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    $number = whatsapp_monitor_number_for_actor($db, $numberId, $actor);
    if ($number === null) {
        return ['ok' => false, 'error' => 'number_not_found'];
    }
    $db->prepare(
        'UPDATE whatsapp_monitor_pairings
         SET pairing_status = "revoked", failure_reason = COALESCE(failure_reason, "revoked_by_admin"), updated_by = :updated_by
         WHERE number_id = :number_id AND pairing_status IN ("requested","qr_ready","scanned","connected")'
    )->execute([
        'number_id' => $numberId,
        'updated_by' => (int) ($actor['id'] ?? 0) ?: null,
    ]);
    $db->prepare(
        'UPDATE whatsapp_employee_numbers
         SET qr_status = "revoked", qr_session_key = NULL, qr_last_error = "revoked_by_admin"
         WHERE id = :number_id'
    )->execute(['number_id' => $numberId]);

    whatsapp_monitor_audit($db, $actor, 'qr_pairing_revoked', 'number', $numberId, $number, ['qr_status' => 'revoked']);
    whatsapp_monitor_event($db, $actor, 'qr_pairing_revoked', 0, (int) ($number['employee_id'] ?? 0), ['number_id' => $numberId]);

    return ['ok' => true];
}

function whatsapp_monitor_worker_authorized(PDO $db, string $token): bool
{
    $expected = whatsapp_monitor_setting_value(whatsapp_monitor_settings($db), 'worker_api_token', '');
    return $expected !== '' && $token !== '' && hash_equals($expected, $token);
}

function whatsapp_monitor_pairing_queue(PDO $db, int $limit = 20): array
{
    $db->exec(
        'UPDATE whatsapp_monitor_pairings
         SET pairing_status = "expired", failure_reason = COALESCE(failure_reason, "qr_timeout")
         WHERE pairing_status IN ("requested","qr_ready","scanned")
           AND expires_at IS NOT NULL
           AND expires_at <= NOW()'
    );
    $statement = $db->prepare(
        'SELECT p.id, p.number_id, p.employee_id, p.device_id, p.session_key, p.pairing_status, p.expires_at,
                n.phone_number, n.normalized_phone, n.whatsapp_account_name,
                d.device_code, d.device_name
         FROM whatsapp_monitor_pairings p
         LEFT JOIN whatsapp_employee_numbers n ON n.id = p.number_id
         LEFT JOIN whatsapp_monitor_devices d ON d.id = p.device_id
         WHERE (p.pairing_status IN ("requested","qr_ready","scanned") AND (p.expires_at IS NULL OR p.expires_at > NOW()))
            OR p.pairing_status = "connected"
         ORDER BY p.created_at ASC, p.id ASC
         LIMIT :limit'
    );
    $statement->bindValue('limit', max(1, min(100, $limit)), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function whatsapp_monitor_worker_update_pairing(PDO $db, array $payload): array
{
    $sessionKey = clean_text((string) ($payload['session_key'] ?? ''));
    $status = clean_text((string) ($payload['status'] ?? ''));
    if ($sessionKey === '' || !in_array($status, ['qr_ready', 'scanned', 'connected', 'expired', 'failed', 'revoked'], true)) {
        return ['ok' => false, 'error' => 'bad_payload'];
    }

    $statement = $db->prepare('SELECT * FROM whatsapp_monitor_pairings WHERE session_key = :session_key LIMIT 1');
    $statement->execute(['session_key' => $sessionKey]);
    $pairing = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($pairing)) {
        return ['ok' => false, 'error' => 'session_not_found'];
    }

    $qrPayload = (string) ($payload['qr_payload'] ?? '');
    $qrImageDataUri = (string) ($payload['qr_image_data_uri'] ?? '');
    $workerId = clean_text((string) ($payload['worker_id'] ?? ''));
    $failureReason = clean_text((string) ($payload['failure_reason'] ?? ''));
    $currentStatus = (string) ($pairing['pairing_status'] ?? 'requested');
    if ($currentStatus === 'connected' && in_array($status, ['qr_ready', 'scanned'], true)) {
        return ['ok' => true, 'status' => 'connected', 'ignored' => 'status_downgrade'];
    }
    if ($status === 'failed'
        && !empty($pairing['connected_at'])
        && str_contains(strtolower($failureReason), 'browser is already running')) {
        return ['ok' => true, 'status' => 'connected', 'ignored' => 'duplicate_worker_profile_lock'];
    }

    $updates = [
        'pairing_status = :pairing_status',
        'qr_payload = COALESCE(:qr_payload, qr_payload)',
        'qr_image_data_uri = COALESCE(:qr_image_data_uri, qr_image_data_uri)',
        'worker_id = COALESCE(:worker_id, worker_id)',
        'failure_reason = :failure_reason',
    ];
    $params = [
        'session_key' => $sessionKey,
        'pairing_status' => $status,
        'qr_payload' => $qrPayload !== '' ? $qrPayload : null,
        'qr_image_data_uri' => $qrImageDataUri !== '' ? $qrImageDataUri : null,
        'worker_id' => $workerId !== '' ? $workerId : null,
        'failure_reason' => $failureReason !== '' ? $failureReason : null,
    ];
    if ($status === 'scanned') {
        $updates[] = 'scanned_at = COALESCE(scanned_at, NOW())';
    }
    if ($status === 'connected') {
        $updates[] = 'connected_at = COALESCE(connected_at, NOW())';
        $updates[] = 'last_seen_at = NOW()';
    }
    $db->prepare('UPDATE whatsapp_monitor_pairings SET ' . implode(', ', $updates) . ' WHERE session_key = :session_key')->execute($params);

    $numberStatus = match ($status) {
        'qr_ready' => 'qr_ready',
        'scanned' => 'scanned',
        'connected' => 'connected',
        'expired' => 'expired',
        'failed' => 'failed',
        'revoked' => 'revoked',
        default => 'qr_requested',
    };
    $numberUpdates = [
        'qr_status = :qr_status',
        'qr_last_error = :qr_last_error',
    ];
    $numberParams = [
        'number_id' => (int) ($pairing['number_id'] ?? 0),
        'qr_status' => $numberStatus,
        'qr_last_error' => $failureReason !== '' ? $failureReason : null,
    ];
    if ($status === 'connected') {
        $numberUpdates[] = 'qr_connected_at = COALESCE(qr_connected_at, NOW())';
        $numberUpdates[] = 'qr_last_seen_at = NOW()';
    }
    if (in_array($status, ['expired', 'failed', 'revoked'], true)) {
        $numberUpdates[] = 'qr_session_key = NULL';
    }
    $db->prepare('UPDATE whatsapp_employee_numbers SET ' . implode(', ', $numberUpdates) . ' WHERE id = :number_id')->execute($numberParams);

    $systemActor = ['id' => 0, 'full_name' => 'whatsapp-worker'];
    whatsapp_monitor_event($db, $systemActor, 'qr_pairing_' . $status, (int) ($pairing['device_id'] ?? 0), (int) ($pairing['employee_id'] ?? 0), [
        'number_id' => (int) ($pairing['number_id'] ?? 0),
        'session_key' => $sessionKey,
        'worker_id' => $workerId,
    ], 'system');

    return ['ok' => true, 'status' => $status];
}

function whatsapp_monitor_worker_ingest_message(PDO $db, array $payload): array
{
    $sessionKey = clean_text((string) ($payload['session_key'] ?? ''));
    $messageId = clean_text((string) ($payload['message_id'] ?? ''));
    $chatId = clean_text((string) ($payload['chat_id'] ?? ''));
    if ($sessionKey === '' || $messageId === '' || $chatId === '') {
        return ['ok' => false, 'error' => 'bad_payload'];
    }

    $pairingStatement = $db->prepare('SELECT number_id, employee_id FROM whatsapp_monitor_pairings WHERE session_key = :session_key LIMIT 1');
    $pairingStatement->execute(['session_key' => $sessionKey]);
    $pairing = $pairingStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($pairing)) {
        return ['ok' => false, 'error' => 'session_not_found'];
    }

    $timestamp = max(1, (int) ($payload['timestamp'] ?? time()));
    $occurredAt = date('Y-m-d H:i:s', $timestamp);
    $direction = !empty($payload['from_me']) ? 'outbound' : 'inbound';
    $statement = $db->prepare(
        'INSERT INTO whatsapp_monitor_messages (
            number_id, employee_id, session_key, whatsapp_message_id, chat_id, sender_id, recipient_id,
            contact_name, direction, message_type, message_body, has_media, occurred_at
         ) VALUES (
            :number_id, :employee_id, :session_key, :message_id, :chat_id, :sender_id, :recipient_id,
            :contact_name, :direction, :message_type, :message_body, :has_media, :occurred_at
         ) ON DUPLICATE KEY UPDATE
            contact_name = VALUES(contact_name), message_body = VALUES(message_body), has_media = VALUES(has_media)'
    );
    $statement->execute([
        'number_id' => (int) ($pairing['number_id'] ?? 0),
        'employee_id' => (int) ($pairing['employee_id'] ?? 0) ?: null,
        'session_key' => $sessionKey,
        'message_id' => mb_substr($messageId, 0, 191),
        'chat_id' => mb_substr($chatId, 0, 191),
        'sender_id' => mb_substr(clean_text((string) ($payload['from'] ?? '')), 0, 191) ?: null,
        'recipient_id' => mb_substr(clean_text((string) ($payload['to'] ?? '')), 0, 191) ?: null,
        'contact_name' => mb_substr(clean_text((string) ($payload['contact_name'] ?? '')), 0, 191) ?: null,
        'direction' => $direction,
        'message_type' => mb_substr(clean_text((string) ($payload['type'] ?? 'chat')), 0, 64) ?: 'chat',
        'message_body' => mb_substr((string) ($payload['body'] ?? ''), 0, 10000),
        'has_media' => !empty($payload['has_media']) ? 1 : 0,
        'occurred_at' => $occurredAt,
    ]);
    $db->prepare('UPDATE whatsapp_employee_numbers SET qr_last_seen_at = NOW() WHERE id = :number_id')->execute(['number_id' => (int) ($pairing['number_id'] ?? 0)]);
    return ['ok' => true, 'id' => (int) $db->lastInsertId(), 'direction' => $direction];
}

/**
 * Canonical per-number status contract (audit section 7.3).
 *
 * The frontend never derives status itself: this function is the single
 * source of `online|sync_delayed|offline|disabled` plus a human reason.
 * Stale thresholds come from whatsapp_monitor_settings.
 */
function whatsapp_monitor_number_status(array $number, array $settings, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $heartbeatStale = max(1, (int) whatsapp_monitor_setting_value($settings, 'heartbeat_stale_minutes', '10'));
    $syncStale = max($heartbeatStale, (int) whatsapp_monitor_setting_value($settings, 'sync_stale_minutes', '30'));

    $lastSyncAt = trim((string) ($number['qr_last_seen_at'] ?? ''));
    $lastActivityAt = trim((string) ($number['last_message_at'] ?? ''));
    $qrStatus = trim((string) ($number['qr_status'] ?? 'not_connected'));
    $numberStatus = trim((string) ($number['number_status'] ?? $number['status'] ?? 'active'));
    $lastError = trim((string) ($number['qr_last_error'] ?? ''));

    $payload = static function (string $status, string $reason) use ($lastSyncAt, $lastActivityAt, $number): array {
        return [
            'status' => $status,
            'status_reason' => $reason,
            'last_activity_at' => $lastActivityAt !== '' ? $lastActivityAt : null,
            'last_sync_at' => $lastSyncAt !== '' ? $lastSyncAt : null,
            'unread_count' => max(0, (int) ($number['unread_count'] ?? $number['unanswered_chats'] ?? 0)),
        ];
    };

    if ($numberStatus !== 'active') {
        return $payload('disabled', 'Nomre deaktiv edilib');
    }
    if (in_array($qrStatus, ['not_connected', 'revoked', ''], true)) {
        return $payload('disabled', 'QR sessiyasi qosulmayib');
    }
    if (in_array($qrStatus, ['requested', 'qr_requested', 'qr_ready', 'scanned'], true)) {
        return $payload('sync_delayed', 'QR baglantisi gozlenilir');
    }
    if (in_array($qrStatus, ['expired', 'failed'], true)) {
        return $payload('offline', $lastError !== '' ? 'Sessiya xetasi: ' . $lastError : 'Sessiya bitib ve ya xeta var');
    }

    if ($lastSyncAt === '') {
        return $payload('sync_delayed', 'Heartbeat hele alinmayib');
    }
    try {
        $syncTime = new DateTimeImmutable($lastSyncAt);
    } catch (Throwable) {
        return $payload('sync_delayed', 'Heartbeat vaxti oxuna bilmedi');
    }
    $ageMinutes = max(0, (int) floor(($now->getTimestamp() - $syncTime->getTimestamp()) / 60));
    if ($ageMinutes <= $heartbeatStale) {
        return $payload('online', 'Sessiya aktivdir');
    }
    if ($ageMinutes <= $syncStale) {
        return $payload('sync_delayed', 'Sinxronizasiya gecikir (' . $ageMinutes . ' deq)');
    }
    return $payload('offline', 'Heartbeat kesilib (' . $ageMinutes . ' deq)');
}

/**
 * Number cards with the canonical status payload for the live monitor.
 *
 * @return list<array<string,mixed>>
 */
function whatsapp_monitor_number_status_cards(PDO $db, array $actor, bool $withChats = true): array
{
    $settings = whatsapp_monitor_settings($db);
    $groups = whatsapp_monitor_chat_number_groups($db, $actor);

    $unanswered = [];
    $unansweredRows = $db->query(
        'SELECT t.number_id, COUNT(*) AS unanswered_chats
           FROM (
                SELECT m.number_id, m.chat_id,
                       SUBSTRING_INDEX(GROUP_CONCAT(m.direction ORDER BY m.occurred_at DESC, m.id DESC), ",", 1) AS last_direction
                  FROM whatsapp_monitor_messages m
                 GROUP BY m.number_id, m.chat_id
           ) t
          WHERE t.last_direction = "inbound"
          GROUP BY t.number_id'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($unansweredRows as $row) {
        $unanswered[(int) $row['number_id']] = (int) $row['unanswered_chats'];
    }

    $cards = [];
    foreach ($groups as $group) {
        $numberId = (int) ($group['number_id'] ?? 0);
        $group['unanswered_chats'] = $unanswered[$numberId] ?? 0;
        $cards[] = [
            'number_id' => $numberId,
            'company_number' => (string) ($group['company_number'] ?? ''),
            'employee_name' => (string) ($group['employee_name'] ?? ''),
            'chat_count' => (int) ($group['chat_count'] ?? 0),
            'message_count' => (int) ($group['message_count'] ?? 0),
            'recent_chats' => ($withChats && $numberId > 0)
                ? array_slice(whatsapp_monitor_chat_rows($db, $actor, $numberId), 0, 6)
                : [],
        ] + whatsapp_monitor_number_status($group, $settings);
    }
    return $cards;
}

function whatsapp_monitor_chat_number_groups(PDO $db, array $actor): array
{
    $where = ['n.status = "active"'];
    $params = [];
    if (!whatsapp_monitor_can_view_all($actor)) {
        $where[] = 'n.employee_id = :employee_id';
        $params['employee_id'] = (int) ($actor['id'] ?? 0);
    }

    $statement = $db->prepare(
        'SELECT n.id AS number_id, n.phone_number AS company_number, n.normalized_phone,
                n.qr_status, n.qr_last_seen_at, n.qr_last_error, n.status AS number_status, e.full_name AS employee_name,
                COUNT(DISTINCT m.chat_id) AS chat_count, COUNT(m.id) AS message_count,
                MAX(m.occurred_at) AS last_message_at
         FROM whatsapp_employee_numbers n
         LEFT JOIN portal_accounts e ON e.id = n.employee_id
         LEFT JOIN whatsapp_monitor_messages m ON m.number_id = n.id
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY n.id, n.phone_number, n.normalized_phone, n.qr_status, n.qr_last_seen_at, n.qr_last_error, n.status, e.full_name
         ORDER BY (COUNT(DISTINCT m.chat_id) > 0) DESC, MAX(m.occurred_at) DESC, e.full_name ASC, n.id ASC'
    );
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function whatsapp_monitor_chat_rows(PDO $db, array $actor, int $numberId = 0): array
{
    $where = '1=1';
    $params = [];
    if ($numberId > 0) {
        $where .= ' AND m.number_id = :number_id';
        $params['number_id'] = $numberId;
    }
    if (!whatsapp_monitor_can_view_all($actor)) {
        $where .= ' AND m.employee_id = :employee_id';
        $params['employee_id'] = (int) ($actor['id'] ?? 0);
    }
    $statement = $db->prepare(
        'SELECT m.number_id, m.chat_id, MAX(m.contact_name) AS contact_name, MAX(m.occurred_at) AS last_message_at,
                SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(NULLIF(m.message_body,""),CONCAT("[",m.message_type,"]")) ORDER BY m.occurred_at DESC, m.id DESC SEPARATOR "\n"), "\n", 1) AS last_message,
                COUNT(*) AS message_count, MAX(m.id) AS last_message_id,
                MAX(n.phone_number) AS company_number, MAX(e.full_name) AS employee_name
         FROM whatsapp_monitor_messages m
         LEFT JOIN whatsapp_employee_numbers n ON n.id = m.number_id
         LEFT JOIN portal_accounts e ON e.id = m.employee_id
         WHERE ' . $where . '
         GROUP BY m.number_id, m.chat_id
         ORDER BY last_message_at DESC
         LIMIT 200'
    );
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function whatsapp_monitor_chat_messages(PDO $db, array $actor, string $chatId, int $afterId = 0, int $numberId = 0): array
{
    $where = 'm.chat_id = :chat_id';
    $params = ['chat_id' => $chatId];
    if ($numberId > 0) {
        $where .= ' AND m.number_id = :number_id';
        $params['number_id'] = $numberId;
    }
    if (!whatsapp_monitor_can_view_all($actor)) {
        $where .= ' AND m.employee_id = :employee_id';
        $params['employee_id'] = (int) ($actor['id'] ?? 0);
    }
    if ($afterId > 0) {
        $where .= ' AND m.id > :after_id';
        $params['after_id'] = $afterId;
    }
    $initialLoad = $afterId <= 0;
    $statement = $db->prepare(
        'SELECT m.*, n.phone_number AS company_number, e.full_name AS employee_name
         FROM whatsapp_monitor_messages m
         LEFT JOIN whatsapp_employee_numbers n ON n.id = m.number_id
         LEFT JOIN portal_accounts e ON e.id = m.employee_id
         WHERE ' . $where . '
         ORDER BY m.occurred_at ' . ($initialLoad ? 'DESC' : 'ASC') . ', m.id ' . ($initialLoad ? 'DESC' : 'ASC') . '
         LIMIT 300'
    );
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return $initialLoad ? array_reverse($rows) : $rows;
}

function whatsapp_monitor_devices(PDO $db, array $actor, array $filters = []): array
{
    $where = [];
    $params = [];
    if (!whatsapp_monitor_can_view_all($actor)) {
        $where[] = 'd.employee_id = :employee_id';
        $params['employee_id'] = (int) ($actor['id'] ?? 0);
    }
    $status = clean_text((string) ($filters['status'] ?? ''));
    if (in_array($status, ['online', 'offline', 'warning', 'disabled'], true)) {
        $where[] = 'd.status = :status';
        $params['status'] = $status;
    }
    $area = clean_text((string) ($filters['area'] ?? ''));
    if ($area !== '') {
        $where[] = 'd.area_id = :area';
        $params['area'] = $area;
    }
    $onlyPolicy = !empty($filters['policy_missing']);
    if ($onlyPolicy) {
        $where[] = '(n.monitoring_policy_accepted IS NULL OR n.monitoring_policy_accepted = 0)';
    }
    $sql = 'SELECT d.*, e.full_name AS employee_name, e.login AS employee_login, e.employee_role,
                   n.phone_number, n.normalized_phone, n.monitoring_policy_accepted, n.status AS number_status,
                   (SELECT COUNT(*) FROM whatsapp_monitor_cases c WHERE c.device_id = d.id AND c.status NOT IN ("resolved","ignored")) AS open_case_count
            FROM whatsapp_monitor_devices d
            LEFT JOIN portal_accounts e ON e.id = d.employee_id
            LEFT JOIN whatsapp_employee_numbers n ON n.id = d.phone_number_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY FIELD(d.status, "warning", "offline", "online", "disabled"), d.updated_at DESC, d.id DESC LIMIT 250';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function whatsapp_monitor_cases(PDO $db, array $actor, array $filters = []): array
{
    $where = [];
    $params = [];
    if (!whatsapp_monitor_can_view_all($actor)) {
        $where[] = '(c.employee_id = :employee_id OR c.assigned_to = :employee_id)';
        $params['employee_id'] = (int) ($actor['id'] ?? 0);
    }
    $status = clean_text((string) ($filters['status'] ?? 'open'));
    if ($status === 'open') {
        $where[] = 'c.status NOT IN ("resolved","ignored")';
    } elseif (in_array($status, ['new', 'waiting_employee', 'in_progress', 'replied', 'escalated', 'resolved', 'ignored'], true)) {
        $where[] = 'c.status = :status';
        $params['status'] = $status;
    }
    $sql = 'SELECT c.*, d.device_name, d.device_code, d.status AS device_status,
                   e.full_name AS employee_name, a.full_name AS assigned_name,
                   cust.full_name AS customer_name, cust.subscriber_code, cust.phone AS customer_phone
            FROM whatsapp_monitor_cases c
            LEFT JOIN whatsapp_monitor_devices d ON d.id = c.device_id
            LEFT JOIN portal_accounts e ON e.id = c.employee_id
            LEFT JOIN portal_accounts a ON a.id = c.assigned_to
            LEFT JOIN portal_accounts cust ON cust.id = c.customer_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY FIELD(c.priority, "critical", "high", "normal", "low"), c.opened_at DESC, c.id DESC LIMIT 250';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function whatsapp_monitor_metrics(PDO $db, array $actor): array
{
    $deviceScope = whatsapp_monitor_can_view_all($actor) ? '1=1' : 'employee_id = ' . (int) ($actor['id'] ?? 0);
    $caseScope = whatsapp_monitor_can_view_all($actor)
        ? '1=1'
        : '(employee_id = ' . (int) ($actor['id'] ?? 0) . ' OR assigned_to = ' . (int) ($actor['id'] ?? 0) . ')';
    $scalar = static function (string $sql) use ($db): int {
        try {
            return (int) ($db->query($sql)->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    };
    $avg = '0';
    try {
        $avg = (string) round((float) ($db->query(
            'SELECT AVG(response_time_minutes) FROM whatsapp_monitor_cases WHERE response_time_minutes IS NOT NULL AND ' . $caseScope
        )->fetchColumn() ?: 0), 1);
    } catch (Throwable) {
    }
    return [
        'online_devices' => $scalar('SELECT COUNT(*) FROM whatsapp_monitor_devices WHERE status = "online" AND ' . $deviceScope),
        'offline_devices' => $scalar('SELECT COUNT(*) FROM whatsapp_monitor_devices WHERE status IN ("offline","warning") AND ' . $deviceScope),
        'active_numbers' => $scalar('SELECT COUNT(*) FROM whatsapp_employee_numbers WHERE status = "active" AND ' . (whatsapp_monitor_can_view_all($actor) ? '1=1' : 'employee_id = ' . (int) ($actor['id'] ?? 0))),
        'qr_connected_numbers' => $scalar('SELECT COUNT(*) FROM whatsapp_employee_numbers WHERE qr_status = "connected" AND ' . (whatsapp_monitor_can_view_all($actor) ? '1=1' : 'employee_id = ' . (int) ($actor['id'] ?? 0))),
        'qr_pending_numbers' => $scalar('SELECT COUNT(*) FROM whatsapp_employee_numbers WHERE qr_status IN ("qr_requested","qr_ready","scanned") AND ' . (whatsapp_monitor_can_view_all($actor) ? '1=1' : 'employee_id = ' . (int) ($actor['id'] ?? 0))),
        'open_cases' => $scalar('SELECT COUNT(*) FROM whatsapp_monitor_cases WHERE status NOT IN ("resolved","ignored") AND ' . $caseScope),
        'critical_cases' => $scalar('SELECT COUNT(*) FROM whatsapp_monitor_cases WHERE priority = "critical" AND status NOT IN ("resolved","ignored") AND ' . $caseScope),
        'tickets_created' => $scalar('SELECT COUNT(*) FROM whatsapp_monitor_cases WHERE ticket_id IS NOT NULL AND DATE(created_at) = CURDATE() AND ' . $caseScope),
        'avg_response_minutes' => $avg,
    ];
}

function whatsapp_monitor_save_number(PDO $db, array $payload, array $actor): array
{
    if (!whatsapp_monitor_can_manage($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    $id = (int) ($payload['id'] ?? 0);
    $phone = clean_text((string) ($payload['phone_number'] ?? ''));
    $normalized = whatsapp_monitor_normalize_phone($phone);
    if ($normalized === '') {
        return ['ok' => false, 'error' => 'phone_required'];
    }
    $employeeId = (int) ($payload['employee_id'] ?? 0);
    $activeCheck = $db->prepare(
        'SELECT id FROM whatsapp_employee_numbers
         WHERE normalized_phone = :normalized_phone AND status = "active" AND id <> :id
         LIMIT 1'
    );
    $activeCheck->execute(['normalized_phone' => $normalized, 'id' => $id]);
    if ($activeCheck->fetchColumn()) {
        return ['ok' => false, 'error' => 'phone_already_active'];
    }
    $record = [
        'employee_id' => $employeeId > 0 ? $employeeId : null,
        'phone_number' => $phone,
        'normalized_phone' => $normalized,
        'whatsapp_account_name' => clean_text((string) ($payload['whatsapp_account_name'] ?? '')),
        'assigned_area_id' => clean_text((string) ($payload['assigned_area_id'] ?? '')),
        'status' => in_array((string) ($payload['status'] ?? 'active'), ['active', 'inactive', 'returned', 'lost', 'blocked'], true) ? (string) $payload['status'] : 'active',
        'is_company_owned' => !empty($payload['is_company_owned']) ? 1 : 0,
        'monitoring_policy_accepted' => !empty($payload['monitoring_policy_accepted']) ? 1 : 0,
        'policy_version' => clean_text((string) ($payload['policy_version'] ?? '2026-07')),
        'accepted_by' => !empty($payload['monitoring_policy_accepted']) ? ((int) ($actor['id'] ?? 0) ?: null) : null,
        'notes' => trim((string) ($payload['notes'] ?? '')),
    ];
    $old = [];
    if ($id > 0) {
        $oldStatement = $db->prepare('SELECT * FROM whatsapp_employee_numbers WHERE id = :id LIMIT 1');
        $oldStatement->execute(['id' => $id]);
        $old = $oldStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if ($id > 0 && $old !== []) {
        $statement = $db->prepare(
            'UPDATE whatsapp_employee_numbers
             SET employee_id = :employee_id, phone_number = :phone_number, normalized_phone = :normalized_phone,
                 whatsapp_account_name = :whatsapp_account_name, assigned_area_id = :assigned_area_id,
                 assigned_at = COALESCE(assigned_at, NOW()), status = :status, is_company_owned = :is_company_owned,
                 monitoring_policy_accepted = :monitoring_policy_accepted, policy_version = :policy_version,
                 accepted_at = CASE WHEN :monitoring_policy_accepted = 1 AND accepted_at IS NULL THEN NOW() ELSE accepted_at END,
                 accepted_by = COALESCE(:accepted_by, accepted_by), notes = :notes
             WHERE id = :id'
        );
        $record['id'] = $id;
        $statement->execute($record);
    } else {
        $statement = $db->prepare(
            'INSERT INTO whatsapp_employee_numbers (
                employee_id, phone_number, normalized_phone, whatsapp_account_name, assigned_area_id, assigned_at,
                status, is_company_owned, monitoring_policy_accepted, policy_version, accepted_at, accepted_by, notes
             ) VALUES (
                :employee_id, :phone_number, :normalized_phone, :whatsapp_account_name, :assigned_area_id, NOW(),
                :status, :is_company_owned, :monitoring_policy_accepted, :policy_version,
                CASE WHEN :monitoring_policy_accepted = 1 THEN NOW() ELSE NULL END, :accepted_by, :notes
             )'
        );
        $statement->execute($record);
        $id = (int) $db->lastInsertId();
    }
    whatsapp_monitor_audit($db, $actor, $old === [] ? 'number_created' : 'number_updated', 'number', $id, $old, $record);
    whatsapp_monitor_event($db, $actor, 'policy_' . (!empty($record['monitoring_policy_accepted']) ? 'accepted' : 'pending'), 0, $employeeId, ['number_id' => $id]);
    return ['ok' => true, 'id' => $id];
}

function whatsapp_monitor_return_number(PDO $db, int $id, array $actor): array
{
    if (!whatsapp_monitor_can_manage($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    $oldStatement = $db->prepare('SELECT * FROM whatsapp_employee_numbers WHERE id = :id LIMIT 1');
    $oldStatement->execute(['id' => $id]);
    $old = $oldStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($old)) {
        return ['ok' => false, 'error' => 'number_not_found'];
    }
    $db->prepare('UPDATE whatsapp_employee_numbers SET status = "returned", returned_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    whatsapp_monitor_audit($db, $actor, 'number_returned', 'number', $id, $old, ['status' => 'returned']);
    return ['ok' => true];
}

function whatsapp_monitor_save_device(PDO $db, array $payload, array $actor): array
{
    if (!whatsapp_monitor_can_manage($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    $id = (int) ($payload['id'] ?? 0);
    $deviceName = clean_text((string) ($payload['device_name'] ?? ''));
    $deviceCode = clean_text((string) ($payload['device_code'] ?? ''));
    if ($deviceName === '' || $deviceCode === '') {
        return ['ok' => false, 'error' => 'device_required'];
    }
    $record = [
        'device_name' => $deviceName,
        'device_code' => $deviceCode,
        'employee_id' => (int) ($payload['employee_id'] ?? 0) ?: null,
        'phone_number_id' => (int) ($payload['phone_number_id'] ?? 0) ?: null,
        'area_id' => clean_text((string) ($payload['area_id'] ?? '')),
        'device_type' => in_array((string) ($payload['device_type'] ?? 'android'), ['android', 'iphone', 'web', 'other'], true) ? (string) $payload['device_type'] : 'android',
        'connection_type' => in_array((string) ($payload['connection_type'] ?? 'stream_url'), ['usb', 'tcpip', 'stream_url', 'mdm', 'manual'], true) ? (string) $payload['connection_type'] : 'stream_url',
        'stream_url' => trim((string) ($payload['stream_url'] ?? '')),
        'status' => in_array((string) ($payload['status'] ?? 'offline'), ['online', 'offline', 'warning', 'disabled'], true) ? (string) $payload['status'] : 'offline',
        'monitoring_enabled' => !empty($payload['monitoring_enabled']) ? 1 : 0,
        'last_error' => clean_text((string) ($payload['last_error'] ?? '')),
        'notes' => trim((string) ($payload['notes'] ?? '')),
        'updated_by' => (int) ($actor['id'] ?? 0) ?: null,
    ];
    $old = [];
    if ($id > 0) {
        $oldStatement = $db->prepare('SELECT * FROM whatsapp_monitor_devices WHERE id = :id LIMIT 1');
        $oldStatement->execute(['id' => $id]);
        $old = $oldStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if ($id > 0 && $old !== []) {
        $statement = $db->prepare(
            'UPDATE whatsapp_monitor_devices
             SET device_name = :device_name, device_code = :device_code, employee_id = :employee_id,
                 phone_number_id = :phone_number_id, area_id = :area_id, device_type = :device_type,
                 connection_type = :connection_type, stream_url = :stream_url, status = :status,
                 monitoring_enabled = :monitoring_enabled, last_seen_at = CASE WHEN :status = "online" THEN NOW() ELSE last_seen_at END,
                 last_stream_at = CASE WHEN :stream_url <> "" THEN NOW() ELSE last_stream_at END,
                 last_error = :last_error, notes = :notes, updated_by = :updated_by
             WHERE id = :id'
        );
        $record['id'] = $id;
        $statement->execute($record);
    } else {
        $statement = $db->prepare(
            'INSERT INTO whatsapp_monitor_devices (
                device_name, device_code, employee_id, phone_number_id, area_id, device_type, connection_type,
                stream_url, status, monitoring_enabled, last_seen_at, last_stream_at, last_error, notes, created_by, updated_by
             ) VALUES (
                :device_name, :device_code, :employee_id, :phone_number_id, :area_id, :device_type, :connection_type,
                :stream_url, :status, :monitoring_enabled,
                CASE WHEN :status = "online" THEN NOW() ELSE NULL END,
                CASE WHEN :stream_url <> "" THEN NOW() ELSE NULL END,
                :last_error, :notes, :created_by, :updated_by
             )'
        );
        $record['created_by'] = (int) ($actor['id'] ?? 0) ?: null;
        $statement->execute($record);
        $id = (int) $db->lastInsertId();
    }
    whatsapp_monitor_audit($db, $actor, $old === [] ? 'device_created' : 'device_updated', 'device', $id, $old, $record);
    whatsapp_monitor_event($db, $actor, 'device_' . (string) $record['status'], $id, (int) ($record['employee_id'] ?? 0), ['device_code' => $deviceCode]);
    return ['ok' => true, 'id' => $id];
}

function whatsapp_monitor_disable_device(PDO $db, int $id, array $actor): array
{
    if (!whatsapp_monitor_can_manage($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    $oldStatement = $db->prepare('SELECT * FROM whatsapp_monitor_devices WHERE id = :id LIMIT 1');
    $oldStatement->execute(['id' => $id]);
    $old = $oldStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($old)) {
        return ['ok' => false, 'error' => 'device_not_found'];
    }
    $db->prepare('UPDATE whatsapp_monitor_devices SET status = "disabled", monitoring_enabled = 0, updated_by = :actor WHERE id = :id')->execute([
        'id' => $id,
        'actor' => (int) ($actor['id'] ?? 0) ?: null,
    ]);
    whatsapp_monitor_audit($db, $actor, 'device_disabled', 'device', $id, $old, ['status' => 'disabled']);
    whatsapp_monitor_event($db, $actor, 'device_disabled', $id, (int) ($old['employee_id'] ?? 0));
    return ['ok' => true];
}

function whatsapp_monitor_device_can_be_used(PDO $db, int $deviceId, array $actor): ?array
{
    $statement = $db->prepare('SELECT * FROM whatsapp_monitor_devices WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $deviceId]);
    $device = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($device)) {
        return null;
    }
    if (whatsapp_monitor_can_view_all($actor) || (int) ($device['employee_id'] ?? 0) === (int) ($actor['id'] ?? 0)) {
        return $device;
    }
    return null;
}

function whatsapp_monitor_create_case(PDO $db, array $payload, array $actor): array
{
    $deviceId = (int) ($payload['device_id'] ?? 0);
    $device = whatsapp_monitor_device_can_be_used($db, $deviceId, $actor);
    if ($deviceId <= 0 || $device === null) {
        return ['ok' => false, 'error' => 'device_not_allowed'];
    }
    $caseType = (string) ($payload['case_type'] ?? 'unanswered');
    $priority = (string) ($payload['priority'] ?? 'normal');
    $record = [
        'device_id' => $deviceId,
        'employee_id' => (int) ($device['employee_id'] ?? 0) ?: null,
        'case_type' => in_array($caseType, ['unanswered', 'complaint', 'payment', 'technical', 'sales', 'unknown'], true) ? $caseType : 'unanswered',
        'status' => 'new',
        'priority' => in_array($priority, ['low', 'normal', 'high', 'critical'], true) ? $priority : 'normal',
        'opened_by' => (int) ($actor['id'] ?? 0) ?: null,
        'assigned_to' => (int) ($payload['assigned_to'] ?? 0) ?: ((int) ($device['employee_id'] ?? 0) ?: null),
        'notes' => trim((string) ($payload['notes'] ?? '')),
    ];
    $statement = $db->prepare(
        'INSERT INTO whatsapp_monitor_cases (device_id, employee_id, case_type, status, priority, opened_by, assigned_to, notes)
         VALUES (:device_id, :employee_id, :case_type, :status, :priority, :opened_by, :assigned_to, :notes)'
    );
    $statement->execute($record);
    $id = (int) $db->lastInsertId();
    whatsapp_monitor_audit($db, $actor, 'case_created', 'case', $id, [], $record);
    whatsapp_monitor_event($db, $actor, 'case_created', $deviceId, (int) ($record['employee_id'] ?? 0), ['case_id' => $id]);
    return ['ok' => true, 'id' => $id];
}

function whatsapp_monitor_case_for_actor(PDO $db, int $caseId, array $actor): ?array
{
    $statement = $db->prepare('SELECT * FROM whatsapp_monitor_cases WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $caseId]);
    $case = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        return null;
    }
    if (whatsapp_monitor_can_view_all($actor)
        || (int) ($case['employee_id'] ?? 0) === (int) ($actor['id'] ?? 0)
        || (int) ($case['assigned_to'] ?? 0) === (int) ($actor['id'] ?? 0)) {
        return $case;
    }
    return null;
}

function whatsapp_monitor_update_case_status(PDO $db, int $caseId, string $status, array $actor): array
{
    $case = whatsapp_monitor_case_for_actor($db, $caseId, $actor);
    if ($case === null) {
        return ['ok' => false, 'error' => 'case_not_allowed'];
    }
    if (!in_array($status, ['waiting_employee', 'in_progress', 'replied', 'escalated', 'resolved', 'ignored'], true)) {
        return ['ok' => false, 'error' => 'bad_status'];
    }
    $fields = ['status = :status'];
    $params = ['id' => $caseId, 'status' => $status];
    if ($status === 'replied') {
        $fields[] = 'replied_at = COALESCE(replied_at, NOW())';
        $fields[] = 'response_time_minutes = COALESCE(response_time_minutes, TIMESTAMPDIFF(MINUTE, opened_at, NOW()))';
    }
    if ($status === 'resolved') {
        $fields[] = 'resolved_at = COALESCE(resolved_at, NOW())';
    }
    $db->prepare('UPDATE whatsapp_monitor_cases SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
    whatsapp_monitor_audit($db, $actor, 'case_status_updated', 'case', $caseId, $case, ['status' => $status]);
    whatsapp_monitor_event($db, $actor, 'case_' . $status, (int) ($case['device_id'] ?? 0), (int) ($case['employee_id'] ?? 0), ['case_id' => $caseId]);
    return ['ok' => true];
}

function whatsapp_monitor_link_customer(PDO $db, int $caseId, string $query, array $actor): array
{
    $case = whatsapp_monitor_case_for_actor($db, $caseId, $actor);
    if ($case === null) {
        return ['ok' => false, 'error' => 'case_not_allowed'];
    }
    $customer = app_db_customer_lookup_for_ticket(0, $query, $query, $query);
    if (!is_array($customer)) {
        return ['ok' => false, 'error' => 'customer_not_found'];
    }
    $db->prepare('UPDATE whatsapp_monitor_cases SET customer_id = :customer_id WHERE id = :id')->execute([
        'id' => $caseId,
        'customer_id' => (int) ($customer['id'] ?? 0),
    ]);
    whatsapp_monitor_audit($db, $actor, 'customer_linked', 'case', $caseId, $case, ['customer_id' => (int) ($customer['id'] ?? 0)]);
    whatsapp_monitor_event($db, $actor, 'customer_linked', (int) ($case['device_id'] ?? 0), (int) ($case['employee_id'] ?? 0), ['case_id' => $caseId, 'customer_id' => (int) ($customer['id'] ?? 0)]);
    return ['ok' => true, 'customer' => $customer];
}

function whatsapp_monitor_create_ticket(PDO $db, int $caseId, array $actor): array
{
    $case = whatsapp_monitor_case_for_actor($db, $caseId, $actor);
    if ($case === null) {
        return ['ok' => false, 'error' => 'case_not_allowed'];
    }
    if (!empty($case['ticket_id'])) {
        return ['ok' => true, 'ticket_no' => (string) ($case['ticket_no'] ?? '')];
    }
    $customer = null;
    if ((int) ($case['customer_id'] ?? 0) > 0) {
        $customer = app_db_account_by_id((int) $case['customer_id']);
    }
    if (!is_array($customer) || (string) ($customer['role'] ?? '') !== 'customer') {
        return ['ok' => false, 'error' => 'customer_required'];
    }
    $subject = match ((string) ($case['case_type'] ?? 'unknown')) {
        'technical' => 'Texniki dəstək',
        'payment' => 'Odenis meselesi',
        'sales' => 'Satis muracieti',
        'complaint' => 'Sikayet',
        default => 'Texniki dəstək',
    };
    if (!in_array($subject, app_portal_ticket_subjects(), true)) {
        $subject = 'Texniki dəstək';
    }
    $ticketNo = app_next_ticket_id();
    $ticket = [
        'ticket_no' => $ticketNo,
        'customer_account_id' => (int) ($customer['id'] ?? 0),
        'subscriber_code' => (string) ($customer['subscriber_code'] ?? ''),
        'contract_no' => (string) ($customer['contract_no'] ?? ''),
        'customer_name' => (string) ($customer['full_name'] ?? ''),
        'phone' => (string) ($customer['phone'] ?? ''),
        'address' => (string) ($customer['address'] ?? ''),
        'area' => (string) ($customer['area'] ?? ''),
        'subject' => $subject,
        'message' => mb_substr('WhatsApp Monitor case #' . $caseId . ': ' . (string) ($case['notes'] ?? ''), 0, 160),
        'status' => 'Aciqdir',
        'opened_at' => app_db_now(),
        'updated_at' => app_db_now(),
        'resolved_at' => null,
        'assigned_technician_account_id' => (int) ($case['assigned_to'] ?? 0) ?: null,
        'assigned_technician_name' => '',
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'created_by_role' => 'employee',
        'created_by_name' => whatsapp_monitor_actor_name($actor),
        'telegram_message_ids_json' => '[]',
        'metadata' => ['whatsapp_monitor_case_id' => $caseId],
    ];
    if (!app_db_insert_ticket($ticket)) {
        return ['ok' => false, 'error' => 'ticket_create_failed'];
    }
    $ticketRow = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
    $ticketRow->execute(['ticket_no' => $ticketNo]);
    $ticketId = (int) ($ticketRow->fetchColumn() ?: 0);
    $db->prepare('UPDATE whatsapp_monitor_cases SET ticket_id = :ticket_id, ticket_no = :ticket_no WHERE id = :id')->execute([
        'id' => $caseId,
        'ticket_id' => $ticketId > 0 ? $ticketId : null,
        'ticket_no' => $ticketNo,
    ]);
    whatsapp_monitor_audit($db, $actor, 'ticket_created', 'case', $caseId, $case, ['ticket_no' => $ticketNo, 'ticket_id' => $ticketId]);
    whatsapp_monitor_event($db, $actor, 'ticket_created', (int) ($case['device_id'] ?? 0), (int) ($case['employee_id'] ?? 0), ['case_id' => $caseId, 'ticket_no' => $ticketNo]);
    return ['ok' => true, 'ticket_no' => $ticketNo];
}

function whatsapp_monitor_save_settings(PDO $db, array $payload, array $actor): array
{
    if (!whatsapp_monitor_can_manage($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    $settings = whatsapp_monitor_settings($db);
    $allowed = array_keys($settings);
    $statement = $db->prepare(
        'UPDATE whatsapp_monitor_settings
         SET setting_value = :setting_value, updated_by = :updated_by
         WHERE setting_key = :setting_key'
    );
    $changed = 0;
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }
        $type = (string) ($settings[$key]['value_type'] ?? 'string');
        $value = trim((string) $payload[$key]);
        if ($type === 'int') {
            $value = (string) max(1, (int) $value);
        } elseif ($type === 'bool') {
            $value = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
        }
        $statement->execute([
            'setting_key' => $key,
            'setting_value' => $value,
            'updated_by' => (int) ($actor['id'] ?? 0) ?: null,
        ]);
        $changed += $statement->rowCount() >= 0 ? 1 : 0;
    }
    whatsapp_monitor_audit($db, $actor, 'settings_updated', 'settings', 'global', [], ['keys' => $allowed]);
    return ['ok' => true, 'changed' => $changed];
}

function whatsapp_monitor_audit_rows(PDO $db, array $actor): array
{
    if (!whatsapp_monitor_can_view_all($actor)) {
        return [];
    }
    $rows = $db->query(
        'SELECT a.*, u.full_name AS actor_name
         FROM whatsapp_monitor_audit_logs a
         LEFT JOIN portal_accounts u ON u.id = a.user_id
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 150'
    )->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function whatsapp_monitor_context(PDO $db, array $actor, string $view, string $lang): array
{
    whatsapp_monitor_ensure_schema($db);
    $settings = whatsapp_monitor_settings($db);
    $chatNumberGroups = $view === 'chats' ? whatsapp_monitor_chat_number_groups($db, $actor) : [];
    $selectedChatId = clean_text((string) ($_GET['chat_id'] ?? ''));
    $selectedNumberId = max(0, (int) ($_GET['number_id'] ?? 0));
    $allowedNumberIds = array_map(static fn (array $row): int => (int) ($row['number_id'] ?? 0), $chatNumberGroups);
    if ($selectedNumberId <= 0 || !in_array($selectedNumberId, $allowedNumberIds, true)) {
        $selectedNumberId = (int) ($chatNumberGroups[0]['number_id'] ?? 0);
    }
    $chatRows = $view === 'chats' && $selectedNumberId > 0
        ? whatsapp_monitor_chat_rows($db, $actor, $selectedNumberId)
        : [];
    $chatSelectionExplicit = $selectedChatId !== '' && $selectedNumberId > 0;
    $selectedChat = null;
    foreach ($chatRows as $chatRow) {
        if ((string) ($chatRow['chat_id'] ?? '') === $selectedChatId
            && (int) ($chatRow['number_id'] ?? 0) === $selectedNumberId) {
            $selectedChat = $chatRow;
            break;
        }
    }
    if ($selectedChat === null && $chatRows !== []) {
        $selectedChat = $chatRows[0];
        $selectedChatId = (string) ($selectedChat['chat_id'] ?? '');
        $selectedNumberId = (int) ($selectedChat['number_id'] ?? 0);
    }
    $menuTabs = whatsapp_monitor_tabs($db, $actor);
    if (!whatsapp_monitor_stage4_enabled($db, $actor)
        && function_exists('app_stage3_workspaces_enabled')
        && app_stage3_workspaces_enabled($db, $actor)) {
        // Stage 3: screen-wall stays reachable by direct URL but leaves the menu.
        unset($menuTabs['screen-wall']);
    }
    return [
        'view' => $view,
        'tabs' => $menuTabs,
        'stage4' => whatsapp_monitor_stage4_enabled($db, $actor),
        'number_status_cards' => in_array($view, ['live-monitor', 'overview'], true)
            ? whatsapp_monitor_number_status_cards($db, $actor)
            : [],
        'lang' => $lang,
        'settings' => $settings,
        'can_manage' => whatsapp_monitor_can_manage($actor),
        'can_view_all' => whatsapp_monitor_can_view_all($actor),
        'employees' => whatsapp_monitor_employees($db),
        'numbers' => whatsapp_monitor_numbers($db, $actor),
        'pairings' => whatsapp_monitor_pairings($db, $actor, $_GET),
        'devices' => whatsapp_monitor_devices($db, $actor, $_GET),
        'cases' => whatsapp_monitor_cases($db, $actor, $_GET),
        'chat_number_groups' => $chatNumberGroups,
        'chat_rows' => $chatRows,
        'selected_number_id' => $selectedNumberId,
        'selected_chat_id' => $selectedChatId,
        'selected_chat' => $selectedChat,
        'chat_selection_explicit' => $chatSelectionExplicit,
        'chat_messages' => $view === 'chats' && $selectedChatId !== '' && $selectedNumberId > 0
            ? whatsapp_monitor_chat_messages($db, $actor, $selectedChatId, 0, $selectedNumberId)
            : [],
        'metrics' => whatsapp_monitor_metrics($db, $actor),
        'audit_rows' => $view === 'audit' ? whatsapp_monitor_audit_rows($db, $actor) : [],
    ];
}
