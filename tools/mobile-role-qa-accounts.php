<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/config.php';

$password = (string) getenv('SUPEROPTIC_MOBILE_QA_PASSWORD');
if (strlen($password) < 12) {
    fwrite(STDERR, "SUPEROPTIC_MOBILE_QA_PASSWORD must contain at least 12 characters.\n");
    exit(2);
}

$db = app_db();
$accounts = [
    ['mobile.qa.superadmin', 'admin', 'super_admin', 'Mobil QA Super Admin', 'Super Admin'],
    ['mobile.qa.admin', 'admin', 'admin', 'Mobil QA Admin', 'Admin'],
    ['mobile.qa.technician', 'technician', 'technician', 'Mobil QA Texnik', 'Sahə texniki'],
    ['mobile.qa.operator', 'operator', 'operator', 'Mobil QA Operator', 'Operator'],
    ['mobile.qa.engineer', 'technician', 'engineer', 'Mobil QA Mühəndis', 'Şəbəkə mühəndisi'],
];

$upsert = $db->prepare(
    'INSERT INTO portal_accounts
        (login, password_hash, role, account_type, employee_role, full_name, job_title, area, status, is_active, payload_json, created_at)
     VALUES
        (:login, :password_hash, "employee", :account_type, :employee_role, :full_name, :job_title, "Baki", "Aktiv", 1, :payload_json, NOW())
     ON DUPLICATE KEY UPDATE
        password_hash = VALUES(password_hash),
        role = "employee",
        account_type = VALUES(account_type),
        employee_role = VALUES(employee_role),
        full_name = VALUES(full_name),
        job_title = VALUES(job_title),
        area = VALUES(area),
        status = "Aktiv",
        is_active = 1,
        payload_json = VALUES(payload_json)'
);

$select = $db->prepare('SELECT id, login, employee_role FROM portal_accounts WHERE login = ? LIMIT 1');
$consent = $db->prepare(
    'INSERT IGNORE INTO hr_employee_legal_consents
        (account_id, consent_type, consent_version, ip_address, user_agent, device_uuid, agreed_at)
     VALUES (?, "gps_tracking_labor_code", "v1.0", "127.0.0.1", "mobile-role-qa", "qa-fixture", NOW())'
);

$db->beginTransaction();
try {
    foreach ($accounts as [$login, $accountType, $employeeRole, $fullName, $jobTitle]) {
        $upsert->execute([
            'login' => $login,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'account_type' => $accountType,
            'employee_role' => $employeeRole,
            'full_name' => $fullName,
            'job_title' => $jobTitle,
            'payload_json' => json_encode(['qa_fixture' => true], JSON_THROW_ON_ERROR),
        ]);
        $select->execute([$login]);
        $account = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new RuntimeException("QA account was not saved: {$login}");
        }
        $consent->execute([(int) $account['id']]);
        echo json_encode($account, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
