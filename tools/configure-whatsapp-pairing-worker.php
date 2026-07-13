<?php
declare(strict_types=1);

require dirname(__DIR__) . '/whatsapp-monitor-lib.php';

$envPath = dirname(__DIR__) . '/whatsapp-bridge/.env';
$env = is_file($envPath) ? parse_ini_file($envPath, false, INI_SCANNER_RAW) : false;
$env = is_array($env) ? $env : [];
$token = trim((string) ($env['PAIRING_WORKER_TOKEN'] ?? $env['API_SECRET_KEY'] ?? ''));

if ($token === '') {
    fwrite(STDERR, "worker_token_missing\n");
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(3);
}

whatsapp_monitor_ensure_schema($db);
$statement = $db->prepare(
    'UPDATE whatsapp_monitor_settings
     SET setting_value = :value, value_type = :value_type
     WHERE setting_key = :setting_key'
);
$statement->execute(['value' => $token, 'value_type' => 'string', 'setting_key' => 'worker_api_token']);
$statement->execute(['value' => '600', 'value_type' => 'int', 'setting_key' => 'pairing_qr_ttl_seconds']);

fwrite(STDOUT, "whatsapp_pairing_worker_configured\n");
