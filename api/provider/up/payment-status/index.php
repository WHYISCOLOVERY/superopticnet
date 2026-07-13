<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/provider-api-lib.php';

app_send_security_headers();

$rawBody = (string) file_get_contents('php://input');
app_provider_authenticate($rawBody);

$provider = app_up_provider_name_from_request();
if (app_provider_is_mpay($provider)) {
    app_up_provider_invalid('MPay provider is pending integration');
}

$toProcessId = clean_text((string) ($_GET['toProcessId'] ?? $_GET['to_process_id'] ?? ''));
if ($toProcessId === '') {
    app_up_provider_invalid('toProcessId is required');
}

$externalId = app_provider_external_id($provider, $toProcessId);
app_provider_payment_by_external_id($externalId) !== null
    ? app_up_provider_approved()
    : app_up_provider_not_found();
