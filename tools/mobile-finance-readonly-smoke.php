<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/api/mobile/v1/lib/mobile-auth-lib.php';

// Uses existing QA devices. Does not create accounts, devices or finance records.
$db = app_db();
$checks = [
    ['mobile.qa.admin', 'range=today', 200, 'finance/ledger.php'],
    ['mobile.qa.admin', 'range=custom&start_date=2026-10-05&end_date=2026-10-01', 422, 'finance/ledger.php'],
    ['mobile.qa.technician', 'range=today', 403, 'finance/ledger.php'],
    ['mobile.qa.technician', '', 200, 'technician/inventory-options.php'],
];
foreach ($checks as [$login, $query, $expected, $endpoint]) {
    $stmt = $db->prepare('SELECT a.*, d.device_uuid FROM portal_accounts a JOIN mobile_device_tokens d ON d.account_id = a.id AND d.is_active = 1 WHERE a.login = ? AND a.is_active = 1 LIMIT 1');
    $stmt->execute([$login]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) { fwrite(STDERR, "Existing QA device unavailable\n"); exit(2); }
    $token = app_mobile_generate_access_token($account, $account['device_uuid'], 60);
    $curl = curl_init('http://127.0.0.1/superopticnet/api/mobile/v1/' . $endpoint . '?' . $query);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $data = json_decode((string)$body, true);
    if ($status !== $expected || !is_array($data)) {
        fwrite(STDERR, "FAIL: expected $expected, got $status\n"); exit(1);
    }
    if ($endpoint === 'finance/ledger.php' && $expected === 200 && (!isset($data['data']['daily'], $data['data']['kpis'], $data['data']['entries']))) {
        fwrite(STDERR, "FAIL: report contract\n"); exit(1);
    }
    echo "PASS: $endpoint $expected\n";
}
