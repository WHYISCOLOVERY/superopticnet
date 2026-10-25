<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$community = 'smoke' . bin2hex(random_bytes(4));
$process = null;
$pipes = [];
try {
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/_fake-snmp-agent-for-smoke.php', $community, '8', 'none', 'none', '1'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('fake_agent_start_failed');
    }
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 5);
    $ready = trim((string) fgets($pipes[1]));
    if (!str_contains($ready, ':')) {
        throw new RuntimeException('fake_agent_not_ready');
    }
    [$host, $port] = explode(':', $ready, 2);
    $result = app_mgs_snmp_get_raw(
        $host,
        $community,
        (int) $port,
        '1.3.6.1.2.1.2.2.1.2.1',
        180
    );
    if (empty($result['ok']) || (int) ($result['version'] ?? -1) !== 1 || (string) ($result['value'] ?? '') !== 'Gi0/1') {
        throw new RuntimeException('v2c_fallback_failed:' . json_encode($result, JSON_UNESCAPED_SLASHES));
    }
    echo json_encode(['status' => 'passed', 'negotiated_version' => '2c'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
}
