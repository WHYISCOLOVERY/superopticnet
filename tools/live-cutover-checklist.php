<?php
declare(strict_types=1);

require dirname(__DIR__) . '/live-cutover-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$argvList = $_SERVER['argv'] ?? [];
$asMarkdown = in_array('--markdown', $argvList, true);
$strict = in_array('--strict', $argvList, true);
$gates = app_live_cutover_gates();
$blocked = array_values(array_filter($gates, static fn (array $gate): bool => (string) ($gate['status'] ?? '') === 'blocked'));

if ($asMarkdown) {
    echo '# SuperOptic MGS live cutover checklist' . PHP_EOL . PHP_EOL;
    echo 'Generated: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;
    echo '| Gate | Status | Evidence | Next | Command |' . PHP_EOL;
    echo '|---|---|---|---|---|' . PHP_EOL;
    foreach ($gates as $gate) {
        echo '| ' . str_replace('|', '/', (string) $gate['title']) .
            ' | ' . str_replace('|', '/', (string) $gate['status']) .
            ' | ' . str_replace('|', '/', (string) $gate['evidence']) .
            ' | ' . str_replace('|', '/', (string) $gate['next']) .
            ' | `' . str_replace('`', '', (string) $gate['command']) . '` |' . PHP_EOL;
    }
    exit($strict && $blocked !== [] ? 2 : 0);
}

echo json_encode([
    'status' => $blocked === [] ? 'ready' : 'controlled_pending',
    'strict' => $strict,
    'generated_at' => date(DATE_ATOM),
    'summary' => [
        'total' => count($gates),
        'blocked' => count($blocked),
        'manual_apply' => count(array_filter($gates, static fn (array $gate): bool => (string) ($gate['severity'] ?? '') === 'manual')),
        'ready' => count(array_filter($gates, static fn (array $gate): bool => in_array((string) ($gate['status'] ?? ''), ['ready', 'ready_for_test', 'ready_for_controlled_apply'], true))),
    ],
    'gates' => $gates,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($strict && $blocked !== [] ? 2 : 0);
