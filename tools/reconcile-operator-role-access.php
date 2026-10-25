<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv, true)) {
    fwrite(STDERR, "CLI only. Use --apply.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config.php';

$profiles = [
    'sugra.ehmedzade' => [
        'role' => 'operator',
        'title' => 'Operator · Maliyyə · Anbardar',
        'capabilities' => ['operator','noc_monitor','finance','warehouse','warehouse_manager'],
        'modules' => ['billing','crm','messaging','whatsapp_monitor','statistics','network','finance','inventory'],
    ],
    'rufane.demirciyeva' => [
        'role' => 'operator',
        'title' => 'Operator · Maliyyə · Anbardar',
        'capabilities' => ['operator','noc_monitor','finance','warehouse','warehouse_manager'],
        'modules' => ['billing','crm','messaging','whatsapp_monitor','statistics','network','finance','inventory'],
    ],
    'guluzar.ehmedli' => [
        'role' => 'night_operator',
        'title' => 'Gecə operatoru',
        'capabilities' => ['night_operator','noc_monitor'],
        'modules' => ['billing','crm','messaging','whatsapp_monitor','statistics','network'],
    ],
    'mehseti.memmedova' => [
        'role' => 'night_operator',
        'title' => 'Gecə operatoru',
        'capabilities' => ['night_operator','noc_monitor'],
        'modules' => ['billing','crm','messaging','whatsapp_monitor','statistics','network'],
    ],
];

$legacyLogins = [
    'sugra.ehmedzade@superoptic.local',
    'rufane.demirciyeva@superoptic.local',
    'guluzar.ehmedli@superoptic.local',
    'mehseti.memmedova@superoptic.local',
];

$db = app_db();
$find = $db->prepare("SELECT id,payload_json FROM portal_accounts WHERE login=? AND role='employee' LIMIT 1 FOR UPDATE");
$update = $db->prepare(
    "UPDATE portal_accounts
        SET account_type='operator',employee_role=?,job_title=?,status='Aktiv',is_active=1,payload_json=?,updated_at=NOW()
      WHERE id=?"
);
$archive = $db->prepare(
    "UPDATE portal_accounts
        SET status='Arxiv',is_active=0,updated_at=NOW()
      WHERE login=? AND role='employee'"
);
$schedule = $db->prepare(
    "INSERT INTO mobile_operator_schedules
        (account_id,shift_start,shift_end,weekdays_json,breaks_json,required_interval_minutes,grace_minutes,is_enabled)
     VALUES (?,'20:00:00','08:00:00','[1,2,3,4,5,6,7]','[]',60,10,1)
     ON DUPLICATE KEY UPDATE shift_start=VALUES(shift_start),shift_end=VALUES(shift_end),
        required_interval_minutes=VALUES(required_interval_minutes),grace_minutes=VALUES(grace_minutes),is_enabled=1"
);

$changed = [];
$archived = [];
$db->beginTransaction();
try {
    foreach ($profiles as $login => $profile) {
        $find->execute([$login]);
        $account = $find->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            throw new RuntimeException("Aktiv əsas hesab tapılmadı: {$login}");
        }
        $payload = json_decode((string) ($account['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $payload['mobile_access'] = true;
        $payload['mobile_capabilities'] = $profile['capabilities'];
        $payload['access']['module_keys'] = $profile['modules'];
        $payload['role_assignment'] = [
            'primary_role' => $profile['role'],
            'additional_capabilities' => array_values(array_diff($profile['capabilities'], [$profile['role']])),
            'managed_by' => 'hr_or_super_admin',
            'updated_at' => date(DATE_ATOM),
        ];
        $update->execute([
            $profile['role'],
            $profile['title'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            (int) $account['id'],
        ]);
        if ($profile['role'] === 'night_operator') {
            $schedule->execute([(int) $account['id']]);
        }
        $changed[] = ['id' => (int) $account['id'], 'login' => $login, 'role' => $profile['role']];
    }

    foreach ($legacyLogins as $legacyLogin) {
        $archive->execute([$legacyLogin]);
        if ($archive->rowCount() > 0) $archived[] = $legacyLogin;
    }
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}

echo json_encode(['updated' => $changed, 'archived_legacy_accounts' => $archived], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
