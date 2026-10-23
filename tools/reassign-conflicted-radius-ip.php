<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(2);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/audit-log-lib.php';

$username = trim((string) ($argv[1] ?? ''));
$apply = in_array('--apply', $argv, true);
$db = app_db();
if (!$db instanceof PDO || $username === '') exit(2);
$q = $db->prepare('SELECT s.*,a.* FROM billing_radius_services s JOIN portal_accounts a ON a.id=s.account_id WHERE s.username=? LIMIT 1');
$q->execute([$username]);
$account = $q->fetch(PDO::FETCH_ASSOC);
if (!is_array($account) || (int) ($account['ip_pool_id'] ?? 0) <= 0) {
    echo json_encode(['ok' => false, 'reason' => 'service_or_pool_missing']) . PHP_EOL;
    exit(1);
}
$oldIp = trim((string) ($account['assigned_ip'] ?? ''));
$newIp = app_billing_next_available_ip((int) $account['ip_pool_id']);
if ($newIp === null || $newIp === '') {
    echo json_encode(['ok' => false, 'reason' => 'no_available_ip']) . PHP_EOL;
    exit(1);
}
$preview = ['ok' => true, 'mode' => $apply ? 'apply' : 'dry_run', 'username' => $username, 'old_ip' => $oldIp, 'new_ip' => $newIp];
if (!$apply) {
    echo json_encode($preview) . PHP_EOL;
    exit(0);
}

try {
    app_audit_ensure_schema($db);
    $db->beginTransaction();
    $lock = $db->prepare('SELECT * FROM portal_accounts WHERE id=? FOR UPDATE');
    $lock->execute([(int) $account['account_id']]);
    $fresh = $lock->fetch(PDO::FETCH_ASSOC);
    if (!is_array($fresh)) throw new RuntimeException('account_missing');
    $payload = app_db_account_payload($fresh);
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $billing['ip_address'] = $newIp;
    $billing['assigned_ip'] = $newIp;
    $billing['static_ip'] = '';
    $billing['ip_assignment_mode'] = 'manual';
    $payload['billing'] = $billing;
    $services = is_array($payload['internet_services'] ?? null) ? $payload['internet_services'] : [];
    foreach ($services as &$service) {
        if (strcasecmp((string) ($service['username'] ?? ''), $username) === 0) {
            $service['ip_address'] = $newIp;
            $service['assigned_ip'] = $newIp;
            $service['static_ip'] = '';
            $service['ip_assignment_mode'] = 'manual';
            break;
        }
    }
    unset($service);
    $payload['internet_services'] = $services;
    $db->prepare('UPDATE portal_accounts SET payload_json=?,updated_at=NOW() WHERE id=?')
        ->execute([app_db_json_encode($payload), (int) $fresh['id']]);
    $fresh['payload_json'] = app_db_json_encode($payload);
    if (!app_billing_sync_radius_account($fresh, null, $db)) throw new RuntimeException('radius_sync_failed');
    app_audit_record($db, [
        'module_key' => 'billing', 'operation_class' => 'network_recovery',
        'action_type' => 'radius_ip_conflict_reassigned', 'entity_type' => 'customer',
        'entity_id' => (string) $fresh['id'], 'status' => 'executed',
        'actor_name' => 'RADIUS recovery', 'summary' => 'Conflicted RADIUS IP reassigned',
        'reason' => 'Stale NAS session held the previous framed IP',
        'before' => ['assigned_ip' => $oldIp], 'after' => ['assigned_ip' => $newIp],
        'payload' => ['username' => $username], 'sensitivity' => 'high',
    ]);
    $db->commit();
    echo json_encode($preview) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['ok' => false, 'reason' => $exception->getMessage(), 'username' => $username]) . PHP_EOL;
    exit(1);
}
