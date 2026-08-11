<?php
declare(strict_types=1);

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

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['ok' => false, 'errors' => ['database_unavailable']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$keys = [
    'channel_whatsapp_enabled',
    'channel_app_enabled',
    'channel_telegram_enabled',
    'channel_sms_enabled',
];
$original = [];
$select = $db->prepare('SELECT setting_value FROM mgs_runtime_settings WHERE setting_key = :setting_key LIMIT 1');
$upsert = $db->prepare(
    'INSERT INTO mgs_runtime_settings (setting_key, setting_value, updated_by_name)
     VALUES (:setting_key, :setting_value, "communication-channel-switch-smoke")
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_name = VALUES(updated_by_name)'
);
$delete = $db->prepare('DELETE FROM mgs_runtime_settings WHERE setting_key = :setting_key');

foreach ($keys as $key) {
    $select->execute(['setting_key' => $key]);
    $value = $select->fetchColumn();
    $original[$key] = is_string($value) ? $value : null;
}

$set = static function (array $values) use ($upsert): void {
    foreach ($values as $key => $value) {
        $upsert->execute(['setting_key' => $key, 'setting_value' => (string) $value]);
    }
};

$account = [
    'id' => 1,
    'login' => 'channel-smoke@example.invalid',
    'phone' => '0501234567',
    'payload_json' => json_encode(['telegram' => ['chat_id' => '-1001234567890']]),
];

try {
    $set([
        'channel_whatsapp_enabled' => '0',
        'channel_app_enabled' => '1',
        'channel_telegram_enabled' => '1',
        'channel_sms_enabled' => '1',
    ]);
    $auto = app_message_channel_for_account($account, 'auto');
    $expect(($auto['channel'] ?? '') === 'app', 'auto_did_not_skip_disabled_whatsapp');

    $explicit = app_message_channel_for_account($account, 'whatsapp');
    $expect(($explicit['channel'] ?? '') === '', 'explicit_disabled_channel_not_blocked');
    $expect(($explicit['reason'] ?? '') === 'channel_disabled', 'explicit_disabled_reason_missing');

    $blocked = app_communication_send_provider('whatsapp', '994501234567', 'NO REQUEST MUST BE SENT');
    $expect(empty($blocked['ok']) && ($blocked['reason'] ?? '') === 'channel_disabled', 'provider_disabled_gate_failed');

    $set(['channel_app_enabled' => '0', 'channel_telegram_enabled' => '1']);
    $auto = app_message_channel_for_account($account, 'auto');
    $expect(($auto['channel'] ?? '') === 'telegram', 'auto_did_not_select_enabled_telegram');

    $set(['channel_telegram_enabled' => '0', 'channel_sms_enabled' => '1']);
    $auto = app_message_channel_for_account($account, 'auto');
    $expect(($auto['channel'] ?? '') === 'sms', 'auto_did_not_select_enabled_sms');

    $set([
        'channel_whatsapp_enabled' => '0',
        'channel_app_enabled' => '0',
        'channel_telegram_enabled' => '0',
        'channel_sms_enabled' => '0',
    ]);
    $auto = app_message_channel_for_account($account, 'auto');
    $expect(($auto['channel'] ?? '') === '', 'all_disabled_still_selected_channel');
    $expect(($auto['reason'] ?? '') === 'all_channels_disabled', 'all_disabled_reason_missing');
} catch (Throwable $exception) {
    $errors[] = 'exception:' . $exception->getMessage();
} finally {
    foreach ($original as $key => $value) {
        if ($value === null) {
            $delete->execute(['setting_key' => $key]);
            continue;
        }
        $upsert->execute(['setting_key' => $key, 'setting_value' => $value]);
    }
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
    'real_provider_requests' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
