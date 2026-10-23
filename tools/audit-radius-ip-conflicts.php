<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';

$apply = in_array('--apply', $argv, true);
$hours = 48;
$minimumErrors = 3;
$limit = 10;
foreach ($argv as $argument) {
    if (preg_match('/^--hours=(\d+)$/', $argument, $match)) {
        $hours = max(1, min(168, (int) $match[1]));
    } elseif (preg_match('/^--min-errors=(\d+)$/', $argument, $match)) {
        $minimumErrors = max(3, min(100, (int) $match[1]));
    } elseif (preg_match('/^--limit=(\d+)$/', $argument, $match)) {
        $limit = max(1, min(25, (int) $match[1]));
    }
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$cutoff = date('Y-m-d H:i:s', time() - ($hours * 3600));
$maximumRadacctId = (int) ($db->query('SELECT MAX(radacctid) FROM radacct')->fetchColumn() ?: 0);
// New accounting rows are append-only in production. Limit the operational
// audit to the latest 500k inserts and still enforce the requested time window.
// This avoids imported historical rows whose acctstarttime was later rewritten.
$minimumRadacctId = max(0, $maximumRadacctId - 500000);

$errorRows = [];
if ($minimumRadacctId > 0) {
    $errors = $db->prepare(
        'SELECT username, COUNT(*) AS nas_errors, MAX(acctstarttime) AS last_error
           FROM radacct
          WHERE radacctid >= ? AND acctstarttime >= ? AND acctterminatecause = "NAS-Error"
          GROUP BY username HAVING COUNT(*) >= ?
          ORDER BY last_error DESC LIMIT 100'
    );
    $errors->execute([$minimumRadacctId, $cutoff, $minimumErrors]);
    $errorRows = $errors->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$serviceStatement = $db->prepare(
    'SELECT username, assigned_ip, ip_pool_id, status
       FROM billing_radius_services WHERE username=? LIMIT 1'
);
$freshStatement = $db->prepare(
    'SELECT COUNT(*) FROM radacct
      WHERE username=? AND acctstoptime IS NULL
        AND COALESCE(acctupdatetime,acctstarttime)>=DATE_SUB(NOW(),INTERVAL 20 MINUTE)'
);
$holderStatement = $db->prepare(
    'SELECT username, COALESCE(acctupdatetime,acctstarttime) AS last_seen
       FROM radacct FORCE INDEX (idx_radacct_framedip)
      WHERE framedipaddress=? AND username<>? AND acctstoptime IS NULL
      ORDER BY COALESCE(acctupdatetime,acctstarttime) DESC LIMIT 1'
);
$authStatement = $db->prepare(
    'SELECT reply,authdate FROM radpostauth WHERE username=? ORDER BY id DESC LIMIT 1'
);

$candidates = [];
foreach ($errorRows as $error) {
    $username = trim((string) ($error['username'] ?? ''));
    $serviceStatement->execute([$username]);
    $service = $serviceStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($service) || (string) ($service['status'] ?? '') !== 'active') {
        continue;
    }
    $ip = trim((string) ($service['assigned_ip'] ?? ''));
    if ($ip === '' || (int) ($service['ip_pool_id'] ?? 0) <= 0) {
        continue;
    }
    $freshStatement->execute([$username]);
    if ((int) $freshStatement->fetchColumn() > 0) {
        continue;
    }
    $authStatement->execute([$username]);
    $auth = $authStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    if (strcasecmp((string) ($auth['reply'] ?? ''), 'Access-Accept') !== 0) {
        continue;
    }
    $holderStatement->execute([$ip, $username]);
    $holder = $holderStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($holder)) {
        continue;
    }
    $candidates[] = [
        'username' => $username,
        'assigned_ip' => $ip,
        'nas_errors' => (int) ($error['nas_errors'] ?? 0),
        'last_error' => (string) ($error['last_error'] ?? ''),
        'last_auth' => (string) ($auth['authdate'] ?? ''),
        'conflicting_username' => (string) ($holder['username'] ?? ''),
        'conflict_last_seen' => (string) ($holder['last_seen'] ?? ''),
    ];
}

$duplicateReplyCount = (int) $db->query(
    'SELECT COUNT(*) FROM (
        SELECT value FROM radreply WHERE attribute="Framed-IP-Address"
        GROUP BY value HAVING COUNT(DISTINCT username)>1
     ) duplicate_replies'
)->fetchColumn();
$liveConflictCount = (int) $db->query(
    'SELECT COUNT(*) FROM (
        SELECT DISTINCT s.username
          FROM radacct r FORCE INDEX (idx_radacct_updatetime_username)
          JOIN billing_radius_services s ON s.assigned_ip=r.framedipaddress
         WHERE r.acctupdatetime>=DATE_SUB(NOW(),INTERVAL 20 MINUTE)
           AND r.acctstoptime IS NULL AND r.username<>s.username AND s.status="active"
     ) live_conflicts'
)->fetchColumn();

$applied = [];
if ($apply) {
    foreach (array_slice($candidates, 0, $limit) as $candidate) {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__DIR__ . '/reassign-conflicted-radius-ip.php') . ' '
            . escapeshellarg((string) $candidate['username']) . ' --apply';
        $lines = [];
        $code = 1;
        exec($command . ' 2>&1', $lines, $code);
        $applied[] = [
            'username' => $candidate['username'],
            'ok' => $code === 0,
            'result' => implode("\n", $lines),
        ];
    }
}

$allCandidatesApplied = $apply
    && count($candidates) <= $limit
    && count($applied) === count($candidates)
    && array_reduce(
        $applied,
        static fn (bool $ok, array $row): bool => $ok && (bool) ($row['ok'] ?? false),
        true
    );
$healthy = $duplicateReplyCount === 0
    && $liveConflictCount === 0
    && ($candidates === [] || $allCandidatesApplied);

echo json_encode([
    'ok' => $healthy,
    'mode' => $apply ? 'apply' : 'audit',
    'window_hours' => $hours,
    'minimum_nas_errors' => $minimumErrors,
    'duplicate_radreply_ips' => $duplicateReplyCount,
    'live_cross_username_conflicts' => $liveConflictCount,
    'repeated_nas_error_users' => count($errorRows),
    'strong_conflict_candidates' => count($candidates),
    'candidates' => $candidates,
    'applied' => $applied,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($healthy ? 0 : 3);
