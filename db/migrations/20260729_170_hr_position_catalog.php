<?php
declare(strict_types=1);

const APP_HR_POSITION_MIGRATION_20260729_170 = '20260729_170_hr_position_catalog';

return [
    'version' => APP_HR_POSITION_MIGRATION_20260729_170,
    'description' => 'Create the HR position catalog and admin-managed module permission templates',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS hr_positions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                position_key VARCHAR(96) NOT NULL,
                title VARCHAR(191) NOT NULL,
                description VARCHAR(500) DEFAULT NULL,
                recommended_account_type VARCHAR(32) NOT NULL DEFAULT 'operator',
                recommended_employee_role VARCHAR(64) NOT NULL DEFAULT '',
                module_keys_json LONGTEXT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                updated_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_hr_positions_key (position_key),
                UNIQUE KEY uq_hr_positions_title (title),
                KEY idx_hr_positions_active (is_active, title)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $roleModules = [
            'finance' => ['finance', 'billing', 'statistics'],
            'warehouse' => ['inventory'],
            'warehouse_manager' => ['inventory'],
            'hr' => ['hr', 'statistics'],
            'operator' => ['billing', 'messaging', 'whatsapp_monitor', 'statistics'],
            'night_operator' => ['billing', 'messaging', 'whatsapp_monitor', 'statistics'],
            'support' => ['billing', 'messaging', 'whatsapp_monitor', 'statistics', 'network', 'ai'],
            'technician' => ['inventory', 'network', 'whatsapp_monitor'],
            'engineer' => ['inventory', 'network', 'whatsapp_monitor'],
            'noc' => ['messaging', 'whatsapp_monitor', 'statistics', 'network', 'ai'],
            'sales' => ['finance', 'billing', 'messaging', 'whatsapp_monitor', 'statistics'],
        ];
        $allowedRoles = array_keys($roleModules);

        $rows = $db->query(
            "SELECT TRIM(job_title) AS title,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(NULLIF(TRIM(employee_role), '') ORDER BY is_active DESC, id SEPARATOR ','),
                        ',',
                        1
                    ) AS employee_role,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(NULLIF(TRIM(account_type), '') ORDER BY is_active DESC, id SEPARATOR ','),
                        ',',
                        1
                    ) AS account_type
               FROM portal_accounts
              WHERE role = 'employee' AND TRIM(COALESCE(job_title, '')) <> ''
              GROUP BY TRIM(job_title)
              ORDER BY title"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $insert = $db->prepare(
            'INSERT IGNORE INTO hr_positions
                (position_key, title, recommended_account_type, recommended_employee_role, module_keys_json)
             VALUES
                (:position_key, :title, :account_type, :employee_role, :module_keys_json)'
        );
        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $role = strtolower(trim((string) ($row['employee_role'] ?? '')));
            if (!in_array($role, $allowedRoles, true)) {
                $role = '';
            }
            $accountType = strtolower(trim((string) ($row['account_type'] ?? 'operator')));
            if (!in_array($accountType, ['operator', 'technician', 'admin', 'support'], true)) {
                $accountType = 'operator';
            }
            $keyBase = strtolower($title);
            $keyBase = preg_replace('/[^a-z0-9]+/', '_', $keyBase) ?: '';
            $keyBase = trim($keyBase, '_');
            $positionKey = $keyBase !== ''
                ? substr($keyBase, 0, 72) . '_' . substr(hash('sha256', $title), 0, 8)
                : 'position_' . substr(hash('sha256', $title), 0, 16);
            $insert->execute([
                'position_key' => $positionKey,
                'title' => $title,
                'account_type' => $accountType,
                'employee_role' => $role,
                'module_keys_json' => json_encode($roleModules[$role] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS hr_positions');
    },
];
