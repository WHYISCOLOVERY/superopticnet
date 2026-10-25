<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-payment-compat-lib.php';
app_send_security_headers();
app_legacy_payment_authenticate();

$transactionId = trim(strip_tags((string) ($_GET['id'] ?? '')));
if ($transactionId === '') {
    app_legacy_payment_response('401', 'Transaction id parameter is required');
}
$payment = app_legacy_payment_by_transaction($transactionId);
if ($payment === null) {
    app_legacy_payment_response('401', 'Transaction id is invalid');
}
app_legacy_payment_response('200', 'Operation completed successfully', [
    'date' => (string) ($payment['created_at'] ?? ''),
    'amount' => (float) ($payment['amount'] ?? 0),
    'currency' => 'AZN',
    'transaction-id' => (string) ($payment['transaction_id'] ?? $transactionId),
]);
