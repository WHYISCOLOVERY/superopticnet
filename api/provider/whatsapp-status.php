<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/communication-provider.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = billing_require_employee();
if (!app_portal_is_admin($user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $payload = is_array($payload) ? $payload : [];
    $csrfToken = (string) ($payload['csrf_token'] ?? '');
    if (!app_verify_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_failed']);
        exit;
    }
    $refresh = app_communication_whatsapp_bridge_refresh();
    http_response_code(!empty($refresh['ok']) ? 202 : 503);
    echo json_encode($refresh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$bridge = app_communication_whatsapp_bridge_status();
$qr = !empty($bridge['connected'])
    ? ['ok' => true, 'status' => 'connected', 'qr' => '']
    : app_communication_whatsapp_bridge_qr();

echo json_encode([
    'ok' => !empty($bridge['reachable']) || !empty($qr['ok']),
    'reachable' => !empty($bridge['reachable']),
    'connected' => !empty($bridge['connected']),
    'status' => (string) ($bridge['status'] ?? $qr['status'] ?? 'unknown'),
    'provider_number' => (string) ($bridge['provider_number'] ?? ''),
    'last_qr_at' => (string) ($bridge['last_qr_at'] ?? ''),
    'qr_expires_at' => (string) ($bridge['qr_expires_at'] ?? $qr['qr_expires_at'] ?? ''),
    'qr_expired' => !empty($bridge['qr_expired']) || !empty($qr['qr_expired']),
    'last_error' => (string) ($bridge['last_error'] ?? $qr['reason'] ?? ''),
    'qr' => (string) ($qr['qr'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
