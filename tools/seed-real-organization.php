<?php
declare(strict_types=1);

require __DIR__ . '/../config.php';

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

app_db_seed_branches($db);
app_db_seed_area_code_prefixes($db);

$branches = [
    ['Baki', 'Baki', 'Merkez ve Abseron/Baki xidmetleri'],
    ['Astara', 'Astara', 'Astara filial xidmeti'],
    ['Lenkeran', 'Lenkeran', 'Lenkeran xidmet zonasi'],
    ['Masalli', 'Masalli', 'Masalli xidmet zonasi'],
    ['Neftcala', 'Neftcala', 'Neftcala xidmet zonasi'],
];

foreach ($branches as [$name, $area, $note]) {
    app_db_upsert_branch($db, $name, $area, '', $note, 'active');
}

$areaPrefixes = [
    'Qaracuxur' => '100',
    'Astara' => '300',
    'Sahagaci' => '200',
    'Bileceri' => '110',
    'Kurdexani' => '232',
    'Zabrat' => '232',
    'Lenkeran' => '500',
    'Masalli' => '400',
    'Neftcala' => '600',
    'Baki' => '700',
];
foreach ($areaPrefixes as $area => $prefix) {
    app_db_upsert_area_code_prefix($db, $area, $prefix, 'Real operation area prefix');
}

function so_employee_login(string $name): string
{
    $map = [
        'Ə' => 'E', 'ə' => 'e', 'Ç' => 'C', 'ç' => 'c', 'Ğ' => 'G', 'ğ' => 'g',
        'İ' => 'I', 'ı' => 'i', 'Ö' => 'O', 'ö' => 'o', 'Ş' => 'S', 'ş' => 's',
        'Ü' => 'U', 'ü' => 'u',
    ];
    $ascii = strtr($name, $map);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ascii);
    $ascii = is_string($ascii) ? $ascii : $name;
    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '.', trim($ascii)));
    $slug = trim($slug, '.');
    return $slug . '@superoptic.local';
}

function so_existing_employee(PDO $db, string $login, string $name): ?array
{
    $existing = app_db_account_by_login($login);
    if (is_array($existing)) {
        return $existing;
    }
    $statement = $db->prepare('SELECT * FROM portal_accounts WHERE role = "employee" AND full_name = :full_name ORDER BY id LIMIT 1');
    $statement->execute(['full_name' => $name]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

$employees = [
    ['Ferid Cebrayilov', 'engineer', 'technician', 'Baki', ['Baki', 'Kurdexani', 'Zabrat', 'Bileceri', 'Qaracuxur']],
    ['Nurlan Huseynli', 'technician', 'technician', 'Baki', ['Baki', 'Kurdexani', 'Zabrat', 'Bileceri', 'Qaracuxur']],
    ['Heyder Eliyev', 'technician', 'technician', 'Baki', ['Baki', 'Kurdexani', 'Zabrat', 'Bileceri', 'Qaracuxur']],
    ['Xeyyam Ferecov', 'technician', 'technician', 'Baki', ['Baki', 'Kurdexani', 'Zabrat', 'Bileceri', 'Qaracuxur']],
    ['Mehreli Ceferov', 'technician', 'technician', 'Astara', ['Astara', 'Lenkeran']],
    ['Orxan Memmedov', 'technician', 'technician', 'Astara', ['Astara']],
    ['Ebulfez Agayev', 'engineer', 'technician', 'Astara', ['Astara', 'Masalli']],
    ['Vusal Zeynalov', 'engineer', 'technician', 'Neftcala', ['Neftcala']],
    ['Zabil Memmedli', 'technician', 'technician', 'Masalli', ['Masalli']],
    ['Sugra Ehmedzade', 'finance', 'operator', 'Baki', ['Baki', 'Astara', 'Lenkeran', 'Masalli', 'Neftcala']],
    ['Rufane Demirciyeva', 'finance', 'operator', 'Baki', ['Baki', 'Astara', 'Lenkeran', 'Masalli', 'Neftcala']],
    ['Guluzar Ehmedli', 'night_operator', 'operator', 'Baki', ['Baki', 'Astara', 'Lenkeran', 'Masalli', 'Neftcala']],
    ['Mehseti Memmedova', 'night_operator', 'operator', 'Baki', ['Baki', 'Astara', 'Lenkeran', 'Masalli', 'Neftcala']],
    ['Serdar Abdullayev', 'admin', 'admin', 'Baki', app_service_areas()],
    ['Ruslan Gulaliyev', 'admin', 'admin', 'Baki', app_service_areas()],
    ['Seltenet Abdullayeva', 'admin', 'admin', 'Baki', app_service_areas()],
];

$created = 0;
$updated = 0;
$defaultPassword = 'SuperOptic!2026';
foreach ($employees as [$name, $employeeRole, $accountType, $area, $areas]) {
    $login = so_employee_login($name);
    if ($name === 'Serdar Abdullayev') {
        $login = 'serdar@sp.az';
    }
    $existing = so_existing_employee($db, $login, $name);
    $payload = [
        'id' => (int) ($existing['id'] ?? 0),
        'external_id' => 'real-employee-' . app_db_area_code_key($name),
        'login' => $login,
        'password' => is_array($existing) ? '' : $defaultPassword,
        'role' => 'employee',
        'account_type' => $accountType,
        'employee_role' => $employeeRole,
        'full_name' => $name,
        'subscriber_code' => '',
        'contract_no' => '',
        'email' => $login,
        'phone' => '',
        'category' => '',
        'area' => $area,
        'address' => '',
        'plan' => '',
        'status' => 'Aktiv',
        'payload' => [
            'employee' => [
                'telegram_chat_id' => '',
                'branch' => $area,
                'role_label' => $employeeRole,
                'source' => 'real_organization_seed',
            ],
            'permissions' => [
                'role_based_access',
                'area_based_work_queue',
                'superpilot_context',
            ],
        ],
        'responsible_areas' => $areas,
        'is_active' => 1,
    ];
    $saved = app_db_upsert_account($payload, false);
    if (is_array($saved)) {
        is_array($existing) ? $updated++ : $created++;
    }
}

echo json_encode([
    'ok' => true,
    'created' => $created,
    'updated' => $updated,
    'default_password_for_new_accounts' => $defaultPassword,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
