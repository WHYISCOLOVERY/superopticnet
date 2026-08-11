<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/config.php';

$options = getopt('', ['create', 'delete', 'login::', 'password::']);
$login = trim((string) ($options['login'] ?? 'visual.smoke@local'));
$password = (string) ($options['password'] ?? 'VisualSmoke123!');
$create = array_key_exists('create', $options);
$delete = array_key_exists('delete', $options);

if (($create ? 1 : 0) + ($delete ? 1 : 0) !== 1 || !str_ends_with($login, '@local')) {
    fwrite(STDERR, "usage: --create|--delete [--login=visual.smoke@local] [--password=...]\n");
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

if ($delete) {
    $account = app_db_account_by_login($login);
    if (!is_array($account)) {
        echo json_encode(['status' => 'absent', 'login' => $login], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    $accountId = (int) ($account['id'] ?? 0);
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM portal_account_areas WHERE account_id=:account_id')->execute(['account_id' => $accountId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id=:account_id AND login=:login')->execute([
            'account_id' => $accountId,
            'login' => $login,
        ]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }

    echo json_encode(['status' => 'deleted', 'login' => $login], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$saved = app_db_upsert_account([
    'login' => $login,
    'password' => $password,
    'role' => 'employee',
    'account_type' => 'admin',
    'employee_role' => 'super_admin',
    'full_name' => 'Visual Smoke Super Admin',
    'status' => 'Aktiv',
    'is_active' => 1,
    'payload' => ['test_account' => true, 'purpose' => 'visual_smoke'],
], false);

$verified = is_array($saved) && is_array(app_db_login('employee', $login, $password));
echo json_encode([
    'status' => $verified ? 'created' : 'failed',
    'login' => $login,
    'account_id' => (int) ($saved['id'] ?? 0),
    'verified' => $verified,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($verified ? 0 : 1);
