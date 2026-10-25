<?php
declare(strict_types=1);

const APP_MGS_ALARM_STATE_EVENTS_MIGRATION_20260819_430 = '20260819_430_mgs_alarm_state_events';

return [
    'version' => APP_MGS_ALARM_STATE_EVENTS_MIGRATION_20260819_430,
    'description' => 'Create mgs_alarm_state_events append-only transition log for mgs_alarm_events (docs/39_MGS_NODE_CONSOLIDATION.md Faza 1 -- mgs_alarm_events only ever held current state, with no history of status/severity changes over time, unlike the separate Node MGS app being retired)',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mgs_alarm_state_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                alarm_event_id BIGINT UNSIGNED NOT NULL,
                dedup_key VARCHAR(191) NOT NULL,
                from_status VARCHAR(32) DEFAULT NULL,
                to_status VARCHAR(32) NOT NULL,
                from_severity VARCHAR(32) DEFAULT NULL,
                to_severity VARCHAR(32) NOT NULL,
                seen_count INT UNSIGNED NOT NULL DEFAULT 1,
                test_mode TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_mgs_alarm_state_alarm (alarm_event_id, created_at),
                KEY idx_mgs_alarm_state_dedup (dedup_key, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS mgs_alarm_state_events');
    },
];
