<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/whatsapp-monitor-lib.php';

header('Content-Type: application/json; charset=utf-8');

$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_unavailable'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$token = (string) ($headers['X-WhatsApp-Monitor-Token'] ?? $headers['x-whatsapp-monitor-token'] ?? '');
if (!whatsapp_monitor_worker_authorized($db, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$limit = (int) ($_GET['limit'] ?? 20);
echo json_encode([
    'ok' => true,
    'items' => whatsapp_monitor_pairing_queue($db, $limit),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
