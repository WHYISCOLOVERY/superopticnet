<?php
declare(strict_types=1);

require dirname(__DIR__) . '/ai-team-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function ai_team_agenda_worker_has_flag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function ai_team_agenda_worker_out(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

$argv = $_SERVER['argv'] ?? [];
$apply = ai_team_agenda_worker_has_flag($argv, '--apply');
$startedAt = microtime(true);

$db = app_db();
if (!$db instanceof PDO) {
    ai_team_agenda_worker_out([
        'status' => 'failed',
        'reason' => 'db_unavailable',
        'generated_at' => date(DATE_ATOM),
    ], 2);
}

try {
    $results = app_ai_team_run_daily_agenda($db, $apply);
} catch (Throwable $exception) {
    ai_team_agenda_worker_out([
        'status' => 'failed',
        'reason' => $exception->getMessage(),
        'generated_at' => date(DATE_ATOM),
    ], 2);
}

$totalStaged = 0;
$totalWouldStage = 0;
foreach ($results as $personaResult) {
    $totalStaged += count($personaResult['staged_task_ids']);
    $totalWouldStage += count($personaResult['would_stage']);
}

ai_team_agenda_worker_out([
    'status' => 'ok',
    'mode' => $apply ? 'apply' : 'dry-run',
    'personas' => $results,
    'total_staged' => $totalStaged,
    'total_would_stage' => $totalWouldStage,
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'generated_at' => date(DATE_ATOM),
], 0);
