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

$numberId = max(0, (int) ($_GET['number_id'] ?? 0));
if ($numberId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'number_scope_required']);
    exit;
}
$rows = whatsapp_monitor_chat_rows($db, $user, $numberId);
echo json_encode([
    'ok' => true,
    'number_id' => $numberId,
    'rows' => $rows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
