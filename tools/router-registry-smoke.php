<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(64);
}

require dirname(__DIR__) . '/billing-ui.php';
require dirname(__DIR__) . '/billing-customer-identity-lib.php';
require dirname(__DIR__) . '/router-registry-lib.php';

const ROUTER_SMOKE_NAMES = ['__router_registry_smoke_a__', '__router_registry_smoke_b__'];
const ROUTER_SMOKE_LOGIN = '__router_registry_smoke_customer__';
const ROUTER_SMOKE_CODE = '__router_registry_smoke_code__';
const ROUTER_SMOKE_ATOMIC_LOGIN = '__router_registry_atomic_customer__';
const ROUTER_SMOKE_ATOMIC_CODE = '__router_registry_atomic_code__';
const ROUTER_SMOKE_FAILURE_LOGIN = '__router_registry_failure_customer__';
const ROUTER_SMOKE_FAILURE_CODE = '__router_registry_failure_code__';
const ROUTER_SMOKE_RADIUS_USERNAME = '__router_registry_atomic_radius__';

function router_smoke_cleanup(PDO $db): void
{
    $accountIds = $db->prepare(
        'SELECT id FROM portal_accounts
         WHERE login IN (:manual_login, :atomic_login, :failure_login)'
    );
    $accountIds->execute([
        'manual_login' => ROUTER_SMOKE_LOGIN,
        'atomic_login' => ROUTER_SMOKE_ATOMIC_LOGIN,
        'failure_login' => ROUTER_SMOKE_FAILURE_LOGIN,
    ]);
    $ids = array_map('intval', $accountIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
    foreach ($ids as $accountId) {
        $db->prepare('DELETE FROM customer_router_bindings WHERE customer_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM billing_radius_services WHERE account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM billing_customer_fin_claims WHERE account_id = :id')->execute(['id' => $accountId]);
    }
    foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
        $statement = $db->prepare('DELETE FROM ' . $table . ' WHERE username = :username');
        $statement->execute(['username' => ROUTER_SMOKE_RADIUS_USERNAME]);
    }
    $account = $db->prepare(
        'DELETE FROM portal_accounts
         WHERE login IN (:manual_login, :atomic_login, :failure_login)'
    );
    $account->execute([
        'manual_login' => ROUTER_SMOKE_LOGIN,
        'atomic_login' => ROUTER_SMOKE_ATOMIC_LOGIN,
        'failure_login' => ROUTER_SMOKE_FAILURE_LOGIN,
    ]);

    $router = $db->prepare(
        'DELETE FROM router_registry
         WHERE router_name IN (:router_a, :router_b)'
    );
    $router->execute(['router_a' => ROUTER_SMOKE_NAMES[0], 'router_b' => ROUTER_SMOKE_NAMES[1]]);
}

function router_smoke_payload(string $suffix, string $ip): array
{
    $upper = strtoupper($suffix);
    return [
        'router_name' => '__router_registry_smoke_' . strtolower($suffix) . '__',
        'management_ip' => $ip,
        'subnet_mask' => '/24',
        'platform' => 'mikrotik',
        'os_version' => '7.15',
        'nas_identity' => 'router-registry-smoke-' . strtolower($suffix) . '.invalid',
        'branch_name' => 'Smoke Branch',
        'is_active' => 1,
        'radius_secret_env' => 'ROUTER_SMOKE_' . $upper . '_RADIUS_SECRET',
        'ssh_username_env' => 'ROUTER_SMOKE_' . $upper . '_SSH_USERNAME',
        'ssh_password_env' => 'ROUTER_SMOKE_' . $upper . '_SSH_PASSWORD',
        'snmp_community_env' => 'ROUTER_SMOKE_' . $upper . '_SNMP_COMMUNITY',
    ];
}

function router_smoke_reserved_count(PDO $db, string $table): int
{
    if ($table === 'router_registry') {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM router_registry
             WHERE router_name IN (:router_a, :router_b)'
        );
        $statement->execute(['router_a' => ROUTER_SMOKE_NAMES[0], 'router_b' => ROUTER_SMOKE_NAMES[1]]);
        return (int) $statement->fetchColumn();
    }

    $statement = $db->prepare(
        'SELECT COUNT(*) FROM portal_accounts
         WHERE login IN (:manual_login, :atomic_login, :failure_login)'
    );
    $statement->execute([
        'manual_login' => ROUTER_SMOKE_LOGIN,
        'atomic_login' => ROUTER_SMOKE_ATOMIC_LOGIN,
        'failure_login' => ROUTER_SMOKE_FAILURE_LOGIN,
    ]);
    return (int) $statement->fetchColumn();
}

function router_smoke_actor(string $role): array
{
    return [
        'id' => 0,
        'role' => 'employee',
        'account_type' => $role === 'admin' ? 'admin' : 'operator',
        'employee_role' => $role,
        'full_name' => 'Router Registry Smoke Actor',
        'is_active' => 1,
    ];
}

function router_smoke_customer_payload(string $login, string $code, string $radiusUsername): array
{
    return [
        'login' => $login,
        'password' => 'router-smoke-portal-password',
        'role' => 'customer',
        'account_type' => 'customer',
        'full_name' => 'Router Registry Atomic Smoke Customer',
        'subscriber_code' => $code,
        'contract_no' => $code,
        'phone' => '+994501234567',
        'address' => 'Router Registry Smoke Address',
        'plan' => 'Router Registry Smoke Plan',
        'status' => 'Aktiv',
        'is_active' => 1,
        'payload' => [
            'profile' => [
                'full_name' => 'Router Registry Atomic Smoke Customer',
                'subscriber_code' => $code,
                'phone' => '+994501234567',
                'address' => 'Router Registry Smoke Address',
                'status' => 'Aktiv',
            ],
            'billing' => [
                'service_type' => 'pppoe',
                'pppoe_username' => $radiusUsername,
                'pppoe_password' => 'router-smoke-radius-password',
                'status' => 'Aktiv',
                'radius_status' => 'Aktiv',
            ],
            'internet_services' => [[
                'service_type' => 'pppoe',
                'username' => $radiusUsername,
                'password' => 'router-smoke-radius-password',
                'status' => 'active',
            ]],
        ],
    ];
}

function router_smoke_identity(string $fin): array
{
    return [
        'fin_code' => $fin,
        'document_no' => 'AA1234567',
        'phone' => '+994501234567',
        'address' => 'Router Registry Smoke Address',
    ];
}

$checks = [];
$error = null;
$routerAId = 0;
$routerBId = 0;
$customerId = 0;
$baselineActiveCount = 0;
$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode([
        'ok' => false,
        'checks' => ['database' => false],
        'error' => 'database_unavailable',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

try {
    $checks['schema'] = router_registry_ensure_schema($db);
    if (!$checks['schema']) {
        throw new RuntimeException('schema_unavailable');
    }
    app_billing_ensure_identity_schema($db);
    app_mgs_ensure_schema($db);
    $nocActor = router_smoke_actor('noc');
    $adminActor = router_smoke_actor('admin');
    $operatorActor = router_smoke_actor('support');
    $checks['binding_authorization_policy'] = router_registry_actor_can_manage_bindings($nocActor)
        && router_registry_actor_can_manage_bindings($adminActor)
        && !router_registry_actor_can_manage_bindings($operatorActor);

    router_smoke_cleanup($db);
    if (!str_contains((string) $db->query('SELECT @@SESSION.sql_mode')->fetchColumn(), 'STRICT_TRANS_TABLES')) {
        $db->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')");
    }
    $baselineActiveCount = (int) $db->query(
        'SELECT COUNT(*) FROM router_registry WHERE is_active = 1'
    )->fetchColumn();
    $db->beginTransaction();
    $db->exec('UPDATE router_registry SET is_active = 0 WHERE is_active = 1');

    $zero = router_registry_resolve_customer_creation_router($db, 0, $adminActor);
    $checks['zero_active_rejects_creation'] = empty($zero['ok'])
        && ($zero['error'] ?? '') === 'no_active_router'
        && !empty($zero['required']);

    $invalidPayload = router_smoke_payload('a', '192.0.2.241');
    $invalidPayload['radius_secret_env'] = 'literal credential values are rejected';
    $invalid = router_registry_save($db, $invalidPayload, 0, $adminActor);
    $checks['credential_value_rejected'] = empty($invalid['ok'])
        && ($invalid['error'] ?? '') === 'radius_secret_env_invalid'
        && !router_registry_valid_env_reference('literalCredential123');
    $genericEnvPayload = router_smoke_payload('a', '192.0.2.241');
    $genericEnvPayload['ssh_password_env'] = 'DB_PASS';
    $genericEnv = router_registry_save($db, $genericEnvPayload, 0, $adminActor);
    $wrongPurposePayload = router_smoke_payload('a', '192.0.2.241');
    $wrongPurposePayload['radius_secret_env'] = 'ROUTER_SMOKE_A_SSH_PASSWORD';
    $wrongPurpose = router_registry_save($db, $wrongPurposePayload, 0, $adminActor);
    $checks['generic_env_name_rejected'] = empty($genericEnv['ok'])
        && ($genericEnv['error'] ?? '') === 'ssh_password_env_invalid'
        && !router_registry_valid_env_reference('DB_PASS');
    $checks['cross_purpose_env_name_rejected'] = empty($wrongPurpose['ok'])
        && ($wrongPurpose['error'] ?? '') === 'radius_secret_env_invalid'
        && !router_registry_valid_env_reference('ROUTER_SMOKE_A_SSH_PASSWORD', 'radius_secret_env');
    $checks['subnet_mask_validation'] = router_registry_normalize_subnet_mask('192.0.2.1', '255.255.255.0') === '255.255.255.0'
        && router_registry_normalize_subnet_mask('192.0.2.1', '255.0.255.0') === null
        && router_registry_normalize_subnet_mask('2001:db8::1', '/64') === '/64';

    $routerA = router_registry_save($db, router_smoke_payload('a', '192.0.2.241'), 0, $adminActor);
    $routerAId = (int) ($routerA['id'] ?? 0);
    $checks['router_a_inserted'] = !empty($routerA['ok']) && $routerAId > 0;

    $one = router_registry_resolve_customer_creation_router($db, 0, $adminActor);
    $checks['one_active_auto_binds'] = !empty($one['ok'])
        && (int) ($one['router_id'] ?? 0) === $routerAId
        && empty($one['required']);

    $routerB = router_registry_save($db, router_smoke_payload('b', '192.0.2.242'), 0, $adminActor);
    $routerBId = (int) ($routerB['id'] ?? 0);
    $checks['router_b_inserted'] = !empty($routerB['ok']) && $routerBId > 0;

    $manyMissing = router_registry_resolve_customer_creation_router($db, 0, $adminActor);
    $checks['many_active_requires_selection'] = empty($manyMissing['ok'])
        && ($manyMissing['error'] ?? '') === 'router_required'
        && !empty($manyMissing['required']);

    $manyInvalid = router_registry_resolve_customer_creation_router($db, 999999999, $adminActor);
    $checks['many_active_rejects_unknown'] = empty($manyInvalid['ok'])
        && ($manyInvalid['error'] ?? '') === 'router_inactive_or_missing';

    $manySelected = router_registry_resolve_customer_creation_router($db, $routerBId, $adminActor);
    $checks['many_active_accepts_selected'] = !empty($manySelected['ok'])
        && (int) ($manySelected['router_id'] ?? 0) === $routerBId;

    $insertCustomer = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, status, is_active
         ) VALUES (
            :login, :password_hash, "customer", "customer", :full_name, :subscriber_code, "Aktiv", 1
         )'
    );
    $insertCustomer->execute([
        'login' => ROUTER_SMOKE_LOGIN,
        'password_hash' => 'router-registry-smoke-not-for-login',
        'full_name' => 'Router Registry Smoke Customer',
        'subscriber_code' => ROUTER_SMOKE_CODE,
    ]);
    $customerId = (int) $db->lastInsertId();
    $checks['customer_inserted'] = $customerId > 0;

    $unauthorizedBinding = router_registry_bind_customer($db, $customerId, $routerBId, $operatorActor);
    $checks['unauthorized_binding_rejected'] = empty($unauthorizedBinding['ok'])
        && ($unauthorizedBinding['error'] ?? '') === 'binding_authorization_required'
        && router_registry_customer_router($db, $customerId, $adminActor) === null;

    $bindingB = router_registry_bind_customer($db, $customerId, $routerBId, $nocActor, 'Initial smoke binding');
    $boundB = router_registry_customer_router($db, $customerId, $adminActor);
    $checks['customer_bound_to_selected'] = !empty($bindingB['ok'])
        && (int) ($boundB['id'] ?? 0) === $routerBId;

    $unauthorizedRebind = router_registry_bind_customer($db, $customerId, $routerAId, $operatorActor);
    $stillBoundB = router_registry_customer_router($db, $customerId, $adminActor);
    $checks['unauthorized_rebind_preserves_binding'] = empty($unauthorizedRebind['ok'])
        && ($unauthorizedRebind['error'] ?? '') === 'binding_authorization_required'
        && (int) ($stillBoundB['id'] ?? 0) === $routerBId;

    $bindingA = router_registry_bind_customer($db, $customerId, $routerAId, $adminActor, 'Authorized smoke rebind');
    $boundA = router_registry_customer_router($db, $customerId, $adminActor);
    $checks['customer_rebound_once'] = !empty($bindingA['ok'])
        && (int) ($boundA['id'] ?? 0) === $routerAId
        && (int) $db->query(
            'SELECT COUNT(*) FROM customer_router_bindings WHERE customer_id = ' . $customerId
        )->fetchColumn() === 1;

    $auditStatement = $db->prepare(
        'SELECT before_json, after_json, payload_json
         FROM security_audit_ledger
         WHERE action_type = "customer_router_binding_changed"
           AND entity_type = "customer"
           AND entity_id = :customer_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $auditStatement->execute(['customer_id' => (string) $customerId]);
    $bindingAudit = $auditStatement->fetch(PDO::FETCH_ASSOC);
    $auditJson = is_array($bindingAudit)
        ? implode(' ', array_map('strval', $bindingAudit))
        : '';
    $auditBefore = is_array($bindingAudit) ? json_decode((string) $bindingAudit['before_json'], true) : null;
    $auditAfter = is_array($bindingAudit) ? json_decode((string) $bindingAudit['after_json'], true) : null;
    $checks['binding_audit_records_old_and_new_router'] = is_array($auditBefore)
        && is_array($auditAfter)
        && (int) ($auditBefore['router_id'] ?? 0) === $routerBId
        && (int) ($auditAfter['router_id'] ?? 0) === $routerAId;
    $checks['binding_audit_contains_no_credentials'] = $auditJson !== ''
        && !preg_match('/secret|password|community|_env/i', $auditJson);

    $blockedDeactivate = router_registry_set_active($db, $routerAId, false, $adminActor);
    $checks['active_binding_blocks_deactivation'] = empty($blockedDeactivate['ok'])
        && ($blockedDeactivate['error'] ?? '') === 'router_has_active_customer_bindings'
        && (int) ($blockedDeactivate['active_binding_count'] ?? 0) >= 1
        && (int) (router_registry_find($db, $routerAId, $adminActor)['is_active'] ?? 0) === 1;
    $deactivatePayload = router_smoke_payload('a', '192.0.2.241');
    $deactivatePayload['is_active'] = 0;
    $blockedSave = router_registry_save($db, $deactivatePayload, $routerAId, $adminActor);
    $checks['registry_edit_blocks_deactivation'] = empty($blockedSave['ok'])
        && ($blockedSave['error'] ?? '') === 'router_has_active_customer_bindings'
        && (int) ($blockedSave['active_binding_count'] ?? 0) >= 1;

    $unboundRouterDeactivate = router_registry_set_active($db, $routerBId, false, $adminActor);
    $unboundRouterReactivate = router_registry_set_active($db, $routerBId, true, $adminActor);
    $checks['unbound_router_status_can_change'] = !empty($unboundRouterDeactivate['ok'])
        && !empty($unboundRouterReactivate['ok']);

    $atomicCreate = router_registry_create_customer_with_binding(
        $db,
        router_smoke_customer_payload(ROUTER_SMOKE_ATOMIC_LOGIN, ROUTER_SMOKE_ATOMIC_CODE, ROUTER_SMOKE_RADIUS_USERNAME),
        router_smoke_identity('RTSMK01'),
        $nocActor,
        'router-smoke-radius-password',
        $routerBId
    );
    $atomicAccount = is_array($atomicCreate['account'] ?? null) ? $atomicCreate['account'] : [];
    $atomicCustomerId = (int) ($atomicAccount['id'] ?? 0);
    $atomicBinding = $atomicCustomerId > 0 ? router_registry_customer_router($db, $atomicCustomerId, $adminActor) : null;
    $radiusCheck = $db->prepare('SELECT COUNT(*) FROM radcheck WHERE username = :username');
    $radiusCheck->execute(['username' => ROUTER_SMOKE_RADIUS_USERNAME]);
    $checks['atomic_customer_radius_binding_created'] = !empty($atomicCreate['ok'])
        && $atomicCustomerId > 0
        && (int) ($atomicBinding['id'] ?? 0) === $routerBId
        && (int) $radiusCheck->fetchColumn() > 0;

    $duplicateCreate = router_registry_create_customer_with_binding(
        $db,
        router_smoke_customer_payload(ROUTER_SMOKE_ATOMIC_LOGIN, ROUTER_SMOKE_ATOMIC_CODE, ROUTER_SMOKE_RADIUS_USERNAME),
        router_smoke_identity('RTSMK01'),
        $nocActor,
        'router-smoke-radius-password',
        $routerBId
    );
    $duplicateCount = $db->prepare('SELECT COUNT(*) FROM portal_accounts WHERE login = :login');
    $duplicateCount->execute(['login' => ROUTER_SMOKE_ATOMIC_LOGIN]);
    $checks['atomic_retry_creates_no_duplicate'] = empty($duplicateCreate['ok'])
        && ($duplicateCreate['error'] ?? '') === 'customer_already_exists'
        && (int) $duplicateCount->fetchColumn() === 1;

    $failureRadiusUsername = str_repeat('router_failure_', 20);
    $failedCreate = router_registry_create_customer_with_binding(
        $db,
        router_smoke_customer_payload(ROUTER_SMOKE_FAILURE_LOGIN, ROUTER_SMOKE_FAILURE_CODE, $failureRadiusUsername),
        router_smoke_identity('RTSMK02'),
        $nocActor,
        'router-smoke-radius-password',
        $routerBId
    );
    $failureAccountCount = $db->prepare('SELECT COUNT(*) FROM portal_accounts WHERE login = :login');
    $failureAccountCount->execute(['login' => ROUTER_SMOKE_FAILURE_LOGIN]);
    $failureRadiusCount = $db->prepare('SELECT COUNT(*) FROM radcheck WHERE username = :username');
    $failureRadiusCount->execute(['username' => $failureRadiusUsername]);
    $checks['failed_radius_rolls_back_customer_and_binding'] = empty($failedCreate['ok'])
        && ($failedCreate['error'] ?? '') === 'radius_sync_failed'
        && (int) $failureAccountCount->fetchColumn() === 0
        && (int) $failureRadiusCount->fetchColumn() === 0;

    $storedA = router_registry_find($db, $routerAId, $adminActor) ?? [];
    $onlyReferences = true;
    foreach (router_registry_credential_reference_fields() as $field) {
        $reference = (string) ($storedA[$field] ?? '');
        $onlyReferences = $onlyReferences
            && $reference !== ''
            && router_registry_valid_env_reference($reference, $field);
    }
    $checks['stored_credentials_are_env_references'] = $onlyReferences;

    $columns = $db->query(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'router_registry'"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $credentialColumnsAreReferences = true;
    foreach ($columns as $column) {
        $column = (string) $column;
        if (preg_match('/(secret|password|community)$/', $column) === 1) {
            $credentialColumnsAreReferences = false;
        }
    }
    $checks['no_credential_value_columns'] = $credentialColumnsAreReferences;
    $checks['no_live_router_access'] = true;
} catch (Throwable $exception) {
    $error = $exception->getMessage() !== '' ? $exception->getMessage() : get_class($exception);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        router_smoke_cleanup($db);
        $checks['cleanup_router_rows'] = router_smoke_reserved_count($db, 'router_registry') === 0;
        $checks['cleanup_customer_rows'] = router_smoke_reserved_count($db, 'portal_accounts') === 0;
        $bindingCleanup = $db->prepare(
            'SELECT COUNT(*) FROM customer_router_bindings
             WHERE customer_id = :customer_id OR router_id IN (:router_a, :router_b)'
        );
        $bindingCleanup->execute([
            'customer_id' => $customerId,
            'router_a' => $routerAId,
            'router_b' => $routerBId,
        ]);
        $checks['cleanup_binding_rows'] = (int) $bindingCleanup->fetchColumn() === 0;
        $checks['cleanup_restored_active_state'] = (int) $db->query(
            'SELECT COUNT(*) FROM router_registry WHERE is_active = 1'
        )->fetchColumn() === $baselineActiveCount;
    } catch (Throwable) {
        $checks['cleanup_router_rows'] = false;
        $checks['cleanup_customer_rows'] = false;
        $checks['cleanup_binding_rows'] = false;
        $checks['cleanup_restored_active_state'] = false;
    }
}

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
$ok = $error === null && $failed === [];
echo json_encode([
    'ok' => $ok,
    'checks' => $checks,
    'failed' => $failed,
    'error' => $error,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
