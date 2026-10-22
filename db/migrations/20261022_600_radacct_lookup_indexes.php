<?php
declare(strict_types=1);

const APP_RADACCT_INDEX_MIGRATION_20261022_600 = '20261022_600_radacct_lookup_indexes';

function app_radacct_index_exists_20261022_600(PDO $db, string $index): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
    );
    $statement->execute(['table_name' => 'radacct', 'index_name' => $index]);
    return (int) $statement->fetchColumn() > 0;
}

function app_radacct_index_definitions_20261022_600(): array
{
    // nasipaddress: NAS filter list (SELECT DISTINCT ... FROM radacct) full-scanned the table.
    // framedipaddress / callingstationid: IP and MAC lookups from RADIUS and NOC pages.
    return [
        'idx_radacct_nasip' => ['nasipaddress'],
        'idx_radacct_framedip' => ['framedipaddress'],
        'idx_radacct_callingstation' => ['callingstationid'],
    ];
}

return [
    'version' => APP_RADACCT_INDEX_MIGRATION_20261022_600,
    'description' => 'Add radacct lookup indexes (NAS IP, framed IP, calling station) to avoid full scans on RADIUS pages',
    'up' => static function (PDO $db): void {
        $table = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'radacct'");
        if ($table === false || (int) $table->fetchColumn() === 0) {
            return;
        }
        foreach (app_radacct_index_definitions_20261022_600() as $index => $columns) {
            if (app_radacct_index_exists_20261022_600($db, $index)) {
                continue;
            }
            $db->exec('CREATE INDEX `' . $index . '` ON `radacct` (`' . implode('`,`', $columns) . '`)');
        }
    },
    'down' => static function (PDO $db): void {
        foreach (array_keys(app_radacct_index_definitions_20261022_600()) as $index) {
            if (app_radacct_index_exists_20261022_600($db, $index)) {
                $db->exec('DROP INDEX `' . $index . '` ON `radacct`');
            }
        }
    },
];
