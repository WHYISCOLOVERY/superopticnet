<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require dirname(__DIR__) . '/config.php';

if (!function_exists('app_db') || !function_exists('app_db_upsert_account')) {
    fwrite(STDERR, "SuperOptic DB helpers are not available.\n");
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is not available.\n");
    exit(1);
}

if (function_exists('app_db_ensure_schema')) {
    app_db_ensure_schema();
}

function seed_employee_slug(string $name): string
{
    $map = [
        'ə' => 'e', 'Ə' => 'e',
        'ö' => 'o', 'Ö' => 'o',
        'ü' => 'u', 'Ü' => 'u',
        'ğ' => 'g', 'Ğ' => 'g',
        'ı' => 'i', 'I' => 'i', 'İ' => 'i',
        'ş' => 's', 'Ş' => 's',
        'ç' => 'c', 'Ç' => 'c',
    ];
    $slug = strtr($name, $map);
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '.', $slug));
    $slug = trim($slug, '.');
    return $slug !== '' ? $slug : 'employee';
}

function seed_existing_account(PDO $db, string $fullName, string $login): ?array
{
    $byLogin = function_exists('app_db_account_by_login') ? app_db_account_by_login($login) : null;
    if (is_array($byLogin)) {
        return $byLogin;
    }

    $statement = $db->prepare(
        "SELECT * FROM portal_accounts
         WHERE role = 'employee' AND LOWER(full_name) = LOWER(:full_name)
         ORDER BY id ASC
         LIMIT 1"
    );
    $statement->execute(['full_name' => $fullName]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function seed_tmp_password(string $login): string
{
    try {
        return 'So!' . bin2hex(random_bytes(8));
    } catch (Throwable) {
        return 'So!' . substr(hash('sha256', microtime(true) . ':' . $login), 0, 14);
    }
}

$allServiceAreas = ['Baki', 'Astara', 'Lenkeran', 'Masalli', 'Neftcala'];
$employees = [
    [
        'full_name' => 'Ferid Cebrayilov',
        'position' => 'muhendis',
        'account_type' => 'admin',
        'employee_role' => 'noc',
        'department' => 'Engineering',
        'area' => 'Baki',
        'areas' => ['Baki'],
    ],
    [
        'full_name' => 'Nurlan Huseynli',
        'position' => 'texnik',
        'account_type' => 'technician',
        'employee_role' => 'technician',
        'department' => 'Field',
        'area' => 'Baki',
        'areas' => ['Baki'],
    ],
    [
        'full_name' => 'Heyder Eliyev',
        'position' => 'texnik',
        'account_type' => 'technician',
        'employee_role' => 'technician',
        'department' => 'Field',
        'area' => 'Baki',
        'areas' => ['Baki'],
    ],
    [
        'full_name' => 'Xeyyam Ferecov',
        'position' => 'texnik',
        'account_type' => 'technician',
        'employee_role' => 'technician',
        'department' => 'Field',
        'area' => 'Baki',
        'areas' => ['Baki'],
    ],
    [
        'full_name' => 'Mehreli Ceferov',
        'position' => 'texnik',
        'account_type' => 'technician',
        'employee_role' => 'technician',
        'department' => 'Field',
        'area' => 'Astara',
        'areas' => ['Astara', 'Lenkeran'],
    ],
    [
        'full_name' => 'Orxan Memmedov',
        'position' => 'texnik',
        'account_type' => 'technician',
        'employee_role' => 'technician',
        'department' => 'Field',
        'area' => 'Astara',
        'areas' => ['Astara'],
    ],
    [
        'full_name' => 'Ebulfez Agayev',
        'position' => 'muhendis',
        'account_type' => 'admin',
        'employee_role' => 'noc',
        'department' => 'Engineering',
        'area' => 'Astara',
        'areas' => ['Astara', 'Masalli'],
    ],
    [
        'full_name' => 'Vusal Zeynalov',
        'position' => 'muhendis',
        'account_type' => 'admin',
        'employee_role' => 'noc',
        'department' => 'Engineering',
        'area' => 'Astara',
        'areas' => ['Astara', 'Neftcala'],
    ],
    [
        'full_name' => 'Zabil Memmedli',
        'position' => 'texnik',
        'account_type' => 'technician',
        'employee_role' => 'technician',
        'department' => 'Field',
        'area' => 'Masalli',
        'areas' => ['Masalli'],
    ],
    [
        'full_name' => 'Sugra Ehmedzade',
        'position' => 'operator',
        'account_type' => 'operator',
        'employee_role' => 'operator',
        'department' => 'Operations/Finance',
        'area' => '',
        'areas' => $allServiceAreas,
        'finance_responsibility' => true,
    ],
    [
        'full_name' => 'Rufane Demirciyeva',
        'position' => 'operator',
        'account_type' => 'operator',
        'employee_role' => 'operator',
        'department' => 'Operations/Finance',
        'area' => '',
        'areas' => $allServiceAreas,
        'finance_responsibility' => true,
    ],
    [
        'full_name' => 'Guluzar Ehmedli',
        'position' => 'gece_operatoru',
        'account_type' => 'operator',
        'employee_role' => 'operator',
        'department' => 'Night Operations',
        'area' => '',
        'areas' => $allServiceAreas,
        'night_shift' => true,
    ],
    [
        'full_name' => 'Mehseti Memmedova',
        'position' => 'gece_operatoru',
        'account_type' => 'operator',
        'employee_role' => 'operator',
        'department' => 'Night Operations',
        'area' => '',
        'areas' => $allServiceAreas,
        'night_shift' => true,
    ],
    [
        'full_name' => 'Serdar Abdullayev',
        'login' => 'serdar@sp.az',
        'position' => 'super_admin',
        'account_type' => 'admin',
        'employee_role' => 'admin',
        'department' => 'Administration',
        'area' => '',
        'areas' => $allServiceAreas,
        'super_admin' => true,
    ],
    [
        'full_name' => 'Ruslan Gulaliyev',
        'position' => 'admin',
        'account_type' => 'admin',
        'employee_role' => 'admin',
        'department' => 'Administration',
        'area' => '',
        'areas' => $allServiceAreas,
    ],
    [
        'full_name' => 'Seltenet Abdullayeva',
        'position' => 'admin',
        'account_type' => 'admin',
        'employee_role' => 'admin',
        'department' => 'Administration',
        'area' => '',
        'areas' => $allServiceAreas,
    ],
];

$created = 0;
$updated = 0;
$failed = [];
$savedRows = [];

foreach ($employees as $employee) {
    $login = (string) ($employee['login'] ?? (seed_employee_slug((string) $employee['full_name']) . '@superoptic.local'));
    $existing = seed_existing_account($db, (string) $employee['full_name'], $login);
    $wasExisting = is_array($existing);

    $payload = is_array($existing) ? app_db_account_payload($existing) : [];
    $payload['employee'] = array_merge((array) ($payload['employee'] ?? []), [
        'source' => 'company_staff_seed_2026_06_13',
        'position' => $employee['position'],
        'display_role' => $employee['position'],
        'department' => $employee['department'],
        'primary_area' => $employee['area'],
        'areas' => $employee['areas'],
        'finance_responsibility' => !empty($employee['finance_responsibility']),
        'night_shift' => !empty($employee['night_shift']),
        'super_admin' => !empty($employee['super_admin']),
        'superpilot_enabled' => true,
        'updated_at' => date('c'),
    ]);

    $saved = app_db_upsert_account([
        'id' => (int) ($existing['id'] ?? 0),
        'login' => $wasExisting ? (string) ($existing['login'] ?? $login) : $login,
        'password' => $wasExisting ? '' : seed_tmp_password($login),
        'role' => 'employee',
        'account_type' => $employee['account_type'],
        'employee_role' => $employee['employee_role'],
        'full_name' => $employee['full_name'],
        'email' => $wasExisting ? (string) ($existing['email'] ?? '') : $login,
        'phone' => $wasExisting ? (string) ($existing['phone'] ?? '') : '',
        'category' => $employee['department'],
        'area' => $employee['area'],
        'address' => implode(', ', (array) $employee['areas']),
        'status' => 'Aktiv',
        'is_active' => 1,
        'payload' => $payload,
        'responsible_areas' => $employee['areas'],
    ], false);

    if (!is_array($saved)) {
        $failed[] = [
            'full_name' => $employee['full_name'],
            'login' => $login,
        ];
        continue;
    }

    $wasExisting ? $updated++ : $created++;
    $savedRows[] = [
        'id' => (int) $saved['id'],
        'login' => (string) $saved['login'],
        'full_name' => (string) $saved['full_name'],
        'role' => (string) $saved['employee_role'],
        'type' => (string) $saved['account_type'],
        'areas' => $employee['areas'],
        'action' => $wasExisting ? 'updated' : 'created',
    ];
}

$verification = [
    'expected' => count($employees),
    'created' => $created,
    'updated' => $updated,
    'failed' => $failed,
    'employees' => $savedRows,
];

echo json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
