<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

class MgsDeviceInterfaceTrafficSmokeFailure extends RuntimeException
{
}

function mgs_traffic_smoke_fail(string $message): never
{
    throw new MgsDeviceInterfaceTrafficSmokeFailure($message);
}

function mgs_traffic_smoke_launch_agent(string $community, int $maxRequests, string $octetsMode): array
{
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . '_fake-snmp-agent-for-smoke.php', $community, (string) $maxRequests, $octetsMode],
        $descriptorSpec,
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        mgs_traffic_smoke_fail('fake_agent_process_start_failed:' . $octetsMode);
    }
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 5);
    $readyLine = trim((string) fgets($pipes[1]));
    $meta = stream_get_meta_data($pipes[1]);
    if ($readyLine === '' || ($meta['timed_out'] ?? false) === true || !str_contains($readyLine, ':')) {
        $stderr = stream_get_contents($pipes[2]);
        mgs_traffic_smoke_fail('fake_agent_did_not_report_ready:' . $octetsMode . ':' . $readyLine . ':' . $stderr);
    }
    [$host, $port] = explode(':', $readyLine, 2);

    return ['process' => $process, 'pipes' => $pipes, 'host' => $host, 'port' => (int) $port];
}

function mgs_traffic_smoke_stop_agent(?array $agent): void
{
    if ($agent === null || !is_resource($agent['process'])) {
        return;
    }
    proc_terminate($agent['process']);
    foreach ($agent['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($agent['process']);
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
foreach (['device_interface_traffic_samples', 'device_interface_traffic_hourly', 'device_interface_traffic_daily'] as $requiredTable) {
    $exists = (int) $db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote($requiredTable)
    )->fetchColumn() > 0;
    if (!$exists) {
        fwrite(STDERR, $requiredTable . '_not_migrated:run tools/run-portal-migrations.php' . PHP_EOL);
        exit(2);
    }
}

$actor = ['id' => 900701, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'full_name' => 'Smoke Admin'];
$deviceKey = 'smoke-if-traffic-' . bin2hex(random_bytes(4));
$community = 'smoke' . bin2hex(random_bytes(3));
$agent = null;

$cleanup = function () use ($db, &$deviceKey, &$agent): void {
    mgs_traffic_smoke_stop_agent($agent);
    $db->prepare('DELETE FROM device_interface_traffic_samples WHERE device_key = :k')->execute(['k' => $deviceKey]);
    $db->prepare('DELETE FROM device_interface_traffic_hourly WHERE device_key = :k')->execute(['k' => $deviceKey]);
    $db->prepare('DELETE FROM device_interface_traffic_daily WHERE device_key = :k')->execute(['k' => $deviceKey]);
    $db->prepare('DELETE FROM device_interfaces WHERE device_key = :k')->execute(['k' => $deviceKey]);
    $db->prepare('DELETE FROM device_access_overrides WHERE device_key = :k')->execute(['k' => $deviceKey]);
    $db->prepare('DELETE FROM device_learning_profiles WHERE device_key = :k')->execute(['k' => $deviceKey]);
};

try {
    // --- Part 1: bps math, no network/DB involved ---
    if (app_mgs_interface_traffic_bps(1060000, 1000000, 64, 60) !== 8000) {
        mgs_traffic_smoke_fail('bps_normal_case_wrong');
    }
    $wrappedDelta = (4294967296 - 4294967200) + 100;
    if (app_mgs_interface_traffic_bps(100, 4294967200, 32, 10) !== (int) round(($wrappedDelta * 8) / 10)) {
        mgs_traffic_smoke_fail('bps_32bit_wraparound_wrong');
    }
    if (app_mgs_interface_traffic_bps(100, 200, 64, 10) !== null) {
        mgs_traffic_smoke_fail('bps_64bit_decrease_should_be_null_not_treated_as_wraparound');
    }
    if (app_mgs_interface_traffic_bps(200, 100, 32, 1000) !== null) {
        mgs_traffic_smoke_fail('bps_32bit_long_gap_should_refuse_to_guess');
    }
    if (app_mgs_interface_traffic_bps(200, 100, 64, 0) !== null) {
        mgs_traffic_smoke_fail('bps_zero_elapsed_should_be_null');
    }

    // --- Part 2: real end-to-end poll against an HC (64-bit) fake agent ---
    $agent = mgs_traffic_smoke_launch_agent($community, 30, 'hc');

    $octets = app_mgs_read_interface_octets($agent['host'], $community, $agent['port'], 1, 700);
    if (empty($octets['ok']) || (int) $octets['counter_bits'] !== 64 || (int) $octets['in_octets'] !== 5000000000 || (int) $octets['out_octets'] !== 3000000000) {
        mgs_traffic_smoke_fail('hc_octet_read_wrong:' . json_encode($octets, JSON_UNESCAPED_SLASHES));
    }

    $db->prepare(
        'INSERT INTO device_learning_profiles (device_key, device_name, ip_address, monitoring_enabled, management_network, expected_reachability, reachability_state, status)
         VALUES (:device_key, :device_name, :ip, 1, "public", 1, "reachable", "learned")'
    )->execute(['device_key' => $deviceKey, 'device_name' => 'Smoke Traffic Device', 'ip' => $agent['host']]);
    $db->prepare(
        'INSERT INTO device_access_overrides (device_key, snmp_read_community, snmp_port, updated_by_name)
         VALUES (:device_key, :community, :port, :name)'
    )->execute(['device_key' => $deviceKey, 'community' => $community, 'port' => $agent['port'], 'name' => 'smoke']);
    $db->prepare(
        'INSERT INTO device_interfaces (device_key, if_index, if_descr, if_admin_status, if_oper_status)
         VALUES (:device_key, 1, "Gi0/1", "up", "up")'
    )->execute(['device_key' => $deviceKey]);

    $pastSampleAt = date('Y-m-d H:i:s', time() - 60);
    $db->prepare(
        'INSERT INTO device_interface_traffic_samples (device_key, if_index, sampled_at, in_octets, out_octets, counter_bits)
         VALUES (:device_key, 1, :sampled_at, 4940000000, 2970000000, 64)'
    )->execute(['device_key' => $deviceKey, 'sampled_at' => $pastSampleAt]);

    $pollResult = app_mgs_poll_device_interface_traffic($db, $actor, true, $deviceKey);
    if (empty($pollResult['ok']) || (int) $pollResult['polled'] !== 1 || (int) $pollResult['stored'] !== 1 || (int) $pollResult['failed'] !== 0) {
        mgs_traffic_smoke_fail('poll_hc_wrong_counts:' . json_encode($pollResult, JSON_UNESCAPED_SLASHES));
    }
    $detail = $pollResult['details'][0] ?? [];
    if ((int) ($detail['in_bps'] ?? -1) !== 8000000 || (int) ($detail['out_bps'] ?? -1) !== 4000000) {
        mgs_traffic_smoke_fail('poll_hc_wrong_computed_bps:' . json_encode($detail, JSON_UNESCAPED_SLASHES));
    }

    $latestRow = $db->prepare(
        'SELECT * FROM device_interface_traffic_samples WHERE device_key = :k AND if_index = 1 ORDER BY sampled_at DESC LIMIT 1'
    );
    $latestRow->execute(['k' => $deviceKey]);
    $stored = $latestRow->fetch(PDO::FETCH_ASSOC);
    if (!is_array($stored) || (int) $stored['in_octets'] !== 5000000000 || (int) $stored['in_bps'] !== 8000000 || (int) $stored['counter_bits'] !== 64) {
        mgs_traffic_smoke_fail('stored_sample_row_wrong:' . json_encode($stored, JSON_UNESCAPED_SLASHES));
    }

    mgs_traffic_smoke_stop_agent($agent);
    $agent = null;

    // --- Part 3: HC-unavailable device falls back to the 32-bit pair, and a
    // counter-width change against an existing previous sample must refuse
    // to compute a rate (mismatched widths are not comparable). ---
    $agent = mgs_traffic_smoke_launch_agent($community, 30, '32bit');
    $db->prepare('UPDATE device_access_overrides SET snmp_port = :port WHERE device_key = :k')->execute(['port' => $agent['port'], 'k' => $deviceKey]);

    $fallbackOctets = app_mgs_read_interface_octets($agent['host'], $community, $agent['port'], 1, 700);
    if (empty($fallbackOctets['ok']) || (int) $fallbackOctets['counter_bits'] !== 32 || (int) $fallbackOctets['in_octets'] !== 200000000 || (int) $fallbackOctets['out_octets'] !== 100000000) {
        mgs_traffic_smoke_fail('32bit_fallback_octet_read_wrong:' . json_encode($fallbackOctets, JSON_UNESCAPED_SLASHES));
    }

    $fallbackPoll = app_mgs_poll_device_interface_traffic($db, $actor, true, $deviceKey);
    if (empty($fallbackPoll['ok']) || (int) $fallbackPoll['stored'] !== 1) {
        mgs_traffic_smoke_fail('poll_32bit_fallback_wrong_counts:' . json_encode($fallbackPoll, JSON_UNESCAPED_SLASHES));
    }
    $fallbackDetail = $fallbackPoll['details'][0] ?? [];
    if ($fallbackDetail['in_bps'] !== null || $fallbackDetail['out_bps'] !== null || (int) ($fallbackDetail['counter_bits'] ?? 0) !== 32) {
        mgs_traffic_smoke_fail('counter_width_change_should_yield_null_bps_not_a_bogus_rate:' . json_encode($fallbackDetail, JSON_UNESCAPED_SLASHES));
    }

    mgs_traffic_smoke_stop_agent($agent);
    $agent = null;

    // --- Part 4: hourly/daily rollup, isolated on if_index=2 with an
    // explicit hour_start so it never straddles the current wall-clock hour
    // boundary. ---
    $hourStart = date('Y-m-d H:00:00');
    $hourStartTs = strtotime($hourStart);
    $db->prepare(
        'INSERT INTO device_interface_traffic_samples (device_key, if_index, sampled_at, in_octets, out_octets, in_bps, out_bps, counter_bits)
         VALUES (:k, 2, :t1, 0, 0, 1000, 2000, 64), (:k, 2, :t2, 0, 0, 3000, 4000, 64)'
    )->execute(['k' => $deviceKey, 't1' => date('Y-m-d H:i:s', $hourStartTs + 300), 't2' => date('Y-m-d H:i:s', $hourStartTs + 1800)]);

    $hourlyResult = app_mgs_rollup_device_interface_traffic_hourly($db, 25);
    if (empty($hourlyResult['ok'])) {
        mgs_traffic_smoke_fail('hourly_rollup_failed');
    }
    $hourlyRow = $db->prepare('SELECT * FROM device_interface_traffic_hourly WHERE device_key = :k AND if_index = 2 AND hour_start = :h');
    $hourlyRow->execute(['k' => $deviceKey, 'h' => $hourStart]);
    $hourly = $hourlyRow->fetch(PDO::FETCH_ASSOC);
    if (!is_array($hourly) || (int) $hourly['avg_in_bps'] !== 2000 || (int) $hourly['max_in_bps'] !== 3000 || (int) $hourly['avg_out_bps'] !== 3000 || (int) $hourly['max_out_bps'] !== 4000 || (int) $hourly['sample_count'] !== 2) {
        mgs_traffic_smoke_fail('hourly_rollup_row_wrong:' . json_encode($hourly, JSON_UNESCAPED_SLASHES));
    }

    $dailyResult = app_mgs_rollup_device_interface_traffic_daily($db, 3);
    if (empty($dailyResult['ok'])) {
        mgs_traffic_smoke_fail('daily_rollup_failed');
    }
    $dailyRow = $db->prepare('SELECT * FROM device_interface_traffic_daily WHERE device_key = :k AND if_index = 2 AND day_start = :d');
    $dailyRow->execute(['k' => $deviceKey, 'd' => date('Y-m-d', $hourStartTs)]);
    $daily = $dailyRow->fetch(PDO::FETCH_ASSOC);
    if (!is_array($daily) || (int) $daily['avg_in_bps'] !== 2000 || (int) $daily['max_in_bps'] !== 3000 || (int) $daily['sample_count'] !== 2) {
        mgs_traffic_smoke_fail('daily_rollup_row_wrong:' . json_encode($daily, JSON_UNESCAPED_SLASHES));
    }

    // --- Part 5: pruning, isolated on if_index=3 ---
    $db->prepare(
        'INSERT INTO device_interface_traffic_samples (device_key, if_index, sampled_at, in_octets, out_octets, counter_bits)
         VALUES (:k, 3, :old_at, 1, 1, 64), (:k, 3, :recent_at, 2, 2, 64)'
    )->execute(['k' => $deviceKey, 'old_at' => date('Y-m-d H:i:s', time() - (40 * 86400)), 'recent_at' => date('Y-m-d H:i:s')]);
    $pruneResult = app_mgs_prune_device_interface_traffic_samples($db, 30);
    if (empty($pruneResult['ok']) || (int) $pruneResult['deleted'] < 1) {
        mgs_traffic_smoke_fail('prune_did_not_delete_old_sample:' . json_encode($pruneResult, JSON_UNESCAPED_SLASHES));
    }
    $remaining = $db->prepare('SELECT COUNT(*) FROM device_interface_traffic_samples WHERE device_key = :k AND if_index = 3');
    $remaining->execute(['k' => $deviceKey]);
    if ((int) $remaining->fetchColumn() !== 1) {
        mgs_traffic_smoke_fail('prune_should_keep_the_recent_sample');
    }

    $cleanup();
    echo json_encode(['status' => 'passed', 'device_key' => $deviceKey], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
