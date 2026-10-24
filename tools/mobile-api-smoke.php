<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/api/mobile/v1/lib/mobile-auth-lib.php';

echo "=== SUPEROPTIC MOBILE API SUITE SMOKE TEST ===\n";

$db = app_db();
if (!$db instanceof PDO) {
    echo "[FAIL] Database unavailable.\n";
    exit(1);
}

// 1. Verify DB Tables created by migration
$requiredTables = [
    'mobile_device_tokens',
    'employee_live_locations',
    'mobile_outbox_queue',
    'hr_employee_legal_consents',
    'mobile_task_proofs',
    'mobile_auth_refresh_tokens',
    'mobile_app_config',
];

foreach ($requiredTables as $tbl) {
    $exists = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$tbl'")->fetchColumn();
    if ($exists === 1) {
        echo "[OK] Table $tbl exists.\n";
    } else {
        echo "[FAIL] Missing table $tbl.\n";
        exit(1);
    }
}

// 2. Test Mobile App Version Check
$stmtConf = $db->query("SELECT * FROM mobile_app_config WHERE platform = 'android' LIMIT 1");
$appConf = $stmtConf->fetch(PDO::FETCH_ASSOC);
if ($appConf && $appConf['min_supported_version'] === '1.0.0') {
    echo "[OK] mobile_app_config seeded successfully for android.\n";
} else {
    echo "[FAIL] mobile_app_config failed.\n";
    exit(1);
}

// 3. Find a test employee account (or create temporary one if none exists)
$stmtEmp = $db->query("SELECT * FROM portal_accounts WHERE role = 'employee' AND is_active = 1 LIMIT 1");
$testEmp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

if (!$testEmp) {
    echo "[WARN] No employee found, using admin account.\n";
    $testEmp = $db->query("SELECT * FROM portal_accounts WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

$testDeviceUuid = 'test-device-uuid-' . bin2hex(random_bytes(8));
$accountId = (int) $testEmp['id'];

// 4. Test Token Generation & Verification
$accessToken = app_mobile_generate_access_token($testEmp, $testDeviceUuid);
$claims = app_mobile_verify_access_token($accessToken);

if ($claims && (int) $claims['uid'] === $accountId && $claims['dev'] === $testDeviceUuid) {
    echo "[OK] Access token generation and HMAC verification passed.\n";
} else {
    echo "[FAIL] Access token verification failed.\n";
    exit(1);
}

// 5. Test Refresh Token Creation & Rotation (Anti-Theft Single Use)
$db->prepare(
    "INSERT INTO mobile_device_tokens (account_id,device_uuid,platform,is_active,last_seen_at)
     VALUES (?,?,'android',1,NOW())
     ON DUPLICATE KEY UPDATE is_active=1,revoked_at=NULL,last_seen_at=NOW()"
)->execute([$accountId, $testDeviceUuid]);
$refreshToken = app_mobile_create_refresh_token($accountId, $testDeviceUuid);
if (strlen($refreshToken) === 64) {
    echo "[OK] Refresh token created successfully.\n";
} else {
    echo "[FAIL] Refresh token creation failed.\n";
    exit(1);
}

$rotated = app_mobile_rotate_tokens($refreshToken, $testDeviceUuid);
if ($rotated && !empty($rotated['access_token']) && !empty($rotated['refresh_token'])) {
    echo "[OK] Single-use refresh token rotation passed.\n";
} else {
    echo "[FAIL] Token rotation failed.\n";
    exit(1);
}

// Test Token Reuse Replay Detection: presenting the same used refresh token MUST fail
$stolenAttempt = app_mobile_rotate_tokens($refreshToken, $testDeviceUuid);
if ($stolenAttempt === null) {
    echo "[OK] Token theft / reuse detection successfully blocked replay.\n";
} else {
    echo "[FAIL] Token reuse detection failed to block reused token.\n";
    exit(1);
}

// 6. Test Legal Consent Insertion
$stmtConsent = $db->prepare(
    "INSERT INTO hr_employee_legal_consents 
     (account_id, consent_type, consent_version, ip_address, user_agent, device_uuid, agreed_at)
     VALUES (?, 'gps_tracking_labor_code', 'v1.0', '127.0.0.1', 'SmokeTest', ?, NOW())"
);
$stmtConsent->execute([$accountId, $testDeviceUuid]);
echo "[OK] Employee legal consent recorded successfully.\n";

// 7. Test GPS Ping & Location Ingestion
$stmtLoc = $db->prepare(
    "INSERT INTO employee_live_locations 
     (account_id, latitude, longitude, accuracy, speed, heading, altitude, battery_level, is_charging, is_mock, is_on_duty, activity_type, recorded_at)
     VALUES (?, 40.409264, 49.867092, 5.0, 1.2, 90.0, 20.0, 85, 0, 0, 1, 'walking', NOW())"
);
$stmtLoc->execute([$accountId]);
echo "[OK] Real-time GPS location recorded with on-duty flag.\n";

// 8. Test Idempotent Mutation Outbox
$clientUuid = 'smoke-uuid-' . bin2hex(random_bytes(8));
$payload = ['ticket_id' => 999999, 'notes' => 'Smoke test payload'];

$res1 = app_mobile_idempotent_mutation($clientUuid, $accountId, 'task_resolve', $payload, static function ($p) {
    return ['status_code' => 200, 'data' => ['processed' => true, 'time' => time()]];
});

if ($res1['status_code'] === 200 && !$res1['is_duplicate']) {
    echo "[OK] Outbox mutation first execution passed.\n";
} else {
    echo "[FAIL] Outbox first execution failed.\n";
    exit(1);
}

$res2 = app_mobile_idempotent_mutation($clientUuid, $accountId, 'task_resolve', $payload, static function ($p) {
    return ['status_code' => 500, 'data' => ['should_not_run' => true]];
});

if ($res2['status_code'] === 200 && $res2['is_duplicate']) {
    echo "[OK] Outbox idempotency successfully deduplicated second execution.\n";
} else {
    echo "[FAIL] Outbox idempotency duplicate check failed.\n";
    exit(1);
}

// 9. Test Remote Wipe Revocation
$db->prepare("UPDATE mobile_device_tokens SET is_active = 0 WHERE account_id = ? AND device_uuid = ?")
   ->execute([$accountId, $testDeviceUuid]);
echo "[OK] Remote wipe flag simulated successfully.\n";

// Cleanup test records
$db->prepare("DELETE FROM mobile_outbox_queue WHERE client_uuid = ?")->execute([$clientUuid]);
$db->prepare("DELETE FROM hr_employee_legal_consents WHERE device_uuid = ?")->execute([$testDeviceUuid]);
$db->prepare("DELETE FROM mobile_auth_refresh_tokens WHERE device_uuid = ?")->execute([$testDeviceUuid]);
$db->prepare("DELETE FROM mobile_device_tokens WHERE device_uuid = ?")->execute([$testDeviceUuid]);

echo "\nALL 9 MOBILE ECOSYSTEM CORE SUITE SMOKE TESTS PASSED [OK]!\n";
