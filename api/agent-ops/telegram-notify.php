<?php
declare(strict_types=1);

/**
 * AI-crew → şöbə Telegram bildirişi (DAXİLİ kanallar).
 *
 * POST, servis-token. Yalnız daxili şöbə botlarına (customer/network/warehouse/
 * finance) problem/təklif bildirişi göndərir — mövcud app_mgs_telegram_bot_send_message()
 * üzərindən. Müştəriyə birbaşa mesaj YOX. Mətn "[AI Komanda]" ilə etiketlənir.
 * Xarici göndərmə mövcud app_communication_external_delivery_enabled() qapısına tabedir.
 */

require_once dirname(__DIR__, 2) . '/mgs-integration-lib.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

const CREW_TELEGRAM_BOTS = ['customer', 'network', 'warehouse', 'finance'];

function tg_fail(int $code, string $reason): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    tg_fail(405, 'method_not_allowed');
}

$expected = getenv('CREW_INGEST_TOKEN');
$expected = is_string($expected) ? trim($expected) : '';
if ($expected === '' || strlen($expected) < 24) {
    tg_fail(503, 'ingest_disabled');
}
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $hName => $hVal) {
        if (strcasecmp($hName, 'Authorization') === 0) { $auth = (string) $hVal; break; }
    }
}
$presented = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1 ? trim($m[1]) : '';
if ($presented === '' || !hash_equals($expected, $presented)) {
    tg_fail(401, 'auth_required');
}

$raw = file_get_contents('php://input', false, null, 0, 16384);
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    tg_fail(400, 'invalid_json');
}

$bot = strtolower(trim((string) ($payload['bot'] ?? '')));
if (!in_array($bot, CREW_TELEGRAM_BOTS, true)) {
    tg_fail(422, 'invalid_bot');
}
$text = trim((string) ($payload['text'] ?? ''));
if ($text === '') {
    tg_fail(422, 'empty_text');
}
$text = '[AI Komanda] ' . mb_substr($text, 0, 3000);

if (!function_exists('app_mgs_telegram_bot_send_message')) {
    tg_fail(503, 'telegram_unavailable');
}

$res = app_mgs_telegram_bot_send_message($bot, $text);
$ok = !empty($res['ok']);
http_response_code($ok ? 200 : 502);
echo json_encode([
    'ok' => $ok,
    'bot' => $bot,
    'reason' => (string) ($res['reason'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
