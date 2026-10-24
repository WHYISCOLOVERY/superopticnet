<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer']);

$accountId = (int) $user['id'];
$debtOnly = !empty($_GET['debt_only']);
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$db = app_db();

$where = ["(a.installer_employee_id = :aid OR a.area_responsible_employee_id = :aid OR a.finder_employee_id = :aid)"];
$params = [':aid' => $accountId];

if ($debtOnly) {
    $where[] = "COALESCE(p.billing_debt_amount, 0) > 0";
}
if ($search !== '') {
    $where[] = "(p.full_name LIKE :q OR p.phone LIKE :q OR p.subscriber_code LIKE :q OR p.contract_no LIKE :q OR p.address LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);

// Total count
$countStmt = $db->prepare(
    "SELECT COUNT(*) FROM customer_employee_assignments a
     JOIN portal_accounts p ON p.id = a.customer_account_id
     WHERE $whereSql"
);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();

// Data rows
$sql = "SELECT 
            p.id,
            p.full_name,
            p.subscriber_code,
            p.contract_no,
            p.phone,
            p.address,
            p.area,
            p.plan,
            p.status,
            COALESCE(p.billing_debt_amount, 0) as billing_debt_amount,
            p.created_at,
            a.assignment_source,
            a.status as assignment_status
        FROM customer_employee_assignments a
        JOIN portal_accounts p ON p.id = a.customer_account_id
        WHERE $whereSql
        ORDER BY 
            CASE WHEN COALESCE(p.billing_debt_amount, 0) > 0 THEN 0 ELSE 1 END,
            p.full_name ASC
        LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$customers = [];
foreach ($rows as $r) {
    $debt = (float) $r['billing_debt_amount'];
    $customers[] = [
        'id' => (int) $r['id'],
        'name' => $r['full_name'],
        'subscriber_code' => $r['subscriber_code'],
        'contract_no' => $r['contract_no'],
        'phone' => $r['phone'],
        'address' => $r['address'],
        'area' => $r['area'],
        'plan' => $r['plan'] ?? 'Fiber',
        'status' => $r['status'], // active, blocked, etc.
        'has_debt' => $debt > 0,
        'debt_azn' => $debt,
        'assignment_role' => $r['assignment_source'] ?? 'installer',
    ];
}

app_mobile_json_response([
    'total' => $totalRows,
    'page' => $page,
    'limit' => $limit,
    'customers' => $customers,
], 200);
