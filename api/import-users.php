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
        'event' => 'import_users_unauthorized',
        'status' => 'warning',
        'role' => (string) ($user['role'] ?? ''),
        'login' => (string) ($user['login'] ?? ''),
        'meta' => [
            'path' => '/api/import-users.php',
            'token_present' => $token !== '',
        ],
    ]);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($sessionAuthorized && !$tokenAuthorized && !app_verify_csrf(app_request_csrf_token())) {
    app_record_security_event([
        'event' => 'import_users_csrf_failed',
        'status' => 'warning',
        'role' => (string) ($user['role'] ?? ''),
        'login' => (string) ($user['login'] ?? ''),
        'meta' => [
            'path' => '/api/import-users.php',
        ],
    ]);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF verification failed']);
    exit;
}

$scope = 'admin-import-api:' . sha1(app_client_ip() . '|import-users');
if (app_rate_limit_is_blocked($scope, 20, 60)) {
    $retryAfter = app_rate_limit_retry_after($scope, 60);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
    }
    app_record_security_event([
        'event' => 'import_users_rate_limited',
        'status' => 'warning',
        'role' => (string) ($user['role'] ?? ''),
        'login' => (string) ($user['login'] ?? ''),
        'meta' => [
            'path' => '/api/import-users.php',
            'retry_after' => $retryAfter,
        ],
    ]);
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests']);
    exit;
}
app_rate_limit_hit($scope, 60);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload) || !is_array($payload['users'] ?? null)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$result = app_db_import_accounts($payload['users']);
echo json_encode([
    'success' => empty($result['errors']),
    'created' => (int) ($result['created'] ?? 0),
    'updated' => (int) ($result['updated'] ?? 0),
    'errors' => array_values((array) ($result['errors'] ?? [])),
], JSON_UNESCAPED_UNICODE);
