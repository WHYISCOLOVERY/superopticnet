<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function concurrency_smoke_finish(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO) {
    concurrency_smoke_finish(['status' => 'skipped', 'reason' => 'database_unavailable']);
}

$customer = $db->query(
    'SELECT id, subscriber_code
     FROM portal_accounts
     WHERE role = "customer" AND subscriber_code IS NOT NULL AND subscriber_code <> ""
     ORDER BY is_active DESC, id
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($customer)) {
    concurrency_smoke_finish(['status' => 'skipped', 'reason' => 'customer_unavailable']);
}

$customerId = (string) ($customer['id'] ?? '0');
$customerCode = (string) ($customer['subscriber_code'] ?? '');
$jobs = [
    ['name' => 'customers', 'query' => ['--render=billing-customers.php', '--query=' . http_build_query(['q' => $customerCode, 'per_page' => '25'])], 'marker' => 'Musteri siyahisi'],
    ['name' => 'profile', 'query' => ['--render=billing-customer-profile.php', '--query=' . http_build_query(['id' => $customerId])], 'marker' => 'Tam musteri karti'],
    ['name' => 'statistics', 'query' => ['--render=billing-statistics.php', '--query='], 'marker' => 'data-billing-statistics'],
    ['name' => 'radius', 'query' => ['--render=radius-active.php', '--query=' . http_build_query(['q' => $customerCode])], 'marker' => 'RADIUS'],
    ['name' => 'messages', 'query' => ['--render=message-center.php', '--query=' . http_build_query(['view' => 'single', 'q' => $customerCode])], 'marker' => 'Ferdi mesaj gonder'],
];

$startedAt = microtime(true);
$running = [];
foreach ($jobs as $job) {
    $command = array_merge([PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'billing-ui-smoke.php'], $job['query']);
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        concurrency_smoke_finish(['status' => 'failed', 'reason' => 'process_start_failed', 'job' => $job['name']], 2);
    }
    fclose($pipes[0]);
    $running[] = ['job' => $job, 'process' => $process, 'pipes' => $pipes, 'started_at' => microtime(true)];
}

$results = [];
$failed = 0;
foreach ($running as $entry) {
    $stdout = (string) stream_get_contents($entry['pipes'][1]);
    $stderr = (string) stream_get_contents($entry['pipes'][2]);
    fclose($entry['pipes'][1]);
    fclose($entry['pipes'][2]);
    $exitCode = proc_close($entry['process']);
    $durationMs = (int) round((microtime(true) - (float) $entry['started_at']) * 1000);
    $text = $stdout . "\n" . $stderr;
    $marker = (string) ($entry['job']['marker'] ?? '');
    $matchedError = preg_match('/Fatal error|Parse error|Uncaught|auth_required|Database baglantisi aktiv deyil/i', $text) === 1;
    $markerMissing = $marker !== '' && !str_contains($stdout, $marker);
    $ok = $exitCode === 0 && !$matchedError && !$markerMissing && $durationMs <= 12000;
    if (!$ok) {
        $failed++;
    }
    $results[] = [
        'name' => (string) ($entry['job']['name'] ?? 'job'),
        'ok' => $ok,
        'exit_code' => $exitCode,
        'duration_ms' => $durationMs,
        'marker_missing' => $markerMissing,
        'matched_error' => $matchedError,
        'html_bytes' => strlen($stdout),
        'stdout_excerpt' => $ok ? '' : mb_substr(trim(strip_tags($stdout)), 0, 800),
        'stderr_excerpt' => $ok ? '' : mb_substr(trim($stderr), 0, 800),
    ];
}

$totalMs = (int) round((microtime(true) - $startedAt) * 1000);
concurrency_smoke_finish([
    'status' => $failed === 0 ? 'ok' : 'failed',
    'concurrent_jobs' => count($jobs),
    'total_duration_ms' => $totalMs,
    'results' => $results,
], $failed === 0 ? 0 : 3);
