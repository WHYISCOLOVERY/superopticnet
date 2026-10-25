<?php
declare(strict_types=1);

/**
 * Not a registered regression-suite entry (leading underscore, no
 * `_smoke.php` suffix). Standalone process launched via proc_open() by
 * tools/mgs-device-interface-inventory-smoke.php,
 * tools/mgs-device-interface-traffic-smoke.php and
 * tools/mgs-device-neighbors-smoke.php to act as a real UDP SNMP agent for a
 * fake 2-interface switch (optionally with one LLDP and/or one CDP neighbor
 * entry), so the actual app_mgs_discover_device_interfaces()/
 * app_mgs_poll_device_interface_traffic()/app_mgs_discover_device_neighbors()
 * walks can be exercised end-to-end over a real socket instead of only
 * unit-testing the BER codec in isolation.
 *
 * GETNEXT (PDU tag 0xA1) is served as "next OID strictly greater than
 * requested" (real ifTable walk semantics). GET (0xA0) is served as an
 * exact match only, or no response at all if the OID isn't in the fake
 * table -- this is what lets the traffic smoke test prove the HC-then-32bit
 * fallback in app_mgs_read_interface_octets() by starting the agent with
 * --octets=32bit (HC OIDs simply aren't in the table, so those GETs time out
 * client-side exactly like a device that doesn't support ifXTable).
 */

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$community = (string) ($argv[1] ?? 'public');
$maxRequests = (int) ($argv[2] ?? 40);
$octetsMode = (string) ($argv[3] ?? 'none'); // none|hc|32bit
$neighborsMode = (string) ($argv[4] ?? 'none'); // none|lldp|cdp|both
$requiredVersion = isset($argv[5]) ? (int) $argv[5] : -1; // -1|0(v1)|1(v2c)

function fake_snmp_oid_compare(string $a, string $b): int
{
    $partsA = array_map('intval', explode('.', $a));
    $partsB = array_map('intval', explode('.', $b));
    $count = max(count($partsA), count($partsB));
    for ($i = 0; $i < $count; $i++) {
        $valueA = $partsA[$i] ?? -1;
        $valueB = $partsB[$i] ?? -1;
        if ($valueA !== $valueB) {
            return $valueA <=> $valueB;
        }
    }

    return 0;
}

$columns = app_mgs_if_mib_columns();
$interfaces = [
    1 => ['if_descr' => 'Gi0/1', 'if_type' => 6, 'if_speed' => 1000000000, 'if_phys_address' => "\x00\x11\x22\x33\x44\x55", 'if_admin_status' => 1, 'if_oper_status' => 1],
    2 => ['if_descr' => 'Gi0/2', 'if_type' => 6, 'if_speed' => 100000000, 'if_phys_address' => "\x00\x11\x22\x33\x44\x66", 'if_admin_status' => 1, 'if_oper_status' => 2],
];

// Flat, sorted-by-construction OID => already-encoded value TLV map, exactly
// as a real ifTable would lay out column-major (all of ifDescr, then all of
// ifType, ...), which is also the real-world GETNEXT traversal order.
$table = [];
foreach ($columns as $field => $baseOid) {
    foreach ($interfaces as $index => $row) {
        $value = $row[$field];
        $valueTlv = is_int($value) ? app_mgs_snmp_ber_integer($value) : app_mgs_snmp_ber_string((string) $value);
        $table[$baseOid . '.' . $index] = $valueTlv;
    }
}

if ($octetsMode === 'hc') {
    $octetOids = app_mgs_interface_octet_oids();
    $table[$octetOids['hc_in'] . '.1'] = app_mgs_snmp_ber_integer(5000000000);
    $table[$octetOids['hc_out'] . '.1'] = app_mgs_snmp_ber_integer(3000000000);
} elseif ($octetsMode === '32bit') {
    $octetOids = app_mgs_interface_octet_oids();
    $table[$octetOids['in'] . '.1'] = app_mgs_snmp_ber_integer(200000000);
    $table[$octetOids['out'] . '.1'] = app_mgs_snmp_ber_integer(100000000);
}

if ($neighborsMode === 'lldp' || $neighborsMode === 'both') {
    // Real lldpRemEntry index is {timeMark, localPortNum, remoteIndex}; 100.1.1 here.
    $lldp = app_mgs_lldp_mib_columns();
    $table[$lldp['remote_chassis_id'] . '.100.1.1'] = app_mgs_snmp_ber_string("\xAA\xBB\xCC\xDD\xEE\xFF");
    $table[$lldp['remote_port_id'] . '.100.1.1'] = app_mgs_snmp_ber_string('GigabitEthernet0/1');
    $table[$lldp['remote_port_desc'] . '.100.1.1'] = app_mgs_snmp_ber_string('Uplink to core');
    $table[$lldp['remote_sys_name'] . '.100.1.1'] = app_mgs_snmp_ber_string('core-switch-01');
}
if ($neighborsMode === 'cdp' || $neighborsMode === 'both') {
    // Real cdpCacheEntry index is {ifIndex, deviceIndex}; 1.1 here.
    $cdp = app_mgs_cdp_mib_columns();
    $table[$cdp['remote_chassis_id'] . '.1.1'] = app_mgs_snmp_ber_string('core-switch-01.example.com');
    $table[$cdp['remote_port_id'] . '.1.1'] = app_mgs_snmp_ber_string('GigabitEthernet1/0/24');
    $table[$cdp['remote_platform'] . '.1.1'] = app_mgs_snmp_ber_string('cisco WS-C3850-24T');
}

$socket = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
if (!is_resource($socket)) {
    fwrite(STDERR, 'bind_failed:' . $errstr . PHP_EOL);
    exit(1);
}
$boundName = stream_socket_get_name($socket, false);
echo $boundName . PHP_EOL;
if (function_exists('fflush')) {
    fflush(STDOUT);
}

for ($i = 0; $i < $maxRequests; $i++) {
    stream_set_timeout($socket, 5);
    $peer = '';
    $request = stream_socket_recvfrom($socket, 2048, 0, $peer);
    $meta = stream_get_meta_data($socket);
    if ($request === false || $request === '' || ($meta['timed_out'] ?? false) === true) {
        break;
    }

    $decoded = app_mgs_snmp_decode_response($request);
    if (empty($decoded['ok']) || $decoded['varbinds'] === []) {
        continue;
    }
    $requestedOid = (string) $decoded['varbinds'][0]['oid'];
    $requestId = (int) ($decoded['request_id'] ?? 0);
    $requestVersion = (int) ($decoded['version'] ?? 0);
    if (in_array($requiredVersion, [0, 1], true) && $requestVersion !== $requiredVersion) {
        continue;
    }
    $requestCommunity = (string) ($decoded['community'] ?? $community);
    $isGet = (int) ($decoded['pdu_tag'] ?? 0) === 0xA0;

    if ($isGet) {
        // Exact match only; an OID this agent doesn't know (e.g. HC octet
        // counters when --octets=32bit) simply gets no reply, exercising the
        // client's snmp_timeout path.
        if (!array_key_exists($requestedOid, $table)) {
            continue;
        }
        $replyOid = $requestedOid;
    } else {
        $replyOid = null;
        foreach ($table as $oid => $valueTlv) {
            if (fake_snmp_oid_compare($oid, $requestedOid) > 0 && ($replyOid === null || fake_snmp_oid_compare($oid, $replyOid) < 0)) {
                $replyOid = $oid;
            }
        }
        if ($replyOid === null) {
            // End of the fake MIB -- a real agent would return endOfMibView or
            // an unrelated next subtree; simplest honest behaviour is to not
            // reply to this one request (the client-side walk already treats
            // a timeout as "end of this column" -- see
            // app_mgs_snmp_walk_column()'s empty($step['ok']) check). This
            // must NOT stop the agent process itself: a test that discovers
            // twice against the same long-lived agent (or discovers past the
            // globally-largest OID in the fake table, as CDP's platform
            // column can be) needs the agent to keep serving requests.
            continue;
        }
    }

    $varBind = app_mgs_snmp_ber_wrap(0x30, app_mgs_snmp_ber_oid($replyOid) . $table[$replyOid]);
    $varBindList = app_mgs_snmp_ber_wrap(0x30, $varBind);
    $pdu = app_mgs_snmp_ber_wrap(0xA2, app_mgs_snmp_ber_integer($requestId) . app_mgs_snmp_ber_integer(0) . app_mgs_snmp_ber_integer(0) . $varBindList);
    $packet = app_mgs_snmp_ber_wrap(0x30, app_mgs_snmp_ber_integer($requestVersion) . app_mgs_snmp_ber_string($requestCommunity) . $pdu);
    stream_socket_sendto($socket, $packet, 0, $peer);
}

fclose($socket);
exit(0);
