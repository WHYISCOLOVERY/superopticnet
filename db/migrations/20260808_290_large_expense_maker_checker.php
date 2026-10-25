<?php
declare(strict_types=1);

const APP_LARGE_EXPENSE_MIGRATION_20260808_290 = '20260808_290_large_expense_maker_checker';

return [
    'version' => APP_LARGE_EXPENSE_MIGRATION_20260808_290,
    'description' => 'Add pending_approval status to finance_entries and a threshold business rule (vision 19.2 "boyuk xerc" maker-checker)',
    'up' => static function (PDO $db): void {
        $db->exec(
            "ALTER TABLE finance_entries
                MODIFY COLUMN status ENUM('posted','void','pending_approval') NOT NULL DEFAULT 'posted'"
        );
        $columns = [];
        $columnsResult = $db->query('SHOW COLUMNS FROM finance_entries');
        if ($columnsResult !== false) {
            foreach ($columnsResult->fetchAll(PDO::FETCH_ASSOC) as $columnRow) {
                $columns[] = (string) ($columnRow['Field'] ?? '');
            }
        }
        if (!in_array('approved_by_account_id', $columns, true)) {
            $db->exec('ALTER TABLE finance_entries ADD COLUMN approved_by_account_id BIGINT UNSIGNED DEFAULT NULL AFTER status');
        }
        if (!in_array('approved_by_name', $columns, true)) {
            $db->exec('ALTER TABLE finance_entries ADD COLUMN approved_by_name VARCHAR(191) DEFAULT NULL AFTER approved_by_account_id');
        }
        if (!in_array('approved_at', $columns, true)) {
            $db->exec('ALTER TABLE finance_entries ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by_name');
        }

        $insert = $db->prepare(
            'INSERT IGNORE INTO app_business_rules (
                rule_key, category, value_type, value_text, min_value, max_value, title, description, updated_by_name
             ) VALUES (
                :rule_key, :category, :value_type, :value_text, :min_value, :max_value, :title, :description, "migration"
             )'
        );
        $insert->execute([
            'rule_key' => 'finance_large_expense_threshold_amount',
            'category' => 'finance',
            'value_type' => 'decimal',
            'value_text' => '1000',
            'min_value' => 0,
            'max_value' => 1000000,
            'title' => 'Boyuk xerc esiyi (AZN)',
            'description' => 'Bu mebleqden boyuk ve ya beraber xerc qeydi avtomatik "pending_approval" statusunda yaranir; yalniz BASQA admin tesdiqleyende hesabatlara dusur.',
        ]);
    },
    'down' => static function (PDO $db): void {
        $db->exec("UPDATE finance_entries SET status = 'void' WHERE status = 'pending_approval'");
        $db->exec(
            "ALTER TABLE finance_entries
                MODIFY COLUMN status ENUM('posted','void') NOT NULL DEFAULT 'posted'"
        );
        $db->exec('ALTER TABLE finance_entries DROP COLUMN approved_by_account_id');
        $db->exec('ALTER TABLE finance_entries DROP COLUMN approved_by_name');
        $db->exec('ALTER TABLE finance_entries DROP COLUMN approved_at');
        $db->prepare('DELETE FROM app_business_rules WHERE rule_key = :rule_key')->execute(['rule_key' => 'finance_large_expense_threshold_amount']);
    },
];
