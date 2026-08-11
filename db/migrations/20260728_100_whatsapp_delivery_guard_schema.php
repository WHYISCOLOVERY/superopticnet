<?php
declare(strict_types=1);

const APP_WHATSAPP_GUARD_MIGRATION_20260728_100 = '20260728_100_whatsapp_delivery_guard_schema';

function app_whatsapp_guard_table_exists_20260728_100(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

return [
    'version' => APP_WHATSAPP_GUARD_MIGRATION_20260728_100,
    'description' => 'Create WhatsApp delivery guard tables outside HTTP and readiness runtime paths',
    'up' => static function (PDO $db): void {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS whatsapp_delivery_guard (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                destination_hash CHAR(64) NOT NULL UNIQUE,
                known_contact TINYINT(1) NOT NULL DEFAULT 0,
                sent_24h INT NOT NULL DEFAULT 0,
                sent_total INT NOT NULL DEFAULT 0,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_window_at DATETIME NULL,
                last_sent_at DATETIME NULL,
                blocked_until DATETIME NULL,
                note VARCHAR(255) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_whatsapp_guard_blocked (blocked_until),
                KEY idx_whatsapp_guard_last_sent (last_sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS whatsapp_delivery_guard_global (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                sent_24h INT NOT NULL DEFAULT 0,
                sent_hour INT NOT NULL DEFAULT 0,
                last_day_window_at DATETIME NULL,
                last_hour_window_at DATETIME NULL,
                last_sent_at DATETIME NULL,
                blocked_until DATETIME NULL,
                note VARCHAR(255) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    },
    'down' => static function (PDO $db): void {
        foreach (['whatsapp_delivery_guard', 'whatsapp_delivery_guard_global'] as $table) {
            if (app_whatsapp_guard_table_exists_20260728_100($db, $table)
                && (int) $db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() > 0) {
                throw new RuntimeException('whatsapp_guard_rollback_requires_empty_tables');
            }
        }
        $db->exec('DROP TABLE IF EXISTS whatsapp_delivery_guard_global');
        $db->exec('DROP TABLE IF EXISTS whatsapp_delivery_guard');
    },
];
