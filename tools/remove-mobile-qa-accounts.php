<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv, true)) {
    fwrite(STDERR, "CLI only. Use --apply.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/config.php';
$db = app_db();
$rows = $db->query(
    "SELECT id,login FROM portal_accounts WHERE login LIKE 'mobile.qa.%'
      AND JSON_UNQUOTE(JSON_EXTRACT(COALESCE(payload_json,'{}'),'$.qa_fixture')) IN ('1','true')"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
if ($ids === []) {
    echo "removed_qa_accounts=0\n";
    exit(0);
}
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$db->beginTransaction();
try {
    $db->prepare("UPDATE portal_tickets SET assigned_technician_account_id=NULL,assigned_technician_name=NULL,status=IF(status IN ('assigned','in_progress'),'open',status) WHERE assigned_technician_account_id IN ({$placeholders})")
        ->execute($ids);
    foreach ([
        ['mobile_auth_refresh_tokens','account_id'], ['mobile_device_tokens','account_id'],
        ['hr_employee_legal_consents','account_id'], ['employee_live_locations','account_id'],
        ['mobile_outbox_queue','account_id'], ['mobile_task_otps','account_id'], ['mobile_task_proofs','account_id'],
        ['mobile_duty_sessions','account_id'], ['mobile_employee_home_geofences','account_id'],
        ['mobile_home_dwell_events','account_id'], ['mobile_manager_alerts','account_id'],
        ['portal_account_areas','account_id'], ['portal_account_identities','account_id'],
    ] as [$table, $column]) {
        try {
            $db->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})")->execute($ids);
        } catch (Throwable) {
        }
    }
    $db->prepare("DELETE FROM portal_accounts WHERE id IN ({$placeholders})")->execute($ids);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
echo 'removed_qa_accounts=' . count($ids) . PHP_EOL;
