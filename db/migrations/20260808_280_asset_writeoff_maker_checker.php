<?php
declare(strict_types=1);

const APP_ASSET_WRITEOFF_MIGRATION_20260808_280 = '20260808_280_asset_writeoff_maker_checker';

return [
    'version' => APP_ASSET_WRITEOFF_MIGRATION_20260808_280,
    'description' => 'Add pending_writeoff status and a separate confirmer to finance_asset_depreciations (vision 19.2 "asset silinmesi" maker-checker)',
    'up' => static function (PDO $db): void {
        $db->exec(
            "ALTER TABLE finance_asset_depreciations
                MODIFY COLUMN writeoff_status ENUM('active','eligible','pending_writeoff','written_off','blocked') NOT NULL DEFAULT 'active'"
        );
        $columns = [];
        $columnsResult = $db->query('SHOW COLUMNS FROM finance_asset_depreciations');
        if ($columnsResult !== false) {
            foreach ($columnsResult->fetchAll(PDO::FETCH_ASSOC) as $columnRow) {
                $columns[] = (string) ($columnRow['Field'] ?? '');
            }
        }
        if (!in_array('confirmed_by_account_id', $columns, true)) {
            $db->exec('ALTER TABLE finance_asset_depreciations ADD COLUMN confirmed_by_account_id BIGINT UNSIGNED DEFAULT NULL AFTER writeoff_status');
        }
        if (!in_array('confirmed_by_name', $columns, true)) {
            $db->exec('ALTER TABLE finance_asset_depreciations ADD COLUMN confirmed_by_name VARCHAR(191) DEFAULT NULL AFTER confirmed_by_account_id');
        }
        if (!in_array('confirmed_at', $columns, true)) {
            $db->exec('ALTER TABLE finance_asset_depreciations ADD COLUMN confirmed_at DATETIME DEFAULT NULL AFTER confirmed_by_name');
        }
    },
    'down' => static function (PDO $db): void {
        $db->exec("UPDATE finance_asset_depreciations SET writeoff_status = 'eligible' WHERE writeoff_status = 'pending_writeoff'");
        $db->exec(
            "ALTER TABLE finance_asset_depreciations
                MODIFY COLUMN writeoff_status ENUM('active','eligible','written_off','blocked') NOT NULL DEFAULT 'active'"
        );
        $db->exec('ALTER TABLE finance_asset_depreciations DROP COLUMN confirmed_by_account_id');
        $db->exec('ALTER TABLE finance_asset_depreciations DROP COLUMN confirmed_by_name');
        $db->exec('ALTER TABLE finance_asset_depreciations DROP COLUMN confirmed_at');
    },
];
