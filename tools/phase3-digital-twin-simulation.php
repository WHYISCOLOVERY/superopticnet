<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/mgs-integration-lib.php';
require_once dirname(__DIR__) . '/live-cutover-lib.php';

function phase3_arg_int(string $name, int $default, int $min = 1): int
{
    $prefix = '--' . $name . '=';
    foreach ($_SERVER['argv'] ?? [] as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return max($min, (int) substr((string) $arg, strlen($prefix)));
        }
    }

    return $default;
}

function phase3_scalar(PDO $db, string $sql, array $params = []): mixed
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    } catch (Throwable) {
        return null;
    }
}

function phase3_table_count(PDO $db, string $table): int
{
    $safe = str_replace('`', '``', $table);
    return (int) (phase3_scalar($db, 'SELECT COUNT(*) FROM `' . $safe . '`') ?? 0);
}

function phase3_table_size_mb(PDO $db, string $table): float
{
    return (float) (phase3_scalar(
        $db,
        'SELECT ROUND((data_length + index_length) / 1024 / 1024, 2)
           FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    ) ?? 0.0);
}

function phase3_table_partitions(PDO $db, string $table): int
{
    return (int) (phase3_scalar(
        $db,
        'SELECT COUNT(*)
           FROM information_schema.partitions
          WHERE table_schema = DATABASE() AND table_name = ? AND partition_name IS NOT NULL',
        [$table]
    ) ?? 0);
}

function phase3_index_count(PDO $db, string $table): int
{
    return (int) (phase3_scalar(
        $db,
        'SELECT COUNT(DISTINCT index_name)
           FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    ) ?? 0);
}

function phase3_table_exists(PDO $db, string $table): bool
{
    return (bool) phase3_scalar(
        $db,
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    );
}

function phase3_score(int $base, array $penalties): int
{
    return max(0, min(100, $base - array_sum($penalties)));
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database_unavailable\n");
    exit(2);
}

$days = phase3_arg_int('days', 30);
$customers = phase3_arg_int('customers', 100000);
$olts = phase3_arg_int('olts', 250);
$onts = phase3_arg_int('onts', 100000);
$uplinks = phase3_arg_int('uplinks', 500);
$pops = phase3_arg_int('pops', 50);
$branches = phase3_arg_int('branches', 10);
$operators = phase3_arg_int('operators', 100);
$technicians = phase3_arg_int('technicians', 50);
$nocEngineers = phase3_arg_int('noc_engineers', 10);
$natLogsPerHour = phase3_arg_int('nat_logs_per_hour', 500000);
$aiQuestions = phase3_arg_int('ai_questions', 10000);

$scenario = [
    'days' => $days,
    'customers' => $customers,
    'olts' => $olts,
    'onts' => $onts,
    'uplinks' => $uplinks,
    'pops' => $pops,
    'branches' => $branches,
    'operators' => $operators,
    'technicians' => $technicians,
    'noc_engineers' => $nocEngineers,
    'nat_logs_per_hour' => $natLogsPerHour,
    'ai_questions' => $aiQuestions,
];

$daily = [
    'new_connections' => (int) round($customers * 0.0007),
    'payments' => (int) round($customers * 0.035),
    'late_payment_cases' => (int) round($customers * 0.006),
    'customer_tickets' => (int) round($customers * 0.0045),
    'ont_reboot_events' => (int) round($onts * 0.003),
    'olt_incidents' => max(1, (int) round($olts * 0.015)),
    'uplink_events' => max(1, (int) round($uplinks * 0.002)),
    'sms_or_whatsapp_notices' => (int) round(($customers * 0.006 * 2) + ($customers * 0.035 * 0.15)),
    'invoices' => (int) round($customers * 0.035),
    'nat_logs' => $natLogsPerHour * 24,
];

$totals = [];
foreach ($daily as $key => $value) {
    $totals[$key] = $value * $days;
}
$totals['ai_questions'] = $aiQuestions;

$tables = [
    'portal_accounts',
    'billing_radius_services',
    'billing_payments',
    'radcheck',
    'radreply',
    'radacct',
    'radius_nat_logs',
    'portal_tickets',
    'communication_messages',
    'mgs_alarm_events',
    'inventory_stock_items',
    'inventory_stock_movements',
    'device_config_backups',
];

$dbMetrics = [];
foreach ($tables as $table) {
    $exists = phase3_table_exists($db, $table);
    $dbMetrics[$table] = [
        'exists' => $exists,
        'rows' => $exists ? phase3_table_count($db, $table) : 0,
        'size_mb' => $exists ? phase3_table_size_mb($db, $table) : 0.0,
        'indexes' => $exists ? phase3_index_count($db, $table) : 0,
        'partitions' => $exists ? phase3_table_partitions($db, $table) : 0,
    ];
}

$readinessRaw = shell_exec('"' . PHP_BINARY . '" "' . dirname(__DIR__) . '/tools/production-readiness-check.php" --production');
$readiness = is_string($readinessRaw) ? json_decode($readinessRaw, true) : null;
$readinessSummary = is_array($readiness) ? ($readiness['summary'] ?? []) : [];
$readinessFailures = [];
if (is_array($readiness)) {
    foreach (($readiness['checks'] ?? []) as $check) {
        if (is_array($check) && empty($check['ok'])) {
            $readinessFailures[] = [
                'name' => $check['name'] ?? '',
                'level' => $check['level'] ?? '',
                'message' => $check['message'] ?? '',
            ];
        }
    }
}

$liveGates = app_live_cutover_gates();
$blockedGates = array_values(array_filter(
    $liveGates,
    static fn (array $gate): bool => ($gate['status'] ?? '') === 'blocked'
));

$operationsTimingRaw = shell_exec('"' . PHP_BINARY . '" "' . dirname(__DIR__) . '/tools/operations-service-timing.php"');
$operationsTiming = is_string($operationsTimingRaw) ? json_decode($operationsTimingRaw, true) : null;
$slowestServiceSeconds = 0.0;
if (is_array($operationsTiming)) {
    foreach (($operationsTiming['results'] ?? []) as $result) {
        $slowestServiceSeconds = max($slowestServiceSeconds, (float) ($result['seconds'] ?? 0));
    }
}

$securitySmokeRaw = shell_exec('"' . PHP_BINARY . '" "' . dirname(__DIR__) . '/tools/security-injection-smoke.php"');
$securitySmoke = is_string($securitySmokeRaw) ? json_decode($securitySmokeRaw, true) : null;

$copilotSmokeRaw = shell_exec('"' . PHP_BINARY . '" "' . dirname(__DIR__) . '/tools/copilot-agent-smoke.php"');
$copilotSmoke = is_string($copilotSmokeRaw) ? json_decode($copilotSmokeRaw, true) : null;

$cgnatStorageRaw = shell_exec('"' . PHP_BINARY . '" "' . dirname(__DIR__) . '/tools/cgnat-storage-readiness.php"');
$cgnatStorage = is_string($cgnatStorageRaw) ? json_decode($cgnatStorageRaw, true) : null;
$analyticsExternalized = is_array($cgnatStorage) && ($cgnatStorage['status'] ?? '') === 'ready';

$natPartitions = (int) ($dbMetrics['radius_nat_logs']['partitions'] ?? 0);
$natIndexes = (int) ($dbMetrics['radius_nat_logs']['indexes'] ?? 0);
$radacctPartitions = (int) ($dbMetrics['radacct']['partitions'] ?? 0);
$criticalFailed = (int) ($readinessSummary['critical_failed'] ?? 99);
$warningFailed = (int) ($readinessSummary['warnings_failed'] ?? 99);
$vlanBlocked = count($blockedGates) > 0;
$workerAutostartWarning = false;
foreach ($readinessFailures as $failure) {
    if (($failure['name'] ?? '') === 'windows_scheduled_tasks_runtime') {
        $workerAutostartWarning = true;
    }
}

$findings = [];
if ($criticalFailed > 0) {
    $findings[] = ['severity' => 'critical', 'area' => 'production_readiness', 'message' => 'Critical production readiness checks failed.'];
}
if ($workerAutostartWarning) {
    $findings[] = ['severity' => 'high', 'area' => 'reliability', 'message' => 'Worker supervisor SYSTEM autostart is not confirmed.'];
}
if ($vlanBlocked) {
    $findings[] = ['severity' => 'high', 'area' => 'network', 'message' => 'VLAN 550/live device path is blocked; OLT/ONT live validation cannot be accepted.'];
}
if ($totals['nat_logs'] >= 100000000 && $natPartitions === 0 && !$analyticsExternalized) {
    $findings[] = ['severity' => 'high', 'area' => 'database', 'message' => '30-day NAT volume requires partitioned or externalized storage; radius_nat_logs has no partitions and ClickHouse CGNAT storage is not ready.'];
}
if ($radacctPartitions === 0 && $customers >= 25000 && !$analyticsExternalized) {
    $findings[] = ['severity' => 'medium', 'area' => 'database', 'message' => 'radacct is indexed but not partitioned; medium/large accounting growth needs partitioned or externalized accounting storage.'];
}
if ($slowestServiceSeconds > 1.0) {
    $findings[] = ['severity' => 'medium', 'area' => 'performance', 'message' => 'Operations service timing exceeds 1s on at least one endpoint.'];
}
if (!is_array($securitySmoke) || ($securitySmoke['status'] ?? '') !== 'ok') {
    $findings[] = ['severity' => 'high', 'area' => 'security', 'message' => 'Security injection smoke did not pass.'];
}
if (!is_array($copilotSmoke) || ($copilotSmoke['status'] ?? '') !== 'ok') {
    $findings[] = ['severity' => 'medium', 'area' => 'ai', 'message' => 'Copilot agent smoke did not pass.'];
}

$scores = [
    'operational_maturity' => phase3_score(86, [
        $criticalFailed > 0 ? 30 : 0,
        $vlanBlocked ? 12 : 0,
        $workerAutostartWarning ? 6 : 0,
    ]),
    'security_maturity' => phase3_score(84, [
        (!is_array($securitySmoke) || ($securitySmoke['status'] ?? '') !== 'ok') ? 25 : 0,
        $criticalFailed > 0 ? 10 : 0,
    ]),
    'ai_maturity' => phase3_score(78, [
        (!is_array($copilotSmoke) || ($copilotSmoke['status'] ?? '') !== 'ok') ? 25 : 0,
        $aiQuestions >= 10000 ? 6 : 0, // 10k corpus is estimated, not fully executed live.
    ]),
    'scalability' => phase3_score(78, [
        ($natPartitions === 0 && !$analyticsExternalized) ? 18 : 0,
        ($radacctPartitions === 0 && !$analyticsExternalized) ? 8 : 0,
        $customers >= 100000 ? 8 : 0,
    ]),
    'reliability' => phase3_score(80, [
        $workerAutostartWarning ? 12 : 0,
        $vlanBlocked ? 8 : 0,
        $warningFailed > 0 ? 4 : 0,
    ]),
    'ux' => phase3_score(82, [
        $slowestServiceSeconds > 1.0 ? 8 : 0,
    ]),
    'business' => phase3_score(84, [
        $criticalFailed > 0 ? 20 : 0,
        $warningFailed > 0 ? 4 : 0,
    ]),
];

$average = (int) round(array_sum($scores) / count($scores));
$decision = 'READY FOR SMALL ISP (<5000 customer)';
$natScaleReady = $natPartitions > 0 || $analyticsExternalized;
$acctScaleReady = $radacctPartitions > 0 || $analyticsExternalized;
if ($average >= 92 && $criticalFailed === 0 && $warningFailed === 0 && !$vlanBlocked && $natScaleReady && $acctScaleReady) {
    $decision = 'READY FOR CARRIER GRADE DEPLOYMENT (100000+ customer)';
} elseif ($average >= 86 && $criticalFailed === 0 && !$vlanBlocked && $natScaleReady) {
    $decision = 'READY FOR LARGE ISP (<100000 customer)';
} elseif ($average >= 78 && $criticalFailed === 0 && !$vlanBlocked) {
    $decision = 'READY FOR MEDIUM ISP (<25000 customer)';
} elseif ($average < 65 || $criticalFailed > 0) {
    $decision = 'NOT READY';
}

$nextRequired = [];
if ($workerAutostartWarning) {
    $nextRequired[] = 'Install and verify SuperOptic MGS Worker Supervisor as SYSTEM scheduled task.';
}
if ($vlanBlocked) {
    $nextRequired[] = 'Connect VLAN 550 and pass native-network-monitor-smoke.php plus hsgq-live-ont-smoke.php.';
}
if ($totals['nat_logs'] >= 100000000 && !$natScaleReady) {
    $nextRequired[] = 'Partition or externalize radius_nat_logs for ' . number_format((float) $totals['nat_logs'], 0, '.', '') . '+ 30-day NAT log volume.';
}
if ($customers >= 25000 && !$acctScaleReady) {
    $nextRequired[] = 'Add radacct partition/retention before medium/large accounting growth.';
}
if ($aiQuestions >= 10000) {
    $nextRequired[] = 'Execute a bounded 10k Copilot corpus test with hallucination/dangerous-advice scoring.';
}
if ($nextRequired === []) {
    $nextRequired[] = 'No blocking upgrade item detected for this scenario; proceed with controlled pilot cutover checks.';
}

$report = [
    'status' => 'ok',
    'generated_at' => date(DATE_ATOM),
    'scenario' => $scenario,
    'daily_event_model' => $daily,
    'thirty_day_totals' => $totals,
    'database_metrics' => $dbMetrics,
    'runtime_evidence' => [
        'production_readiness_summary' => $readinessSummary,
        'production_readiness_failures' => $readinessFailures,
        'blocked_live_gates' => array_map(static fn (array $gate): array => [
            'name' => $gate['name'] ?? '',
            'status' => $gate['status'] ?? '',
            'evidence' => $gate['evidence'] ?? '',
        ], $blockedGates),
        'slowest_operations_service_seconds' => $slowestServiceSeconds,
        'security_smoke_status' => is_array($securitySmoke) ? ($securitySmoke['status'] ?? 'unknown') : 'unknown',
        'copilot_smoke_status' => is_array($copilotSmoke) ? ($copilotSmoke['status'] ?? 'unknown') : 'unknown',
        'cgnat_storage_status' => is_array($cgnatStorage) ? ($cgnatStorage['status'] ?? 'unknown') : 'unknown',
        'cgnat_storage_externalized' => $analyticsExternalized,
    ],
    'findings' => $findings,
    'scores' => $scores + ['average' => $average],
    'go_live_decision' => $decision,
    'next_required_to_upgrade_decision' => $nextRequired,
];

$reportPath = dirname(__DIR__) . '/tmp/phase3-digital-twin-' . date('Ymd-His') . '.json';
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$report['report_path'] = $reportPath;

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
