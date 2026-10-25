<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-payment-compat-lib.php';
app_send_security_headers();

// United Payment's legacy kiosk lookup service rotates the Basic password on
// the provider side.  It comes from a fixed source address and can only read
// the amount due.  Keep payment submission separately authenticated in
// payments.php; this exception can never create a transaction.
$legacyLookupSource = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$legacyLookupAuth = app_legacy_payment_authorization_header();
if (trim((string) ($_GET['id'] ?? '')) !== '') {
    error_log(sprintf(
        'legacy-payment lookup source=%s forwarded=%s basic=%s id=%s',
        $legacyLookupSource,
        trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')),
        preg_match('/^Basic\s+[^\s]+$/i', $legacyLookupAuth) === 1 ? 'present' : 'missing',
        substr(trim((string) $_GET['id']), 0, 32)
    ));
}
if (!app_legacy_payment_trusted_terminal_source()) {
    app_legacy_payment_authenticate();
}

$customerCode = trim(strip_tags((string) ($_GET['id'] ?? '')));
if ($customerCode === '') {
    app_legacy_payment_response('401', 'Client id parameter is required');
}
$account = app_legacy_payment_customer($customerCode);
if ($account === null) {
    app_legacy_payment_response('401', 'Client id is invalid', [
        'id' => null,
        'name' => null,
        'amount' => null,
        'currency' => 'AZN',
    ]);
}
app_legacy_payment_response('200', 'Operation completed successfully', app_legacy_payment_customer_data($account));
