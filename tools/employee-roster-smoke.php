<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require dirname(__DIR__) . '/config.php';

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is not available.\n");
    exit(1);
}

$allServiceAreas = app_service_areas();
$expected = [
    'Ferid Cebrayilov' => ['account_type' => 'technician', 'employee_role' => 'engineer', 'areas' => ['Baki']],
    'Nurlan Huseynli' => ['account_type' => 'technician', 'employee_role' => 'technician', 'areas' => ['Baki']],
    'Heyder Eliyev' => ['account_type' => 'technician', 'employee_role' => 'technician', 'areas' => ['Baki']],
    'Xeyyam Ferecov' => ['account_type' => 'technician', 'employee_role' => 'technician', 'areas' => ['Baki']],
    'Mehreli Ceferov' => ['account_type' => 'technician', 'employee_role' => 'technician', 'areas' => ['Astara', 'Lenkeran']],
    'Orxan Memmedov' => ['account_type' => 'technician', 'employee_role' => 'technician', 'areas' => ['Astara']],
    'Ebulfez Agayev' => ['account_type' => 'technician', 'employee_role' => 'engineer', 'areas' => ['Astara', 'Masalli']],
    'Vusal Zeynalov' => ['account_type' => 'technician', 'employee_role' => 'engineer', 'areas' => ['Neftcala']],
    'Zabil Memmedli' => ['account_type' => 'technician', 'employee_role' => 'technician', 'areas' => ['Masalli']],
    'Sugra Ehmedzade' => ['account_type' => 'operator', 'employee_role' => 'finance', 'areas' => ['Baki', 'Astara', 'Lenkeran', 'Masalli', 'Neftcala']],
    'Rufane Demirciyeva' => ['account_type' => 'operator', 'employee_role' => 'finance', 'areas' => ['Baki', 'Astara', 'Lenkeran', 'Masalli', 'Neftcala']],
    'Guluzar Ehmedli' => ['account_type' => 'operator', 'employee_role' => 'night_operator', 'areas' => ['Baki', 'Astara', 'Lenkeran', 'Masalli', 'Neftcala']],
    'Mehseti Memmedova' => ['account_type' => 'operator', 'employee_role' => 'night_operator', 'areas' => ['Baki', 'Astara', 'Lenkeran', 'Masalli', 'Neftcala']],
    'Serdar Abdullayev' => ['account_type' => 'admin', 'employee_role' => 'admin', 'areas' => $allServiceAreas],
    'Ruslan Gulaliyev' => ['account_type' => 'admin', 'employee_role' => 'admin', 'areas' => $allServiceAreas],
    'Seltenet Abdullayeva' => ['account_type' => 'admin', 'employee_role' => 'admin', 'areas' => $allServiceAreas],
];

$accountQuery = $db->prepare(
    "SELECT id, login, full_name, account_type, employee_role, is_active
     FROM portal_accounts
     WHERE role = 'employee' AND LOWER(full_name) = LOWER(:full_name)
     ORDER BY id ASC"
);
$areaQuery = $db->prepare('SELECT area FROM portal_account_areas WHERE account_id = :account_id ORDER BY area');

$failures = [];
$checked = [];

foreach ($expected as $fullName => $rule) {
    $accountQuery->execute(['full_name' => $fullName]);
    $accounts = $accountQuery->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($accounts) || $accounts === []) {
        $failures[] = "$fullName missing";
        continue;
    }

    $best = null;
    $candidateFailures = [];
    foreach ($accounts as $account) {
        if ((int) ($account['is_active'] ?? 0) !== 1) {
            $candidateFailures[] = "$fullName inactive account " . (string) ($account['login'] ?? $account['id'] ?? '');
            continue;
        }

        $roleOk = true;
        foreach (['account_type', 'employee_role'] as $field) {
            if ((string) ($account[$field] ?? '') !== (string) $rule[$field]) {
                $roleOk = false;
                $candidateFailures[] = "$fullName $field expected {$rule[$field]} got " . (string) ($account[$field] ?? '');
            }
        }
        if (!$roleOk) {
            continue;
        }

        $areaQuery->execute(['account_id' => (int) $account['id']]);
        $areas = array_values(array_map('strval', $areaQuery->fetchAll(PDO::FETCH_COLUMN)));
        sort($areas);
        $expectedAreas = $rule['areas'];
        sort($expectedAreas);
        $areaOk = empty(array_diff($expectedAreas, $areas));
        if (!$areaOk) {
            $candidateFailures[] = "$fullName areas expected at least " . implode(',', $expectedAreas) . ' got ' . implode(',', $areas);
            continue;
        }

        $best = [$account, $areas];
        break;
    }

    if ($best === null) {
        $failures[] = implode('; ', $candidateFailures) ?: "$fullName no matching active account";
        continue;
    }

    [$account, $areas] = $best;
    $checked[] = [
        'id' => (int) $account['id'],
        'full_name' => $fullName,
        'login' => (string) $account['login'],
        'type' => (string) $account['account_type'],
        'role' => (string) $account['employee_role'],
        'areas' => $areas,
    ];
}

echo json_encode([
    'status' => $failures === [] ? 'ok' : 'failed',
    'expected' => count($expected),
    'checked' => count($checked),
    'failures' => $failures,
    'employees' => $checked,
    'checked_at' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 1);
