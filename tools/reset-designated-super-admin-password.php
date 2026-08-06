<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/audit-log-lib.php';

const DESIGNATED_SUPER_ADMIN_LOGIN = 's.abdullayev@superoptic.net';

$password = (string) getenv('RESET_SUPER_ADMIN_PASSWORD');
$strongPassword = strlen($password) >= 14
    && preg_match('/[a-z]/', $password) === 1
    && preg_match('/[A-Z]/', $password) === 1
    && preg_match('/\d/', $password) === 1
    && preg_match('/[^A-Za-z0-9]/', $password) === 1;
if (!$strongPassword) {
    fwrite(STDERR, "reset_super_admin_password_policy_failed\n");
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database_unavailable\n");
    exit(3);
}

$account = app_db_account_by_login(DESIGNATED_SUPER_ADMIN_LOGIN);
if (!is_array($account)
    || (string) ($account['role'] ?? '') !== 'employee'
    || (string) ($account['account_type'] ?? '') !== 'admin'
    || (string) ($account['employee_role'] ?? '') !== 'super_admin'
) {
    fwrite(STDERR, "designated_super_admin_unavailable\n");
    exit(4);
}

app_audit_ensure_schema($db);
$payload = app_db_account_payload($account);
$security = is_array($payload['security'] ?? null) ? $payload['security'] : [];
$previousMustChangePassword = !empty($security['must_change_password']);
$security['must_change_password'] = true;
$security['password_reset_at'] = date(DATE_ATOM);
$security['password_reset_origin'] = 'local_cli';
$payload['security'] = $security;

try {
    $db->beginTransaction();
    $statement = $db->prepare(
        'UPDATE portal_accounts
            SET password_hash = :password_hash,
                payload_json = :payload_json,
                is_active = 1,
                status = :status
          WHERE id = :id
            AND login = :login
            AND role = :role
            AND account_type = :account_type
            AND employee_role = :employee_role'
    );
    $statement->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'payload_json' => app_db_json_encode($payload),
        'status' => 'Aktiv',
        'id' => (int) $account['id'],
        'login' => DESIGNATED_SUPER_ADMIN_LOGIN,
        'role' => 'employee',
        'account_type' => 'admin',
        'employee_role' => 'super_admin',
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('super_admin_password_update_failed');
    }

    app_audit_record($db, [
        'module_key' => 'security',
        'operation_class' => 'credential_recovery',
        'action_type' => 'designated_super_admin_password_reset',
        'entity_type' => 'portal_account',
        'entity_id' => (string) $account['id'],
        'status' => 'executed',
        'actor_name' => 'Local security bootstrap',
        'summary' => 'Designated Super Admin password reset',
        'reason' => 'Owner-requested local access recovery before Ubuntu deployment',
        'before' => [
            'login' => DESIGNATED_SUPER_ADMIN_LOGIN,
            'is_active' => (int) ($account['is_active'] ?? 0),
            'must_change_password' => $previousMustChangePassword,
        ],
        'after' => [
            'login' => DESIGNATED_SUPER_ADMIN_LOGIN,
            'is_active' => 1,
            'must_change_password' => true,
        ],
        'sensitivity' => 'critical',
    ]);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "super_admin_password_reset_failed\n");
    exit(5);
}

$authenticated = app_db_login('employee', DESIGNATED_SUPER_ADMIN_LOGIN, $password);
$ok = is_array($authenticated)
    && (string) ($authenticated['employee_role'] ?? '') === 'super_admin'
    && !empty($authenticated['must_change_password']);

echo json_encode([
    'ok' => $ok,
    'login' => $ok ? DESIGNATED_SUPER_ADMIN_LOGIN : null,
    'must_change_password' => $ok,
    'credentials_exposed' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 6);
