<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/communication-provider.php';

$db = app_db();
$subscriberCode = '2320025';
$sms = app_communication_provider_public_status('sms', '994000000000');
$telegram = app_communication_provider_public_status('telegram', '0');
$whatsapp = app_communication_provider_public_status('whatsapp', '994000000000');

$attempts = [];
if ($db instanceof PDO) {
    $statement = $db->prepare(
        'SELECT a.id,a.message_id,a.channel,a.delivery,a.destination_masked,a.status,
                a.delivery_state,a.provider_message_id,a.provider_status_code,
                a.provider_status_description,a.provider_status_final,a.reason,a.attempted_at
         FROM communication_delivery_attempts a
         WHERE a.subscriber_code=:subscriber_code
         ORDER BY a.id DESC
         LIMIT 10'
    );
    $statement->execute(['subscriber_code' => $subscriberCode]);
    $attempts = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$bridge = is_array($whatsapp['bridge'] ?? null) ? $whatsapp['bridge'] : [];
echo json_encode([
    'status' => 'ok',
    'checked_at' => date(DATE_ATOM),
    'subscriber_code' => $subscriberCode,
    'sms' => [
        'enabled' => (bool) ($sms['enabled'] ?? false),
        'configured' => (bool) ($sms['configured'] ?? false),
        'driver' => (string) ($sms['driver'] ?? ''),
        'credential_envs' => array_values((array) ($sms['credential_envs'] ?? [])),
    ],
    'telegram' => [
        'enabled' => (bool) ($telegram['enabled'] ?? false),
        'configured' => (bool) ($telegram['configured'] ?? false),
        'credential_envs' => array_values((array) ($telegram['credential_envs'] ?? [])),
    ],
    'whatsapp' => [
        'enabled' => (bool) ($whatsapp['enabled'] ?? false),
        'configured' => (bool) ($whatsapp['configured'] ?? false),
        'reachable' => (bool) ($bridge['reachable'] ?? false),
        'connected' => (bool) ($bridge['connected'] ?? false),
        'status' => (string) ($bridge['status'] ?? 'unknown'),
        'provider_number' => (string) ($bridge['provider_number'] ?? ''),
        'ready_at' => (string) ($bridge['ready_at'] ?? ''),
        'last_qr_at' => (string) ($bridge['last_qr_at'] ?? ''),
        'qr_expires_at' => (string) ($bridge['qr_expires_at'] ?? ''),
        'qr_expired' => (bool) ($bridge['qr_expired'] ?? false),
        'last_error' => (string) ($bridge['last_error'] ?? $bridge['reason'] ?? ''),
    ],
    'recent_customer_attempts' => $attempts,
    'secrets_exposed' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
