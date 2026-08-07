<?php
declare(strict_types=1);

const APP_HR_APPEALS_MIGRATION_20260807_270 = '20260807_270_hr_score_appeals_and_mass_incident_rule';

return [
    'version' => APP_HR_APPEALS_MIGRATION_20260807_270,
    'description' => 'Add hr_score_appeals table (vision 9.3 "KPI-ye etiraz mexanizmi") and seed the mass-incident daily ticket threshold business rule',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS hr_score_appeals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                score_event_id BIGINT UNSIGNED NOT NULL,
                employee_account_id BIGINT UNSIGNED NOT NULL,
                appeal_reason VARCHAR(500) NOT NULL,
                status ENUM('pending','upheld','overturned') NOT NULL DEFAULT 'pending',
                reviewed_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                reviewed_by_name VARCHAR(191) DEFAULT NULL,
                resolution_note VARCHAR(500) DEFAULT NULL,
                reversal_score_event_id BIGINT UNSIGNED DEFAULT NULL,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_by_name VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME DEFAULT NULL,
                KEY idx_hr_score_appeals_event (score_event_id),
                KEY idx_hr_score_appeals_employee (employee_account_id, status),
                KEY idx_hr_score_appeals_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $insert = $db->prepare(
            'INSERT IGNORE INTO app_business_rules (
                rule_key, category, value_type, value_text, min_value, max_value, title, description, updated_by_name
             ) VALUES (
                :rule_key, :category, :value_type, :value_text, :min_value, :max_value, :title, :description, "migration"
             )'
        );
        $insert->execute([
            'rule_key' => 'hr_mass_incident_daily_ticket_threshold',
            'category' => 'hr',
            'value_type' => 'int',
            'value_text' => '30',
            'min_value' => 5,
            'max_value' => 500,
            'title' => 'Kutlevi insident - gunluk bilet esiyi',
            'description' => 'Bu qeder ve daha cox bilet eyni gun acilarsa, o gunku task_missed cezalari "kutlevi sebeke incidenti" kimi avtomatik bloklanir.',
        ]);
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS hr_score_appeals');
        $db->prepare('DELETE FROM app_business_rules WHERE rule_key = :rule_key')->execute(['rule_key' => 'hr_mass_incident_daily_ticket_threshold']);
    },
];
