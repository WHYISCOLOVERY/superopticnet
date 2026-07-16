<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/mgs-integration-lib.php';
require_once dirname(__DIR__) . '/communication-provider.php';
require_once dirname(__DIR__) . '/live-cutover-lib.php';

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
    'sms_provider_username',
    'sms_provider_password',
    'data-sms-provider-config',
    'api.poctgoyercini.com',
] as $marker) {
    $expect(str_contains($messageCenter, $marker), 'message_center_marker_missing:' . $marker);
}
foreach ([
    'poctgoyercini_json',
    'APP_COMMUNICATION_SMS_POCTGOYERCINI_SEND_ENDPOINT',
    'Authorization: Bearer ',
    'publicKey=',
    'ReceiversAccepted',
] as $marker) {
    $expect(str_contains($providerSource, $marker), 'provider_marker_missing:' . $marker);
}

$expect(app_communication_normalize_sms_destination('050 123 45 67') === '994501234567', 'local_sms_number_not_normalized');
$expect(
    app_communication_poctgoyercini_send_url('api.poctgoyercini.com', 'public-key-test')
        === 'https://api.poctgoyercini.com/gateway/api/sms/v1/message/send?publicKey=public-key-test',
    'poctgoyercini_url_build_failed'
);

$db = app_db();
if ($db instanceof PDO && app_mgs_ensure_schema($db)) {
    $keys = [
        'sms_provider_driver',
        'sms_provider_endpoint',
        'sms_provider_user',
        'sms_provider_password',
        'sms_provider_sender',
        'sms_provider_purpose',
        'sms_provider_encoding',
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
            'sms_provider_driver' => 'poctgoyercini_json',
            'sms_provider_endpoint' => APP_COMMUNICATION_SMS_POCTGOYERCINI_SEND_ENDPOINT,
            'sms_provider_user' => 'public-key-smoke',
            'sms_provider_password' => 'private-key-smoke',
            'sms_provider_sender' => 'SuperOptic',
            'sms_provider_purpose' => 'INF',
            'sms_provider_encoding' => 'LATIN',
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
        $expect((string) ($provider['driver'] ?? '') === 'poctgoyercini_json', 'runtime_sms_driver_not_json');
        $expect((string) ($provider['endpoint'] ?? '') === APP_COMMUNICATION_SMS_POCTGOYERCINI_SEND_ENDPOINT, 'runtime_sms_endpoint_wrong');
        $expect((string) ($provider['user'] ?? '') === 'public-key-smoke', 'runtime_sms_user_wrong');
        $expect((string) ($provider['password'] ?? '') === 'private-key-smoke', 'runtime_sms_password_wrong');
        $expect(!empty($status['has_credentials']) && !empty($status['has_endpoint']), 'runtime_sms_public_status_not_ready');
        $smsGate = null;
        foreach (app_live_cutover_gates($db) as $gate) {
            if ((string) ($gate['key'] ?? '') === 'real_sms_send') {
                $smsGate = $gate;
                break;
            }
        }
        $expect(is_array($smsGate), 'live_sms_gate_missing');
        $expect((string) ($smsGate['status'] ?? '') === 'ready_for_controlled_apply', 'live_sms_gate_not_ready');
        $expect(str_contains((string) ($smsGate['evidence'] ?? ''), 'driver=poctgoyercini_json'), 'live_sms_gate_driver_not_runtime_json');
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

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
