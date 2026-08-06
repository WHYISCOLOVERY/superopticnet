<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

app_send_security_headers();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$db = app_db();
$databaseOk = false;
$databaseName = null;

if ($db instanceof PDO) {
    try {
        $databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
        $databaseOk = $databaseName !== '';
    } catch (Throwable) {
        $databaseOk = false;
    }
}

$status = $databaseOk ? 'ok' : 'degraded';
http_response_code($databaseOk ? 200 : 503);

$clientIp = app_client_ip();
$detailAllowed = in_array($clientIp, ['127.0.0.1', '::1', 'localhost'], true)
    || str_starts_with($clientIp, '10.')
    || str_starts_with($clientIp, '172.16.')
    || str_starts_with($clientIp, '172.17.')
    || str_starts_with($clientIp, '172.18.')
    || str_starts_with($clientIp, '172.19.')
    || str_starts_with($clientIp, '172.20.')
    || str_starts_with($clientIp, '172.21.')
    || str_starts_with($clientIp, '172.22.')
    || str_starts_with($clientIp, '172.23.')
    || str_starts_with($clientIp, '172.24.')
    || str_starts_with($clientIp, '172.25.')
    || str_starts_with($clientIp, '172.26.')
    || str_starts_with($clientIp, '172.27.')
    || str_starts_with($clientIp, '172.28.')
    || str_starts_with($clientIp, '172.29.')
    || str_starts_with($clientIp, '172.30.')
    || str_starts_with($clientIp, '172.31.')
    || str_starts_with($clientIp, '192.168.');

$payload = [
    'success' => $databaseOk,
    'status' => $status,
    'service' => 'superoptic-portal',
    'time' => date(DATE_ATOM),
];

if ($detailAllowed) {
    $payload['storage_driver'] = app_config('portal_storage_driver', 'mysql');
    $payload['database'] = [
        'connected' => $databaseOk,
        'name' => $databaseOk ? $databaseName : null,
    ];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
