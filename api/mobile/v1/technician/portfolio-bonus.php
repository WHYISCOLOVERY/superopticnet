<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/finance-bonus-lib.php';
require_once dirname(__DIR__, 4) . '/mgs-integration-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer']);

$accountId = (int) $user['id'];
$db = app_db();

$period = strtolower(trim((string) ($_GET['period'] ?? 'month')));
$now = new DateTimeImmutable('now');
if ($period === 'year') {
    $periodStart = $now->format('Y-01-01');
    $periodEnd = $now->format('Y-12-31');
    $periodLabel = $now->format('Y') . ' ili';
} else {
    $period = 'month';
    $periodStart = $now->format('Y-m-01');
    $periodEnd = $now->format('Y-m-t');
    $periodLabel = $now->format('Y-m') . ' ayı';
}

$assignmentWhere = '(a.installer_employee_id = :employee_id OR a.area_responsible_employee_id = :employee_id OR a.finder_employee_id = :employee_id OR a.bonus_owner_employee_id = :employee_id)';
$assignmentParams = ['employee_id' => $accountId];

$portfolioStatement = $db->prepare(
    "SELECT
        COUNT(DISTINCT p.id) AS total_customers,
        COUNT(DISTINCT CASE WHEN p.status = 'active' THEN p.id END) AS active_customers,
        COUNT(DISTINCT CASE WHEN COALESCE(p.billing_debt_amount, 0) > 0 THEN p.id END) AS debt_customers,
        COALESCE(SUM(COALESCE(p.billing_debt_amount, 0)), 0) AS total_debt_amount,
        COUNT(DISTINCT CASE WHEN a.bonus_owner_employee_id = :employee_id THEN p.id END) AS bonus_owner_customers
       FROM customer_employee_assignments a
       JOIN portal_accounts p ON p.id = a.customer_account_id
      WHERE {$assignmentWhere}
        AND a.status = 'active'"
);
$portfolioStatement->execute($assignmentParams);
$portfolio = $portfolioStatement->fetch(PDO::FETCH_ASSOC) ?: [];

$problemStatement = $db->prepare(
    "SELECT COUNT(DISTINCT p.id)
       FROM customer_employee_assignments a
       JOIN portal_accounts p ON p.id = a.customer_account_id
       JOIN portal_tickets t ON t.customer_account_id = p.id
      WHERE {$assignmentWhere}
        AND a.status = 'active'
        AND t.status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir')
        AND (t.ticket_no NOT LIKE 'LEGACY-%' OR t.opened_at >= (NOW() - INTERVAL 24 HOUR))"
);
$problemStatement->execute($assignmentParams);
$problemCustomers = (int) $problemStatement->fetchColumn();

$riskStatement = $db->prepare(
    "SELECT
        p.id,
        p.full_name,
        p.subscriber_code,
        p.contract_no,
        p.phone,
        p.address,
        p.area,
        p.plan,
        p.status,
        COALESCE(p.billing_debt_amount, 0) AS debt_azn,
        COUNT(t.id) AS active_ticket_count,
        MAX(t.opened_at) AS latest_ticket_at,
        SUBSTRING_INDEX(GROUP_CONCAT(t.subject ORDER BY t.opened_at DESC SEPARATOR '|||'), '|||', 1) AS latest_ticket_subject
       FROM customer_employee_assignments a
       JOIN portal_accounts p ON p.id = a.customer_account_id
       LEFT JOIN portal_tickets t ON t.customer_account_id = p.id
        AND t.status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir')
        AND (t.ticket_no NOT LIKE 'LEGACY-%' OR t.opened_at >= (NOW() - INTERVAL 24 HOUR))
      WHERE {$assignmentWhere}
        AND a.status = 'active'
      GROUP BY p.id, p.full_name, p.subscriber_code, p.contract_no, p.phone, p.address, p.area, p.plan, p.status, p.billing_debt_amount
      HAVING debt_azn > 0 OR active_ticket_count > 0 OR p.status <> 'active'
      ORDER BY active_ticket_count DESC, debt_azn DESC, latest_ticket_at DESC
      LIMIT 20"
);
$riskStatement->execute($assignmentParams);
$riskCustomers = [];
foreach ($riskStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $riskReasons = [];
    if ((int) ($row['active_ticket_count'] ?? 0) > 0) {
        $riskReasons[] = 'Açıq problem var';
    }
    if ((float) ($row['debt_azn'] ?? 0) > 0) {
        $riskReasons[] = 'Borc var';
    }
    if ((string) ($row['status'] ?? '') !== 'active') {
        $riskReasons[] = 'Status aktiv deyil';
    }
    $riskCustomers[] = [
        'id' => (int) $row['id'],
        'name' => (string) $row['full_name'],
        'subscriber_code' => (string) ($row['subscriber_code'] ?? ''),
        'contract_no' => (string) ($row['contract_no'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'address' => (string) ($row['address'] ?? ''),
        'area' => (string) ($row['area'] ?? ''),
        'plan' => (string) ($row['plan'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'debt_azn' => (float) ($row['debt_azn'] ?? 0),
        'active_ticket_count' => (int) ($row['active_ticket_count'] ?? 0),
        'latest_ticket_at' => $row['latest_ticket_at'] !== null ? (string) $row['latest_ticket_at'] : null,
        'latest_ticket_subject' => (string) ($row['latest_ticket_subject'] ?? ''),
        'risk_reasons' => $riskReasons,
    ];
}

try {
    $bonusSummary = app_bonus_employee_range_summary($db, $periodStart, $periodEnd, $accountId) ?: [];
    $bonusItemsPage = app_bonus_items_range_page($db, $periodStart, $periodEnd, $accountId, ['page' => 1, 'limit' => 8]);
    $bonusNotifications = app_bonus_notifications_page($db, ['page' => 1, 'limit' => 8], $accountId);
} catch (Throwable) {
    $bonusSummary = [];
    $bonusItemsPage = ['rows' => []];
    $bonusNotifications = ['rows' => []];
}

$items = [];
foreach ((array) ($bonusItemsPage['rows'] ?? []) as $row) {
    $items[] = [
        'item_type' => (string) ($row['item_type'] ?? ''),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'subscriber_code' => (string) ($row['subscriber_code'] ?? ''),
        'amount' => (float) ($row['amount'] ?? 0),
        'status' => (string) ($row['status'] ?? ''),
        'reason' => (string) ($row['reason'] ?? ''),
        'period_start' => (string) ($row['period_start'] ?? ''),
        'period_end' => (string) ($row['period_end'] ?? ''),
    ];
}

$notifications = [];
foreach ((array) ($bonusNotifications['rows'] ?? []) as $row) {
    $notifications[] = [
        'id' => (int) ($row['id'] ?? 0),
        'type' => (string) ($row['notification_type'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'message' => (string) ($row['message'] ?? ''),
        'due_at' => $row['due_at'] !== null ? (string) $row['due_at'] : null,
    ];
}

try {
    $score = app_mgs_hr_score_summary($accountId, $now->format('Y-01-01'), $now->format('Y-12-31'), $db);
} catch (Throwable) {
    $score = [
        'score' => 100,
        'penalty_points' => 0,
        'reward_points' => 0,
        'event_count' => 0,
    ];
}

app_mobile_json_response([
    'period' => [
        'key' => $period,
        'label' => $periodLabel,
        'start' => $periodStart,
        'end' => $periodEnd,
    ],
    'portfolio' => [
        'total_customers' => (int) ($portfolio['total_customers'] ?? 0),
        'active_customers' => (int) ($portfolio['active_customers'] ?? 0),
        'debt_customers' => (int) ($portfolio['debt_customers'] ?? 0),
        'problem_customers' => $problemCustomers,
        'bonus_owner_customers' => (int) ($portfolio['bonus_owner_customers'] ?? 0),
        'total_debt_azn' => (float) ($portfolio['total_debt_amount'] ?? 0),
    ],
    'bonus' => [
        'base_salary' => (float) ($bonusSummary['base_salary'] ?? 0),
        'new_connection_bonus' => (float) ($bonusSummary['new_connection_bonus'] ?? 0),
        'recurring_bonus' => (float) ($bonusSummary['recurring_bonus'] ?? 0),
        'manual_adjustment' => (float) ($bonusSummary['manual_adjustment'] ?? 0),
        'total_payable' => (float) ($bonusSummary['total_payable'] ?? 0),
        'active_customer_count' => (int) ($bonusSummary['active_customer_count'] ?? 0),
        'items' => $items,
        'notifications' => $notifications,
    ],
    'score' => [
        'score' => (int) ($score['score'] ?? 100),
        'penalty_points' => (float) ($score['penalty_points'] ?? 0),
        'reward_points' => (float) ($score['reward_points'] ?? 0),
        'event_count' => (int) ($score['event_count'] ?? 0),
    ],
    'risk_customers' => $riskCustomers,
], 200);
