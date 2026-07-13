<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/finance-bonus-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['status' => 'failed', 'errors' => ['db_unavailable']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$run = date('YmdHis') . random_int(100, 999);
$area = 'BonusSmokeArea' . $run;
$employeeId = 0;
$customerId = 0;
$noPaymentCustomerId = 0;
$assignmentId = 0;
$noPaymentAssignmentId = 0;
$periodId = 0;
$adjustmentId = 0;
$financeEntryId = 0;
$tierId = 0;
$bracketId = 0;
$qualityRuleId = 0;
$paymentExternal = 'bonus-smoke-' . $run;
$superActor = ['id' => 1, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'super_admin', 'full_name' => 'Bonus Smoke Super'];
$financeActor = ['id' => 2, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'finance', 'full_name' => 'Bonus Smoke Finance'];
$errors = [];

$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) {
        $errors[] = $error;
    }
};

try {
    app_bonus_ensure_schema($db);

    $employeeLogin = 'bonus.employee.' . $run . '@qa.local';
    $customerLogin = 'bonus.customer.' . $run . '@smoke.local';
    $subscriberCode = 'BSM' . $run;
    $hash = password_hash('SmokePass123!', PASSWORD_DEFAULT);
    $employeeInsert = $db->prepare('INSERT INTO portal_accounts (login,password_hash,role,account_type,employee_role,full_name,area,status,is_active,created_at) VALUES (:login,:password_hash,"employee","operator","sales",:full_name,:area,"active",1,"2000-12-01 00:00:00")');
    $employeeInsert->execute(['login' => $employeeLogin, 'password_hash' => $hash, 'full_name' => 'Bonus QA Employee ' . $run, 'area' => $area]);
    $employeeId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO portal_account_areas (account_id, area) VALUES (:account_id, :area)')->execute(['account_id' => $employeeId, 'area' => $area]);

    $customerInsert = $db->prepare('INSERT INTO portal_accounts (login,password_hash,role,account_type,employee_role,full_name,subscriber_code,area,plan,status,is_active,created_at) VALUES (:login,:password_hash,"customer","customer","",:full_name,:subscriber_code,:area,"Smoke 30","active",1,"2000-12-01 00:00:00")');
    $customerInsert->execute(['login' => $customerLogin, 'password_hash' => $hash, 'full_name' => 'Bonus Smoke Customer ' . $run, 'subscriber_code' => $subscriberCode, 'area' => $area]);
    $customerId = (int) $db->lastInsertId();
    $noPaymentSubscriberCode = 'BSMN' . $run;
    $customerInsert->execute(['login' => 'bonus.customer.nopay.' . $run . '@smoke.local', 'password_hash' => $hash, 'full_name' => 'Bonus Smoke No Pay ' . $run, 'subscriber_code' => $noPaymentSubscriberCode, 'area' => $area]);
    $noPaymentCustomerId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO billing_payments (external_id,account_id,subscriber_code,amount,payment_method,transaction_id,note,created_at) VALUES (:external_id,:account_id,:subscriber_code,20,"manual",:transaction_id,"bonus smoke","2001-01-02 10:00:00")')
        ->execute(['external_id' => $paymentExternal, 'account_id' => $customerId, 'subscriber_code' => $subscriberCode, 'transaction_id' => 'TRX-' . $run]);

    $assignment = app_bonus_save_assignment($db, [
        'subscriber_code' => $subscriberCode,
        'finder_employee_id' => $employeeId,
        'installer_employee_id' => $employeeId,
        'area_responsible_employee_id' => $employeeId,
        'bonus_owner_employee_id' => $employeeId,
        'assigned_area_id' => $area,
        'note' => 'smoke',
    ], $financeActor);
    $assignmentId = (int) ($assignment['id'] ?? 0);
    $expect(!empty($assignment['ok']) && $assignmentId > 0, 'assignment_create_failed:' . (string) ($assignment['error'] ?? '') . ':' . (string) ($assignment['detail'] ?? ''));
    $noPaymentAssignment = app_bonus_save_assignment($db, [
        'subscriber_code' => $noPaymentSubscriberCode,
        'finder_employee_id' => $employeeId,
        'installer_employee_id' => $employeeId,
        'area_responsible_employee_id' => $employeeId,
        'bonus_owner_employee_id' => $employeeId,
        'assigned_area_id' => $area,
        'note' => 'smoke no payment',
    ], $financeActor);
    $noPaymentAssignmentId = (int) ($noPaymentAssignment['id'] ?? 0);
    $expect(!empty($noPaymentAssignment['ok']) && $noPaymentAssignmentId > 0, 'no_payment_assignment_create_failed:' . (string) ($noPaymentAssignment['error'] ?? ''));

    $tier = app_bonus_save_salary_tier($db, ['min_customers' => 999999, 'max_customers' => '', 'salary_amount' => 999, 'area_quality_percent' => 100, 'is_active' => 1, 'reason' => 'smoke tier'], $superActor);
    $tierId = (int) ($tier['id'] ?? 0);
    $expect(!empty($tier['ok']) && $tierId > 0, 'salary_tier_save_failed:' . (string) ($tier['error'] ?? ''));
    $bracket = app_bonus_save_active_day_bracket($db, ['min_days' => 999, 'max_days' => 1000, 'active_percent' => 99, 'is_active' => 1, 'reason' => 'smoke bracket'], $superActor);
    $bracketId = (int) ($bracket['id'] ?? 0);
    $expect(!empty($bracket['ok']) && $bracketId > 0, 'active_day_bracket_save_failed:' . (string) ($bracket['error'] ?? ''));
    $quality = app_bonus_save_quality_rule($db, ['problem_key' => 'smoke_problem_' . $run, 'problem_label' => 'Smoke problem ' . $run, 'problem_source' => 'unknown', 'severity' => 'normal', 'quality_percent' => 75, 'is_active' => 1, 'reason' => 'smoke quality'], $superActor);
    $qualityRuleId = (int) ($quality['id'] ?? 0);
    $expect(!empty($quality['ok']) && $qualityRuleId > 0, 'quality_rule_save_failed:' . (string) ($quality['error'] ?? ''));

    $calc = app_bonus_calculate_period($db, '2001-01-01', '2001-01-31', $financeActor);
    $periodId = (int) ($calc['period_id'] ?? 0);
    $expect(!empty($calc['ok']) && $periodId > 0, 'period_calculate_failed:' . (string) ($calc['error'] ?? ''));
    $expect((int) ($calc['notifications'] ?? 0) >= 1, 'period_notifications_not_created');
    $notificationCheck = $db->prepare('SELECT COUNT(*) FROM employee_bonus_notifications WHERE period_id = :period_id AND customer_account_id = :customer_id AND notification_type = "new_customer_no_payment_7_days"');
    $notificationCheck->execute(['period_id' => $periodId, 'customer_id' => $noPaymentCustomerId]);
    $expect((int) $notificationCheck->fetchColumn() >= 1, 'no_payment_notification_missing');

    $summary = $db->prepare('SELECT * FROM employee_bonus_summaries WHERE period_id = :period_id AND employee_id = :employee_id');
    $summary->execute(['period_id' => $periodId, 'employee_id' => $employeeId]);
    $summaryRow = $summary->fetch(PDO::FETCH_ASSOC) ?: [];
    $summaryDebug = json_encode([
        'employee_id' => $employeeId,
        'period_id' => $periodId,
        'summary' => $summaryRow,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $expect((float) ($summaryRow['base_salary'] ?? 0) === 450.0, 'base_salary_tier_wrong:' . (string) $summaryDebug);
    $expect((float) ($summaryRow['new_connection_bonus'] ?? 0) === 5.0, 'new_connection_bonus_wrong:' . (string) $summaryDebug);
    $expect((float) ($summaryRow['recurring_bonus'] ?? 0) === 1.0, 'recurring_bonus_wrong:' . (string) $summaryDebug);

    $adjustment = app_bonus_save_adjustment($db, ['period_id' => $periodId, 'employee_id' => $employeeId, 'adjustment_type' => 'bonus', 'amount' => 10, 'reason' => 'smoke approved bonus'], $superActor);
    $adjustmentId = (int) ($adjustment['id'] ?? 0);
    $expect(!empty($adjustment['ok']) && ($adjustment['status'] ?? '') === 'approved', 'adjustment_not_approved_for_super_admin');

    $recalc = app_bonus_calculate_period($db, '2001-01-01', '2001-01-31', $financeActor);
    $expect(!empty($recalc['ok']) && (int) ($recalc['period_id'] ?? 0) === $periodId, 'recalculate_duplicate_period');
    $summary->execute(['period_id' => $periodId, 'employee_id' => $employeeId]);
    $summaryRow = $summary->fetch(PDO::FETCH_ASSOC) ?: [];
    $expect((float) ($summaryRow['manual_adjustment'] ?? 0) === 10.0, 'manual_adjustment_not_applied');
    $expect((float) ($summaryRow['total_payable'] ?? 0) === 466.0, 'total_payable_wrong');
    $summaryCount = $db->prepare('SELECT COUNT(*) FROM employee_bonus_summaries WHERE period_id = :period_id AND employee_id = :employee_id');
    $summaryCount->execute(['period_id' => $periodId, 'employee_id' => $employeeId]);
    $expect((int) $summaryCount->fetchColumn() === 1, 'recalculate_duplicate_summary');
    $summaryExport = app_bonus_export_csv($db, $periodId, 'summary', $financeActor);
    $itemsExport = app_bonus_export_csv($db, $periodId, 'items', $financeActor, $employeeId);
    $expect(!empty($summaryExport['ok']) && str_contains((string) ($summaryExport['csv'] ?? ''), 'Bonus QA Employee'), 'summary_export_failed');
    $expect(!empty($itemsExport['ok']) && str_contains((string) ($itemsExport['csv'] ?? ''), $subscriberCode), 'items_export_failed');

    $deniedSetting = app_bonus_save_setting($db, ['setting_key' => 'new_connection_bonus_amount', 'setting_value' => '6', 'value_type' => 'decimal'], $financeActor);
    $expect(($deniedSetting['error'] ?? '') === 'super_admin_required', 'finance_can_change_bonus_setting');
    $setting = app_bonus_save_setting($db, ['setting_key' => 'new_connection_bonus_amount', 'setting_value' => '5', 'value_type' => 'decimal'], $superActor);
    $expect(!empty($setting['ok']), 'super_admin_setting_save_failed');

    $pending = app_bonus_update_period_status($db, $periodId, 'pending_approval', $financeActor, 'smoke pending');
    $approved = app_bonus_update_period_status($db, $periodId, 'approved', $superActor, 'smoke approved');
    $paid = app_bonus_update_period_status($db, $periodId, 'paid', $financeActor, 'smoke paid');
    $expect(!empty($pending['ok']), 'pending_transition_failed:' . (string) ($pending['error'] ?? ''));
    $expect(!empty($approved['ok']), 'approved_transition_failed:' . (string) ($approved['error'] ?? ''));
    $expect(!empty($paid['ok']), 'paid_transition_failed:' . (string) ($paid['error'] ?? ''));
    $period = app_bonus_period_by_id($db, $periodId) ?: [];
    $financeEntryId = (int) ($period['finance_entry_id'] ?? 0);
    $expect(($period['status'] ?? '') === 'paid' && $financeEntryId > 0, 'paid_period_missing_finance_entry');

    $auditCount = $db->prepare('SELECT COUNT(*) FROM bonus_audit_logs WHERE entity_id = :period_id OR entity_id = :assignment_id OR entity_id = :adjustment_id');
    $auditCount->execute(['period_id' => (string) $periodId, 'assignment_id' => (string) $assignmentId, 'adjustment_id' => (string) $adjustmentId]);
    $expect((int) $auditCount->fetchColumn() >= 3, 'bonus_audit_missing');
} finally {
    if ($financeEntryId > 0) {
        $db->prepare('DELETE FROM finance_entries WHERE id = :id')->execute(['id' => $financeEntryId]);
    }
    if ($periodId > 0) {
        $db->prepare('DELETE FROM employee_bonus_notifications WHERE period_id = :period_id')->execute(['period_id' => $periodId]);
        $db->prepare('DELETE FROM employee_bonus_items WHERE period_id = :period_id')->execute(['period_id' => $periodId]);
        $db->prepare('DELETE FROM employee_bonus_summaries WHERE period_id = :period_id')->execute(['period_id' => $periodId]);
        $db->prepare('DELETE FROM employee_bonus_adjustments WHERE period_id = :period_id')->execute(['period_id' => $periodId]);
        $db->prepare('DELETE FROM bonus_periods WHERE id = :id')->execute(['id' => $periodId]);
        $db->prepare('DELETE FROM bonus_audit_logs WHERE entity_id = :period_id')->execute(['period_id' => (string) $periodId]);
    }
    if ($assignmentId > 0) {
        $db->prepare('DELETE FROM customer_employee_assignments WHERE id = :id')->execute(['id' => $assignmentId]);
        $db->prepare('DELETE FROM bonus_audit_logs WHERE entity_id = :id')->execute(['id' => (string) $assignmentId]);
    }
    if ($noPaymentAssignmentId > 0) {
        $db->prepare('DELETE FROM customer_employee_assignments WHERE id = :id')->execute(['id' => $noPaymentAssignmentId]);
        $db->prepare('DELETE FROM bonus_audit_logs WHERE entity_id = :id')->execute(['id' => (string) $noPaymentAssignmentId]);
    }
    if ($customerId > 0) {
        $db->prepare('DELETE FROM billing_payments WHERE account_id = :account_id')->execute(['account_id' => $customerId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $customerId]);
    }
    if ($noPaymentCustomerId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $noPaymentCustomerId]);
    }
    if ($employeeId > 0) {
        $db->prepare('DELETE FROM portal_account_areas WHERE account_id = :account_id')->execute(['account_id' => $employeeId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $employeeId]);
    }
    if ($tierId > 0) {
        $db->prepare('DELETE FROM bonus_base_salary_tiers WHERE id = :id')->execute(['id' => $tierId]);
        $db->prepare('DELETE FROM bonus_audit_logs WHERE entity_type = "bonus_base_salary_tier" AND entity_id = :id')->execute(['id' => (string) $tierId]);
    }
    if ($bracketId > 0) {
        $db->prepare('DELETE FROM bonus_active_day_brackets WHERE id = :id')->execute(['id' => $bracketId]);
        $db->prepare('DELETE FROM bonus_audit_logs WHERE entity_type = "bonus_active_day_bracket" AND entity_id = :id')->execute(['id' => (string) $bracketId]);
    }
    if ($qualityRuleId > 0) {
        $db->prepare('DELETE FROM bonus_quality_rules WHERE id = :id')->execute(['id' => $qualityRuleId]);
        $db->prepare('DELETE FROM bonus_audit_logs WHERE entity_type = "bonus_quality_rule" AND entity_id = :id')->execute(['id' => (string) $qualityRuleId]);
    }
    $db->prepare('DELETE FROM mgs_operation_actions WHERE actor_name IN ("Bonus Smoke Super","Bonus Smoke Finance")')->execute();
}

echo json_encode(['status' => $errors === [] ? 'passed' : 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
