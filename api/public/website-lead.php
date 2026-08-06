<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/website-lead-service.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$rawBody = (string) file_get_contents('php://input');
if ($rawBody === '' || strlen($rawBody) > 32768) {
    $respond(400, ['ok' => false, 'error' => 'invalid_body']);
}

$keyId = trim((string) ($_SERVER['HTTP_X_SUPEROPTIC_KEY_ID'] ?? ''));
$timestamp = trim((string) ($_SERVER['HTTP_X_SUPEROPTIC_TIMESTAMP'] ?? ''));
$nonce = trim((string) ($_SERVER['HTTP_X_SUPEROPTIC_NONCE'] ?? ''));
$signature = strtolower(trim((string) ($_SERVER['HTTP_X_SUPEROPTIC_SIGNATURE'] ?? '')));
$expectedKeyId = app_environment_secret('PUBLIC_SITE_INGRESS_KEY_ID');
$secret = app_environment_secret('PUBLIC_SITE_INGRESS_SECRET');

if ($expectedKeyId === '' || strlen($secret) < 32) {
    $respond(503, ['ok' => false, 'error' => 'ingress_not_configured']);
}
if ($keyId === '' || !hash_equals($expectedKeyId, $keyId)) {
    $respond(401, ['ok' => false, 'error' => 'authentication_failed']);
}
if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
    $respond(401, ['ok' => false, 'error' => 'request_expired']);
}
if (preg_match('/^[A-Za-z0-9_-]{16,96}$/', $nonce) !== 1 || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
    $respond(401, ['ok' => false, 'error' => 'authentication_failed']);
}

$bodyHash = hash('sha256', $rawBody);
$canonical = $timestamp . "\n" . $nonce . "\n" . $bodyHash;
$expectedSignature = hash_hmac('sha256', $canonical, $secret);
if (!hash_equals($expectedSignature, $signature)) {
    $respond(401, ['ok' => false, 'error' => 'authentication_failed']);
}

$db = app_db();
if (!$db instanceof PDO) {
    $respond(503, ['ok' => false, 'error' => 'storage_unavailable']);
}

try {
    $db->exec("DELETE FROM public_ingress_nonces WHERE received_at < (NOW() - INTERVAL 1 DAY)");
    $claim = $db->prepare(
        'INSERT INTO public_ingress_nonces
            (client_key_id, request_nonce, request_hash, source_ip)
         VALUES
            (:client_key_id, :request_nonce, :request_hash, :source_ip)'
    );
    $claim->execute([
        'client_key_id' => $keyId,
        'request_nonce' => $nonce,
        'request_hash' => $bodyHash,
        'source_ip' => mb_substr(app_client_ip(), 0, 64),
    ]);
} catch (PDOException $exception) {
    if ((string) $exception->getCode() === '23000') {
        $respond(409, ['ok' => false, 'error' => 'request_replayed']);
    }
    $reference = app_runtime_error_reference('public_ingress_nonce', $exception);
    $respond(503, ['ok' => false, 'error' => 'storage_unavailable', 'reference' => $reference]);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $respond(400, ['ok' => false, 'error' => 'invalid_json']);
}

$correlationId = app_request_correlation_id();
$result = app_website_lead_process($payload, [
    'key_id' => $keyId,
    'correlation_id' => $correlationId,
]);
if (empty($result['ok'])) {
    $reason = (string) ($result['reason'] ?? 'request_failed');
    $status = in_array($reason, ['required_fields_missing', 'phone_invalid', 'email_invalid'], true) ? 422 : 503;
    $respond($status, [
        'ok' => false,
        'error' => $reason,
        'correlation_id' => $correlationId,
    ]);
}

$respond(202, [
    'ok' => true,
    'status' => 'queued',
    'correlation_id' => $correlationId,
]);
