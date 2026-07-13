<?php
declare(strict_types=1);

require dirname(__DIR__) . '/role-agent-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$result = app_role_agent_run_e2e_suite(true);
$ok = is_array($result)
    && ($result['status'] ?? '') === 'ok'
    && count($result['agents'] ?? []) >= 6
    && empty($result['failures'] ?? []);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
