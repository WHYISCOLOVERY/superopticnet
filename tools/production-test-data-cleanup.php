<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/audit-log-lib.php';

const CLEANUP_CONFIRMATION = 'DELETE-VERIFIED-TEST-DATA';
const PROTECTED_CUSTOMER_CODE = '2320025';
const PROTECTED_SUPERADMIN_LOGIN = 's.abdullayev@superoptic.net';

$options = getopt('', ['apply', 'confirm:', 'backup-dir:']);
$apply = array_key_exists('apply', $options);
$confirmation = trim((string) ($options['confirm'] ?? ''));

if ($apply && !hash_equals(CLEANUP_CONFIRMATION, $confirmation)) {
    fwrite(STDERR, "confirmation_required:" . CLEANUP_CONFIRMATION . "\n");
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

function cleanup_table_exists(PDO $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $statement->execute(['table' => $table]);
    return $cache[$table] = (int) $statement->fetchColumn() > 0;
}

function cleanup_column_exists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $statement->execute(['table' => $table, 'column' => $column]);
    return $cache[$key] = (int) $statement->fetchColumn() > 0;
}

function cleanup_placeholders(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

function cleanup_rows(PDO $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function cleanup_ids(array $rows, string $column = 'id'): array
{
    return array_values(array_unique(array_filter(
        array_map(static fn(array $row): int => (int) ($row[$column] ?? 0), $rows),
        static fn(int $id): bool => $id > 0
    )));
}

function cleanup_strings(array $rows, array $columns): array
{
    $values = [];
    foreach ($rows as $row) {
        foreach ($columns as $column) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                $values[$value] = true;
            }
        }
    }
    return array_keys($values);
}

function cleanup_count(PDO $db, string $table, string $where, array $params = []): int
{
    if (!cleanup_table_exists($db, $table)) {
        return 0;
    }
    $statement = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
    $statement->execute($params);
    return (int) $statement->fetchColumn();
}

function cleanup_delete(PDO $db, array &$deleted, string $label, string $table, string $where, array $params = []): void
{
    if (!cleanup_table_exists($db, $table)) {
        return;
    }
    $statement = $db->prepare("DELETE FROM `{$table}` WHERE {$where}");
    $statement->execute($params);
    $deleted[$label] = ($deleted[$label] ?? 0) + $statement->rowCount();
}

function cleanup_latest_backup(string $requested): array
{
    $root = dirname(__DIR__);
    $candidates = [];
    if ($requested !== '') {
        $candidates[] = rtrim($requested, '\\/') . DIRECTORY_SEPARATOR . 'superoptic_portal.sql';
    } else {
        $candidates = glob($root . '/tmp/pre-production-cleanup-*/superoptic_portal.sql') ?: [];
        usort($candidates, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    }
    $path = $candidates[0] ?? '';
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('verified_backup_not_found');
    }
    $size = filesize($path);
    if (!is_int($size) || $size < 1024 * 1024) {
        throw new RuntimeException('verified_backup_too_small');
    }
    $sha256 = hash_file('sha256', $path);
    if (!is_string($sha256) || strlen($sha256) !== 64) {
        throw new RuntimeException('verified_backup_hash_failed');
    }
    return ['path' => $path, 'size' => $size, 'sha256' => strtoupper($sha256)];
}

try {
    $backup = cleanup_latest_backup(trim((string) ($options['backup-dir'] ?? '')));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(3);
}

$protectedWhere = "LOWER(COALESCE(login,'')) <> LOWER(:superadmin)
    AND COALESCE(subscriber_code,'') <> :protected_customer";
$protectedParams = [
    'superadmin' => PROTECTED_SUPERADMIN_LOGIN,
    'protected_customer' => PROTECTED_CUSTOMER_CODE,
];

$customerSql = "SELECT id,login,subscriber_code,full_name,area,role,account_type
    FROM portal_accounts
    WHERE role='customer' AND {$protectedWhere} AND (
        subscriber_code LIKE 'TGACT-%' OR subscriber_code LIKE 'SEC-SMOKE-%'
        OR subscriber_code LIKE 'SMOKE-%' OR subscriber_code LIKE 'CP-SMOKE-%'
        OR subscriber_code LIKE 'UP-SMOKE-%' OR login LIKE 'TGACT-%'
        OR login LIKE 'SEC-SMOKE-%' OR login LIKE 'SMOKE-%'
        OR login LIKE 'CP-SMOKE-%' OR login LIKE 'UP-SMOKE-%'
        OR login LIKE 'smoke-%' OR login LIKE 'rds%'
        OR login IN ('1200000','909990999','3001423')
        OR full_name IN ('test','Test api','Test IPoE Musteri 1200000','Telegram Action Customer','Copilot Agent Customer')
        OR full_name LIKE '% Smoke' OR full_name LIKE '% Smoke %'
        OR full_name LIKE 'Radius Cycle Test %'
    ) ORDER BY id";

$employeeSql = "SELECT id,login,subscriber_code,full_name,area,role,account_type
    FROM portal_accounts
    WHERE role='employee' AND {$protectedWhere} AND (
        login LIKE 'agent.%' OR login LIKE 'cp-tech-%'
        OR login LIKE 'smoke.password.%' OR login LIKE 'tg-operator-%'
        OR login LIKE 'visual.smoke%' OR login LIKE '%@smoke.local'
        OR login LIKE '%@local' OR login='staff-login'
        OR full_name LIKE 'Role Agent %' OR full_name LIKE 'Copilot Texnik %'
        OR full_name LIKE '% Smoke %' OR full_name LIKE 'Smoke %'
        OR full_name IN ('Smoke Password User','Telegram Smoke Operator')
        OR area='Role QA'
        OR JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.simulation.run_id')) LIKE 'SIM26-%'
    ) ORDER BY id";

$customers = cleanup_rows($db, $customerSql, $protectedParams);
$employees = cleanup_rows($db, $employeeSql, $protectedParams);
$customerIds = cleanup_ids($customers);
$employeeIds = cleanup_ids($employees);
$accountIds = array_values(array_unique(array_merge($customerIds, $employeeIds)));
$usernames = cleanup_strings(array_merge($customers, $employees), ['login', 'subscriber_code']);

$stockItems = cleanup_table_exists($db, 'inventory_stock_items')
    ? cleanup_rows(
        $db,
        "SELECT id,sku,item_name FROM inventory_stock_items
         WHERE sku LIKE 'CP-MODEM-%' OR sku LIKE 'SMOKE-%' OR sku LIKE 'SIM26%'
            OR item_name='Copilot smoke modem' OR item_name LIKE 'Smoke Test %'
         ORDER BY id"
    ) : [];
$itemIds = cleanup_ids($stockItems);

$assetModels = cleanup_table_exists($db, 'inventory_asset_models')
    ? cleanup_rows(
        $db,
        "SELECT id,vendor,model,device_type FROM inventory_asset_models
         WHERE model='Customer360 Smoke ONT' OR model LIKE '% Smoke %' OR model LIKE 'SIM26%'
         ORDER BY id"
    ) : [];
$modelIds = cleanup_ids($assetModels);

$assetConditions = ["asset_code LIKE 'SMOKE-%'", "asset_code LIKE 'SIM26%'", "asset_code LIKE 'CP-SMOKE-%'"];
$assetParams = [];
if ($itemIds !== [] && cleanup_column_exists($db, 'inventory_assets', 'product_id')) {
    $assetConditions[] = 'product_id IN (' . cleanup_placeholders($itemIds) . ')';
    $assetParams = array_merge($assetParams, $itemIds);
}
if ($modelIds !== [] && cleanup_column_exists($db, 'inventory_assets', 'model_id')) {
    $assetConditions[] = 'model_id IN (' . cleanup_placeholders($modelIds) . ')';
    $assetParams = array_merge($assetParams, $modelIds);
}
$assets = cleanup_table_exists($db, 'inventory_assets')
    ? cleanup_rows(
        $db,
        'SELECT id,asset_code,product_id,model_id,lifecycle_status FROM inventory_assets WHERE ' . implode(' OR ', $assetConditions) . ' ORDER BY id',
        $assetParams
    ) : [];
$assetIds = cleanup_ids($assets);

$tariffs = cleanup_table_exists($db, 'billing_tariffs')
    ? cleanup_rows(
        $db,
        "SELECT id,name,service_type,radius_group FROM billing_tariffs
         WHERE name LIKE '% smoke%' OR name LIKE 'Smoke %' OR name LIKE 'SIM26%'
            OR name LIKE 'Radius Cycle Test %' OR name LIKE 'WSL Test %'
            OR radius_group LIKE 'SMOKE-%' OR radius_group LIKE 'SIM26%'
         ORDER BY id"
    ) : [];
$tariffIds = cleanup_ids($tariffs);

$protectedCustomer = cleanup_rows(
    $db,
    "SELECT id,login,subscriber_code,full_name,role,is_active
     FROM portal_accounts WHERE subscriber_code=:subscriber_code LIMIT 1",
    ['subscriber_code' => PROTECTED_CUSTOMER_CODE]
);
$protectedSuperadmin = cleanup_rows(
    $db,
    "SELECT id,login,subscriber_code,full_name,role,account_type,is_active
     FROM portal_accounts WHERE LOWER(login)=LOWER(:login) LIMIT 1",
    ['login' => PROTECTED_SUPERADMIN_LOGIN]
);

$relations = [];
if ($accountIds !== []) {
    $idSql = cleanup_placeholders($accountIds);
    $semanticColumns = [
        'account_id', 'customer_id', 'customer_account_id', 'employee_id',
        'employee_account_id', 'technician_id', 'technician_account_id',
        'assigned_technician_account_id', 'created_by_account_id', 'actor_account_id',
        'requested_by_account_id', 'approved_by_account_id', 'assigned_by_account_id',
    ];
    $columnSql = cleanup_placeholders($semanticColumns);
    $statement = $db->prepare(
        "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ({$columnSql})
         ORDER BY TABLE_NAME,COLUMN_NAME"
    );
    $statement->execute($semanticColumns);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $column) {
        $table = (string) $column['TABLE_NAME'];
        $name = (string) $column['COLUMN_NAME'];
        $count = cleanup_count($db, $table, "`{$name}` IN ({$idSql})", $accountIds);
        if ($count > 0) {
            $relations[$table . '.' . $name] = $count;
        }
    }
}
if ($usernames !== []) {
    $usernameSql = cleanup_placeholders($usernames);
    foreach (['radcheck', 'radreply', 'radusergroup', 'radacct', 'billing_radius_services', 'radius_session_actions'] as $table) {
        if (cleanup_table_exists($db, $table) && cleanup_column_exists($db, $table, 'username')) {
            $count = cleanup_count($db, $table, "username IN ({$usernameSql})", $usernames);
            if ($count > 0) {
                $relations[$table . '.username'] = $count;
            }
        }
    }
}
if ($itemIds !== []) {
    $itemSql = cleanup_placeholders($itemIds);
    foreach (['item_id', 'product_id'] as $columnName) {
        $statement = $db->prepare(
            'SELECT TABLE_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME=:column_name
             ORDER BY TABLE_NAME'
        );
        $statement->execute(['column_name' => $columnName]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $table) {
            $table = (string) $table;
            $count = cleanup_count($db, $table, "`{$columnName}` IN ({$itemSql})", $itemIds);
            if ($count > 0) {
                $relations[$table . '.' . $columnName] = $count;
            }
        }
    }
}
if ($modelIds !== []) {
    $modelSql = cleanup_placeholders($modelIds);
    $statement = $db->query(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='model_id'
         ORDER BY TABLE_NAME"
    );
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $table) {
        $table = (string) $table;
        $count = cleanup_count($db, $table, "model_id IN ({$modelSql})", $modelIds);
        if ($count > 0) {
            $relations[$table . '.model_id'] = $count;
        }
    }
}
ksort($relations);

$runDir = dirname(__DIR__) . '/tmp/production-test-cleanup-' . date('Ymd-His');
if (!is_dir($runDir) && !mkdir($runDir, 0770, true) && !is_dir($runDir)) {
    fwrite(STDERR, "manifest_directory_failed\n");
    exit(3);
}
$manifestPath = $runDir . '/manifest-before.json';
$manifest = [
    'generated_at' => date(DATE_ATOM),
    'mode' => $apply ? 'apply' : 'dry-run',
    'backup' => $backup,
    'protected' => [
        'customer_code' => PROTECTED_CUSTOMER_CODE,
        'superadmin_login' => PROTECTED_SUPERADMIN_LOGIN,
        'customer' => $protectedCustomer[0] ?? null,
        'superadmin' => $protectedSuperadmin[0] ?? null,
    ],
    'candidates' => [
        'customers' => $customers,
        'employees' => $employees,
        'inventory_stock_items' => $stockItems,
        'inventory_asset_models' => $assetModels,
        'inventory_assets' => $assets,
        'billing_tariffs' => $tariffs,
    ],
    'counts' => [
        'customers' => count($customers),
        'employees' => count($employees),
        'inventory_stock_items' => count($stockItems),
        'inventory_asset_models' => count($assetModels),
        'inventory_assets' => count($assets),
        'billing_tariffs' => count($tariffs),
    ],
    'related_rows' => $relations,
];
$manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($manifestJson) || file_put_contents($manifestPath, $manifestJson . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "manifest_write_failed\n");
    exit(3);
}

if (!$apply) {
    echo json_encode([
        'status' => 'dry_run',
        'manifest' => $manifestPath,
        'backup' => $backup,
        'counts' => $manifest['counts'],
        'related_rows' => $relations,
        'protected' => $manifest['protected'],
        'apply_command' => 'php tools/production-test-data-cleanup.php --apply --confirm=' . CLEANUP_CONFIRMATION,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$deleted = [];
$skipped = [];
$db->beginTransaction();
try {
    $customerIn = $customerIds === [] ? 'NULL' : cleanup_placeholders($customerIds);
    $employeeIn = $employeeIds === [] ? 'NULL' : cleanup_placeholders($employeeIds);
    $accountIn = $accountIds === [] ? 'NULL' : cleanup_placeholders($accountIds);
    $usernameIn = $usernames === [] ? 'NULL' : cleanup_placeholders($usernames);

    if ($customerIds !== []) {
        $ticketIds = cleanup_ids(cleanup_rows(
            $db,
            "SELECT id FROM portal_tickets WHERE customer_account_id IN ({$customerIn})",
            $customerIds
        ));
        if ($ticketIds !== []) {
            $ticketIn = cleanup_placeholders($ticketIds);
            cleanup_delete($db, $deleted, 'portal_ticket_events', 'portal_ticket_events', "ticket_id IN ({$ticketIn})", $ticketIds);
            cleanup_delete($db, $deleted, 'portal_tickets', 'portal_tickets', "id IN ({$ticketIn})", $ticketIds);
        }
    }

    if ($accountIds !== [] && cleanup_table_exists($db, 'billing_payment_operations')) {
        $operationIds = cleanup_ids(cleanup_rows(
            $db,
            "SELECT id FROM billing_payment_operations WHERE account_id IN ({$accountIn})",
            $accountIds
        ));
        if ($operationIds !== []) {
            $operationIn = cleanup_placeholders($operationIds);
            $actionIds = cleanup_table_exists($db, 'billing_payment_service_actions')
                ? cleanup_ids(cleanup_rows($db, "SELECT id FROM billing_payment_service_actions WHERE operation_id IN ({$operationIn})", $operationIds))
                : [];
            if ($actionIds !== []) {
                $actionIn = cleanup_placeholders($actionIds);
                cleanup_delete($db, $deleted, 'billing_payment_outbox', 'billing_payment_outbox', "service_action_id IN ({$actionIn})", $actionIds);
                cleanup_delete($db, $deleted, 'billing_payment_service_attempts', 'billing_payment_service_attempts', "service_action_id IN ({$actionIn})", $actionIds);
            }
            cleanup_delete($db, $deleted, 'billing_payment_operation_events', 'billing_payment_operation_events', "operation_id IN ({$operationIn})", $operationIds);
            cleanup_delete($db, $deleted, 'billing_payment_reversals', 'billing_payment_reversals', "operation_id IN ({$operationIn})", $operationIds);
            cleanup_delete($db, $deleted, 'billing_payment_service_actions', 'billing_payment_service_actions', "operation_id IN ({$operationIn})", $operationIds);
            cleanup_delete($db, $deleted, 'billing_payment_operations', 'billing_payment_operations', "id IN ({$operationIn})", $operationIds);
        }
    }

    if ($accountIds !== []) {
        if (cleanup_table_exists($db, 'communication_messages')) {
            $messageIds = cleanup_ids(cleanup_rows($db, "SELECT id FROM communication_messages WHERE account_id IN ({$accountIn})", $accountIds));
            if ($messageIds !== []) {
                $messageIn = cleanup_placeholders($messageIds);
                cleanup_delete($db, $deleted, 'communication_delivery_attempts', 'communication_delivery_attempts', "message_id IN ({$messageIn})", $messageIds);
                cleanup_delete($db, $deleted, 'communication_messages', 'communication_messages', "id IN ({$messageIn})", $messageIds);
            }
        }
        cleanup_delete($db, $deleted, 'billing_payments', 'billing_payments', "account_id IN ({$accountIn})", $accountIds);
        cleanup_delete($db, $deleted, 'billing_radius_services', 'billing_radius_services', "account_id IN ({$accountIn})", $accountIds);
        cleanup_delete($db, $deleted, 'billing_customer_fin_claims', 'billing_customer_fin_claims', "account_id IN ({$accountIn})", $accountIds);
        cleanup_delete($db, $deleted, 'customer_ip_assignments', 'customer_ip_assignments', "customer_id IN ({$accountIn})", $accountIds);
        cleanup_delete($db, $deleted, 'ip_assignment_history', 'ip_assignment_history', "customer_id IN ({$accountIn})", $accountIds);
        cleanup_delete($db, $deleted, 'customer_router_bindings', 'customer_router_bindings', "customer_id IN ({$accountIn})", $accountIds);
        cleanup_delete($db, $deleted, 'portal_account_areas', 'portal_account_areas', "account_id IN ({$accountIn})", $accountIds);
        cleanup_delete($db, $deleted, 'portal_password_recovery_requests', 'portal_password_recovery_requests', "account_id IN ({$accountIn})", $accountIds);
        cleanup_delete($db, $deleted, 'portal_account_identities', 'portal_account_identities', "account_id IN ({$accountIn})", $accountIds);
        cleanup_delete($db, $deleted, 'legacy_billing_profile_sections', 'legacy_billing_profile_sections', "account_id IN ({$accountIn})", $accountIds);
        cleanup_delete($db, $deleted, 'communication_delivery_attempts_accounts', 'communication_delivery_attempts', "account_id IN ({$accountIn})", $accountIds);
        cleanup_delete(
            $db,
            $deleted,
            'inventory_stock_movements_accounts',
            'inventory_stock_movements',
            "customer_account_id IN ({$accountIn}) OR technician_account_id IN ({$accountIn})",
            array_merge($accountIds, $accountIds)
        );
        if (cleanup_table_exists($db, 'portal_ticket_events') && cleanup_column_exists($db, 'portal_ticket_events', 'actor_account_id')) {
            $statement = $db->prepare("UPDATE portal_ticket_events SET actor_account_id=NULL WHERE actor_account_id IN ({$accountIn})");
            $statement->execute($accountIds);
            $deleted['portal_ticket_events_actor_detached'] = $statement->rowCount();
        }
        if (cleanup_table_exists($db, 'portal_tickets')) {
            $statement = $db->prepare(
                "UPDATE portal_tickets
                 SET assigned_technician_account_id=CASE WHEN assigned_technician_account_id IN ({$accountIn}) THEN NULL ELSE assigned_technician_account_id END,
                     created_by_account_id=CASE WHEN created_by_account_id IN ({$accountIn}) THEN NULL ELSE created_by_account_id END
                 WHERE assigned_technician_account_id IN ({$accountIn}) OR created_by_account_id IN ({$accountIn})"
            );
            $statement->execute(array_merge($accountIds, $accountIds, $accountIds, $accountIds));
            $deleted['portal_ticket_account_refs_detached'] = $statement->rowCount();
        }
    }

    if ($customerIds !== []) {
        $customerCodes = cleanup_strings($customers, ['subscriber_code', 'login']);
        if ($customerCodes !== []) {
            $customerCodeIn = cleanup_placeholders($customerCodes);
            cleanup_delete($db, $deleted, 'customer_equipment_assignments_customer', 'customer_equipment_assignments', "customer_code IN ({$customerCodeIn})", $customerCodes);
        }
        cleanup_delete($db, $deleted, 'customer_employee_assignments_customer', 'customer_employee_assignments', "customer_account_id IN ({$customerIn})", $customerIds);
        cleanup_delete($db, $deleted, 'employee_bonus_items_customer', 'employee_bonus_items', "customer_account_id IN ({$customerIn})", $customerIds);
        cleanup_delete($db, $deleted, 'employee_bonus_notifications_customer', 'employee_bonus_notifications', "customer_account_id IN ({$customerIn})", $customerIds);
    }
    if ($employeeIds !== []) {
        foreach (['hr_attendance_events', 'hr_score_events', 'hr_payroll_records', 'hr_employee_compensations', 'hr_leave_requests', 'hr_travel_requests', 'hr_employee_documents', 'employee_work_snapshots'] as $table) {
            cleanup_delete($db, $deleted, $table, $table, "employee_account_id IN ({$employeeIn})", $employeeIds);
        }
        if (cleanup_table_exists($db, 'customer_employee_assignments')) {
            $assignmentColumns = [
                'finder_employee_id',
                'installer_employee_id',
                'area_responsible_employee_id',
                'bonus_owner_employee_id',
            ];
            $assignmentWhere = implode(' OR ', array_map(
                static fn(string $column): string => "{$column} IN ({$employeeIn})",
                $assignmentColumns
            ));
            $assignmentParams = [];
            foreach ($assignmentColumns as $_column) {
                $assignmentParams = array_merge($assignmentParams, $employeeIds);
            }
            cleanup_delete($db, $deleted, 'customer_employee_assignments_employee', 'customer_employee_assignments', $assignmentWhere, $assignmentParams);
        }
        foreach (['employee_bonus_items', 'employee_bonus_summaries', 'employee_bonus_adjustments', 'employee_bonus_notifications'] as $table) {
            cleanup_delete($db, $deleted, $table . '_employee', $table, "employee_id IN ({$employeeIn})", $employeeIds);
        }
        cleanup_delete(
            $db,
            $deleted,
            'inventory_location_balances_employee',
            'inventory_location_balances',
            "location_type='employee' AND CAST(location_id AS UNSIGNED) IN ({$employeeIn})",
            $employeeIds
        );
        foreach (['whatsapp_monitor_messages', 'whatsapp_monitor_events', 'whatsapp_monitor_screenshots', 'whatsapp_monitor_cases', 'whatsapp_monitor_pairings', 'whatsapp_monitor_devices', 'whatsapp_employee_numbers'] as $table) {
            cleanup_delete($db, $deleted, $table, $table, "employee_id IN ({$employeeIn})", $employeeIds);
        }
    }

    if ($usernames !== []) {
        foreach (['radcheck', 'radreply', 'radusergroup', 'radacct'] as $table) {
            cleanup_delete($db, $deleted, $table, $table, "username IN ({$usernameIn})", $usernames);
        }
        if (cleanup_table_exists($db, 'radius_session_actions')) {
            $where = "username IN ({$usernameIn})";
            $params = $usernames;
            if ($accountIds !== [] && cleanup_column_exists($db, 'radius_session_actions', 'account_id')) {
                $where .= " OR account_id IN ({$accountIn})";
                $params = array_merge($params, $accountIds);
            }
            if ($accountIds !== [] && cleanup_column_exists($db, 'radius_session_actions', 'requested_by_account_id')) {
                $where .= " OR requested_by_account_id IN ({$accountIn})";
                $params = array_merge($params, $accountIds);
            }
            cleanup_delete($db, $deleted, 'radius_session_actions', 'radius_session_actions', $where, $params);
        }
    }

    if ($itemIds !== []) {
        $itemIn = cleanup_placeholders($itemIds);
        cleanup_delete($db, $deleted, 'inventory_document_items', 'inventory_document_items', "item_id IN ({$itemIn})", $itemIds);
        cleanup_delete($db, $deleted, 'inventory_lifecycle_movements', 'inventory_lifecycle_movements', "product_id IN ({$itemIn})", $itemIds);
        cleanup_delete($db, $deleted, 'inventory_location_balances_items', 'inventory_location_balances', "item_id IN ({$itemIn})", $itemIds);
        cleanup_delete($db, $deleted, 'inventory_stock_movements', 'inventory_stock_movements', "item_id IN ({$itemIn})", $itemIds);
    }

    if ($assetIds !== []) {
        $assetIn = cleanup_placeholders($assetIds);
        foreach (['inventory_asset_inspections', 'inventory_asset_returns', 'customer_equipment_assignments', 'inventory_device_detection_evidence', 'finance_asset_depreciations'] as $table) {
            cleanup_delete($db, $deleted, $table . '_asset', $table, "asset_id IN ({$assetIn})", $assetIds);
        }
        if (cleanup_column_exists($db, 'inventory_lifecycle_movements', 'asset_id')) {
            cleanup_delete($db, $deleted, 'inventory_lifecycle_movements_asset', 'inventory_lifecycle_movements', "asset_id IN ({$assetIn})", $assetIds);
        }
        cleanup_delete($db, $deleted, 'inventory_assets', 'inventory_assets', "id IN ({$assetIn})", $assetIds);
    }
    if ($itemIds !== []) {
        $itemIn = cleanup_placeholders($itemIds);
        cleanup_delete($db, $deleted, 'inventory_stock_items', 'inventory_stock_items', "id IN ({$itemIn})", $itemIds);
    }
    if ($modelIds !== []) {
        $modelIn = cleanup_placeholders($modelIds);
        cleanup_delete($db, $deleted, 'inventory_asset_models', 'inventory_asset_models', "id IN ({$modelIn})", $modelIds);
    }

    if ($tariffIds !== []) {
        foreach ($tariffIds as $tariffId) {
            $serviceCount = cleanup_count($db, 'billing_radius_services', 'tariff_id = ?', [$tariffId]);
            if ($serviceCount > 0) {
                $skipped['billing_tariffs'][] = ['id' => $tariffId, 'reason' => 'tariff_still_used'];
                continue;
            }
            cleanup_delete($db, $deleted, 'billing_tariffs', 'billing_tariffs', 'id = ?', [$tariffId]);
        }
    }

    if ($accountIds !== []) {
        cleanup_delete(
            $db,
            $deleted,
            'mgs_operation_actions',
            'mgs_operation_actions',
            "actor_account_id IN ({$accountIn}) OR (entity_type IN ('employee','customer','account') AND CAST(entity_id AS UNSIGNED) IN ({$accountIn}))",
            array_merge($accountIds, $accountIds)
        );
        cleanup_delete($db, $deleted, 'portal_accounts', 'portal_accounts', "id IN ({$accountIn})", $accountIds);
    }

    $audit = app_audit_record($db, [
        'module_key' => 'system',
        'operation_class' => 'maintenance',
        'action_type' => 'production_test_data_cleanup',
        'entity_type' => 'database',
        'entity_id' => 'superoptic_portal',
        'status' => 'executed',
        'actor_account_id' => (int) ($db->query(
            "SELECT id FROM portal_accounts WHERE LOWER(login)=LOWER(" . $db->quote(PROTECTED_SUPERADMIN_LOGIN) . ") LIMIT 1"
        )->fetchColumn() ?: 0),
        'actor_name' => 'Super Admin maintenance',
        'summary' => 'Verified test accounts and explicit smoke inventory removed',
        'reason' => 'Production cutover data hygiene requested by Super Admin',
        'sensitivity' => 'restricted',
        'payload' => [
            'manifest' => str_replace('\\', '/', $manifestPath),
            'backup_sha256' => $backup['sha256'],
            'candidate_counts' => $manifest['counts'],
            'deleted_rows' => $deleted,
            'security_audit_ledger_retained' => true,
        ],
    ]);

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $failure = [
        'status' => 'rolled_back',
        'error' => $exception->getMessage(),
        'manifest' => $manifestPath,
        'deleted_before_rollback' => $deleted,
    ];
    file_put_contents($runDir . '/result.json', json_encode($failure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    echo json_encode($failure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(4);
}

$result = [
    'status' => 'applied',
    'manifest' => $manifestPath,
    'backup' => $backup,
    'candidate_counts' => $manifest['counts'],
    'deleted_rows' => $deleted,
    'skipped' => $skipped,
    'audit' => $audit,
];
file_put_contents($runDir . '/result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
