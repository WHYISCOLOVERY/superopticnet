<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv, true)) {
    fwrite(STDERR, "CLI only. Use --apply.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config.php';

$db = app_db();
$targets = $db->query(
    "SELECT id,login,full_name,employee_role,is_active
       FROM portal_accounts
      WHERE role='employee'
        AND (login LIKE 'cp-tech-%'
             OR login IN ('heyder.eliyev@superoptic.local','xeyyam.ferecov@superoptic.local'))
      ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($targets === []) {
    echo "removed_accounts=0\n";
    exit(0);
}

$ids = array_map(static fn(array $row): int => (int) $row['id'], $targets);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$db->beginTransaction();
try {
    // Keep customer history, but return unfinished work to the unassigned queue.
    $db->prepare(
        "UPDATE portal_tickets
            SET assigned_technician_account_id=NULL,
                assigned_technician_name='',
                status=CASE WHEN status IN ('assigned','in_progress') THEN 'Aciqdir' ELSE status END,
                updated_at=NOW()
          WHERE assigned_technician_account_id IN ($placeholders)"
    )->execute($ids);

    foreach ([
        'mobile_auth_refresh_tokens', 'mobile_device_tokens', 'mobile_duty_sessions',
        'employee_live_locations', 'mobile_outbox_queue', 'mobile_task_proofs',
        'hr_employee_legal_consents', 'mobile_employee_home_geofences',
        'mobile_home_dwell_events', 'mobile_operator_activity', 'mobile_operator_schedules',
    ] as $table) {
        $exists = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
        );
        $exists->execute([$table]);
        if (!(bool) $exists->fetchColumn()) continue;
        $column = $table === 'mobile_operator_schedules' ? 'account_id' : 'account_id';
        $db->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ($placeholders)")->execute($ids);
    }

    $db->prepare("DELETE FROM portal_accounts WHERE id IN ($placeholders)")->execute($ids);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}

$directory = dirname(__DIR__) . '/data/private';
if (!is_dir($directory)) mkdir($directory, 0700, true);
$archive = $directory . '/removed-mobile-technicians-20261008.json';
file_put_contents($archive, json_encode([
    'removed_at' => date(DATE_ATOM),
    'reason' => 'Copilot texnik hesablarının təmizlənməsi və işdən ayrılan əməkdaşlar',
    'accounts' => $targets,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
@chmod($archive, 0600);

echo 'removed_accounts=' . count($targets) . PHP_EOL;
echo 'archive=' . $archive . PHP_EOL;
