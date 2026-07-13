<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/whatsapp-monitor-lib.php';

header('Content-Type: application/json; charset=utf-8');
$user = billing_require_employee(false, 'whatsapp_monitor');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_unavailable']);
    exit;
}

$chatId = clean_text((string) ($_GET['chat_id'] ?? ''));
if ($chatId === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'chat_required']);
    exit;
}
$rows = whatsapp_monitor_chat_messages($db, $user, $chatId, max(0, (int) ($_GET['after_id'] ?? 0)));
echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
