<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/noc-scan-results-lib.php';
require_once __DIR__ . '/ui-language-lib.php';

$lang = app_lang();
$user = billing_require_employee();
$db = app_db();
$flash = null;
$scanState = [];
$backupWorkerResult = null;
$deviceActionResult = null;
$liveOntResult = null;
$modemBotPreview = null;
$submittedOntQuery = clean_text((string) ($_REQUEST['ont_q'] ?? ''));

if ($db instanceof PDO) {
    app_mgs_ensure_schema($db);
}

app_session_boot();
if (is_array($_SESSION['noc_scan_state'] ?? null)) {
    $scanState = $_SESSION['noc_scan_state'];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf($csrfToken)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (!$db instanceof PDO) {
        $flash = ['type' => 'error', 'message' => 'Database baglantisi aktiv deyil.'];
    } elseif ($action === 'scan_network') {
        $payload = [
            'target' => clean_text((string) ($_POST['scan_target'] ?? '')),
            'ports' => clean_text((string) ($_POST['scan_ports'] ?? '22,80,443,161')),
            'maxHosts' => max(1, min(1024, (int) ($_POST['scan_max_hosts'] ?? 64))),
            'concurrency' => max(1, min(128, (int) ($_POST['scan_concurrency'] ?? 24))),
        ];
        $result = app_mgs_noc_service_request_json(
            '/api/service/network-scan',
            'POST',
            $payload,
            $user,
            'scan_network',
            [],
            60
        );
        if (!empty($result['ok'])) {
            $scanState = is_array($result['data'] ?? null) ? $result['data'] : [];
            if (!array_key_exists('target', $scanState)) {
                $scanState['target'] = $payload['target'];
            }
            $_SESSION['noc_scan_state'] = $scanState;
        }
        $flash = [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => !empty($result['ok'])
                ? 'Scan tamamlandi. Tapilan cihazlari secib monitorinqe elave edin.'
                : 'IP scan icra olunmadi: ' . (string) ($result['error'] ?? 'service cavabi yoxdur'),
        ];
    } elseif ($action === 'create_monitor_device') {
        $payload = [
            'name' => clean_text((string) ($_POST['device_name'] ?? '')),
            'ipAddress' => clean_text((string) ($_POST['device_ip'] ?? '')),
            'deviceType' => clean_text((string) ($_POST['device_type'] ?? 'auto')),
            'vendor' => clean_text((string) ($_POST['device_vendor'] ?? '')),
            'model' => clean_text((string) ($_POST['device_model'] ?? '')),
            'location' => clean_text((string) ($_POST['device_location'] ?? '')),
            'noteText' => clean_text((string) ($_POST['device_note'] ?? '')),
            'sshPort' => max(1, (int) ($_POST['ssh_port'] ?? 22)),
            'sshUsername' => clean_text((string) ($_POST['ssh_username'] ?? '')),
            'sshPassword' => (string) ($_POST['ssh_password'] ?? ''),
            'snmpPort' => max(1, (int) ($_POST['snmp_port'] ?? 161)),
            'snmpTrapPort' => max(1, (int) ($_POST['snmp_trap_port'] ?? 162)),
            'snmpVersion' => clean_text((string) ($_POST['snmp_version'] ?? '2c')),
            'snmpReadCommunity' => clean_text((string) ($_POST['snmp_read_community'] ?? '')),
            'snmpWriteCommunity' => clean_text((string) ($_POST['snmp_write_community'] ?? '')),
            'driverMode' => clean_text((string) ($_POST['driver_mode'] ?? 'real')),
        ];
        $result = app_mgs_noc_service_request_json(
            '/api/service/devices',
            'POST',
            $payload,
            $user,
            'create_monitor_device',
            ['management_network' => (string) ($payload['location'] ?? '')],
            60
        );
        $flash = [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => !empty($result['ok'])
                ? 'Cihaz yaradildi ve discovery/probe axini ucun queue-ya yazildi.'
                : 'Cihaz elave olunmadi: ' . (string) ($result['error'] ?? 'service cavabi yoxdur'),
        ];
    } elseif ($action === 'set_management_path') {
        $enabled = (string) ($_POST['enabled'] ?? '0') === '1';
        $result = app_mgs_set_management_path($enabled, $user, $db);
        $flash = [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => $enabled
                ? 'VLAN 550 monitorinq yolu aktiv edildi. Probe emeliyyati artiq icazelidir.'
                : 'VLAN 550 monitorinq yolu passiv edildi. Internal cihaz offline alarmlari bloklanir.',
        ];
    } elseif ($action === 'probe_devices') {
        $result = app_mgs_refresh_device_reachability($db, true, $user);
        app_mgs_record_operation_action([
            'action_type' => 'device_probe',
            'entity_type' => 'device_learning_profiles',
            'entity_id' => 'all',
            'status' => !empty($result['ok']) ? 'executed' : 'failed',
            'actor' => $user,
            'summary' => 'NOC device probe run',
            'payload' => $result,
        ]);
        $flash = [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => 'Probe neticesi: ' . (int) ($result['reachable'] ?? 0) . '/' . (int) ($result['probed'] ?? 0) . ' reachable, suppressed ' . (int) ($result['suppressed'] ?? 0) . '.',
        ];
    } elseif ($action === 'run_backup_worker') {
        $apply = isset($_POST['apply_backup']) && app_portal_is_admin($user);
        $backupType = clean_text((string) ($_POST['backup_type'] ?? 'profile_snapshot'));
        $limit = (int) ($_POST['limit'] ?? 5);
        $backupWorkerResult = app_mgs_run_backup_worker($db, $apply, $limit, $backupType, $user);
        $flash = [
            'type' => !empty($backupWorkerResult['ok']) ? 'success' : 'error',
            'message' => !empty($backupWorkerResult['ok'])
                ? 'Backup worker ' . ($apply ? 'apply' : 'dry-run') . ': checked ' . (int) ($backupWorkerResult['checked'] ?? 0) . ', stored ' . (int) ($backupWorkerResult['stored'] ?? 0) . ', duplicate ' . (int) ($backupWorkerResult['duplicates'] ?? 0) . ', skipped ' . (int) ($backupWorkerResult['skipped'] ?? 0) . '.'
                : 'Backup worker xetasi: ' . (string) ($backupWorkerResult['reason'] ?? 'unknown'),
        ];
    } elseif ($action === 'probe_single_device') {
        $deviceKey = clean_text((string) ($_POST['device_key'] ?? ''));
        $deviceActionResult = app_mgs_probe_single_device($deviceKey, $db, $user);
        $flash = [
            'type' => !empty($deviceActionResult['ok']) && (($deviceActionResult['mode'] ?? '') !== 'probe' || !empty($deviceActionResult['reachable'])) ? 'success' : ((!empty($deviceActionResult['ok'])) ? 'warning' : 'error'),
            'message' => !empty($deviceActionResult['ok'])
                ? (
                    ($deviceActionResult['mode'] ?? '') === 'probe'
                        ? ((string) ($deviceActionResult['device_name'] ?? $deviceKey)) . ': ' . (string) ($deviceActionResult['state'] ?? 'unknown') . ' [' . (string) ($deviceActionResult['method'] ?? '-') . ']'
                        : ((string) ($deviceActionResult['device_name'] ?? $deviceKey)) . ': ' . (string) ($deviceActionResult['reason'] ?? 'skipped')
                )
                : 'Cihaz probe xetasi: ' . (string) ($deviceActionResult['reason'] ?? 'unknown'),
        ];
    } elseif ($action === 'discover_device_interfaces') {
        $deviceKey = clean_text((string) ($_POST['device_key'] ?? ''));
        $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $user);
        if (!is_array($device)) {
            $flash = ['type' => 'error', 'message' => 'Cihaz profili tapilmadi.'];
        } else {
            $deviceActionResult = app_mgs_discover_device_interfaces($device, $db, $user);
            $flash = [
                'type' => !empty($deviceActionResult['ok']) ? 'success' : 'error',
                'message' => !empty($deviceActionResult['ok'])
                    ? ((string) ($device['device_name'] ?? $deviceKey)) . ': ' . (int) ($deviceActionResult['interface_count'] ?? 0) . ' interfeys askarlandi/yenilendi'
                    : 'Interfeys kesfi xetasi: ' . (string) ($deviceActionResult['reason'] ?? 'unknown'),
            ];
        }
    } elseif ($action === 'discover_device_neighbors') {
        $deviceKey = clean_text((string) ($_POST['device_key'] ?? ''));
        $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $user);
        if (!is_array($device)) {
            $flash = ['type' => 'error', 'message' => 'Cihaz profili tapilmadi.'];
        } else {
            $deviceActionResult = app_mgs_discover_device_neighbors($device, $db, $user);
            $flash = [
                'type' => !empty($deviceActionResult['ok']) ? 'success' : 'error',
                'message' => !empty($deviceActionResult['ok'])
                    ? ((string) ($device['device_name'] ?? $deviceKey)) . ': ' . (int) ($deviceActionResult['neighbor_count'] ?? 0) . ' qonşu askarlandi/yenilendi'
                    : 'Qonşu kesfi xetasi: ' . (string) ($deviceActionResult['reason'] ?? 'unknown'),
            ];
        }
    } elseif ($action === 'backup_single_device') {
        $deviceKey = clean_text((string) ($_POST['device_key'] ?? ''));
        $backupType = clean_text((string) ($_POST['backup_type'] ?? 'profile_snapshot'));
        $apply = isset($_POST['apply_backup']) && app_portal_is_admin($user);
        $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $user);
        if (!is_array($device)) {
            $flash = ['type' => 'error', 'message' => 'Cihaz profili tapilmadi.'];
        } else {
            $deviceActionResult = app_mgs_store_device_backup($device, $backupType, $apply, $user, $db);
            $flash = [
                'type' => !empty($deviceActionResult['ok']) ? 'success' : 'error',
                'message' => !empty($deviceActionResult['ok'])
                    ? ((string) ($device['device_name'] ?? $deviceKey)) . ': backup ' . (string) ($deviceActionResult['mode'] ?? 'done') . ' [' . $backupType . ']'
                    : 'Per-device backup xetasi: ' . (string) ($deviceActionResult['reason'] ?? 'unknown'),
            ];
        }
    } elseif ($action === 'run_cli_discovery') {
        $deviceKey = clean_text((string) ($_POST['device_key'] ?? ''));
        $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $user);
        if (!is_array($device)) {
            $flash = ['type' => 'error', 'message' => 'Cihaz profili tapilmadi.'];
        } else {
            $deviceActionResult = app_mgs_store_device_backup($device, 'cli_discovery_snapshot', true, $user, $db);
            $cliDiscoveryErrorMessage = 'CLI discovery xetasi: ' . (string) ($deviceActionResult['reason'] ?? 'unknown');
            if ((string) ($deviceActionResult['reason'] ?? '') === 'cli_discovery_not_supported') {
                $supportedVendors = implode(', ', (array) ($deviceActionResult['supported_vendors'] ?? []));
                $cliDiscoveryErrorMessage = 'Bu vendor ucun "ogren" (CLI discovery) helelik yoxdur. Hazirda deste olunanlar: ' . ($supportedVendors !== '' ? $supportedVendors : '-') . '. Yeni vendor elave etmek ucun app_mgs_cli_discovery_handler_registry()-e bir qeyd elave edin.';
            }
            $flash = [
                'type' => !empty($deviceActionResult['ok']) ? 'success' : 'error',
                'message' => !empty($deviceActionResult['ok'])
                    ? ((string) ($device['device_name'] ?? $deviceKey)) . ': CLI discovery ' . (string) ($deviceActionResult['mode'] ?? 'done')
                    : $cliDiscoveryErrorMessage,
            ];
        }
    } elseif ($action === 'set_device_monitoring_mode') {
        $deviceKey = clean_text((string) ($_POST['device_key'] ?? ''));
        $mode = clean_text((string) ($_POST['monitor_mode'] ?? 'active'));
        $deviceActionResult = app_mgs_set_device_monitoring_mode($deviceKey, $mode, $user, $db);
        $flash = [
            'type' => !empty($deviceActionResult['ok']) ? 'success' : 'error',
            'message' => !empty($deviceActionResult['ok'])
                ? ((string) ($deviceActionResult['device_name'] ?? $deviceKey)) . ': ' . (string) ($deviceActionResult['summary'] ?? $mode)
                : 'Monitor mode xetasi: ' . (string) ($deviceActionResult['reason'] ?? 'unknown'),
        ];
    } elseif ($action === 'set_device_access_override') {
        $deviceKey = clean_text((string) ($_POST['device_key'] ?? ''));
        $deviceActionResult = app_mgs_set_device_access_override($deviceKey, [
            'ssh_username' => (string) ($_POST['ssh_username'] ?? ''),
            'ssh_password' => (string) ($_POST['ssh_password'] ?? ''),
            'ssh_port' => (int) ($_POST['ssh_port'] ?? 22),
            'snmp_read_community' => (string) ($_POST['snmp_read_community'] ?? ''),
            'snmp_write_community' => (string) ($_POST['snmp_write_community'] ?? ''),
            'snmp_port' => (int) ($_POST['snmp_port'] ?? 161),
            'trap_server' => (string) ($_POST['trap_server'] ?? ''),
            'trap_port' => (int) ($_POST['trap_port'] ?? 162),
            'contact' => (string) ($_POST['contact'] ?? ''),
            'location' => (string) ($_POST['location'] ?? ''),
            'notes' => (string) ($_POST['notes'] ?? ''),
        ], $user, $db);
        $flash = [
            'type' => !empty($deviceActionResult['ok']) ? 'success' : 'error',
            'message' => !empty($deviceActionResult['ok'])
                ? ((string) ($deviceActionResult['device_name'] ?? $deviceKey)) . ': access override yadda saxlandi'
                : 'Access override xetasi: ' . (string) ($deviceActionResult['reason'] ?? 'unknown'),
        ];
    } elseif ($action === 'set_device_command_map') {
        if (!app_portal_is_admin($user)) {
            $flash = ['type' => 'error', 'message' => 'Command map ucun admin icazesi lazimdir.'];
        } else {
            $deviceKey = clean_text((string) ($_POST['device_key'] ?? ''));
            $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $user);
            $commandMapText = trim((string) ($_POST['command_map_text'] ?? ''));
            $commandMap = [];
            foreach (preg_split('/\R/', $commandMapText) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '!')) {
                    continue;
                }
                [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                $key = strtolower(preg_replace('/[^a-z0-9_]+/', '_', trim($key)) ?? '');
                $value = trim($value);
                if ($key !== '' && $value !== '') {
                    $commandMap[$key] = $value;
                }
            }
            if (!is_array($device)) {
                $flash = ['type' => 'error', 'message' => 'Command map ucun cihaz profili tapilmadi.'];
            } elseif ($commandMap === []) {
                $flash = ['type' => 'error', 'message' => 'Command map bosdur. key=command formatinda yazin.'];
            } else {
                $deviceActionResult = app_mgs_sync_device_command_map($deviceKey, $commandMap, $user, $db);
                $flash = !empty($deviceActionResult['ok'])
                    ? ['type' => 'success', 'message' => ((string) ($device['device_name'] ?? $deviceKey)) . ': command map yadda saxlandi.']
                    : ['type' => 'error', 'message' => 'Command map xetasi: ' . (string) ($deviceActionResult['reason'] ?? 'unknown')];
            }
        }
    } elseif ($action === 'apply_vendor_access_defaults') {
        $vendor = clean_text((string) ($_POST['vendor'] ?? 'HSGQ'));
        $deviceActionResult = app_mgs_apply_vendor_access_defaults($vendor, $user, $db);
        if (!empty($deviceActionResult['ok'])) {
            app_mgs_refresh_device_reachability($db, true, $user);
        }
        $flash = [
            'type' => !empty($deviceActionResult['ok']) ? 'success' : 'error',
            'message' => !empty($deviceActionResult['ok'])
                ? ((string) ($deviceActionResult['vendor'] ?? $vendor)) . ' defaults apply olundu: ' . (int) ($deviceActionResult['count'] ?? 0) . ' cihaz'
                : 'Vendor default xetasi: ' . (string) ($deviceActionResult['reason'] ?? 'unknown'),
        ];
    } elseif ($action === 'live_ont_read') {
        $oltName = clean_text((string) ($_POST['olt'] ?? ''));
        $pon = clean_text((string) ($_POST['pon'] ?? ''));
        $ontId = clean_text((string) ($_POST['ont_id'] ?? ''));
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $device = app_mgs_hsgq_device_by_olt_name($oltName, $db, $user);
        if (!is_array($device)) {
            $flash = ['type' => 'error', 'message' => 'Live OLT query ucun HSGQ cihaz profili tapilmadi.'];
        } else {
            $liveOntResult = app_mgs_hsgq_live_ont_snapshot($device, $pon, $ontId, $user, $db);
            if (!empty($liveOntResult['ok'])) {
                $liveOntResult['account_id'] = $accountId;
                $liveOntResult['olt'] = $oltName;
                app_mgs_record_operation_action([
                    'action_type' => 'ont_live_optical_read',
                    'entity_type' => 'ont',
                    'entity_id' => $oltName . '/' . $pon . '/' . $ontId,
                    'status' => 'executed',
                    'actor' => $user,
                    'summary' => 'Live ONT optical read',
                    'payload' => $liveOntResult,
                ]);
                $flash = ['type' => 'success', 'message' => $oltName . ' / ' . $pon . ' / ' . $ontId . ': live optical oxundu.'];
            } elseif ((string) ($liveOntResult['reason'] ?? '') === 'management_path_down') {
                $flash = ['type' => 'warning', 'message' => 'VLAN 550 hazir deyil. Live OLT optical oxunmadi, yalniz stored context gosterile biler.'];
            } else {
                $flash = ['type' => 'error', 'message' => 'Live OLT query xetasi: ' . (string) ($liveOntResult['reason'] ?? 'unknown')];
            }
        }
    } elseif ($action === 'device_cli_command') {
        if (!app_portal_is_admin($user)) {
            $flash = ['type' => 'error', 'message' => 'Real cihaz emri ucun admin icazesi lazimdir.'];
        } else {
            $deviceKey = clean_text((string) ($_POST['device_key'] ?? ''));
            $commandText = trim((string) ($_POST['commands'] ?? ''));
            $allowDangerous = isset($_POST['allow_dangerous']);
            $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $user);
            if (!is_array($device)) {
                $flash = ['type' => 'error', 'message' => 'Real command ucun cihaz profili tapilmadi.'];
            } elseif ($commandText === '') {
                $flash = ['type' => 'error', 'message' => 'Icra edilecek command yazilmayib.'];
            } else {
                $deviceActionResult = app_mgs_execute_device_command_plan(
                    $device,
                    app_mgs_command_list_from_text($commandText),
                    $user,
                    $db,
                    [
                        'allow_dangerous' => $allowDangerous,
                        'action_type' => 'device_manual_cli_command',
                        'timeout_ms' => 12000,
                        'reason' => clean_text((string) ($_POST['command_reason'] ?? '')),
                    ]
                );
                if (!empty($deviceActionResult['ok'])) {
                    $flash = ['type' => 'success', 'message' => ((string) ($device['device_name'] ?? $deviceKey)) . ': real SSH command icra olundu, audit yazildi.'];
                } elseif ((string) ($deviceActionResult['reason'] ?? '') === 'dangerous_command_staged_for_approval') {
                    $flash = ['type' => 'success', 'message' => ((string) ($device['device_name'] ?? $deviceKey)) . ': write emr sorgusu qeyde alindi (#' . (int) ($deviceActionResult['request_id'] ?? 0) . ') - BASQA admin tesdiqleyene qeder icra olunmayacaq.'];
                } elseif ((string) ($deviceActionResult['reason'] ?? '') === 'reason_required') {
                    $flash = ['type' => 'error', 'message' => 'Write emrleri ucun sebeb sahesi mecburidir.'];
                } else {
                    $flash = ['type' => 'error', 'message' => ((string) ($device['device_name'] ?? $deviceKey)) . ': command xetasi - ' . (string) ($deviceActionResult['reason'] ?? 'unknown')];
                }
            }
        }
    } elseif ($action === 'resolve_device_command_request') {
        if (!app_portal_is_admin($user)) {
            $flash = ['type' => 'error', 'message' => 'Sorgunu yalniz admin heli ede biler.'];
        } else {
            $resolveResult = app_mgs_resolve_device_command_request(
                $db,
                (int) ($_POST['request_id'] ?? 0),
                clean_text((string) ($_POST['decision'] ?? '')),
                clean_text((string) ($_POST['resolution_note'] ?? '')),
                $user
            );
            $resolveErrorMessages = [
                'invalid_decision' => 'Qerar "approved" ve ya "rejected" olmalidir.',
                'request_not_found' => 'Sorgu tapilmadi.',
                'request_already_resolved' => 'Bu sorgu artiq helli olunub.',
                'requester_cannot_approve_own_request' => 'Sorgu eden sexs oz sorgusunu tesdiqleye bilmez - basqa admin lazimdir.',
                'device_not_found' => 'Cihaz profili tapilmadi.',
                'device_command_execution_failed' => 'Tesdiqlendi, amma real icra ugursuz oldu (sorgu hele pending qalir, yeniden cehd edile biler).',
            ];
            $flash = [
                'type' => !empty($resolveResult['ok']) ? 'success' : 'error',
                'message' => !empty($resolveResult['ok'])
                    ? ('Cihaz emri sorgusu helli olundu: ' . (string) ($resolveResult['status'] ?? ''))
                    : ($resolveErrorMessages[(string) ($resolveResult['error'] ?? '')] ?? ('Sorgu helli olunmadi: ' . ((string) ($resolveResult['error'] ?? '') ?: 'DB cavabi yoxdur'))),
            ];
        }
    }
}

$ontQuery = $submittedOntQuery;
$validNocViews = ['monitor', 'devices', 'backups', 'ont-search', 'legacy-onts'];
$activeNocView = clean_text((string) ($_GET['view'] ?? 'monitor'));
if ($ontQuery !== '') {
    $activeNocView = 'ont-search';
}
if (!in_array($activeNocView, $validNocViews, true)) {
    $activeNocView = 'monitor';
}
$selectedDeviceKey = clean_text((string) ($_GET['device'] ?? ''));
$needsDeviceRows = in_array($activeNocView, ['monitor', 'devices'], true);
$needsBackupRows = $activeNocView === 'backups';
$needsLegacyOntRows = $activeNocView === 'legacy-onts';

$summary = $db instanceof PDO ? app_mgs_summary($db, false, $user) : ['db' => false];
$devices = ($db instanceof PDO && $needsDeviceRows) ? app_mgs_device_learning_rows($db, $user) : [];
$bridge = app_mgs_bridge_status();
$backups = $needsBackupRows ? app_mgs_noc_recent_rows('device_config_backups', 10, $user) : [];
$dedupRows = $needsBackupRows ? app_mgs_noc_recent_rows('mgs_dedup_registry', 8, $user) : [];
$legacyOntInventory = ['ok' => false, 'rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'total_pages' => 1, 'olts' => []];
if ($needsLegacyOntRows && $db instanceof PDO) {
    $legacyOntInventory = app_mgs_legacy_ont_inventory_rows(
        $db,
        $user,
        [
            'olt_device_key' => clean_text((string) ($_GET['legacy_olt'] ?? '')),
            'status' => clean_text((string) ($_GET['legacy_status'] ?? '')),
            'q' => clean_text((string) ($_GET['legacy_q'] ?? '')),
        ],
        max(1, (int) ($_GET['legacy_page'] ?? 1))
    );
}
$managementPathReady = app_mgs_management_path_ready();
$snmpDefaults = app_mgs_snmp_defaults();
$scanResults = app_noc_scan_results_from_state($scanState);
$scanTarget = (string) ($scanState['target'] ?? '');
$suffix = $lang !== 'az' ? '?lang=' . rawurlencode($lang) : '';
$monitorMapKey = 'noc-monitor';
$monitorLayout = ($db instanceof PDO && $needsDeviceRows) ? app_mgs_monitor_map_layout($monitorMapKey, $user, $db) : [];

if (!function_exists('noc_device_feature_list')) {
function noc_device_feature_list(array $device, int $limit = 5): array
{
    $features = app_mgs_json_decode_array($device['features_json'] ?? null);
    $result = [];
    foreach ($features as $feature => $enabled) {
        if ($enabled === true || $enabled === 1 || $enabled === '1') {
            $result[] = (string) $feature;
        }
        if (count($result) >= $limit) {
            break;
        }
    }

    return $result;
}

function noc_device_bridge_url(array $device): string
{
    $type = strtolower((string) ($device['device_type'] ?? ''));
    if ($type === 'olt') {
        return 'noc-devices.php?view=ont-search';
    }
    if ($type === 'router' || $type === 'switch') {
        return 'network-definitions.php';
    }
    return 'noc-devices.php?view=monitor';
}

function noc_state_tone(string $state): string
{
    return match ($state) {
        'reachable' => 'success',
        'unreachable_confirmed' => 'danger',
        'unreachable_because_monitor_path_down' => 'warning',
        'not_monitored' => 'muted',
        default => 'info',
    };
}

function noc_monitor_tone(array $device): string
{
    $state = strtolower((string) ($device['reachability_state'] ?? ''));
    $status = strtolower((string) ($device['status'] ?? ''));
    if ($state === 'reachable' || str_contains($status, 'online')) {
        return 'up';
    }
    if ($state === 'unreachable_confirmed' || str_contains($status, 'down') || str_contains($status, 'offline')) {
        return 'down';
    }
    if ($state === 'unreachable_because_monitor_path_down' || $state === 'not_monitored' || str_contains($status, 'maintenance')) {
        return 'maintenance';
    }
    if (str_contains($status, 'wait')) {
        return 'maintenance';
    }

    return 'unknown';
}

function noc_monitor_points(array $devices): array
{
    $devices = array_slice(array_values($devices), 0, 12);
    $total = max(1, count($devices));
    $points = [];
    foreach ($devices as $index => $device) {
        if (!is_array($device)) {
            continue;
        }
        $angle = (-90 + (360 / $total) * $index) * (M_PI / 180);
        $radiusX = 38;
        $radiusY = 33;
        $points[] = [
            'device' => $device,
            'x' => round(50 + cos($angle) * $radiusX, 2),
            'y' => round(50 + sin($angle) * $radiusY, 2),
        ];
    }

    return $points;
}

function noc_monitor_device_key(array $device, int $index): string
{
    $key = trim((string) ($device['device_key'] ?? ''));
    if ($key !== '') {
        return $key;
    }

    return 'noc-device-' . (string) ((int) ($device['id'] ?? ($index + 1)));
}

function noc_probe_note(?string $value): string
{
    $value = trim((string) $value);
    return match ($value) {
        '' => '-',
        'management_path_down' => 'Management path down',
        'planned_down_not_up_yet' => 'Planli down, hele up deyil',
        'public_alias_via_172.16.5.5' => 'Public IP 172.16.5.5 uzerinden izlenir',
        'no_reply' => 'Cavab yoxdur',
        default => $value,
    };
}

function noc_duration_text(?string $timestamp): string
{
    $timestamp = trim((string) $timestamp);
    if ($timestamp === '') {
        return 'vaxt bilinmir';
    }

    try {
        $then = new DateTimeImmutable($timestamp);
        $now = new DateTimeImmutable('now');
    } catch (Throwable) {
        return $timestamp;
    }

    $seconds = max(0, $now->getTimestamp() - $then->getTimestamp());
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($days > 0) {
        return $days . ' gun ' . $hours . ' saat';
    }
    if ($hours > 0) {
        return $hours . ' saat ' . $minutes . ' deqiqe';
    }

    return max(1, $minutes) . ' deqiqe';
}

function noc_monitor_status_label(string $tone): string
{
    return match ($tone) {
        'up' => 'Online',
        'down' => 'Offline',
        'maintenance' => 'Xeta',
        default => 'Namelum',
    };
}

function noc_monitor_tooltip(array $device, string $tone): array
{
    $reason = noc_probe_note((string) ($device['last_probe_error'] ?? ''));
    if ($reason === '-') {
        $reason = trim((string) ($device['status'] ?? $device['reachability_state'] ?? ''));
    }
    if ($reason === '') {
        $reason = 'Sebeb qeyd olunmayib';
    }

    $onlineSince = (string) (
        ($device['last_successful_ping_at'] ?? '')
        ?: ($device['last_successful_ssh_auth_at'] ?? '')
        ?: ($device['last_successful_ssh_at'] ?? '')
        ?: ($device['last_successful_snmp_at'] ?? '')
        ?: ($device['updated_at'] ?? '')
    );
    $problemSince = (string) (($device['last_probe_at'] ?? '') ?: ($device['updated_at'] ?? ''));
    $label = noc_monitor_status_label($tone);

    if ($tone === 'up') {
        $headline = 'Online: ' . noc_duration_text($onlineSince);
        $detail = 'Son ugurlu yoxlama: ' . ($onlineSince !== '' ? billing_datetime($onlineSince) : '-');
    } elseif ($tone === 'down') {
        $headline = 'Offline: ' . noc_duration_text($problemSince);
        $detail = 'Sebeb: ' . $reason;
    } elseif ($tone === 'maintenance') {
        $headline = 'Xeta: ' . noc_duration_text($problemSince);
        $detail = 'Sebeb: ' . $reason;
    } else {
        $headline = 'Status bilinmir';
        $detail = 'Son yoxlama: ' . ($problemSince !== '' ? billing_datetime($problemSince) : '-');
    }

    return [
        'label' => $label,
        'headline' => $headline,
        'detail' => $detail,
        'title' => $label . ' - ' . $headline . ' - ' . $detail,
    ];
}

function noc_service_probe(array $device): array
{
    return app_mgs_json_decode_array($device['service_probe_json'] ?? null);
}

function noc_service_summary(array $device): array
{
    $probe = noc_service_probe($device);
    $items = [];
    if (!empty($probe['ssh_auth_reachable'])) {
        $items[] = ['tone' => 'success', 'label' => 'SSH auth ok'];
    } elseif (!empty($probe['ssh_reachable'])) {
        $items[] = ['tone' => 'warning', 'label' => 'SSH port ok'];
    } else {
        $items[] = ['tone' => 'muted', 'label' => 'SSH yox'];
    }
    if (!empty($probe['snmp_reachable'])) {
        $items[] = ['tone' => 'success', 'label' => 'SNMP ok'];
    } elseif (!empty($probe['snmp_port_open'])) {
        $items[] = ['tone' => 'warning', 'label' => 'SNMP port aciq'];
    } else {
        $items[] = ['tone' => 'muted', 'label' => 'SNMP yox'];
    }

    return $items;
}

function noc_secret_status(string $value): string
{
    return trim($value) === '' ? 'empty' : 'saved';
}

function noc_profile_field(array $row, array $keys, string $default = '-'): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row) || $row[$key] === null) {
            continue;
        }
        $value = trim((string) $row[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}

function noc_text_lower(string $value): string
{
    $value = app_repair_mojibake_dynamic(app_repair_mojibake($value));
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function noc_text_matches(string $query, array $parts): bool
{
    $needle = noc_text_lower(trim($query));
    if ($needle === '') {
        return false;
    }

    $haystack = [];
    foreach ($parts as $part) {
        if ($part === null) {
            continue;
        }
        $text = trim((string) $part);
        if ($text !== '') {
            $haystack[] = $text;
        }
    }

    return str_contains(noc_text_lower(implode(' ', $haystack)), $needle);
}

function noc_normalize_mac(string $value): string
{
    $normalized = preg_replace('/[^A-Fa-f0-9]/', '', $value);
    return strtoupper((string) $normalized);
}

function noc_search_accounts(PDO $db, string $query, int $limit = 80): array
{
    $query = noc_text_lower($query);
    if ($query === '') {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $statement = $db->prepare(
        'SELECT *
         FROM portal_accounts
         WHERE role = :role
           AND (
               LOWER(full_name) LIKE :q
               OR LOWER(login) LIKE :q
               OR LOWER(subscriber_code) LIKE :q
               OR LOWER(phone) LIKE :q
               OR LOWER(address) LIKE :q
               OR LOWER(payload_json) LIKE :q
           )
         ORDER BY updated_at DESC, id DESC
         LIMIT ' . $limit
    );
    $statement->execute([
        'role' => 'customer',
        'q' => '%' . $query . '%',
    ]);

    return array_values(array_filter($statement->fetchAll() ?: [], 'is_array'));
}

function noc_mock_accounts(): array
{
    $accounts = [];
    foreach (billing_mock_customer_rows() as $row) {
        $accounts[] = [
            'id' => (int) ($row['id'] ?? 0),
            'role' => 'customer',
            'login' => (string) ($row['login'] ?? $row['subscriber_code'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'subscriber_code' => (string) ($row['subscriber_code'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'area' => (string) ($row['area'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'status' => (string) ($row['status'] ?? 'Aktiv'),
            'is_active' => (int) ($row['is_active'] ?? 1),
        ];
    }

    return $accounts;
}

function noc_pick_service_row(array $services, array $ont, array $billing): array
{
    $ontMac = noc_normalize_mac(noc_profile_field($ont, ['mac', 'mac_address'], ''));
    if ($ontMac !== '') {
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }
            $serviceMac = noc_normalize_mac((string) ($service['mac'] ?? ''));
            if ($serviceMac !== '' && $serviceMac === $ontMac) {
                return $service;
            }
        }
    }

    return is_array($services[0] ?? null) ? $services[0] : $billing;
}

function noc_collect_ont_matches(array $accounts, string $query, bool $dbAvailable, int $limit = 24): array
{
    $results = [];
    $seen = [];

    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }

        $snapshot = $dbAvailable ? app_db_account_payload($account) : app_mock_customer_snapshot_data($account);
        $billing = is_array($snapshot['billing'] ?? null) ? $snapshot['billing'] : [];
        $services = is_array($snapshot['internet_services'] ?? null) ? array_values(array_filter($snapshot['internet_services'], 'is_array')) : [];
        $onts = is_array($snapshot['ont_profiles'] ?? null) ? array_values(array_filter($snapshot['ont_profiles'], 'is_array')) : [];
        $primaryService = is_array($services[0] ?? null) ? $services[0] : [];

        if ($onts === []) {
            $onts = [[
                'olt' => (string) ($primaryService['olt'] ?? $billing['olt_name'] ?? ''),
                'pon' => (string) ($primaryService['pon'] ?? $primaryService['pon_port'] ?? $billing['pon_port'] ?? ''),
                'ont_id' => (string) ($primaryService['ont_id'] ?? $billing['ont_id'] ?? ''),
                'serial' => (string) ($primaryService['serial'] ?? $primaryService['sn'] ?? $billing['ont_serial'] ?? ''),
                'mac' => (string) ($primaryService['mac'] ?? $billing['mac'] ?? ''),
                'status' => (string) ($billing['status'] ?? $account['status'] ?? ''),
            ]];
        }

        foreach ($onts as $ont) {
            $service = noc_pick_service_row($services, $ont, $billing);
            $subscriberCode = (string) ($account['subscriber_code'] ?? '');
            $result = [
                'account_id' => (int) ($account['id'] ?? 0),
                'subscriber_code' => $subscriberCode,
                'full_name' => (string) ($account['full_name'] ?? ''),
                'phone' => (string) ($account['phone'] ?? ''),
                'area' => (string) ($account['area'] ?? ''),
                'address' => (string) ($account['address'] ?? ''),
                'account_status' => (string) ($account['status'] ?? ''),
                'service_type' => (string) ($service['service_type'] ?? $billing['service_type'] ?? '-'),
                'username' => (string) ($service['username'] ?? $billing['pppoe_username'] ?? $subscriberCode),
                'assigned_ip' => (string) ($service['assigned_ip'] ?? $service['ip_address'] ?? $billing['assigned_ip'] ?? $billing['ip_address'] ?? ''),
                'nat_public_ip' => (string) ($service['nat_public_ip'] ?? $billing['nat_public_ip'] ?? ''),
                'static_ip' => (string) ($service['static_ip'] ?? $billing['static_ip'] ?? ''),
                'olt' => noc_profile_field($ont, ['olt', 'olt_name', 'device_name'], '-'),
                'pon' => noc_profile_field($ont, ['pon', 'pon_port'], '-'),
                'ont_id' => noc_profile_field($ont, ['ont_id', 'ont'], '-'),
                'serial' => noc_profile_field($ont, ['serial', 'sn'], '-'),
                'mac' => noc_profile_field($ont, ['mac', 'mac_address'], '-'),
                'temperature' => noc_profile_field($ont, ['temperature', 'temp'], '-'),
                'ont_rx' => noc_profile_field($ont, ['rx_power', 'ont_rx', 'rx'], '-'),
                'ont_tx' => noc_profile_field($ont, ['tx_power', 'ont_tx', 'tx'], '-'),
                'olt_rx' => noc_profile_field($ont, ['olt_rx', 'olt_rx_power'], '-'),
                'olt_tx' => noc_profile_field($ont, ['olt_tx', 'olt_tx_power'], '-'),
                'wifi_ssid' => noc_profile_field($ont, ['wifi_ssid', 'ssid'], '-'),
                'wifi_password' => noc_profile_field($ont, ['wifi_password', 'wifi_pass'], '-'),
                'cable_meter' => noc_profile_field($ont, ['cable_meter', 'cable_meters', 'drop_cable_meter'], '-'),
                'fast_connector' => noc_profile_field($ont, ['fast_connector', 'connector'], '-'),
                'ont_status' => noc_profile_field($ont, ['status'], (string) ($account['status'] ?? '-')),
            ];

            if (!noc_text_matches($query, $result)) {
                continue;
            }

            $dedupKey = implode('|', [
                $subscriberCode,
                $result['serial'],
                noc_normalize_mac($result['mac']),
                $result['pon'],
                $result['ont_id'],
            ]);
            if (isset($seen[$dedupKey])) {
                continue;
            }

            $seen[$dedupKey] = true;
            $results[] = $result;

            if (count($results) >= $limit) {
                return $results;
            }
        }
    }

    return $results;
}

function noc_in_clause(array $values, string $prefix): array
{
    $placeholders = [];
    $params = [];
    foreach (array_values($values) as $index => $value) {
        $key = $prefix . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $value;
    }

    return [implode(',', $placeholders), $params];
}

function noc_service_context_maps(PDO $db, array $rows): array
{
    $accountIds = [];
    $usernames = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $accountId = (int) ($row['account_id'] ?? 0);
        if ($accountId > 0) {
            $accountIds[] = $accountId;
        }
        $username = trim((string) ($row['username'] ?? ''));
        if ($username !== '') {
            $usernames[] = $username;
        }
    }

    $accountIds = array_values(array_unique($accountIds));
    $usernames = array_values(array_unique($usernames));
    if ($accountIds === [] && $usernames === []) {
        return ['by_account' => [], 'by_username' => []];
    }

    $sqlParts = [];
    $params = [];
    if ($accountIds !== []) {
        [$accountClause, $accountParams] = noc_in_clause($accountIds, 'svc_account_');
        $sqlParts[] = 'account_id IN (' . $accountClause . ')';
        $params += $accountParams;
    }
    if ($usernames !== []) {
        [$usernameClause, $usernameParams] = noc_in_clause($usernames, 'svc_user_');
        $sqlParts[] = 'username IN (' . $usernameClause . ')';
        $params += $usernameParams;
    }

    $statement = $db->prepare(
        'SELECT account_id, username, service_type, assigned_ip, nat_public_ip, static_ip, status, expires_at
         FROM billing_radius_services
         WHERE ' . implode(' OR ', $sqlParts) . '
         ORDER BY FIELD(status, "active", "suspended", "expired", "disabled"), id DESC'
    );
    $statement->execute($params);

    $byAccount = [];
    $byUsername = [];
    foreach (($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) as $service) {
        if (!is_array($service)) {
            continue;
        }

        $accountId = (int) ($service['account_id'] ?? 0);
        $username = strtolower(trim((string) ($service['username'] ?? '')));
        if ($accountId > 0 && !isset($byAccount[$accountId])) {
            $byAccount[$accountId] = $service;
        }
        if ($username !== '' && !isset($byUsername[$username])) {
            $byUsername[$username] = $service;
        }
    }

    return ['by_account' => $byAccount, 'by_username' => $byUsername];
}

function noc_radius_session_map(PDO $db, array $usernames): array
{
    $usernames = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $usernames)))));
    if ($usernames === []) {
        return [];
    }

    [$clause, $params] = noc_in_clause($usernames, 'rad_user_');
    $statement = $db->prepare(
        'SELECT r1.username,
                r1.framedipaddress,
                r1.nasipaddress,
                r1.nasportid,
                r1.callingstationid,
                r1.acctstarttime,
                COALESCE(r1.acctupdatetime, r1.acctstarttime) AS last_seen_at,
                r1.acctinputoctets,
                r1.acctoutputoctets
         FROM radacct r1
         INNER JOIN (
            SELECT username, MAX(radacctid) AS max_id
            FROM radacct
            WHERE username IN (' . $clause . ')
              AND acctstoptime IS NULL
            GROUP BY username
         ) current_session ON current_session.max_id = r1.radacctid'
    );
    $statement->execute($params);

    $map = [];
    foreach (($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) as $session) {
        if (!is_array($session)) {
            continue;
        }
        $username = strtolower(trim((string) ($session['username'] ?? '')));
        if ($username !== '') {
            $map[$username] = $session;
        }
    }

    return $map;
}

function noc_ticket_context_map(PDO $db, array $accountIds): array
{
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0)));
    if ($accountIds === []) {
        return [];
    }

    [$clause, $params] = noc_in_clause($accountIds, 'ticket_account_');

    $aggregateStatement = $db->prepare(
        'SELECT customer_account_id,
                SUM(CASE WHEN status NOT IN ("closed", "resolved", "Baglandi") THEN 1 ELSE 0 END) AS open_count,
                COUNT(*) AS total_count,
                MAX(opened_at) AS last_opened_at
         FROM portal_tickets
         WHERE customer_account_id IN (' . $clause . ')
         GROUP BY customer_account_id'
    );
    $aggregateStatement->execute($params);

    $map = [];
    foreach (($aggregateStatement->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $accountId = (int) ($row['customer_account_id'] ?? 0);
        if ($accountId > 0) {
            $map[$accountId] = $row;
        }
    }

    $latestStatement = $db->prepare(
        'SELECT t.customer_account_id, t.ticket_no, t.subject, t.status, t.opened_at
         FROM portal_tickets t
         INNER JOIN (
            SELECT customer_account_id, MAX(id) AS max_id
            FROM portal_tickets
            WHERE customer_account_id IN (' . $clause . ')
            GROUP BY customer_account_id
         ) latest ON latest.max_id = t.id'
    );
    $latestStatement->execute($params);

    foreach (($latestStatement->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $accountId = (int) ($row['customer_account_id'] ?? 0);
        if ($accountId <= 0) {
            continue;
        }

        $map[$accountId]['last_ticket_no'] = (string) ($row['ticket_no'] ?? '');
        $map[$accountId]['last_ticket_subject'] = (string) ($row['subject'] ?? '');
        $map[$accountId]['last_ticket_status'] = (string) ($row['status'] ?? '');
        $map[$accountId]['last_ticket_opened_at'] = (string) ($row['opened_at'] ?? '');
    }

    return $map;
}

function noc_enrich_ont_results(PDO $db, array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $serviceMaps = noc_service_context_maps($db, $rows);
    $serviceByAccount = $serviceMaps['by_account'] ?? [];
    $serviceByUsername = $serviceMaps['by_username'] ?? [];
    $sessionMap = noc_radius_session_map($db, array_column($rows, 'username'));
    $ticketMap = noc_ticket_context_map($db, array_column($rows, 'account_id'));

    foreach ($rows as &$row) {
        if (!is_array($row)) {
            continue;
        }

        $accountId = (int) ($row['account_id'] ?? 0);
        $usernameKey = strtolower(trim((string) ($row['username'] ?? '')));
        $service = $serviceByUsername[$usernameKey] ?? $serviceByAccount[$accountId] ?? [];
        $session = $usernameKey !== '' ? ($sessionMap[$usernameKey] ?? []) : [];
        $ticket = $ticketMap[$accountId] ?? [];

        $row['radius_status'] = (string) ($service['status'] ?? '');
        $row['service_expires_at'] = (string) ($service['expires_at'] ?? '');
        $row['radius_online'] = $session !== [];
        $row['session_ip'] = (string) ($session['framedipaddress'] ?? '');
        $row['session_nas'] = (string) ($session['nasipaddress'] ?? '');
        $row['session_port'] = (string) ($session['nasportid'] ?? '');
        $row['session_mac'] = (string) ($session['callingstationid'] ?? '');
        $row['session_started_at'] = (string) ($session['acctstarttime'] ?? '');
        $row['session_last_seen_at'] = (string) ($session['last_seen_at'] ?? '');
        $row['session_download'] = (float) ($session['acctoutputoctets'] ?? 0);
        $row['session_upload'] = (float) ($session['acctinputoctets'] ?? 0);
        $row['open_ticket_count'] = (int) ($ticket['open_count'] ?? 0);
        $row['total_ticket_count'] = (int) ($ticket['total_count'] ?? 0);
        $row['last_ticket_no'] = (string) ($ticket['last_ticket_no'] ?? '');
        $row['last_ticket_subject'] = (string) ($ticket['last_ticket_subject'] ?? '');
        $row['last_ticket_status'] = (string) ($ticket['last_ticket_status'] ?? '');
        $row['last_ticket_opened_at'] = (string) (($ticket['last_ticket_opened_at'] ?? '') ?: ($ticket['last_opened_at'] ?? ''));
    }
    unset($row);

    return $rows;
}

function noc_attach_device_context(PDO $db, array $rows, array $user): array
{
    foreach ($rows as &$row) {
        if (!is_array($row)) {
            continue;
        }
        $device = app_mgs_hsgq_device_by_olt_name((string) ($row['olt'] ?? ''), $db, $user);
        $row['device_key'] = (string) ($device['device_key'] ?? '');
        $row['device_ip'] = (string) ($device['ip_address'] ?? '');
        $row['live_capable'] = is_array($device) && app_mgs_management_path_ready();
    }
    unset($row);

    return $rows;
}

function noc_merge_declared_network_nodes(array $devices): array
{
    $path = __DIR__ . '/config/network_nodes.json';
    if (!is_file($path)) {
        return $devices;
    }
    $registry = json_decode((string) file_get_contents($path), true);
    if (!is_array($registry) || !is_array($registry['nodes'] ?? null)) {
        return $devices;
    }
    $known = [];
    foreach ($devices as $device) {
        $ip = trim((string) ($device['ip_address'] ?? ''));
        if ($ip !== '') {
            $known[$ip] = true;
        }
    }
    foreach ($registry['nodes'] as $node) {
        if (!is_array($node)) {
            continue;
        }
        $ip = trim((string) ($node['ip'] ?? ''));
        if ($ip === '' || isset($known[$ip])) {
            continue;
        }
        $devices[] = [
            'id' => 0,
            'device_key' => (string) ($node['id'] ?? 'declared-' . str_replace('.', '-', $ip)),
            'device_name' => (string) ($node['name'] ?? $ip),
            'ip_address' => $ip,
            'device_type' => (string) ($node['type'] ?? 'unknown'),
            'vendor' => (string) ($node['vendor'] ?? 'unknown'),
            'model' => '',
            'adapter_key' => '',
            'management_network' => (string) ($node['management_network'] ?? ''),
            'expected_reachability' => 1,
            'monitoring_enabled' => 1,
            'reachability_state' => 'not_registered',
            'status' => 'not_registered',
            'last_probe_error' => 'device_not_registered',
            'features_json' => '{}',
            'service_probe_json' => '{}',
            'registered' => false,
        ];
        $known[$ip] = true;
    }

    return $devices;
}
}

$ontResults = [];
if ($ontQuery !== '') {
    $ontAccounts = $db instanceof PDO ? noc_search_accounts($db, $ontQuery, 80) : noc_mock_accounts();
    $ontResults = noc_collect_ont_matches($ontAccounts, $ontQuery, $db instanceof PDO, 24);
    if ($db instanceof PDO && $ontResults !== []) {
        $ontResults = noc_enrich_ont_results($db, $ontResults);
        $ontResults = noc_attach_device_context($db, $ontResults, $user);
    }
    if ($db instanceof PDO) {
        $modemBotPreview = app_mgs_build_modem_search_reply($ontQuery, true, $db, $user);
    }
}
$devices = $needsDeviceRows ? noc_merge_declared_network_nodes($devices) : $devices;
$monitorPoints = $needsDeviceRows ? noc_monitor_points($devices) : [];
$monitorCounts = ['up' => 0, 'down' => 0, 'maintenance' => 0, 'unknown' => 0];
if ($needsDeviceRows) {
    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        $tone = noc_monitor_tone($device);
        $monitorCounts[$tone] = ($monitorCounts[$tone] ?? 0) + 1;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOC cihaz paneli</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <link rel="stylesheet" href="<?= e(app_asset('css/operations-modules.css')); ?>">
    <style>
        .noc-device-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;align-items:start}
        .noc-device-card{display:grid;gap:12px;min-width:0;max-width:100%;padding:16px;border:1px solid rgba(148,163,184,.24);border-radius:24px;background:rgba(255,255,255,.9);box-shadow:0 18px 42px rgba(17,38,63,.08)}
        .noc-device-card.is-compact{align-content:start}
        .noc-device-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;min-width:0;flex-wrap:wrap}
        .noc-device-title{display:grid;gap:4px}
        .mgs-stat-grid>*{min-width:0}.mgs-stat-grid strong,.mgs-stat-grid small{overflow-wrap:anywhere}.noc-device-title strong{font-size:1.05rem;color:#10233f;overflow-wrap:anywhere}
        .noc-device-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
        .noc-device-meta.is-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
        .noc-device-meta span{display:grid;gap:3px;padding:10px;border-radius:14px;background:rgba(245,248,253,.9);color:#64748b;font-size:.78rem;font-weight:800}
        .noc-device-meta.is-summary span{padding:8px 10px}
        .noc-device-meta b{color:#10233f;font-size:.9rem}
        .noc-device-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;min-width:0}
        .noc-device-actions .portal-inline-link,.noc-device-actions button{width:100%;min-height:38px;padding:8px 10px;font-size:.82rem}
        .noc-device-actions form{margin:0;max-width:100%;display:flex;gap:8px;flex-wrap:wrap;min-width:0}
        .noc-device-actions select{max-width:100%;min-width:0}
        .noc-node-pending{display:grid;gap:4px;padding:11px 12px;border:1px dashed rgba(245,158,11,.55);border-radius:14px;background:rgba(255,251,235,.8);color:#92400e}
        .noc-node-pending strong{font-size:.82rem}.noc-node-pending small{color:#a16207;line-height:1.4}
        .portal-layout.is-loading [data-noc-content]{opacity:.55;pointer-events:none}
        .noc-ajax-status{min-height:20px;color:#64748b;font-weight:800}
        .noc-native-actions{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.25fr);gap:16px;margin-top:16px}
        .noc-native-form{display:grid;gap:12px;align-content:start;padding:16px;border:1px solid rgba(148,163,184,.24);border-radius:22px;background:rgba(248,250,252,.88)}
        .noc-native-form h3{margin:0;color:#10233f}
        .noc-native-form p{margin:0;color:#64748b;line-height:1.5}
        .noc-native-form>.portal-primary-btn,.noc-native-form>.portal-ghost-btn{justify-self:start;align-self:start;width:auto;min-width:180px;max-width:100%;height:auto;min-height:44px;padding:10px 18px;border-radius:999px;font-size:.92rem}
        .noc-native-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .noc-native-grid .wide{grid-column:1/-1}
        .noc-scan-dialog{grid-column:1/-1;display:grid;gap:14px;padding:16px;border:1px solid rgba(11,123,135,.18);border-radius:22px;background:linear-gradient(135deg,rgba(238,248,247,.95),rgba(255,255,255,.96))}
        .noc-scan-dialog.is-empty[hidden]{display:none!important}
        body:has(.noc-scan-dialog.is-open),body:has(.noc-device-detail[open]){overflow:hidden}
        .noc-scan-dialog.is-open{position:fixed;z-index:620;inset:0;display:grid;grid-template-columns:minmax(0,1100px);grid-template-rows:auto minmax(0,min(68vh,720px));align-content:center;justify-content:center;gap:0;margin:0;padding:24px;border:0;border-radius:0;background:rgba(15,23,42,.48);backdrop-filter:blur(7px);overflow:hidden}
        .noc-scan-dialog.is-open>.noc-scan-head,.noc-scan-dialog.is-open>.noc-scan-results{width:min(1100px,calc(100vw - 32px));box-sizing:border-box}
        .noc-scan-dialog.is-open>.noc-scan-head{padding:18px 20px;border:1px solid rgba(148,163,184,.28);border-bottom:0;border-radius:22px 22px 0 0;background:linear-gradient(180deg,#fff,rgba(248,250,252,.98))}
        .noc-scan-dialog.is-open>.noc-scan-results{min-height:0;max-height:none;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;padding:0 20px 20px;border:1px solid rgba(148,163,184,.28);border-top:0;border-radius:0 0 22px 22px;background:rgba(248,250,252,.98)}
        .noc-scan-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
        .noc-scan-head h3{margin:4px 0;color:#10233f}
        .noc-scan-head small{color:#64748b}
        .noc-scan-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
        .noc-scan-close{background:#e5eaf0;color:#10233f}
        .noc-scan-results{display:grid;gap:10px}
        .noc-scan-results.is-attention{outline:3px solid rgba(249,115,22,.18);border-radius:18px}
        .noc-scan-empty,.noc-scan-result{display:grid;gap:10px;padding:12px 14px;border-radius:18px;background:#fff;border:1px solid rgba(148,163,184,.22);min-width:0}
        .noc-scan-result{grid-template-columns:auto minmax(160px,.55fr) minmax(0,1.45fr);align-items:start}
        .noc-scan-check{display:inline-flex;gap:7px;align-items:center;font-weight:900;color:#475569}
        .noc-scan-summary strong{display:block;color:#10233f;overflow-wrap:anywhere}
        .noc-scan-summary small,.noc-scan-empty small{display:block;margin-top:4px;color:#64748b;overflow-wrap:anywhere}
        .noc-scan-add-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:8px;align-items:center}
        .noc-scan-add-form input,.noc-scan-add-form select{width:100%;min-width:0;min-height:40px;border:1px solid rgba(148,163,184,.35);border-radius:12px;padding:0 10px;background:#fff;color:#10233f;font-weight:800}
        .noc-scan-add-form button{min-height:40px;white-space:nowrap;padding:9px 12px;font-size:.84rem}
        .noc-monitor-shell{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(300px,.75fr);gap:18px;align-items:start}
        .native-monitor-board{position:relative;min-height:640px;padding:18px;border-radius:24px;border:1px solid rgba(148,163,184,.22);background:linear-gradient(180deg,rgba(255,255,255,.96),rgba(248,250,252,.95)),linear-gradient(90deg,rgba(15,23,42,.04) 1px,transparent 1px),linear-gradient(rgba(15,23,42,.04) 1px,transparent 1px);background-size:auto,32px 32px,32px 32px;overflow:hidden;touch-action:none}
        .native-monitor-board:fullscreen{width:100vw;height:100vh;min-height:100vh;border-radius:0;background:linear-gradient(180deg,rgba(255,255,255,.98),rgba(248,250,252,.98)),linear-gradient(90deg,rgba(15,23,42,.05) 1px,transparent 1px),linear-gradient(rgba(15,23,42,.05) 1px,transparent 1px);background-size:auto,32px 32px,32px 32px}
        .native-monitor-board.is-fullscreen-fallback{position:fixed;z-index:500;inset:0;width:100vw;height:100vh;min-height:100vh;border-radius:0}
        .monitor-toolbar{position:absolute;z-index:8;top:14px;left:14px;display:flex;gap:8px;align-items:center;padding:6px;border-radius:14px;background:rgba(255,255,255,.92);border:1px solid rgba(148,163,184,.22);box-shadow:0 10px 24px rgba(15,23,42,.08)}
        .monitor-tool{width:36px;height:34px;border:0;border-radius:10px;background:#eef6f7;color:#0b7b87;font-weight:900;cursor:pointer}
        .monitor-tool:hover{background:#d8eef1}
        .monitor-zoom-value{min-width:54px;text-align:center;color:#64748b;font-weight:900;font-size:.8rem}
        .monitor-stage{position:absolute;inset:0;transform-origin:0 0;transition:transform .12s ease}
        .monitor-links{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
        .monitor-link{stroke-width:2.2;opacity:.82}
        .monitor-link-up{stroke:#22c55e}
        .monitor-link-down{stroke:#f43f5e}
        .monitor-link-maintenance{stroke:#f59e0b}
        .monitor-link-unknown{stroke:#94a3b8}
        .monitor-hub-native{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:260px;min-height:150px;display:grid;place-content:center;gap:8px;padding:20px;border-radius:18px;background:#1e293b;color:#f8fafc;text-align:center;box-shadow:0 20px 40px rgba(15,23,42,.25)}
        .monitor-hub-native span{font-size:.78rem;text-transform:uppercase;letter-spacing:.12em;color:#fbbf24}
        .monitor-hub-native strong{font-size:1.3rem}
        .monitor-hub-native p{margin:0;color:#cbd5e1}
        .monitor-node{position:absolute;transform:translate(-50%,-50%) scale(var(--node-scale,1));transform-origin:center;width:min(230px,30%);min-width:150px;display:grid;gap:6px;padding:12px;border-radius:14px;background:#fff;border:2px solid #60a5fa;box-shadow:0 18px 30px rgba(15,23,42,.08);cursor:grab;user-select:none}
        .monitor-node:active{cursor:grabbing}
        .monitor-node-up{border-color:#22c55e;background:linear-gradient(180deg,#ffffff,#f0fdf4)}
        .monitor-node-down{border-color:#fb7185;background:linear-gradient(180deg,#ffffff,#fff1f2)}
        .monitor-node-maintenance{border-color:#f59e0b;background:linear-gradient(180deg,#ffffff,#fffbeb)}
        .monitor-node-unknown{border-color:#60a5fa;background:linear-gradient(180deg,#ffffff,#eff6ff)}
        .monitor-node-status{justify-self:start;display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;background:#e0f7e8;color:#166534;font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
        .monitor-node-down .monitor-node-status{background:#fee2e2;color:#991b1b}
        .monitor-node-maintenance .monitor-node-status{background:#ffedd5;color:#9a3412}
        .monitor-node-unknown .monitor-node-status{background:#dbeafe;color:#1d4ed8}
        .monitor-node-type{font-size:.8rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
        .monitor-node-name{font-size:1rem;font-weight:900;color:#10233f;overflow-wrap:anywhere}
        .monitor-node-meta,.monitor-node-ip{font-size:.84rem;color:#64748b;overflow-wrap:anywhere}
        .monitor-node-note{width:100%;min-height:42px;resize:vertical;border:1px solid rgba(148,163,184,.24);border-radius:10px;padding:8px 10px;background:rgba(255,255,255,.82);color:#10233f;font-size:.8rem;line-height:1.35;user-select:text}
        .monitor-node-tools{display:flex;align-items:center;justify-content:flex-end;gap:6px}
        .monitor-node-tool{width:28px;height:28px;border:0;border-radius:10px;background:#e2e8f0;color:#10233f;font-weight:900;cursor:pointer}
        .monitor-node-scale{min-width:42px;text-align:center;font-size:.72rem;font-weight:900;color:#64748b}
        .monitor-node-tooltip{position:absolute;z-index:12;left:calc(100% + 10px);top:8px;width:min(270px,72vw);display:none;gap:4px;padding:10px 12px;border-radius:14px;background:#0f172a;color:#f8fafc;box-shadow:0 18px 38px rgba(15,23,42,.24);pointer-events:none}
        .monitor-node-tooltip strong{color:#fff;font-size:.86rem}
        .monitor-node-tooltip span{color:#cbd5e1;font-size:.78rem;line-height:1.4}
        .monitor-node:hover .monitor-node-tooltip,.monitor-node:focus-within .monitor-node-tooltip{display:grid}
        .noc-command-console{margin-top:14px;border:1px solid rgba(148,163,184,.22);border-radius:18px;background:#f8fafc;padding:14px;display:grid;gap:10px}
        .noc-command-console textarea{width:100%;min-height:88px;border:1px solid #dbe3ef;border-radius:14px;padding:12px;background:#fff;color:#10233f;font:600 .86rem/1.45 ui-monospace,SFMono-Regular,Consolas,monospace;resize:vertical}
        .noc-command-console-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
        .noc-command-console label{display:inline-flex;align-items:center;gap:8px;font-weight:900;color:#475569}
        .noc-monitor-side{display:grid;gap:10px}
        .noc-monitor-side-card{display:grid;gap:5px;padding:12px 14px;border-radius:16px;background:rgba(248,250,252,.94);border:1px solid rgba(148,163,184,.18)}
        .noc-monitor-side-card strong{color:#10233f}
        .noc-monitor-side-card small{color:#64748b;overflow-wrap:anywhere}
        .noc-pill{display:inline-flex;align-items:center;width:auto;max-width:100%;gap:6px;padding:6px 10px;border-radius:999px;background:#e8f3ff;color:#0f4f8f;font-size:.76rem;font-weight:900;white-space:normal;overflow-wrap:anywhere}
        .noc-pill-success{background:#dcfce7;color:#166534}
        .noc-pill-danger{background:#fee2e2;color:#991b1b}
        .noc-pill-warning{background:#fef3c7;color:#92400e}
        .noc-pill-muted{background:#e5e7eb;color:#374151}
        .noc-feature-row{display:flex;gap:6px;flex-wrap:wrap}
        .noc-feature-row small{padding:5px 8px;border-radius:999px;background:#eef8f7;color:#087b83;font-weight:800}
        .noc-device-safe-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .noc-device-quick-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
        .noc-device-quick-actions form,.noc-device-quick-actions a,.noc-device-quick-actions button,.noc-device-quick-actions summary{width:100%;min-width:0}
        .noc-device-quick-actions .portal-primary-btn,.noc-device-quick-actions .portal-ghost-btn,.noc-device-detail-trigger{min-height:40px;padding:9px 12px;font-size:.84rem;border-radius:999px}
        .noc-inline-action{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px;border:1px solid rgba(148,163,184,.22);border-radius:18px;background:rgba(255,255,255,.86);min-width:0}
        .noc-inline-action select,.noc-inline-action input{min-height:38px;border:1px solid rgba(148,163,184,.35);border-radius:12px;padding:0 10px;background:#fff;color:#10233f;font-weight:800;min-width:0;max-width:100%}
        .noc-inline-action small{flex:1 1 100%;color:#64748b;line-height:1.45}
        .noc-inline-action .portal-primary-btn,.noc-inline-action .portal-ghost-btn{min-height:38px;padding:8px 12px;font-size:.84rem}
        .noc-action-form .portal-primary-btn,.noc-action-form .portal-ghost-btn{min-height:42px;padding:9px 14px;font-size:.88rem}
        .noc-device-detail{display:contents}
        body:has(.noc-device-detail[open]) .portal-reveal{transform:none!important}
        .noc-device-detail>summary{list-style:none;cursor:pointer}
        .noc-device-detail>summary::-webkit-details-marker{display:none}
        .noc-device-detail[open]::before{content:"";position:fixed;inset:0;z-index:260;background:rgba(15,23,42,.48);backdrop-filter:blur(8px)}
        .noc-device-detail-panel{display:none}
        .noc-device-detail[open] .noc-device-detail-panel{position:fixed;z-index:270;top:96px;left:50%;bottom:24px;transform:translateX(-50%);width:min(calc(100vw - 36px),1040px);display:grid;gap:16px;max-height:calc(100vh - 120px);overflow:auto;padding:22px;border-radius:28px;background:linear-gradient(180deg,#fff,rgba(248,250,252,.98));box-shadow:0 36px 90px rgba(15,23,42,.32);border:1px solid rgba(148,163,184,.28)}
        .noc-device-detail-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;position:sticky;top:-22px;z-index:2;padding:0 0 12px;background:linear-gradient(180deg,#fff,rgba(248,250,252,.96));border-bottom:1px solid rgba(148,163,184,.18)}
        .noc-device-detail-head h3{margin:0;color:#10233f}
        .noc-device-detail-head small{display:block;margin-top:4px;color:#64748b}
        .noc-device-detail-close{border:0;border-radius:999px;background:#e5eaf0;color:#10233f;font-weight:900;padding:10px 14px;cursor:pointer}
        .noc-command-hero{padding:20px 22px;align-items:center}
        .noc-command-hero h1{max-width:860px;font-size:clamp(1.8rem,3vw,2.7rem);line-height:1.05}
        .noc-command-hero p{max-width:900px}
        .noc-secondary-summary{border:1px solid rgba(148,163,184,.22);border-radius:18px;background:rgba(255,255,255,.78)}
        .noc-secondary-summary>summary{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:48px;padding:10px 16px;list-style:none;cursor:pointer;color:#10233f;font-weight:900}
        .noc-secondary-summary>summary::-webkit-details-marker{display:none}
        .noc-secondary-summary>summary::after{content:"+";display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:#e6f2f4;color:#087b83;font-size:1.1rem}
        .noc-secondary-summary[open]>summary::after{content:"-"}
        .noc-secondary-summary>summary span{color:#64748b;font-size:.82rem;font-weight:800}
        .noc-secondary-summary>.mgs-stat-grid{padding:0 14px 14px}
        .noc-access-panel{display:grid;gap:12px;padding:14px;border-radius:22px;background:rgba(238,248,247,.72);border:1px solid rgba(11,123,135,.16)}
        .noc-access-panel h4{margin:0;color:#0b7b87}
        .noc-access-panel p{margin:0;color:#64748b;line-height:1.45}
        .noc-access-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .noc-access-grid input,.noc-access-grid select{width:100%;min-height:42px;border:1px solid rgba(148,163,184,.35);border-radius:13px;padding:0 11px;background:#fff;color:#10233f;font-weight:800}
        .noc-access-grid button{min-height:42px}
        .noc-access-grid .wide{grid-column:1/-1}
        .noc-two-col{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(320px,.9fr);gap:18px}
        .noc-action-form{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .noc-action-form select,.noc-action-form input{min-height:44px;border:1px solid rgba(148,163,184,.35);border-radius:14px;padding:0 12px;background:#fff;color:#10233f;font-weight:800}
        .noc-search-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .noc-search-card{display:grid;gap:12px;padding:16px;border:1px solid rgba(148,163,184,.24);border-radius:22px;background:rgba(255,255,255,.94);box-shadow:0 16px 34px rgba(15,23,42,.08)}
        .noc-search-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
        .noc-search-kv{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
        .noc-search-kv span{display:grid;gap:3px;padding:10px;border-radius:14px;background:rgba(245,248,253,.9);color:#64748b;font-size:.78rem;font-weight:800}
        .noc-search-kv b{color:#10233f;font-size:.9rem;overflow-wrap:anywhere}
        .noc-search-actions{display:flex;gap:8px;flex-wrap:wrap}
        .noc-search-meta{display:flex;gap:6px;flex-wrap:wrap}
        .noc-search-meta small{padding:5px 8px;border-radius:999px;background:#eef8f7;color:#087b83;font-weight:800}
        .modem-bot-preview{display:grid;gap:10px;margin:14px 0;padding:16px;border-radius:22px;background:#10233f;color:#eaf4ff;box-shadow:0 18px 40px rgba(15,23,42,.18)}
        .modem-bot-preview h3{margin:0;color:#fff}
        .modem-bot-preview pre{margin:0;white-space:pre-wrap;word-break:break-word;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);border-radius:16px;padding:14px;color:#f8fafc;font-size:.86rem;line-height:1.55}
        .modem-bot-preview small{color:#b7c7dc}
        .portal-noc-report .portal-table{min-width:0;table-layout:fixed}
        .portal-noc-report .portal-table th,
        .portal-noc-report .portal-table td{overflow-wrap:anywhere}
        @media (max-width:1100px){.noc-device-grid,.noc-two-col,.noc-native-actions,.noc-monitor-shell{grid-template-columns:1fr}.monitor-node{width:min(82vw,260px)}.native-monitor-board{min-height:min(72vh,680px)}}
        @media (max-width:760px){
            .noc-command-hero{padding:16px}
            .noc-command-hero h1{font-size:1.65rem}
            .noc-command-hero p{display:none}
            .noc-scan-dialog.is-open{grid-template-columns:minmax(0,1fr);grid-template-rows:auto minmax(0,1fr);align-content:stretch;padding:10px}
            .noc-scan-dialog.is-open>.noc-scan-head,.noc-scan-dialog.is-open>.noc-scan-results{width:100%}
            .noc-scan-dialog.is-open>.noc-scan-head{padding:14px}
            .noc-scan-dialog.is-open>.noc-scan-results{padding:0 14px 14px}
            .native-monitor-board{min-height:0;overflow:hidden;touch-action:auto}
            .monitor-toolbar,.monitor-links,.monitor-hub-native{display:none}
            .monitor-stage{position:static;inset:auto;display:grid;grid-template-columns:1fr;gap:12px;transform:none!important}
            .monitor-stage{width:100%!important;height:auto!important}
            .monitor-node{position:static!important;left:auto!important;top:auto!important;transform:none!important;width:100%;min-width:0;max-width:100%;cursor:default}
            .monitor-node-note{min-height:38px}
            .noc-device-meta,.noc-search-kv{grid-template-columns:1fr}
            .noc-device-meta.is-summary{grid-template-columns:1fr 1fr}
            .noc-device-safe-actions,.noc-device-quick-actions{grid-template-columns:1fr}
            .noc-device-actions form,.noc-device-actions select,.noc-device-actions button{width:100%}
            .noc-action-form select,.noc-action-form input,.noc-action-form button{width:100%;max-width:100%;flex:1 1 100%}
            .noc-device-detail[open] .noc-device-detail-panel{inset:76px 10px 12px;left:10px;right:10px;width:auto;max-height:none;transform:none;padding:16px;border-radius:22px}
            .noc-device-detail-head{top:-16px}
            .noc-access-grid{grid-template-columns:1fr}
            .noc-scan-result,.noc-scan-add-form{grid-template-columns:1fr}
            .noc-scan-head .portal-ghost-btn{width:100%}
        }
        @media (max-width:720px){.noc-native-grid{grid-template-columns:1fr}.noc-native-grid .wide{grid-column:auto}}
        @media (max-width:1100px){.noc-search-grid{grid-template-columns:1fr}}
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('NOC cihaz paneli', 'noc-devices', $lang); ?>

    <main class="portal-layout" data-noc-shell data-noc-view="<?= e($activeNocView); ?>">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e((string) $flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e((string) $flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <?php $pendingDeviceCommands = app_portal_is_admin($user) ? app_mgs_device_command_requests($db, 'pending') : []; ?>
        <?php if ($pendingDeviceCommands !== []): ?>
        <section class="portal-card portal-reveal">
            <div class="portal-card-head"><div><strong>Gozleyen cihaz emri sorgulari</strong><small>Vizyon 19.2: write emrleri BASQA admin tesdiqleyene qeder cihaza gonderilmir.</small></div></div>
            <div class="portal-table-wrap"><table class="portal-table"><thead><tr><th>Cihaz</th><th>Emrler</th><th>Sebeb</th><th>Sorgu eden</th><th>Qerar</th></tr></thead><tbody>
            <?php foreach ($pendingDeviceCommands as $cmdRequest): ?>
                <?php $cmdList = app_mgs_json_decode_array($cmdRequest['commands_json'] ?? []); ?>
                <tr>
                    <td data-label="Cihaz"><?= e((string) ($cmdRequest['device_name'] ?? $cmdRequest['device_key'] ?? '-')); ?></td>
                    <td data-label="Emrler"><code><?= e(implode(' | ', array_map('strval', $cmdList))); ?></code></td>
                    <td data-label="Sebeb"><?= e((string) ($cmdRequest['reason'] ?? '-')); ?></td>
                    <td data-label="Sorgu eden"><?= e((string) ($cmdRequest['requested_by_name'] ?? '-')); ?></td>
                    <td data-label="Qerar">
                        <form class="portal-inline-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="resolve_device_command_request">
                            <input type="hidden" name="request_id" value="<?= e((string) ($cmdRequest['id'] ?? 0)); ?>">
                            <input type="text" name="resolution_note" placeholder="Qeyd" style="width:140px">
                            <select name="decision"><option value="approved">Tesdiqle ve icra et</option><option value="rejected">Redd et</option></select>
                            <button class="portal-ghost-btn" type="submit">Qerar ver</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
        <?php endif; ?>

        <section class="mgs-command-hero noc-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">NOC native panel</span>
                <h1>Cihaz oyrenme, reachability, OLT/ONT ve backup nezareti</h1>
                <p>Bu sehife billing dizaynindan cixmadan cihaz statusu, VLAN 550 management qaydasi, backup worker, OLT/ONT context ve live optical oxulari gosterir.</p>
            </div>
            <div class="mgs-command-panel">
                <span>Management path</span>
                <strong><?= $managementPathReady ? 'VLAN 550 aktiv' : 'VLAN 550 passiv'; ?></strong>
                <small><?= $managementPathReady ? 'Internal probe icazelidir' : 'Internal offline ticketler suppressed'; ?></small>
                <span>Servis qati</span>
                <strong><?= $bridge['online'] ? 'Hazirdir' : 'Cavab vermir'; ?></strong>
                <small><?= e((string) $bridge['message']); ?></small>
            </div>
        </section>

        <?php
        $nocLink = static fn (string $key, string $label, string $href): array => ['key'=>$key,'label'=>$label,'href'=>$href];
        $moduleNavGroups = [
            ['label'=>'Monitor','icon'=>'fa-display','links'=>[$nocLink('monitor','Network monitor','noc-devices.php?view=monitor')]],
            ['label'=>'Cihazlar','icon'=>'fa-network-wired','links'=>[$nocLink('devices','OLT / ONT paneli','noc-devices.php?view=devices'),$nocLink('ont-search','ONT axtarisi','noc-devices.php?view=ont-search'),$nocLink('legacy-onts','Kohne MGS ONT arxivi','noc-devices.php?view=legacy-onts')]],
            ['label'=>'Backup','icon'=>'fa-box-archive','links'=>[$nocLink('backups','Backup / dedup','noc-devices.php?view=backups')]],
            ['label'=>'Sebeke tenzimlemeleri','icon'=>'fa-route','links'=>[$nocLink('definitions','MPLS / VLAN','network-definitions.php')]],
        ];
        $moduleNavActive = $activeNocView;
        $moduleNavAria = 'NOC bolmeleri';
        $moduleNavName = 'noc-module-nav';
        $moduleNavLinkAttribute = 'data-noc-nav';
        require __DIR__ . '/views/_module-group-nav.php';
        ?>

        <?php if (!$managementPathReady): ?>
            <section class="portal-alert system-health-warning portal-reveal">
                <i class="fa-solid fa-route"></i>
                <span>Monitoring server VLAN 550 management yoluna qosulu kimi isaretlenmeyib. Internal OLT/switch/router offline alarmlari ferdi bilet yaratmir; yalniz management path problemi kimi qruplasdirilir.</span>
            </section>
        <?php endif; ?>

        <section class="mgs-stat-grid portal-reveal" data-noc-primary-stats>
            <?php billing_render_stat_card('Cihaz profili', (string) ($summary['device_profiles'] ?? count($devices)), 'öyrənilmiş cihazlar', 'fa-network-wired', 'blue'); ?>
            <?php billing_render_stat_card('Əlçatan', (string) ($summary['devices_reachable'] ?? 0), 'son yoxlama', 'fa-circle-check', 'green'); ?>
            <?php billing_render_stat_card('Şəbəkə yolu gözləyir', (string) ($summary['devices_waiting_path'] ?? 0), 'yanlış offline bildirişi bloklanır', 'fa-route', 'orange'); ?>
            <?php billing_render_stat_card('Yoxlanılmayıb', (string) ($summary['devices_unknown'] ?? 0), 'yoxlama gözləyir', 'fa-circle-question', 'navy'); ?>
        </section>

        <details class="noc-secondary-summary portal-reveal" data-noc-secondary-stats>
            <summary>Ətraflı NOC göstəriciləri <span>7 texniki göstərici</span></summary>
            <section class="mgs-stat-grid">
                <?php billing_render_stat_card('Monitorinqdən kənar', (string) ($summary['devices_not_monitored'] ?? 0), 'alias və texniki fasilə', 'fa-eye-slash', 'navy'); ?>
                <?php billing_render_stat_card('SSH portu açıqdır', (string) ($summary['devices_ssh_confirmed'] ?? 0), '22/tcp cavab verir', 'fa-terminal', 'green'); ?>
                <?php billing_render_stat_card('SSH giriş uğurludur', (string) ($summary['devices_ssh_auth_confirmed'] ?? 0), 'hesab məlumatları təsdiqlənib', 'fa-user-shield', 'green'); ?>
                <?php billing_render_stat_card('SNMP işləyir', (string) ($summary['devices_snmp_confirmed'] ?? 0), 'aktiv community yoxlaması', 'fa-wave-square', 'green'); ?>
                <?php billing_render_stat_card('Fərdi giriş profili', (string) ($summary['device_access_overrides'] ?? 0), 'cihaza xüsusi giriş', 'fa-key', 'orange'); ?>
                <?php billing_render_stat_card('Backup versiyası', (string) ($summary['device_backup_versions'] ?? 0), 'təkrar nüsxələr ayrılır', 'fa-box-archive', 'blue'); ?>
                <?php billing_render_stat_card('Təkrar alarm bloku', (string) ($summary['dedup_keys'] ?? 0), 'təkrar mesaj göndərilmir', 'fa-clone', 'green'); ?>
            </section>
        </details>

        <div class="noc-ajax-status" data-noc-status aria-live="polite"></div>
        <div data-noc-content>
        <?php if ($activeNocView === 'monitor'): ?>
        <section class="portal-card portal-reveal" id="network-monitor">
            <div class="portal-card-head">
                <h2>Canli avadanliq monitoru</h2>
                <small>Reng statusa gore deyisir. Kartlari el ile cekmek, zoom etmek ve her cihaz altina qeyd yazmaq mumkundur.</small>
            </div>
            <div class="noc-monitor-shell">
                <div class="native-monitor-board" data-native-monitor-board data-monitor-save-url="api/monitor-layout.php" data-monitor-csrf="<?= e(app_csrf_token()); ?>" data-monitor-map-key="<?= e($monitorMapKey); ?>" data-monitor-storage-key="superoptic.noc.monitor.map.v2">
                    <div class="monitor-toolbar" aria-label="Monitor aletleri">
                        <button type="button" class="monitor-tool" data-monitor-zoom="out" title="Kicilt">-</button>
                        <span class="monitor-zoom-value" data-monitor-zoom-value>100%</span>
                        <button type="button" class="monitor-tool" data-monitor-zoom="in" title="Boyut">+</button>
                        <button type="button" class="monitor-tool" data-monitor-reset title="Sifirla">R</button>
                        <button type="button" class="monitor-tool" data-monitor-fullscreen title="Tam ekran">[]</button>
                    </div>
                    <div class="monitor-stage" data-monitor-stage>
                        <svg class="monitor-links" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                            <?php foreach ($monitorPoints as $index => $point): ?>
                                <?php
                                $lineDevice = (array) $point['device'];
                                $tone = noc_monitor_tone($lineDevice);
                                $deviceKey = noc_monitor_device_key($lineDevice, (int) $index);
                                ?>
                                <line x1="50" y1="50" x2="<?= e((string) $point['x']); ?>" y2="<?= e((string) $point['y']); ?>" data-monitor-link-to="<?= e($deviceKey); ?>" class="monitor-link monitor-link-<?= e($tone); ?>"></line>
                            <?php endforeach; ?>
                        </svg>
                        <div class="monitor-hub-native">
                            <span>SuperOptik monitor</span>
                            <strong><?= e((string) count($devices)); ?> avadanliq</strong>
                            <p><?= e((string) ($monitorCounts['up'] ?? 0)); ?> up / <?= e((string) ($monitorCounts['down'] ?? 0)); ?> problemli / <?= e((string) ($monitorCounts['maintenance'] ?? 0)); ?> maintenance</p>
                        </div>
                        <?php foreach ($monitorPoints as $index => $point): ?>
                            <?php
                            $device = (array) $point['device'];
                            $tone = noc_monitor_tone($device);
                            $deviceKey = noc_monitor_device_key($device, (int) $index);
                            $layout = is_array($monitorLayout[$deviceKey] ?? null) ? $monitorLayout[$deviceKey] : [];
                            $nodeX = isset($layout['x']) ? (float) $layout['x'] : (float) $point['x'];
                            $nodeY = isset($layout['y']) ? (float) $layout['y'] : (float) $point['y'];
                            $nodeScale = isset($layout['scale']) ? (float) $layout['scale'] : 1.0;
                            $nodeNote = array_key_exists('note', $layout) ? (string) $layout['note'] : (string) ($device['note_text'] ?? '');
                            $tooltip = noc_monitor_tooltip($device, $tone);
                            ?>
                            <div class="monitor-node monitor-node-<?= e($tone); ?>" data-monitor-node data-monitor-id="<?= e($deviceKey); ?>" data-default-x="<?= e((string) $point['x']); ?>" data-default-y="<?= e((string) $point['y']); ?>" data-server-layout="<?= $layout !== [] ? '1' : '0'; ?>" title="<?= e((string) $tooltip['title']); ?>" style="left:<?= e((string) $nodeX); ?>%;top:<?= e((string) $nodeY); ?>%;--node-scale:<?= e((string) $nodeScale); ?>;">
                                <span class="monitor-node-status" data-monitor-status-text><?= e((string) $tooltip['label']); ?></span>
                                <span class="monitor-node-type"><?= e(strtoupper((string) ($device['device_type'] ?? 'DEV'))); ?></span>
                                <strong class="monitor-node-name"><?= e((string) ($device['device_name'] ?? '-')); ?></strong>
                                <span class="monitor-node-meta"><?= e((string) ($device['vendor'] ?? '-')); ?> / <?= e(app_ui_status((string) ($device['reachability_state'] ?? $device['status'] ?? 'unknown'))); ?></span>
                                <span class="monitor-node-ip"><?= e((string) ($device['ip_address'] ?? '-')); ?></span>
                                <textarea class="monitor-node-note" data-monitor-note placeholder="Qeyd..."><?= e($nodeNote); ?></textarea>
                                <div class="monitor-node-tools" data-monitor-node-tools>
                                    <button type="button" class="monitor-node-tool" data-node-scale="out" title="Karti kicilt">-</button>
                                    <span class="monitor-node-scale" data-node-scale-value><?= e((string) round($nodeScale * 100)); ?>%</span>
                                    <button type="button" class="monitor-node-tool" data-node-scale="in" title="Karti boyut">+</button>
                                </div>
                                <div class="monitor-node-tooltip" role="tooltip">
                                    <strong><?= e((string) $tooltip['headline']); ?></strong>
                                    <span><?= e((string) $tooltip['detail']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="noc-monitor-side">
                    <?php foreach (array_slice($devices, 0, 8) as $device): ?>
                        <?php if (!is_array($device)) { continue; } ?>
                        <?php $tone = noc_monitor_tone($device); ?>
                        <div class="noc-monitor-side-card">
                            <span class="noc-pill noc-pill-<?= e($tone === 'up' ? 'success' : ($tone === 'down' ? 'danger' : ($tone === 'maintenance' ? 'muted' : 'warning'))); ?>"><?= e(app_ui_status($tone)); ?></span>
                            <strong><?= e((string) ($device['device_name'] ?? '-')); ?></strong>
                            <small><?= e((string) ($device['ip_address'] ?? '-')); ?> / <?= e((string) ($device['last_probe_error'] ?? $device['status'] ?? '-')); ?></small>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($devices === []): ?>
                        <?php billing_render_empty_state('Cihaz yoxdur', 'Cihaz elave et ve ya IP scan et.', 'fa-network-wired'); ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Tehlukesiz emeliyyatlar</h2>
                <small>VLAN 550 aktiv deyilse probe ve running-config backup real cihazlara getmir. SNMP default: <?= e((string) $snmpDefaults['read_community']); ?> / <?= e((string) $snmpDefaults['port']); ?>, trap server <?= e((string) $snmpDefaults['trap_server']); ?>:<?= e((string) $snmpDefaults['trap_port']); ?>.</small>
            </div>
            <div class="noc-native-actions">
                <form class="noc-native-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="scan_network">
                    <h3>Sebekeni IP scan et</h3>
                    <p>Legacy MGS scanner mentiqi service API uzerinden isleyir. VLAN 550 qosulmadigi halda internal cihaz scan-i real cavab vermeye biler.</p>
                    <div class="noc-native-grid">
                        <div class="portal-form-row wide">
                            <label>Scan hedefi</label>
                            <input type="text" name="scan_target" placeholder="172.16.5.0/24 veya 172.16.5.5" value="172.16.5.0/24">
                        </div>
                        <div class="portal-form-row">
                            <label>Portlar</label>
                            <input type="text" name="scan_ports" value="22,80,443,161">
                        </div>
                        <div class="portal-form-row">
                            <label>Max host</label>
                            <input type="number" name="scan_max_hosts" min="1" max="1024" value="64">
                        </div>
                        <div class="portal-form-row">
                            <label>Concurrency</label>
                            <input type="number" name="scan_concurrency" min="1" max="128" value="24">
                        </div>
                    </div>
                    <button class="portal-primary-btn" type="submit">Sebekeni IP scan et</button>
                </form>

                <?php app_noc_render_scan_results($scanResults, $scanTarget, [
                    'snmp_read_community' => (string) ($snmpDefaults['read_community'] ?? ''),
                    'snmp_write_community' => (string) ($snmpDefaults['write_community'] ?? ''),
                    'snmp_port' => (int) $snmpDefaults['port'],
                    'snmp_trap_port' => (int) $snmpDefaults['trap_port'],
                ]); ?>

                <form class="noc-native-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_monitor_device">
                    <h3>Cihaz elave et</h3>
                    <p>Yeni OLT/router/switch/server NOC monitor data qatinda yaradilir ve discovery/probe queue-ya dusur.</p>
                    <div class="noc-native-grid">
                        <div class="portal-form-row">
                            <label>Cihaz adi</label>
                            <input type="text" name="device_name" placeholder="Kurdexani HSGQ 20" required>
                        </div>
                        <div class="portal-form-row">
                            <label>IP</label>
                            <input type="text" name="device_ip" placeholder="172.16.5.20" required>
                        </div>
                        <div class="portal-form-row">
                            <label>Tip</label>
                            <select name="device_type">
                                <option value="olt">OLT</option>
                                <option value="router">Router</option>
                                <option value="switch">Switch</option>
                                <option value="server">Server</option>
                                <option value="auto">Auto</option>
                            </select>
                        </div>
                        <div class="portal-form-row">
                            <label>Vendor / model</label>
                            <input type="text" name="device_vendor" placeholder="HSGQ, Cisco, Proxmox">
                        </div>
                        <div class="portal-form-row">
                            <label>Model</label>
                            <input type="text" name="device_model" placeholder="G08L, ASR1006">
                        </div>
                        <div class="portal-form-row">
                            <label>Yer</label>
                            <input type="text" name="device_location" placeholder="Kurdexani">
                        </div>
                        <div class="portal-form-row">
                            <label>SSH user</label>
                            <input type="text" name="ssh_username" placeholder="bos qala biler">
                        </div>
                        <div class="portal-form-row">
                            <label>SSH port</label>
                            <input type="number" name="ssh_port" min="1" max="65535" value="22">
                        </div>
                        <div class="portal-form-row">
                            <label>SNMP read</label>
                            <input type="text" name="snmp_read_community" value="<?= e((string) ($snmpDefaults['read_community'] ?? '')); ?>">
                        </div>
                        <div class="portal-form-row">
                            <label>SNMP write</label>
                            <input type="text" name="snmp_write_community" value="<?= e((string) ($snmpDefaults['write_community'] ?? '')); ?>">
                        </div>
                        <div class="portal-form-row">
                            <label>SNMP port</label>
                            <input type="number" name="snmp_port" min="1" max="65535" value="<?= e((string) $snmpDefaults['port']); ?>">
                        </div>
                        <div class="portal-form-row">
                            <label>Trap port</label>
                            <input type="number" name="snmp_trap_port" min="1" max="65535" value="<?= e((string) $snmpDefaults['trap_port']); ?>">
                        </div>
                        <div class="portal-form-row wide">
                            <label>Qeyd</label>
                            <textarea name="device_note" rows="3" placeholder="Trap server <?= e((string) $snmpDefaults['trap_server']); ?>, VLAN, kabinet ve s."></textarea>
                        </div>
                    </div>
                    <button class="portal-primary-btn" type="submit">Cihaz elave et</button>
                </form>
            </div>
            <div class="mgs-command-actions">
                <form class="noc-action-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="set_management_path">
                    <input type="hidden" name="enabled" value="<?= $managementPathReady ? '0' : '1'; ?>">
                    <button class="portal-ghost-btn" type="submit"><?= $managementPathReady ? 'VLAN 550 passiv et' : 'VLAN 550 aktiv kimi qeyd et'; ?></button>
                </form>
                <form class="noc-action-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="probe_devices">
                    <button class="portal-primary-btn" type="submit">Cihaz probe et</button>
                </form>
                <form class="noc-action-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="apply_vendor_access_defaults">
                    <input type="hidden" name="vendor" value="HSGQ">
                    <button class="portal-ghost-btn" type="submit">HSGQ SNMP defaultlarini yaz</button>
                </form>
                <form class="noc-action-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="run_backup_worker">
                    <select name="backup_type">
                        <option value="profile_snapshot">profile snapshot</option>
                        <option value="inventory_snapshot">inventory snapshot</option>
                        <option value="cli_discovery_snapshot">cli discovery snapshot</option>
                        <option value="running_config">running config</option>
                        <option value="startup_config">startup config</option>
                    </select>
                    <input type="number" name="limit" min="1" max="50" value="5">
                    <?php if (app_portal_is_admin($user)): ?>
                        <label class="noc-pill"><input type="checkbox" name="apply_backup" value="1"> apply</label>
                    <?php endif; ?>
                    <button class="portal-ghost-btn" type="submit">Backup worker</button>
                </form>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($activeNocView === 'devices'): ?>
        <section id="olt-ont" class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Avadanliq oyrenme profilleri</h2>
                <small>Vendor/model, adapter, OID/CLI imkanlari ve reachability statusu. Live SSH/SNMP probe yalniz VLAN 550 aktiv olanda icra edilir.</small>
            </div>
            <?php if ($devices === []): ?>
                <?php billing_render_empty_state('Cihaz profili yoxdur', 'Sistem servislari sehifesinden seed learning emeliyyatini isledin.', 'fa-network-wired'); ?>
            <?php else: ?>
                <div class="noc-device-grid">
                    <?php foreach ($devices as $device): ?>
                        <?php
                        $state = (string) ($device['reachability_state'] ?? 'unknown');
                        $tone = noc_state_tone($state);
                        $features = noc_device_feature_list($device);
                        $serviceItems = noc_service_summary($device);
                        $accessProfile = app_mgs_device_access_profile($device, $db, $user);
                        $cliDiscovery = app_mgs_latest_cli_discovery_summary($device, $db, $user);
                        $learnedCommandMap = is_array($cliDiscovery['derived_command_map'] ?? null) ? $cliDiscovery['derived_command_map'] : [];
                        $savedPasswordState = noc_secret_status((string) ($accessProfile['ssh_password'] ?? ''));
                        $deviceKey = (string) ($device['device_key'] ?? '');
                        $deviceInterfaces = app_mgs_device_interfaces($deviceKey, $db, $user);
                        $deviceNeighbors = app_mgs_device_neighbors($deviceKey, $db, $user);
                        $isRegistered = ($device['registered'] ?? true) !== false;
                        $isSelectedDevice = $isRegistered && $selectedDeviceKey !== '' && $deviceKey !== '' && hash_equals($deviceKey, $selectedDeviceKey);
                        $deviceDetailParams = ['view' => 'devices', 'device' => $deviceKey];
                        if ($lang !== 'az') {
                            $deviceDetailParams['lang'] = $lang;
                        }
                        $deviceDetailHref = 'noc-devices.php?' . http_build_query($deviceDetailParams) . '#olt-ont';
                        ?>
                        <article class="noc-device-card is-compact">
                            <div class="noc-device-head">
                                <div class="noc-device-title">
                                    <small><?= e(strtoupper((string) ($device['device_type'] ?? 'unknown'))); ?></small>
                                    <strong><?= e((string) ($device['device_name'] ?? '-')); ?></strong>
                                </div>
                                <span class="noc-pill noc-pill-<?= e($tone); ?>"><?= e(app_ui_status($state)); ?></span>
                            </div>
                            <div class="noc-device-meta is-summary">
                                <span>IP<b><?= e((string) ($device['ip_address'] ?? '-')); ?></b></span>
                                <span>Vendor<b><?= e((string) ($device['vendor'] ?? '-')); ?></b></span>
                                <span>Model<b><?= e((string) ($device['model'] ?? '-')); ?></b></span>
                                <span>Monitor<b><?= !empty($device['monitoring_enabled']) ? 'on' : 'off'; ?></b></span>
                            </div>
                            <?php if ($isSelectedDevice): ?>
                            <details class="noc-device-detail" open>
                                <summary class="portal-ghost-btn noc-device-detail-trigger">Etrafli / redakte</summary>
                                <div class="noc-device-detail-panel" data-noc-device-drawer>
                                    <div class="noc-device-detail-head">
                                        <div>
                                            <h3><?= e((string) ($device['device_name'] ?? '-')); ?></h3>
                                            <small><?= e((string) ($device['ip_address'] ?? '-')); ?> / <?= e((string) ($device['vendor'] ?? '-')); ?> / <?= e((string) ($device['model'] ?? '-')); ?></small>
                                        </div>
                                        <button type="button" class="noc-device-detail-close" data-noc-close-details onpointerdown="document.querySelectorAll('.noc-device-detail[open]').forEach((details)=>details.removeAttribute('open'))" onclick="document.querySelectorAll('.noc-device-detail[open]').forEach((details)=>details.removeAttribute('open'))">Bagla</button>
                                    </div>
                            <div class="noc-device-meta">
                                <span>Adapter<b><?= e((string) ($device['adapter_key'] ?? '-')); ?></b></span>
                                <span>Mgmt net<b><?= e((string) ($device['management_network'] ?? '-')); ?></b></span>
                                <span>Expected<b><?= !empty($device['expected_reachability']) ? 'yes' : 'no'; ?></b></span>
                                <span>Probe method<b><?= e((string) ($device['last_probe_method'] ?? '-')); ?></b></span>
                                <span>Son ugurlu ping<b><?= e(billing_datetime($device['last_successful_ping_at'] ?? null)); ?></b></span>
                                <span>Son ssh port<b><?= e(billing_datetime($device['last_successful_ssh_at'] ?? null)); ?></b></span>
                                <span>Son ssh auth<b><?= e(billing_datetime($device['last_successful_ssh_auth_at'] ?? null)); ?></b></span>
                                <span>Son ugurlu snmp<b><?= e(billing_datetime($device['last_successful_snmp_at'] ?? null)); ?></b></span>
                                <span>CLI discovery<b><?= e(billing_datetime($cliDiscovery['created_at'] ?? null)); ?></b></span>
                                <span>CLI prompt<b><?= e((string) (($cliDiscovery['prompts']['enable'] ?? $cliDiscovery['prompts']['welcome'] ?? '-'))); ?></b></span>
                                <span>Sample ONT<b><?= e((string) ($cliDiscovery['sample_ont_id'] ?? '-')); ?></b></span>
                                <span>Learned cmds<b><?= e((string) count($learnedCommandMap)); ?></b></span>
                            </div>
                            <div class="noc-device-meta">
                                <span>SSH user<b><?= e((string) ($accessProfile['ssh_username'] ?? '-')); ?></b></span>
                                <span>SSH port<b><?= e((string) ($accessProfile['ssh_port'] ?? 22)); ?></b></span>
                                <span>SSH pass<b><?= e($savedPasswordState); ?></b></span>
                                <span>SNMP RO<b><?= e((string) ($accessProfile['read_community'] ?? '-')); ?></b></span>
                                <span>SNMP RW<b><?= e((string) ($accessProfile['write_community'] ?? '-')); ?></b></span>
                                <span>SNMP port<b><?= e((string) ($accessProfile['port'] ?? 161)); ?></b></span>
                                <span>Trap host<b><?= e((string) ($accessProfile['trap_server'] ?? '-')); ?></b></span>
                                <span>Trap port<b><?= e((string) ($accessProfile['trap_port'] ?? 162)); ?></b></span>
                                <span>Contact<b><?= e((string) ($accessProfile['contact'] ?? '-')); ?></b></span>
                                <span>Location<b><?= e((string) ($accessProfile['location'] ?? '-')); ?></b></span>
                            </div>
                            <div class="noc-feature-row">
                                <?php foreach ($features as $feature): ?>
                                    <small><?= e($feature); ?></small>
                                <?php endforeach; ?>
                                <?php if ($features === []): ?>
                                    <small>feature pending</small>
                                <?php endif; ?>
                                <?php foreach (array_slice((array) ($cliDiscovery['keywords'] ?? []), 0, 6) as $keyword): ?>
                                    <small>cli:<?= e((string) $keyword); ?></small>
                                <?php endforeach; ?>
                            </div>
                            <small><?= e(noc_probe_note((string) ($device['last_probe_error'] ?? $device['status'] ?? ''))); ?></small>
                            <?php if (is_array($cliDiscovery) && !empty($cliDiscovery['nodes'])): ?>
                                <small>CLI nodes: <?= e(implode(', ', array_keys((array) $cliDiscovery['nodes']))); ?></small>
                            <?php endif; ?>
                            <?php if ($learnedCommandMap !== []): ?>
                                <small>Learned map: <?= e(implode(', ', array_map(static fn ($key, $value): string => (string) $key . '=' . (string) $value, array_keys($learnedCommandMap), array_values($learnedCommandMap)))); ?></small>
                            <?php endif; ?>
                            <?php if ($deviceInterfaces !== []): ?>
                                <?php $interfacesUpCount = count(array_filter($deviceInterfaces, static fn (array $row): bool => (string) ($row['if_oper_status'] ?? '') === 'up')); ?>
                                <small>Interfeyslər: <?= (int) count($deviceInterfaces); ?> (<?= $interfacesUpCount; ?> up) -
                                    <?= e(implode(', ', array_map(static fn (array $row): string => (string) ($row['if_descr'] ?? ('if' . $row['if_index'])), array_slice($deviceInterfaces, 0, 6)))); ?>
                                </small>
                            <?php endif; ?>
                            <?php if ($deviceNeighbors !== []): ?>
                                <small>Qonşular: <?= (int) count($deviceNeighbors); ?> -
                                    <?= e(implode(', ', array_map(
                                        static fn (array $row): string => strtoupper((string) $row['protocol']) . ':' . ((string) ($row['remote_sys_name'] ?? '') !== '' ? (string) $row['remote_sys_name'] : (string) $row['remote_chassis_id']),
                                        array_slice($deviceNeighbors, 0, 6)
                                    ))); ?>
                                </small>
                            <?php endif; ?>
                            <div class="noc-device-safe-actions">
                                <form class="noc-inline-action" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="probe_single_device">
                                    <input type="hidden" name="device_key" value="<?= e((string) ($device['device_key'] ?? '')); ?>">
                                    <button class="portal-primary-btn" type="submit">Probe</button>
                                </form>
                                <form class="noc-inline-action" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="discover_device_interfaces">
                                    <input type="hidden" name="device_key" value="<?= e((string) ($device['device_key'] ?? '')); ?>">
                                    <button class="portal-ghost-btn" type="submit">Interfeysləri kəşf et</button>
                                </form>
                                <form class="noc-inline-action" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="discover_device_neighbors">
                                    <input type="hidden" name="device_key" value="<?= e((string) ($device['device_key'] ?? '')); ?>">
                                    <button class="portal-ghost-btn" type="submit">Qonşuları kəşf et</button>
                                </form>
                                <form class="noc-inline-action" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="backup_single_device">
                                    <input type="hidden" name="device_key" value="<?= e((string) ($device['device_key'] ?? '')); ?>">
                                    <select name="backup_type">
                                        <option value="profile_snapshot">profile snapshot</option>
                                        <option value="inventory_snapshot">inventory snapshot</option>
                                        <option value="cli_discovery_snapshot">cli discovery snapshot</option>
                                        <option value="running_config">running config</option>
                                    </select>
                                    <?php if (app_portal_is_admin($user)): ?>
                                        <input type="hidden" name="apply_backup" value="1">
                                    <?php endif; ?>
                                    <button class="portal-ghost-btn" type="submit"><?= app_portal_is_admin($user) ? 'Backup' : 'Backup dry-run'; ?></button>
                                </form>
                                <form class="noc-inline-action" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="run_cli_discovery">
                                    <input type="hidden" name="device_key" value="<?= e((string) ($device['device_key'] ?? '')); ?>">
                                    <button class="portal-ghost-btn" type="submit">CLI discovery</button>
                                </form>
                                <?php
                                $isHsgqOlt = strtolower((string) ($device['device_type'] ?? '')) === 'olt'
                                    && str_contains(strtolower((string) ($device['vendor'] ?? '')), 'hsgq');
                                ?>
                                <?php if ($isHsgqOlt): ?>
                                    <form class="noc-inline-action" method="post" data-live-ont-card-action style="grid-column:1 / -1">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="live_ont_read">
                                        <input type="hidden" name="olt" value="<?= e((string) ($device['device_name'] ?? '')); ?>">
                                        <input type="hidden" name="account_id" value="0">
                                        <input type="hidden" name="ont_q" value="">
                                        <input type="number" name="pon" min="1" max="16" value="<?= e((string) max(1, (int) ($cliDiscovery['sample_pon'] ?? 1))); ?>" placeholder="PON">
                                        <input type="text" name="ont_id" value="<?= e((string) (($cliDiscovery['sample_ont_id'] ?? '') !== '' ? $cliDiscovery['sample_ont_id'] : '1')); ?>" placeholder="ONT ID">
                                        <button class="portal-ghost-btn" type="submit">Live ONT optical oxu</button>
                                        <small><?= $managementPathReady ? 'VLAN 550 aktiv: OLT-den live RX/TX/temperatur oxunacaq.' : 'VLAN 550 passiv: action tehlukesiz sekilde warning qaytaracaq.'; ?></small>
                                    </form>
                                <?php endif; ?>
                                <form class="noc-inline-action" method="post" style="grid-column:1 / -1">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="set_device_monitoring_mode">
                                    <input type="hidden" name="device_key" value="<?= e((string) ($device['device_key'] ?? '')); ?>">
                                    <select name="monitor_mode">
                                        <option value="active"<?= !empty($device['monitoring_enabled']) ? ' selected' : ''; ?>>active monitor</option>
                                        <option value="planned_down"<?= (string) ($device['last_probe_error'] ?? '') === 'planned_down_not_up_yet' ? ' selected' : ''; ?>>planned down</option>
                                        <option value="public_alias"<?= (string) ($device['last_probe_error'] ?? '') === 'public_alias_via_172.16.5.5' ? ' selected' : ''; ?>>public alias</option>
                                    </select>
                                    <button class="portal-ghost-btn" type="submit">Mode apply</button>
                                </form>
                                <?php if (app_portal_is_admin($user)): ?>
                                    <form class="noc-command-console" method="post" style="grid-column:1 / -1">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="device_cli_command">
                                        <input type="hidden" name="device_key" value="<?= e((string) ($device['device_key'] ?? '')); ?>">
                                        <strong>Real CLI command</strong>
                                        <small>Bu emrler SSH ile cihazda icra olunur ve audit yazilir. VLAN 550 bu komputerde yoxdursa internal cihazlar management_path_down qaytaracaq.</small>
                                        <textarea name="commands" placeholder="show version&#10;show interfaces status"></textarea>
                                        <input type="text" name="command_reason" placeholder="Sebeb (yalniz write emrleri ucun mecburidir)">
                                        <div class="noc-command-console-actions">
                                            <label><input type="checkbox" name="allow_dangerous" value="1"> shutdown/delete/restart kimi write emrlerine icaze ver</label>
                                            <button class="portal-primary-btn" type="submit">Real command icra et</button>
                                        </div>
                                        <small>Write (dangerous) emrleri deryal icra olunmur - BASQA admin tesdiqleyene qeder sorgu kimi qalir (vizyon 19.2).</small>
                                    </form>
                                    <?php
                                    $commandMapLines = [];
                                    foreach (app_mgs_device_command_map($device, $db, $user) as $mapKey => $mapCommand) {
                                        $commandMapLines[] = (string) $mapKey . '=' . (string) $mapCommand;
                                    }
                                    ?>
                                    <form class="noc-command-console" method="post" style="grid-column:1 / -1">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="set_device_command_map">
                                        <input type="hidden" name="device_key" value="<?= e((string) ($device['device_key'] ?? '')); ?>">
                                        <strong>Command map editor</strong>
                                        <small>Format: key=command. ONT control ucun minimum: enter_enable, enter_config, enter_gpon_context, ont_restart/ont_disable/ont_enable/ont_delete. Deyisenler: {pon}, {ont}.</small>
                                        <textarea name="command_map_text" placeholder="enter_enable=enable&#10;enter_config=configure&#10;enter_gpon_context=interface gpon {pon}&#10;ont_restart=... {ont}"><?= e(implode("\n", $commandMapLines)); ?></textarea>
                                        <div class="noc-command-console-actions">
                                            <button class="portal-ghost-btn" type="submit">Command map saxla</button>
                                        </div>
                                    </form>
                                    <div class="noc-access-panel" style="grid-column:1 / -1">
                                        <div>
                                            <h4>Access & SNMP profili</h4>
                                            <p>SSH, SNMP read/write community, trap server ve kontakt melumatlari yalniz bu detal panelinde redakte olunur.</p>
                                        </div>
                                        <form class="noc-access-grid" method="post">
                                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="set_device_access_override">
                                            <input type="hidden" name="device_key" value="<?= e((string) ($device['device_key'] ?? '')); ?>">
                                            <input type="text" name="ssh_username" value="<?= e((string) ($accessProfile['ssh_username'] ?? '')); ?>" placeholder="SSH user">
                                            <input type="password" name="ssh_password" value="" placeholder="SSH password (blank=keep)">
                                            <input type="number" name="ssh_port" min="1" max="65535" value="<?= e((string) ($accessProfile['ssh_port'] ?? 22)); ?>" placeholder="SSH port">
                                            <input type="text" name="snmp_read_community" value="<?= e((string) ($accessProfile['read_community'] ?? '')); ?>" placeholder="SNMP RO">
                                            <input type="text" name="snmp_write_community" value="<?= e((string) ($accessProfile['write_community'] ?? '')); ?>" placeholder="SNMP RW">
                                            <input type="number" name="snmp_port" min="1" max="65535" value="<?= e((string) ($accessProfile['port'] ?? 161)); ?>" placeholder="SNMP port">
                                            <input type="text" name="trap_server" value="<?= e((string) ($accessProfile['trap_server'] ?? '')); ?>" placeholder="Trap host">
                                            <input type="number" name="trap_port" min="1" max="65535" value="<?= e((string) ($accessProfile['trap_port'] ?? 162)); ?>" placeholder="Trap port">
                                            <input type="text" name="contact" value="<?= e((string) ($accessProfile['contact'] ?? '')); ?>" placeholder="Contact">
                                            <input type="text" name="location" value="<?= e((string) ($accessProfile['location'] ?? '')); ?>" placeholder="Location">
                                            <input class="wide" type="text" name="notes" value="<?= e((string) ($accessProfile['notes'] ?? '')); ?>" placeholder="Qeyd">
                                            <button class="portal-primary-btn" type="submit">Access save</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                                </div>
                            </details>
                            <?php endif; ?>
                            <?php if ($isRegistered): ?>
                                <div class="noc-device-actions">
                                    <a class="portal-ghost-btn" href="<?= e($deviceDetailHref); ?>" data-noc-open-device-detail>Detallar</a>
                                </div>
                            <?php else: ?>
                                <div class="noc-node-pending">
                                    <strong>Node qeydiyyatda deyil</strong>
                                    <small>Görünürlük aktivdir. Access profili yaradıldıqdan sonra probe, SNMP walk və agent əməliyyatları açılacaq.</small>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($activeNocView === 'backups'): ?>
        <section class="noc-two-col portal-reveal portal-noc-report">
            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Son backup versiyalari</h2>
                    <small>Identik config tekrarlanmir; hash eynidise duplicate kimi qeyd olunur.</small>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Cihaz</th><th>Tip</th><th>Status</th><th>Hash</th><th>Yoxlama</th></tr></thead>
                        <tbody>
                            <?php foreach ($backups as $backup): ?>
                                <tr>
                                    <td><?= e((string) ($backup['device_name'] ?? $backup['device_key'] ?? '-')); ?></td>
                                    <td><?= e((string) ($backup['backup_type'] ?? '-')); ?></td>
                                    <td><?= e((string) ($backup['status'] ?? '-')); ?></td>
                                    <td><code><?= e(substr((string) ($backup['config_hash'] ?? ''), 0, 12)); ?></code></td>
                                    <td><?= e(billing_datetime($backup['last_checked_at'] ?? $backup['created_at'] ?? null)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($backups === []): ?>
                                <tr><td colspan="5">Backup versiyasi hele yoxdur.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Son alarm/dedup veziyyeti</h2>
                    <small>Tekrar alarm varsa yeni Telegram/bilet acilmir, last_seen yenilenir.</small>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Tip</th><th>Obyekt</th><th>Status</th><th>Son gorulme</th></tr></thead>
                        <tbody>
                            <?php foreach ($dedupRows as $row): ?>
                                <tr>
                                    <td><?= e((string) ($row['alarm_type'] ?? '-')); ?></td>
                                    <td><?= e((string) ($row['object_key'] ?? '-')); ?></td>
                                    <td><?= e((string) ($row['status'] ?? '-')); ?></td>
                                    <td><?= e(billing_datetime($row['last_seen_at'] ?? null)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($dedupRows === []): ?>
                                <tr><td colspan="4">Dedup qeydi yoxdur.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
        <?php endif; ?>

        <?php if ($activeNocView === 'ont-search'): ?>
        <section id="ont-search" class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>ONT/SN/MAC native axtarisi</h2>
                <small>Stored customer snapshot uzerinden SN, MAC, subscriber code, PPPoE/IPoE username, OLT/PON/ONT ve optik sahelere gore axtaris. VLAN 550 passiv olsa da saxlanmis profil gorunur.</small>
            </div>
            <form method="get" class="portal-stacked-form" data-noc-auto-filter>
                <input type="hidden" name="view" value="ont-search">
                <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                <div class="billing-filter-grid">
                    <div class="portal-form-row">
                        <label>Axtaris</label>
                        <input type="text" name="ont_q" value="<?= e($ontQuery); ?>" placeholder="SN, MAC, username, muqavile, OLT, PON, SSID">
                    </div>
                    <div class="billing-filter-actions">
                        <button type="submit" class="portal-primary-btn">Axtar</button>
                        <a class="portal-ghost-btn portal-inline-link" href="noc-devices.php?view=ont-search">Sifirla</a>
                    </div>
                </div>
            </form>
            <?php if (is_array($modemBotPreview)): ?>
                <article class="modem-bot-preview" data-modem-bot-preview>
                    <div>
                        <h3>Modem bot cavab preview</h3>
                        <small>Telegram modem botu bu sorgu ucun eyni cavabi gonderecek. Live OLT query varsa birinci onu yoxlayir, alinmasa stored snapshot-a dusur.</small>
                    </div>
                    <?php if (!empty($modemBotPreview['ok'])): ?>
                        <pre><?= e((string) ($modemBotPreview['text'] ?? '')); ?></pre>
                    <?php else: ?>
                        <pre>MODEM AXTARIS NETICESI
Sorgu: <?= e($ontQuery); ?>
Netice: <?= e((string) ($modemBotPreview['reason'] ?? 'not_found')); ?></pre>
                    <?php endif; ?>
                </article>
            <?php endif; ?>
            <?php if ($ontQuery === ''): ?>
                <?php billing_render_empty_state('ONT axtarisi hazirdir', 'SN, MAC, username ve ya muqavile kodu yazin. Netice kartinda optik, Wi-Fi, kabel ve RADIUS kecidleri gorunecek.', 'fa-magnifying-glass'); ?>
            <?php elseif ($ontResults === []): ?>
                <?php billing_render_empty_state('ONT tapilmadi', 'Axtaris kriteriyasina uygun saxlanmis ONT snapshot yoxdur. Live probe ucun VLAN 550 ve OLT path aktiv olmalidir.', 'fa-circle-question'); ?>
            <?php else: ?>
                <div class="portal-badge-list" style="margin-bottom:12px">
                    <span><?= e((string) count($ontResults)); ?> netice</span>
                    <span><?= $db instanceof PDO ? 'DB snapshot' : 'Mock snapshot'; ?></span>
                    <span><?= $managementPathReady ? 'VLAN 550 aktiv' : 'VLAN 550 passiv'; ?></span>
                </div>
                <div class="noc-search-grid">
                    <?php foreach ($ontResults as $ontRow): ?>
                        <?php
                        $profileUrl = 'billing-customer-profile.php?id=' . rawurlencode((string) $ontRow['account_id']) . '#profile-modems';
                        $ticketUrl = 'billing-customer-profile.php?id=' . rawurlencode((string) $ontRow['account_id']) . '#profile-tickets';
                        $radiusUrl = 'radius-active.php?q=' . rawurlencode((string) ($ontRow['username'] !== '' ? $ontRow['username'] : $ontRow['subscriber_code']));
                        $onlineTone = str_contains(noc_text_lower((string) $ontRow['ont_status']), 'online') || str_contains(noc_text_lower((string) $ontRow['ont_status']), 'aktiv')
                            ? 'success'
                            : 'warning';
                        $radiusTone = !empty($ontRow['radius_online']) ? 'success' : 'muted';
                        $ticketTone = ((int) ($ontRow['open_ticket_count'] ?? 0) > 0) ? 'warning' : 'muted';
                        $radiusStatusText = !empty($ontRow['radius_online']) ? 'RADIUS online' : ('RADIUS ' . ((string) ($ontRow['radius_status'] ?? '') !== '' ? (string) $ontRow['radius_status'] : 'offline'));
                        $ticketStatusText = ((int) ($ontRow['open_ticket_count'] ?? 0) > 0)
                            ? ((int) $ontRow['open_ticket_count']) . ' aciq ticket'
                            : (((int) ($ontRow['total_ticket_count'] ?? 0) > 0) ? 'Aciq ticket yoxdur' : 'Ticket yoxdur');
                        $isLiveMatch = !empty($liveOntResult['ok'])
                            && (int) ($liveOntResult['account_id'] ?? 0) === (int) ($ontRow['account_id'] ?? 0)
                            && trim((string) ($liveOntResult['pon'] ?? '')) === trim((string) ($ontRow['pon'] ?? ''))
                            && trim((string) ($liveOntResult['ont_id'] ?? '')) === trim((string) ($ontRow['ont_id'] ?? ''));
                        $liveOnt = $isLiveMatch && is_array($liveOntResult['ont'] ?? null) ? $liveOntResult['ont'] : [];
                        ?>
                        <article class="noc-search-card">
                            <div class="noc-search-head">
                                <div>
                                    <small><?= e((string) $ontRow['subscriber_code']); ?></small>
                                    <strong><?= e((string) $ontRow['full_name']); ?></strong>
                                </div>
                                <span class="noc-pill noc-pill-<?= e($onlineTone); ?>"><?= e((string) $ontRow['ont_status']); ?></span>
                            </div>
                            <div class="noc-search-meta">
                                <small><?= e((string) $ontRow['service_type']); ?></small>
                                <small><?= e((string) $ontRow['username']); ?></small>
                                <small><?= e((string) $ontRow['area']); ?></small>
                                <small class="noc-pill noc-pill-<?= e($radiusTone); ?>"><?= e($radiusStatusText); ?></small>
                                <small class="noc-pill noc-pill-<?= e($ticketTone); ?>"><?= e($ticketStatusText); ?></small>
                            </div>
                            <div class="noc-search-kv">
                                <span>OLT / PON / ONT<b><?= e((string) $ontRow['olt']); ?> / <?= e((string) $ontRow['pon']); ?> / <?= e((string) $ontRow['ont_id']); ?></b></span>
                                <span>SN / MAC<b><?= e((string) $ontRow['serial']); ?><br><?= e((string) $ontRow['mac']); ?></b></span>
                                <span>ONT RX / TX<b><?= e((string) $ontRow['ont_rx']); ?> / <?= e((string) $ontRow['ont_tx']); ?></b></span>
                                <span>OLT RX / TX<b><?= e((string) $ontRow['olt_rx']); ?> / <?= e((string) $ontRow['olt_tx']); ?></b></span>
                                <span>Temperatur<b><?= e((string) $ontRow['temperature']); ?> C</b></span>
                                <span>IP / NAT / Static<b><?= e((string) $ontRow['assigned_ip'] ?: '-'); ?><br><?= e((string) $ontRow['nat_public_ip'] ?: '-'); ?> / <?= e((string) $ontRow['static_ip'] ?: '-'); ?></b></span>
                                <span>Wi-Fi<b><?= e((string) $ontRow['wifi_ssid']); ?><br><?= e((string) $ontRow['wifi_password']); ?></b></span>
                                <span>Kabel / connector<b><?= e((string) $ontRow['cable_meter']); ?> m<br><?= e((string) $ontRow['fast_connector']); ?></b></span>
                                <span>Sessiya / son gorulme<b><?= e(billing_datetime($ontRow['session_started_at'] ?? null)); ?><br><?= e(billing_datetime($ontRow['session_last_seen_at'] ?? null)); ?></b></span>
                                <span>NAS / port<b><?= e((string) (($ontRow['session_nas'] ?? '') !== '' ? $ontRow['session_nas'] : '-')); ?><br><?= e((string) (($ontRow['session_port'] ?? '') !== '' ? $ontRow['session_port'] : '-')); ?></b></span>
                                <span>Trafik down / up<b><?= e(billing_bytes((float) ($ontRow['session_download'] ?? 0))); ?><br><?= e(billing_bytes((float) ($ontRow['session_upload'] ?? 0))); ?></b></span>
                                <span>Son ticket<b><?= e((string) (($ontRow['last_ticket_no'] ?? '') !== '' ? $ontRow['last_ticket_no'] : '-')); ?><br><?= e((string) (($ontRow['last_ticket_subject'] ?? '') !== '' ? $ontRow['last_ticket_subject'] : '-')); ?></b></span>
                            </div>
                            <?php if ($isLiveMatch): ?>
                                <div class="noc-search-kv">
                                    <span>Live temperatur / gerginlik<b><?= e((string) ($liveOnt['temperature'] ?? '-')); ?> C<br><?= e((string) ($liveOnt['voltage'] ?? '-')); ?> V</b></span>
                                    <span>Live bias / status<b><?= e((string) ($liveOnt['bias_current'] ?? '-')); ?> mA<br><?= e((string) ($liveOnt['status'] ?? '-')); ?></b></span>
                                    <span>Live ONT RX / TX<b><?= e((string) ($liveOnt['ont_rx'] ?? '-')); ?><br><?= e((string) ($liveOnt['ont_tx'] ?? '-')); ?></b></span>
                                    <span>Live OLT RX / TX<b><?= e((string) ($liveOnt['olt_rx'] ?? '-')); ?><br><?= e((string) ($liveOnt['olt_tx'] ?? '-')); ?></b></span>
                                    <span>Distance / uptime<b><?= e((string) ($liveOnt['distance'] ?? '-')); ?><br><?= e((string) ($liveOnt['uptime'] ?? '-')); ?></b></span>
                                    <span>Port state / son up<b><?= e((string) ($liveOnt['port_state'] ?? '-')); ?><br><?= e((string) ($liveOnt['last_up_time'] ?? '-')); ?></b></span>
                                </div>
                            <?php endif; ?>
                            <small><?= e((string) $ontRow['phone']); ?><?= $ontRow['address'] !== '' ? ' / ' . e((string) $ontRow['address']) : ''; ?></small>
                            <div class="noc-search-actions">
                                <a class="portal-primary-btn portal-inline-link" href="<?= e($profileUrl); ?>">Musteri 360</a>
                                <a class="portal-ghost-btn portal-inline-link" href="<?= e($ticketUrl); ?>">Ticketler</a>
                                <a class="portal-ghost-btn portal-inline-link" href="<?= e($radiusUrl); ?>">RADIUS konteksti</a>
                                <?php if (!empty($ontRow['live_capable'])): ?>
                                    <form method="post" class="portal-inline-form">
                                        <?= app_csrf_field(); ?>
                                        <input type="hidden" name="action" value="live_ont_read">
                                        <input type="hidden" name="ont_q" value="<?= e($ontQuery); ?>">
                                        <input type="hidden" name="account_id" value="<?= e((string) $ontRow['account_id']); ?>">
                                        <input type="hidden" name="olt" value="<?= e((string) $ontRow['olt']); ?>">
                                        <input type="hidden" name="pon" value="<?= e((string) $ontRow['pon']); ?>">
                                        <input type="hidden" name="ont_id" value="<?= e((string) $ontRow['ont_id']); ?>">
                                        <button type="submit" class="portal-ghost-btn">Live optical oxu</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if ($activeNocView === 'legacy-onts'): ?>
        <section id="legacy-onts" class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Kohne MGS ONT arxivi</h2>
                <small>Ayrica Node MGS proqramindan (docs/39_MGS_NODE_CONSOLIDATION.md Faza 2) kocurulmus 730 tarixi ONT qeydi. Bu, canli monitorinq deyil ve hec bir muster hesabi ile baglanmayib (menbe datada real musteri elaqesi hec vaxt olmayib) — yalniz OLT/PON kontekstinde tarixi referansdir.</small>
            </div>
            <form method="get" class="portal-stacked-form" data-noc-auto-filter>
                <input type="hidden" name="view" value="legacy-onts">
                <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                <div class="billing-filter-grid">
                    <div class="portal-form-row">
                        <label>OLT</label>
                        <select name="legacy_olt">
                            <option value="">Butun OLT-lar</option>
                            <?php foreach ($legacyOntInventory['olts'] as $oltOption): ?>
                                <?php
                                $oltKey = (string) $oltOption['olt_device_key'];
                                $oltDevice = app_mgs_device_learning_profile_by_key($oltKey, $db, $user);
                                $oltLabel = (is_array($oltDevice) ? (string) ($oltDevice['device_name'] ?? '') : '') ?: $oltKey;
                                ?>
                                <option value="<?= e($oltKey); ?>" <?= ($_GET['legacy_olt'] ?? '') === $oltKey ? 'selected' : ''; ?>><?= e($oltLabel); ?> (<?= (int) $oltOption['c']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Status</label>
                        <select name="legacy_status">
                            <option value="">Hamisi</option>
                            <?php foreach (['online', 'offline', 'flapping', 'disabled'] as $statusOption): ?>
                                <option value="<?= e($statusOption); ?>" <?= ($_GET['legacy_status'] ?? '') === $statusOption ? 'selected' : ''; ?>><?= e($statusOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Axtaris</label>
                        <input type="text" name="legacy_q" value="<?= e((string) ($_GET['legacy_q'] ?? '')); ?>" placeholder="Serial ve ya port">
                    </div>
                    <div class="billing-filter-actions">
                        <button type="submit" class="portal-primary-btn">Axtar</button>
                        <a class="portal-ghost-btn portal-inline-link" href="noc-devices.php?view=legacy-onts">Sifirla</a>
                    </div>
                </div>
            </form>
            <?php if ($legacyOntInventory['rows'] === []): ?>
                <?php billing_render_empty_state('Netice yoxdur', 'Filterlere uygun kohne ONT qeydi tapilmadi.', 'fa-box-archive'); ?>
            <?php else: ?>
                <div class="portal-badge-list" style="margin-bottom:12px">
                    <span><?= (int) $legacyOntInventory['total']; ?> qeyd</span>
                    <span>Sehife <?= (int) $legacyOntInventory['page']; ?> / <?= (int) $legacyOntInventory['total_pages']; ?></span>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>OLT</th><th>Port</th><th>Serial</th><th>Status</th><th>RX / TX / OLT-RX (dBm)</th><th>Mesafe</th><th>Vendor</th><th>Menbe yenilenme</th></tr></thead>
                        <tbody>
                            <?php foreach ($legacyOntInventory['rows'] as $ontInventoryRow): ?>
                                <?php
                                $rowOltDevice = app_mgs_device_learning_profile_by_key((string) $ontInventoryRow['olt_device_key'], $db, $user);
                                $rowOltLabel = (is_array($rowOltDevice) ? (string) ($rowOltDevice['device_name'] ?? '') : '') ?: (string) $ontInventoryRow['olt_device_key'];
                                ?>
                                <tr>
                                    <td data-label="OLT"><?= e($rowOltLabel); ?></td>
                                    <td data-label="Port"><?= e((string) $ontInventoryRow['port_key']); ?></td>
                                    <td data-label="Serial"><?= e((string) $ontInventoryRow['serial_number']); ?></td>
                                    <?php
                                    $legacyOntTone = match ((string) $ontInventoryRow['status']) {
                                        'online' => 'success',
                                        'offline' => 'danger',
                                        'flapping' => 'warning',
                                        'disabled' => 'muted',
                                        default => 'muted',
                                    };
                                    ?>
                                    <td data-label="Status"><span class="noc-pill noc-pill-<?= e($legacyOntTone); ?>"><?= e((string) $ontInventoryRow['status']); ?></span></td>
                                    <td data-label="RX/TX/OLT-RX"><?= e((string) ($ontInventoryRow['rx_dbm'] ?? '-')); ?> / <?= e((string) ($ontInventoryRow['tx_dbm'] ?? '-')); ?> / <?= e((string) ($ontInventoryRow['olt_rx_dbm'] ?? '-')); ?></td>
                                    <td data-label="Mesafe"><?= (int) $ontInventoryRow['distance_meters']; ?> m</td>
                                    <td data-label="Vendor"><?= e((string) $ontInventoryRow['ont_vendor']); ?></td>
                                    <td data-label="Menbe yenilenme"><?= e(billing_datetime($ontInventoryRow['source_updated_at'] ?? null)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ((int) $legacyOntInventory['total_pages'] > 1): ?>
                    <?php
                    $legacyPageParams = $_GET;
                    unset($legacyPageParams['legacy_page']);
                    $legacyPrevParams = $legacyPageParams + ['legacy_page' => max(1, (int) $legacyOntInventory['page'] - 1)];
                    $legacyNextParams = $legacyPageParams + ['legacy_page' => min((int) $legacyOntInventory['total_pages'], (int) $legacyOntInventory['page'] + 1)];
                    ?>
                    <div class="billing-filter-actions" style="margin-top:12px">
                        <a class="portal-ghost-btn portal-inline-link" href="noc-devices.php?<?= e(http_build_query($legacyPrevParams)); ?>">Evvelki</a>
                        <a class="portal-ghost-btn portal-inline-link" href="noc-devices.php?<?= e(http_build_query($legacyNextParams)); ?>">Novbeti</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        </div>
    </main>
    <script>
    window.SuperopticInitNocMonitor = () => {
        const board = document.querySelector('[data-native-monitor-board]');
        if (!board) {
            return;
        }
        if (board.dataset.monitorReady === '1') {
            return;
        }
        board.dataset.monitorReady = '1';
        const stage = board.querySelector('[data-monitor-stage]');
        const nodes = Array.from(board.querySelectorAll('[data-monitor-node]'));
        const lines = Array.from(board.querySelectorAll('.monitor-link'));
        const zoomValue = board.querySelector('[data-monitor-zoom-value]');
        const saveUrl = board.dataset.monitorSaveUrl || '';
        const csrfToken = board.dataset.monitorCsrf || '';
        const mapKey = board.dataset.monitorMapKey || 'noc-monitor';
        const storageKey = board.dataset.monitorStorageKey || 'superoptic.noc.monitor.map.v2';
        const serverBacked = saveUrl !== '';
        const saveTimers = new Map();
        const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
        let state = {};
        let zoom = 1;
        if (!serverBacked) {
            try {
                state = JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
            } catch (error) {
                state = {};
            }
        }
        const saveLocal = () => {
            try {
                localStorage.setItem(storageKey, JSON.stringify(state));
            } catch (error) {
            }
        };
        const nodePosition = (node) => ({
            x: Number.parseFloat(node.style.left || node.dataset.defaultX || '50'),
            y: Number.parseFloat(node.style.top || node.dataset.defaultY || '50'),
        });
        const nodePayload = (id, node, note) => {
            const position = nodePosition(node);
            const scale = Number.parseFloat(node.style.getPropertyValue('--node-scale') || '1');
            return {
                map_key: mapKey,
                device_id: id,
                x: Number.isFinite(position.x) ? position.x : 50,
                y: Number.isFinite(position.y) ? position.y : 50,
                scale: Number.isFinite(scale) ? scale : 1,
                note: note ? note.value : '',
                csrf_token: csrfToken,
            };
        };
        const persistNode = (id, node, note, immediate = false) => {
            const payload = nodePayload(id, node, note);
            state[id] = { x: payload.x, y: payload.y, scale: payload.scale, note: payload.note };
            if (!serverBacked) {
                saveLocal();
                return;
            }
            if (saveTimers.has(id)) {
                window.clearTimeout(saveTimers.get(id));
            }
            const submit = () => {
                fetch(saveUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify(payload),
                }).catch(() => {
                    saveLocal();
                });
            };
            if (immediate) {
                submit();
            } else {
                saveTimers.set(id, window.setTimeout(submit, 420));
            }
        };
        const updateLinks = () => {
            lines.forEach((line) => {
                const id = line.dataset.monitorLinkTo || '';
                const node = nodes.find((item) => (item.dataset.monitorId || '') === id);
                if (!node) {
                    return;
                }
                const pos = nodePosition(node);
                line.setAttribute('x2', `${pos.x}`);
                line.setAttribute('y2', `${pos.y}`);
            });
        };
        const applyNodeScale = (node, scale) => {
            const next = clamp(Number.isFinite(scale) ? scale : 1, 0.45, 1.65);
            node.style.setProperty('--node-scale', String(next));
            node.querySelector('[data-node-scale-value]')?.replaceChildren(document.createTextNode(`${Math.round(next * 100)}%`));
            return next;
        };
        const applyZoom = () => {
            if (stage) {
                stage.style.width = `${100 / zoom}%`;
                stage.style.height = `${100 / zoom}%`;
                stage.style.transform = `scale(${zoom})`;
            }
            if (zoomValue) {
                zoomValue.textContent = `${Math.round(zoom * 100)}%`;
            }
        };
        nodes.forEach((node, index) => {
            const id = node.dataset.monitorId || `node-${index}`;
            const saved = node.dataset.serverLayout === '1' ? {} : (state[id] || {});
            const renderedX = Number.parseFloat(node.style.left || node.dataset.defaultX || '50');
            const renderedY = Number.parseFloat(node.style.top || node.dataset.defaultY || '50');
            const x = Number.isFinite(saved.x) ? saved.x : (Number.isFinite(renderedX) ? renderedX : 50);
            const y = Number.isFinite(saved.y) ? saved.y : (Number.isFinite(renderedY) ? renderedY : 50);
            node.style.left = `${x}%`;
            node.style.top = `${y}%`;
            const styleScale = Number.parseFloat(node.style.getPropertyValue('--node-scale') || '1');
            const initialScale = applyNodeScale(node, Number.isFinite(saved.scale) ? saved.scale : (Number.isFinite(styleScale) ? styleScale : 1));
            const note = node.querySelector('[data-monitor-note]');
            if (note && typeof saved.note === 'string') {
                note.value = saved.note;
            }
            node.querySelectorAll('[data-node-scale]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    const current = Number.parseFloat(node.style.getPropertyValue('--node-scale') || String(initialScale) || '1');
                    const next = applyNodeScale(node, current + (button.dataset.nodeScale === 'in' ? 0.1 : -0.1));
                    persistNode(id, node, note);
                    updateLinks();
                });
            });
            if (note) {
                note.addEventListener('input', () => {
                    persistNode(id, node, note);
                });
            }
            let dragging = false;
            node.addEventListener('pointerdown', (event) => {
                if (event.target?.closest?.('[data-monitor-note],[data-monitor-node-tools]')) {
                    return;
                }
                dragging = true;
                node.setPointerCapture(event.pointerId);
                event.preventDefault();
            });
            node.addEventListener('pointermove', (event) => {
                if (!dragging) {
                    return;
                }
                const rect = (stage || board).getBoundingClientRect();
                const xPct = clamp(((event.clientX - rect.left) / rect.width) * 100, -8, 108);
                const yPct = clamp(((event.clientY - rect.top) / rect.height) * 100, -8, 108);
                node.style.left = `${xPct}%`;
                node.style.top = `${yPct}%`;
                persistNode(id, node, note);
                updateLinks();
            });
            const stopDrag = () => {
                dragging = false;
            };
            node.addEventListener('pointerup', stopDrag);
            node.addEventListener('pointercancel', stopDrag);
        });
        board.querySelectorAll('[data-monitor-zoom]').forEach((button) => {
            button.addEventListener('click', () => {
                zoom = clamp(zoom + (button.dataset.monitorZoom === 'in' ? 0.1 : -0.1), 0.35, 1.8);
                applyZoom();
                updateLinks();
            });
        });
        board.querySelector('[data-monitor-reset]')?.addEventListener('click', () => {
            nodes.forEach((node, index) => {
                const id = node.dataset.monitorId || `node-${index}`;
                const x = Number.parseFloat(node.dataset.defaultX || '50');
                const y = Number.parseFloat(node.dataset.defaultY || '50');
                node.style.left = `${x}%`;
                node.style.top = `${y}%`;
                const note = node.querySelector('[data-monitor-note]');
                const scale = applyNodeScale(node, 1);
                persistNode(id, node, note, true);
            });
            zoom = 1;
            applyZoom();
            updateLinks();
        });
        board.querySelector('[data-monitor-fullscreen]')?.addEventListener('click', async () => {
            try {
                if (document.fullscreenElement === board) {
                    await document.exitFullscreen();
                } else if (board.requestFullscreen) {
                    await board.requestFullscreen();
                } else {
                    board.classList.toggle('is-fullscreen-fallback');
                }
            } catch (error) {
                board.classList.toggle('is-fullscreen-fallback');
            }
        });
        document.addEventListener('fullscreenchange', () => {
            board.classList.toggle('is-fullscreen-fallback', document.fullscreenElement === board && !board.matches(':fullscreen'));
            updateLinks();
        });
        applyZoom();
        updateLinks();
        window.SuperopticNocMonitor = { updateLinks };
        lines.forEach((line) => line.setAttribute('vector-effect', 'non-scaling-stroke'));
    };
    window.SuperopticInitNocMonitor();
    </script>
    <?php app_noc_render_scan_bulk_script(); ?>
    <script>
    (() => {
        const shell = document.querySelector('[data-noc-shell]');
        if (!shell || !window.fetch || !window.DOMParser) return;
        let controller = null;
        let filterTimer = null;
        const viewFromUrl = url => {
            const target = new URL(url, window.location.href);
            if ((target.searchParams.get('ont_q') || '').trim() !== '') return 'ont-search';
            return target.searchParams.get('view') || 'monitor';
        };
        const updateShell = (url, html) => {
            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const nextContent = parsed.querySelector('[data-noc-content]');
            const currentContent = shell.querySelector('[data-noc-content]');
            if (!nextContent || !currentContent) throw new Error('NOC content not found');
            currentContent.innerHTML = nextContent.innerHTML;
            const view = viewFromUrl(url);
            shell.dataset.nocView = view;
            shell.querySelectorAll('[data-noc-nav]').forEach(link => {
                const target = new URL(link.href, window.location.href);
                if (target.pathname.split('/').pop() !== 'noc-devices.php') return;
                const linkView = link.dataset.moduleSection || viewFromUrl(target);
                link.classList.toggle('is-active', linkView === view);
            });
            shell.querySelectorAll('[data-module-nav-group]').forEach(group => {
                const active = !!group.querySelector('[data-noc-nav].is-active');
                group.classList.toggle('is-active-group', active);
                if (active) group.open = true;
            });
            const status = shell.querySelector('[data-noc-status]');
            if (status) status.textContent = '';
            window.SuperopticInitNocMonitor?.();
            document.querySelector('[data-noc-scan-dialog].is-open')?.focus();
        };
        const loadNoc = async (url, push = true) => {
            if (controller) controller.abort();
            controller = new AbortController();
            shell.classList.add('is-loading');
            const target = new URL(url, window.location.href);
            try {
                const response = await fetch(target, {headers: {'X-Requested-With': 'fetch'}, signal: controller.signal});
                if (!response.ok) throw new Error('NOC panel yenilenmedi');
                updateShell(target, await response.text());
                if (push === 'replace') history.replaceState({noc: true}, '', target);
                else if (push) history.pushState({noc: true}, '', target);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    const status = shell.querySelector('[data-noc-status]');
                    if (status) status.textContent = error.message || 'NOC panel yenilenmedi.';
                }
            } finally {
                shell.classList.remove('is-loading');
            }
        };
        shell.addEventListener('click', event => {
            const link = event.target.closest('a[href]');
            if (!link || link.classList.contains('is-disabled')) return;
            const target = new URL(link.href, window.location.href);
            if (target.pathname.split('/').pop() !== 'noc-devices.php') return;
            event.preventDefault();
            loadNoc(target);
        });
        shell.addEventListener('submit', event => {
            const form = event.target.closest('form');
            if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
            event.preventDefault();
            const target = new URL(form.action || window.location.href, window.location.href);
            new FormData(form).forEach((value, key) => target.searchParams.set(key, value));
            const historyMode = form.dataset.filterHistory === 'replace' ? 'replace' : true;
            delete form.dataset.filterHistory;
            loadNoc(target, historyMode);
        });
        shell.addEventListener('input', event => {
            const field = event.target.closest('[data-noc-auto-filter] input[name="ont_q"]');
            if (!field) return;
            const form = field.closest('form');
            const query = field.value.trim();
            window.clearTimeout(filterTimer);
            if (query.length === 1) return;
            filterTimer = window.setTimeout(() => {
                if (!form?.isConnected) return;
                form.dataset.filterHistory = 'replace';
                form.requestSubmit();
            }, query === '' ? 0 : 400);
        });
        window.addEventListener('popstate', () => loadNoc(window.location.href, false));
        window.addEventListener('superoptic:noc-refresh', () => loadNoc(window.location.href, false));
        window.SuperopticReloadNocContent = loadNoc;
    })();
    </script>
    <script>
    (() => {
        const closeOpenDeviceDetails = () => {
            document.querySelectorAll('.noc-device-detail[open]').forEach((details) => {
                details.removeAttribute('open');
            });
        };
        const handleDetailClose = (event) => {
            const closeButton = event.target?.closest?.('[data-noc-close-details]');
            if (closeButton) {
                event.preventDefault();
                event.stopPropagation();
                closeOpenDeviceDetails();
                return;
            }
        };
        document.addEventListener('pointerdown', handleDetailClose, true);
        document.addEventListener('click', (event) => {
            handleDetailClose(event);
            if (event.defaultPrevented) {
                return;
            }
            const openButton = event.target?.closest?.('[data-noc-open-device-detail]');
            if (openButton) {
                const card = openButton.closest('.noc-device-card');
                const details = card?.querySelector?.('.noc-device-detail');
                if (details) {
                    event.preventDefault();
                    details.open = true;
                }
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }
            closeOpenDeviceDetails();
        });
    })();
    </script>
    <script src="<?= e(app_asset('script/portal.js')); ?>" defer></script>
</body>
</html>
