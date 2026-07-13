<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

app_session_boot();
app_send_security_headers();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'reason' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = app_portal_current_user();
if ($user === null || ($user['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'reason' => 'unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawBody = (string) file_get_contents('php://input');
$payload = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    $payload = is_array($decoded) ? $decoded : [];
}
if ($payload === []) {
    $payload = $_POST;
}

$csrfToken = $payload['csrf_token'] ?? app_request_csrf_token();
if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'reason' => 'csrf_failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$result = app_mgs_save_monitor_map_node(
    (string) ($payload['map_key'] ?? 'noc-monitor'),
    $user,
    (string) ($payload['device_id'] ?? ''),
    (float) ($payload['x'] ?? 50),
    (float) ($payload['y'] ?? 50),
    (float) ($payload['scale'] ?? 1),
    (string) ($payload['note'] ?? '')
);

if (empty($result['ok'])) {
    http_response_code(422);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
