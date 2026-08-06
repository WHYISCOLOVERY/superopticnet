<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/provider-api-lib.php';

app_send_security_headers();

$rawBody = (string) file_get_contents('php://input');
$requiredScope = trim((string) ($_GET['toProcessId'] ?? $_GET['to_process_id'] ?? '')) !== ''
    && trim((string) ($_GET['memberId'] ?? $_GET['member_id'] ?? $_GET['code'] ?? '')) === ''
    ? 'payment:status'
    : 'customer:read';
$auth = app_up_provider_authenticate($rawBody, $requiredScope);

$provider = (string) $auth['provider'];
if (app_provider_is_mpay($provider)) {
    app_up_provider_invalid('MPay provider is pending integration');
}

$memberId = clean_login_identifier((string) ($_GET['memberId'] ?? $_GET['member_id'] ?? $_GET['code'] ?? ''));
$toProcessId = clean_text((string) ($_GET['toProcessId'] ?? $_GET['to_process_id'] ?? ''));

if ($toProcessId !== '' && $memberId === '') {
    $externalId = app_provider_external_id($provider, $toProcessId);
    $operation = app_provider_payment_operation_by_external_id($externalId);
    app_provider_payment_by_external_id($externalId) !== null
        ? app_up_provider_approved([
            'paymentState' => (string) ($operation['state'] ?? 'payment_recorded'),
            'correlationId' => (string) ($operation['correlation_id'] ?? ''),
        ])
        : app_up_provider_not_found();
}

if ($memberId === '') {
    app_up_provider_invalid('memberId is required');
}

$account = app_provider_customer_by_code($memberId);
if ($account === null) {
    app_up_provider_not_found();
}

app_up_provider_approved(app_up_provider_member_payload($account));
