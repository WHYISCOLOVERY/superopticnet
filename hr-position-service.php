<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/audit-log-lib.php';

function app_hr_position_schema_ready(PDO $db): bool
{
    return app_db_table_exists($db, 'hr_positions');
}

/** @return list<string> */
function app_hr_position_role_keys(): array
{
    return [
        'finance',
        'super_admin',
        'admin',
        'director',
        'team_lead',
        'warehouse',
        'warehouse_manager',
        'hr',
        'operator',
        'night_operator',
        'support',
        'technician',
        'engineer',
        'noc',
        'sales',
    ];
}

/** @return list<string> */
function app_hr_position_module_keys(array $position): array
{
    $decoded = app_db_json_decode((string) ($position['module_keys_json'] ?? ''), []);
    if (!is_array($decoded)) {
        return [];
    }
    $allowed = array_keys(app_portal_module_catalog());
    return array_values(array_intersect($allowed, array_values(array_unique(array_map(
        static fn (mixed $key): string => strtolower(clean_text((string) $key)),
        $decoded
    )))));
}

/** @return list<array<string,mixed>> */
function app_hr_positions(PDO $db, bool $activeOnly = true): array
{
    if (!app_hr_position_schema_ready($db)) {
        return [];
    }
    $sql = 'SELECT * FROM hr_positions';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY title, id';
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['module_keys'] = app_hr_position_module_keys($row);
    }
    unset($row);
    return $rows;
}

function app_hr_position_by_id(PDO $db, int $positionId, bool $activeOnly = false): ?array
{
    if ($positionId <= 0 || !app_hr_position_schema_ready($db)) {
        return null;
    }
    $sql = 'SELECT * FROM hr_positions WHERE id = :id';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' LIMIT 1';
    $statement = $db->prepare($sql);
    $statement->execute(['id' => $positionId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $row['module_keys'] = app_hr_position_module_keys($row);
    return $row;
}

function app_hr_position_for_account(PDO $db, array $account): ?array
{
    $payload = app_db_account_payload($account);
    $employee = is_array($payload['employee'] ?? null) ? $payload['employee'] : [];
    $positionId = (int) ($employee['position_id'] ?? 0);
    $position = app_hr_position_by_id($db, $positionId);
    if ($position !== null) {
        return $position;
    }

    $title = clean_text((string) ($account['job_title'] ?? ''));
    if ($title === '' || !app_hr_position_schema_ready($db)) {
        return null;
    }
    $statement = $db->prepare('SELECT * FROM hr_positions WHERE title = :title LIMIT 1');
    $statement->execute(['title' => $title]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $row['module_keys'] = app_hr_position_module_keys($row);
    return $row;
}

function app_hr_save_position(PDO $db, array $input, array $actor): array
{
    if (!app_portal_is_admin($actor)) {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    if (!app_hr_position_schema_ready($db)) {
        return ['ok' => false, 'error' => 'schema_not_ready'];
    }

    $positionId = max(0, (int) ($input['position_id'] ?? 0));
    $title = clean_text((string) ($input['title'] ?? ''));
    $description = clean_text((string) ($input['description'] ?? ''));
    $accountType = strtolower(clean_text((string) ($input['recommended_account_type'] ?? 'operator')));
    $employeeRole = strtolower(clean_text((string) ($input['recommended_employee_role'] ?? '')));
    $reason = clean_text((string) ($input['change_reason'] ?? ''));
    $isActive = !empty($input['is_active']) ? 1 : 0;
    if ($title === '' || $reason === '') {
        return ['ok' => false, 'error' => $title === '' ? 'title_required' : 'reason_required'];
    }
    if (!in_array($accountType, ['operator', 'technician', 'admin', 'support'], true)) {
        return ['ok' => false, 'error' => 'invalid_account_type'];
    }
    if ($employeeRole !== '' && !in_array($employeeRole, app_hr_position_role_keys(), true)) {
        return ['ok' => false, 'error' => 'invalid_employee_role'];
    }

    $allowedModules = array_keys(app_portal_module_catalog());
    $moduleKeys = array_values(array_intersect($allowedModules, array_values(array_unique(array_map(
        static fn (mixed $key): string => strtolower(clean_text((string) $key)),
        (array) ($input['module_keys'] ?? [])
    )))));
    $before = $positionId > 0 ? app_hr_position_by_id($db, $positionId) : null;
    if ($positionId > 0 && $before === null) {
        return ['ok' => false, 'error' => 'position_not_found'];
    }
    $duplicate = $db->prepare('SELECT id FROM hr_positions WHERE title = :title AND id <> :id LIMIT 1');
    $duplicate->execute(['title' => $title, 'id' => $positionId]);
    if ($duplicate->fetchColumn()) {
        return ['ok' => false, 'error' => 'duplicate_title'];
    }

    $after = [
        'title' => $title,
        'description' => $description,
        'recommended_account_type' => $accountType,
        'recommended_employee_role' => $employeeRole,
        'module_keys' => $moduleKeys,
        'is_active' => $isActive,
    ];
    try {
        $db->beginTransaction();
        if ($positionId > 0) {
            $statement = $db->prepare(
                'UPDATE hr_positions
                    SET title = :title,
                        description = :description,
                        recommended_account_type = :account_type,
                        recommended_employee_role = :employee_role,
                        module_keys_json = :module_keys_json,
                        is_active = :is_active,
                        updated_by_account_id = :actor_id
                  WHERE id = :id'
            );
            $statement->execute([
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'account_type' => $accountType,
                'employee_role' => $employeeRole,
                'module_keys_json' => app_db_json_encode($moduleKeys),
                'is_active' => $isActive,
                'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
                'id' => $positionId,
            ]);
        } else {
            $positionKey = 'position_' . substr(hash('sha256', $title . '|' . microtime(true)), 0, 20);
            $statement = $db->prepare(
                'INSERT INTO hr_positions
                    (position_key, title, description, recommended_account_type, recommended_employee_role,
                     module_keys_json, is_active, created_by_account_id, updated_by_account_id)
                 VALUES
                    (:position_key, :title, :description, :account_type, :employee_role,
                     :module_keys_json, :is_active, :actor_id, :actor_id)'
            );
            $statement->execute([
                'position_key' => $positionKey,
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'account_type' => $accountType,
                'employee_role' => $employeeRole,
                'module_keys_json' => app_db_json_encode($moduleKeys),
                'is_active' => $isActive,
                'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
            ]);
            $positionId = (int) $db->lastInsertId();
        }

        app_audit_record($db, [
            'module_key' => 'hr',
            'operation_class' => 'privilege',
            'action_type' => $before === null ? 'hr_position_created' : 'hr_position_updated',
            'entity_type' => 'hr_position',
            'entity_id' => (string) $positionId,
            'status' => 'executed',
            'actor_account_id' => (int) ($actor['id'] ?? 0),
            'actor_name' => (string) ($actor['name'] ?? $actor['full_name'] ?? $actor['login'] ?? 'admin'),
            'summary' => ($before === null ? 'HR position created: ' : 'HR position updated: ') . $title,
            'reason' => $reason,
            'before' => $before,
            'after' => $after,
            'sensitivity' => 'critical',
        ]);
        $db->commit();
        return ['ok' => true, 'position_id' => $positionId];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'position_save_failed'];
    }
}
