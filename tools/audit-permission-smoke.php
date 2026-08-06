<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/audit-log-lib.php';

$errors = [];
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['ok' => false, 'errors' => ['db_not_ready']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

try {
    app_audit_ensure_schema($db);
    $portalActionsSource = (string) file_get_contents(dirname(__DIR__) . '/portal-actions.php');
    $adminUsersSource = (string) file_get_contents(dirname(__DIR__) . '/admin-users.php');
    $portalSource = (string) file_get_contents(dirname(__DIR__) . '/portal.php');
    $operationLogsPath = dirname(__DIR__) . '/operation-logs.php';
    $operationLogsExportPath = dirname(__DIR__) . '/operation-logs-export.php';

    $requiredFunctions = [
        'app_portal_is_super_admin',
        'app_audit_actor_can_view',
        'app_audit_actor_can_manage',
        'app_audit_edit_visible',
        'app_audit_delete_visible',
    ];
    foreach ($requiredFunctions as $functionName) {
        $expect(function_exists($functionName), $functionName . '_missing');
    }
    $expect(
        str_contains($portalActionsSource, 'employee_role') && str_contains($portalActionsSource, 'super_admin'),
        'portal_action_super_admin_assignment_guard_missing'
    );
    $expect(
        str_contains($portalActionsSource, 's.abdullayev@superoptic.net'),
        'portal_action_protected_designated_account_missing'
    );
    $expect(str_contains($portalActionsSource, '$protectedShapeChanged'), 'protected_super_admin_shape_guard_missing');
    $expect(
        str_contains($portalActionsSource, 'privilege_change_reason'),
        'portal_action_privilege_reason_missing'
    );
    $expect(
        str_contains($portalActionsSource, 'delete_reason')
        && str_contains($portalActionsSource, 'admin_account_deleted')
        && str_contains($portalActionsSource, 'app_portal_is_super_admin($user)'),
        'portal_action_delete_audit_guard_missing'
    );
    $expect(str_contains($adminUsersSource, 'Super admin'), 'admin_users_super_admin_badge_missing');
    $expect(
        str_contains($adminUsersSource, 'admin-users-danger-menu')
        && str_contains($adminUsersSource, 'delete_reason')
        && str_contains($adminUsersSource, '$canDeleteAccounts'),
        'admin_users_super_admin_delete_ui_missing'
    );
    $expect(is_file($operationLogsPath), 'operation_logs_page_missing');
    $expect(is_file($operationLogsExportPath), 'operation_logs_export_missing');
    if (is_file($operationLogsPath)) {
        $operationLogsSource = (string) file_get_contents($operationLogsPath);
        foreach ([
            'data-audit-log-root',
            'data-audit-filter',
            'data-audit-tabs',
            'data-audit-filter-panel',
            'data-audit-pagination',
            'data-audit-detail',
            'Adminler yalniz baxa',
            'Protected Ledger',
            'Operational Journal',
            'app_audit_operation_page',
            'app_audit_ledger_page',
            'edit_visible_audit',
            'delete_visible_audit',
        ] as $marker) {
            $expect(str_contains($operationLogsSource, $marker), 'operation_logs_marker_missing:' . $marker);
        }
    }
    if (is_file($operationLogsExportPath)) {
        $exportSource = (string) file_get_contents($operationLogsExportPath);
        foreach (['text/csv', 'app_audit_operation_page', 'before_json', 'after_json'] as $marker) {
            $expect(str_contains($exportSource, $marker), 'operation_logs_export_marker_missing:' . $marker);
        }
    }
    $expect(str_contains($adminUsersSource, 'operation-logs.php'), 'admin_users_journal_link_missing');
    $expect(str_contains($portalSource, 'operation-logs.php'), 'portal_journal_link_missing');
    $expect(str_contains($portalSource, 'Admin kecidleri'), 'portal_admin_shortcuts_missing');
    // Label text was renamed to "Mesaj kanallari" when SMS/WhatsApp/external
    // delivery readiness were consolidated under one shortcut card; check the
    // stable route+anchor instead of the cosmetic label so relabeling doesn't
    // keep breaking this contract.
    $expect(
        str_contains($portalSource, 'message-center.php?view=settings')
            && str_contains($portalSource, '#sms-provider-settings'),
        'portal_sms_provider_shortcut_missing'
    );

    $superAdmin = app_db_account_by_login('s.abdullayev@superoptic.net');
    $expect(is_array($superAdmin), 'designated_super_admin_missing');
    if (is_array($superAdmin)) {
        $expect((string) ($superAdmin['role'] ?? '') === 'employee', 'super_admin_role_invalid');
        $expect((string) ($superAdmin['account_type'] ?? '') === 'admin', 'super_admin_account_type_invalid');
        $expect((string) ($superAdmin['employee_role'] ?? '') === 'super_admin', 'super_admin_employee_role_invalid');
        $expect((int) ($superAdmin['is_active'] ?? 0) === 1, 'super_admin_not_active');
        if (function_exists('app_portal_is_super_admin')) {
            $expect(app_portal_is_super_admin($superAdmin) === true, 'super_admin_helper_false');
        }
        $expect(app_portal_is_admin($superAdmin) === true, 'super_admin_not_treated_as_admin');
    }

    $normalAdmin = [
        'id' => 910001,
        'role' => 'employee',
        'account_type' => 'admin',
        'employee_role' => 'admin',
        'full_name' => 'Permission Smoke Admin',
        'login' => 'permission-smoke-admin@superoptic.local',
    ];
    $operator = [
        'id' => 910002,
        'role' => 'employee',
        'account_type' => 'operator',
        'employee_role' => 'operator',
        'full_name' => 'Permission Smoke Operator',
        'login' => 'permission-smoke-operator@superoptic.local',
    ];
    $nocAdmin = [
        'id' => 910003,
        'role' => 'employee',
        'account_type' => 'admin',
        'employee_role' => 'noc',
        'full_name' => 'Permission Smoke NOC',
        'login' => 'permission-smoke-noc@superoptic.local',
    ];
    $nameImpersonator = [
        'id' => 910004,
        'role' => 'employee',
        'account_type' => 'admin',
        'employee_role' => 'super_admin',
        'full_name' => 'Serdar Abdullayev',
        'login' => 'permission-smoke-impostor@superoptic.local',
        'email' => 's.abdullayev@superoptic.net',
    ];

    if (function_exists('app_audit_actor_can_view')) {
        $expect(app_audit_actor_can_view($normalAdmin) === true, 'normal_admin_cannot_view_journal');
        $expect(app_audit_actor_can_view($nocAdmin) === true, 'account_type_admin_cannot_view_journal');
        $expect(app_audit_actor_can_view($operator) === false, 'operator_can_view_journal');
    }
    if (function_exists('app_audit_actor_can_manage')) {
        $expect(app_audit_actor_can_manage($normalAdmin) === false, 'normal_admin_can_manage_journal');
        $expect(app_audit_actor_can_manage($nocAdmin) === false, 'account_type_admin_can_manage_journal');
        $expect(app_audit_actor_can_manage($nameImpersonator) === false, 'name_or_role_impersonator_can_manage_journal');
        if (is_array($superAdmin)) {
            $expect(app_audit_actor_can_manage($superAdmin) === true, 'super_admin_cannot_manage_journal');
        }
    }

    if ($errors === [] && is_array($superAdmin)) {
        $db->beginTransaction();
        try {
            $seed = app_audit_record($db, [
                'module_key' => 'security',
                'operation_class' => 'permission_probe',
                'action_type' => 'audit_permission_seed',
                'entity_type' => 'audit_permission',
                'entity_id' => 'permission-smoke-' . bin2hex(random_bytes(4)),
                'status' => 'executed',
                'actor_account_id' => (int) ($superAdmin['id'] ?? 0),
                'actor_name' => (string) ($superAdmin['full_name'] ?? $superAdmin['login'] ?? 'super admin'),
                'summary' => 'Permission smoke seed',
                'reason' => 'Permission smoke setup',
                'before' => ['state' => 'before'],
                'after' => ['state' => 'after'],
                'context' => [
                    'request_ip' => '127.0.0.1',
                    'user_agent' => 'audit-permission-smoke',
                    'request_id' => 'audit-permission-' . random_int(1000, 9999),
                ],
                'sensitivity' => 'critical',
            ]);
            $actionId = (int) ($seed['action_id'] ?? 0);
            $expect($actionId > 0, 'seed_action_missing');

            $normalEditBlocked = false;
            try {
                app_audit_edit_visible($db, $actionId, 'Normal admin edited summary', 'Normal admin reason', $normalAdmin);
            } catch (Throwable) {
                $normalEditBlocked = true;
            }
            $expect($normalEditBlocked, 'normal_admin_edit_not_blocked');

            $normalDeleteBlocked = false;
            try {
                app_audit_delete_visible($db, $actionId, 'Normal admin delete reason', $normalAdmin);
            } catch (Throwable) {
                $normalDeleteBlocked = true;
            }
            $expect($normalDeleteBlocked, 'normal_admin_delete_not_blocked');

            $emptyReasonBlocked = false;
            try {
                app_audit_edit_visible($db, $actionId, 'Missing reason summary', '', $superAdmin);
            } catch (Throwable) {
                $emptyReasonBlocked = true;
            }
            $expect($emptyReasonBlocked, 'super_admin_empty_reason_allowed');

            $edit = app_audit_edit_visible(
                $db,
                $actionId,
                'Super admin visible summary',
                'Correcting visible journal note',
                $superAdmin
            );
            $expect(($edit['ok'] ?? false) === true, 'super_admin_edit_failed');

            $rowStatement = $db->prepare(
                'SELECT summary, reason, edited_by_account_id, edited_at, deleted_by_account_id, deleted_at, is_visible
                   FROM mgs_operation_actions
                  WHERE id = :id'
            );
            $rowStatement->execute(['id' => $actionId]);
            $editedRow = $rowStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $expect((string) ($editedRow['summary'] ?? '') === 'Super admin visible summary', 'visible_summary_not_updated');
            $expect((string) ($editedRow['reason'] ?? '') === 'Correcting visible journal note', 'visible_reason_not_updated');
            $expect((int) ($editedRow['edited_by_account_id'] ?? 0) === (int) ($superAdmin['id'] ?? 0), 'edited_by_not_stored');
            $expect((string) ($editedRow['edited_at'] ?? '') !== '', 'edited_at_not_stored');
            $expect((int) ($editedRow['is_visible'] ?? 0) === 1, 'edit_changed_visibility');

            $delete = app_audit_delete_visible($db, $actionId, 'Hide duplicate visible event', $superAdmin);
            $expect(($delete['ok'] ?? false) === true, 'super_admin_delete_failed');

            $rowStatement->execute(['id' => $actionId]);
            $deletedRow = $rowStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $expect((int) ($deletedRow['is_visible'] ?? 1) === 0, 'soft_delete_visibility_not_zero');
            $expect((int) ($deletedRow['deleted_by_account_id'] ?? 0) === (int) ($superAdmin['id'] ?? 0), 'deleted_by_not_stored');
            $expect((string) ($deletedRow['deleted_at'] ?? '') !== '', 'deleted_at_not_stored');

            $ledgerActions = $db->prepare(
                'SELECT action_type, entity_type, entity_id, reason
                   FROM security_audit_ledger
                  WHERE action_type IN ("journal_edit", "journal_delete")
                    AND entity_type = "mgs_operation_action"
                    AND entity_id = :entity_id'
            );
            $ledgerActions->execute(['entity_id' => (string) $actionId]);
            $journalEvents = $ledgerActions->fetchAll(PDO::FETCH_ASSOC);
            $expect(count($journalEvents) === 2, 'journal_intervention_ledger_events_missing');
            $expect(app_audit_verify_chain($db)['ok'] === true, 'chain_invalid_after_journal_intervention');
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $errors[] = 'exception:' . $exception->getMessage();
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
