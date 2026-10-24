<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(2);
require_once dirname(__DIR__) . '/config.php';

$target = trim((string) ($argv[1] ?? ''));
if ($target === '') {
    fwrite(STDERR, "Usage: php queue-stale-ip-disconnect.php <target-username>\n");
    exit(2);
}
$db = app_db();
if (!$db instanceof PDO) exit(2);
$service = $db->prepare('SELECT assigned_ip,static_ip FROM billing_radius_services WHERE username=? LIMIT 1');
$service->execute([$target]);
$row = $service->fetch(PDO::FETCH_ASSOC);
$ip = trim((string) (($row['static_ip'] ?? '') ?: ($row['assigned_ip'] ?? '')));
if ($ip === '') {
    echo json_encode(['ok' => false, 'reason' => 'target_has_no_fixed_ip']) . PHP_EOL;
    exit(1);
}
$stale = $db->prepare(
    'SELECT r.*,a.id AS account_id
       FROM radacct r
       LEFT JOIN billing_radius_services s ON s.username=r.username
       LEFT JOIN portal_accounts a ON a.id=s.account_id
      WHERE r.framedipaddress=? AND r.username<>? AND r.acctstoptime IS NULL
        AND COALESCE(r.acctupdatetime,r.acctstarttime)<DATE_SUB(NOW(),INTERVAL 20 MINUTE)
      ORDER BY r.radacctid DESC LIMIT 1'
);
$stale->execute([$ip, $target]);
$session = $stale->fetch(PDO::FETCH_ASSOC);
if (!is_array($session)) {
    echo json_encode(['ok' => true, 'reason' => 'no_stale_conflict', 'target' => $target, 'ip' => $ip]) . PHP_EOL;
    exit(0);
}
$actionId = app_radius_request_disconnect(
    $db,
    $session,
    ['id' => 0, 'full_name' => 'RADIUS stale-session recovery'],
    'Stale framed IP conflicts with active customer ' . $target
);
echo json_encode([
    'ok' => $actionId > 0,
    'action_id' => $actionId,
    'target' => $target,
    'ip' => $ip,
    'stale_username' => (string) $session['username'],
    'stale_since' => (string) ($session['acctupdatetime'] ?? $session['acctstarttime'] ?? ''),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($actionId > 0 ? 0 : 1);
