<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once dirname(__DIR__) . '/mgs-integration-lib.php';

$apply = in_array('--apply', $argv, true);
$result = app_mgs_inventory_lifecycle_acceptance_smoke($apply);

$expected = [
    'drum_remaining_meters' => 930.0,
    'technician_connectors_remaining' => 46.0,
    'modem_a_installation_count' => 2,
    'modem_a_condition' => 'USED_WORKING',
    'modem_b_active_customer' => '2320025',
    'customer_owned_stock_deducted' => false,
    'duplicate_assignment_blocked' => true,
    'insufficient_cable_blocked' => true,
    'duplicate_mac_blocked' => true,
];

$failed = [];
foreach ($expected as $key => $value) {
    $actual = $result['assertions'][$key] ?? null;
    if ($actual !== $value) {
        $failed[$key] = ['expected' => $value, 'actual' => $actual];
    }
}

$output = [
    'status' => empty($failed) && !empty($result['ok']) ? 'ok' : 'failed',
    'mode' => $apply ? 'apply' : 'rollback',
    'assertions' => $result['assertions'] ?? [],
    'failed' => $failed,
    'summary' => $result['summary'] ?? '',
];

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($output['status'] === 'ok' ? 0 : 1);
