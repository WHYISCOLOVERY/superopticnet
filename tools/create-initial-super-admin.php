<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require dirname(__DIR__) . '/config.php';

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
$statusOnly = in_array('--status', $arguments, true);
$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database_unavailable\n");
    exit(2);
}

$login = strtolower(trim((string) getenv('INITIAL_ADMIN_LOGIN')));
$login = $login !== '' ? $login : 's.abdullayev@superoptic.net';
if (!filter_var($login, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "invalid_admin_login\n");
    exit(4);
}
$activeStatement = $db->prepare(
    "SELECT COUNT(*) FROM portal_accounts
      WHERE LOWER(login) = :login
        AND role = 'employee' AND employee_role = 'super_admin' AND is_active = 1"
);
$activeStatement->execute(['login' => $login]);
$activeCount = (int) $activeStatement->fetchColumn();

if ($statusOnly) {
    echo json_encode([
        'ok' => $activeCount > 0,
        'active_super_admin_count' => $activeCount,
        'login' => $login,
        'credentials_exposed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($activeCount > 0 ? 0 : 3);
}

if ($activeCount > 0) {
    echo json_encode([
        'ok' => true,
        'created' => false,
        'reason' => 'active_super_admin_already_exists',
        'credentials_exposed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$password = (string) getenv('INITIAL_ADMIN_PASSWORD');
$fullName = trim((string) getenv('INITIAL_ADMIN_FULL_NAME'));
$fullName = $fullName !== '' ? $fullName : 'Samir Abdullayev';
$strongPassword = strlen($password) >= 14
    && preg_match('/[a-z]/', $password) === 1
    && preg_match('/[A-Z]/', $password) === 1
    && preg_match('/\d/', $password) === 1
    && preg_match('/[^A-Za-z0-9]/', $password) === 1;
if (!$strongPassword) {
    fwrite(STDERR, "initial_admin_password_policy_failed\n");
    exit(5);
}

$payload = [
    'summary' => [
        'role_title' => 'Portal administratoru',
        'billing_access' => 'Aktiv',
        'shift' => 'Idareetme',
        'open_queue' => 'Butun sistem',
    ],
    'permissions' => [
        'Customer, operator, technician ve admin hesablarini idare etmek',
        'CRM, statistika ve import proseslerini idare etmek',
        'OLT rolu ve sync prosesine nezaret etmek',
    ],
    'security' => [
        'must_change_password' => true,
        'initial_admin_created_at' => date(DATE_ATOM),
    ],
];

app_db_upsert_account([
    'external_id' => 'initial-super-admin',
    'login' => $login,
    'password' => $password,
    'role' => 'employee',
    'account_type' => 'admin',
    'employee_role' => 'super_admin',
    'full_name' => $fullName,
    'subscriber_code' => '',
    'contract_no' => '',
    'email' => $login,
    'phone' => '',
    'category' => '',
    'area' => 'Baki',
    'address' => '',
    'plan' => '',
    'status' => 'Aktiv',
    'payload' => $payload,
    'responsible_areas' => app_service_areas(),
    'is_active' => 1,
], false);

$created = app_db_account_by_login($login);
$ok = is_array($created)
    && (string) ($created['employee_role'] ?? '') === 'super_admin'
    && (int) ($created['is_active'] ?? 0) === 1;

echo json_encode([
    'ok' => $ok,
    'created' => $ok,
    'login' => $ok ? $login : null,
    'must_change_password' => $ok,
    'credentials_exposed' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 6);
