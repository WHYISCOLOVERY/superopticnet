<?php
declare(strict_types=1);

require __DIR__ . '/../config.php';

$apply = in_array('--apply', $argv, true);
$db = app_db();
app_db_migrate_schema($db);

$allowedEmployeeLogins = [
    'ferid.cebrayilov@superoptic.local',
    'nurlan.huseynli@superoptic.local',
    'heyder.eliyev@superoptic.local',
    'xeyyam.ferecov@superoptic.local',
    'mehreli.ceferov@superoptic.local',
    'orxan.memmedov@superoptic.local',
    'ebulfez.agayev@superoptic.local',
    'vusal.zeynalov@superoptic.local',
    'zabil.memmedli@superoptic.local',
    'sugra.ehmedzade@superoptic.local',
    'rufane.demirciyeva@superoptic.local',
    'guluzar.ehmedli@superoptic.local',
    'mehseti.memmedova@superoptic.local',
    'serdar@sp.az',
    'ruslan.gulaliyev@superoptic.local',
    'seltenet.abdullayeva@superoptic.local',
];

$employees = $db->query(
    "SELECT id, login, full_name, area, account_type, employee_role, status
       FROM portal_accounts
      WHERE role='employee'
      ORDER BY full_name, login"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$removeEmployees = array_values(array_filter($employees, static function (array $row) use ($allowedEmployeeLogins): bool {
    return !in_array((string) ($row['login'] ?? ''), $allowedEmployeeLogins, true);
}));

$prefixRows = $db->query(
    "SELECT id, area_name, area_key, code_prefix, note
       FROM customer_area_code_prefixes
      WHERE is_active=1
      ORDER BY area_name"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$allowedAreaNames = [
    'Qaracuxur',
    'Qaraçuxur',
    'Astara',
    'Sahagaci',
    'Şahağacı',
    'Bileceri',
    'Biləcəri',
    'Kurdexani',
    'Kürdəxanı',
    'Zabrat',
    'Lenkeran',
    'Lənkəran',
    'Masalli',
    'Masallı',
    'Neftcala',
    'Baki',
];

$removePrefixes = array_values(array_filter($prefixRows, static function (array $row) use ($allowedAreaNames): bool {
    $area = (string) ($row['area_name'] ?? '');
    return !in_array($area, $allowedAreaNames, true);
}));

$result = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'remove_employees' => $removeEmployees,
    'remove_bad_prefixes' => $removePrefixes,
    'deleted' => [
        'employees' => [],
        'bad_prefixes' => [],
    ],
    'errors' => [],
    'rolled_back' => false,
];

if ($apply) {
    try {
        $db->beginTransaction();

        $deleteEmployee = $db->prepare('DELETE FROM portal_accounts WHERE id = :id AND role = "employee"');
        foreach ($removeEmployees as $row) {
            $deleteEmployee->execute([':id' => (int) $row['id']]);
            $result['deleted']['employees'][] = $row;
        }

        $deletePrefix = $db->prepare('DELETE FROM customer_area_code_prefixes WHERE id = :id');
        foreach ($removePrefixes as $row) {
            $deletePrefix->execute([':id' => (int) $row['id']]);
            $result['deleted']['bad_prefixes'][] = $row;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
            $result['rolled_back'] = true;
        }
        $result['errors'][] = $e->getMessage();
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
