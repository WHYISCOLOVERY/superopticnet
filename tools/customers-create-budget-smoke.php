<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only' . PHP_EOL;
    exit(1);
}

$root = dirname(__DIR__);
$renderScript = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'billing-ui-smoke.php';
$command = [
    PHP_BINARY,
    $renderScript,
    '--render=billing-customers.php',
    '--query=' . http_build_query(['create' => '1', 'perf' => '1']),
];

$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$startedAt = microtime(true);
$process = proc_open($command, $descriptorSpec, $pipes, $root);
if (!is_resource($process)) {
    fwrite(STDERR, 'Unable to render billing-customers.php create mode' . PHP_EOL);
    exit(2);
}

fclose($pipes[0]);
$html = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
$durationMs = (int) round((microtime(true) - $startedAt) * 1000);

$perfPayload = null;
if (preg_match('/\{[\s\S]*"billing_customers_perf"[\s\S]*\}/', $stderr, $match) === 1) {
    $decoded = json_decode($match[0], true);
    if (is_array($decoded)) {
        $perfPayload = $decoded;
    }
}

$labels = [];
foreach (($perfPayload['billing_customers_perf'] ?? []) as $mark) {
    if (is_array($mark) && isset($mark['label'])) {
        $labels[] = (string) $mark['label'];
    }
}

preg_match_all('/<tbody[\s\S]*?<\/tbody>/i', $html, $tbodyMatches);
$rowCount = 0;
foreach ($tbodyMatches[0] ?? [] as $tbody) {
    preg_match_all('/<tr\b/i', $tbody, $rowMatches);
    $rowCount += count($rowMatches[0] ?? []);
}

$failures = [];
if ($exitCode !== 0) {
    $failures[] = 'render_exit_code=' . $exitCode;
}
if ($perfPayload === null) {
    $failures[] = 'perf_payload_missing';
}
if (!in_array('data_skipped_for_create', $labels, true)) {
    $failures[] = 'customer_list_query_not_skipped_in_create_mode';
}
if (in_array('data', $labels, true)) {
    $failures[] = 'customer_data_query_executed_in_create_mode';
}
if ($rowCount > 5) {
    $failures[] = 'create_mode_table_rows_over_budget=' . $rowCount;
}
if (strlen($html) > 90000) {
    $failures[] = 'create_mode_html_too_large=' . strlen($html);
}

$payload = [
    'status' => $failures === [] ? 'ok' : 'failed',
    'duration_ms' => $durationMs,
    'html_bytes' => strlen($html),
    'row_count' => $rowCount,
    'perf_labels' => $labels,
    'failures' => $failures,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($failures === [] ? 0 : 1);
