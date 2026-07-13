<?php
declare(strict_types=1);

require dirname(__DIR__) . '/billing-customer-identity-lib.php';
require dirname(__DIR__) . '/finance-operations-lib.php';
require dirname(__DIR__) . '/inventory-operations-lib.php';

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'sensitive-reason-smoke';
$_SERVER['HTTP_X_REQUEST_ID'] = 'sensitive-reason-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));

$db = app_db();
$errors = [];

function sensitive_smoke_expect(bool $condition, string $error, array &$errors): void
{
    if (!$condition) {
        $errors[] = $error;
    }
}

function sensitive_smoke_latest_action(PDO $db, string $actionType, string $entityId): array
{
    $statement = $db->prepare(
        'SELECT *
           FROM mgs_operation_actions
          WHERE action_type = :action_type
            AND entity_id = :entity_id
          ORDER BY id DESC
          LIMIT 1'
    );
    $statement->execute(['action_type' => $actionType, 'entity_id' => $entityId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function sensitive_smoke_assert_audit(PDO $db, string $actionType, string $entityId, string $reason, array &$errors): void
{
    $row = sensitive_smoke_latest_action($db, $actionType, $entityId);
    sensitive_smoke_expect($row !== [], $actionType . '_audit_missing', $errors);
    if ($row === []) {
        return;
    }
    sensitive_smoke_expect((string) ($row['reason'] ?? '') === $reason, $actionType . '_reason_missing', $errors);
    sensitive_smoke_expect((string) ($row['before_json'] ?? '') !== '', $actionType . '_before_missing', $errors);
    sensitive_smoke_expect((string) ($row['after_json'] ?? '') !== '', $actionType . '_after_missing', $errors);
    sensitive_smoke_expect((int) ($row['actor_account_id'] ?? 0) > 0, $actionType . '_actor_missing', $errors);
    sensitive_smoke_expect((string) ($row['request_ip'] ?? '') === '127.0.0.1', $actionType . '_ip_missing', $errors);
    sensitive_smoke_expect((string) ($row['user_agent'] ?? '') === 'sensitive-reason-smoke', $actionType . '_agent_missing', $errors);
    sensitive_smoke_expect(str_starts_with((string) ($row['request_id'] ?? ''), 'sensitive-reason-'), $actionType . '_request_id_missing', $errors);
}

if (!$db instanceof PDO) {
    $errors[] = 'db_not_ready';
} else {
    app_mgs_ensure_schema($db);
    app_audit_ensure_schema($db);
    app_finance_ensure_operational_schema($db);
    app_inventory_ensure_operational_schema($db);

    $actor = ['id' => 990001, 'role' => 'employee', 'employee_role' => 'finance', 'account_type' => 'admin', 'full_name' => 'Sensitive Smoke'];
    $hrActor = ['id' => 990002, 'role' => 'employee', 'employee_role' => 'hr', 'account_type' => 'admin', 'full_name' => 'Sensitive HR Smoke'];
    $warehouseActor = ['id' => 990003, 'role' => 'employee', 'employee_role' => 'warehouse_manager', 'account_type' => 'admin', 'full_name' => 'Sensitive Warehouse Smoke'];
    $run = bin2hex(random_bytes(5));
    $financeEntryId = 0;
    $financeVoidId = 0;
    $employeeId = 0;
    $itemId = 0;

    try {
        if (!function_exists('app_billing_validate_sensitive_change')) {
            $errors[] = 'billing_sensitive_validator_missing';
        } else {
            $billingBefore = ['billing_days' => 30, 'stable_due_day' => 1, 'tariff' => 'OLD', 'expires_at' => '2026-07-31 23:59:59', 'is_active' => 1];
            $billingAfter = ['billing_days' => 35, 'stable_due_day' => 5, 'tariff' => 'NEW', 'expires_at' => '2026-08-05 23:59:59', 'is_active' => 1];
            $missingReason = app_billing_validate_sensitive_change($billingBefore, $billingAfter, '');
            sensitive_smoke_expect(empty($missingReason['ok']) && ($missingReason['error'] ?? '') === 'reason_required', 'billing_reason_not_required', $errors);
            $withReason = app_billing_validate_sensitive_change($billingBefore, $billingAfter, 'Customer approved service day change');
            sensitive_smoke_expect(!empty($withReason['ok']) && !empty($withReason['sensitive']), 'billing_reason_validation_failed', $errors);
        }

        $created = app_finance_save_entry($db, [
            'entry_type' => 'income',
            'title' => 'Sensitive income ' . $run,
            'category' => 'manual',
            'amount' => 100,
            'currency' => 'AZN',
            'transaction_date' => date('Y-m-d'),
            'reference_no' => 'SENS-UPD-' . $run,
        ], $actor);
        $financeEntryId = (int) ($created['id'] ?? 0);
        sensitive_smoke_expect(!empty($created['ok']) && $financeEntryId > 0, 'finance_entry_seed_failed', $errors);
        if ($financeEntryId > 0) {
            $missingUpdateReason = app_finance_save_entry($db, [
                'entry_id' => $financeEntryId,
                'entry_type' => 'income',
                'title' => 'Sensitive income updated ' . $run,
                'category' => 'service',
                'amount' => 125,
                'currency' => 'AZN',
                'transaction_date' => date('Y-m-d'),
                'reference_no' => 'SENS-UPD-' . $run,
            ], $actor);
            sensitive_smoke_expect(empty($missingUpdateReason['ok']) && ($missingUpdateReason['error'] ?? '') === 'reason_required', 'finance_update_reason_not_required', $errors);

            $updateReason = 'Bank extract correction';
            $updated = app_finance_save_entry($db, [
                'entry_id' => $financeEntryId,
                'entry_type' => 'income',
                'title' => 'Sensitive income updated ' . $run,
                'category' => 'service',
                'amount' => 125,
                'currency' => 'AZN',
                'transaction_date' => date('Y-m-d'),
                'reference_no' => 'SENS-UPD-' . $run,
                'reason' => $updateReason,
            ], $actor);
            sensitive_smoke_expect(!empty($updated['ok']), 'finance_update_with_reason_failed', $errors);
            sensitive_smoke_assert_audit($db, 'finance_entry_updated', (string) $financeEntryId, $updateReason, $errors);
        }

        $createdVoid = app_finance_save_entry($db, [
            'entry_type' => 'expense',
            'title' => 'Sensitive void ' . $run,
            'category' => 'manual',
            'amount' => 20,
            'currency' => 'AZN',
            'transaction_date' => date('Y-m-d'),
            'reference_no' => 'SENS-VOID-' . $run,
        ], $actor);
        $financeVoidId = (int) ($createdVoid['id'] ?? 0);
        if ($financeVoidId > 0) {
            $missingVoidReason = app_finance_void_entry($db, $financeVoidId, $actor);
            sensitive_smoke_expect(empty($missingVoidReason['ok']) && ($missingVoidReason['error'] ?? '') === 'reason_required', 'finance_void_reason_not_required', $errors);
            $voidReason = 'Duplicate receipt voided';
            $voided = app_finance_void_entry($db, $financeVoidId, $actor, $voidReason);
            sensitive_smoke_expect(!empty($voided['ok']), 'finance_void_with_reason_failed', $errors);
            sensitive_smoke_assert_audit($db, 'finance_entry_voided', (string) $financeVoidId, $voidReason, $errors);
        } else {
            $errors[] = 'finance_void_seed_failed';
        }

        $employeeInsert = $db->prepare(
            'INSERT INTO portal_accounts(login,password_hash,role,account_type,employee_role,full_name,status,is_active,payload_json)
             VALUES(:login,:password_hash,"employee","employee","technician","Sensitive HR Employee","Aktiv",1,"{}")'
        );
        $employeeInsert->execute([
            'login' => 'sensitive-hr-' . $run . '@local',
            'password_hash' => password_hash('smoke', PASSWORD_DEFAULT),
        ]);
        $employeeId = (int) $db->lastInsertId();
        sensitive_smoke_expect($employeeId > 0, 'hr_employee_seed_failed', $errors);
        if ($employeeId > 0) {
            $missingCompReason = app_mgs_save_hr_employee_compensation([
                'employee_account_id' => $employeeId,
                'salary_mode' => 'gross',
                'gross_salary' => 1200,
                'effective_from' => '2026-07-01',
                'reason' => '',
            ], $hrActor, $db);
            sensitive_smoke_expect(empty($missingCompReason['ok']) && ($missingCompReason['error'] ?? '') === 'reason_required', 'hr_compensation_reason_not_required', $errors);

            $compReason = 'Employment contract salary clause';
            $compensation = app_mgs_save_hr_employee_compensation([
                'employee_account_id' => $employeeId,
                'salary_mode' => 'gross',
                'gross_salary' => 1200,
                'effective_from' => '2026-07-01',
                'reason' => $compReason,
            ], $hrActor, $db);
            sensitive_smoke_expect(!empty($compensation['ok']), 'hr_compensation_with_reason_failed', $errors);
            sensitive_smoke_assert_audit($db, 'hr_employee_compensation_save', (string) $employeeId, $compReason, $errors);

            $missingScoreReason = app_mgs_record_hr_score_event([
                'employee_account_id' => $employeeId,
                'event_type' => 'reset',
                'reason_code' => 'manual',
                'event_date' => '2026-07-02',
            ], $hrActor, $db);
            sensitive_smoke_expect(empty($missingScoreReason['ok']) && ($missingScoreReason['error'] ?? '') === 'reason_required', 'hr_score_reason_not_required', $errors);
        }

        $sku = 'SNS-' . strtoupper($run);
        $db->prepare('INSERT INTO inventory_stock_items(sku,item_name,category,unit,quantity_on_hand,minimum_quantity,status) VALUES(:sku,"Sensitive item","ont_modem","eded",5,0,"active")')
            ->execute(['sku' => $sku]);
        $itemId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO inventory_location_balances(item_id,location_type,location_id,available_quantity) VALUES(:item,"warehouse","MAIN",5)')
            ->execute(['item' => $itemId]);
        $missingInventoryReason = app_inventory_adjust_stock($db, [
            'item_id' => $itemId,
            'location_type' => 'warehouse',
            'location_id' => 'MAIN',
            'quantity_delta' => -1,
            'reason' => '',
        ], $warehouseActor);
        sensitive_smoke_expect(empty($missingInventoryReason['ok']) && ($missingInventoryReason['error'] ?? '') === 'reason_required', 'inventory_reason_not_required', $errors);
        $inventoryReason = 'Physical count correction';
        $adjusted = app_inventory_adjust_stock($db, [
            'item_id' => $itemId,
            'location_type' => 'warehouse',
            'location_id' => 'MAIN',
            'quantity_delta' => -1,
            'reason' => $inventoryReason,
        ], $warehouseActor);
        sensitive_smoke_expect(!empty($adjusted['ok']), 'inventory_adjust_with_reason_failed', $errors);
        sensitive_smoke_assert_audit($db, 'inventory_stock_adjusted', (string) $itemId, $inventoryReason, $errors);
    } catch (Throwable $exception) {
        $errors[] = 'exception:' . $exception->getMessage();
    } finally {
        foreach ([$financeEntryId, $financeVoidId] as $id) {
            if ($id > 0) {
                try {
                    $db->prepare('DELETE FROM finance_entries WHERE id = :id')->execute(['id' => $id]);
                } catch (Throwable) {
                }
            }
        }
        if ($employeeId > 0) {
            try {
                $db->prepare('DELETE FROM hr_score_events WHERE employee_account_id = :id')->execute(['id' => $employeeId]);
                $db->prepare('DELETE FROM hr_employee_compensations WHERE employee_account_id = :id')->execute(['id' => $employeeId]);
                $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $employeeId]);
            } catch (Throwable) {
            }
        }
        if ($itemId > 0) {
            try {
                $db->prepare('DELETE FROM inventory_location_balances WHERE item_id = :id')->execute(['id' => $itemId]);
                $db->prepare('DELETE FROM inventory_stock_movements WHERE item_id = :id')->execute(['id' => $itemId]);
                $db->prepare('DELETE FROM inventory_lifecycle_movements WHERE product_id = :id')->execute(['id' => $itemId]);
                $db->prepare('DELETE FROM inventory_stock_items WHERE id = :id')->execute(['id' => $itemId]);
            } catch (Throwable) {
            }
        }
        try {
            $db->prepare('DELETE FROM mgs_operation_actions WHERE actor_name IN ("Sensitive Smoke","Sensitive HR Smoke","Sensitive Warehouse Smoke")')->execute();
        } catch (Throwable) {
        }
    }
}

echo json_encode(['ok' => $errors === [], 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($errors === [] ? 0 : 1);
