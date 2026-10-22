<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/config.php';
$db = app_db();
if (!$db instanceof PDO || !app_db_table_exists($db, 'hr_positions')) {
    fwrite(STDERR, "hr_positions_unavailable\n"); exit(2);
}
$defaults = [
    ['Super admin', 'super_admin'], ['Admin', 'admin'], ['Direktor', 'director'],
    ['Baş texnik', 'team_lead'], ['Texnik', 'technician'], ['Mühəndis', 'engineer'],
    ['Operator', 'operator'], ['Gecə operatoru', 'night_operator'], ['NOC mühəndisi', 'noc'],
    ['Anbardar', 'warehouse'], ['Maliyyə', 'finance'], ['HR', 'hr'],
];
$rows = $db->query("SELECT DISTINCT TRIM(COALESCE(job_title,'')) title, LOWER(TRIM(COALESCE(employee_role,''))) employee_role FROM portal_accounts WHERE role='employee'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($rows as $row) {
    $title = trim((string) $row['title']);
    if ($title !== '') $defaults[] = [$title, (string) $row['employee_role']];
}
$allowed = ['finance','super_admin','admin','director','team_lead','warehouse','warehouse_manager','hr','operator','night_operator','support','technician','engineer','noc','sales'];
$insert = $db->prepare('INSERT IGNORE INTO hr_positions (position_key,title,recommended_account_type,recommended_employee_role,module_keys_json,is_active) VALUES (?,?,?,?,?,1)');
$added = 0;
foreach ($defaults as [$title, $role]) {
    $title = trim((string) $title); $role = strtolower(trim((string) $role));
    if ($title === '') continue;
    if (!in_array($role, $allowed, true)) $role = '';
    $key = 'catalog_' . substr(hash('sha256', $title), 0, 32);
    $type = in_array($role, ['technician','team_lead','engineer'], true) ? 'technician' : (in_array($role, ['admin','super_admin','director'], true) ? 'admin' : 'operator');
    $insert->execute([$key, $title, $type, $role, '[]']);
    $added += $insert->rowCount();
}
echo json_encode(['positions_added' => $added, 'positions_total' => (int) $db->query('SELECT COUNT(*) FROM hr_positions WHERE is_active=1')->fetchColumn()]) . PHP_EOL;
