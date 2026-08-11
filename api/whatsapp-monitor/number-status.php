<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/whatsapp-monitor-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = billing_require_employee(false, 'whatsapp_monitor');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_unavailable']);
    exit;
}
whatsapp_monitor_ensure_schema($db);

echo json_encode([
    'ok' => true,
    'generated_at' => date(DATE_ATOM),
    'cards' => whatsapp_monitor_number_status_cards($db, $user, false),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
