<?php
declare(strict_types=1);

putenv('MOBILE_JWT_SECRET=unit-test-mobile-jwt-secret-value-32-plus');
putenv('MOBILE_OTP_PEPPER=unit-test-mobile-otp-pepper-value-32-plus');
require_once dirname(__DIR__) . '/api/mobile/v1/lib/mobile-auth-lib.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$first = ['z' => 1, 'nested' => ['b' => 2, 'a' => 1], 'list' => [3, 2, 1]];
$second = ['list' => [3, 2, 1], 'nested' => ['a' => 1, 'b' => 2], 'z' => 1];
$expect(app_mobile_payload_hash($first) === app_mobile_payload_hash($second), 'canonical_payload_hash');
$expect(app_mobile_payload_hash($first) !== app_mobile_payload_hash(['z' => 2]), 'payload_change_detected');
$expect(app_mobile_ticket_is_assigned_to(['assigned_technician_account_id' => 42], 42), 'assigned_technician_allowed');
$expect(!app_mobile_ticket_is_assigned_to(['assigned_technician_account_id' => 41], 42), 'other_technician_denied');
$expect(app_mobile_otp_hash('1234') === app_mobile_otp_hash('1234'), 'otp_hash_stable');
$expect(app_mobile_otp_hash('1234') !== app_mobile_otp_hash('1235'), 'otp_hash_distinct');

$account = ['id' => 7, 'role' => 'employee', 'login' => 'unit', 'full_name' => 'Unit'];
$token = app_mobile_generate_access_token($account, 'device-unit', 60);
$claims = app_mobile_verify_access_token($token);
$expect(is_array($claims) && ($claims['uid'] ?? null) === 7, 'token_round_trip');
$expect(app_mobile_verify_access_token($token . 'tampered') === null, 'token_tamper_rejected');

if ($failures !== []) {
    fwrite(STDERR, 'FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'Mobile security unit checks passed: 8' . PHP_EOL;
