<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv, true)) {
    fwrite(STDERR, "CLI only. Use --apply.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/config.php';

$profiles = [
    's.abdullayev@superoptic.net' => ['super_admin', 'Super Admin', ['manager','finance','warehouse_manager','operator','noc','engineer','technician','team_lead']],
    'ferid.cebrayilov' => ['team_lead', 'Baş texnik', ['technician','team_lead']],
    'nurlan.huseyinli' => ['technician', 'Texnik', ['technician']],
    'vusal.zeynalov' => ['team_lead', 'Baş texnik', ['technician','team_lead']],
    'ebulfez.agayev' => ['team_lead', 'Baş texnik', ['technician','team_lead']],
    'orxan.efselov' => ['technician', 'Texnik', ['technician']],
    'mehrali.ceferov' => ['technician', 'Texnik', ['technician']],
    'zabil.quliyev' => ['technician', 'Texnik', ['technician']],
    'sugra.ehmedzade' => ['operator', 'Operator · Maliyyə · Anbardar', ['operator','noc_monitor','finance','warehouse','warehouse_manager']],
    'rufane.demirciyeva' => ['operator', 'Operator · Maliyyə · Anbardar', ['operator','noc_monitor','finance','warehouse','warehouse_manager']],
    'mehseti.memmedova' => ['night_operator', 'Gecə operatoru', ['night_operator','noc_monitor']],
    'guluzar.ehmedli' => ['night_operator', 'Gecə operatoru', ['night_operator','noc_monitor']],
    'ruslan.gulaliyev' => ['admin', 'Direktor müavini', ['manager','operator','technician','team_lead','noc','engineer','warehouse_manager','finance']],
];

function production_password(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $password = 'A1!';
    for ($i = 0; $i < 21; $i++) $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $password;
}

$db = app_db();
$select = $db->prepare('SELECT * FROM portal_accounts WHERE login=? AND role="employee" LIMIT 1 FOR UPDATE');
$update = $db->prepare(
    'UPDATE portal_accounts SET account_type=?,employee_role=?,job_title=?,password_hash=?,status="Aktiv",is_active=1,payload_json=?,updated_at=NOW() WHERE id=?'
);
$credentials = [];
$db->beginTransaction();
try {
    foreach ($profiles as $login => [$employeeRole, $jobTitle, $capabilities]) {
        $select->execute([$login]);
        $account = $select->fetch(PDO::FETCH_ASSOC);
        if (!$account) throw new RuntimeException("Required account not found: {$login}");
        $payload = json_decode((string) ($account['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        unset($payload['qa_fixture']);
        $payload['mobile_access'] = true;
        $payload['mobile_capabilities'] = $capabilities;
        $moduleMap = [
            'operator' => ['billing','crm','messaging','whatsapp_monitor','statistics','network'],
            'night_operator' => ['billing','crm','messaging','whatsapp_monitor','statistics','network'],
            'finance' => ['finance'],
            'warehouse' => ['inventory'],
            'warehouse_manager' => ['inventory'],
            'noc_monitor' => ['network'],
        ];
        $moduleKeys = [];
        foreach ($capabilities as $capability) {
            $moduleKeys = array_merge($moduleKeys, $moduleMap[$capability] ?? []);
        }
        $payload['access']['module_keys'] = array_values(array_unique($moduleKeys));
        $payload['security']['password_reset_at'] = date(DATE_ATOM);
        $payload['security']['password_reset_origin'] = 'production_activation_2026-10-08';
        $password = production_password();
        $accountType = in_array($employeeRole, ['super_admin','admin'], true)
            ? 'admin'
            : (in_array($employeeRole, ['operator','night_operator'], true) ? 'operator' : 'technician');
        $update->execute([
            $accountType, $employeeRole, $jobTitle, password_hash($password, PASSWORD_DEFAULT),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $account['id'],
        ]);
        $db->prepare('DELETE FROM mobile_auth_refresh_tokens WHERE account_id=?')->execute([(int) $account['id']]);
        $db->prepare('UPDATE mobile_device_tokens SET is_active=0,revoked_at=NOW(),revoked_reason="production_password_rotation" WHERE account_id=? AND is_active=1')
            ->execute([(int) $account['id']]);
        $credentials[] = [
            'id' => (int) $account['id'], 'full_name' => (string) $account['full_name'], 'login' => $login,
            'job_title' => $jobTitle, 'initial_password' => $password,
        ];
    }
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}

$directory = dirname(__DIR__) . '/data/private';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Private directory unavailable.');
$path = $directory . '/mobile-production-credentials-20261008.json';
file_put_contents($path, json_encode([
    'created_at' => date(DATE_ATOM),
    'release_date' => '2026-10-12',
    'notice' => 'Bu fayl məxfidir. Parollar təqdim edildikdən sonra təhlükəsiz arxivə köçürün.',
    'accounts' => $credentials,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
@chmod($path, 0600);
@unlink($directory . '/mobile-staff-initial-credentials-20261008.json');
echo json_encode(['configured' => count($credentials), 'credentials_file' => $path], JSON_UNESCAPED_SLASHES), PHP_EOL;
