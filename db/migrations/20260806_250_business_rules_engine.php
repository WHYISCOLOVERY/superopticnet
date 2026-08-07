<?php
declare(strict_types=1);

const APP_BUSINESS_RULES_MIGRATION_20260806_250 = '20260806_250_business_rules_engine';

return [
    'version' => APP_BUSINESS_RULES_MIGRATION_20260806_250,
    'description' => 'Create a versioned, audited business-rules table (starts with 3 previously-hardcoded thresholds) plus its change-history table',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS app_business_rules (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_key VARCHAR(96) NOT NULL,
                category VARCHAR(64) NOT NULL DEFAULT 'general',
                value_type ENUM('int','decimal','string','bool') NOT NULL DEFAULT 'int',
                value_text VARCHAR(255) NOT NULL,
                min_value DECIMAL(14,4) DEFAULT NULL,
                max_value DECIMAL(14,4) DEFAULT NULL,
                title VARCHAR(191) NOT NULL,
                description VARCHAR(500) DEFAULT NULL,
                updated_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                updated_by_name VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_app_business_rules_key (rule_key),
                KEY idx_app_business_rules_category (category, rule_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS app_business_rule_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_key VARCHAR(96) NOT NULL,
                previous_value_text VARCHAR(255) DEFAULT NULL,
                new_value_text VARCHAR(255) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                is_rollback TINYINT(1) NOT NULL DEFAULT 0,
                changed_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                changed_by_name VARCHAR(191) DEFAULT NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_app_business_rule_history_key (rule_key, changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $seed = [
            [
                'rule_key' => 'churn_inactive_threshold_days',
                'category' => 'ai',
                'value_type' => 'int',
                'value_text' => '45',
                'min_value' => 7,
                'max_value' => 365,
                'title' => 'Churn riski - hereketsizlik hesabatı (gun)',
                'description' => 'Bu qeder gun erzinde odenis etmeyen aktiv musteri "churn riski" kimi CEO/maliyye agent hesabatinda gorunur.',
            ],
            [
                'rule_key' => 'billing_risky_service_window_days',
                'category' => 'billing',
                'value_type' => 'int',
                'value_text' => '7',
                'min_value' => 1,
                'max_value' => 60,
                'title' => 'Riskli xidmet penceresi (gun)',
                'description' => 'Bu qeder gun erzinde bitecek/artiq bitmis xidmetler Billing panelinde "riskli" kimi qeyd olunur ve xeberdarliq siyahisina dusur.',
            ],
            [
                'rule_key' => 'campaign_expiring_soon_days',
                'category' => 'marketing',
                'value_type' => 'int',
                'value_text' => '2',
                'min_value' => 1,
                'max_value' => 30,
                'title' => 'Kampaniya - "tezlikle bitir" seqmenti (gun)',
                'description' => 'Xidmeti bu qeder gun erzinde bitecek musteriler kampaniya "tezlikle bitir" seqmentine daxil edilir.',
            ],
        ];
        $insert = $db->prepare(
            'INSERT IGNORE INTO app_business_rules (
                rule_key, category, value_type, value_text, min_value, max_value, title, description, updated_by_name
             ) VALUES (
                :rule_key, :category, :value_type, :value_text, :min_value, :max_value, :title, :description, "migration"
             )'
        );
        foreach ($seed as $rule) {
            $insert->execute($rule);
        }
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS app_business_rule_history');
        $db->exec('DROP TABLE IF EXISTS app_business_rules');
    },
];
