<?php
declare(strict_types=1);

require_once __DIR__ . '/audit-log-lib.php';
require_once __DIR__ . '/noc-authorization-lib.php';

function router_registry_credential_reference_fields(): array
{
    return [
        'radius_secret_env',
        'ssh_username_env',
        'ssh_password_env',
        'snmp_community_env',
    ];
}

function router_registry_ensure_schema(PDO $db): bool
{
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS router_registry (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                router_name VARCHAR(191) NOT NULL,
                management_ip VARCHAR(45) NOT NULL,
                subnet_mask VARCHAR(45) NOT NULL,
                platform VARCHAR(64) NOT NULL,
                os_version VARCHAR(64) DEFAULT NULL,
                nas_identity VARCHAR(128) NOT NULL,
                branch_name VARCHAR(128) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                radius_secret_env VARCHAR(128) DEFAULT NULL,
                ssh_username_env VARCHAR(128) DEFAULT NULL,
                ssh_password_env VARCHAR(128) DEFAULT NULL,
                snmp_community_env VARCHAR(128) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_router_registry_management_ip (management_ip),
                UNIQUE KEY uq_router_registry_nas_identity (nas_identity),
                KEY idx_router_registry_active_name (is_active, router_name),
                KEY idx_router_registry_branch (branch_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS customer_router_bindings (
                customer_id BIGINT UNSIGNED NOT NULL,
                router_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (customer_id),
                KEY idx_customer_router_bindings_router (router_id),
                CONSTRAINT fk_customer_router_bindings_customer
                    FOREIGN KEY (customer_id) REFERENCES portal_accounts(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_customer_router_bindings_router
                    FOREIGN KEY (router_id) REFERENCES router_registry(id)
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        return true;
    } catch (Throwable) {
        return false;
    }
}

function router_registry_normalize_subnet_mask(string $managementIp, string $subnetMask): ?string
{
    $subnetMask = trim($subnetMask);
    if ($subnetMask === '') {
        return null;
    }

    $isIpv4 = filter_var($managementIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    $maxPrefix = $isIpv4 ? 32 : 128;
    if (preg_match('/^\/?([0-9]{1,3})$/', $subnetMask, $matches) === 1) {
        $prefix = (int) $matches[1];
        return $prefix <= $maxPrefix ? '/' . $prefix : null;
    }

    if (!$isIpv4 || filter_var($subnetMask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return null;
    }

    $bits = '';
    foreach (explode('.', $subnetMask) as $octet) {
        $bits .= str_pad(decbin((int) $octet), 8, '0', STR_PAD_LEFT);
    }

    return preg_match('/^1*0*$/', $bits) === 1 ? $subnetMask : null;
}

function router_registry_env_reference_suffixes(): array
{
    return [
        'radius_secret_env' => 'RADIUS_SECRET',
        'ssh_username_env' => 'SSH_USERNAME',
        'ssh_password_env' => 'SSH_PASSWORD',
        'snmp_community_env' => 'SNMP_COMMUNITY',
    ];
}

function router_registry_valid_env_reference(string $reference, ?string $field = null): bool
{
    if ($reference === '') {
        return true;
    }
    if (strlen($reference) > 128) {
        return false;
    }

    $suffixes = router_registry_env_reference_suffixes();
    $allowedSuffixes = $field !== null && array_key_exists($field, $suffixes)
        ? [$suffixes[$field]]
        : array_values($suffixes);
    foreach ($allowedSuffixes as $suffix) {
        if (preg_match('/^ROUTER_[A-Z0-9]+(?:_[A-Z0-9]+)*_' . preg_quote($suffix, '/') . '$/', $reference) === 1) {
            return true;
        }
    }

    return false;
}

function router_registry_validate(array $input): array
{
    $managementIp = trim((string) ($input['management_ip'] ?? ''));
    $subnetMask = router_registry_normalize_subnet_mask(
        $managementIp,
        (string) ($input['subnet_mask'] ?? '')
    );
    $data = [
        'router_name' => trim((string) ($input['router_name'] ?? '')),
        'management_ip' => $managementIp,
        'subnet_mask' => $subnetMask ?? '',
        'platform' => trim((string) ($input['platform'] ?? '')),
        'os_version' => trim((string) ($input['os_version'] ?? '')),
        'nas_identity' => trim((string) ($input['nas_identity'] ?? '')),
        'branch_name' => trim((string) ($input['branch_name'] ?? '')),
        'is_active' => !empty($input['is_active']) ? 1 : 0,
    ];
    foreach (router_registry_credential_reference_fields() as $field) {
        $data[$field] = trim((string) ($input[$field] ?? ''));
    }

    $requiredLimits = [
        'router_name' => 191,
        'management_ip' => 45,
        'subnet_mask' => 45,
        'platform' => 64,
        'nas_identity' => 128,
    ];
    foreach ($requiredLimits as $field => $limit) {
        if ($data[$field] === '') {
            return ['ok' => false, 'error' => $field . '_required', 'data' => $data];
        }
        if (strlen($data[$field]) > $limit) {
            return ['ok' => false, 'error' => $field . '_too_long', 'data' => $data];
        }
    }

    if (filter_var($managementIp, FILTER_VALIDATE_IP) === false) {
        return ['ok' => false, 'error' => 'management_ip_invalid', 'data' => $data];
    }
    if ($subnetMask === null) {
        return ['ok' => false, 'error' => 'subnet_mask_invalid', 'data' => $data];
    }

    foreach (['os_version' => 64, 'branch_name' => 128] as $field => $limit) {
        if (strlen($data[$field]) > $limit) {
            return ['ok' => false, 'error' => $field . '_too_long', 'data' => $data];
        }
    }
    foreach (router_registry_credential_reference_fields() as $field) {
        if (!router_registry_valid_env_reference((string) $data[$field], $field)) {
            return ['ok' => false, 'error' => $field . '_invalid', 'data' => $data];
        }
    }

    return ['ok' => true, 'error' => null, 'data' => $data];
}

function router_registry_save(PDO $db, array $input, int $routerId = 0, array $actor = []): array
{
    $authorization = app_noc_authorize($actor, 'manage_router', [], $db);
    if (empty($authorization['ok'])) {
        return ['ok' => false, 'error' => (string) ($authorization['reason'] ?? 'router_authorization_required')];
    }
    $validation = router_registry_validate($input);
    if (empty($validation['ok'])) {
        return $validation;
    }
    $data = (array) $validation['data'];

    $startedTransaction = false;
    try {
        if ($routerId > 0) {
            $startedTransaction = !$db->inTransaction();
            if ($startedTransaction) {
                $db->beginTransaction();
            }
            $exists = $db->prepare('SELECT is_active FROM router_registry WHERE id = :id FOR UPDATE');
            $exists->execute(['id' => $routerId]);
            $currentActive = $exists->fetchColumn();
            if ($currentActive === false) {
                if ($startedTransaction && $db->inTransaction()) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'router_not_found', 'data' => $data];
            }
            if ((int) $currentActive === 1 && (int) $data['is_active'] === 0) {
                $activeBindingCount = router_registry_active_customer_binding_count($db, $routerId);
                if ($activeBindingCount > 0) {
                    if ($startedTransaction && $db->inTransaction()) {
                        $db->rollBack();
                    }
                    return [
                        'ok' => false,
                        'error' => 'router_has_active_customer_bindings',
                        'active_binding_count' => $activeBindingCount,
                        'data' => $data,
                    ];
                }
            }
            $statement = $db->prepare(
                'UPDATE router_registry
                 SET router_name = :router_name,
                     management_ip = :management_ip,
                     subnet_mask = :subnet_mask,
                     platform = :platform,
                     os_version = :os_version,
                     nas_identity = :nas_identity,
                     branch_name = :branch_name,
                     is_active = :is_active,
                     radius_secret_env = :radius_secret_env,
                     ssh_username_env = :ssh_username_env,
                     ssh_password_env = :ssh_password_env,
                     snmp_community_env = :snmp_community_env
                 WHERE id = :id'
            );
            $statement->execute($data + ['id' => $routerId]);
            if ($startedTransaction) {
                $db->commit();
            }
        } else {
            $statement = $db->prepare(
                'INSERT INTO router_registry (
                    router_name, management_ip, subnet_mask, platform, os_version,
                    nas_identity, branch_name, is_active, radius_secret_env,
                    ssh_username_env, ssh_password_env, snmp_community_env
                 ) VALUES (
                    :router_name, :management_ip, :subnet_mask, :platform, :os_version,
                    :nas_identity, :branch_name, :is_active, :radius_secret_env,
                    :ssh_username_env, :ssh_password_env, :snmp_community_env
                 )'
            );
            $statement->execute($data);
            $routerId = (int) $db->lastInsertId();
        }

        return ['ok' => true, 'error' => null, 'id' => $routerId, 'data' => $data];
    } catch (PDOException $exception) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        $error = (string) $exception->getCode() === '23000' ? 'router_conflict' : 'database_error';
        return ['ok' => false, 'error' => $error, 'data' => $data];
    } catch (Throwable) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'database_error', 'data' => $data];
    }
}

function router_registry_active_customer_binding_count(PDO $db, int $routerId): int
{
    if ($routerId <= 0) {
        return 0;
    }
    $statement = $db->prepare(
        'SELECT COUNT(*)
         FROM customer_router_bindings b
         INNER JOIN portal_accounts a ON a.id = b.customer_id
         WHERE b.router_id = :router_id
           AND a.role = "customer"
           AND a.is_active = 1'
    );
    $statement->execute(['router_id' => $routerId]);

    return (int) $statement->fetchColumn();
}

function router_registry_set_active(PDO $db, int $routerId, bool $active, array $actor = []): array
{
    $authorization = app_noc_authorize($actor, 'manage_router', [], $db);
    if (empty($authorization['ok'])) {
        return ['ok' => false, 'error' => (string) ($authorization['reason'] ?? 'router_authorization_required')];
    }
    if ($routerId <= 0) {
        return ['ok' => false, 'error' => 'router_not_found'];
    }

    $startedTransaction = !$db->inTransaction();
    try {
        if ($startedTransaction) {
            $db->beginTransaction();
        }
        $lock = $db->prepare('SELECT is_active FROM router_registry WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $routerId]);
        $currentActive = $lock->fetchColumn();
        if ($currentActive === false) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => 'router_not_found'];
        }

        $desiredActive = $active ? 1 : 0;
        if ((int) $currentActive === $desiredActive) {
            if ($startedTransaction) {
                $db->commit();
            }
            return ['ok' => true, 'error' => null, 'changed' => false, 'id' => $routerId];
        }
        if ($desiredActive === 0) {
            $activeBindingCount = router_registry_active_customer_binding_count($db, $routerId);
            if ($activeBindingCount > 0) {
                if ($startedTransaction && $db->inTransaction()) {
                    $db->rollBack();
                }
                return [
                    'ok' => false,
                    'error' => 'router_has_active_customer_bindings',
                    'active_binding_count' => $activeBindingCount,
                    'id' => $routerId,
                ];
            }
        }

        $statement = $db->prepare('UPDATE router_registry SET is_active = :is_active WHERE id = :id');
        $statement->execute(['is_active' => $desiredActive, 'id' => $routerId]);
        if ($startedTransaction) {
            $db->commit();
        }

        return ['ok' => true, 'error' => null, 'changed' => true, 'id' => $routerId];
    } catch (Throwable) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'database_error', 'id' => $routerId];
    }
}

function router_registry_find(PDO $db, int $routerId, array $actor = []): ?array
{
    if ($routerId <= 0) {
        return null;
    }
    $statement = $db->prepare('SELECT * FROM router_registry WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $routerId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return null;
    }
    $authorization = app_noc_authorize($actor, 'read_router', $row, $db);
    return !empty($authorization['ok']) ? $row : null;
}

function router_registry_list(PDO $db, bool $activeOnly = false, array $actor = []): array
{
    $authorization = app_noc_authorize($actor, 'read_router', [], $db);
    if (empty($authorization['ok'])) {
        return [];
    }
    $sql =
        'SELECT r.*, COALESCE(bindings.customer_count, 0) AS customer_count
         FROM router_registry r
         LEFT JOIN (
             SELECT router_id, COUNT(*) AS customer_count
             FROM customer_router_bindings
             GROUP BY router_id
         ) bindings ON bindings.router_id = r.id';
    if ($activeOnly) {
        $sql .= ' WHERE r.is_active = 1';
    }
    $sql .= ' ORDER BY r.is_active DESC, r.branch_name, r.router_name, r.id';

    return app_noc_filter_objects($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [], $actor, $db);
}

function router_registry_actor_can_manage_bindings(array $actor): bool
{
    return !empty(app_noc_authorize($actor, 'bind_router')['ok']);
}

function router_registry_safe_router_snapshot(?array $router): ?array
{
    if (!is_array($router)) {
        return null;
    }

    return [
        'router_id' => (int) ($router['id'] ?? 0),
        'router_name' => (string) ($router['router_name'] ?? ''),
        'management_ip' => (string) ($router['management_ip'] ?? ''),
        'nas_identity' => (string) ($router['nas_identity'] ?? ''),
        'branch_name' => (string) ($router['branch_name'] ?? ''),
    ];
}

function router_registry_resolve_customer_creation_router(PDO $db, int $selectedRouterId, array $actor = []): array
{
    $routers = router_registry_list($db, true, $actor);
    if ($routers === [] && empty(app_noc_authorize($actor, 'read_router', [], $db)['ok'])) {
        return ['ok' => false, 'error' => 'router_authorization_required', 'required' => true, 'active_count' => 0];
    }
    $count = count($routers);
    if ($count === 0) {
        return ['ok' => false, 'error' => 'no_active_router', 'required' => true, 'active_count' => 0];
    }
    if ($count === 1) {
        return [
            'ok' => true,
            'router_id' => (int) $routers[0]['id'],
            'required' => false,
            'active_count' => 1,
        ];
    }
    if ($selectedRouterId <= 0) {
        return ['ok' => false, 'error' => 'router_required', 'required' => true, 'active_count' => $count];
    }
    foreach ($routers as $router) {
        if ((int) ($router['id'] ?? 0) === $selectedRouterId) {
            return [
                'ok' => true,
                'router_id' => $selectedRouterId,
                'required' => true,
                'active_count' => $count,
            ];
        }
    }

    return ['ok' => false, 'error' => 'router_inactive_or_missing', 'required' => true, 'active_count' => $count];
}

function router_registry_lock_customer_creation_router(PDO $db, int $selectedRouterId): array
{
    $columns = 'id, router_name, management_ip, nas_identity, branch_name, is_active';
    if ($selectedRouterId > 0) {
        $statement = $db->prepare(
            'SELECT ' . $columns . '
             FROM router_registry
             WHERE id = :id AND is_active = 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $selectedRouterId]);
        $router = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($router)
            ? ['ok' => true, 'router' => $router, 'router_id' => (int) $router['id']]
            : ['ok' => false, 'error' => 'router_inactive_or_missing'];
    }

    $routers = $db->query(
        'SELECT ' . $columns . '
         FROM router_registry
         WHERE is_active = 1
         ORDER BY id
         LIMIT 2
         FOR UPDATE'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($routers === []) {
        return ['ok' => false, 'error' => 'no_active_router'];
    }
    if (count($routers) > 1) {
        return ['ok' => false, 'error' => 'router_required'];
    }

    return ['ok' => true, 'router' => $routers[0], 'router_id' => (int) $routers[0]['id']];
}

function router_registry_bind_customer(
    PDO $db,
    int $customerId,
    int $routerId,
    array $actor = [],
    string $reason = ''
): array {
    if ($customerId <= 0 || $routerId <= 0) {
        return ['ok' => false, 'error' => 'binding_invalid'];
    }
    if (!router_registry_actor_can_manage_bindings($actor)) {
        return ['ok' => false, 'error' => 'binding_authorization_required'];
    }

    $startedTransaction = !$db->inTransaction();
    try {
        if ($startedTransaction) {
            app_audit_ensure_schema($db);
            $db->beginTransaction();
        }

        $router = $db->prepare(
            'SELECT id, router_name, management_ip, nas_identity, branch_name, is_active
             FROM router_registry
             WHERE id = :id AND is_active = 1
             FOR UPDATE'
        );
        $router->execute(['id' => $routerId]);
        $newRouter = $router->fetch(PDO::FETCH_ASSOC);
        if (!is_array($newRouter)) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => 'router_inactive_or_missing'];
        }
        $authorization = app_noc_authorize($actor, 'bind_router', $newRouter, $db);
        if (empty($authorization['ok'])) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => (string) ($authorization['reason'] ?? 'binding_authorization_required')];
        }

        $customer = $db->prepare('SELECT id, role FROM portal_accounts WHERE id = :id FOR UPDATE');
        $customer->execute(['id' => $customerId]);
        $customerRow = $customer->fetch(PDO::FETCH_ASSOC);
        if (!is_array($customerRow) || (string) ($customerRow['role'] ?? '') !== 'customer') {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => 'customer_not_found'];
        }

        $current = $db->prepare(
            'SELECT r.id, r.router_name, r.management_ip, r.nas_identity, r.branch_name, r.is_active
             FROM customer_router_bindings b
             INNER JOIN router_registry r ON r.id = b.router_id
             WHERE b.customer_id = :customer_id
             FOR UPDATE'
        );
        $current->execute(['customer_id' => $customerId]);
        $oldRouter = $current->fetch(PDO::FETCH_ASSOC);
        if (is_array($oldRouter) && (int) ($oldRouter['id'] ?? 0) === $routerId) {
            if ($startedTransaction) {
                $db->commit();
            }
            return [
                'ok' => true,
                'error' => null,
                'changed' => false,
                'customer_id' => $customerId,
                'router_id' => $routerId,
                'before' => router_registry_safe_router_snapshot($oldRouter),
                'after' => router_registry_safe_router_snapshot($newRouter),
            ];
        }

        $statement = $db->prepare(
            'INSERT INTO customer_router_bindings (customer_id, router_id)
             VALUES (:customer_id, :router_id)
             ON DUPLICATE KEY UPDATE router_id = VALUES(router_id), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute(['customer_id' => $customerId, 'router_id' => $routerId]);

        $before = router_registry_safe_router_snapshot(is_array($oldRouter) ? $oldRouter : null);
        $after = router_registry_safe_router_snapshot($newRouter);
        app_audit_record($db, [
            'module_key' => 'billing',
            'operation_class' => 'customer_router_binding',
            'action_type' => $before === null ? 'customer_router_bound' : 'customer_router_binding_changed',
            'entity_type' => 'customer',
            'entity_id' => (string) $customerId,
            'status' => 'executed',
            'actor_account_id' => function_exists('app_audit_actor_id') ? app_audit_actor_id($actor) : 0,
            'actor_name' => function_exists('app_audit_actor_name') ? app_audit_actor_name($actor) : '',
            'summary' => $before === null ? 'Customer router binding created' : 'Customer router binding changed',
            'reason' => trim($reason) !== '' ? $reason : 'Authorized customer router assignment',
            'before' => $before,
            'after' => $after,
            'payload' => [
                'old_router_id' => (int) ($before['router_id'] ?? 0),
                'new_router_id' => (int) ($after['router_id'] ?? 0),
            ],
            'sensitivity' => 'high',
        ]);

        if ($startedTransaction) {
            $db->commit();
        }

        return [
            'ok' => true,
            'error' => null,
            'changed' => true,
            'customer_id' => $customerId,
            'router_id' => $routerId,
            'before' => $before,
            'after' => $after,
        ];
    } catch (Throwable) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'binding_persistence_failed'];
    }
}

function router_registry_create_customer_with_binding(
    PDO $db,
    array $accountPayload,
    array $identityInput,
    array $actor,
    ?string $radiusPassword,
    int $selectedRouterId
): array {
    if (!router_registry_actor_can_manage_bindings($actor)) {
        return ['ok' => false, 'error' => 'binding_authorization_required'];
    }
    foreach ([
        'app_billing_ensure_identity_schema',
        'app_billing_validate_customer_identity',
        'app_billing_save_customer_identity',
        'app_billing_sync_radius_account',
        'app_db_upsert_account',
    ] as $requiredFunction) {
        if (!function_exists($requiredFunction)) {
            return ['ok' => false, 'error' => 'customer_service_unavailable'];
        }
    }

    $ownsTransaction = !$db->inTransaction();
    $savepoint = '';
    try {
        if ($ownsTransaction) {
            app_billing_ensure_identity_schema($db);
            if (function_exists('app_mgs_ensure_schema')) {
                app_mgs_ensure_schema($db);
            }
            if (!router_registry_ensure_schema($db)) {
                return ['ok' => false, 'error' => 'registry_unavailable'];
            }
            $db->beginTransaction();
        } else {
            $savepoint = 'router_customer_' . bin2hex(random_bytes(6));
            $db->exec('SAVEPOINT ' . $savepoint);
        }
        $routerResolution = router_registry_lock_customer_creation_router($db, $selectedRouterId);
        if (empty($routerResolution['ok'])) {
            throw new RuntimeException((string) ($routerResolution['error'] ?? 'router_inactive_or_missing'));
        }

        $login = trim((string) ($accountPayload['login'] ?? ''));
        $subscriberCode = trim((string) ($accountPayload['subscriber_code'] ?? ''));
        if ($login === '' || $subscriberCode === '' || (int) ($accountPayload['id'] ?? 0) > 0) {
            throw new RuntimeException('account_payload_invalid');
        }
        $duplicate = $db->prepare(
            'SELECT id
             FROM portal_accounts
             WHERE login = :login OR subscriber_code = :subscriber_code
             LIMIT 1
             FOR UPDATE'
        );
        $duplicate->execute(['login' => $login, 'subscriber_code' => $subscriberCode]);
        if ($duplicate->fetchColumn() !== false) {
            throw new RuntimeException('customer_already_exists');
        }

        $identityValidation = app_billing_validate_customer_identity($db, $identityInput, 0, false);
        if (empty($identityValidation['ok'])) {
            throw new RuntimeException((string) ($identityValidation['error'] ?? 'identity_validation_failed'));
        }

        $accountPayload['id'] = 0;
        $accountPayload['role'] = 'customer';
        $accountPayload['account_type'] = 'customer';
        $saved = app_db_upsert_account($accountPayload, false);
        if (!is_array($saved) || (int) ($saved['id'] ?? 0) <= 0) {
            throw new RuntimeException('account_save_failed');
        }
        $customerId = (int) $saved['id'];

        $identity = app_billing_save_customer_identity($db, $customerId, $identityInput, false, $actor);
        if (empty($identity['ok'])) {
            throw new RuntimeException((string) ($identity['error'] ?? 'identity_save_failed'));
        }

        $accountStatement = $db->prepare('SELECT * FROM portal_accounts WHERE id = :id AND role = "customer"');
        $accountStatement->execute(['id' => $customerId]);
        $account = $accountStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account) || !app_billing_sync_radius_account($account, $radiusPassword, $db)) {
            throw new RuntimeException('radius_sync_failed');
        }

        $binding = router_registry_bind_customer(
            $db,
            $customerId,
            (int) $routerResolution['router_id'],
            $actor,
            'Router assigned during atomic customer creation'
        );
        if (empty($binding['ok'])) {
            throw new RuntimeException((string) ($binding['error'] ?? 'binding_persistence_failed'));
        }

        if ($ownsTransaction) {
            $db->commit();
        } else {
            $db->exec('RELEASE SAVEPOINT ' . $savepoint);
        }

        return [
            'ok' => true,
            'error' => null,
            'account' => function_exists('app_db_account_by_id') ? app_db_account_by_id($customerId) : $account,
            'identity' => (array) ($identity['data'] ?? []),
            'radius_synced' => true,
            'router_id' => (int) $routerResolution['router_id'],
            'binding' => $binding,
        ];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        } elseif ($savepoint !== '' && $db->inTransaction()) {
            try {
                $db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $db->exec('RELEASE SAVEPOINT ' . $savepoint);
            } catch (Throwable) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            }
        }
        $knownErrors = [
            'account_payload_invalid',
            'account_save_failed',
            'binding_authorization_required',
            'binding_persistence_failed',
            'customer_already_exists',
            'duplicate_fin',
            'identity_save_failed',
            'identity_validation_failed',
            'no_active_router',
            'radius_sync_failed',
            'required_identity_fields',
            'router_inactive_or_missing',
            'router_required',
        ];
        $error = in_array($exception->getMessage(), $knownErrors, true)
            ? $exception->getMessage()
            : 'customer_creation_failed';

        return ['ok' => false, 'error' => $error];
    }
}

function router_registry_customer_router(PDO $db, int $customerId, array $actor = []): ?array
{
    if ($customerId <= 0) {
        return null;
    }
    $statement = $db->prepare(
        'SELECT r.id, r.router_name, r.management_ip, r.subnet_mask, r.platform, r.os_version,
                r.nas_identity, r.branch_name, r.is_active, b.created_at AS bound_at,
                b.updated_at AS binding_updated_at
         FROM customer_router_bindings b
         INNER JOIN router_registry r ON r.id = b.router_id
         WHERE b.customer_id = :customer_id
         LIMIT 1'
    );
    $statement->execute(['customer_id' => $customerId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return null;
    }
    $authorization = app_noc_authorize($actor, 'read_router', $row, $db);
    return !empty($authorization['ok']) ? $row : null;
}
