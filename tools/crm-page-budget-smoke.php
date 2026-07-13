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
    '--render=crm.php',
];

$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$startedAt = microtime(true);
$process = proc_open($command, $descriptorSpec, $pipes, $root);
if (!is_resource($process)) {
    fwrite(STDERR, 'Unable to render crm.php' . PHP_EOL);
    exit(2);
}

fclose($pipes[0]);
$html = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
$durationMs = (int) round((microtime(true) - $startedAt) * 1000);

preg_match_all('/<tr\b/i', $html, $rowMatches);
$rowCount = count($rowMatches[0] ?? []);

$failures = [];
if ($exitCode !== 0) {
    $failures[] = 'render_exit_code=' . $exitCode;
}
if ($rowCount > 60) {
    $failures[] = 'crm_rows_over_budget=' . $rowCount;
}
if ($durationMs > 2800) {
    $failures[] = 'crm_default_too_slow=' . $durationMs;
}
if (strlen($html) > 90000) {
    $failures[] = 'crm_html_too_large=' . strlen($html);
}

$payload = [
    'status' => $failures === [] ? 'ok' : 'failed',
    'duration_ms' => $durationMs,
    'html_bytes' => strlen($html),
    'row_count' => $rowCount,
    'stderr' => trim($stderr),
    'failures' => $failures,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($failures === [] ? 0 : 1);
