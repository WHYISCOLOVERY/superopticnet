<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/provider-api-lib.php';

app_send_security_headers();

$rawBody = (string) file_get_contents('php://input');
$auth = app_provider_authenticate($rawBody);
$payload = app_provider_read_json_body($rawBody);

$provider = clean_text((string) ($payload['provider'] ?? $auth['provider'] ?? 'provider'));
$terminalId = clean_text((string) ($payload['terminal_id'] ?? $payload['terminal'] ?? ''));
$customerCode = clean_login_identifier((string) ($payload['customer_code'] ?? $payload['subscriber_code'] ?? $payload['abonent_kodu'] ?? $payload['code'] ?? ''));
$transactionId = clean_text((string) ($payload['transaction_id'] ?? $payload['transaction'] ?? $payload['receipt_no'] ?? ''));
$amount = app_billing_parse_money_amount($payload['amount'] ?? $payload['payment_amount'] ?? $payload['mebleg'] ?? 0);
$paidAt = trim((string) ($payload['paid_at'] ?? $payload['created_at'] ?? $payload['date'] ?? ''));
$days = max(0, min(366, (int) ($payload['days'] ?? 0)));

if ($provider === '' || $provider === 'provider') {
    $provider = 'terminal';
}
if (app_provider_is_mpay($provider)) {
    app_provider_json_response(['success' => false, 'code' => 'provider_pending', 'provider' => 'mpay'], 409);
}
if ($customerCode === '') {
    app_provider_json_response(['success' => false, 'code' => 'customer_code_required'], 400);
}
if ($transactionId === '') {
    app_provider_json_response(['success' => false, 'code' => 'transaction_id_required'], 400);
}
if ($amount <= 0) {
    app_provider_json_response(['success' => false, 'code' => 'amount_required'], 400);
}

$account = app_provider_customer_by_code($customerCode);
if ($account === null) {
    app_provider_json_response([
        'success' => false,
        'code' => 'customer_not_found',
        'customer_code' => $customerCode,
    ], 404);
}

$externalId = app_provider_external_id($provider, $transactionId);
$duplicate = app_billing_payment_external_exists($externalId);
$method = $terminalId !== '' ? ($provider . ':' . $terminalId) : $provider;
$createdAt = $paidAt !== '' && strtotime($paidAt) !== false ? date('Y-m-d H:i:s', strtotime($paidAt)) : null;
$applied = app_billing_apply_customer_payment(
    (string) ($account['subscriber_code'] ?? $customerCode),
    $amount,
    $method,
    $days,
    $transactionId,
    $externalId,
    $createdAt
);

if (!$applied) {
    app_provider_json_response([
        'success' => false,
        'code' => 'payment_apply_failed',
        'customer_code' => $customerCode,
    ], 500);
}

$updated = app_provider_customer_by_code((string) ($account['subscriber_code'] ?? $customerCode)) ?? $account;
app_provider_json_response([
    'success' => true,
    'duplicate' => $duplicate,
    'external_id' => $externalId,
    'transaction_id' => $transactionId,
    'amount' => number_format($amount, 2, '.', ''),
    'customer' => app_provider_customer_summary($updated),
]);
