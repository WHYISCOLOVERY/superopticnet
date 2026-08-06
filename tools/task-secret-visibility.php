<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/secret-visibility-lib.php';

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
$plan = in_array('--plan', $arguments, true);
$requiredKeys = app_service_secret_visibility_required_keys();
if ($plan) {
    echo json_encode([
        'ok' => true,
        'mode' => 'plan',
        'context' => 'scheduled-task',
        'required_count' => count($requiredKeys),
        'files_written' => 0,
        'secrets_exposed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$missing = app_service_secret_visibility_missing_keys($requiredKeys);
$evidence = [
    'ok' => $missing === [],
    'context' => 'scheduled-task',
    'sapi' => PHP_SAPI,
    'required_count' => count($requiredKeys),
    'missing_keys' => $missing,
    'checked_at' => date(DATE_ATOM),
    'secrets_exposed' => false,
];
$evidencePath = dirname(__DIR__) . '/tmp/task-secret-visibility.json';
app_service_secret_visibility_write_evidence($evidencePath, $evidence);

echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($evidence['ok'] ? 0 : 2);
