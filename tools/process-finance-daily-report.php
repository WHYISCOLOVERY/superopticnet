<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function finance_report_worker_has_flag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function finance_report_worker_option(array $argv, string $name, mixed $default = null): mixed
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }
    return $default;
}

function finance_report_worker_out(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

$argv = $_SERVER['argv'] ?? [];
$apply = finance_report_worker_has_flag($argv, '--apply');
$period = (string) finance_report_worker_option($argv, 'period', 'daily');
$startedAt = microtime(true);

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    finance_report_worker_out([
        'status' => 'failed',
        'reason' => 'db_unavailable',
        'generated_at' => date(DATE_ATOM),
    ], 2);
}

$nocActor = app_noc_system_actor('finance-report-worker');
$result = app_mgs_finance_period_report($period, $db, $nocActor, !$apply, $apply);

finance_report_worker_out([
    'status' => !empty($result['ok']) ? 'ok' : 'failed',
    'mode' => $apply ? 'apply' : 'dry-run',
    'period' => (string) ($result['period'] ?? $period),
    'from' => (string) ($result['from'] ?? ''),
    'to' => (string) ($result['to'] ?? ''),
    'income' => (float) ($result['income'] ?? 0),
    'expense' => (float) ($result['expense'] ?? 0),
    'net' => (float) ($result['net'] ?? 0),
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'generated_at' => date(DATE_ATOM),
], !empty($result['ok']) ? 0 : 1);
