<?php
declare(strict_types=1);

require_once __DIR__ . '/operations-permission-lib.php';

function app_noc_action_roles(): array
{
    return [
        'scan_network' => ['noc'],
        'create_monitor_device' => ['noc'],
        'read_device' => ['support', 'technician', 'engineer', 'noc'],
        'read_monitor_map' => ['support', 'technician', 'engineer', 'noc'],
        'update_monitor_map' => ['support', 'technician', 'engineer', 'noc'],
        'read_device_sensitive' => ['noc'],
        'read_noc_logs' => ['noc'],
        'probe_network' => ['noc'],
        'probe_device' => ['engineer', 'noc'],
        'run_olt_rules' => ['noc'],
        'preview_device_backup' => ['engineer', 'noc'],
        'apply_device_backup' => [],
        'read_ont_live' => ['support', 'engineer', 'noc'],
        'execute_ont_control' => ['noc'],
        'update_monitoring_mode' => ['noc'],
        'update_device_access' => [],
        'update_vendor_defaults' => [],
        'update_command_map' => [],
        'execute_device_command' => [],
        'update_management_path' => ['noc'],
        'seed_device_profiles' => [],
        'sync_process_registry' => ['noc'],
        'record_noc_alarm' => ['noc'],
        'read_security_anomaly' => ['support', 'technician', 'engineer', 'noc'],
        'run_security_anomaly_scan' => ['noc'],
        'read_router' => ['support', 'operator', 'noc'],
        'manage_router' => [],
        'bind_router' => ['noc'],
    ];
}

function app_noc_service_action_map(): array
{
    return [
        'device-backup-worker' => [
            'read_device',
            'read_device_sensitive',
            'preview_device_backup',
            'apply_device_backup',
            'update_command_map',
        ],
        'device-probe-worker' => [
            'read_device',
            'read_device_sensitive',
            'probe_network',
            'probe_device',
            'sync_process_registry',
        ],
        'olt-rule-worker' => ['read_device', 'run_olt_rules', 'seed_device_profiles', 'record_noc_alarm'],
        'modem-search-worker' => ['read_device', 'read_device_sensitive', 'read_ont_live'],
        'system-audit-worker' => ['read_device', 'read_noc_logs', 'seed_device_profiles', 'sync_process_registry'],
        'security-anomaly-worker' => ['read_security_anomaly', 'run_security_anomaly_scan', 'record_noc_alarm'],
        'ont-telemetry-sync-worker' => ['read_device', 'read_device_sensitive', 'read_ont_live'],
        'olt-inventory-worker' => ['read_device', 'read_device_sensitive', 'read_ont_live'],
        'finance-report-worker' => ['record_noc_alarm'],
        'whatsapp-heartbeat-watcher' => ['record_noc_alarm'],
    ];
}

function app_noc_system_actor(string $serviceName): array
{
    return [
        'role' => 'service',
        'account_type' => 'service',
        'employee_role' => 'service',
        'service_name' => strtolower(trim($serviceName)),
        'noc_service_actor' => true,
        'is_active' => 1,
    ];
}

function app_noc_actor_role(array $actor): string
{
    return str_replace(
        [' ', '-'],
        '_',
        strtolower(trim((string) ($actor['employee_role'] ?? '')))
    );
}

function app_noc_is_active_employee(array $actor): bool
{
    return (string) ($actor['role'] ?? '') === 'employee'
        && (!array_key_exists('is_active', $actor) || (int) $actor['is_active'] === 1);
}

function app_noc_is_service_actor(array $actor): bool
{
    if (PHP_SAPI !== 'cli'
        || empty($actor['noc_service_actor'])
        || (string) ($actor['role'] ?? '') !== 'service'
        || (string) ($actor['account_type'] ?? '') !== 'service'
        || (int) ($actor['is_active'] ?? 0) !== 1) {
        return false;
    }

    $serviceName = strtolower(trim((string) ($actor['service_name'] ?? '')));
    return array_key_exists($serviceName, app_noc_service_action_map());
}

function app_noc_scope_values(array $actor, ?PDO $db = null): array
{
    $values = [
        'device_keys' => [],
        'management_networks' => [],
        'areas' => [],
    ];
    $copy = static function (mixed $source): array {
        if (is_string($source)) {
            $source = preg_split('/\s*,\s*/', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($source)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $source
        ))));
    };

    $values['device_keys'] = $copy($actor['noc_device_keys'] ?? []);
    $values['management_networks'] = $copy($actor['noc_management_networks'] ?? []);
    $values['areas'] = $copy($actor['responsible_areas'] ?? ($actor['areas'] ?? []));

    $accountId = (int) ($actor['id'] ?? 0);
    if ($accountId > 0 && $db instanceof PDO) {
        try {
            $statement = $db->prepare('SELECT area FROM portal_account_areas WHERE account_id = :account_id ORDER BY area');
            $statement->execute(['account_id' => $accountId]);
            $dbAreas = array_map(
                static fn (array $row): string => (string) ($row['area'] ?? ''),
                $statement->fetchAll(PDO::FETCH_ASSOC) ?: []
            );
            $values['areas'] = array_values(array_unique(array_merge($values['areas'], $copy($dbAreas))));
        } catch (Throwable) {
            // Missing scope data fails closed for non-global roles.
        }
    }

    return $values;
}

function app_noc_object_in_scope(array $actor, array $object, ?PDO $db = null): bool
{
    if (app_portal_is_admin($actor) || app_noc_actor_role($actor) === 'noc' || app_noc_is_service_actor($actor)) {
        return true;
    }

    $scope = app_noc_scope_values($actor, $db);
    $deviceKey = strtolower(trim((string) ($object['device_key'] ?? '')));
    if ($deviceKey !== '' && in_array($deviceKey, $scope['device_keys'], true)) {
        return true;
    }

    $managementNetwork = strtolower(trim((string) ($object['management_network'] ?? '')));
    if ($managementNetwork !== '' && in_array($managementNetwork, $scope['management_networks'], true)) {
        return true;
    }

    $objectText = strtolower(implode(' ', array_filter([
        $deviceKey,
        (string) ($object['area'] ?? ''),
        (string) ($object['location'] ?? ''),
        (string) ($object['branch_name'] ?? ''),
        (string) ($object['device_name'] ?? ''),
        $managementNetwork,
    ])));
    foreach ($scope['areas'] as $area) {
        if ($area !== '' && str_contains($objectText, $area)) {
            return true;
        }
    }

    return false;
}

function app_noc_ticket_probe_object_matches(array $actor, array $object): bool
{
    $boundDevice = strtolower((string) preg_replace(
        '/[^a-z0-9]+/',
        '',
        (string) ($actor['ticket_resolution_device'] ?? '')
    ));
    if ($boundDevice === '') {
        return false;
    }

    foreach (['device_name', 'device_key', 'ip_address'] as $field) {
        $candidate = strtolower((string) preg_replace('/[^a-z0-9]+/', '', (string) ($object[$field] ?? '')));
        if ($candidate !== '' && hash_equals($boundDevice, $candidate)) {
            return true;
        }
    }

    return false;
}

function app_noc_authorize(array $actor, string $action, array $object = [], ?PDO $db = null): array
{
    $action = strtolower(trim($action));
    $matrix = app_noc_action_roles();
    if (!array_key_exists($action, $matrix)) {
        return ['ok' => false, 'reason' => 'unknown_noc_action', 'action' => $action];
    }

    if (app_noc_is_service_actor($actor)) {
        $serviceName = strtolower(trim((string) ($actor['service_name'] ?? '')));
        $allowed = in_array($action, app_noc_service_action_map()[$serviceName] ?? [], true);
        return [
            'ok' => $allowed,
            'reason' => $allowed ? '' : 'service_action_denied',
            'action' => $action,
            'scope' => $allowed ? 'service' : 'denied',
        ];
    }

    if (!app_noc_is_active_employee($actor)) {
        return ['ok' => false, 'reason' => 'noc_authentication_required', 'action' => $action];
    }
    if (app_portal_is_admin($actor)) {
        return ['ok' => true, 'reason' => '', 'action' => $action, 'scope' => 'global'];
    }
    $ticketProbeRole = app_noc_actor_role($actor);
    if ($action === 'read_ont_live'
        && !empty($actor['ticket_resolution_scope'])
        && in_array($ticketProbeRole, ['technician', 'support', 'engineer', 'noc'], true)
        && ($object === []
            || app_noc_ticket_probe_object_matches($actor, $object)
            || app_noc_object_in_scope($actor, $object, $db))) {
        return ['ok' => true, 'reason' => '', 'action' => $action, 'scope' => 'assigned_ticket'];
    }
    if (!app_operations_action_allowed($actor, $action)) {
        return ['ok' => false, 'reason' => 'noc_module_denied', 'action' => $action];
    }

    $role = app_noc_actor_role($actor);
    if (!in_array($role, $matrix[$action], true)) {
        return ['ok' => false, 'reason' => 'noc_action_denied', 'action' => $action];
    }
    if ($object !== [] && !app_noc_object_in_scope($actor, $object, $db)) {
        return ['ok' => false, 'reason' => 'noc_object_scope_denied', 'action' => $action];
    }

    return [
        'ok' => true,
        'reason' => '',
        'action' => $action,
        'scope' => $role === 'noc' ? 'global' : 'restricted',
    ];
}

function app_noc_authorization_failure(string $action, array $decision): array
{
    return [
        'ok' => false,
        'reason' => (string) ($decision['reason'] ?? 'noc_authorization_required'),
        'action' => $action,
    ];
}

function app_noc_filter_objects(array $rows, array $actor, ?PDO $db = null): array
{
    if (app_portal_is_admin($actor) || app_noc_actor_role($actor) === 'noc' || app_noc_is_service_actor($actor)) {
        return array_values(array_filter($rows, 'is_array'));
    }

    return array_values(array_filter(
        $rows,
        static fn (mixed $row): bool => is_array($row) && app_noc_object_in_scope($actor, $row, $db)
    ));
}
