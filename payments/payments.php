<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-payment-compat-lib.php';
app_send_security_headers();
$provider = app_legacy_payment_authenticate();

$customerCode = trim(strip_tags((string) ($_POST['id'] ?? '')));
$amountRaw = $_POST['amount'] ?? null;
$transactionId = trim(strip_tags((string) ($_POST['transaction-id'] ?? '')));
if ($customerCode === '') {
    app_legacy_payment_response('401', 'Client id parameter is required');
}
if ($amountRaw === null || !is_numeric($amountRaw)) {
    app_legacy_payment_response('401', 'Amount parameter is required and must be numeric');
}
if ($transactionId === '') {
    app_legacy_payment_response('401', 'Transaction-id parameter is required');
}
if (preg_match('/^[a-zA-Z0-9_.-]+$/', $customerCode) !== 1) {
    app_legacy_payment_response('401', 'Client id format is invalid');
}
$amount = (float) $amountRaw;
if ($amount < 0) {
    app_legacy_payment_response('401', 'Amount must be positive');
}
if (strlen($transactionId) < 5 || strlen($transactionId) > 100) {
    app_legacy_payment_response('401', 'Transaction-id length must be between 5 and 100 characters');
}

$account = app_legacy_payment_customer($customerCode);
if ($account === null) {
    app_legacy_payment_response('401', 'Client id is invalid');
}
$duplicate = app_legacy_payment_by_transaction($transactionId);
if ($duplicate !== null) {
    app_legacy_payment_response(
        '406',
        'Transaction with given transaction-id already exists',
        app_legacy_payment_customer_data($account)
    );
}

$agent = trim(strip_tags((string) ($_POST['agent'] ?? '')));
$employee = trim(strip_tags((string) ($_POST['paymen'] ?? '')));
$terminalSuffix = $agent !== '' ? $agent : $employee;
$paymentMethod = 'legacy-' . $provider . ($terminalSuffix !== '' ? ':' . $terminalSuffix : '');
$externalId = app_provider_external_id('legacy-' . $provider, $transactionId);
$applied = app_billing_apply_customer_payment(
    (string) ($account['subscriber_code'] ?? $customerCode),
    $amount,
    $paymentMethod,
    0,
    $transactionId,
    $externalId,
    null,
    $externalId,
    app_request_correlation_id()
);
if (!$applied) {
    app_legacy_payment_response('503', 'Internal error. Try again later', [], 503);
}
app_legacy_payment_response('200', 'Operation completed successfully', [
    'id' => (string) ($account['subscriber_code'] ?? $customerCode),
    'amount' => $amount,
    'currency' => 'AZN',
    'transaction-id' => $transactionId,
]);
