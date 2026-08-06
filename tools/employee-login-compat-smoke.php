<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function employee_login_compat_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

$login = app_environment_secret('SUPEROPTIC_TEST_LOGIN');
$password = app_environment_secret('SUPEROPTIC_TEST_PASSWORD');
if ($login === '' || $password === '') {
    echo json_encode([
        'status' => 'skipped',
        'message' => 'SUPEROPTIC_TEST_LOGIN and SUPEROPTIC_TEST_PASSWORD are required.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$user = app_portal_login('employee', $login, $password);
if (!is_array($user) || (string) ($user['login'] ?? '') !== $login) {
    employee_login_compat_fail('Environment-provided employee login failed', 2);
}

$session = app_db_user_session_payload($user);
if ((string) ($session['role'] ?? '') !== 'employee') {
    employee_login_compat_fail('Session payload role mismatch', 3);
}

echo json_encode([
    'status' => 'ok',
    'checks' => [
        'employee_login' => true,
        'session_payload_employee' => true,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
