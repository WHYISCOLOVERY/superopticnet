<?php
declare(strict_types=1);

const APP_COMPANY_BRANCHES_MIGRATION_20260729_130 = '20260729_130_company_branches';

function app_company_branches_table_exists_20260729_130(PDO $db): bool
{
    $statement = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'company_branches'"
    );
    return (int) ($statement?->fetchColumn() ?: 0) > 0;
}

return [
    'version' => APP_COMPANY_BRANCHES_MIGRATION_20260729_130,
    'description' => 'Create and seed company branches outside HR page rendering',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS company_branches (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                branch_name VARCHAR(128) NOT NULL,
                branch_key VARCHAR(160) NOT NULL,
                area_name VARCHAR(128) DEFAULT NULL,
                address VARCHAR(255) DEFAULT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                note VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_company_branches_key (branch_key),
                KEY idx_company_branches_status (status),
                KEY idx_company_branches_area (area_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $seed = $db->prepare(
            'INSERT INTO company_branches
                (branch_name, branch_key, area_name, status, note)
             VALUES
                (:branch_name, :branch_key, :area_name, "active", :note)
             ON DUPLICATE KEY UPDATE
                branch_name = VALUES(branch_name),
                area_name = VALUES(area_name),
                note = VALUES(note)'
        );
        foreach ([
            ['Baki', 'baki', 'Baki', 'Baki operator, texnik ve NOC merkezi'],
            ['Astara', 'astara', 'Astara', 'Astara filial xidmeti'],
            ['Lenkeran', 'lenkeran', 'Lenkeran', 'Lenkeran xidmet zonasi'],
            ['Masalli', 'masalli', 'Masalli', 'Masalli xidmet zonasi'],
            ['Neftcala', 'neftcala', 'Neftcala', 'Neftcala xidmet zonasi'],
        ] as [$name, $key, $area, $note]) {
            $seed->execute([
                'branch_name' => $name,
                'branch_key' => $key,
                'area_name' => $area,
                'note' => $note,
            ]);
        }
    },
    'down' => static function (PDO $db): void {
        if (!app_company_branches_table_exists_20260729_130($db)) {
            return;
        }
        $customCount = (int) $db->query(
            "SELECT COUNT(*) FROM company_branches
              WHERE branch_key NOT IN ('baki','astara','lenkeran','masalli','neftcala')"
        )->fetchColumn();
        if ($customCount > 0) {
            throw new RuntimeException('company_branches_rollback_requires_no_custom_branches');
        }
        $db->exec('DROP TABLE company_branches');
    },
];
