<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

class MgsDeviceNeighborsSmokeFailure extends RuntimeException
{
}

function mgs_neighbors_smoke_fail(string $message): never
{
    throw new MgsDeviceNeighborsSmokeFailure($message);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}
if (!app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'mgs_schema_unavailable' . PHP_EOL);
    exit(2);
}
$hasNeighborsTable = (int) $db->query(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "device_neighbors"'
)->fetchColumn() > 0;
if (!$hasNeighborsTable) {
    fwrite(STDERR, 'device_neighbors_not_migrated:run tools/run-portal-migrations.php' . PHP_EOL);
    exit(2);
}

$actor = ['id' => 900901, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'full_name' => 'Smoke Admin'];
$deviceKey = 'smoke-neighbors-' . bin2hex(random_bytes(4));
$community = 'smoke' . bin2hex(random_bytes(3));
$agentProcess = null;
$agentPipes = null;

$cleanup = function () use ($db, &$deviceKey, &$agentProcess, &$agentPipes): void {
    if (is_resource($agentProcess)) {
        proc_terminate($agentProcess);
        foreach ((array) $agentPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($agentProcess);
    }
    $db->prepare('DELETE FROM device_neighbors WHERE device_key = :k')->execute(['k' => $deviceKey]);
    $db->prepare('DELETE FROM device_access_overrides WHERE device_key = :k')->execute(['k' => $deviceKey]);
    $db->prepare('DELETE FROM device_learning_profiles WHERE device_key = :k')->execute(['k' => $deviceKey]);
};

try {
    // --- Unreachable target: must fail cleanly, not hang or throw ---
    $unreachable = app_mgs_discover_device_neighbors(
        ['device_key' => $deviceKey, 'ip_address' => '127.0.0.1', 'device_name' => 'Smoke Unreachable', 'management_network' => 'public'],
        $db,
        $actor,
        8
    );
    if (empty($unreachable['ok']) || (int) ($unreachable['neighbor_count'] ?? -1) !== 0) {
        mgs_neighbors_smoke_fail('unreachable_target_should_ok_with_zero_neighbors:' . json_encode($unreachable, JSON_UNESCAPED_SLASHES));
    }

    // --- Real end-to-end walk against a fake agent serving both LLDP and CDP ---
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $agentProcess = proc_open(
        [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . '_fake-snmp-agent-for-smoke.php', $community, '80', 'none', 'both'],
        $descriptorSpec,
        $agentPipes,
        dirname(__DIR__)
    );
    if (!is_resource($agentProcess)) {
        mgs_neighbors_smoke_fail('fake_agent_process_start_failed');
    }
    fclose($agentPipes[0]);
    stream_set_timeout($agentPipes[1], 5);
    $readyLine = trim((string) fgets($agentPipes[1]));
    $meta = stream_get_meta_data($agentPipes[1]);
    if ($readyLine === '' || ($meta['timed_out'] ?? false) === true || !str_contains($readyLine, ':')) {
        $stderr = stream_get_contents($agentPipes[2]);
        mgs_neighbors_smoke_fail('fake_agent_did_not_report_ready:' . $readyLine . ':' . $stderr);
    }
    [$agentHost, $agentPort] = explode(':', $readyLine, 2);
    $agentPort = (int) $agentPort;

    $db->prepare(
        'INSERT INTO device_learning_profiles (device_key, device_name, ip_address, monitoring_enabled, management_network, expected_reachability, reachability_state, status)
         VALUES (:device_key, "Smoke Neighbors Device", :ip, 1, "public", 1, "reachable", "learned")'
    )->execute(['device_key' => $deviceKey, 'ip' => $agentHost]);
    $db->prepare(
        'INSERT INTO device_access_overrides (device_key, snmp_read_community, snmp_port, updated_by_name)
         VALUES (:device_key, :community, :port, "smoke")'
    )->execute(['device_key' => $deviceKey, 'community' => $community, 'port' => $agentPort]);

    $device = ['device_key' => $deviceKey, 'ip_address' => $agentHost, 'device_name' => 'Smoke Neighbors Device', 'management_network' => 'public'];
    $result = app_mgs_discover_device_neighbors($device, $db, $actor, 16);
    if (empty($result['ok']) || (int) ($result['neighbor_count'] ?? -1) !== 2 || (int) ($result['by_protocol']['lldp'] ?? -1) !== 1 || (int) ($result['by_protocol']['cdp'] ?? -1) !== 1) {
        $stderr = stream_get_contents($agentPipes[2]);
        mgs_neighbors_smoke_fail('discovery_did_not_find_1_lldp_and_1_cdp:' . json_encode($result, JSON_UNESCAPED_SLASHES) . ':stderr=' . $stderr);
    }

    $rows = app_mgs_device_neighbors($deviceKey, $db, $actor);
    if (count($rows) !== 2) {
        mgs_neighbors_smoke_fail('expected_2_stored_neighbor_rows:' . count($rows));
    }
    $byProtocol = [];
    foreach ($rows as $row) {
        $byProtocol[(string) $row['protocol']] = $row;
    }
    if (!isset($byProtocol['lldp'], $byProtocol['cdp'])) {
        mgs_neighbors_smoke_fail('missing_lldp_or_cdp_row');
    }

    $lldpRow = $byProtocol['lldp'];
    if ((string) $lldpRow['remote_chassis_id'] !== 'AA:BB:CC:DD:EE:FF') {
        mgs_neighbors_smoke_fail('lldp_chassis_id_should_be_mac_formatted:' . $lldpRow['remote_chassis_id']);
    }
    if ((string) $lldpRow['remote_port_id'] !== 'GigabitEthernet0/1' || (string) $lldpRow['remote_sys_name'] !== 'core-switch-01' || (string) $lldpRow['remote_port_desc'] !== 'Uplink to core') {
        mgs_neighbors_smoke_fail('lldp_fields_wrong:' . json_encode($lldpRow, JSON_UNESCAPED_SLASHES));
    }
    // lldpRemLocalPortNum is the second-to-last arc of "100.1.1" -> 1.
    if ((int) $lldpRow['local_if_index'] !== 1) {
        mgs_neighbors_smoke_fail('lldp_local_if_index_wrong:' . json_encode($lldpRow, JSON_UNESCAPED_SLASHES));
    }

    $cdpRow = $byProtocol['cdp'];
    if ((string) $cdpRow['remote_chassis_id'] !== 'core-switch-01.example.com' || (string) $cdpRow['remote_port_id'] !== 'GigabitEthernet1/0/24' || (string) $cdpRow['remote_platform'] !== 'cisco WS-C3850-24T') {
        mgs_neighbors_smoke_fail('cdp_fields_wrong:' . json_encode($cdpRow, JSON_UNESCAPED_SLASHES));
    }
    // cdpCacheIfIndex is the first arc of "1.1" -> 1 (a real ifIndex, unlike LLDP's port num).
    if ((int) $cdpRow['local_if_index'] !== 1) {
        mgs_neighbors_smoke_fail('cdp_local_if_index_wrong:' . json_encode($cdpRow, JSON_UNESCAPED_SLASHES));
    }

    // --- Re-run must upsert in place, not duplicate rows ---
    $secondResult = app_mgs_discover_device_neighbors($device, $db, $actor, 16);
    if (empty($secondResult['ok']) || (int) ($secondResult['neighbor_count'] ?? -1) !== 2) {
        mgs_neighbors_smoke_fail('second_discovery_wrong_count:' . json_encode($secondResult, JSON_UNESCAPED_SLASHES));
    }
    $rowsAfterSecond = app_mgs_device_neighbors($deviceKey, $db, $actor);
    if (count($rowsAfterSecond) !== 2) {
        mgs_neighbors_smoke_fail('second_discovery_should_upsert_not_duplicate:' . count($rowsAfterSecond));
    }

    proc_terminate($agentProcess);
    foreach ($agentPipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($agentProcess);
    $agentProcess = null;
    $agentPipes = null;

    $cleanup();
    echo json_encode(['status' => 'passed', 'device_key' => $deviceKey, 'neighbor_count' => 2], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
