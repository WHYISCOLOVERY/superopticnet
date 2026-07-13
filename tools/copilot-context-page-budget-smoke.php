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
    '--render=mgs-copilot-context.php',
];

$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$startedAt = microtime(true);
$process = proc_open($command, $descriptorSpec, $pipes, $root);
if (!is_resource($process)) {
    fwrite(STDERR, 'Unable to render mgs-copilot-context.php' . PHP_EOL);
    exit(2);
}

fclose($pipes[0]);
$html = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
$durationMs = (int) round((microtime(true) - $startedAt) * 1000);

$jsonCommand = [
    PHP_BINARY,
    $renderScript,
    '--render=mgs-copilot-context.php',
    '--query=format=json',
];
$jsonProcess = proc_open($jsonCommand, $descriptorSpec, $jsonPipes, $root);
$jsonOutput = '';
$jsonError = '';
$jsonExitCode = 99;
if (is_resource($jsonProcess)) {
    fclose($jsonPipes[0]);
    $jsonOutput = (string) stream_get_contents($jsonPipes[1]);
    $jsonError = (string) stream_get_contents($jsonPipes[2]);
    fclose($jsonPipes[1]);
    fclose($jsonPipes[2]);
    $jsonExitCode = proc_close($jsonProcess);
}
$jsonPayload = json_decode($jsonOutput, true);
$legacySource = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'mgs-copilot-context.php');

$failures = [];
if ($exitCode !== 0) {
    $failures[] = 'render_exit_code=' . $exitCode;
}
if (trim($html) !== '') {
    $failures[] = 'legacy_html_route_rendered_body';
}
if (stripos($legacySource, 'ai-network-analytics.php') === false) {
    $failures[] = 'canonical_ai_redirect_missing';
}
if ($jsonExitCode !== 0 || !is_array($jsonPayload) || empty($jsonPayload['ok'])) {
    $failures[] = 'legacy_json_contract_failed:' . trim($jsonError);
}
if (strlen($html) > 45000) {
    $failures[] = 'default_page_html_too_large=' . strlen($html);
}
if ($durationMs > 1800) {
    $failures[] = 'default_page_too_slow=' . $durationMs;
}

$payload = [
    'status' => $failures === [] ? 'ok' : 'failed',
    'duration_ms' => $durationMs,
    'html_bytes' => strlen($html),
    'json_bytes' => strlen($jsonOutput),
    'stderr' => trim($stderr),
    'failures' => $failures,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($failures === [] ? 0 : 1);
