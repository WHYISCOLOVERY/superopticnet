<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

$secret = bin2hex(random_bytes(32));
$now = time();
$requirements = [
    'iss' => 'portal.superoptic.net',
    'aud' => 'portal.ticket.close',
    'type' => 'ticket-close',
];
$payload = [
    'iss' => $requirements['iss'],
    'aud' => $requirements['aud'],
    'type' => $requirements['type'],
    'ticket_id' => 'SECURITY-SMOKE',
    'iat' => $now,
    'nbf' => $now - 1,
    'exp' => $now + 60,
];

$token = app_portal_sign_token($payload, $secret);
$parts = explode('.', $token);
$noneHeader = app_base64url_encode(json_encode(['alg' => 'none', 'typ' => 'JWT']) ?: '{}');
$noneToken = $noneHeader . '.' . ($parts[1] ?? '') . '.' . ($parts[2] ?? '');
$validCloseReason = (string) (app_portal_ticket_close_reasons()[0] ?? '');

$checks = [
    'empty_secret_cannot_sign' => app_portal_sign_token($payload, '') === '',
    'empty_secret_cannot_verify' => app_portal_verify_token('a.b.c', '') === null,
    'valid_contract_accepted' => is_array(app_portal_verify_token($token, $secret, $requirements)),
    'issuer_mismatch_rejected' => app_portal_verify_token($token, $secret, array_merge($requirements, ['iss' => 'invalid'])) === null,
    'type_mismatch_rejected' => app_portal_verify_token($token, $secret, array_merge($requirements, ['type' => 'other'])) === null,
    'non_hs256_header_rejected' => app_portal_verify_token($noneToken, $secret, $requirements) === null,
    'legacy_token_only_closure_disabled' => $validCloseReason !== ''
        && !app_portal_close_ticket_by_token($token, $validCloseReason, 'Must not close'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
