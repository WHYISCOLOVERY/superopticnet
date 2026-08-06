<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/copilot-agent.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
app_send_security_headers();

app_session_boot();
$user = app_portal_current_user();
if ($user === null || (string) ($user['role'] ?? '') !== 'employee') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'auth_required', 'text' => 'Emekdas girisi teleb olunur.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 8192) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'reason' => 'request_too_large', 'text' => 'Sorgu hecmi limiti kecir.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawPayload = (string) file_get_contents('php://input');
if (strlen($rawPayload) > 8192) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'reason' => 'request_too_large', 'text' => 'Sorgu hecmi limiti kecir.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if (!app_verify_csrf($payload['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'reason' => 'csrf_failed', 'text' => 'Sessiya yoxlamasi ugursuz oldu.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$question = trim((string) ($payload['question'] ?? ''));
if ($question === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'reason' => 'question_required', 'text' => 'Sual bosdur.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (mb_strlen($question) > 2000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'reason' => 'question_too_long', 'text' => 'Sual 2000 simvoldan uzun ola bilmez.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rateScope = 'copilot:' . (int) ($user['id'] ?? 0) . ':' . app_client_ip();
if (app_rate_limit_is_blocked($rateScope, 10, 60)) {
    $retryAfter = app_rate_limit_retry_after($rateScope, 60);
    header('Retry-After: ' . max(1, $retryAfter));
    http_response_code(429);
    echo json_encode(['ok' => false, 'reason' => 'rate_limited', 'text' => 'Cox sayda sorgu gonderildi. Bir qeder sonra yeniden yoxlayin.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
app_rate_limit_hit($rateScope, 60);

$actorPayload = app_db_account_payload($user);
if ($actorPayload === [] && (int) ($user['id'] ?? 0) > 0) {
    $freshUser = app_db_account_by_id((int) $user['id']);
    if (is_array($freshUser)) {
        $actorPayload = app_db_account_payload($freshUser);
    }
}
$employeeMeta = is_array($actorPayload['employee'] ?? null) ? $actorPayload['employee'] : [];
$telegramMeta = is_array($actorPayload['telegram'] ?? null) ? $actorPayload['telegram'] : [];

$actor = [
    'id' => (int) ($user['id'] ?? 0),
    'full_name' => (string) ($user['full_name'] ?? $user['login'] ?? 'employee'),
    'telegram_chat_id' => (string) ($employeeMeta['telegram_chat_id'] ?? $telegramMeta['chat_id'] ?? ''),
    'source' => 'web_superpilot_widget',
];

$result = app_copilot_agent_handle_message($question, $actor);
echo json_encode([
    'ok' => !empty($result['ok']),
    'text' => (string) ($result['text'] ?? $result['answer'] ?? ''),
    'action_id' => $result['action_id'] ?? null,
    'kind' => $result['kind'] ?? null,
    'reason' => (string) ($result['reason'] ?? ''),
    'analysis' => is_array($result['analysis'] ?? null) ? $result['analysis'] : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
