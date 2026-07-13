<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function portal_login_password_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO) {
    portal_login_password_fail('Database unavailable', 2);
}

$login = 'smoke.password.' . strtolower(bin2hex(random_bytes(4)));
$password = 'Smoke<admin>Pass!42';

$account = app_db_upsert_account([
    'login' => $login,
    'password' => $password,
    'role' => 'employee',
    'account_type' => 'operator',
    'employee_role' => 'support',
    'full_name' => 'Smoke Password User',
    'is_active' => 1,
], false);

if (!is_array($account) || !isset($account['login'])) {
    portal_login_password_fail('Unable to create smoke employee account', 3);
}

$authed = app_portal_login('employee', $login, $password);
if (!is_array($authed) || (string) ($authed['login'] ?? '') !== $login) {
    portal_login_password_fail('Portal login rejected password containing tag-like characters', 4);
}

echo json_encode([
    'status' => 'ok',
    'checks' => [
        'password_preserved' => true,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
