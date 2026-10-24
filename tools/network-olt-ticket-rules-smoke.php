<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function network_olt_rules_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    network_olt_rules_fail('db_unavailable', 2);
}

$stamp = date('His') . random_int(100, 999);
$prefix = 'SMOKE-OLT-RULE-' . $stamp;
$createdCodes = [];
$dedupNeedles = [];
$offlineDeviceKey = 'smoke-offline-device-' . strtolower($stamp);
$offlineDeviceDedup = 'device-offline:' . $offlineDeviceKey;
$previousDeviceState = null;
$nocActor = app_noc_system_actor('olt-rule-worker');

try {
    app_mgs_seed_device_learning_profiles($db, $nocActor);

    $previousDevice = $db->prepare(
        'SELECT reachability_state, status, last_probe_error
           FROM device_learning_profiles
          WHERE device_key = :device_key
          LIMIT 1'
    );
    $previousDevice->execute(['device_key' => $offlineDeviceKey]);
    $row = $previousDevice->fetch(PDO::FETCH_ASSOC);
    $previousDeviceState = is_array($row) ? $row : null;
    $db->prepare(
        'INSERT INTO device_learning_profiles (
            device_key, device_name, ip_address, device_type, vendor, model, adapter_key, management_network,
            expected_reachability, monitoring_enabled, reachability_state, confidence_score, features_json, command_map_json,
            snmp_oids_json, status, last_probe_error
         ) VALUES (
            :device_key, :device_name, :ip_address, "switch", "Cisco", "2960", "CiscoSwitchAdapter", "vlan550",
            1, 1, "unreachable_confirmed", 0.95, :features_json, :command_map_json, :snmp_oids_json, "failed", "smoke_offline_device"
         )
         ON DUPLICATE KEY UPDATE
            device_name = VALUES(device_name),
            ip_address = VALUES(ip_address),
            device_type = VALUES(device_type),
            vendor = VALUES(vendor),
            model = VALUES(model),
            adapter_key = VALUES(adapter_key),
            management_network = VALUES(management_network),
            expected_reachability = VALUES(expected_reachability),
            monitoring_enabled = VALUES(monitoring_enabled),
            reachability_state = VALUES(reachability_state),
            confidence_score = VALUES(confidence_score),
            features_json = VALUES(features_json),
            command_map_json = VALUES(command_map_json),
            snmp_oids_json = VALUES(snmp_oids_json),
            status = VALUES(status),
            last_probe_error = VALUES(last_probe_error)'
    )->execute([
        'device_key' => $offlineDeviceKey,
        'device_name' => 'Smoke Offline Switch ' . $stamp,
        'ip_address' => '172.16.5.241',
        'features_json' => app_db_json_encode(['interfaces', 'mac_table', 'vlan_list']),
        'command_map_json' => app_db_json_encode(['show_version' => 'show version', 'interfaces' => 'show interfaces status']),
        'snmp_oids_json' => app_db_json_encode([]),
    ]);
    $dedupNeedles[] = $offlineDeviceDedup;

    $sets = [
        [
            'olt' => 'Kurdexani HSGQ 20',
            'pon' => 'PON01',
            'base' => 100,
            'dedup' => 'pon-mass-optical:olt-hsgq-kurdexani-20:pon01',
        ],
        [
            'olt' => 'HSGQ 172.16.5.26',
            'pon' => 'PON03',
            'base' => 300,
            'dedup' => 'pon-mass-optical:olt-hsgq-172-16-5-26:pon03',
        ],
    ];

    foreach ($sets as $set) {
        $dedupNeedles[] = $set['dedup'];
        for ($i = 1; $i <= 9; $i++) {
            $code = $prefix . '-' . preg_replace('/[^0-9]/', '', (string) $set['pon']) . '-' . $i;
            $createdCodes[] = $code;
            $saved = app_db_upsert_account([
                'login' => strtolower($code),
                'password' => 'SmokePass123',
                'role' => 'customer',
                'account_type' => 'customer',
                'full_name' => 'OLT Rule Smoke ' . $code,
                'subscriber_code' => $code,
                'phone' => '+99450123' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
                'area' => 'Sebeke',
                'address' => 'OLT rule smoke',
                'plan' => 'Smoke',
                'status' => 'Aktiv',
                'is_active' => 1,
                'payload' => [
                    'billing' => ['status' => 'Aktiv'],
                    'internet_services' => [[
                        'service_type' => 'pppoe',
                        'username' => $code,
                        'olt' => (string) $set['olt'],
                        'pon' => (string) $set['pon'],
                        'ont_id' => (string) ((int) $set['base'] + $i),
                    ]],
                    'ont_profiles' => [[
                        'olt' => (string) $set['olt'],
                        'pon' => (string) $set['pon'],
                        'ont_id' => (string) ((int) $set['base'] + $i),
                        'serial' => 'SMK' . $stamp . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                        'mac' => 'AA:BB:CC:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ':00:01',
                        'rx_power' => '-29.' . $i,
                        'ont_rx' => '-29.' . $i,
                        'olt_rx' => '-30.' . $i,
                        'ont_tx' => '2.1',
                        'olt_tx' => '4.0',
                        'temperature' => '70',
                        'optical_synced_at' => date('Y-m-d H:i:s'),
                    ]],
                ],
            ], false);

            if (!is_array($saved)) {
                network_olt_rules_fail('account_seed_failed:' . $code, 3);
            }
        }
    }

    $result = app_mgs_analyze_olt_ticket_rules([
        'test_mode' => true,
        'queue_messages' => false,
        'create_tickets' => true,
        'require_management_path' => false,
        'olt_mass_pon_min_affected' => 5,
    ], $db, $nocActor);

    if (empty($result['ok'])) {
        network_olt_rules_fail('engine_failed:' . (string) ($result['reason'] ?? 'unknown'), 4);
    }

    $resultDedups = array_map(static fn (array $row): string => (string) ($row['dedup_key'] ?? ''), array_filter((array) ($result['results'] ?? []), 'is_array'));
    foreach ($dedupNeedles as $dedup) {
        if (!in_array($dedup, $resultDedups, true)) {
            network_olt_rules_fail('missing_dedup:' . $dedup, 5);
        }
    }

    if (((int) ($result['tickets_created'] ?? 0) + (int) ($result['tickets_duplicate'] ?? 0)) < 3) {
        network_olt_rules_fail('expected_three_tickets:' . json_encode([
            'alerts' => (int) ($result['alerts'] ?? 0),
            'tickets_created' => (int) ($result['tickets_created'] ?? 0),
            'tickets_duplicate' => (int) ($result['tickets_duplicate'] ?? 0),
            'dedups' => $resultDedups,
        ], JSON_UNESCAPED_SLASHES), 6);
    }

    echo json_encode([
        'status' => 'ok',
        'devices_scanned' => (int) ($result['devices_scanned'] ?? 0),
        'onts_checked' => (int) ($result['onts_checked'] ?? 0),
        'alerts' => (int) ($result['alerts'] ?? 0),
        'tickets_created' => (int) ($result['tickets_created'] ?? 0),
        'tickets_duplicate' => (int) ($result['tickets_duplicate'] ?? 0),
        'covered_dedups' => $dedupNeedles,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if (is_array($previousDeviceState)) {
        $db->prepare(
            'UPDATE device_learning_profiles
                SET reachability_state = :reachability_state,
                    status = :status,
                    last_probe_error = :last_probe_error
              WHERE device_key = :device_key'
        )->execute([
            'reachability_state' => (string) ($previousDeviceState['reachability_state'] ?? 'unknown'),
            'status' => (string) ($previousDeviceState['status'] ?? 'pending_discovery'),
            'last_probe_error' => $previousDeviceState['last_probe_error'] ?? null,
            'device_key' => $offlineDeviceKey,
        ]);
    } else {
        $db->prepare('DELETE FROM device_learning_profiles WHERE device_key = :device_key')->execute(['device_key' => $offlineDeviceKey]);
    }
    if ($createdCodes !== []) {
        $placeholders = implode(',', array_fill(0, count($createdCodes), '?'));
        $db->prepare('DELETE FROM portal_accounts WHERE subscriber_code IN (' . $placeholders . ')')->execute($createdCodes);
    }
    foreach ($dedupNeedles as $dedup) {
        $tickets = $db->prepare('SELECT id FROM portal_tickets WHERE metadata_json LIKE :needle');
        $tickets->execute(['needle' => '%' . $dedup . '%']);
        $ids = array_map('intval', $tickets->fetchAll(PDO::FETCH_COLUMN) ?: []);
        foreach ($ids as $id) {
            $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM portal_tickets WHERE id = :id')->execute(['id' => $id]);
        }
        $db->prepare('DELETE FROM mgs_alarm_events WHERE dedup_key = :dedup')->execute(['dedup' => $dedup]);
        $db->prepare('DELETE FROM mgs_dedup_registry WHERE dedup_key = :dedup')->execute(['dedup' => $dedup]);
    }
}
