<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

app_session_boot();
app_send_security_headers();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$user = app_portal_current_user();
$token = trim((string) ($_SERVER['HTTP_X_IMPORT_TOKEN'] ?? ''));
$expectedToken = app_config('import_api_token');
$sessionAuthorized = $user !== null && ($user['role'] ?? '') === 'employee' && app_portal_is_admin($user);
$tokenAuthorized = $expectedToken !== '' && $token !== '' && hash_equals($expectedToken, $token);
$authorized = $sessionAuthorized || $tokenAuthorized;

if (!$authorized) {
    app_record_security_event([
        'event' => 'olt_sync_unauthorized',
        'status' => 'warning',
        'role' => (string) ($user['role'] ?? ''),
        'login' => (string) ($user['login'] ?? ''),
        'meta' => [
            'path' => '/api/olt-sync-users.php',
            'token_present' => $token !== '',
        ],
    ]);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($sessionAuthorized && !$tokenAuthorized && !app_verify_csrf(app_request_csrf_token())) {
    app_record_security_event([
        'event' => 'olt_sync_csrf_failed',
        'status' => 'warning',
        'role' => (string) ($user['role'] ?? ''),
        'login' => (string) ($user['login'] ?? ''),
        'meta' => [
            'path' => '/api/olt-sync-users.php',
        ],
    ]);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF verification failed']);
    exit;
}

$scope = 'admin-import-api:' . sha1(app_client_ip() . '|olt-sync-users');
if (app_rate_limit_is_blocked($scope, 20, 60)) {
    $retryAfter = app_rate_limit_retry_after($scope, 60);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
    }
    app_record_security_event([
        'event' => 'olt_sync_rate_limited',
        'status' => 'warning',
        'role' => (string) ($user['role'] ?? ''),
        'login' => (string) ($user['login'] ?? ''),
        'meta' => [
            'path' => '/api/olt-sync-users.php',
            'retry_after' => $retryAfter,
        ],
    ]);
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests']);
    exit;
}
app_rate_limit_hit($scope, 60);

if (app_config('olt_user_sync_endpoint') === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'OLT sync endpoint not configured']);
    exit;
}

$result = app_db_sync_all_olt_accounts();
echo json_encode([
    'success' => true,
    'synced' => (int) ($result['synced'] ?? 0),
], JSON_UNESCAPED_UNICODE);
