<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$defaults = app_mgs_snmp_defaults();
$hostname = trim((string) ($_SERVER['argv'][1] ?? 'cisco-switch'));
$readCommunity = trim((string) ($_SERVER['argv'][2] ?? $defaults['read_community']));
$writeCommunity = trim((string) ($_SERVER['argv'][3] ?? $defaults['write_community']));
$trapServer = trim((string) ($_SERVER['argv'][4] ?? $defaults['trap_server']));
$trapPort = (int) ($_SERVER['argv'][5] ?? $defaults['trap_port']);
$contact = trim((string) ($_SERVER['argv'][6] ?? $defaults['contact']));
$location = trim((string) ($_SERVER['argv'][7] ?? $defaults['location']));

$lines = [
    '! SuperOptic MGS Cisco SNMP trap template',
    '! target: ' . ($hostname !== '' ? $hostname : 'cisco-switch'),
    'configure terminal',
    'snmp-server community ' . $readCommunity . ' RO',
    'snmp-server community ' . $writeCommunity . ' RW',
    'snmp-server contact ' . ($contact !== '' ? $contact : 'Contact'),
    'snmp-server location ' . ($location !== '' ? $location : 'Location'),
    'snmp-server enable traps',
    'snmp-server host ' . $trapServer . ' version 2c ' . $readCommunity . ' udp-port ' . $trapPort,
    'end',
    'write memory',
];

echo implode(PHP_EOL, $lines) . PHP_EOL;
