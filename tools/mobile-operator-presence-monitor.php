<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once dirname(__DIR__) . '/config.php';

$db = app_db();
$rows = $db->query(
    "SELECT s.*,p.full_name,p.login
       FROM mobile_operator_schedules s
       JOIN portal_accounts p ON p.id=s.account_id
      WHERE s.is_enabled=1 AND p.is_active=1 AND p.employee_role IN ('operator','night_operator','support')"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$created = 0;
$checked = 0;

foreach ($rows as $row) {
    $timezone = new DateTimeZone((string) ($row['timezone'] ?: 'Asia/Baku'));
    $now = new DateTimeImmutable('now', $timezone);
    $today = $now->format('Y-m-d');
    $startToday = new DateTimeImmutable($today . ' ' . $row['shift_start'], $timezone);
    $endToday = new DateTimeImmutable($today . ' ' . $row['shift_end'], $timezone);
    $overnight = $endToday <= $startToday;
    if ($overnight) $endToday = $endToday->modify('+1 day');
    $shiftStart = $startToday;
    $shiftEnd = $endToday;
    if ($overnight && $now < $startToday) {
        $shiftStart = $startToday->modify('-1 day');
        $shiftEnd = new DateTimeImmutable($today . ' ' . $row['shift_end'], $timezone);
    }
    if ($now < $shiftStart || $now >= $shiftEnd) continue;
    $weekdays = array_map('intval', json_decode((string) $row['weekdays_json'], true) ?: []);
    if (!in_array((int) $shiftStart->format('N'), $weekdays, true)) continue;

    $inBreak = false;
    foreach (json_decode((string) ($row['breaks_json'] ?? '[]'), true) ?: [] as $break) {
        if (!is_array($break) || empty($break['start']) || empty($break['end'])) continue;
        $breakStart = new DateTimeImmutable($shiftStart->format('Y-m-d') . ' ' . $break['start'], $timezone);
        $breakEnd = new DateTimeImmutable($shiftStart->format('Y-m-d') . ' ' . $break['end'], $timezone);
        if ($overnight && $breakStart < $shiftStart) $breakStart = $breakStart->modify('+1 day');
        if ($breakEnd <= $breakStart) $breakEnd = $breakEnd->modify('+1 day');
        if ($now >= $breakStart && $now < $breakEnd) { $inBreak = true; break; }
    }
    if ($inBreak) continue;
    $checked++;

    $latest = $db->prepare('SELECT MAX(occurred_at) FROM mobile_operator_activity WHERE account_id=? AND occurred_at>=?');
    $latest->execute([(int) $row['account_id'], $shiftStart->format('Y-m-d H:i:s')]);
    $latestValue = $latest->fetchColumn();
    $anchor = $latestValue ? new DateTimeImmutable((string) $latestValue, $timezone) : $shiftStart;
    $elapsedMinutes = max(0, (int) floor(($now->getTimestamp() - $anchor->getTimestamp()) / 60));
    $interval = max(30, (int) $row['required_interval_minutes']);
    $grace = max(0, (int) $row['grace_minutes']);
    $completedIntervals = intdiv($elapsedMinutes, $interval);
    $lastDue = $anchor->modify('+' . ($completedIntervals * $interval) . ' minutes');
    $nextDue = $anchor->modify('+' . (($completedIntervals + 1) * $interval) . ' minutes');
    $type = null;
    $eventAt = null;
    $severity = 'warning';
    if ($completedIntervals >= 1 && $now >= $lastDue->modify('+' . $grace . ' minutes')) {
        $type = 'operator_presence_missed';
        $eventAt = $lastDue;
        $severity = 'high';
    } elseif ($now >= $nextDue->modify('-10 minutes') && $now < $nextDue) {
        $type = 'operator_presence_reminder';
        $eventAt = $nextDue;
    }
    if ($type === null || !$eventAt) continue;
    $name = trim((string) $row['full_name']);
    $alertKey = $type . ':' . (int) $row['account_id'] . ':' . $eventAt->format('YmdHi');
    $title = $type === 'operator_presence_missed' ? $name . ' saatlıq aktivlik yoxlamasını keçib' : 'Tətbiqə daxil olma vaxtı yaxınlaşır';
    $message = $type === 'operator_presence_missed'
        ? sprintf('%s növbədədir, lakin %d dəqiqədir tətbiqdə aktivlik qeydə alınmayıb. Son aktivlik: %s.', $name, $elapsedMinutes, $anchor->format('d.m.Y H:i'))
        : sprintf('%s, növbə nəzarəti üçün 10 dəqiqə ərzində tətbiqə daxil olun.', $name);
    $stmt = $db->prepare(
        'INSERT IGNORE INTO mobile_manager_alerts
            (alert_key,account_id,alert_type,severity,title,message,event_at,metadata_json)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $alertKey, (int) $row['account_id'], $type, $severity, $title, mb_substr($message, 0, 500),
        $eventAt->format('Y-m-d H:i:s'),
        json_encode(['last_activity_at' => $anchor->format(DATE_ATOM), 'shift_start' => $shiftStart->format(DATE_ATOM), 'shift_end' => $shiftEnd->format(DATE_ATOM)], JSON_UNESCAPED_UNICODE),
    ]);
    $created += $stmt->rowCount();
}

echo "operator_presence_checked={$checked} alerts_created={$created}\n";
