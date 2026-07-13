<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function cgnat_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : (string) $value;
}

function cgnat_command_exists(string $command): bool
{
    $probe = PHP_OS_FAMILY === 'Windows'
        ? 'where.exe ' . escapeshellarg($command) . ' 2>NUL'
        : 'command -v ' . escapeshellarg($command) . ' >/dev/null 2>&1';
    exec($probe, $output, $code);
    return $code === 0;
}

function cgnat_clickhouse_probe(array $config): array
{
    $scheme = $config['secure'] ? 'https' : 'http';
    if ($config['host'] !== '') {
        $url = $scheme . '://' . $config['host'] . ':' . (int) ($config['http_port'] ?? 8123) . '/?database=' . rawurlencode((string) $config['database']);
        $headers = [
            'Content-Type: text/plain; charset=utf-8',
            'X-ClickHouse-User: ' . (string) $config['user'],
        ];
        if ((string) $config['password'] !== '') {
            $headers[] = 'X-ClickHouse-Key: ' . (string) $config['password'];
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => 'SELECT 1',
                'timeout' => 5,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if (is_string($response) && trim($response) === '1') {
            return ['ok' => true, 'reason' => 'http_probe_ok'];
        }
    }

    if (!cgnat_command_exists('clickhouse-client')) {
        return ['ok' => false, 'reason' => 'clickhouse_client_missing'];
    }

    $args = [
        'clickhouse-client',
        '--host=' . escapeshellarg($config['host']),
        '--port=' . escapeshellarg((string) $config['port']),
        '--user=' . escapeshellarg($config['user']),
        '--database=' . escapeshellarg($config['database']),
        '--query=' . escapeshellarg('SELECT 1'),
    ];
    if ($config['password'] !== '') {
        $args[] = '--password=' . escapeshellarg($config['password']);
    }
    if ($config['secure']) {
        $args[] = '--secure';
    }

    $output = [];
    exec(implode(' ', $args) . ' 2>&1', $output, $code);
    return [
        'ok' => $code === 0 && trim(implode("\n", $output)) === '1',
        'reason' => $code === 0 ? '' : trim(implode(' ', $output)),
    ];
}

$schemaPath = dirname(__DIR__) . '/deploy/clickhouse/superoptic-cgnat.sql';
$composePath = dirname(__DIR__) . '/deploy/clickhouse/docker-compose.clickhouse.yml';
$keeperConfigPath = dirname(__DIR__) . '/deploy/clickhouse/config/keeper.xml';
$serverConfigPath = dirname(__DIR__) . '/deploy/clickhouse/config/config.xml';
$ingestPath = dirname(__DIR__) . '/tools/cgnat-clickhouse-ingest.php';
$offloadPath = dirname(__DIR__) . '/tools/radius-accounting-offload.php';
$exportPath = dirname(__DIR__) . '/tools/law-enforcement-export.php';
$config = [
    'host' => cgnat_env('CLICKHOUSE_HOST'),
    'port' => (int) cgnat_env('CLICKHOUSE_PORT', '9000'),
    'http_port' => (int) cgnat_env('CLICKHOUSE_HTTP_PORT', '8123'),
    'user' => cgnat_env('CLICKHOUSE_USER', 'default'),
    'password' => cgnat_env('CLICKHOUSE_PASSWORD'),
    'database' => cgnat_env('CLICKHOUSE_DATABASE', 'superoptic_analytics'),
    'secure' => in_array(strtolower(cgnat_env('CLICKHOUSE_SECURE', '0')), ['1', 'true', 'yes'], true),
];

$schemaReady = is_file($schemaPath) && filesize($schemaPath) > 0;
$schema = $schemaReady ? (string) file_get_contents($schemaPath) : '';
$deploymentReady = is_file($composePath) && is_file($keeperConfigPath) && is_file($serverConfigPath);
$toolingReady = is_file($ingestPath) && is_file($offloadPath) && is_file($exportPath);
$carrierSchemaReady = str_contains($schema, 'ReplicatedMergeTree')
    && str_contains($schema, 'CODEC(')
    && str_contains($schema, 'nat_logs_hourly')
    && str_contains($schema, 'radacct_hourly_rollup')
    && str_contains($schema, 'law_export_requests');
$configured = $config['host'] !== '';
$probe = ['ok' => false, 'reason' => 'not_configured'];
if ($configured) {
    $probe = cgnat_clickhouse_probe($config);
}

$status = 'planned';
if (!$schemaReady || !$carrierSchemaReady || !$deploymentReady || !$toolingReady) {
    $status = 'failed';
} elseif ($configured && ($probe['ok'] ?? false)) {
    $status = 'ready';
}

$payload = [
    'status' => $status,
    'externalized' => $status === 'ready',
    'schema_ready' => $schemaReady,
    'carrier_schema_ready' => $carrierSchemaReady,
    'deployment_ready' => $deploymentReady,
    'tooling_ready' => $toolingReady,
    'schema_path' => $schemaPath,
    'docker_compose_path' => $composePath,
    'configured' => $configured,
    'clickhouse' => [
        'host' => $config['host'] !== '' ? $config['host'] : null,
        'port' => $config['port'],
        'http_port' => $config['http_port'],
        'database' => $config['database'],
        'user' => $config['user'],
        'secure' => $config['secure'],
        'password_set' => $config['password'] !== '',
        'probe_ok' => (bool) ($probe['ok'] ?? false),
        'probe_reason' => (string) ($probe['reason'] ?? ''),
    ],
    'required_env' => [
        'CLICKHOUSE_HOST',
        'CLICKHOUSE_PORT',
        'CLICKHOUSE_HTTP_PORT',
        'CLICKHOUSE_USER',
        'CLICKHOUSE_PASSWORD',
        'CLICKHOUSE_DATABASE',
        'CLICKHOUSE_SECURE',
    ],
    'next' => $status === 'ready'
        ? 'Route CGNAT/syslog parser to ClickHouse and keep MySQL radius_nat_logs for short development/search samples only.'
        : 'Install ClickHouse, apply deploy/clickhouse/superoptic-cgnat.sql, then set CLICKHOUSE_* environment variables.',
    'generated_at' => date(DATE_ATOM),
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($status === 'failed' ? 2 : 0);
