<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

app_session_boot();
app_send_security_headers();

header('Content-Type: application/json; charset=UTF-8');

$user = app_portal_current_user();
if ($user === null || ($user['role'] ?? '') !== 'employee') {
    app_record_security_event([
        'event' => 'customer_search_unauthorized',
        'status' => 'warning',
        'role' => (string) ($user['role'] ?? ''),
        'login' => (string) ($user['login'] ?? ''),
        'meta' => [
            'path' => '/api/customer-search.php',
        ],
    ]);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$query = clean_login_identifier((string) ($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode(['success' => true, 'results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$scope = 'customer-search:' . sha1(app_client_ip() . '|' . (string) ($user['id'] ?? $user['login'] ?? session_id()));
$limit = 25;
$windowSeconds = 120;
if (app_rate_limit_is_blocked($scope, $limit, $windowSeconds)) {
    $retryAfter = app_rate_limit_retry_after($scope, $windowSeconds);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
    }
    app_record_security_event([
        'event' => 'customer_search_rate_limited',
        'status' => 'warning',
        'role' => (string) ($user['role'] ?? ''),
        'login' => (string) ($user['login'] ?? ''),
        'meta' => [
            'path' => '/api/customer-search.php',
            'q' => $query,
            'retry_after' => $retryAfter,
        ],
    ]);
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests'], JSON_UNESCAPED_UNICODE);
    exit;
}

app_rate_limit_hit($scope, $windowSeconds);

echo json_encode([
    'success' => true,
    'results' => app_db_customer_lookup($query),
], JSON_UNESCAPED_UNICODE);
