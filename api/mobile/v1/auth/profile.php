<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    app_mobile_json_response(null, 405, 'Yalnız GET sorğusu dəstəklənir.');
}

$user = app_mobile_authenticate();
app_mobile_json_response([
    'id' => (int) $user['id'],
    'role' => (string) $user['role'],
    'employee_role' => (string) ($user['employee_role'] ?: $user['role']),
    'full_name' => (string) $user['full_name'],
    'job_title' => (string) ($user['job_title'] ?? ''),
    'capabilities' => app_mobile_account_capabilities($user),
], 200);
