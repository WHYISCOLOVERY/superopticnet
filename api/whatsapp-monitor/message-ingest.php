<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/whatsapp-monitor-lib.php';

header('Content-Type: application/json; charset=utf-8');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_unavailable']);
    exit;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$payload = json_decode((string) file_get_contents('php://input'), true);
$payload = is_array($payload) ? $payload : $_POST;
$token = (string) ($headers['X-WhatsApp-Monitor-Token'] ?? $headers['x-whatsapp-monitor-token'] ?? $payload['token'] ?? '');
if (!whatsapp_monitor_worker_authorized($db, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$result = whatsapp_monitor_worker_ingest_message($db, $payload);
if (empty($result['ok'])) {
    http_response_code(422);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
