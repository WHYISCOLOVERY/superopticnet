<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/clickhouse-client.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function acct_offload_arg(string $name, string $default = ''): string
{
    $prefix = '--' . $name . '=';
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
        if ($arg === '--' . $name) {
            return '1';
        }
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }
    return $default;
}

function acct_offload_time(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return gmdate('Y-m-d H:i:s.000');
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? gmdate('Y-m-d H:i:s.000') : gmdate('Y-m-d H:i:s.000', $timestamp);
}

$apply = acct_offload_arg('apply') === '1';
$limit = max(1, min(100000, (int) acct_offload_arg('limit', '10000')));
$olderThanHours = max(0, (int) acct_offload_arg('older-than-hours', '24'));

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "mysql_unavailable\n");
    exit(2);
}

$sql = 'SELECT *
          FROM radacct
         WHERE acctstarttime IS NOT NULL
           AND (
                acctstoptime IS NOT NULL
                OR acctupdatetime < DATE_SUB(NOW(), INTERVAL :hours HOUR)
           )
         ORDER BY acctstarttime ASC, radacctid ASC
         LIMIT :limit';
$stmt = $db->prepare($sql);
$stmt->bindValue('hours', $olderThanHours, PDO::PARAM_INT);
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = [];
while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
    $rows[] = [
        'acctstarttime' => acct_offload_time($row['acctstarttime'] ?? null),
        'acctupdatetime' => acct_offload_time($row['acctupdatetime'] ?? ($row['acctstarttime'] ?? null)),
        'acctstoptime' => trim((string) ($row['acctstoptime'] ?? '')) !== '' ? acct_offload_time((string) $row['acctstoptime']) : null,
        'username' => (string) ($row['username'] ?? ''),
        'nasipaddress' => (string) ($row['nasipaddress'] ?? ''),
        'framedipaddress' => (string) ($row['framedipaddress'] ?? ''),
        'callingstationid' => (string) ($row['callingstationid'] ?? ''),
        'acctsessionid' => (string) ($row['acctsessionid'] ?? ''),
        'acctuniqueid' => (string) ($row['acctuniqueid'] ?? ''),
        'acctinputoctets' => max(0, (int) ($row['acctinputoctets'] ?? 0)),
        'acctoutputoctets' => max(0, (int) ($row['acctoutputoctets'] ?? 0)),
        'acctsessiontime' => max(0, (int) ($row['acctsessiontime'] ?? 0)),
        'acctterminatecause' => (string) ($row['acctterminatecause'] ?? ''),
    ];
}

$result = [
    'status' => 'ok',
    'mode' => $apply ? 'apply' : 'dry-run',
    'candidate_rows' => count($rows),
    'sent_rows' => 0,
    'active_sessions_kept_in_mysql' => (int) $db->query('SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL')->fetchColumn(),
    'rollups' => [
        'hourly' => 'superoptic_analytics.radacct_hourly_rollup',
        'daily' => 'superoptic_analytics.radacct_daily_rollup',
        'monthly' => 'superoptic_analytics.radacct_monthly_rollup',
    ],
    'error' => '',
];

if ($apply && $rows !== []) {
    $response = app_clickhouse_request(
        'INSERT INTO superoptic_analytics.radacct_events FORMAT JSONEachRow',
        app_clickhouse_json_each_row($rows)
    );
    if (!$response['ok']) {
        $result['status'] = 'failed';
        $result['error'] = $response['error'] ?: ('http_' . $response['status']);
    } else {
        $result['sent_rows'] = count($rows);
    }
} elseif (!$apply) {
    $result['sent_rows'] = count($rows);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['status'] === 'ok' ? 0 : 3);
