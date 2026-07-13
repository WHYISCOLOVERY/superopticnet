<?php
declare(strict_types=1);

require dirname(__DIR__) . '/finance-module-lib.php';
require_once dirname(__DIR__) . '/billing-ui.php';

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}
app_bonus_ensure_schema($db);

$userStatement = $db->prepare('SELECT * FROM portal_accounts WHERE login = :login OR role = :role OR account_type = :account_type ORDER BY (login = :preferred_login) DESC, id LIMIT 1');
$userStatement->execute(['login' => 's.abdullayev@superoptic.net', 'role' => 'super_admin', 'account_type' => 'super_admin', 'preferred_login' => 's.abdullayev@superoptic.net']);
$user = $userStatement->fetch(PDO::FETCH_ASSOC);
$sample = $db->query(
    'SELECT s.employee_id, p.period_start, p.period_end, s.total_payable
     FROM employee_bonus_summaries s
     INNER JOIN bonus_periods p ON p.id = s.period_id
     ORDER BY p.period_start DESC, s.id DESC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($user)) {
    fwrite(STDOUT, json_encode(['ok' => true, 'skipped' => 'super_admin_missing']) . PHP_EOL);
    exit(0);
}

$temporaryPeriodId = 0;
$temporaryEmployeeId = 0;
if (!is_array($sample)) {
    $employee = $db->query('SELECT id, full_name FROM portal_accounts WHERE role = "employee" AND is_active = 1 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($employee)) {
        fwrite(STDOUT, json_encode(['ok' => true, 'skipped' => 'employee_missing']) . PHP_EOL);
        exit(0);
    }
    $year = 2070 + random_int(0, 20);
    $periodStart = sprintf('%04d-01-01', $year);
    $periodEnd = sprintf('%04d-01-31', $year);
    $db->prepare('DELETE FROM bonus_periods WHERE period_start = :period_start AND period_end = :period_end')->execute(['period_start' => $periodStart, 'period_end' => $periodEnd]);
    $insertPeriod = $db->prepare('INSERT INTO bonus_periods (period_start, period_end, status, employee_count, total_base_salary, total_payable) VALUES (:period_start, :period_end, "calculated", 1, 450, 450)');
    $insertPeriod->execute(['period_start' => $periodStart, 'period_end' => $periodEnd]);
    $temporaryPeriodId = (int) $db->lastInsertId();
    $temporaryEmployeeId = (int) $employee['id'];
    $insertSummary = $db->prepare('INSERT INTO employee_bonus_summaries (period_id, employee_id, employee_name, base_salary, total_payable) VALUES (:period_id, :employee_id, :employee_name, 450, 450)');
    $insertSummary->execute(['period_id' => $temporaryPeriodId, 'employee_id' => $temporaryEmployeeId, 'employee_name' => (string) ($employee['full_name'] ?? 'Smoke employee')]);
    $db->prepare('INSERT INTO employee_bonus_items (period_id, employee_id, item_type, base_amount, active_days, active_percent, amount, status, reason) VALUES (:period_id, :employee_id, "base_salary", 450, 31, 100, 450, "payable", "smoke")')->execute(['period_id' => $temporaryPeriodId, 'employee_id' => $temporaryEmployeeId]);
    $sample = ['employee_id' => $temporaryEmployeeId, 'period_start' => $periodStart, 'period_end' => $periodEnd, 'total_payable' => 450];
}

$query = [
    'bonus_view' => 'employee-detail',
    'employee_id' => (int) $sample['employee_id'],
    'period_start' => (string) $sample['period_start'],
    'period_end' => (string) $sample['period_end'],
    'page' => 1,
    'limit' => 50,
];
$bonus = app_bonus_context($db, $user, $query);
$errors = [];
if (($bonus['selected_period_start'] ?? '') !== $query['period_start'] || ($bonus['selected_period_end'] ?? '') !== $query['period_end']) {
    $errors[] = 'date_range_not_preserved';
}
if (abs((float) ($bonus['employee_summary']['total_payable'] ?? -1) - (float) $sample['total_payable']) > 0.001) {
    $errors[] = 'summary_total_mismatch';
}

$financeContext = ['data' => ['bonus' => $bonus]];
$financeLang = 'az';
$financeTab = 'employee-bonuses';
ob_start();
require dirname(__DIR__) . '/views/finance/employee-bonuses.php';
$employeeHtml = (string) ob_get_clean();
foreach (['type="date" name="period_start"', 'type="date" name="period_end"', 'data-finance-filter', 'Tarix ve ya emekdas deyisdirilen kimi'] as $marker) {
    if (!str_contains($employeeHtml, $marker)) {
        $errors[] = 'employee_view_missing:' . $marker;
    }
}

$rulesBonus = app_bonus_context($db, $user, ['bonus_view' => 'rules']);
$financeContext = ['data' => ['bonus' => $rulesBonus]];
ob_start();
require dirname(__DIR__) . '/views/finance/employee-bonuses.php';
$rulesHtml = (string) ob_get_clean();
foreach (['Bonus meblegleri', 'Yeni qosulma bonusu', 'Musteri sayina gore sabit maas', 'Aktiv gun sayina gore bonus faizi', 'data-bonus-rule-picker="salary"', 'data-bonus-rule-picker="quality"'] as $marker) {
    if (!str_contains($rulesHtml, $marker)) {
        $errors[] = 'rules_view_missing:' . $marker;
    }
}
if (str_contains($rulesHtml, '<label>Ayar acari</label>') || str_contains($rulesHtml, '<label>ID (yenileme)</label>')) {
    $errors[] = 'technical_field_still_visible';
}

fwrite(STDOUT, json_encode([
    'ok' => $errors === [],
    'employee_id' => (int) $sample['employee_id'],
    'period_start' => $query['period_start'],
    'period_end' => $query['period_end'],
    'item_count' => count((array) ($bonus['items_page']['rows'] ?? [])),
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
if ($temporaryPeriodId > 0) {
    $db->prepare('DELETE FROM employee_bonus_items WHERE period_id = :period_id')->execute(['period_id' => $temporaryPeriodId]);
    $db->prepare('DELETE FROM employee_bonus_summaries WHERE period_id = :period_id')->execute(['period_id' => $temporaryPeriodId]);
    $db->prepare('DELETE FROM bonus_periods WHERE id = :period_id')->execute(['period_id' => $temporaryPeriodId]);
}
exit($errors === [] ? 0 : 1);
