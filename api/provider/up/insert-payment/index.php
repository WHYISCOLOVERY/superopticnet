<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/provider-api-lib.php';

app_send_security_headers();

$rawBody = (string) file_get_contents('php://input');
app_provider_authenticate($rawBody);
$payload = app_provider_read_json_body($rawBody);

$provider = app_up_provider_name_from_request($payload);
if (app_provider_is_mpay($provider)) {
    app_up_provider_invalid('MPay provider is pending integration');
}

$memberId = clean_login_identifier((string) ($payload['memberId'] ?? $payload['member_id'] ?? $payload['customer_code'] ?? ''));
$toProcessId = clean_text((string) ($payload['toProcessId'] ?? $payload['to_process_id'] ?? $payload['transaction_id'] ?? ''));
$amount = app_billing_parse_money_amount($payload['amount'] ?? 0);
$channelId = clean_text((string) ($payload['channelId'] ?? $payload['channel_id'] ?? ''));
$channelDesc = clean_text((string) ($payload['channelDesc'] ?? $payload['channel_desc'] ?? ''));

if ($memberId === '' || $toProcessId === '' || $amount <= 0) {
    app_up_provider_invalid();
}

$account = app_provider_customer_by_code($memberId);
if ($account === null) {
    app_up_provider_not_found();
}

$externalId = app_provider_external_id($provider, $toProcessId);
if (app_provider_payment_by_external_id($externalId) !== null) {
    app_up_provider_duplicate();
}

$terminal = $channelDesc !== '' ? $channelDesc : ($channelId !== '' ? 'channel-' . $channelId : 'terminal');
$method = $provider . ':' . $terminal;
$applied = app_billing_apply_customer_payment(
    (string) ($account['subscriber_code'] ?? $memberId),
    $amount,
    $method,
    0,
    $toProcessId,
    $externalId,
    null
);

$applied ? app_up_provider_approved() : app_up_provider_invalid();
