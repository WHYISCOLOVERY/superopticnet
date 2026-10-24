<?php
declare(strict_types=1);

const APP_MESSAGE_TEMPLATE_VERSIONING_MIGRATION_20260809_340 = '20260809_340_message_template_editing_and_versioning';

return [
    'version' => APP_MESSAGE_TEMPLATE_VERSIONING_MIGRATION_20260809_340,
    'description' => 'Add version/updated_by tracking to communication_templates and a communication_template_history table -- there was no template EDIT capability at all before this (docs/38 3.6)',
    'up' => static function (PDO $db): void {
        $columns = [];
        $columnsResult = $db->query('SHOW COLUMNS FROM communication_templates');
        if ($columnsResult !== false) {
            foreach ($columnsResult->fetchAll(PDO::FETCH_ASSOC) as $columnRow) {
                $columns[] = (string) ($columnRow['Field'] ?? '');
            }
        }
        if (!in_array('version', $columns, true)) {
            $db->exec('ALTER TABLE communication_templates ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status');
        }
        if (!in_array('updated_by_account_id', $columns, true)) {
            $db->exec('ALTER TABLE communication_templates ADD COLUMN updated_by_account_id BIGINT UNSIGNED DEFAULT NULL AFTER version');
        }
        if (!in_array('updated_by_name', $columns, true)) {
            $db->exec('ALTER TABLE communication_templates ADD COLUMN updated_by_name VARCHAR(191) DEFAULT NULL AFTER updated_by_account_id');
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS communication_template_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                template_id BIGINT UNSIGNED NOT NULL,
                template_code VARCHAR(64) NOT NULL,
                version INT UNSIGNED NOT NULL,
                previous_title VARCHAR(191) NOT NULL DEFAULT '',
                previous_body TEXT NOT NULL,
                previous_status ENUM('active','disabled') NOT NULL DEFAULT 'active',
                new_title VARCHAR(191) NOT NULL DEFAULT '',
                new_body TEXT NOT NULL,
                new_status ENUM('active','disabled') NOT NULL DEFAULT 'active',
                reason VARCHAR(255) NOT NULL,
                changed_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                changed_by_name VARCHAR(191) DEFAULT NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_communication_template_history_template (template_id, version),
                KEY idx_communication_template_history_code (template_code, changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS communication_template_history');
        $db->exec('ALTER TABLE communication_templates DROP COLUMN version, DROP COLUMN updated_by_account_id, DROP COLUMN updated_by_name');
    },
];
