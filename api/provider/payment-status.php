<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/provider-api-lib.php';

app_send_security_headers();

$rawBody = (string) file_get_contents('php://input');
$auth = app_provider_authenticate($rawBody, '', 'payment:status');
$transactionId = clean_text((string) (
    $_GET['transaction_id']
    ?? $_GET['transaction']
    ?? $_GET['receipt_no']
    ?? ''
));
if ($transactionId === '') {
    app_provider_json_response(['success' => false, 'code' => 'transaction_id_required'], 400);
}

$provider = (string) $auth['provider'];
$externalId = app_provider_external_id($provider, $transactionId);
$payment = app_provider_payment_by_external_id($externalId);
if ($payment === null) {
    app_provider_json_response([
        'success' => false,
        'code' => 'payment_not_found',
        'provider' => $provider,
        'transaction_id' => $transactionId,
    ], 404);
}

$operation = app_provider_payment_operation_by_external_id($externalId);
app_provider_json_response([
    'success' => true,
    'provider' => $provider,
    'transaction_id' => $transactionId,
    'external_id' => $externalId,
    'amount' => number_format((float) ($payment['amount'] ?? 0), 2, '.', ''),
    'payment_state' => (string) ($operation['state'] ?? 'payment_recorded'),
    'payment_created_at' => (string) ($payment['created_at'] ?? ''),
    'service_completed_at' => (string) ($operation['completed_at'] ?? ''),
]);
