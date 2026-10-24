<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/audit-log-lib.php';

const APP_AUDIT_ALIGNMENT_MIGRATION_20260729_120 = '20260729_120_audit_schema_alignment';

return [
    'version' => APP_AUDIT_ALIGNMENT_MIGRATION_20260729_120,
    'description' => 'Align legacy operation audit schema with the immutable audit ledger contract',
    'up' => static function (PDO $db): void {
        app_audit_ensure_schema($db);
    },
    'down' => static function (PDO $db): void {
        $ledgerCount = 0;
        $table = $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_audit_ledger'"
        );
        if ((int) ($table?->fetchColumn() ?: 0) > 0) {
            $ledgerCount = (int) $db->query('SELECT COUNT(*) FROM security_audit_ledger')->fetchColumn();
        }
        if ($ledgerCount > 0) {
            throw new RuntimeException('audit_schema_alignment_rollback_requires_empty_ledger');
        }

        foreach (['security_audit_ledger_bu', 'security_audit_ledger_bd'] as $trigger) {
            $db->exec('DROP TRIGGER IF EXISTS `' . $trigger . '`');
        }
        $db->exec('DROP TABLE IF EXISTS security_audit_chain_state');
        $db->exec('DROP TABLE IF EXISTS security_audit_ledger');
        $db->exec('DROP TABLE IF EXISTS security_audit_schema_migrations');

        $columns = [
            'module_key',
            'operation_class',
            'reason',
            'before_json',
            'after_json',
            'request_ip',
            'user_agent',
            'request_id',
            'sensitivity',
            'is_visible',
            'edited_by_account_id',
            'edited_at',
            'deleted_by_account_id',
            'deleted_at',
        ];
        foreach ($columns as $column) {
            $statement = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = "mgs_operation_actions"
                    AND COLUMN_NAME = :column_name'
            );
            $statement->execute(['column_name' => $column]);
            if ((int) $statement->fetchColumn() > 0) {
                $db->exec('ALTER TABLE mgs_operation_actions DROP COLUMN `' . $column . '`');
            }
        }
    },
];
