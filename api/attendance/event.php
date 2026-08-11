<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/attendance-api-lib.php';

if (function_exists('app_send_security_headers')) {
    app_send_security_headers();
}
header('Content-Type: application/json; charset=UTF-8');

$respond = static function (array $payload, int $status): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$auth = app_attendance_api_authenticate();
if (empty($auth['ok'])) {
    $respond(['ok' => false, 'error' => 'unauthorized'], 401);
}

$rawBody = (string) file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    // Terminallar form-encoded da göndərə bilər.
    $payload = $_POST ?: [];
}

$result = app_attendance_api_record($payload, (string) ($auth['device_slot'] ?? 'device'));
$respond($result, !empty($result['ok']) ? 200 : 422);
