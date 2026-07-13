<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only' . PHP_EOL;
    exit(1);
}

$root = dirname(__DIR__);
$renderScript = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'billing-ui-smoke.php';
$query = http_build_query(['per_page' => '500']);
$command = [
    PHP_BINARY,
    $renderScript,
    '--render=billing-payments.php',
    '--query=' . $query,
];

$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$startedAt = microtime(true);
$process = proc_open($command, $descriptorSpec, $pipes, $root);
if (!is_resource($process)) {
    fwrite(STDERR, 'Unable to render billing-payments.php' . PHP_EOL);
    exit(2);
}

fclose($pipes[0]);
$html = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
$durationMs = (int) round((microtime(true) - $startedAt) * 1000);

preg_match_all('/<tbody[\s\S]*?<\/tbody>/i', $html, $tbodyMatches);
$rowCount = 0;
foreach ($tbodyMatches[0] ?? [] as $tbody) {
    preg_match_all('/<tr\b/i', $tbody, $rowMatches);
    $rowCount += count($rowMatches[0] ?? []);
}

$hasLargePageOptions = preg_match('/<option[^>]+value="(?:100|200|500)"/i', $html) === 1;
$failures = [];
if ($exitCode !== 0) {
    $failures[] = 'render_exit_code=' . $exitCode;
}
if ($stderr !== '') {
    $failures[] = 'render_stderr=' . trim($stderr);
}
if ($rowCount > 55) {
    $failures[] = 'payment_page_rows_over_budget=' . $rowCount;
}
if ($hasLargePageOptions) {
    $failures[] = 'large_per_page_options_visible';
}

$payload = [
    'status' => $failures === [] ? 'ok' : 'failed',
    'duration_ms' => $durationMs,
    'row_count' => $rowCount,
    'html_bytes' => strlen($html),
    'failures' => $failures,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($failures === [] ? 0 : 1);
