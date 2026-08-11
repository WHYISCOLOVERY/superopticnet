<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/copilot-engine.php';

app_session_boot();
app_send_security_headers();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = app_portal_current_user();
$token = '';
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (is_array($headers)) {
    foreach ($headers as $key => $value) {
        if (strtolower((string) $key) === 'authorization' && preg_match('/^Bearer\s+(.+)$/i', (string) $value, $match) === 1) {
            $token = trim((string) $match[1]);
        }
    }
}
$expectedToken = app_environment_secret('COPILOT_API_TOKEN');
$tokenAllowed = $expectedToken !== '' && $token !== '' && hash_equals($expectedToken, $token);

if (!(is_array($user) && ($user['role'] ?? '') === 'employee') && !$tokenAllowed) {
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

$question = trim((string) ($payload['question'] ?? $_GET['q'] ?? $_GET['question'] ?? ''));
if ($question === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'question_required'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$result = app_copilot_customer_analysis($question);
$result['requested_by'] = is_array($user) ? [
    'account_id' => (int) ($user['id'] ?? 0),
    'role' => (string) ($user['role'] ?? ''),
] : ['token' => 'copilot_api_token'];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
