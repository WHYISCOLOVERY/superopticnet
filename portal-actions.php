<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/mgs-integration-lib.php';

app_session_boot();
$user = app_portal_current_user();
$action = clean_text($_POST['action'] ?? '');
$csrfToken = $_POST['csrf_token'] ?? '';
$lang = $_GET['lang'] ?? $_POST['lang'] ?? app_lang();
$lang = is_string($lang) && in_array($lang, app_supported_langs(), true) ? $lang : 'az';

function portal_redirect_url(string $path, string $status = ''): string
{
    global $lang;

    $params = [];
    if ($status !== '') {
        $params['status'] = $status;
    }
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }

    return $path . ($params === [] ? '' : '?' . http_build_query($params));
}

function portal_safe_return_path(string $value): string
{
    $value = trim(str_replace(["\r", "\n"], '', $value));
    if ($value === '' || str_starts_with($value, '//') || str_contains($value, '://')) {
        return '';
    }

    if (!preg_match('/^[A-Za-z0-9_\/.\-]+\.php(\?[^#]*)?(#[A-Za-z0-9_\-]+)?$/', $value)) {
        return '';
    }

    return $value;
}

function portal_redirect_return_or(string $fallbackPath, string $status, string $returnTo = ''): string
{
    global $lang;

    $returnTo = portal_safe_return_path($returnTo);
    if ($returnTo === '') {
        return portal_redirect_url($fallbackPath, $status);
    }

    $fragment = '';
    $hashPos = strpos($returnTo, '#');
    if ($hashPos !== false) {
        $fragment = substr($returnTo, $hashPos);
        $returnTo = substr($returnTo, 0, $hashPos);
    }

    $separator = str_contains($returnTo, '?') ? '&' : '?';
    $params = ['status' => $status];
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }

    return $returnTo . $separator . http_build_query($params) . $fragment;
}

if ($action === 'logout') {
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        header('Location: ' . portal_redirect_url('portal-login.php', 'csrf_failed'));
        exit;
    }

    app_portal_sign_out();
    header('Location: ' . portal_redirect_url('portal-login.php', 'logged_out'));
    exit;
}

if ($user === null) {
    header('Location: ' . portal_redirect_url('portal-login.php', 'auth_required'));
    exit;
}

if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
    header('Location: ' . portal_redirect_url('portal.php', 'csrf_failed'));
    exit;
}

if ($action === 'update_phone' && $user['role'] === 'customer') {
    $phone = clean_text($_POST['phone'] ?? '');
    $success = app_portal_update_customer_phone($user, $phone);
    header('Location: ' . portal_redirect_url('portal.php', $success ? 'portal_phone_updated' : 'portal_phone_failed'));
    exit;
}

if ($action === 'update_password' && $user['role'] === 'customer') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $success = app_portal_update_customer_password($user, $currentPassword, $newPassword, $confirmPassword);
    header('Location: ' . portal_redirect_url('portal.php', $success ? 'portal_password_updated' : 'portal_password_failed'));
    exit;
}

if ($action === 'create_ticket' && $user['role'] === 'customer') {
    $subject = clean_text($_POST['subject'] ?? '');
    $message = clean_text($_POST['message'] ?? '');
    $success = app_portal_create_customer_ticket($user, $subject, $message);
    if ($success) {
        app_mgs_record_operation_action([
            'action_type' => 'ticket_created',
            'entity_type' => 'ticket',
            'entity_id' => clean_text((string) ($user['subscriber_code'] ?? $user['login'] ?? 'customer')),
            'status' => 'executed',
            'actor' => $user,
            'actor_account_id' => (int) ($user['id'] ?? 0),
            'actor_name' => (string) ($user['full_name'] ?? $user['name'] ?? $user['login'] ?? 'customer'),
            'summary' => 'Customer ticket yaradildi: ' . $subject,
            'payload' => ['subject' => $subject, 'message' => $message, 'subscriber_code' => (string) ($user['subscriber_code'] ?? '')],
        ]);
        app_mgs_rebuild_employee_work_snapshots();
    }
    header('Location: ' . portal_redirect_url('portal.php', $success ? 'portal_ticket_created' : 'portal_ticket_failed'));
    exit;
}

if ($action === 'create_operator_ticket' && $user['role'] === 'employee') {
    $returnTo = (string) ($_POST['return_to'] ?? '');
    $success = app_portal_create_operator_ticket($user, [
        'customer_account_id' => $_POST['customer_account_id'] ?? '',
        'customer_name' => $_POST['customer_name'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'area' => $_POST['area'] ?? '',
        'subject' => $_POST['subject'] ?? '',
        'message' => $_POST['message'] ?? '',
        'subscriber_code' => $_POST['subscriber_code'] ?? '',
        'address' => $_POST['address'] ?? '',
    ]);
    if ($success) {
        app_mgs_record_operation_action([
            'action_type' => 'ticket_created',
            'entity_type' => 'ticket',
            'entity_id' => clean_text((string) ($_POST['subscriber_code'] ?? $_POST['phone'] ?? 'manual')),
            'status' => 'executed',
            'actor' => $user,
            'actor_account_id' => (int) ($user['id'] ?? 0),
            'actor_name' => (string) ($user['full_name'] ?? $user['name'] ?? $user['login'] ?? 'operator'),
            'summary' => 'Operator ticket yaradildi: ' . clean_text((string) ($_POST['subject'] ?? '')),
            'payload' => [
                'customer_name' => clean_text((string) ($_POST['customer_name'] ?? '')),
                'phone' => clean_text((string) ($_POST['phone'] ?? '')),
                'area' => clean_text((string) ($_POST['area'] ?? '')),
                'subject' => clean_text((string) ($_POST['subject'] ?? '')),
                'subscriber_code' => clean_text((string) ($_POST['subscriber_code'] ?? '')),
            ],
        ]);
        app_mgs_rebuild_employee_work_snapshots();
    }
    header('Location: ' . portal_redirect_return_or('crm.php', $success ? 'operator_ticket_created' : 'operator_ticket_failed', $returnTo));
    exit;
}

if ($action === 'save_account' && $user['role'] === 'employee' && app_portal_is_admin($user)) {
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $employeeOnly = clean_text((string) ($_POST['account_scope'] ?? '')) === 'employee';
    $role = $employeeOnly ? 'employee' : clean_text((string) ($_POST['role'] ?? 'customer'));
    $accountType = clean_text((string) ($_POST['account_type'] ?? ($role === 'employee' ? 'operator' : 'customer')));
    if ($role === 'customer') {
        $accountType = 'customer';
    }
    if ($employeeOnly && !in_array($accountType, ['operator', 'technician', 'admin', 'support'], true)) {
        $accountType = 'operator';
    }
    $employeeRole = clean_text((string) ($_POST['employee_role'] ?? ''));
    if ($employeeOnly && $accountType === 'admin' && $employeeRole === '') {
        $employeeRole = 'admin';
    }
    if ($employeeRole === 'super_admin' && !app_portal_is_super_admin($user)) {
        header('Location: ' . portal_redirect_url('admin-users.php', 'account_forbidden'));
        exit;
    }

    $accountPayload = [];
    $existingAccount = null;
    if ($accountId > 0) {
        $existingAccount = app_db_account_by_id($accountId);
        if (is_array($existingAccount)) {
            $accountPayload = app_db_account_payload($existingAccount);
        }
    }
    $targetLogin = strtolower(trim((string) ($_POST['login'] ?? ($existingAccount['login'] ?? ''))));
    $isProtectedDefaultSuperAdmin = $targetLogin === 's.abdullayev@superoptic.net'
        || strtolower(trim((string) ($existingAccount['login'] ?? ''))) === 's.abdullayev@superoptic.net';
    $willBeActive = isset($_POST['is_active']) ? 1 : 0;
    if ($isProtectedDefaultSuperAdmin
        && !app_portal_is_super_admin($user)
        && ($role !== 'employee' || $accountType !== 'admin' || $employeeRole !== 'super_admin' || $willBeActive !== 1)
    ) {
        header('Location: ' . portal_redirect_url('admin-users.php', 'protected_account'));
        exit;
    }
    $previousPrivileges = [
        'role' => $existingAccount['role'] ?? null,
        'account_type' => $existingAccount['account_type'] ?? null,
        'employee_role' => $existingAccount['employee_role'] ?? null,
        'is_active' => isset($existingAccount['is_active']) ? (int) $existingAccount['is_active'] : null,
    ];
    $newPrivileges = [
        'role' => $role,
        'account_type' => $accountType,
        'employee_role' => $role === 'employee' ? $employeeRole : '',
        'is_active' => $willBeActive,
    ];
    $privilegeChanged = $existingAccount !== null && $previousPrivileges !== $newPrivileges;
    $privilegeReason = clean_text((string) ($_POST['privilege_change_reason'] ?? ''));
    if ($privilegeChanged && $privilegeReason === '') {
        header('Location: ' . portal_redirect_url('admin-users.php', 'account_reason_required'));
        exit;
    }
    if ($employeeOnly) {
        $employeeMeta = is_array($accountPayload['employee'] ?? null) ? $accountPayload['employee'] : [];
        $salaryMode = clean_text((string) ($_POST['employee_salary_mode'] ?? 'gross'));
        if (!in_array($salaryMode, ['gross', 'net'], true)) {
            $salaryMode = 'gross';
        }
        $salaryGross = max(0, (float) ($_POST['employee_gross_salary'] ?? 0));
        $salaryNet = max(0, (float) ($_POST['employee_net_salary'] ?? 0));
        $salaryEffectiveFrom = clean_text((string) ($_POST['employee_salary_effective_from'] ?? date('Y-m-d')));
        $employeeMeta['telegram_chat_id'] = clean_text((string) ($_POST['telegram_chat_id'] ?? ''));
        $employeeMeta['compensation'] = [
            'salary_mode' => $salaryMode,
            'gross_salary' => $salaryGross,
            'net_salary' => $salaryNet,
            'effective_from' => $salaryEffectiveFrom,
        ];
        $employeeMeta['superpilot_enabled'] = true;
        $employeeMeta['updated_at'] = app_db_now();
        $accountPayload['employee'] = $employeeMeta;
    }

    $saved = app_db_upsert_account([
        'id' => $accountId,
        'login' => $_POST['login'] ?? '',
        'password' => $_POST['password'] ?? '',
        'role' => $role,
        'account_type' => $accountType,
        'employee_role' => $employeeRole,
        'job_title' => (string) ($existingAccount['job_title'] ?? ''),
        'fin_code' => (string) ($existingAccount['fin_code'] ?? ''),
        'document_no' => (string) ($existingAccount['document_no'] ?? ''),
        'full_name' => $_POST['full_name'] ?? '',
        'subscriber_code' => $employeeOnly ? '' : ($_POST['subscriber_code'] ?? ''),
        'contract_no' => $employeeOnly ? '' : ($_POST['contract_no'] ?? ''),
        'email' => $_POST['email'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'category' => $employeeOnly ? '' : ($_POST['category'] ?? ''),
        'area' => $_POST['area'] ?? '',
        'address' => $_POST['address'] ?? '',
        'plan' => $employeeOnly ? '' : ($_POST['plan'] ?? ''),
        'status' => $_POST['status'] ?? 'Aktiv',
        'responsible_areas' => $_POST['responsible_areas'] ?? [],
        'payload' => $accountPayload,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ]);

    if ($saved !== null && $employeeOnly) {
        $salaryMode = clean_text((string) ($_POST['employee_salary_mode'] ?? 'gross'));
        if (!in_array($salaryMode, ['gross', 'net'], true)) {
            $salaryMode = 'gross';
        }
        $salaryGross = max(0, (float) ($_POST['employee_gross_salary'] ?? 0));
        $salaryNet = max(0, (float) ($_POST['employee_net_salary'] ?? 0));
        if ($salaryGross > 0 || $salaryNet > 0) {
            app_mgs_save_hr_employee_compensation([
                'employee_account_id' => (int) ($saved['id'] ?? 0),
                'employee_name' => (string) ($saved['full_name'] ?? ''),
                'salary_mode' => $salaryMode,
                'gross_salary' => $salaryGross,
                'net_salary' => $salaryNet,
                'effective_from' => clean_text((string) ($_POST['employee_salary_effective_from'] ?? date('Y-m-d'))),
                'note_text' => 'Admin employee form',
            ], $user, app_db());
        }
    }
    if($saved!==null){
        $auditDb = app_db();
        if ($auditDb instanceof PDO) {
            app_audit_ensure_schema($auditDb);
            $auditDb->beginTransaction();
            try {
                app_audit_record($auditDb, [
                    'module_key' => 'admin',
                    'operation_class' => 'privilege',
                    'action_type' => 'admin_account_privileges_changed',
                    'entity_type' => 'account',
                    'entity_id' => (string) ($saved['id'] ?? 0),
                    'status' => 'executed',
                    'actor_account_id' => (int) ($user['id'] ?? 0),
                    'actor_name' => (string) ($user['full_name'] ?? $user['login'] ?? 'admin'),
                    'summary' => 'Admin account role and access settings saved',
                    'reason' => $privilegeReason !== '' ? $privilegeReason : 'Admin account saved',
                    'before' => $previousPrivileges,
                    'after' => [
                        'role' => $saved['role'] ?? null,
                        'account_type' => $saved['account_type'] ?? null,
                        'employee_role' => $saved['employee_role'] ?? null,
                        'is_active' => isset($saved['is_active']) ? (int) $saved['is_active'] : null,
                    ],
                    'sensitivity' => $privilegeChanged ? 'critical' : 'normal',
                ]);
                $auditDb->commit();
            } catch (Throwable) {
                if ($auditDb->inTransaction()) {
                    $auditDb->rollBack();
                }
            }
        }
    }

    header('Location: ' . portal_redirect_url('admin-users.php', $saved !== null ? 'account_saved' : 'account_failed'));
    exit;
}

if ($action === 'delete_account' && $user['role'] === 'employee' && app_portal_is_admin($user)) {
    if (!app_portal_is_super_admin($user)) {
        header('Location: ' . portal_redirect_url('admin-users.php', 'account_forbidden'));
        exit;
    }

    $accountId = (int) ($_POST['account_id'] ?? 0);
    $deleteReason = clean_text((string) ($_POST['delete_reason'] ?? ''));
    if ($deleteReason === '') {
        header('Location: ' . portal_redirect_url('admin-users.php', 'account_reason_required'));
        exit;
    }

    $deleteTarget = app_db_account_by_id($accountId);
    if (!is_array($deleteTarget)) {
        header('Location: ' . portal_redirect_url('admin-users.php', 'account_failed'));
        exit;
    }
    if (strtolower(trim((string) ($deleteTarget['login'] ?? ''))) === 's.abdullayev@superoptic.net'
        || (int) ($deleteTarget['id'] ?? 0) === (int) ($user['id'] ?? 0)
    ) {
        header('Location: ' . portal_redirect_url('admin-users.php', 'protected_account'));
        exit;
    }

    $deleteAreas = app_db_account_areas($accountId);
    $deletePayload = app_db_account_payload($deleteTarget);
    $db = app_db();
    $success = false;
    if ($db instanceof PDO) {
        $startedTransaction = !$db->inTransaction();
        try {
            app_audit_ensure_schema($db);
            if ($startedTransaction) {
                $db->beginTransaction();
            }
            $delete = $db->prepare('DELETE FROM portal_accounts WHERE id = :id');
            $delete->execute(['id' => $accountId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('account_delete_failed');
            }
            app_audit_record($db, [
                'module_key' => 'admin',
                'operation_class' => 'privilege',
                'action_type' => 'admin_account_deleted',
                'entity_type' => 'account',
                'entity_id' => (string) $accountId,
                'status' => 'executed',
                'actor_account_id' => (int) ($user['id'] ?? 0),
                'actor_name' => (string) ($user['full_name'] ?? $user['login'] ?? 'admin'),
                'summary' => 'Admin account deleted',
                'reason' => $deleteReason,
                'before' => [
                    'account' => $deleteTarget,
                    'areas' => $deleteAreas,
                    'payload' => $deletePayload,
                ],
                'after' => [
                    'deleted' => true,
                    'deleted_account_id' => $accountId,
                ],
                'sensitivity' => 'critical',
            ]);
            if ($startedTransaction) {
                $db->commit();
            }
            $success = true;
        } catch (Throwable) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            $success = false;
        }
    }
    if ($success) {
        app_db_sync_olt_account('delete', $deleteTarget);
    }
    header('Location: ' . portal_redirect_url('admin-users.php', $success ? 'account_deleted' : 'account_failed'));
    exit;
}

if ($action === 'import_accounts' && $user['role'] === 'employee' && app_portal_is_admin($user)) {
    $file = $_FILES['import_file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        header('Location: ' . portal_redirect_url('admin-users.php', 'import_failed'));
        exit;
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $rows = app_db_import_rows_from_file((string) ($file['tmp_name'] ?? ''), $extension);
    $result = $rows !== [] ? app_db_import_accounts($rows) : ['created' => 0, 'updated' => 0, 'errors' => ['Import fayli oxunmadi']];
    $params = [
        'status' => empty($result['errors']) ? 'import_done' : 'import_failed',
        'created' => (string) (int) ($result['created'] ?? 0),
        'updated' => (string) (int) ($result['updated'] ?? 0),
    ];
    if (!empty($result['errors'])) {
        $params['errors'] = implode(' | ', array_slice((array) $result['errors'], 0, 3));
    }
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }
    header('Location: admin-users.php?' . http_build_query($params));
    exit;
}

header('Location: ' . portal_redirect_url('portal.php'));
exit;
