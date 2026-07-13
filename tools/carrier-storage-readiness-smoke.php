<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$root = dirname(__DIR__);
$checks = [];

$add = static function (string $name, bool $ok, string $message) use (&$checks): void {
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'message' => $message,
    ];
};

$schemaPath = $root . '/deploy/clickhouse/superoptic-cgnat.sql';
$composePath = $root . '/deploy/clickhouse/docker-compose.clickhouse.yml';
$configPath = $root . '/deploy/clickhouse/config/config.xml';
$natIngestPath = $root . '/tools/cgnat-clickhouse-ingest.php';
$radacctOffloadPath = $root . '/tools/radius-accounting-offload.php';
$exportPath = $root . '/tools/law-enforcement-export.php';

$schema = is_file($schemaPath) ? (string) file_get_contents($schemaPath) : '';
$compose = is_file($composePath) ? (string) file_get_contents($composePath) : '';

$add('clickhouse_docker_compose', is_file($composePath) && str_contains($compose, 'clickhouse/clickhouse-server'), 'Dockerized ClickHouse deployment must be present.');
$add('clickhouse_keeper_replicated_ready', str_contains($compose, 'clickhouse-keeper') && is_file($configPath), 'Deployment must be replicated-ready through ClickHouse Keeper config.');
$add('cgnat_replicated_merge_tree', str_contains($schema, 'ReplicatedMergeTree') && str_contains($schema, 'radius_nat_logs'), 'CGNAT logs must use replicated-ready MergeTree storage.');
$add('cgnat_monthly_partition', str_contains($schema, 'PARTITION BY toYYYYMM(logged_at)'), 'CGNAT logs must use monthly partitions.');
$add('cgnat_compression', str_contains($schema, 'CODEC(') && str_contains($schema, 'ZSTD'), 'CGNAT schema must define compression codecs.');
$add('cgnat_retention_ttl', str_contains($schema, 'TTL logged_at + INTERVAL'), 'CGNAT schema must define retention TTL.');
$add('cgnat_rollups', str_contains($schema, 'nat_logs_hourly') && str_contains($schema, 'nat_logs_daily'), 'CGNAT hourly/daily rollups must exist.');
$add('law_export_audit_schema', str_contains($schema, 'law_export_requests') && str_contains($schema, 'export_hash'), 'Law enforcement export audit schema must exist.');
$add('radacct_replicated_table', str_contains($schema, 'radacct_events') && str_contains($schema, 'PARTITION BY toYYYYMM(acctstarttime)'), 'RADIUS accounting event table must be partitioned.');
$add('radacct_rollups', str_contains($schema, 'radacct_hourly_rollup') && str_contains($schema, 'radacct_daily_rollup') && str_contains($schema, 'radacct_monthly_rollup'), 'RADIUS accounting rollup tables must exist.');
$add('cgnat_ingest_cli', is_file($natIngestPath), 'Queue -> ClickHouse CGNAT ingest CLI must exist.');
$add('radacct_offload_cli', is_file($radacctOffloadPath), 'RADIUS accounting offload CLI must exist.');
$add('law_export_cli', is_file($exportPath), 'Law enforcement export CLI must exist.');

$failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok']));
$payload = [
    'status' => $failed === [] ? 'ok' : 'failed',
    'failed' => count($failed),
    'checks' => $checks,
    'generated_at' => date(DATE_ATOM),
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failed === [] ? 0 : 1);
