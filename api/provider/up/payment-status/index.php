<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/provider-api-lib.php';

app_send_security_headers();

$rawBody = (string) file_get_contents('php://input');
$auth = app_up_provider_authenticate($rawBody, 'payment:status');

$provider = (string) $auth['provider'];
if (app_provider_is_mpay($provider)) {
    app_up_provider_invalid('MPay provider is pending integration');
}

$toProcessId = clean_text((string) ($_GET['toProcessId'] ?? $_GET['to_process_id'] ?? ''));
if ($toProcessId === '') {
    app_up_provider_invalid('toProcessId is required');
}

$externalId = app_provider_external_id($provider, $toProcessId);
$payment = app_provider_payment_by_external_id($externalId);
$operation = app_provider_payment_operation_by_external_id($externalId);
$payment !== null
    ? app_up_provider_approved([
        'paymentState' => (string) ($operation['state'] ?? 'payment_recorded'),
        'correlationId' => (string) ($operation['correlation_id'] ?? ''),
    ])
    : app_up_provider_not_found();
