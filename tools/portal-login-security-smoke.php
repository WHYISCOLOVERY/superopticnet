<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function portal_login_security_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

$path = app_security_event_log_path();
$before = file_exists($path) ? (string) file_get_contents($path) : '';

$success = app_record_security_event([
    'event' => 'smoke_login_security_success',
    'status' => 'success',
    'role' => 'employee',
    'login' => 'smoke.security',
    'meta' => ['source' => 'portal-login-security-smoke'],
]);
$failure = app_record_security_event([
    'event' => 'smoke_login_security_failed',
    'status' => 'warning',
    'role' => 'employee',
    'login' => 'smoke.security',
    'meta' => ['source' => 'portal-login-security-smoke'],
]);

if (!$success || !$failure) {
    portal_login_security_fail('Unable to append security events', 2);
}

$after = file_exists($path) ? (string) file_get_contents($path) : '';
if (!str_contains($after, '"event":"smoke_login_security_success"') || !str_contains($after, '"event":"smoke_login_security_failed"')) {
    portal_login_security_fail('Security events were not persisted correctly', 3);
}

echo json_encode([
    'status' => 'ok',
    'checks' => [
        'log_file_exists' => file_exists($path),
        'success_event_written' => str_contains($after, '"event":"smoke_login_security_success"'),
        'failure_event_written' => str_contains($after, '"event":"smoke_login_security_failed"'),
        'log_grew' => strlen($after) >= strlen($before),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
