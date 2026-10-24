<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/audit-log-lib.php';
require_once __DIR__ . '/mgs-integration-lib.php';

/**
 * Maker-checker for admin/role privilege escalation (vision doc 19.2
 * "rol dəyişikliyi"). De-escalating changes (removing admin rights,
 * deactivating an account, removing module access) still apply immediately
 * via the normal save_account flow -- those are the safe direction. Only an
 * ESCALATING change (granting admin/super_admin, reactivating a disabled
 * account, or adding module access the account did not already have) is
 * staged here and requires a different admin to approve before
 * app_db_upsert_account() actually runs.
 */

/**
 * @param array{role?:?string,account_type?:?string,employee_role?:?string,is_active?:?int,module_keys?:?array} $previous
 * @param array{role?:?string,account_type?:?string,employee_role?:?string,is_active?:?int,module_keys?:?array} $proposed
 */
function app_admin_privilege_change_is_escalation(array $previous, array $proposed): bool
{
    $previousIsAdminTier = in_array((string) ($previous['account_type'] ?? ''), ['admin'], true)
        || in_array(strtolower((string) ($previous['employee_role'] ?? '')), ['admin', 'super_admin', 'superadmin'], true);
    $proposedIsAdminTier = in_array((string) ($proposed['account_type'] ?? ''), ['admin'], true)
        || in_array(strtolower((string) ($proposed['employee_role'] ?? '')), ['admin', 'super_admin', 'superadmin'], true);
    if ($proposedIsAdminTier && !$previousIsAdminTier) {
        return true;
    }

    $previousActive = (int) ($previous['is_active'] ?? 1) === 1;
    $proposedActive = (int) ($proposed['is_active'] ?? 1) === 1;
    if ($proposedActive && !$previousActive) {
        return true;
    }

    $previousModules = array_map('strtolower', is_array($previous['module_keys'] ?? null) ? $previous['module_keys'] : []);
    $proposedModules = array_map('strtolower', is_array($proposed['module_keys'] ?? null) ? $proposed['module_keys'] : []);
    if (array_diff($proposedModules, $previousModules) !== []) {
        return true;
    }

    return false;
}

/**
 * @param array<string,mixed> $accountPayload the exact array to later pass to app_db_upsert_account()
 */
function app_admin_privilege_change_request_create(
    PDO $db,
    array $accountPayload,
    array $previousPrivileges,
    array $proposedPrivileges,
    string $reason,
    array $actor
): array {
    $reason = mb_substr(clean_text($reason), 0, 255);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'reason_required'];
    }
    $targetAccountId = (int) ($accountPayload['id'] ?? 0);
    $statement = $db->prepare(
        'INSERT INTO admin_privilege_change_requests (
            target_account_id, target_login, previous_privileges_json, proposed_privileges_json,
            account_payload_json, reason, status, requested_by_account_id, requested_by_name
         ) VALUES (
            :target_account_id, :target_login, :previous_privileges_json, :proposed_privileges_json,
            :account_payload_json, :reason, "pending", :actor_id, :actor_name
         )'
    );
    $statement->execute([
        'target_account_id' => $targetAccountId ?: null,
        'target_login' => mb_substr((string) ($accountPayload['login'] ?? ''), 0, 191),
        'previous_privileges_json' => app_db_json_encode($previousPrivileges),
        'proposed_privileges_json' => app_db_json_encode($proposedPrivileges),
        'account_payload_json' => app_db_json_encode($accountPayload),
        'reason' => $reason,
        'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
        'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
    ]);
    $id = (int) $db->lastInsertId();

    if (function_exists('app_audit_record')) {
        app_audit_ensure_schema($db);
        try {
            $db->beginTransaction();
            app_audit_record($db, [
                'module_key' => 'admin',
                'operation_class' => 'privilege',
                'action_type' => 'admin_privilege_escalation_requested',
                'entity_type' => 'account',
                'entity_id' => (string) $targetAccountId,
                'status' => 'pending',
                'actor_account_id' => (int) ($actor['id'] ?? 0),
                'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? 'admin'),
                'summary' => 'Privilege escalation requested for account #' . $targetAccountId,
                'reason' => $reason,
                'before' => $previousPrivileges,
                'after' => $proposedPrivileges,
                'sensitivity' => 'critical',
            ]);
            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }

    return ['ok' => true, 'id' => $id];
}

function app_admin_privilege_change_requests(PDO $db, string $status = 'pending'): array
{
    $statement = $db->prepare('SELECT * FROM admin_privilege_change_requests WHERE status = :status ORDER BY created_at DESC LIMIT 100');
    $statement->execute(['status' => $status]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_admin_privilege_change_request_resolve(PDO $db, int $requestId, string $decision, string $resolutionNote, array $actor): array
{
    if (!app_portal_is_admin($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'invalid_decision'];
    }
    $resolutionNote = mb_substr(clean_text($resolutionNote), 0, 255);
    $actorId = (int) ($actor['id'] ?? 0);

    $requestStatement = $db->prepare('SELECT * FROM admin_privilege_change_requests WHERE id = :id LIMIT 1');
    $requestStatement->execute(['id' => $requestId]);
    $request = $requestStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($request)) {
        return ['ok' => false, 'error' => 'request_not_found'];
    }
    if ((string) $request['status'] !== 'pending') {
        return ['ok' => false, 'error' => 'request_already_resolved'];
    }
    if ($actorId > 0 && $actorId === (int) ($request['requested_by_account_id'] ?? 0)) {
        return ['ok' => false, 'error' => 'requester_cannot_approve_own_request'];
    }

    $savedAccountId = null;
    if ($decision === 'approved') {
        $accountPayload = app_mgs_json_decode_array($request['account_payload_json'] ?? []);
        if ($accountPayload === []) {
            return ['ok' => false, 'error' => 'stored_payload_invalid'];
        }
        $saved = app_db_upsert_account($accountPayload);
        if ($saved === null) {
            return ['ok' => false, 'error' => 'account_save_failed'];
        }
        $savedAccountId = (int) ($saved['id'] ?? 0);
    }

    if (function_exists('app_audit_ensure_schema')) {
        app_audit_ensure_schema($db);
    }

    try {
        $db->beginTransaction();
        $update = $db->prepare(
            "UPDATE admin_privilege_change_requests SET status = :status, reviewed_by_account_id = :actor_id,
                reviewed_by_name = :actor_name, resolution_note = :resolution_note, reviewed_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $update->execute([
            'status' => $decision,
            'actor_id' => $actorId ?: null,
            'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
            'resolution_note' => $resolutionNote ?: null,
            'id' => $requestId,
        ]);
        if ($update->rowCount() === 0) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'request_already_resolved'];
        }
        if (function_exists('app_audit_record')) {
            app_audit_record($db, [
                'module_key' => 'admin',
                'operation_class' => 'privilege',
                'action_type' => 'admin_privilege_escalation_' . $decision,
                'entity_type' => 'account',
                'entity_id' => (string) ($request['target_account_id'] ?? 0),
                'status' => 'executed',
                'actor_account_id' => $actorId,
                'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
                'summary' => 'Privilege escalation request #' . $requestId . ' ' . $decision,
                'reason' => $resolutionNote,
                'before' => app_mgs_json_decode_array($request['previous_privileges_json'] ?? []),
                'after' => app_mgs_json_decode_array($request['proposed_privileges_json'] ?? []),
                'sensitivity' => 'critical',
            ]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'request_resolve_failed', 'message' => $exception->getMessage()];
    }

    return ['ok' => true, 'status' => $decision, 'account_id' => $savedAccountId];
}
