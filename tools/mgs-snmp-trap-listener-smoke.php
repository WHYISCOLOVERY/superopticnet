<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

class MgsSnmpTrapListenerSmokeFailure extends RuntimeException
{
}

function mgs_trap_smoke_fail(string $message): never
{
    throw new MgsSnmpTrapListenerSmokeFailure($message);
}

/** TimeTicks (tag 0x43) shares INTEGER's big-endian encoding, just a different tag. */
function mgs_trap_smoke_ber_timeticks(int $value): string
{
    if ($value === 0) {
        return app_mgs_snmp_ber_wrap(0x43, "\x00");
    }
    $bytes = '';
    $number = $value;
    while ($number > 0) {
        $bytes = chr($number & 0xFF) . $bytes;
        $number >>= 8;
    }
    if ((ord($bytes[0]) & 0x80) !== 0) {
        $bytes = "\x00" . $bytes;
    }

    return app_mgs_snmp_ber_wrap(0x43, $bytes);
}

/** Builds a raw SNMPv1 Trap-PDU packet using the module's own BER encoders. */
function mgs_trap_smoke_build_v1_trap(string $community, string $enterpriseOid, string $agentAddr, int $genericTrap, int $specificTrap, int $uptimeTicks): string
{
    $agentAddrTlv = app_mgs_snmp_ber_wrap(0x40, implode('', array_map('chr', array_map('intval', explode('.', $agentAddr)))));
    $varBind = app_mgs_snmp_ber_wrap(0x30, app_mgs_snmp_ber_oid('1.3.6.1.2.1.1.1.0') . app_mgs_snmp_ber_string('smoke test device'));
    $varBindList = app_mgs_snmp_ber_wrap(0x30, $varBind);
    $pduBody = app_mgs_snmp_ber_oid($enterpriseOid)
        . $agentAddrTlv
        . app_mgs_snmp_ber_integer($genericTrap)
        . app_mgs_snmp_ber_integer($specificTrap)
        . mgs_trap_smoke_ber_timeticks($uptimeTicks)
        . $varBindList;
    $pdu = app_mgs_snmp_ber_wrap(0xA4, $pduBody);

    return app_mgs_snmp_ber_wrap(0x30, app_mgs_snmp_ber_integer(0) . app_mgs_snmp_ber_string($community) . $pdu);
}

/** Builds a raw SNMPv2c trap packet (sysUpTime.0 + snmpTrapOID.0 varbinds). */
function mgs_trap_smoke_build_v2c_trap(string $community, int $uptimeTicks, string $trapOid): string
{
    $varBind1 = app_mgs_snmp_ber_wrap(0x30, app_mgs_snmp_ber_oid('1.3.6.1.2.1.1.3.0') . mgs_trap_smoke_ber_timeticks($uptimeTicks));
    $varBind2 = app_mgs_snmp_ber_wrap(0x30, app_mgs_snmp_ber_oid('1.3.6.1.6.3.1.1.4.1.0') . app_mgs_snmp_ber_oid($trapOid));
    $varBindList = app_mgs_snmp_ber_wrap(0x30, $varBind1 . $varBind2);
    $pdu = app_mgs_snmp_ber_wrap(0xA7, app_mgs_snmp_ber_integer(1) . app_mgs_snmp_ber_integer(0) . app_mgs_snmp_ber_integer(0) . $varBindList);

    return app_mgs_snmp_ber_wrap(0x30, app_mgs_snmp_ber_integer(1) . app_mgs_snmp_ber_string($community) . $pdu);
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
$hasTrapTable = (int) $db->query(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "snmp_trap_log"'
)->fetchColumn() > 0;
if (!$hasTrapTable) {
    fwrite(STDERR, 'snmp_trap_log_not_migrated:run tools/run-portal-migrations.php' . PHP_EOL);
    exit(2);
}

$actor = ['id' => 900801, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'full_name' => 'Smoke Admin'];
$deviceKey = 'smoke-trap-' . bin2hex(random_bytes(4));
$knownIp = '198.51.100.' . random_int(2, 250); // TEST-NET-2 (RFC5737), never a real routable device
$unknownIp = '198.51.100.' . random_int(2, 250);
while ($unknownIp === $knownIp) {
    $unknownIp = '198.51.100.' . random_int(2, 250);
}
$community = 'smoke-trap-' . bin2hex(random_bytes(3));
$insertedTrapIds = [];
$insertedAlarmDedupKeys = [];

$cleanup = function () use ($db, &$deviceKey, &$knownIp, &$unknownIp, &$insertedTrapIds, &$insertedAlarmDedupKeys): void {
    foreach ($insertedTrapIds as $id) {
        $db->prepare('DELETE FROM snmp_trap_log WHERE id = :id')->execute(['id' => $id]);
    }
    $db->prepare('DELETE FROM snmp_trap_log WHERE source_ip IN (:a, :b)')->execute(['a' => $knownIp, 'b' => $unknownIp]);
    foreach ($insertedAlarmDedupKeys as $dedupKey) {
        $db->prepare('DELETE FROM mgs_alarm_state_events WHERE dedup_key = :d')->execute(['d' => $dedupKey]);
        $db->prepare('DELETE FROM mgs_alarm_events WHERE dedup_key = :d')->execute(['d' => $dedupKey]);
        $db->prepare('DELETE FROM mgs_dedup_registry WHERE dedup_key = :d')->execute(['d' => $dedupKey]);
    }
    $db->prepare('DELETE FROM device_learning_profiles WHERE device_key = :k')->execute(['k' => $deviceKey]);
};

try {
    // --- Part 1: malformed/truncated packets fail closed, never throw ---
    foreach (['', "\x30", "\x30\x05\x02\x01\x00", random_bytes(20)] as $garbage) {
        $decoded = app_mgs_snmp_decode_trap_packet($garbage);
        if (!is_array($decoded) || !array_key_exists('ok', $decoded) || !empty($decoded['ok'])) {
            mgs_trap_smoke_fail('malformed_packet_should_fail_closed');
        }
    }

    // --- Part 2: v1 Trap-PDU decode round-trip ---
    $v1Packet = mgs_trap_smoke_build_v1_trap($community, '1.3.6.1.4.1.9999', '10.20.30.40', 2, 0, 123456);
    $v1Decoded = app_mgs_snmp_decode_trap_packet($v1Packet);
    if (empty($v1Decoded['ok']) || $v1Decoded['version'] !== 'v1' || (int) $v1Decoded['generic_trap'] !== 2 || (string) $v1Decoded['enterprise_oid'] !== '1.3.6.1.4.1.9999' || (int) $v1Decoded['uptime_ticks'] !== 123456 || (string) $v1Decoded['community'] !== $community) {
        mgs_trap_smoke_fail('v1_trap_decode_wrong:' . json_encode($v1Decoded, JSON_UNESCAPED_SLASHES));
    }

    // --- Part 3: v2c trap decode round-trip ---
    $v2cPacket = mgs_trap_smoke_build_v2c_trap($community, 987654, '1.3.6.1.4.1.9999.0.1');
    $v2cDecoded = app_mgs_snmp_decode_trap_packet($v2cPacket);
    if (empty($v2cDecoded['ok']) || $v2cDecoded['version'] !== 'v2c' || (string) $v2cDecoded['trap_oid'] !== '1.3.6.1.4.1.9999.0.1' || (int) $v2cDecoded['uptime_ticks'] !== 987654) {
        mgs_trap_smoke_fail('v2c_trap_decode_wrong:' . json_encode($v2cDecoded, JSON_UNESCAPED_SLASHES));
    }

    // --- Part 4: real UDP round-trip in this same process (bind, send-to-self, recv) ---
    $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if (!is_resource($socket)) {
        mgs_trap_smoke_fail('socket_bind_failed:' . $errstr);
    }
    $boundName = stream_socket_get_name($socket, false);
    $client = stream_socket_client('udp://' . $boundName, $errno, $errstr, 1.0);
    if (!is_resource($client)) {
        fclose($socket);
        mgs_trap_smoke_fail('socket_client_failed:' . $errstr);
    }
    fwrite($client, $v1Packet);
    fclose($client);
    stream_set_timeout($socket, 2);
    $peer = '';
    $received = stream_socket_recvfrom($socket, 4096, 0, $peer);
    fclose($socket);
    if ($received !== $v1Packet) {
        mgs_trap_smoke_fail('real_udp_roundtrip_bytes_mismatch');
    }
    $roundTripDecoded = app_mgs_snmp_decode_trap_packet($received);
    if (empty($roundTripDecoded['ok']) || (int) $roundTripDecoded['generic_trap'] !== 2) {
        mgs_trap_smoke_fail('real_udp_roundtrip_decode_wrong');
    }

    // --- Part 5: storage + device_key resolution (known vs unknown source) ---
    $db->prepare(
        'INSERT INTO device_learning_profiles (device_key, device_name, ip_address, monitoring_enabled, management_network, expected_reachability, reachability_state, status)
         VALUES (:device_key, "Smoke Trap Device", :ip, 1, "public", 1, "reachable", "learned")'
    )->execute(['device_key' => $deviceKey, 'ip' => $knownIp]);

    $knownResult = app_mgs_record_snmp_trap($db, $v1Decoded, $knownIp, 162, $actor);
    if (empty($knownResult['ok']) || $knownResult['device_key'] !== $deviceKey) {
        mgs_trap_smoke_fail('known_source_device_key_not_resolved:' . json_encode($knownResult, JSON_UNESCAPED_SLASHES));
    }
    $insertedTrapIds[] = (int) $knownResult['id'];
    if (empty($knownResult['alarm_bridged'])) {
        mgs_trap_smoke_fail('linkdown_trap_should_bridge_to_alarm');
    }
    // Look up by the columns app_mgs_record_alarm_event() actually stores
    // (alarm_type/object_id), rather than reimplementing its internal
    // dedup_key hash formula here -- that would make this test brittle to
    // an implementation detail instead of observable behaviour.
    $alarmRow = $db->prepare('SELECT id, dedup_key FROM mgs_alarm_events WHERE alarm_type = "SNMP_TRAP_LINKDOWN" AND object_id = :object_id');
    $alarmRow->execute(['object_id' => $deviceKey]);
    $bridgedAlarm = $alarmRow->fetch(PDO::FETCH_ASSOC);
    if (!is_array($bridgedAlarm)) {
        mgs_trap_smoke_fail('bridged_alarm_row_not_found');
    }
    $insertedAlarmDedupKeys[] = (string) $bridgedAlarm['dedup_key'];

    $unknownResult = app_mgs_record_snmp_trap($db, $v1Decoded, $unknownIp, 162, $actor);
    if (empty($unknownResult['ok']) || $unknownResult['device_key'] !== null) {
        mgs_trap_smoke_fail('unknown_source_should_have_null_device_key:' . json_encode($unknownResult, JSON_UNESCAPED_SLASHES));
    }
    $insertedTrapIds[] = (int) $unknownResult['id'];
    // Still a linkDown trap (reusing $v1Decoded), so it still bridges to an
    // alarm -- just with object_id falling back to the raw IP since no
    // device_key resolved. Track it for cleanup the same way as the known-
    // source alarm above.
    if (!empty($unknownResult['alarm_bridged'])) {
        $unknownAlarmRow = $db->prepare('SELECT dedup_key FROM mgs_alarm_events WHERE alarm_type = "SNMP_TRAP_LINKDOWN" AND object_id = :object_id');
        $unknownAlarmRow->execute(['object_id' => $unknownIp]);
        $unknownDedupKey = $unknownAlarmRow->fetchColumn();
        if (is_string($unknownDedupKey) && $unknownDedupKey !== '') {
            $insertedAlarmDedupKeys[] = $unknownDedupKey;
        }
    }

    // --- Part 6: linkUp must NOT auto-bridge to an alarm (recovery signal, not a problem) ---
    $linkUpPacket = mgs_trap_smoke_build_v1_trap($community, '1.3.6.1.4.1.9999', '10.20.30.40', 3, 0, 123999);
    $linkUpDecoded = app_mgs_snmp_decode_trap_packet($linkUpPacket);
    $linkUpResult = app_mgs_record_snmp_trap($db, $linkUpDecoded, $knownIp, 162, $actor);
    if (empty($linkUpResult['ok']) || !empty($linkUpResult['alarm_bridged'])) {
        mgs_trap_smoke_fail('linkup_trap_should_not_bridge_to_alarm:' . json_encode($linkUpResult, JSON_UNESCAPED_SLASHES));
    }
    $insertedTrapIds[] = (int) $linkUpResult['id'];

    // --- Part 7: community mismatch is flagged, not silently dropped ---
    // generic_trap=3 (linkUp), not 2 (linkDown): this part only checks
    // community_valid, and linkUp deliberately never bridges to an alarm
    // (Part 6 already covers that), so there is no second alarm row to track.
    $wrongCommunityPacket = mgs_trap_smoke_build_v1_trap('totally-wrong-community', '1.3.6.1.4.1.9999', '10.20.30.40', 3, 0, 1);
    $wrongCommunityDecoded = app_mgs_snmp_decode_trap_packet($wrongCommunityPacket);
    $wrongCommunityResult = app_mgs_record_snmp_trap($db, $wrongCommunityDecoded, $unknownIp, 162, $actor);
    if (empty($wrongCommunityResult['ok']) || !empty($wrongCommunityResult['community_valid'])) {
        mgs_trap_smoke_fail('wrong_community_should_still_be_stored_but_flagged_invalid');
    }
    $insertedTrapIds[] = (int) $wrongCommunityResult['id'];
    $wrongCommunityRow = $db->prepare('SELECT community_valid FROM snmp_trap_log WHERE id = :id');
    $wrongCommunityRow->execute(['id' => $wrongCommunityResult['id']]);
    if ((int) $wrongCommunityRow->fetchColumn() !== 0) {
        mgs_trap_smoke_fail('stored_community_valid_flag_wrong');
    }

    $cleanup();
    echo json_encode(['status' => 'passed', 'device_key' => $deviceKey], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
