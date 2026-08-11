<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/mgs-integration-lib.php';
require_once dirname(__DIR__) . '/communication-provider.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$errors = [];
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$messageCenter = (string) file_get_contents(dirname(__DIR__) . '/message-center.php');
$providerSource = (string) file_get_contents(dirname(__DIR__) . '/communication-provider.php');
foreach ([
    'save_sms_provider_credentials',
    'data-sms-provider-config',
    'SMS_PROVIDER_PUBLIC_KEY',
    'SMS_PROVIDER_PRIVATE_KEY',
] as $marker) {
    $expect(str_contains($messageCenter, $marker), 'message_center_marker_missing:' . $marker);
}
$expect(!str_contains($messageCenter, 'name="sms_provider_username"'), 'sms_public_key_input_must_not_exist');
$expect(!str_contains($messageCenter, 'name="sms_provider_password"'), 'sms_private_key_input_must_not_exist');
foreach ([
    "'driver' => 'pg365_json'",
    'APP_COMMUNICATION_SMS_POCTGOYERCINI_SEND_ENDPOINT',
    'api.poctgoyercini.com',
    'Authorization: Bearer ',
    "\$queryValues['publicKey']",
    'ReceiversAccepted',
] as $marker) {
    $expect(str_contains($providerSource, $marker), 'provider_marker_missing:' . $marker);
}
$expect(!str_contains($providerSource, 'poctgoyercini_xml'), 'legacy_xml_driver_must_not_exist');
$expect(!str_contains($providerSource, 'SMS-InsRequest'), 'legacy_xml_payload_must_not_exist');
$expect(!str_contains($providerSource, 'app_communication_send_poctgoyercini_sms'), 'legacy_xml_sender_must_not_exist');
$expect(str_contains($providerSource, 'app_communication_pg365_request_url_allowed'), 'request_boundary_host_guard_missing');

$expect(app_communication_normalize_sms_destination('050 123 45 67') === '994501234567', 'local_sms_number_not_normalized');
$expect(
    app_communication_poctgoyercini_send_url('api.poctgoyercini.com', 'public-key-test')
        === 'https://api.poctgoyercini.com/gateway/api/sms/v1/message/send?publicKey=public-key-test',
    'poctgoyercini_url_build_failed'
);
$expect(
    app_communication_poctgoyercini_send_url('https://example.com/gateway/api/sms/v1/message/send', 'public-key-test') === '',
    'unapproved_sms_host_not_rejected'
);

$originalPublicKey = getenv('SMS_PROVIDER_PUBLIC_KEY');
$originalPrivateKey = getenv('SMS_PROVIDER_PRIVATE_KEY');
putenv('SMS_PROVIDER_PUBLIC_KEY=public-key-smoke');
putenv('SMS_PROVIDER_PRIVATE_KEY=private-key-smoke');

// app_communication_external_delivery_enabled() is a global kill switch that
// defaults OFF (communication-provider.php ~55-63) so no environment sends a
// real message unless explicitly turned on. This smoke test only exercises
// app_communication_provider_config()'s config-resolution logic and injects a
// fake callback for the status poll (below) - it never performs a real HTTP
// send - so it is safe to flip the switch on for this process only, otherwise
// every "configured"/"reason" assertion below sees the generic
// "external_delivery_paused" short-circuit instead of the specific condition
// being tested.
$originalExternalSendEnabled = getenv('COMMUNICATION_EXTERNAL_SEND_ENABLED');
putenv('COMMUNICATION_EXTERNAL_SEND_ENABLED=1');

$db = app_db();
if ($db instanceof PDO && app_mgs_ensure_schema($db)) {
    $keys = [
        'sms_provider_driver',
        'sms_provider_endpoint',
        'sms_provider_sender',
        'sms_provider_purpose',
        'sms_provider_encoding',
        'channel_sms_enabled',
    ];
    $original = [];
    $select = $db->prepare('SELECT setting_value FROM mgs_runtime_settings WHERE setting_key = :setting_key LIMIT 1');
    foreach ($keys as $key) {
        $select->execute(['setting_key' => $key]);
        $value = $select->fetchColumn();
        $original[$key] = is_string($value) ? $value : null;
    }

    try {
        $upsert = $db->prepare(
            'INSERT INTO mgs_runtime_settings (setting_key, setting_value, updated_by_name)
             VALUES (:setting_key, :setting_value, :updated_by_name)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by_name = VALUES(updated_by_name)'
        );
        foreach ([
            'sms_provider_driver' => 'ignored-legacy-driver',
            'sms_provider_endpoint' => APP_COMMUNICATION_SMS_POCTGOYERCINI_SEND_ENDPOINT,
            'sms_provider_sender' => 'SuperOptic',
            'sms_provider_purpose' => 'INF',
            'sms_provider_encoding' => 'LATIN',
            'channel_sms_enabled' => '1',
        ] as $key => $value) {
            $upsert->execute([
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_by_name' => 'sms-provider-config-smoke',
            ]);
        }

        $provider = app_communication_provider_config('sms', '0501234567');
        $status = app_communication_provider_public_status('sms', '0501234567');
        $expect((bool) ($provider['configured'] ?? false) === true, 'runtime_sms_provider_not_configured');
        $expect((string) ($provider['driver'] ?? '') === 'pg365_json', 'runtime_sms_driver_not_forced_pg365_json');
        $expect((string) ($provider['endpoint'] ?? '') === APP_COMMUNICATION_SMS_POCTGOYERCINI_SEND_ENDPOINT, 'runtime_sms_endpoint_wrong');
        $expect(!array_key_exists('user', $provider), 'runtime_sms_config_must_not_expose_public_key');
        $expect(!array_key_exists('password', $provider), 'runtime_sms_config_must_not_expose_private_key');
        $expect(!array_key_exists('token', $provider), 'runtime_sms_config_must_not_expose_token');
        $expect(!str_contains(json_encode($provider) ?: '', 'public-key-smoke'), 'runtime_sms_config_serialized_public_key');
        $expect(!str_contains(json_encode($provider) ?: '', 'private-key-smoke'), 'runtime_sms_config_serialized_private_key');
        $expect(($provider['credential_envs'] ?? []) === ['SMS_PROVIDER_PUBLIC_KEY', 'SMS_PROVIDER_PRIVATE_KEY'], 'runtime_sms_env_names_wrong');
        $expect(!empty($status['has_credentials']) && !empty($status['has_endpoint']), 'runtime_sms_public_status_not_ready');
        $expect(($status['credential_envs'] ?? []) === ['SMS_PROVIDER_PUBLIC_KEY', 'SMS_PROVIDER_PRIVATE_KEY'], 'public_sms_status_env_names_wrong');
        $expect(!str_contains(json_encode($status) ?: '', 'public-key-smoke'), 'public_sms_status_serialized_public_key');
        $expect(!str_contains(json_encode($status) ?: '', 'private-key-smoke'), 'public_sms_status_serialized_private_key');

        $upsert->execute([
            'setting_key' => 'sms_provider_endpoint',
            'setting_value' => 'https://attacker.example/sms',
            'updated_by_name' => 'sms-provider-config-smoke',
        ]);
        $hostileProvider = app_communication_provider_config('sms', '0501234567');
        $expect((string) ($hostileProvider['driver'] ?? '') === 'pg365_json', 'DB driver must not select another transport');
        $expect(empty($hostileProvider['configured']), 'unapproved DB endpoint must block SMS send');
        $expect(empty($hostileProvider['status_configured']), 'unapproved DB endpoint must block status request');
        $expect(!array_key_exists('token', $hostileProvider), 'private key must not enter generic token field');
        $expect((string) ($hostileProvider['reason'] ?? '') === 'sms_provider_endpoint_invalid', 'invalid endpoint reason missing');

        $upsert->execute([
            'setting_key' => 'sms_provider_endpoint',
            'setting_value' => APP_COMMUNICATION_SMS_PG365_SEND_ENDPOINT,
            'updated_by_name' => 'sms-provider-config-smoke',
        ]);
        $upsert->execute([
            'setting_key' => 'channel_sms_enabled',
            'setting_value' => '0',
            'updated_by_name' => 'sms-provider-config-smoke',
        ]);
        $disabledProvider = app_communication_provider_config('sms', '0501234567');
        $expect(empty($disabledProvider['configured']), 'disabled SMS must block new sends');
        $expect(!empty($disabledProvider['status_configured']), 'disabled SMS must retain status polling configuration');
        $disabledPollRequests = 0;
        $disabledPoll = app_communication_pg365_poll_status(
            $disabledProvider,
            'disabled-switch-status-id',
            static function () use (&$disabledPollRequests): array {
                $disabledPollRequests++;
                return [
                    'Status' => 200,
                    'Result' => [
                        'id' => 'disabled-switch-status-id',
                        'Status' => 300,
                        'StatusDescription' => 'Sent',
                        'IsFinal' => false,
                    ],
                ];
            }
        );
        $expect(!empty($disabledPoll['ok']) && $disabledPollRequests === 1, 'disabled SMS status poll did not run');
    } catch (Throwable $exception) {
        $errors[] = 'runtime_sms_provider_exception:' . $exception->getMessage();
    } finally {
        $restore = $db->prepare(
            'INSERT INTO mgs_runtime_settings (setting_key, setting_value, updated_by_name)
             VALUES (:setting_key, :setting_value, :updated_by_name)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by_name = VALUES(updated_by_name)'
        );
        $delete = $db->prepare('DELETE FROM mgs_runtime_settings WHERE setting_key = :setting_key');
        foreach ($original as $key => $value) {
            if ($value === null) {
                $delete->execute(['setting_key' => $key]);
                continue;
            }
            $restore->execute([
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_by_name' => 'sms-provider-config-smoke-restore',
            ]);
        }
    }
}

if ($originalPublicKey === false) {
    putenv('SMS_PROVIDER_PUBLIC_KEY');
} else {
    putenv('SMS_PROVIDER_PUBLIC_KEY=' . $originalPublicKey);
}
if ($originalPrivateKey === false) {
    putenv('SMS_PROVIDER_PRIVATE_KEY');
} else {
    putenv('SMS_PROVIDER_PRIVATE_KEY=' . $originalPrivateKey);
}
if ($originalExternalSendEnabled === false) {
    putenv('COMMUNICATION_EXTERNAL_SEND_ENABLED');
} else {
    putenv('COMMUNICATION_EXTERNAL_SEND_ENABLED=' . $originalExternalSendEnabled);
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
