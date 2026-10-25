<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
if (!in_array('--apply', $argv, true)) {
    fwrite(STDERR, "Use --apply to create or update the requested staff accounts.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config.php';

$staff = [
    ['ferid.cebrayilov', 'technician', 'team_lead', 'Fərid Cəbrayılov', 'Baş texnik'],
    ['nurlan.huseyinli', 'technician', 'technician', 'Nurlan Hüseyinli', 'Texnik'],
    ['vusal.zeynalov', 'technician', 'team_lead', 'Vüsal Zeynalov', 'Baş texnik'],
    ['ebulfez.agayev', 'technician', 'team_lead', 'Əbülfez Ağayev', 'Baş texnik'],
    ['orxan.efselov', 'technician', 'technician', 'Orxan Əfsəlov', 'Texnik'],
    ['mehrali.ceferov', 'technician', 'technician', 'Mehrəli Cəfərov', 'Texnik'],
    ['zabil.quliyev', 'technician', 'technician', 'Zabil Quliyev', 'Texnik'],
    ['sugra.ehmedzade', 'operator', 'operator', 'Suğra Əhmədzadə', 'Operator · Maliyyə · Anbardar'],
    ['rufane.demirciyeva', 'operator', 'operator', 'Rüfanə Dəmirçiyeva', 'Operator · Maliyyə · Anbardar'],
    ['mehseti.memmedova', 'operator', 'night_operator', 'Məhsəti Məmmədova', 'Gecə operatoru'],
    ['guluzar.ehmedli', 'operator', 'night_operator', 'Gülüzar Əhmədli', 'Gecə operatoru'],
    ['ruslan.gulaliyev', 'admin', 'director', 'Ruslan Gülaliyev', 'Direktor müavini'],
];

$loginAliases = [
    'vusal.zeynalov' => ['v.zeynalov@superoptic.net', 'vusal.zeynalov@superoptic.local'],
    'sugra.ehmedzade' => ['s.ehmedzade@superoptic.net', 'sugra.ehmedzade@superoptic.local'],
    'rufane.demirciyeva' => ['r.demirciyeva@superoptic.net', 'rufane.demirciyeva@superoptic.local'],
    'guluzar.ehmedli' => ['guluzar.ehmedli@superoptic.local'],
    'mehseti.memmedova' => ['mehseti.memmedova@superoptic.local'],
    'ruslan.gulaliyev' => ['ruslan.gulaliyev@superoptic.local'],
];

function staff_initial_password(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $password = '';
    for ($i = 0; $i < 20; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'A1!' . $password;
}

$db = app_db();
$insert = $db->prepare(
    'INSERT INTO portal_accounts
        (login,password_hash,role,account_type,employee_role,full_name,job_title,area,status,is_active,payload_json,created_at)
     VALUES (?, ?, "employee", ?, ?, ?, ?, "", "Aktiv", 1, ?, NOW())'
);
$update = $db->prepare(
    'UPDATE portal_accounts
        SET login=?,role="employee",account_type=?,employee_role=?,full_name=?,job_title=?,status="Aktiv",is_active=1,payload_json=?
      WHERE id=?'
);

$created = [];
$updated = [];
$db->beginTransaction();
try {
    foreach ($staff as [$login, $accountType, $employeeRole, $fullName, $jobTitle]) {
        $candidateLogins = array_values(array_unique(array_merge([$login], $loginAliases[$login] ?? [])));
        $loginPlaceholders = implode(',', array_fill(0, count($candidateLogins), '?'));
        $find = $db->prepare(
            "SELECT id,login,full_name,payload_json FROM portal_accounts
              WHERE login IN ({$loginPlaceholders}) OR full_name=?
              ORDER BY login=? DESC LIMIT 1"
        );
        $find->execute(array_merge($candidateLogins, [$fullName, $login]));
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        $payload = is_array($existing)
            ? (json_decode((string) ($existing['payload_json'] ?? ''), true) ?: [])
            : [];
        $payload['mobile_access'] = true;
        $payload['staff_directory_source'] = 'requested_2026-10-08';
        if (in_array($login, ['sugra.ehmedzade', 'rufane.demirciyeva'], true)) {
            $payload['mobile_capabilities'] = ['operator','noc_monitor','finance','warehouse','warehouse_manager'];
            $payload['access']['module_keys'] = ['billing','crm','messaging','whatsapp_monitor','statistics','network','finance','inventory'];
        } elseif ($employeeRole === 'night_operator') {
            $payload['mobile_capabilities'] = ['night_operator','noc_monitor'];
            $payload['access']['module_keys'] = ['billing','crm','messaging','whatsapp_monitor','statistics','network'];
        }
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($existing) {
            $update->execute([$login, $accountType, $employeeRole, $fullName, $jobTitle, $payloadJson, (int) $existing['id']]);
            $updated[] = [
                'id' => (int) $existing['id'],
                'login' => (string) $existing['login'],
                'full_name' => $fullName,
                'job_title' => $jobTitle,
            ];
            continue;
        }
        $password = staff_initial_password();
        $insert->execute([$login, password_hash($password, PASSWORD_DEFAULT), $accountType, $employeeRole, $fullName, $jobTitle, $payloadJson]);
        $created[] = [
            'id' => (int) $db->lastInsertId(),
            'login' => $login,
            'initial_password' => $password,
            'full_name' => $fullName,
            'job_title' => $jobTitle,
        ];
    }
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}

$credentialPath = null;
if ($created !== []) {
    $privateDirectory = dirname(__DIR__) . '/data/private';
    if (!is_dir($privateDirectory) && !mkdir($privateDirectory, 0700, true) && !is_dir($privateDirectory)) {
        throw new RuntimeException('Private credential directory could not be created.');
    }
    $credentialPath = $privateDirectory . '/mobile-staff-initial-credentials-20261008.json';
    $document = [
        'created_at' => date(DATE_ATOM),
        'notice' => 'İlkin parolları ilk girişdən sonra dəyişin və bu faylı silin.',
        'accounts' => $created,
    ];
    if (file_put_contents(
        $credentialPath,
        json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        LOCK_EX
    ) === false) {
        throw new RuntimeException('Credential file could not be written.');
    }
    @chmod($credentialPath, 0600);
}

echo json_encode([
    'created_count' => count($created),
    'updated_count' => count($updated),
    'credentials_file' => $credentialPath,
    'accounts' => array_map(
        static fn (array $row): array => array_diff_key($row, ['initial_password' => true]),
        array_merge($created, $updated)
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
