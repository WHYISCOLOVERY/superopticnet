<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/provider-api-lib.php';

app_send_security_headers();

$rawBody = (string) file_get_contents('php://input');
$auth = app_up_provider_authenticate($rawBody, 'payment:write');
$payload = app_provider_read_json_body($rawBody);

$provider = (string) $auth['provider'];
$requestedProvider = app_provider_normalize_name((string) ($payload['provider'] ?? $provider));
if ($requestedProvider !== '' && $requestedProvider !== $provider) {
    app_up_provider_invalid('Provider credential mismatch');
}
if (app_provider_is_mpay($provider)) {
    app_up_provider_invalid('MPay provider is pending integration');
}

$memberId = clean_login_identifier((string) ($payload['memberId'] ?? $payload['member_id'] ?? $payload['customer_code'] ?? ''));
$toProcessId = clean_text((string) ($payload['toProcessId'] ?? $payload['to_process_id'] ?? $payload['transaction_id'] ?? ''));
$amount = app_billing_parse_money_amount($payload['amount'] ?? 0);
$channelId = clean_text((string) ($payload['channelId'] ?? $payload['channel_id'] ?? ''));
$channelDesc = clean_text((string) ($payload['channelDesc'] ?? $payload['channel_desc'] ?? ''));
$headers = app_provider_request_headers();
$idempotencyKey = app_provider_request_idempotency_key($headers, $payload);
$correlationId = app_request_correlation_id();

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
    null,
    $idempotencyKey,
    $correlationId
);

$operation = app_provider_payment_operation_by_external_id($externalId);
$applied
    ? app_up_provider_approved([
        'paymentState' => (string) ($operation['state'] ?? 'payment_recorded'),
        'correlationId' => (string) ($operation['correlation_id'] ?? $correlationId),
    ])
    : app_up_provider_invalid();
