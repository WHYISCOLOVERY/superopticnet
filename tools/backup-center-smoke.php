<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function backup_center_smoke_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO) {
    backup_center_smoke_fail('Database unavailable', 2);
}

$employee = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'employee' AND is_active = 1
     ORDER BY FIELD(account_type, 'admin', 'operator', 'technician') ASC, id
     LIMIT 1"
)?->fetch();

if (!is_array($employee)) {
    backup_center_smoke_fail('No active employee', 3);
}

$_GET = ['section' => 'reports', 'focus' => 'backup'];
$_POST = [];
$_REQUEST = $_GET;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/superopticnet/operations-center.php?section=reports&focus=backup';
$_SERVER['SCRIPT_NAME'] = '/superopticnet/operations-center.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

app_session_boot();
$_SESSION['portal_user'] = app_db_user_session_payload($employee);

chdir(dirname(__DIR__));
ob_start();
include dirname(__DIR__) . '/operations-center.php';
$html = (string) ob_get_clean();

$expectedMarkers = [
    'name="action" value="backup_now"',
    'name="action" value="backup_telegram"',
    'name="action" value="install_weekly_backup_task"',
    'name="action" value="restore_verify"',
    'name="action" value="restore_execute"',
    'name="action" value="check_update_channel"',
    'name="action" value="verify_update_package"',
    'name="action" value="apply_update_package"',
    'data-update-package-picker',
    'data-update-package-card',
    'data-update-package-upload',
    'spog-update-forms',
    'type="file" name="update_package_file"',
    'data-update-package-path',
    'Fayl sec',
    'Son restore pointler',
    'spog-backup-quick-summary',
    'Apply axini evvelce avtomatik backup yaradir',
];

foreach ($expectedMarkers as $marker) {
    if (!str_contains($html, $marker)) {
        backup_center_smoke_fail('operations-center.php missing backup marker: ' . $marker, 4);
    }
}

echo json_encode([
    'status' => 'ok',
    'markers_checked' => count($expectedMarkers),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
