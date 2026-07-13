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

$sessionKey = clean_text((string) ($_GET['session_key'] ?? ''));
if ($sessionKey === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'session_required']);
    exit;
}

$statement = $db->prepare(
    'SELECT p.*, n.employee_id AS number_employee_id
     FROM whatsapp_monitor_pairings p
     INNER JOIN whatsapp_employee_numbers n ON n.id = p.number_id
     WHERE p.session_key = :session_key
     LIMIT 1'
);
$statement->execute(['session_key' => $sessionKey]);
$pairing = $statement->fetch(PDO::FETCH_ASSOC);
if (!is_array($pairing)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'session_not_found']);
    exit;
}
if (!whatsapp_monitor_can_view_all($user) && (int) ($pairing['number_employee_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$status = (string) ($pairing['pairing_status'] ?? 'requested');
echo json_encode([
    'ok' => true,
    'status' => $status,
    'status_label' => whatsapp_monitor_pairing_status_label($status),
    'qr_image_data_uri' => (string) ($pairing['qr_image_data_uri'] ?? ''),
    'worker_id' => (string) ($pairing['worker_id'] ?? ''),
    'failure_reason' => (string) ($pairing['failure_reason'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
