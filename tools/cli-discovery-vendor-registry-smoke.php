<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$errors = [];
$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

// 1. The registry itself must be reachable and list HSGQ (the only
//    vendor with a real discovery helper today).
$registry = app_mgs_cli_discovery_handler_registry();
if (!array_key_exists('HSGQ', $registry) || !isset($registry['HSGQ']['probe'], $registry['HSGQ']['derive'], $registry['HSGQ']['source_label'])) {
    $errors[] = 'hsgq_missing_from_registry';
}
if (!function_exists((string) ($registry['HSGQ']['probe'] ?? ''))) {
    $errors[] = 'hsgq_probe_function_missing';
}
if (!function_exists((string) ($registry['HSGQ']['derive'] ?? ''))) {
    $errors[] = 'hsgq_derive_function_missing';
}
$supported = app_mgs_cli_discovery_supported_vendors();
if (!in_array('HSGQ', $supported, true)) {
    $errors[] = 'hsgq_missing_from_supported_vendor_list';
}

$nocActor = ['role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'name' => 'CLI Discovery Registry Smoke'];

// The vendor-dispatch gate sits behind an earlier "is the management VLAN
// reachable at all" check (app_mgs_management_path_ready()). This dev
// environment has no live management path, so that earlier gate would mask
// the vendor-dispatch logic entirely -- temporarily flip it on to actually
// exercise the registry, then always restore whatever it was before.
$originalManagementPath = app_mgs_management_path_ready();
app_mgs_set_management_path(true, $nocActor, $db);

try {
    // 2. A vendor with NO registered handler must fail fast at the dispatch
    //    gate -- before any SSH attempt -- and tell the caller which vendors
    //    ARE supported, so a future onboarding effort knows where to plug in.
    $fakeDevice = [
        'device_key' => 'smoke-fake-vendor-' . date('His') . random_int(100, 999),
        'device_name' => 'Fake Unsupported Vendor Smoke Device',
        'ip_address' => '203.0.113.10',
        'device_type' => 'olt',
        'vendor' => 'ACME',
        'model' => 'AcmeOLT-1',
    ];
    $unsupported = app_mgs_backup_payload_for_device($fakeDevice, 'cli_discovery_snapshot', $nocActor, $db, 'apply_device_backup');
    if (!empty($unsupported['ok']) || (string) ($unsupported['reason'] ?? '') !== 'cli_discovery_not_supported') {
        $errors[] = 'unsupported_vendor_was_not_rejected:' . json_encode($unsupported, JSON_UNESCAPED_SLASHES);
    }
    if (!in_array('HSGQ', (array) ($unsupported['supported_vendors'] ?? []), true)) {
        $errors[] = 'unsupported_vendor_response_missing_supported_list';
    }

    // 3. A registered vendor (HSGQ) with a fake device key must pass the
    //    dispatch gate and reach the credential-resolution step -- i.e. it must
    //    fail with a DIFFERENT reason than cli_discovery_not_supported, proving
    //    the registry lookup itself is not the blocker for a known vendor.
    $fakeHsgqDevice = [
        'device_key' => 'smoke-fake-hsgq-' . date('His') . random_int(100, 999),
        'device_name' => 'Fake HSGQ Smoke Device',
        'ip_address' => '203.0.113.11',
        'device_type' => 'olt',
        'vendor' => 'HSGQ',
        'model' => 'HSGQ-Smoke',
    ];
    $hsgqAttempt = app_mgs_backup_payload_for_device($fakeHsgqDevice, 'cli_discovery_snapshot', $nocActor, $db, 'apply_device_backup');
    if (!empty($hsgqAttempt['ok']) || (string) ($hsgqAttempt['reason'] ?? '') === 'cli_discovery_not_supported') {
        $errors[] = 'registered_vendor_incorrectly_rejected_at_gate:' . json_encode($hsgqAttempt, JSON_UNESCAPED_SLASHES);
    }
} finally {
    app_mgs_set_management_path($originalManagementPath, $nocActor, $db);
}

echo json_encode([
    'status' => $errors === [] ? 'passed' : 'failed',
    'errors' => $errors,
    'supported_vendors' => $supported,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
