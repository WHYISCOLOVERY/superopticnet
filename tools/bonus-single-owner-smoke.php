<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/finance-bonus-lib.php';

$db = app_db();
$run = date('YmdHis') . random_int(100, 999);
$employeeIds = [];
$customerId = 0;
$assignmentIds = [];
$periodId = 0;
$errors = [];
$actor = ['id' => 1, 'role' => 'employee', 'employee_role' => 'finance', 'full_name' => 'Single owner smoke'];
$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) $errors[] = $error;
};

try {
    app_bonus_ensure_schema($db);
    $hash = password_hash('SmokePass123!', PASSWORD_DEFAULT);
    $employeeInsert = $db->prepare(
        'INSERT INTO portal_accounts
            (login,password_hash,role,account_type,employee_role,full_name,status,is_active)
         VALUES (:login,:password_hash,"employee","operator","technician",:full_name,"active",1)'
    );
    foreach ([1, 2] as $number) {
        $employeeInsert->execute([
            'login' => "bonus.single.owner.{$number}.{$run}@qa.local",
            'password_hash' => $hash,
            'full_name' => "Bonus single owner {$number} {$run}",
        ]);
        $employeeIds[] = (int) $db->lastInsertId();
    }

    $subscriberCode = 'BSO' . $run;
    $customerInsert = $db->prepare(
        'INSERT INTO portal_accounts
            (login,password_hash,role,account_type,employee_role,full_name,subscriber_code,status,is_active)
         VALUES (:login,:password_hash,"customer","customer","",:full_name,:subscriber_code,"active",1)'
    );
    $customerInsert->execute([
        'login' => "bonus.single.customer.{$run}@qa.local",
        'password_hash' => $hash,
        'full_name' => 'Bonus single customer ' . $run,
        'subscriber_code' => $subscriberCode,
    ]);
    $customerId = (int) $db->lastInsertId();

    $first = app_bonus_save_assignment($db, [
        'customer_account_id' => $customerId,
        'finder_employee_id' => $employeeIds[1],
        'installer_employee_id' => $employeeIds[1],
        'bonus_owner_employee_id' => $employeeIds[0],
    ], $actor);
    $assignmentIds[] = (int) ($first['id'] ?? 0);
    $expect(!empty($first['ok']), 'first_assignment_failed');

    $assignment = $db->query('SELECT * FROM customer_employee_assignments WHERE id=' . (int) $assignmentIds[0])->fetch(PDO::FETCH_ASSOC) ?: [];
    $expect(app_bonus_employee_share($db, $assignment, $employeeIds[0], 'new_connection') === 100.0, 'owner_missing_new_connection_share');
    $expect(app_bonus_employee_share($db, $assignment, $employeeIds[0], 'recurring') === 100.0, 'owner_missing_recurring_share');
    $expect(app_bonus_employee_share($db, $assignment, $employeeIds[1], 'new_connection') === 0.0, 'finder_received_duplicate_new_connection_share');
    $expect(app_bonus_employee_share($db, $assignment, $employeeIds[1], 'recurring') === 0.0, 'installer_received_duplicate_recurring_share');

    $second = app_bonus_save_assignment($db, [
        'customer_account_id' => $customerId,
        'bonus_owner_employee_id' => $employeeIds[1],
    ], $actor);
    $assignmentIds[] = (int) ($second['id'] ?? 0);
    $expect(!empty($second['ok']), 'owner_transfer_failed');
    $activeCount = $db->prepare('SELECT COUNT(*) FROM customer_employee_assignments WHERE customer_account_id=? AND status="active"');
    $activeCount->execute([$customerId]);
    $expect((int) $activeCount->fetchColumn() === 1, 'customer_has_multiple_active_owners');

    $duplicateBlocked = false;
    try {
        $db->prepare(
            'INSERT INTO customer_employee_assignments
                (customer_account_id,bonus_owner_employee_id,split_mode,status)
             VALUES (?, ?, "single_owner", "active")'
        )->execute([$customerId, $employeeIds[0]]);
    } catch (PDOException $exception) {
        $duplicateBlocked = (string) $exception->getCode() === '23000';
    }
    $expect($duplicateBlocked, 'database_allowed_second_active_owner');

    $db->prepare(
        'INSERT INTO bonus_periods (period_start,period_end,status,created_by_account_id)
         VALUES (?,?,"draft",?)'
    )->execute(['2099-11-01', '2099-11-30', 1]);
    $periodId = (int) $db->lastInsertId();
    $itemInsert = $db->prepare(
        'INSERT INTO employee_bonus_items
            (period_id,employee_id,customer_account_id,subscriber_code,item_type,amount,status)
         VALUES (?,?,?,?,"recurring_active_payment",1,"payable")'
    );
    $itemInsert->execute([$periodId, $employeeIds[1], $customerId, $subscriberCode]);
    $duplicateAwardBlocked = false;
    try {
        $itemInsert->execute([$periodId, $employeeIds[0], $customerId, $subscriberCode]);
    } catch (PDOException $exception) {
        $duplicateAwardBlocked = (string) $exception->getCode() === '23000';
    }
    $expect($duplicateAwardBlocked, 'database_allowed_duplicate_customer_monthly_bonus');
} finally {
    if ($periodId > 0) {
        $db->prepare('DELETE FROM employee_bonus_items WHERE period_id=?')->execute([$periodId]);
        $db->prepare('DELETE FROM bonus_periods WHERE id=?')->execute([$periodId]);
    }
    if ($customerId > 0) {
        $db->prepare('DELETE FROM customer_employee_assignments WHERE customer_account_id=?')->execute([$customerId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id=?')->execute([$customerId]);
    }
    foreach ($assignmentIds as $assignmentId) {
        if ($assignmentId > 0) $db->prepare('DELETE FROM bonus_audit_logs WHERE entity_id=?')->execute([(string) $assignmentId]);
    }
    foreach ($employeeIds as $employeeId) {
        $db->prepare('DELETE FROM portal_accounts WHERE id=?')->execute([$employeeId]);
    }
    $db->prepare('DELETE FROM mgs_operation_actions WHERE actor_name="Single owner smoke"')->execute();
}

echo json_encode(['status' => $errors === [] ? 'passed' : 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
