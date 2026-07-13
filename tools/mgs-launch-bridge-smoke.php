<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function mgs_launch_bridge_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

if (!function_exists('app_mgs_bridge_shell_path')) {
    mgs_launch_bridge_fail('app_mgs_bridge_shell_path function missing');
}

$inventoryPath = app_mgs_bridge_shell_path('inventory');
if ($inventoryPath !== '/superopticnet/operations-center.php?section=inventory') {
    mgs_launch_bridge_fail('Unexpected inventory shell path: ' . $inventoryPath);
}

$ontPath = app_mgs_bridge_shell_path('ont');
if ($ontPath !== '/superopticnet/noc-devices.php?view=devices') {
    mgs_launch_bridge_fail('Unexpected ont shell path: ' . $ontPath);
}

$indexHtml = @file_get_contents('D:/xampp/htdocs/oltidareetme/public/index.html');
if (!is_string($indexHtml) || !str_contains($indexHtml, 'id="portal-return-link"')) {
    mgs_launch_bridge_fail('portal-return-link missing in MGS index');
}

$appJs = @file_get_contents('D:/xampp/htdocs/oltidareetme/public/app.js');
if (!is_string($appJs) || !str_contains($appJs, 'portal_return')) {
    mgs_launch_bridge_fail('portal_return query handling missing in MGS app.js');
}

echo json_encode([
    'status' => 'ok',
    'checks' => [
        'shell_path_helper' => true,
        'portal_return_link_markup' => true,
        'portal_return_query_handling' => true,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
