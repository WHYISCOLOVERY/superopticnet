<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "pdo_mysql extension is required.\n");
    exit(2);
}

$host = app_config('db_host', '127.0.0.1');
$port = (int) app_setting('db_port', 3306);
$user = trim(app_environment_secret('SUPEROPTIC_TEST_DB_ADMIN_USER')) ?: 'root';
$password = app_environment_secret('SUPEROPTIC_TEST_DB_ADMIN_PASS');
$databaseName = 'superoptic_mobile_smoke_' . getmypid() . '_' . bin2hex(random_bytes(3));
$quotedDatabase = chr(96) . str_replace(chr(96), chr(96) . chr(96), $databaseName) . chr(96);
$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$created = false;
try {
    $server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $databaseName),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $core = require dirname(__DIR__) . '/db/migrations/20260927_510_mobile_ecosystem_core.php';
    $hardening = require dirname(__DIR__) . '/db/migrations/20260930_520_mobile_ecosystem_hardening.php';
    $core['up']($db);
    $hardening['up']($db);

    $expectedTables = ['mobile_task_otps', 'mobile_duty_sessions', 'mobile_outbox_queue', 'employee_live_locations'];
    foreach ($expectedTables as $table) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException("Missing table: {$table}");
        }
    }

    $expectedColumns = [
        ['mobile_outbox_queue', 'payload_sha256'],
        ['mobile_outbox_queue', 'started_at'],
        ['employee_live_locations', 'client_uuid'],
    ];
    foreach ($expectedColumns as [$table, $column]) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException("Missing column: {$table}.{$column}");
        }
    }

    $hardening['down']($db);
    $core['down']($db);
    echo "Mobile migration smoke passed: isolated schema created, verified, and rolled back.\n";
} finally {
    if ($created) {
        $server->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
    }
}
