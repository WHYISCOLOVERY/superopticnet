<?php
declare(strict_types=1);

require __DIR__ . '/../config.php';

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$tables = [
    'portal_accounts',
    'portal_account_areas',
    'customer_area_code_prefixes',
    'inventory_items',
    'billing_tariffs',
];

foreach ($tables as $table) {
    echo "--- {$table}\n";
    try {
        foreach ($db->query('DESCRIBE `' . str_replace('`', '``', $table) . '`') as $row) {
            echo $row['Field'] . ':' . $row['Type'] . ':' . $row['Null'] . ':' . $row['Key'] . "\n";
        }
    } catch (Throwable $exception) {
        echo 'ERR ' . $exception->getMessage() . "\n";
    }
}

$queries = [
    'employees' => "SELECT COUNT(*) FROM portal_accounts WHERE role = 'employee'",
    'customers' => "SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer'",
    'test_customers' => "SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer' AND (full_name LIKE '%Test%' OR subscriber_code LIKE 'TGACT-%' OR subscriber_code LIKE 'SEC-SMOKE-%' OR login LIKE 'test%' OR login LIKE 'smoke%')",
    'test_employees' => "SELECT COUNT(*) FROM portal_accounts WHERE role = 'employee' AND (full_name LIKE '%Role Agent%' OR login LIKE 'agent_%' OR login LIKE 'staff-login%' OR full_name LIKE '%Test%')",
    'tariffs' => 'SELECT COUNT(*) FROM billing_tariffs',
    'test_tariffs' => "SELECT COUNT(*) FROM billing_tariffs WHERE name LIKE '%test%' OR name LIKE '%demo%' OR name LIKE '%smoke%'",
];

echo "--- counts\n";
foreach ($queries as $name => $sql) {
    try {
        echo $name . '=' . (string) $db->query($sql)->fetchColumn() . "\n";
    } catch (Throwable $exception) {
        echo $name . '=ERR ' . $exception->getMessage() . "\n";
    }
}

$samples = [
    'test_customers' => "SELECT id, login, subscriber_code, full_name, area, account_type, status FROM portal_accounts WHERE role = 'customer' AND (full_name LIKE '%Test%' OR subscriber_code LIKE 'TGACT-%' OR subscriber_code LIKE 'SEC-SMOKE-%' OR login LIKE 'test%' OR login LIKE 'smoke%') ORDER BY id LIMIT 20",
    'test_employees' => "SELECT id, login, subscriber_code, full_name, area, account_type, employee_role, status FROM portal_accounts WHERE role = 'employee' AND (full_name LIKE '%Role Agent%' OR login LIKE 'agent_%' OR login LIKE 'staff-login%' OR full_name LIKE '%Test%') ORDER BY id LIMIT 20",
    'real_employee_names' => "SELECT id, login, full_name, area, account_type, employee_role, status FROM portal_accounts WHERE role = 'employee' ORDER BY full_name LIMIT 80",
];

foreach ($samples as $name => $sql) {
    echo "--- sample: {$name}\n";
    try {
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
    } catch (Throwable $exception) {
        echo 'ERR ' . $exception->getMessage() . "\n";
    }
}
