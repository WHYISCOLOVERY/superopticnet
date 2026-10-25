<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

class MgsDeviceInterfaceInventorySmokeFailure extends RuntimeException
{
}

function mgs_if_inventory_smoke_fail(string $message): never
{
    throw new MgsDeviceInterfaceInventorySmokeFailure($message);
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
$hasInterfaceTable = (int) $db->query(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "device_interfaces"'
)->fetchColumn() > 0;
if (!$hasInterfaceTable) {
    fwrite(STDERR, 'device_interfaces_not_migrated:run tools/run-portal-migrations.php' . PHP_EOL);
    exit(2);
}

$actor = ['id' => 900601, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'full_name' => 'Smoke Admin'];
$deviceKey = 'smoke-if-inventory-' . bin2hex(random_bytes(4));
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
    $db->prepare('DELETE FROM device_interfaces WHERE device_key = :device_key')->execute(['device_key' => $deviceKey]);
    $db->prepare('DELETE FROM device_access_overrides WHERE device_key = :device_key')->execute(['device_key' => $deviceKey]);
};

try {
    // --- Defensive: malformed/truncated packets must fail closed, never throw ---
    foreach (['', "\x30", "\x30\x05\x02\x01\x00", random_bytes(12)] as $garbage) {
        $decoded = app_mgs_snmp_decode_response($garbage);
        if (!is_array($decoded) || !array_key_exists('ok', $decoded)) {
            mgs_if_inventory_smoke_fail('decoder_did_not_return_shape_for_garbage_input');
        }
    }

    // --- Unreachable target: must fail cleanly, not hang or throw ---
    $unreachable = app_mgs_discover_device_interfaces(
        ['device_key' => $deviceKey, 'ip_address' => '127.0.0.1', 'device_name' => 'Smoke Unreachable', 'management_network' => 'public'],
        $db,
        $actor,
        4
    );
    if (!empty($unreachable['ok'])) {
        mgs_if_inventory_smoke_fail('unreachable_target_unexpectedly_ok:' . json_encode($unreachable, JSON_UNESCAPED_SLASHES));
    }

    // --- Real end-to-end walk against a fake UDP SNMP agent (2 interfaces) ---
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $agentProcess = proc_open(
        [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . '_fake-snmp-agent-for-smoke.php', $community, '60'],
        $descriptorSpec,
        $agentPipes,
        dirname(__DIR__)
    );
    if (!is_resource($agentProcess)) {
        mgs_if_inventory_smoke_fail('fake_agent_process_start_failed');
    }
    fclose($agentPipes[0]);
    stream_set_timeout($agentPipes[1], 5);
    $readyLine = trim((string) fgets($agentPipes[1]));
    $meta = stream_get_meta_data($agentPipes[1]);
    if ($readyLine === '' || ($meta['timed_out'] ?? false) === true || !str_contains($readyLine, ':')) {
        $stderr = stream_get_contents($agentPipes[2]);
        mgs_if_inventory_smoke_fail('fake_agent_did_not_report_ready:' . $readyLine . ':' . $stderr);
    }
    [$agentHost, $agentPort] = explode(':', $readyLine, 2);
    $agentPort = (int) $agentPort;

    $db->prepare(
        'INSERT INTO device_access_overrides (device_key, snmp_read_community, snmp_port, updated_by_name)
         VALUES (:device_key, :community, :port, :updated_by_name)'
    )->execute(['device_key' => $deviceKey, 'community' => $community, 'port' => $agentPort, 'updated_by_name' => 'smoke']);

    $device = ['device_key' => $deviceKey, 'ip_address' => $agentHost, 'device_name' => 'Smoke Fake Switch', 'management_network' => 'public'];
    $result = app_mgs_discover_device_interfaces($device, $db, $actor, 16);
    if (empty($result['ok']) || (int) ($result['interface_count'] ?? -1) !== 2) {
        $stderr = stream_get_contents($agentPipes[2]);
        mgs_if_inventory_smoke_fail('discovery_did_not_find_2_interfaces:' . json_encode($result, JSON_UNESCAPED_SLASHES) . ':stderr=' . $stderr);
    }

    $rows = app_mgs_device_interfaces($deviceKey, $db, $actor);
    if (count($rows) !== 2) {
        mgs_if_inventory_smoke_fail('expected_2_stored_interfaces:' . count($rows));
    }
    $byIndex = [];
    foreach ($rows as $row) {
        $byIndex[(int) $row['if_index']] = $row;
    }
    if (!isset($byIndex[1], $byIndex[2])) {
        mgs_if_inventory_smoke_fail('missing_expected_if_index_1_or_2');
    }
    $checks = [
        [1, 'if_descr', 'Gi0/1'],
        [2, 'if_descr', 'Gi0/2'],
        [1, 'if_type', '6'],
        [1, 'if_speed_bps', '1000000000'],
        [2, 'if_speed_bps', '100000000'],
        [1, 'if_phys_address', '00:11:22:33:44:55'],
        [2, 'if_phys_address', '00:11:22:33:44:66'],
        [1, 'if_admin_status', 'up'],
        [1, 'if_oper_status', 'up'],
        [2, 'if_oper_status', 'down'],
    ];
    foreach ($checks as [$index, $field, $expected]) {
        $actual = (string) $byIndex[$index][$field];
        if ($actual !== $expected) {
            mgs_if_inventory_smoke_fail("field_mismatch:if{$index}.{$field}:expected={$expected}:actual={$actual}");
        }
    }

    // --- Re-run must upsert in place, not duplicate rows (agent is dead now,
    // so re-running against the same closed port must fail cleanly and leave
    // the previously-stored rows untouched -- upsert-on-next-success, not
    // delete-on-failure). ---
    proc_terminate($agentProcess);
    foreach ($agentPipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($agentProcess);
    $agentProcess = null;
    $agentPipes = null;

    $afterAgentDead = app_mgs_discover_device_interfaces($device, $db, $actor, 4);
    if (!empty($afterAgentDead['ok'])) {
        mgs_if_inventory_smoke_fail('discovery_unexpectedly_ok_after_agent_stopped');
    }
    $rowsAfter = app_mgs_device_interfaces($deviceKey, $db, $actor);
    if (count($rowsAfter) !== 2) {
        mgs_if_inventory_smoke_fail('stored_rows_should_survive_a_later_failed_discovery_attempt:' . count($rowsAfter));
    }

    $cleanup();
    echo json_encode(['status' => 'passed', 'device_key' => $deviceKey, 'interface_count' => 2], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
