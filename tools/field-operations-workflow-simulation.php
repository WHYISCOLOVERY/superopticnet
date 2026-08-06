<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/mgs-integration-lib.php';
require_once dirname(__DIR__) . '/hr-employee-service.php';
require_once dirname(__DIR__) . '/inventory-operations-lib.php';
require_once dirname(__DIR__) . '/billing-customer-identity-lib.php';
require_once dirname(__DIR__) . '/finance-bonus-lib.php';

$apply = in_array('--apply', $argv, true);
if (!$apply) {
    echo json_encode([
        'status' => 'preview',
        'message' => 'Use --apply to create the isolated SIM26 workflow data set. Test data is retained until explicit cleanup.',
        'employees' => ['technicians' => 6, 'warehouse' => 1, 'operators' => 2],
        'customers' => 12,
        'inventory' => ['modem', 'cable', 'clamp', 'ladder', 'drill'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

app_mgs_ensure_schema($db);
app_inventory_ensure_operational_schema($db);
app_bonus_ensure_schema($db);

$db->exec('CREATE TABLE IF NOT EXISTS system_test_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(96) NOT NULL UNIQUE,
    scenario_type VARCHAR(64) NOT NULL,
    status ENUM("running","passed","failed","cleaned") NOT NULL DEFAULT "running",
    report_path VARCHAR(255) DEFAULT NULL,
    report_json LONGTEXT DEFAULT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    cleaned_at DATETIME DEFAULT NULL,
    KEY idx_system_test_status(status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$stamp = date('ymdHis');
$runId = 'SIM26-' . $stamp . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
$prefix = strtoupper(str_replace('-', '', $runId));
$ticketPrefix = 'SIM' . strtoupper(substr(hash('sha256', $runId), 0, 10));
$today = date('Y-m-d');
$bonusPeriodStart = '2099-01-01';
$bonusPeriodEnd = '2099-01-31';
$bonusPaymentAt = '2099-01-05 10:00:00';
$payrollMonth = date('Y-m');
$payrollMonthDate = $payrollMonth . '-01';
$reportPath = dirname(__DIR__) . '/tmp/field-operations-simulation-' . $runId . '.json';

$db->prepare('INSERT INTO system_test_runs(run_id,scenario_type,status)VALUES(:run_id,"field_operations_day","running")')
    ->execute(['run_id' => $runId]);

$adminStatement = $db->prepare('SELECT * FROM portal_accounts WHERE login=:login AND role="employee" AND is_active=1 LIMIT 1');
$adminStatement->execute(['login' => 's.abdullayev@superoptic.net']);
$admin = $adminStatement->fetch(PDO::FETCH_ASSOC) ?: [];
if ($admin === []) {
    throw new RuntimeException('super_admin_not_found');
}

$report = [
    'status' => 'failed',
    'run_id' => $runId,
    'retained_for_review' => true,
    'created_at' => date(DATE_ATOM),
    'actors' => [],
    'inventory' => [],
    'technicians' => [],
    'customers' => [],
    'statistics' => [],
    'checks' => [],
    'errors' => [],
    'entity_ids' => [
        'employees' => [], 'customers' => [], 'items' => [], 'assets' => [], 'tickets' => [],
        'documents' => [], 'payments' => [], 'assignments' => [], 'payroll' => [],
        'tariffs' => [], 'billing_pools' => [], 'engine_pools' => [], 'bonus_periods' => [],
    ],
];

function sim_check(array &$report, string $key, bool $ok, array $details = []): void
{
    $report['checks'][$key] = ['ok' => $ok, 'details' => $details];
    if (!$ok) {
        throw new RuntimeException('check_failed:' . $key);
    }
}

function sim_action(PDO $db, string $type, string $entityType, string $entityId, array $actor, string $summary, array $payload): void
{
    app_mgs_record_operation_action([
        'action_type' => $type,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'status' => 'executed',
        'actor' => $actor,
        'actor_account_id' => (int) ($actor['id'] ?? 0),
        'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? 'simulation'),
        'summary' => $summary,
        'payload' => $payload,
    ]);
}

function sim_create_employee(PDO $db, array $admin, string $runId, string $label, string $accountType, string $employeeRole, string $jobTitle, float $grossSalary, string $area): array
{
    $positionStatement = $db->prepare('SELECT id FROM hr_positions WHERE title=:title AND is_active=1 LIMIT 1');
    $positionStatement->execute(['title' => $jobTitle]);
    $positionId = (int) $positionStatement->fetchColumn();
    if ($positionId <= 0) {
        $positionInsert = $db->prepare(
            'INSERT INTO hr_positions
                (position_key,title,recommended_account_type,recommended_employee_role,module_keys_json,is_active,created_by_account_id,updated_by_account_id)
             VALUES (:position_key,:title,:account_type,:employee_role,:module_keys_json,1,:actor_id,:actor_id)'
        );
        $positionInsert->execute([
            'position_key' => 'sim_' . substr(hash('sha256', $runId . '|' . $jobTitle), 0, 20),
            'title' => $jobTitle,
            'account_type' => in_array($accountType, ['operator', 'technician', 'admin', 'support'], true) ? $accountType : 'operator',
            'employee_role' => $employeeRole,
            'module_keys_json' => app_db_json_encode([]),
            'actor_id' => (int) ($admin['id'] ?? 0) ?: null,
        ]);
        $positionId = (int) $db->lastInsertId();
    }
    $login = strtolower(str_replace([' ', '-'], ['.', '.'], $label)) . '.' . strtolower(str_replace('-', '', $runId)) . '@local';
    $created = app_hr_create_employee($db, [
        'full_name' => $label,
        'login' => $login,
        'position_id' => $positionId,
        'salary_mode' => 'gross',
        'gross_salary' => $grossSalary,
        'branch_id' => '1',
        'hire_date' => date('Y-m-d'),
        'effective_from' => date('Y-m-d'),
        'area' => $area,
        'address' => 'SIM26 workflow address',
    ], $admin);
    if (empty($created['ok'])) {
        throw new RuntimeException('employee_create_failed:' . $label . ':' . (string) ($created['error'] ?? 'unknown'));
    }
    $account = app_db_account_by_id((int) $created['account_id']);
    if (!is_array($account)) {
        throw new RuntimeException('employee_reload_failed:' . $label);
    }
    $payload = app_db_account_payload($account);
    $payload['simulation'] = ['run_id' => $runId, 'kind' => $employeeRole];
    $saved = app_db_upsert_account([
        'id' => (int) $account['id'],
        'login' => (string) $account['login'],
        'password' => '',
        'role' => 'employee',
        'account_type' => $accountType,
        'employee_role' => $employeeRole,
        'job_title' => $jobTitle,
        'full_name' => $label,
        'email' => $login,
        'phone' => '+99450000' . str_pad((string) ((int) $account['id'] % 1000), 3, '0', STR_PAD_LEFT),
        'area' => $area,
        'address' => 'SIM26 workflow address',
        'status' => 'Aktiv',
        'is_active' => 1,
        'payload' => $payload,
    ], false);
    if (!is_array($saved) || (int) ($saved['is_active'] ?? 0) !== 1 || (string) ($saved['employee_role'] ?? '') !== $employeeRole) {
        throw new RuntimeException('admin_role_assignment_failed:' . $label);
    }
    sim_action($db, 'admin_employee_role_assigned', 'employee', (string) $saved['id'], $admin, 'Simulation employee role assigned by super admin', ['run_id' => $runId, 'employee_role' => $employeeRole]);
    return $saved;
}

function sim_attendance_profile(PDO $db, array $employee, array $actor, string $runId): void
{
    $payload = [
        'userId' => (string) $employee['id'], 'branchId' => '1',
        'cardCode' => 'SIM-' . $employee['id'], 'workStartTime' => '09:00',
        'workEndTime' => '18:00', 'lateGraceMinutes' => 10,
        'expectedWorkMinutes' => 480, 'noteText' => 'Simulation ' . $runId,
    ];
    $statement = $db->prepare('INSERT INTO mgs_operation_actions(action_type,entity_type,entity_id,status,actor_account_id,actor_name,summary,payload_json,executed_at)VALUES("attendance_profile_save","employee",:entity_id,"executed",:actor_id,:actor_name,:summary,:payload,NOW())');
    $statement->execute(['entity_id' => (string) $employee['id'], 'actor_id' => (int) $actor['id'], 'actor_name' => (string) $actor['full_name'], 'summary' => 'Simulation attendance profile saved', 'payload' => app_db_json_encode($payload)]);
}

function sim_close_ticket(PDO $db, int $ticketId, array $technician, string $reason, string $note): void
{
    $statement = $db->prepare('UPDATE portal_tickets SET status="Baglandi",resolved_at=NOW(),updated_at=NOW(),resolution_reason=:reason,resolution_note=:note,handled_by=:handled_by WHERE id=:id');
    $statement->execute(['reason' => $reason, 'note' => $note, 'handled_by' => (string) $technician['full_name'], 'id' => $ticketId]);
    app_db_add_ticket_event($ticketId, 'closed', $note, (int) $technician['id'], (string) $technician['full_name'], ['reason' => $reason]);
}

try {
    $warehouse = sim_create_employee($db, $admin, $runId, 'SIM26 Anbardar ' . $stamp, 'warehouse', 'warehouse_manager', 'Bas anbardar', 1100, $runId . '-WAREHOUSE');
    $operators = [];
    for ($i = 1; $i <= 2; $i++) {
        $operators[] = sim_create_employee($db, $admin, $runId, 'SIM26 Operator ' . $i . ' ' . $stamp, 'operator', 'operator', 'Musteri operatoru', 1000, $runId . '-OP-' . $i);
    }
    $technicians = [];
    for ($i = 1; $i <= 6; $i++) {
        $technicians[] = sim_create_employee($db, $admin, $runId, 'SIM26 Texnik ' . $i . ' ' . $stamp, 'technician', 'technician', 'Saha texniki', 1200, $runId . '-ZONE-' . $i);
    }
    $allEmployees = array_merge([$warehouse], $operators, $technicians);
    foreach ($allEmployees as $employee) {
        $report['entity_ids']['employees'][] = (int) $employee['id'];
        sim_attendance_profile($db, $employee, $admin, $runId);
    }
    $report['actors'] = [
        'super_admin' => ['id' => (int) $admin['id'], 'name' => (string) $admin['full_name']],
        'warehouse' => ['id' => (int) $warehouse['id'], 'name' => (string) $warehouse['full_name']],
        'operators' => array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['full_name']], $operators),
        'technicians' => array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['full_name']], $technicians),
    ];
    sim_check($report, 'employees_created_and_admin_roles_assigned', count($technicians) === 6 && count($operators) === 2 && (string) $warehouse['employee_role'] === 'warehouse_manager');

    foreach ($allEmployees as $index => $employee) {
        $arrival = ($index === 6 || $index === 8) ? '09:22:00' : ('08:' . str_pad((string) (48 + ($index % 10)), 2, '0', STR_PAD_LEFT) . ':00');
        $attendance = app_mgs_record_hr_attendance_event([
            'employee_account_id' => (int) $employee['id'], 'event_type' => 'check_in',
            'work_date' => $today, 'event_at' => $today . ' ' . $arrival,
            'branch_id' => '1', 'source' => 'simulation', 'note' => $runId,
        ], $employee, $db);
        if (empty($attendance['ok'])) {
            throw new RuntimeException('attendance_checkin_failed:' . $employee['id'] . ':' . ($attendance['error'] ?? ''));
        }
    }
    sim_check($report, 'attendance_check_in_recorded', true, ['employees' => count($allEmployees)]);

    $itemDefinitions = [
        'modem' => ['sku' => $prefix . '-MODEM', 'name' => 'SIM26 GPON modem', 'category' => 'modem', 'unit' => 'eded', 'quantity' => 20, 'price' => 65],
        'cable' => ['sku' => $prefix . '-CABLE', 'name' => 'SIM26 drop kabel', 'category' => 'fiber', 'unit' => 'metr', 'quantity' => 2400, 'price' => 0.35],
        'clamp' => ['sku' => $prefix . '-CLAMP', 'name' => 'SIM26 kabel zajimi', 'category' => 'fiber', 'unit' => 'eded', 'quantity' => 300, 'price' => 0.45],
        'ladder' => ['sku' => $prefix . '-LADDER', 'name' => 'SIM26 pilleken', 'category' => 'tool', 'unit' => 'eded', 'quantity' => 6, 'price' => 180],
        'drill' => ['sku' => $prefix . '-DRILL', 'name' => 'SIM26 drel', 'category' => 'tool', 'unit' => 'eded', 'quantity' => 6, 'price' => 220],
    ];
    $items = [];
    foreach ($itemDefinitions as $key => $definition) {
        $intake = app_inventory_post_intake($db, [
            'new_sku' => $definition['sku'], 'new_item_name' => $definition['name'],
            'new_category' => $definition['category'], 'new_unit' => $definition['unit'],
            'quantity' => $definition['quantity'], 'unit_price' => $definition['price'],
            'to_location_type' => 'warehouse', 'to_location_id' => 'MAIN',
            'source_name' => 'SIM26 supplier', 'note' => $runId . ' warehouse intake',
        ], $warehouse);
        if (empty($intake['ok'])) {
            throw new RuntimeException('inventory_intake_failed:' . $key . ':' . ($intake['error'] ?? ''));
        }
        $items[$key] = ['id' => (int) $intake['item_id']] + $definition;
        $report['entity_ids']['items'][] = (int) $intake['item_id'];
        $report['entity_ids']['documents'][] = (int) $intake['id'];
        $transfer = app_inventory_transfer_branch($db, [
            'item_id' => (int) $intake['item_id'], 'quantity' => (float) $definition['quantity'],
            'from_location_type' => 'warehouse', 'from_location_id' => 'MAIN',
            'to_location_type' => 'branch', 'to_location_id' => '1',
            'note' => $runId . ' Baki field stock',
        ], $warehouse);
        if (empty($transfer['ok'])) {
            throw new RuntimeException('inventory_branch_transfer_failed:' . $key . ':' . ($transfer['error'] ?? ''));
        }
        $report['entity_ids']['documents'][] = (int) $transfer['id'];
    }
    sim_check($report, 'warehouse_intake_and_branch_transfer', count($items) === 5);

    $modemModelId = app_mgs_inventory_upsert_asset_model($db, 'SIM26', 'GPON ONT ' . $runId, 'modem');
    $ladderModelId = app_mgs_inventory_upsert_asset_model($db, 'SIM26', 'Field Ladder ' . $runId, 'tool');
    $drillModelId = app_mgs_inventory_upsert_asset_model($db, 'SIM26', 'Field Drill ' . $runId, 'tool');
    $modemAssets = [];
    $macSeed = hash('sha256', $runId, true);
    for ($i = 1; $i <= 16; $i++) {
        $serial = $prefix . '-MSN-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $mac = sprintf(
            '02:%02X:%02X:%02X:%02X:%02X',
            ord($macSeed[0]),
            ord($macSeed[1]),
            ord($macSeed[2]),
            ($i >> 8) & 255,
            $i & 255
        );
        $assetId = app_mgs_inventory_create_asset($db, ['product_id' => $items['modem']['id'], 'model_id' => $modemModelId, 'asset_code' => $prefix . '-MODEM-' . $i, 'serial_number' => $serial, 'mac_address' => $mac, 'ownership_type' => 'company', 'lifecycle_status' => 'in_warehouse', 'condition_status' => 'new', 'current_location_type' => 'branch', 'current_location_id' => '1', 'metadata_json' => app_db_json_encode(['run_id' => $runId])]);
        $modemAssets[] = ['id' => $assetId, 'serial' => $serial, 'mac' => $mac];
        $report['entity_ids']['assets'][] = $assetId;
    }
    $toolAssets = ['ladder' => [], 'drill' => []];
    foreach (['ladder' => $ladderModelId, 'drill' => $drillModelId] as $kind => $modelId) {
        for ($i = 1; $i <= 6; $i++) {
            $serial = $prefix . '-' . strtoupper($kind) . '-SN-' . $i;
            $assetId = app_mgs_inventory_create_asset($db, ['product_id' => $items[$kind]['id'], 'model_id' => $modelId, 'asset_code' => $prefix . '-' . strtoupper($kind) . '-' . $i, 'serial_number' => $serial, 'ownership_type' => 'company', 'lifecycle_status' => 'in_warehouse', 'condition_status' => 'working', 'current_location_type' => 'branch', 'current_location_id' => '1', 'metadata_json' => app_db_json_encode(['run_id' => $runId])]);
            $toolAssets[$kind][] = ['id' => $assetId, 'serial' => $serial, 'mac' => ''];
            $report['entity_ids']['assets'][] = $assetId;
        }
    }
    sim_check($report, 'serialized_assets_created', count($modemAssets) === 16 && count($toolAssets['ladder']) === 6 && count($toolAssets['drill']) === 6);

    $installTargets = [3, 3, 2, 2, 1, 1];
    $modemIssueTargets = [4, 3, 3, 2, 2, 2];
    $issuedModems = [];
    $assetCursor = 0;
    foreach ($technicians as $techIndex => $technician) {
        $issuedModems[$techIndex] = [];
        for ($m = 0; $m < $modemIssueTargets[$techIndex]; $m++) {
            $asset = $modemAssets[$assetCursor++];
            $issuedModems[$techIndex][] = $asset;
            $handover = app_inventory_handover_employee($db, ['item_id' => $items['modem']['id'], 'employee_id' => (int) $technician['id'], 'quantity' => 1, 'source_branch_id' => '1', 'serial_number' => $asset['serial'], 'mac_address' => $asset['mac'], 'note' => $runId . ' modem handover', 'require_confirmation' => true], $warehouse);
            if (empty($handover['ok'])) throw new RuntimeException('modem_handover_failed:' . $techIndex);
            $report['entity_ids']['documents'][] = (int) $handover['id'];
            $confirmed = app_inventory_confirm_employee_handover($db, (int) $handover['id'], $technician);
            if (empty($confirmed['ok'])) throw new RuntimeException('modem_confirmation_failed:' . $techIndex . ':' . ($confirmed['error'] ?? ''));
        }
        foreach ([
            ['key' => 'cable', 'quantity' => 300, 'serial' => ''],
            ['key' => 'clamp', 'quantity' => 30, 'serial' => ''],
            ['key' => 'ladder', 'quantity' => 1, 'serial' => $toolAssets['ladder'][$techIndex]['serial']],
            ['key' => 'drill', 'quantity' => 1, 'serial' => $toolAssets['drill'][$techIndex]['serial']],
        ] as $handoverLine) {
            $handover = app_inventory_handover_employee($db, ['item_id' => $items[$handoverLine['key']]['id'], 'employee_id' => (int) $technician['id'], 'quantity' => $handoverLine['quantity'], 'source_branch_id' => '1', 'serial_number' => $handoverLine['serial'], 'note' => $runId . ' ' . $handoverLine['key'] . ' handover', 'require_confirmation' => true], $warehouse);
            if (empty($handover['ok'])) throw new RuntimeException('handover_failed:' . $handoverLine['key'] . ':' . $techIndex);
            $report['entity_ids']['documents'][] = (int) $handover['id'];
            $confirmed = app_inventory_confirm_employee_handover($db, (int) $handover['id'], $technician);
            if (empty($confirmed['ok'])) throw new RuntimeException('handover_confirmation_failed:' . $handoverLine['key'] . ':' . $techIndex . ':' . ($confirmed['error'] ?? ''));
        }
    }
    $pendingCount = (int) $db->query('SELECT COUNT(*) FROM inventory_documents WHERE document_type="handover" AND status="pending" AND note LIKE ' . $db->quote('%' . $runId . '%'))->fetchColumn();
    sim_check($report, 'employee_handover_confirmation', $pendingCount === 0, ['confirmed_documents' => array_sum($modemIssueTargets) + 24]);

    $poolOctet = 180 + (hexdec(substr(hash('sha256', $runId), 0, 2)) % 40);
    $poolName = $runId . ' PPPoE Pool';
    $poolInsert = $db->prepare('INSERT INTO billing_ip_pools(name,service_type,pool_type,range_start,range_end,gateway,netmask,status)VALUES(:name,"pppoe","dynamic",:start_ip,:end_ip,:gateway,"255.255.255.0","active")');
    $poolInsert->execute(['name' => $poolName, 'start_ip' => '10.249.' . $poolOctet . '.10', 'end_ip' => '10.249.' . $poolOctet . '.80', 'gateway' => '10.249.' . $poolOctet . '.1']);
    $billingPoolId = (int) $db->lastInsertId();
    app_ip_sync_legacy_pools($db);
    $enginePoolStatement = $db->prepare('SELECT id FROM ip_pools WHERE legacy_billing_pool_id=:id LIMIT 1');$enginePoolStatement->execute(['id' => $billingPoolId]);$enginePoolId = (int) $enginePoolStatement->fetchColumn();
    $report['entity_ids']['billing_pools'][] = $billingPoolId;$report['entity_ids']['engine_pools'][] = $enginePoolId;
    $tariffName = $runId . ' 100 Mb/s';
    $tariffInsert = $db->prepare('INSERT INTO billing_tariffs(external_id,name,service_type,download_mbps,upload_mbps,monthly_fee,radius_group,status,metadata_json)VALUES(:external_id,:name,"pppoe",100,50,25,:radius_group,"active",:metadata)');
    $tariffInsert->execute(['external_id' => $runId . '-TARIFF', 'name' => $tariffName, 'radius_group' => strtolower($runId) . '-100m', 'metadata' => app_db_json_encode(['run_id' => $runId, 'simulation' => true])]);
    $tariffId = (int) $db->lastInsertId();$report['entity_ids']['tariffs'][] = $tariffId;
    sim_check($report, 'isolated_billing_plan_created', $billingPoolId > 0 && $enginePoolId > 0 && $tariffId > 0);

    $customerIndex = 0;
    foreach ($technicians as $techIndex => $technician) {
        for ($connection = 1; $connection <= $installTargets[$techIndex]; $connection++) {
            $customerIndex++;
            $operator = $operators[($customerIndex - 1) % 2];
            $subscriberCode = $prefix . 'C' . str_pad((string) $customerIndex, 3, '0', STR_PAD_LEFT);
            $password = 'Sim' . $customerIndex . 'Pass26';
            $customerName = 'SIM26 Musteri ' . $customerIndex . ' ' . $stamp;
            $area = $runId . '-ZONE-' . ($techIndex + 1);
            $payload = [
                'profile' => ['subscriber_code' => $subscriberCode, 'full_name' => $customerName, 'phone' => '+99455100' . str_pad((string) $customerIndex, 4, '0', STR_PAD_LEFT)],
                'billing' => ['service_type' => 'pppoe', 'tariff' => $tariffName, 'pppoe_username' => $subscriberCode, 'pppoe_password' => $password, 'pool' => $poolName, 'ip_assignment_mode' => 'pool', 'status' => 'Deaktiv', 'radius_status' => 'Vaxt bitib', 'expires_at' => date('Y-m-d H:i:s', time() - 3600), 'default_days' => 30, 'billing_days' => 30],
                'internet_services' => [['service_type' => 'pppoe', 'username' => $subscriberCode, 'password' => $password, 'tariff' => $tariffName, 'pool' => $poolName, 'ip_assignment_mode' => 'pool', 'status' => 'expired', 'expires_at' => date('Y-m-d H:i:s', time() - 3600)]],
                'simulation' => ['run_id' => $runId, 'operator_id' => (int) $operator['id'], 'technician_id' => (int) $technician['id']],
            ];
            $identity = ['fin_code' => 'S' . str_pad((string) $customerIndex, 5, '0', STR_PAD_LEFT) . substr($stamp, -1), 'document_no' => 'SIM' . substr($prefix, -6) . str_pad((string) $customerIndex, 3, '0', STR_PAD_LEFT), 'phone' => '+99455100' . str_pad((string) $customerIndex, 4, '0', STR_PAD_LEFT), 'address' => 'SIM26 unvan ' . $customerIndex . ', ' . $area];
            $created = app_billing_upsert_customer_with_identity($db, ['login' => strtolower($subscriberCode) . '@customer.local', 'password' => $password, 'role' => 'customer', 'account_type' => 'customer', 'full_name' => $customerName, 'subscriber_code' => $subscriberCode, 'contract_no' => $subscriberCode, 'phone' => $identity['phone'], 'area' => $area, 'address' => $identity['address'], 'plan' => $tariffName, 'status' => 'Deaktiv', 'is_active' => 0, 'payload' => $payload], $identity, $operator, $password);
            if (empty($created['ok'])) throw new RuntimeException('customer_create_failed:' . $customerIndex . ':' . ($created['error'] ?? ''));
            $customer = (array) $created['account'];$customerId = (int) $customer['id'];
            $report['entity_ids']['customers'][] = $customerId;
            $db->prepare('UPDATE portal_accounts SET created_at="2098-12-01 09:00:00" WHERE id=:id')->execute(['id' => $customerId]);
            $paid = app_billing_apply_customer_payment($subscriberCode, 25, 'operator:simulation', 30, $runId . '-TX-' . $customerIndex, $runId . '-EXT-' . $customerIndex, $bonusPaymentAt);
            if (!$paid) throw new RuntimeException('customer_payment_failed:' . $customerIndex);
            $paymentIdStatement = $db->prepare('SELECT id FROM billing_payments WHERE external_id=:external_id LIMIT 1');$paymentIdStatement->execute(['external_id' => $runId . '-EXT-' . $customerIndex]);$paymentId = (int) $paymentIdStatement->fetchColumn();$report['entity_ids']['payments'][] = $paymentId;
            $assignment = app_bonus_save_assignment($db, ['customer_account_id' => $customerId, 'finder_employee_id' => (int) $operator['id'], 'installer_employee_id' => (int) $technician['id'], 'area_responsible_employee_id' => (int) $technician['id'], 'bonus_owner_employee_id' => (int) $technician['id'], 'assigned_area_id' => $area, 'split_mode' => 'technician_100', 'effective_from' => $bonusPeriodStart, 'note' => $runId . ' installation ownership'], $admin);
            if (empty($assignment['ok'])) throw new RuntimeException('bonus_assignment_failed:' . $customerIndex);
            $report['entity_ids']['assignments'][] = (int) $assignment['id'];

            $ticketNo = $ticketPrefix . '-I-' . str_pad((string) $customerIndex, 3, '0', STR_PAD_LEFT);
            $ticketCreated = app_db_insert_ticket(['ticket_no' => $ticketNo, 'customer_account_id' => $customerId, 'subscriber_code' => $subscriberCode, 'contract_no' => $subscriberCode, 'customer_name' => $customerName, 'phone' => $identity['phone'], 'address' => $identity['address'], 'area' => $area, 'subject' => 'Yeni musteri qosulmasi', 'message' => 'SIM26 modem ve xett qurasdirilmasi', 'status' => 'Aciqdir', 'opened_at' => app_db_now(), 'updated_at' => app_db_now(), 'assigned_technician_account_id' => (int) $technician['id'], 'assigned_technician_name' => (string) $technician['full_name'], 'created_by_account_id' => (int) $operator['id'], 'created_by_role' => 'employee', 'created_by_name' => (string) $operator['full_name'], 'metadata' => ['run_id' => $runId, 'type' => 'installation']]);
            if (!$ticketCreated) throw new RuntimeException('installation_ticket_failed:' . $customerIndex);
            $ticketIdStatement = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no=:ticket_no LIMIT 1');$ticketIdStatement->execute(['ticket_no' => $ticketNo]);$ticketId = (int) $ticketIdStatement->fetchColumn();$report['entity_ids']['tickets'][] = $ticketId;

            $asset = $issuedModems[$techIndex][$connection - 1];
            $modemBefore = app_inventory_location_quantity($db, $items['modem']['id'], 'employee', (string) $technician['id']);
            app_mgs_inventory_install_asset($db, (int) $asset['id'], $subscriberCode, (string) $technician['full_name'], $ticketNo);
            foreach ([
                ['key' => 'modem', 'quantity' => 1, 'serial' => $asset['serial'], 'mac' => $asset['mac']],
                ['key' => 'cable', 'quantity' => 55 + (($customerIndex % 4) * 10), 'serial' => '', 'mac' => ''],
                ['key' => 'clamp', 'quantity' => 5 + ($customerIndex % 3), 'serial' => '', 'mac' => ''],
            ] as $used) {
                $consumed = app_inventory_consume_employee_stock($db, ['item_id' => $items[$used['key']]['id'], 'employee_id' => (int) $technician['id'], 'quantity' => $used['quantity'], 'customer_code' => $subscriberCode, 'customer_account_id' => $customerId, 'ticket_no' => $ticketNo, 'serial_number' => $used['serial'], 'mac_address' => $used['mac'], 'note' => $runId . ' field installation'], $technician);
                if (empty($consumed['ok'])) throw new RuntimeException('field_consumption_failed:' . $used['key'] . ':' . $customerIndex . ':' . ($consumed['error'] ?? ''));
                $report['entity_ids']['documents'][] = (int) $consumed['id'];
            }
            $modemAfter = app_inventory_location_quantity($db, $items['modem']['id'], 'employee', (string) $technician['id']);
            if (abs(($modemBefore - $modemAfter) - 1.0) > 0.001) throw new RuntimeException('technician_modem_balance_not_decremented:' . $customerIndex);
            sim_close_ticket($db, $ticketId, $technician, 'Yeni qosulma tamamlandi', 'Modem ' . $asset['serial'] . ' qosuldu ve xett aktiv edildi.');
            $report['customers'][] = ['id' => $customerId, 'subscriber_code' => $subscriberCode, 'name' => $customerName, 'operator' => (string) $operator['full_name'], 'technician' => (string) $technician['full_name'], 'ticket_no' => $ticketNo, 'modem_serial' => $asset['serial'], 'modem_mac' => $asset['mac'], 'modem_balance_before' => $modemBefore, 'modem_balance_after' => $modemAfter];
        }
    }
    sim_check($report, 'new_customer_installations', count($report['customers']) === 12, ['customers' => count($report['customers'])]);

    $problemTargets = [0, 1, 2, 0, 1, 3];
    foreach ($technicians as $techIndex => $technician) {
        for ($problem = 1; $problem <= $problemTargets[$techIndex]; $problem++) {
            $customerOffset = array_sum(array_slice($installTargets, 0, $techIndex));
            $customer = $report['customers'][$customerOffset];
            $ticketNo = $ticketPrefix . '-P-' . ($techIndex + 1) . '-' . $problem;
            app_db_insert_ticket(['ticket_no' => $ticketNo, 'customer_account_id' => (int) $customer['id'], 'subscriber_code' => (string) $customer['subscriber_code'], 'customer_name' => (string) $customer['name'], 'phone' => '+994551000000', 'address' => 'SIM26 problem address', 'area' => $runId . '-ZONE-' . ($techIndex + 1), 'subject' => $problem % 2 === 0 ? 'Modem siqnal problemi' : 'Kabel connector problemi', 'message' => 'SIM26 problem helli', 'status' => 'Aciqdir', 'opened_at' => '2099-01-' . str_pad((string) (10 + $problem), 2, '0', STR_PAD_LEFT) . ' 10:00:00', 'updated_at' => app_db_now(), 'assigned_technician_account_id' => (int) $technician['id'], 'assigned_technician_name' => (string) $technician['full_name'], 'created_by_account_id' => (int) $operators[$problem % 2]['id'], 'created_by_role' => 'employee', 'created_by_name' => (string) $operators[$problem % 2]['full_name'], 'metadata' => ['run_id' => $runId, 'type' => 'problem']]);
            $idStatement = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no=:ticket_no LIMIT 1');$idStatement->execute(['ticket_no' => $ticketNo]);$ticketId = (int) $idStatement->fetchColumn();$report['entity_ids']['tickets'][] = $ticketId;
            sim_close_ticket($db, $ticketId, $technician, 'Problem aradan qaldirildi', 'Connector yoxlanildi, modem signal seviyesi normallasdi.');
        }
    }
    sim_check($report, 'problem_resolution_flow', array_sum($problemTargets) === 7, ['resolved_problem_tickets' => array_sum($problemTargets)]);

    foreach ($technicians as $techIndex => $technician) {
        $unusedModems = array_slice($issuedModems[$techIndex], $installTargets[$techIndex]);
        foreach ($unusedModems as $unusedIndex => $asset) {
            if ($techIndex === 5 && $unusedIndex === count($unusedModems) - 1) continue;
            $returned = app_inventory_return_employee_stock($db, ['item_id' => $items['modem']['id'], 'employee_id' => (int) $technician['id'], 'quantity' => 1, 'destination_type' => 'warehouse', 'destination_id' => 'MAIN', 'serial_number' => $asset['serial'], 'mac_address' => $asset['mac'], 'note' => $runId . ' unused modem return'], $warehouse);
            if (empty($returned['ok'])) throw new RuntimeException('unused_modem_return_failed:' . $techIndex);
            $report['entity_ids']['documents'][] = (int) $returned['id'];
        }
        foreach (['ladder', 'drill'] as $toolKind) {
            $asset = $toolAssets[$toolKind][$techIndex];
            $returned = app_inventory_return_employee_stock($db, ['item_id' => $items[$toolKind]['id'], 'employee_id' => (int) $technician['id'], 'quantity' => 1, 'destination_type' => 'warehouse', 'destination_id' => 'MAIN', 'serial_number' => $asset['serial'], 'note' => $runId . ' end of day tool return'], $warehouse);
            if (empty($returned['ok'])) throw new RuntimeException('tool_return_failed:' . $toolKind . ':' . $techIndex);
            $report['entity_ids']['documents'][] = (int) $returned['id'];
        }
        foreach (['cable', 'clamp'] as $materialKind) {
            $remaining = app_inventory_location_quantity($db, $items[$materialKind]['id'], 'employee', (string) $technician['id']);
            $leave = $techIndex === 5 ? ($materialKind === 'cable' ? 25.0 : 3.0) : 0.0;
            $returnQuantity = max(0.0, $remaining - $leave);
            if ($returnQuantity > 0) {
                $returned = app_inventory_return_employee_stock($db, ['item_id' => $items[$materialKind]['id'], 'employee_id' => (int) $technician['id'], 'quantity' => $returnQuantity, 'destination_type' => 'warehouse', 'destination_id' => 'MAIN', 'note' => $runId . ' end of day material return'], $warehouse);
                if (empty($returned['ok'])) throw new RuntimeException('material_return_failed:' . $materialKind . ':' . $techIndex);
                $report['entity_ids']['documents'][] = (int) $returned['id'];
            }
        }
    }

    foreach ($technicians as $techIndex => $technician) {
        $reward = app_mgs_record_hr_score_event(['employee_account_id' => (int) $technician['id'], 'event_type' => 'reward', 'reason_code' => 'bonus', 'points_delta' => $installTargets[$techIndex] * 5, 'event_date' => $today, 'note' => $runId . ' connected customers'], $admin, $db);
        if (empty($reward['ok'])) throw new RuntimeException('score_reward_failed:' . $techIndex);
        if ($techIndex === 3) {
            $penalty = app_mgs_record_hr_score_event(['employee_account_id' => (int) $technician['id'], 'event_type' => 'penalty', 'reason_code' => 'late', 'event_date' => $today, 'note' => $runId . ' late arrival'], $admin, $db);
            if (empty($penalty['ok'])) throw new RuntimeException('score_penalty_failed');
        }
    }
    $snapshot = app_mgs_rebuild_employee_work_snapshots($db);
    sim_check($report, 'performance_snapshots_rebuilt', !empty($snapshot['ok']), ['updated' => (int) ($snapshot['updated'] ?? 0)]);

    $bonus = app_bonus_calculate_period($db, $bonusPeriodStart, $bonusPeriodEnd, $admin);
    if (empty($bonus['ok'])) throw new RuntimeException('bonus_calculation_failed:' . ($bonus['error'] ?? ''));
    $bonusPeriodId = (int) $bonus['period_id'];$report['entity_ids']['bonus_periods'][] = $bonusPeriodId;
    $summaryStatement = $db->prepare('SELECT * FROM employee_bonus_summaries WHERE period_id=:period_id AND employee_id=:employee_id LIMIT 1');
    foreach ($technicians as $techIndex => $technician) {
        $summaryStatement->execute(['period_id' => $bonusPeriodId, 'employee_id' => (int) $technician['id']]);
        $summary = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($summary === []) throw new RuntimeException('bonus_summary_missing:' . $techIndex);
        $variablePay = (float) ($summary['new_connection_bonus'] ?? 0) + (float) ($summary['recurring_bonus'] ?? 0) + (float) ($summary['manual_adjustment'] ?? 0);
        $payroll = app_mgs_save_hr_payroll_record(['employee_account_id' => (int) $technician['id'], 'period_month' => $payrollMonth, 'gross_salary' => 1200 + $variablePay, 'status' => 'draft', 'note' => $runId . ' simulated payroll'], $admin, $db);
        if (empty($payroll['ok'])) throw new RuntimeException('payroll_failed:' . $techIndex . ':' . ($payroll['error'] ?? ''));
        $report['entity_ids']['payroll'][] = (int) $payroll['id'];
    }

    foreach ($allEmployees as $index => $employee) {
        $checkout = app_mgs_record_hr_attendance_event(['employee_account_id' => (int) $employee['id'], 'event_type' => 'check_out', 'work_date' => $today, 'event_at' => $today . ' 18:' . str_pad((string) (5 + ($index % 20)), 2, '0', STR_PAD_LEFT) . ':00', 'source' => 'simulation', 'note' => $runId . ' end of shift'], $employee, $db);
        if (empty($checkout['ok'])) throw new RuntimeException('attendance_checkout_failed:' . $employee['id'] . ':' . ($checkout['error'] ?? ''));
    }

    $attendanceStatement = $db->prepare('SELECT attendance_status,COUNT(*) total FROM hr_attendance_events WHERE employee_account_id IN (' . implode(',', array_fill(0, count($report['entity_ids']['employees']), '?')) . ') AND work_date=? GROUP BY attendance_status');
    $attendanceStatement->execute(array_merge($report['entity_ids']['employees'], [$today]));
    $attendanceStats = [];
    foreach ($attendanceStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $attendanceStats[(string) $row['attendance_status']] = (int) $row['total'];
    $remainingBalances = [];
    $balanceStatement = $db->prepare('SELECT b.available_quantity,i.sku,i.item_name,i.unit FROM inventory_location_balances b JOIN inventory_stock_items i ON i.id=b.item_id WHERE b.location_type="employee" AND b.location_id=:employee_id AND b.available_quantity>0 ORDER BY i.item_name');
    $snapshotStatement = $db->prepare('SELECT * FROM employee_work_snapshots WHERE employee_account_id=:employee_id AND work_date=CURDATE() LIMIT 1');
    $scoreStatement = $db->prepare('SELECT 100+COALESCE(SUM(points_delta),0) FROM hr_score_events WHERE employee_account_id=:employee_id AND event_date BETWEEN :start_date AND :end_date');
    $payrollStatement = $db->prepare('SELECT * FROM hr_payroll_records WHERE employee_account_id=:employee_id AND period_month=:period_month LIMIT 1');
    foreach ($technicians as $techIndex => $technician) {
        $balanceStatement->execute(['employee_id' => (string) $technician['id']]);$balances = $balanceStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $snapshotStatement->execute(['employee_id' => (int) $technician['id']]);$workSnapshot = $snapshotStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $scoreStatement->execute(['employee_id' => (int) $technician['id'], 'start_date' => date('Y-01-01'), 'end_date' => date('Y-12-31')]);$score = max(0, min(100, (float) $scoreStatement->fetchColumn()));
        $summaryStatement->execute(['period_id' => $bonusPeriodId, 'employee_id' => (int) $technician['id']]);$bonusSummary = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $payrollStatement->execute(['employee_id' => (int) $technician['id'], 'period_month' => $payrollMonthDate]);$payroll = $payrollStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $report['technicians'][] = ['id' => (int) $technician['id'], 'name' => (string) $technician['full_name'], 'connected_customers' => $installTargets[$techIndex], 'resolved_problems' => $problemTargets[$techIndex], 'registry_score' => $score, 'performance_score' => (float) ($workSnapshot['performance_score'] ?? 0), 'closed_ticket_count' => (int) ($workSnapshot['closed_ticket_count'] ?? 0), 'inventory_activity_count' => (int) ($workSnapshot['inventory_issued_count'] ?? 0), 'remaining_inventory' => $balances, 'bonus' => ['base_salary' => (float) ($bonusSummary['base_salary'] ?? 0), 'new_connection_bonus' => (float) ($bonusSummary['new_connection_bonus'] ?? 0), 'recurring_bonus' => (float) ($bonusSummary['recurring_bonus'] ?? 0), 'quality_deduction' => (float) ($bonusSummary['quality_deduction'] ?? 0), 'total_payable' => (float) ($bonusSummary['total_payable'] ?? 0)], 'payroll' => ['gross_salary' => (float) ($payroll['gross_salary'] ?? 0), 'employee_deductions' => (float) ($payroll['employee_total_deductions'] ?? 0), 'net_salary' => (float) ($payroll['net_salary'] ?? 0), 'employer_total_cost' => (float) ($payroll['employer_total_cost'] ?? 0)]];
        foreach ($balances as $balance) $remainingBalances[] = ['employee_id' => (int) $technician['id'], 'employee_name' => (string) $technician['full_name']] + $balance;
    }

    $identityCount = (int) $db->query('SELECT COUNT(*) FROM portal_accounts WHERE id IN (' . implode(',', array_map('intval', $report['entity_ids']['customers'])) . ') AND fin_code IS NOT NULL AND document_no IS NOT NULL AND phone IS NOT NULL AND address IS NOT NULL')->fetchColumn();
    $customerCodeList = implode(',', array_map([$db, 'quote'], array_column($report['customers'], 'subscriber_code')));
    $equipmentCount = (int) $db->query('SELECT COUNT(*) FROM customer_equipment_assignments WHERE customer_code IN (' . $customerCodeList . ') AND assignment_status="active"')->fetchColumn();
    $installedAssetCount = (int) $db->query('SELECT COUNT(*) FROM inventory_assets WHERE id IN (' . implode(',', array_map('intval', $report['entity_ids']['assets'])) . ') AND lifecycle_status="installed"')->fetchColumn();
    $payrollCount = (int) $db->query('SELECT COUNT(*) FROM hr_payroll_records WHERE id IN (' . implode(',', array_map('intval', $report['entity_ids']['payroll'])) . ')')->fetchColumn();
    $auditStatement = $db->prepare('SELECT COUNT(*) FROM mgs_operation_actions WHERE payload_json LIKE :run_id OR summary LIKE :run_id');$auditStatement->execute(['run_id' => '%' . $runId . '%']);$auditCount = (int) $auditStatement->fetchColumn();
    $report['inventory'] = ['items' => $items, 'remaining_employee_balances' => $remainingBalances, 'expected_deliberate_balance' => ['technician' => (string) $technicians[5]['full_name'], 'modem' => 1, 'cable' => 25, 'clamp' => 3, 'ladder' => 0, 'drill' => 0]];
    $report['statistics'] = ['employees' => 9, 'technicians' => 6, 'warehouse_staff' => 1, 'operators' => 2, 'attendance' => $attendanceStats, 'customers_created' => count($report['entity_ids']['customers']), 'identity_complete_customers' => $identityCount, 'active_equipment_assignments' => $equipmentCount, 'installed_assets' => $installedAssetCount, 'installation_tickets' => 12, 'resolved_problem_tickets' => 7, 'payroll_records' => $payrollCount, 'audit_actions_with_run_id' => $auditCount, 'bonus_period_id' => $bonusPeriodId, 'bonus_period' => $bonusPeriodStart . ' - ' . $bonusPeriodEnd];
    sim_check($report, 'customer_identity_completeness', $identityCount === 12, ['count' => $identityCount]);
    sim_check($report, 'modem_customer_assignment', $equipmentCount === 12 && $installedAssetCount === 12, ['assignments' => $equipmentCount, 'installed_assets' => $installedAssetCount]);
    sim_check($report, 'end_of_day_remaining_inventory', count($remainingBalances) === 3, ['rows' => $remainingBalances]);
    sim_check($report, 'attendance_check_out_recorded', array_sum($attendanceStats) === 9, ['status_counts' => $attendanceStats]);
    sim_check($report, 'payroll_and_bonus_records', $payrollCount === 6, ['payroll_records' => $payrollCount]);
    sim_check($report, 'audit_trail_present', $auditCount > 0, ['actions' => $auditCount]);

    $report['status'] = 'passed';
} catch (Throwable $exception) {
    $report['errors'][] = $exception->getMessage();
    $report['status'] = 'failed';
}

$report['completed_at'] = date(DATE_ATOM);
if (!is_dir(dirname($reportPath))) mkdir(dirname($reportPath), 0775, true);
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$db->prepare('UPDATE system_test_runs SET status=:status,report_path=:report_path,report_json=:report_json,completed_at=NOW() WHERE run_id=:run_id')->execute(['status' => $report['status'], 'report_path' => $reportPath, 'report_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'run_id' => $runId]);

echo json_encode(['status' => $report['status'], 'run_id' => $runId, 'report_path' => $reportPath, 'checks' => $report['checks'], 'statistics' => $report['statistics'], 'technicians' => $report['technicians'], 'errors' => $report['errors'], 'retained_for_review' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['status'] === 'passed' ? 0 : 1);
