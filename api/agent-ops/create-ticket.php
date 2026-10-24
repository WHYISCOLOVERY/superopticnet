<?php
declare(strict_types=1);

/**
 * AI-crew → müştəri bileti açma (daxili, texniklər üçün).
 *
 * POST, servis-token. Mövcud app_copilot_agent_execute_create_ticket()-i işlədir.
 * Müştəri account_id və ya subscriber_code ilə tapılır. Bilet source='superpilot',
 * status='open'. Bu DAXİLİ əməliyyatdır (müştəriyə mesaj DEYİL) — texnik baxışı üçün.
 */

require_once dirname(__DIR__, 2) . '/copilot-agent.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function tkt_fail(int $code, string $reason): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    tkt_fail(405, 'method_not_allowed');
}
$expected = getenv('CREW_INGEST_TOKEN');
$expected = is_string($expected) ? trim($expected) : '';
if ($expected === '' || strlen($expected) < 24) {
    tkt_fail(503, 'ingest_disabled');
}
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $hName => $hVal) {
        if (strcasecmp($hName, 'Authorization') === 0) { $auth = (string) $hVal; break; }
    }
}
$presented = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1 ? trim($m[1]) : '';
if ($presented === '' || !hash_equals($expected, $presented)) {
    tkt_fail(401, 'auth_required');
}

$db = app_db();
if (!$db instanceof PDO) {
    tkt_fail(503, 'database_unavailable');
}

$payload = json_decode((string) file_get_contents('php://input', false, null, 0, 16384), true);
if (!is_array($payload)) {
    tkt_fail(400, 'invalid_json');
}

// Müştərini tap: account_id və ya subscriber_code
$accountId = (int) ($payload['account_id'] ?? 0);
if ($accountId <= 0) {
    $code = trim((string) ($payload['subscriber_code'] ?? ''));
    if ($code !== '' && function_exists('app_db_account_by_subscriber_code')) {
        $acc = app_db_account_by_subscriber_code($code);
        $accountId = is_array($acc) ? (int) ($acc['id'] ?? 0) : 0;
    }
}
if ($accountId <= 0) {
    tkt_fail(422, 'customer_required');
}

$subject = trim((string) ($payload['subject'] ?? ''));
$message = trim((string) ($payload['message'] ?? ''));
if ($subject === '' || $message === '') {
    tkt_fail(422, 'subject_and_message_required');
}
$priority = in_array(($payload['priority'] ?? ''), ['low', 'normal', 'high', 'urgent'], true)
    ? (string) $payload['priority'] : 'normal';

$actor = ['full_name' => 'SuperOptic AI', 'name' => 'crew-ai', 'role' => 'employee'];
$res = app_copilot_agent_execute_create_ticket([
    'customer_account_id' => $accountId,
    'subject' => mb_substr($subject, 0, 200),
    'message' => "[AI Komanda] " . mb_substr($message, 0, 2000),
    'priority' => $priority,
], $actor, $db);

$ok = !empty($res['ok']);
http_response_code($ok ? 201 : 422);
echo json_encode(['ok' => $ok, 'reason' => (string) ($res['reason'] ?? ''),
    'ticket_no' => (string) ($res['ticket_no'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
