<?php
declare(strict_types=1);

require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/communication-provider.php';

/**
 * @return array<int,array<string,mixed>>
 */
function app_live_cutover_gates(?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if ($db instanceof PDO) {
        app_mgs_ensure_schema($db);
        app_communication_whatsapp_guard_schema($db);
    }

    $count = static function (string $sql) use ($db): int {
        if (!$db instanceof PDO) {
            return 0;
        }
        try {
            return (int) $db->query($sql)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    };

    $secretStatus = static function (string $value, array $badDefaults = []): bool {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        $upper = strtoupper($value);
        if (str_contains($upper, 'CHANGE_ME') || str_contains($upper, 'YOUR_') || str_contains($upper, 'EXAMPLE')) {
            return false;
        }
        return !in_array($value, $badDefaults, true);
    };

    $radiusSettings = app_radius_settings();
    $radiusReadiness = app_radius_disconnect_worker_readiness($radiusSettings, $db);
    $managementPathReady = app_mgs_management_path_ready();
    $reachableDevices = $count('SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = "reachable"');
    $waitingDevices = $count('SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = "unreachable_because_monitor_path_down"');
    $telegramReady = $secretStatus(app_communication_environment_secret('TELEGRAM_TOKEN')) && $secretStatus(app_config('telegram_chat_id'));
    $smsProvider = app_communication_provider_config('sms', '994000000000');
    $smsDriver = strtolower(trim((string) ($smsProvider['driver'] ?? '')));
    $smsReady = !empty($smsProvider['configured'])
        && $secretStatus((string) ($smsProvider['endpoint'] ?? ''))
        && (
            $secretStatus((string) ($smsProvider['token'] ?? ''))
            || (
                $secretStatus((string) ($smsProvider['user'] ?? ''))
                && $secretStatus((string) ($smsProvider['password'] ?? ''))
            )
        );
    $whatsappEnabled = in_array(strtolower((string) app_setting('whatsapp_provider_enabled', '0')), ['1', 'true', 'yes', 'on'], true);
    $whatsappBridge = $whatsappEnabled ? app_communication_whatsapp_bridge_status() : ['reachable' => false, 'connected' => false, 'status' => 'disabled'];

    return [
        [
            'key' => 'vlan550_management_path',
            'title' => 'VLAN 550 management path',
            'status' => $managementPathReady ? 'ready' : 'blocked',
            'severity' => $managementPathReady ? 'ok' : 'manual',
            'evidence' => $managementPathReady
                ? 'Management path is marked ready; reachable devices=' . $reachableDevices . '.'
                : 'Management path is not connected/enabled; suppressed devices=' . $waitingDevices . '.',
            'next' => $managementPathReady
                ? 'Run device probe and HSGQ live ONT smoke.'
                : 'Connect VLAN 550, then enable management path and run device probe.',
            'command' => 'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\native-network-monitor-smoke.php',
        ],
        [
            'key' => 'hsgq_live_ont_optical',
            'title' => 'HSGQ live ONT optical read',
            'status' => ($managementPathReady && $reachableDevices > 0) ? 'ready_for_test' : 'blocked',
            'severity' => ($managementPathReady && $reachableDevices > 0) ? 'ok' : 'manual',
            'evidence' => 'management_path=' . ($managementPathReady ? 'ready' : 'down') . '; reachable_devices=' . $reachableDevices . '.',
            'next' => ($managementPathReady && $reachableDevices > 0)
                ? 'Run HSGQ ONT smoke with confirmed test SN/MAC.'
                : 'Do not claim live optical validation until VLAN/device reachability is ready.',
            'command' => 'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\hsgq-live-ont-smoke.php',
        ],
        [
            'key' => 'radius_live_coa',
            'title' => 'FreeRADIUS CoA/disconnect live cutover',
            'status' => !empty($radiusReadiness['ok']) ? 'ready_for_controlled_apply' : 'blocked',
            'severity' => !empty($radiusReadiness['ok']) ? 'manual' : 'blocker',
            'evidence' => !empty($radiusReadiness['ok'])
                ? 'Worker config ready; target=' . (string) ($radiusSettings['router_ip'] ?? '') . ':' . (string) ($radiusSettings['coa_port'] ?? 3799) . '.'
                : 'Worker not ready: ' . (string) ($radiusReadiness['reason'] ?? 'unknown') . '.',
            'next' => !empty($radiusReadiness['ok'])
                ? 'Use only a selected test PPPoE/IPoE session; default smoke is dry-run.'
                : 'Configure router IP, shared secret, NAS source and CoA port first.',
            'command' => 'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\radius-live-coa-smoke.php',
            'apply_command' => 'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\radius-live-coa-smoke.php --apply --auto-approve',
        ],
        [
            'key' => 'real_sms_send',
            'title' => 'Controlled paid SMS send',
            'status' => $smsReady ? 'ready_for_controlled_apply' : 'blocked',
            'severity' => $smsReady ? 'manual' : 'blocker',
            'evidence' => $smsReady ? 'SMS provider configured; driver=' . $smsDriver . '.' : 'SMS provider endpoint/credentials are missing.',
            'next' => $smsReady ? 'Send one TEST MESAJI only to an approved destination.' : 'Configure SMS provider before paid send.',
            'command' => 'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\test-communication-provider.php --channel=sms --destination=994XXXXXXXXX',
            'apply_command' => 'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\test-communication-provider.php --channel=sms --destination=994XXXXXXXXX --apply',
        ],
        [
            'key' => 'whatsapp_web_bridge',
            'title' => 'WhatsApp Web bridge controlled send',
            'status' => (!empty($whatsappBridge['reachable']) && !empty($whatsappBridge['connected'])) ? 'ready_for_controlled_apply' : 'blocked',
            'severity' => (!empty($whatsappBridge['reachable']) && !empty($whatsappBridge['connected'])) ? 'manual' : 'blocker',
            'evidence' => 'bridge=' . (string) ($whatsappBridge['status'] ?? 'unknown') . '; connected=' . (!empty($whatsappBridge['connected']) ? 'yes' : 'no') . '.',
            'next' => (!empty($whatsappBridge['reachable']) && !empty($whatsappBridge['connected']))
                ? 'Use guard limits; if WhatsApp limit is hit, SMS fallback should take over.'
                : 'Start bridge and scan QR in message center.',
            'command' => 'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\whatsapp-guard-smoke.php',
        ],
        [
            'key' => 'telegram_live_polling',
            'title' => 'Telegram live polling/apply',
            'status' => $telegramReady ? 'ready_for_controlled_apply' : 'blocked',
            'severity' => $telegramReady ? 'manual' : 'blocker',
            'evidence' => $telegramReady ? 'Telegram token/chat configured.' : 'Telegram token/chat missing.',
            'next' => $telegramReady ? 'Run live polling only after confirming bot groups to avoid old update replies.' : 'Configure Telegram bot token and chat first.',
            'command' => 'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\test-telegram-bots.php',
        ],
    ];
}

function app_live_cutover_gate_badge_class(string $severity): string
{
    return match ($severity) {
        'ok' => 'portal-pill-success',
        'blocker' => 'portal-pill-danger',
        default => 'system-health-warning',
    };
}
