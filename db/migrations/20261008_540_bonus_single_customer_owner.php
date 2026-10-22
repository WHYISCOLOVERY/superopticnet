<?php
declare(strict_types=1);

const APP_BONUS_SINGLE_CUSTOMER_OWNER_MIGRATION_20261008_540 = '20261008_540_bonus_single_customer_owner';

return [
    'version' => APP_BONUS_SINGLE_CUSTOMER_OWNER_MIGRATION_20261008_540,
    'description' => 'Guarantee one active bonus owner and one customer bonus award per period.',
    'up' => static function (PDO $db): void {
        $duplicateAssignments = (int) $db->query(
            'SELECT COUNT(*) FROM (
                SELECT customer_account_id
                  FROM customer_employee_assignments
                 WHERE status="active"
                 GROUP BY customer_account_id
                HAVING COUNT(*) > 1
            ) duplicates'
        )->fetchColumn();
        if ($duplicateAssignments > 0) {
            throw new RuntimeException('duplicate_active_customer_bonus_assignments_require_review');
        }

        $duplicateAwards = (int) $db->query(
            'SELECT COUNT(*) FROM (
                SELECT period_id, customer_account_id, item_type
                  FROM employee_bonus_items
                 WHERE customer_account_id IS NOT NULL
                   AND item_type IN ("new_connection","recurring_active_payment")
                 GROUP BY period_id, customer_account_id, item_type
                HAVING COUNT(*) > 1
            ) duplicates'
        )->fetchColumn();
        if ($duplicateAwards > 0) {
            throw new RuntimeException('duplicate_customer_bonus_awards_require_review');
        }

        $db->exec(
            'UPDATE customer_employee_assignments
                SET bonus_owner_employee_id=COALESCE(
                    bonus_owner_employee_id,
                    finder_employee_id,
                    installer_employee_id,
                    area_responsible_employee_id
                ), split_mode="single_owner"
              WHERE status="active"'
        );

        $db->exec(
            'ALTER TABLE customer_employee_assignments
               ADD COLUMN active_customer_account_id BIGINT UNSIGNED
                   GENERATED ALWAYS AS (
                       CASE WHEN status="active" THEN customer_account_id ELSE NULL END
                   ) STORED,
               ADD UNIQUE KEY uq_bonus_one_active_customer (active_customer_account_id)'
        );
        $db->exec(
            'ALTER TABLE employee_bonus_items
               ADD UNIQUE KEY uq_bonus_one_customer_award (period_id, customer_account_id, item_type)'
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('ALTER TABLE employee_bonus_items DROP INDEX uq_bonus_one_customer_award');
        $db->exec(
            'ALTER TABLE customer_employee_assignments
               DROP INDEX uq_bonus_one_active_customer,
               DROP COLUMN active_customer_account_id'
        );
    },
];
