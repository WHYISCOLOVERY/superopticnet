<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

function live_risk_has_flag(string $flag): bool
{
    return in_array($flag, $_SERVER['argv'] ?? [], true);
}

function live_risk_option(string $name, string $default = ''): string
{
    $prefix = '--' . $name . '=';
    foreach ($_SERVER['argv'] ?? [] as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }

    return $default;
}

function live_risk_php_command(array $args): string
{
    $parts = [escapeshellarg(PHP_BINARY)];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg((string) $arg);
    }

    return implode(' ', $parts);
}

function live_risk_shell_command(array $args): string
{
    return implode(' ', array_map(static fn (string $arg): string => escapeshellarg($arg), $args));
}

function live_risk_run(string $name, string $command, int $timeoutSeconds = 60): array
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return [
            'name' => $name,
            'status' => 'failed',
            'exit_code' => -1,
            'stdout' => '',
            'stderr' => 'proc_open_failed',
            'decoded' => null,
        ];
    }

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $started = microtime(true);
    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if ((microtime(true) - $started) > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(100000);
    }

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($timedOut) {
        $exitCode = 124;
        $stderr = trim($stderr . "\n" . 'timeout_after_' . $timeoutSeconds . 's');
    }

    $trimmed = trim($stdout);
    $decoded = $trimmed !== '' ? json_decode($trimmed, true) : null;
    $semanticStatus = is_array($decoded) ? strtolower((string) ($decoded['status'] ?? '')) : '';
    $combined = strtolower($trimmed . "\n" . $stderr);
    $blockedByInfra = str_contains($combined, 'management_path_down')
        || str_contains($combined, 'dockerdesktoplinuxengine')
        || str_contains($combined, 'request returned 500 internal server error')
        || str_contains($combined, 'timeout_waiting_for_radius_response');
    if ($blockedByInfra) {
        $status = 'blocked';
    } elseif ($exitCode === 0 && ($semanticStatus === '' || in_array($semanticStatus, ['ok', 'skipped'], true))) {
        $status = 'ok';
    } else {
        $status = 'failed';
    }

    return [
        'name' => $name,
        'status' => $status,
        'exit_code' => $exitCode,
        'stdout' => $trimmed,
        'stderr' => trim($stderr),
        'decoded' => is_array($decoded) ? $decoded : null,
    ];
}

function live_risk_tcp_check(string $host, int $port, float $timeout = 2.0): array
{
    $errno = 0;
    $errstr = '';
    $started = microtime(true);
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $latencyMs = (int) round((microtime(true) - $started) * 1000);
    if (is_resource($socket)) {
        fclose($socket);
        return [
            'host' => $host,
            'port' => $port,
            'reachable' => true,
            'latency_ms' => $latencyMs,
            'error' => '',
        ];
    }

    return [
        'host' => $host,
        'port' => $port,
        'reachable' => false,
        'latency_ms' => $latencyMs,
        'error' => trim($errstr) !== '' ? $errstr : ('errno_' . $errno),
    ];
}

function live_risk_write_latest(array $payload): string
{
    $tmp = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($tmp)) {
        mkdir($tmp, 0775, true);
    }

    $path = $tmp . DIRECTORY_SEPARATOR . 'live-risk-regression-' . date('Ymd-His') . '.json';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    file_put_contents($path, $json !== false ? $json . PHP_EOL : "{}\n");
    file_put_contents($tmp . DIRECTORY_SEPARATOR . 'live-risk-regression-latest.json', $json !== false ? $json . PHP_EOL : "{}\n");

    return $path;
}

$applyTelegram = live_risk_has_flag('--apply-telegram');
$applySms = live_risk_has_flag('--apply-sms');
$applyRadiusCoa = live_risk_has_flag('--apply-radius-coa');
$includeDocker = !live_risk_has_flag('--skip-docker');
$includeOlt = !live_risk_has_flag('--skip-olt');
$smsDestination = live_risk_option('sms-destination', '994501234567');
$message = live_risk_option('message', 'Live risk regression: SuperOptic MGS');

$db = app_db();
$settings = $db instanceof PDO ? app_radius_settings() : [];
$coreRouter = (string) ($settings['router_ip'] ?? app_config('radius_router_ip', '172.16.5.5'));
$managementPathReady = app_mgs_management_path_ready();

$checks = [];
$checks[] = [
    'name' => 'management_path_vlan550',
    'status' => $managementPathReady ? 'ok' : 'blocked',
    'message' => $managementPathReady
        ? 'VLAN 550 management path is marked active.'
        : 'VLAN 550 management path is not connected/active; live OLT/NOC reads are expected to skip.',
];

foreach ([22, 3799, 1812] as $port) {
    $tcp = live_risk_tcp_check($coreRouter !== '' ? $coreRouter : '172.16.5.5', $port, 2.0);
    $checks[] = [
        'name' => 'core_router_tcp_' . $port,
        'status' => $tcp['reachable'] ? 'ok' : 'blocked',
        'message' => $tcp['reachable'] ? 'TCP reachable.' : 'TCP not reachable from this server.',
        'evidence' => $tcp,
    ];
}

$commands = [];
$commands[] = [
    'name' => $applyTelegram ? 'telegram_apply' : 'telegram_dry_run',
    'command' => live_risk_php_command(array_values(array_filter([
        'tools/test-telegram-bots.php',
        $applyTelegram ? '--apply' : '',
        '--message=' . $message,
    ]))),
    'timeout' => $applyTelegram ? 120 : 60,
    'risk' => $applyTelegram ? 'sends_real_telegram_messages' : 'dry_run_only',
];
$commands[] = [
    'name' => $applySms ? 'sms_apply' : 'sms_dry_run',
    'command' => live_risk_php_command(array_values(array_filter([
        'tools/test-communication-provider.php',
        '--channel=sms',
        '--destination=' . $smsDestination,
        '--message=' . $message,
        $applySms ? '--apply' : '',
    ]))),
    'timeout' => $applySms ? 120 : 60,
    'risk' => $applySms ? 'sends_one_real_sms_test' : 'dry_run_only',
];
if ($includeOlt) {
    $commands[] = [
        'name' => 'hsgq_live_ont_read',
        'command' => live_risk_php_command(['tools/hsgq-live-ont-smoke.php']),
        'timeout' => 90,
        'risk' => 'read_only_or_management_path_skip',
    ];
    $commands[] = [
        'name' => 'hsgq_cli_discovery',
        'command' => live_risk_php_command(['tools/hsgq-cli-discovery-smoke.php']),
        'timeout' => 120,
        'risk' => 'read_only_or_management_path_skip',
    ];
}
if ($applyRadiusCoa) {
    $commands[] = [
        'name' => 'radius_live_fake_coa',
        'command' => live_risk_php_command(['tools/radius-live-coa-smoke.php']),
        'timeout' => 90,
        'risk' => 'sends_fake_disconnect_request_to_router',
    ];
} else {
    $checks[] = [
        'name' => 'radius_live_fake_coa',
        'status' => 'skipped',
        'message' => 'Skipped. Add --apply-radius-coa to send a fake Disconnect-Request to the configured router.',
    ];
}
if ($includeDocker) {
    $commands[] = [
        'name' => 'docker_freeradius_container',
        'command' => live_risk_shell_command(['docker', 'ps', '--filter', 'name=superoptic-freeradius', '--format', '{{.Names}} {{.Status}}']),
        'timeout' => 30,
        'risk' => 'docker_status_only',
    ];
    $radiusScript = 'D:\\freeradius\\scripts\\test-radius.ps1';
    if (is_file($radiusScript)) {
        $commands[] = [
            'name' => 'docker_freeradius_radtest',
            'command' => 'PowerShell -ExecutionPolicy Bypass -File ' . escapeshellarg($radiusScript),
            'timeout' => 120,
            'risk' => 'local_radius_auth_test',
        ];
    } else {
        $checks[] = [
            'name' => 'docker_freeradius_radtest',
            'status' => 'skipped',
            'message' => 'D:\\freeradius\\scripts\\test-radius.ps1 not found.',
        ];
    }
}

$commandResults = [];
foreach ($commands as $definition) {
    $result = live_risk_run((string) $definition['name'], (string) $definition['command'], (int) $definition['timeout']);
    $result['risk'] = $definition['risk'];
    $commandResults[] = $result;
}

$failed = 0;
$blocked = 0;
foreach ($checks as $check) {
    if (($check['status'] ?? '') === 'blocked') {
        $blocked++;
    } elseif (($check['status'] ?? '') === 'failed') {
        $failed++;
    }
}
foreach ($commandResults as $result) {
    if (($result['status'] ?? '') === 'blocked') {
        $blocked++;
    } elseif (($result['status'] ?? '') !== 'ok') {
        $failed++;
    }
}

$payload = [
    'status' => $failed === 0 ? ($blocked > 0 ? 'blocked_by_infra' : 'ok') : 'failed',
    'mode' => [
        'telegram' => $applyTelegram ? 'apply' : 'dry-run',
        'sms' => $applySms ? 'apply' : 'dry-run',
        'radius_coa' => $applyRadiusCoa ? 'apply' : 'skipped',
    ],
    'generated_at' => date(DATE_ATOM),
    'management_path_ready' => $managementPathReady,
    'core_router' => $coreRouter,
    'summary' => [
        'checks' => count($checks),
        'commands' => count($commandResults),
        'failed' => $failed,
        'blocked' => $blocked,
    ],
    'checks' => $checks,
    'commands' => $commandResults,
];
$payload['report_path'] = live_risk_write_latest($payload);

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit($failed === 0 ? 0 : 4);
