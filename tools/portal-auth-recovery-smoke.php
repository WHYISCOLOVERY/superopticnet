<?php
declare(strict_types=1);

require dirname(__DIR__) . '/portal-security-lib.php';

$checks = [];
$failures = [];
$expect = static function (bool $condition, string $name) use (&$checks, &$failures): void {
    $checks[] = ['name' => $name, 'ok' => $condition];
    if (!$condition) {
        $failures[] = $name;
    }
};

$originalRecaptchaSecret = getenv('RECAPTCHA_SECRET');
putenv('RECAPTCHA_SECRET');
putenv('CAPTCHA_LOCAL_MIN_SECONDS');
$expect(app_portal_local_challenge_minimum_seconds() === 0, 'local_captcha_default_has_no_false_rejection_delay');
putenv('CAPTCHA_LOCAL_MIN_SECONDS=0');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.77.' . random_int(1, 200) . '.' . random_int(1, 200);
app_session_boot();
$localChallenge = app_portal_human_challenge('smoke-human');
$expect(($localChallenge['mode'] ?? '') === 'local', 'local_captcha_fallback_issued');
$localVerification = app_portal_human_verify('smoke-human', [
    'website' => '',
    'human_nonce' => (string) ($localChallenge['nonce'] ?? ''),
    'human_answer' => (string) ($_SESSION['portal_human_challenges']['smoke-human']['answer'] ?? ''),
    'human_confirm' => '1',
]);
$expect(!empty($localVerification['ok']), 'local_captcha_verified');
$replayVerification = app_portal_human_verify('smoke-human', [
    'website' => '',
    'human_nonce' => (string) ($localChallenge['nonce'] ?? ''),
    'human_answer' => '1',
    'human_confirm' => '1',
]);
$expect(empty($replayVerification['ok']), 'local_captcha_replay_blocked');
putenv('CAPTCHA_LOCAL_MIN_SECONDS');
if ($originalRecaptchaSecret !== false) {
    putenv('RECAPTCHA_SECRET=' . $originalRecaptchaSecret);
}

$db = app_db();
$expect($db instanceof PDO, 'database_available');
if (!$db instanceof PDO) {
    echo json_encode(['ok' => false, 'checks' => $checks, 'failures' => $failures], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
$expect(app_portal_recovery_ensure_schema($db), 'recovery_schema_ready');

$stamp = date('YmdHis') . bin2hex(random_bytes(2));
$customerLogin = 'recovery.customer.' . $stamp . '@smoke.local';
$customerCode = 'REC' . $stamp;
$staffLogin = 'recovery.operator.' . $stamp . '@smoke.local';
$customer = app_db_upsert_account([
    'login' => $customerLogin,
    'password' => 'OldCustomer!Password7',
    'role' => 'customer',
    'account_type' => 'customer',
    'full_name' => 'Recovery Smoke Customer',
    'subscriber_code' => $customerCode,
    'phone' => '0501234567',
    'status' => 'Aktiv',
    'is_active' => 1,
    'payload' => ['security' => ['must_change_password' => false]],
], false);
$staff = app_db_upsert_account([
    'login' => $staffLogin,
    'password' => 'OldOperator!Password7',
    'role' => 'employee',
    'account_type' => 'operator',
    'employee_role' => 'operator',
    'full_name' => 'Recovery Smoke Operator',
    'status' => 'Aktiv',
    'is_active' => 1,
    'payload' => ['security' => ['must_change_password' => false]],
], false);
$expect(is_array($customer) && is_array($staff), 'smoke_accounts_created');

try {
    if (!is_array($customer) || !is_array($staff)) {
        throw new RuntimeException('smoke_accounts_missing');
    }

    $operatorReset = app_portal_privileged_password_reset(
        $customer,
        $staff,
        'Smoke customer identity verified by operator',
        'customer_operator'
    );
    $expect(!empty($operatorReset['ok']), 'operator_can_reset_customer_password');
    $temporaryCustomerPassword = (string) ($operatorReset['password'] ?? '');
    $expect(app_portal_recovery_password_valid($temporaryCustomerPassword), 'operator_temporary_password_strong');
    $customerAfterOperatorReset = app_db_account_by_id((int) $customer['id']);
    $expect(
        is_array($customerAfterOperatorReset)
        && password_verify($temporaryCustomerPassword, (string) ($customerAfterOperatorReset['password_hash'] ?? '')),
        'operator_reset_password_persisted'
    );

    $forbiddenStaffReset = app_portal_privileged_password_reset(
        $staff,
        $staff,
        'Smoke ordinary operator attempt',
        'staff_admin'
    );
    $expect(empty($forbiddenStaffReset['ok']) && ($forbiddenStaffReset['reason'] ?? '') === 'forbidden', 'operator_cannot_reset_staff_password');

    $superAdmin = app_db_account_by_login('s.abdullayev@superoptic.net');
    $expect(is_array($superAdmin) && app_portal_is_super_admin($superAdmin), 'designated_super_admin_available');
    if (is_array($superAdmin)) {
        $staffReset = app_portal_privileged_password_reset(
            $staff,
            $superAdmin,
            'Smoke admin-authorized staff recovery',
            'staff_admin'
        );
        $expect(!empty($staffReset['ok']), 'admin_can_reset_staff_password');
        $staffAfterReset = app_db_account_by_id((int) $staff['id']);
        $staffSecurity = is_array($staffAfterReset) ? app_db_account_payload($staffAfterReset)['security'] ?? [] : [];
        $expect(
            is_array($staffAfterReset)
            && password_verify((string) ($staffReset['password'] ?? ''), (string) ($staffAfterReset['password_hash'] ?? '')),
            'staff_temporary_password_persisted'
        );
        $expect(!empty($staffSecurity['must_change_password']), 'staff_forced_password_change_enabled');
    }

    $capturedDelivery = [];
    $request = app_portal_recovery_request(
        $customerCode,
        static function (string $destination, string $message, string $externalId) use (&$capturedDelivery): array {
            $capturedDelivery = compact('destination', 'message', 'externalId');
            return ['ok' => true, 'provider_message_id' => 'smoke-provider-message'];
        },
        '654321'
    );
    $expect(!empty($request['issued']), 'whatsapp_recovery_request_issued');
    $expect(($capturedDelivery['destination'] ?? '') === '994501234567', 'whatsapp_destination_normalized');
    $expect(str_contains((string) ($capturedDelivery['message'] ?? ''), '654321'), 'whatsapp_otp_delivered_to_sender_adapter');

    $recoveryRowStatement = $db->prepare(
        'SELECT * FROM portal_password_recovery_requests WHERE account_id = :account_id ORDER BY id DESC LIMIT 1'
    );
    $recoveryRowStatement->execute(['account_id' => (int) $customer['id']]);
    $recoveryRow = $recoveryRowStatement->fetch(PDO::FETCH_ASSOC);
    $expect(is_array($recoveryRow), 'recovery_row_persisted');
    $serializedRecovery = json_encode($recoveryRow);
    $expect(is_string($serializedRecovery) && !str_contains($serializedRecovery, '654321'), 'otp_not_stored_in_plaintext');
    $expect(strlen((string) ($recoveryRow['token_hash'] ?? '')) === 64, 'recovery_token_hashed');
    $expect(strlen((string) ($recoveryRow['destination_hash'] ?? '')) === 64, 'recovery_destination_hashed');

    $wrongCode = app_portal_recovery_complete('111111', 'NewCustomer!Password8', 'NewCustomer!Password8');
    $expect(empty($wrongCode['ok']) && ($wrongCode['reason'] ?? '') === 'recovery_code_invalid', 'wrong_otp_rejected');
    $correctCode = app_portal_recovery_complete('654321', 'NewCustomer!Password8', 'NewCustomer!Password8');
    $expect(!empty($correctCode['ok']), 'correct_otp_resets_password');
    $customerAfterRecovery = app_db_account_by_id((int) $customer['id']);
    $expect(
        is_array($customerAfterRecovery)
        && password_verify('NewCustomer!Password8', (string) ($customerAfterRecovery['password_hash'] ?? '')),
        'recovered_password_persisted'
    );
    $customerSecurity = is_array($customerAfterRecovery) ? app_db_account_payload($customerAfterRecovery)['security'] ?? [] : [];
    $expect(empty($customerSecurity['must_change_password']), 'whatsapp_recovery_does_not_force_second_change');

    $auditStatement = $db->prepare(
        "SELECT action_type, before_json, after_json, payload_json
         FROM mgs_operation_actions
         WHERE entity_type = 'portal_account' AND entity_id IN (:customer_id, :staff_id)
         ORDER BY id DESC LIMIT 20"
    );
    $auditStatement->execute([
        'customer_id' => (string) $customer['id'],
        'staff_id' => (string) $staff['id'],
    ]);
    $auditRows = $auditStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $actionTypes = array_column($auditRows, 'action_type');
    $expect(in_array('customer_password_reset_by_operator', $actionTypes, true), 'operator_reset_audited');
    $expect(in_array('staff_password_reset_by_admin', $actionTypes, true), 'staff_reset_audited');
    $expect(in_array('customer_password_recovered_by_whatsapp', $actionTypes, true), 'whatsapp_recovery_audited');
    $auditSerialized = json_encode($auditRows);
    $expect(
        is_string($auditSerialized)
        && !str_contains($auditSerialized, $temporaryCustomerPassword)
        && !str_contains($auditSerialized, '654321'),
        'credentials_absent_from_audit_payloads'
    );
} finally {
    foreach ([$customer['id'] ?? 0, $staff['id'] ?? 0] as $accountId) {
        if ((int) $accountId > 0) {
            $delete = $db->prepare('DELETE FROM portal_accounts WHERE id = :id');
            $delete->execute(['id' => (int) $accountId]);
        }
    }
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
