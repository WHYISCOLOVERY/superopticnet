<?php
declare(strict_types=1);

require __DIR__ . '/../config.php';

$apply = in_array('--apply', $argv, true);
$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$candidateSql = [
    'customers' => "SELECT id, login, subscriber_code, full_name, area, role, account_type
        FROM portal_accounts
        WHERE role = 'customer'
          AND (
            subscriber_code LIKE 'TGACT-%'
            OR subscriber_code LIKE 'SEC-SMOKE-%'
            OR subscriber_code LIKE 'SMOKE-%'
            OR login LIKE 'TGACT-%'
            OR login LIKE 'SEC-SMOKE-%'
            OR login LIKE 'SMOKE-%'
            OR login LIKE 'smoke-%'
            OR login LIKE 'rds%'
            OR login IN ('1200000','909990999')
            OR full_name IN ('test','Test api','Test IPoE Musteri 1200000','Telegram Action Customer')
            OR full_name LIKE '% Smoke'
            OR full_name LIKE '% Smoke %'
            OR full_name LIKE 'Radius Cycle Test %'
          )
        ORDER BY id",
    'employees' => "SELECT id, login, subscriber_code, full_name, area, role, account_type
        FROM portal_accounts
        WHERE role = 'employee'
          AND (
            login LIKE 'agent.%'
            OR login LIKE 'cp-tech-%'
            OR login LIKE 'smoke.password.%'
            OR login LIKE 'tg-operator-%'
            OR login = 'staff-login'
            OR full_name LIKE 'Role Agent %'
            OR full_name LIKE 'Copilot Texnik %'
            OR full_name = 'Smoke Password User'
            OR full_name = 'Telegram Smoke Operator'
            OR area = 'Role QA'
          )
        ORDER BY id",
    'tariffs' => "SELECT id, name, service_type, radius_group
        FROM billing_tariffs
        WHERE name LIKE '%test%'
           OR name LIKE '%demo%'
           OR name LIKE '%smoke%'
        ORDER BY id",
];

$result = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'candidates' => [],
    'deleted' => [],
    'skipped' => [],
    'errors' => [],
];

foreach ($candidateSql as $group => $sql) {
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $result['candidates'][$group] = [
        'count' => count($rows),
        'sample' => array_slice($rows, 0, 20),
    ];
}

if ($apply) {
    $db->beginTransaction();
    try {
        $deleteAccount = $db->prepare('DELETE FROM portal_accounts WHERE id = :id');
        foreach (['customers', 'employees'] as $group) {
            foreach (($db->query($candidateSql[$group])->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                try {
                    $deleteAccount->execute(['id' => (int) $row['id']]);
                    $result['deleted'][$group][] = $row;
                } catch (Throwable $exception) {
                    $result['errors'][] = [
                        'group' => $group,
                        'id' => (int) ($row['id'] ?? 0),
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }

        $deleteTariff = $db->prepare('DELETE FROM billing_tariffs WHERE id = :id');
        $serviceCount = $db->prepare('SELECT COUNT(*) FROM billing_radius_services WHERE tariff_id = :id');
        foreach (($db->query($candidateSql['tariffs'])->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $serviceCount->execute(['id' => (int) $row['id']]);
            if ((int) $serviceCount->fetchColumn() > 0) {
                $result['skipped']['tariffs'][] = [
                    'row' => $row,
                    'reason' => 'tariff_has_radius_services',
                ];
                continue;
            }
            try {
                $deleteTariff->execute(['id' => (int) $row['id']]);
                $result['deleted']['tariffs'][] = $row;
            } catch (Throwable $exception) {
                $result['errors'][] = [
                    'group' => 'tariffs',
                    'id' => (int) ($row['id'] ?? 0),
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($result['errors'] !== []) {
            $db->rollBack();
            $result['rolled_back'] = true;
        } else {
            $db->commit();
            $result['rolled_back'] = false;
        }
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $result['errors'][] = ['group' => 'transaction', 'message' => $exception->getMessage()];
        $result['rolled_back'] = true;
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
