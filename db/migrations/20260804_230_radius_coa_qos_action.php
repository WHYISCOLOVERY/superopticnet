<?php
declare(strict_types=1);

const APP_RADIUS_MIGRATION_20260804_230 = '20260804_230_radius_coa_qos_action';

return [
    'version' => APP_RADIUS_MIGRATION_20260804_230,
    'description' => 'Allow radius_session_actions to carry CoA QoS/policy-map change requests (temporary rate-limit + restore), not just disconnect_request',
    'up' => static function (PDO $db): void {
        $db->exec(
            "ALTER TABLE radius_session_actions
                MODIFY COLUMN action_type ENUM('disconnect_request','coa_qos_change') NOT NULL DEFAULT 'disconnect_request'"
        );
    },
    'down' => static function (PDO $db): void {
        $stillUsed = (int) $db->query(
            "SELECT COUNT(*) FROM radius_session_actions WHERE action_type = 'coa_qos_change'"
        )->fetchColumn();
        if ($stillUsed > 0) {
            throw new RuntimeException('radius_coa_qos_rollback_requires_no_coa_qos_change_rows');
        }
        $db->exec(
            "ALTER TABLE radius_session_actions
                MODIFY COLUMN action_type ENUM('disconnect_request') NOT NULL DEFAULT 'disconnect_request'"
        );
    },
];
