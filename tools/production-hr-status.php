<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/config.php';
$db = app_db();
if (!$db instanceof PDO) { fwrite(STDERR, "database_unavailable\n"); exit(2); }
$ready = app_db_table_exists($db, 'hr_positions');
echo json_encode([
    'hr_positions_table' => $ready,
    'positions' => $ready ? (int) $db->query('SELECT COUNT(*) FROM hr_positions')->fetchColumn() : 0,
    'migrations_table' => app_db_table_exists($db, 'portal_schema_migrations'),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
