<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['admin','manager','super_admin','director','team_lead','operator','support']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') app_mobile_json_response(null, 405, 'Yalnız GET sorğuları dəstəklənir.');
$db = app_db();
$status = trim((string) ($_GET['status'] ?? 'active'));
$query = mb_substr(trim((string) ($_GET['query'] ?? '')), 0, 128);
$area = mb_substr(trim((string) ($_GET['area'] ?? '')), 0, 128);
$where = [];
$params = [];
if ($status === 'active') $where[] = "t.status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir')";
elseif ($status === 'completed') $where[] = "t.status IN ('resolved','closed','Baglandi','Tesdiqlendi')";
if ($query !== '') {
    $where[] = '(t.ticket_no LIKE ? OR t.customer_name LIKE ? OR t.subject LIKE ? OR t.phone LIKE ?)';
    $like = '%' . $query . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($area !== '') { $where[] = 't.area=?'; $params[] = $area; }
$sql = "SELECT t.id,t.ticket_no,t.customer_account_id,t.customer_name,t.phone,t.address,t.area,t.subject,t.message,t.status,
               t.assigned_technician_account_id,t.assigned_technician_name,t.opened_at,t.updated_at,t.resolved_at,t.metadata_json
          FROM portal_tickets t" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
       " ORDER BY FIELD(t.status,'in_progress','Icrada','assigned','open','Aciqdir','Gozlenilir','resolved','closed'),t.opened_at DESC,t.id DESC LIMIT 250";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tickets = array_map(static function (array $row): array {
    $row['id'] = (int) $row['id'];
    $row['customer_account_id'] = $row['customer_account_id'] === null ? null : (int) $row['customer_account_id'];
    $row['assigned_technician_account_id'] = $row['assigned_technician_account_id'] === null ? null : (int) $row['assigned_technician_account_id'];
    $row['metadata'] = json_decode((string) ($row['metadata_json'] ?? ''), true) ?: [];
    unset($row['metadata_json']);
    return $row;
}, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
$employees = $db->query(
    "SELECT id,full_name,employee_role,job_title,area
       FROM portal_accounts
      WHERE role='employee' AND is_active=1 AND employee_role IN ('technician','engineer','team_lead')
      ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($employees as &$employee) $employee['id'] = (int) $employee['id'];
unset($employee);
$areas = app_service_areas();
app_mobile_json_response(['tickets' => $tickets, 'employees' => $employees, 'areas' => $areas]);
