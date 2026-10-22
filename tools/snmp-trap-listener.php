<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$options = getopt('', [
    'port::',
    'bind::',
    'max-packets::',
    'max-runtime-seconds::',
    'help',
]);

if (isset($options['help'])) {
    echo implode(PHP_EOL, [
        'Usage:',
        '  php tools/snmp-trap-listener.php [--port=1162] [--bind=0.0.0.0] [--max-packets=0] [--max-runtime-seconds=0]',
        '',
        'Long-running UDP listener: decodes incoming SNMPv1/v2c traps and stores them via',
        'app_mgs_record_snmp_trap(). Not a periodic cron worker -- this must stay bound to its',
        'port continuously or it will miss traps, so it is meant to be launched once as a',
        'persistent background process (see deploy/windows/start-superoptic-stack.ps1), not',
        'invoked repeatedly like tools/poll-device-interface-traffic.php.',
        '--port        UDP port to bind. Default: 1162 (the standard trap port 162 needs an',
        '              elevated/administrator process on Windows and Linux; point real device',
        '              trap destinations at this port directly, or NAT 162 -> this port).',
        '--bind        Bind address. Default: 0.0.0.0 (all interfaces).',
        '--max-packets Stop after this many packets. Default: 0 (unlimited).',
        '--max-runtime-seconds Stop after this many seconds. Default: 0 (unlimited).',
        '',
    ]) . PHP_EOL;
    exit(0);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL/PDO connection is not ready.\n");
    exit(2);
}

$port = isset($options['port']) ? max(1, (int) $options['port']) : (int) app_config('mgs_snmp_trap_listen_port', '1162');
$bind = isset($options['bind']) ? (string) $options['bind'] : '0.0.0.0';
$maxPackets = isset($options['max-packets']) ? max(0, (int) $options['max-packets']) : 0;
$maxRuntimeSeconds = isset($options['max-runtime-seconds']) ? max(0, (int) $options['max-runtime-seconds']) : 0;

$socket = @stream_socket_server('udp://' . $bind . ':' . $port, $errno, $errstr, STREAM_SERVER_BIND);
if (!is_resource($socket)) {
    fwrite(STDERR, 'bind_failed:' . $errstr . PHP_EOL);
    exit(2);
}
$boundName = stream_socket_get_name($socket, false);
echo $boundName . PHP_EOL;
if (function_exists('fflush')) {
    fflush(STDOUT);
}

$actor = ['id' => 0, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'full_name' => 'SNMP Trap Listener (daemon)'];
$startedAt = time();
$received = 0;
$stored = 0;
$failed = 0;

while (true) {
    if ($maxPackets > 0 && $received >= $maxPackets) {
        break;
    }
    if ($maxRuntimeSeconds > 0 && (time() - $startedAt) >= $maxRuntimeSeconds) {
        break;
    }

    stream_set_timeout($socket, 2);
    $peer = '';
    $packet = stream_socket_recvfrom($socket, 4096, 0, $peer);
    if ($packet === false || $packet === '') {
        continue; // per-iteration timeout; loop back to re-check max-packets/max-runtime
    }

    $received++;
    $sourceIp = $peer;
    $sourcePort = 0;
    if (str_contains($peer, ':')) {
        [$sourceIp, $sourcePortText] = explode(':', $peer, 2);
        $sourcePort = (int) $sourcePortText;
    }

    $decoded = app_mgs_snmp_decode_trap_packet($packet);
    if (empty($decoded['ok'])) {
        $failed++;
        echo json_encode(['event' => 'trap_decode_failed', 'source' => $peer, 'error' => (string) ($decoded['error'] ?? 'unknown')], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        continue;
    }

    $stored++;
    $result = app_mgs_record_snmp_trap($db, $decoded, $sourceIp, $sourcePort, $actor);
    echo json_encode([
        'event' => 'trap_received',
        'source' => $peer,
        'version' => $decoded['version'],
        'trap_oid' => $decoded['trap_oid'] ?? null,
        'generic_trap' => $decoded['generic_trap'] ?? null,
        'device_key' => $result['device_key'] ?? null,
        'community_valid' => $result['community_valid'] ?? false,
        'alarm_bridged' => $result['alarm_bridged'] ?? false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

fclose($socket);
fwrite(STDERR, json_encode(['event' => 'listener_stopped', 'received' => $received, 'stored' => $stored, 'failed' => $failed]) . PHP_EOL);
exit(0);
