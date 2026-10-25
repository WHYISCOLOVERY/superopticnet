<?php
declare(strict_types=1);

function app_mobile_distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): int
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return (int) round(6371000.0 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a))));
}

function app_mobile_seconds_excluding_lunch(
    DateTimeImmutable $start,
    DateTimeImmutable $end,
    string $lunchStart,
    string $lunchEnd
): int {
    $seconds = max(0, $end->getTimestamp() - $start->getTimestamp());
    $cursor = $start->setTime(0, 0);
    $lastDay = $end->setTime(0, 0);
    while ($cursor <= $lastDay) {
        $from = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $lunchStart, $start->getTimezone());
        $to = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $lunchEnd, $start->getTimezone());
        $overlapStart = max($start->getTimestamp(), $from->getTimestamp());
        $overlapEnd = min($end->getTimestamp(), $to->getTimestamp());
        if ($overlapEnd > $overlapStart) $seconds -= $overlapEnd - $overlapStart;
        $cursor = $cursor->modify('+1 day');
    }
    return max(0, $seconds);
}

function app_mobile_process_home_dwell(PDO $db, array $user, float $lat, float $lng, string $recordedAt): ?array
{
    $accountId = (int) ($user['id'] ?? 0);
    $configStatement = $db->prepare(
        'SELECT * FROM mobile_employee_home_geofences WHERE account_id=? AND is_active=1 LIMIT 1'
    );
    $configStatement->execute([$accountId]);
    $config = $configStatement->fetch(PDO::FETCH_ASSOC);
    if (!$config) return null;

    $timezone = new DateTimeZone('Asia/Baku');
    $now = (new DateTimeImmutable($recordedAt, $timezone))->setTimezone($timezone);
    $clock = $now->format('H:i:s');
    $workStart = (string) $config['work_start'];
    $workEnd = (string) $config['work_end'];
    $insideSchedule = $workStart < $workEnd
        ? ($clock >= $workStart && $clock <= $workEnd)
        : ($clock >= $workStart || $clock <= $workEnd);
    $distance = app_mobile_distance_meters(
        $lat,
        $lng,
        (float) $config['center_latitude'],
        (float) $config['center_longitude']
    );
    $insideHome = $distance <= (int) $config['radius_meters'];
    $activeStatement = $db->prepare(
        'SELECT * FROM mobile_home_dwell_events WHERE account_id=? AND exited_at IS NULL ORDER BY id DESC LIMIT 1'
    );
    $activeStatement->execute([$accountId]);
    $active = $activeStatement->fetch(PDO::FETCH_ASSOC);

    $isNightOperator = strtolower(trim((string) ($user['employee_role'] ?? ''))) === 'night_operator';
    $violation = $isNightOperator ? !$insideHome : $insideHome;
    if (!$violation || !$insideSchedule) {
        if ($active) {
            $db->prepare(
                'UPDATE mobile_home_dwell_events
                    SET exited_at=?,last_seen_at=?,status="closed"
                  WHERE id=?'
            )->execute([$now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), (int) $active['id']]);
            if (!empty($active['alert_started_at'])) {
                $db->prepare('UPDATE mobile_manager_alerts SET resolved_at=? WHERE alert_key=?')
                    ->execute([$now->format('Y-m-d H:i:s'), 'home_dwell:' . (int) $active['id']]);
            }
        }
        return null;
    }

    if (!$active) {
        $session = $db->prepare('SELECT id FROM mobile_duty_sessions WHERE account_id=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
        $session->execute([$accountId]);
        $db->prepare(
            'INSERT INTO mobile_home_dwell_events
                (account_id,geofence_id,duty_session_id,entered_at,last_seen_at)
             VALUES (?,?,?,?,?)'
        )->execute([
            $accountId,
            (int) $config['id'],
            (int) $session->fetchColumn() ?: null,
            $now->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
        ]);
        return ['event' => 'home_observing', 'minutes' => 0];
    }

    $entered = new DateTimeImmutable((string) $active['entered_at'], $timezone);
    $unauthorizedSeconds = app_mobile_seconds_excluding_lunch(
        $entered,
        $now,
        (string) $config['lunch_start'],
        (string) $config['lunch_end']
    );
    $db->prepare('UPDATE mobile_home_dwell_events SET last_seen_at=?,unauthorized_seconds=? WHERE id=?')
        ->execute([$now->format('Y-m-d H:i:s'), $unauthorizedSeconds, (int) $active['id']]);

    $thresholdSeconds = (int) $config['dwell_threshold_minutes'] * 60;
    $inLunch = $clock >= (string) $config['lunch_start'] && $clock <= (string) $config['lunch_end'];
    if ($unauthorizedSeconds < $thresholdSeconds || $inLunch || !empty($active['alert_started_at'])) return null;

    $employeeName = trim((string) ($user['full_name'] ?? $user['login'] ?? 'Əməkdaş'));
    $db->prepare('UPDATE mobile_home_dwell_events SET alert_started_at=?,status="alerted" WHERE id=?')
        ->execute([$now->format('Y-m-d H:i:s'), (int) $active['id']]);
    $title = $isNightOperator
        ? $employeeName . ' ev iş məntəqəsindən ' . (int) $config['dwell_threshold_minutes'] . ' dəqiqədən çox uzaqdır'
        : $employeeName . ' ev geofence-ində ' . (int) $config['dwell_threshold_minutes'] . ' dəqiqədən çox qalıb';
    $message = $isNightOperator
        ? 'Gecə növbəsi vaxtında əməkdaş təyin olunmuş ev iş məntəqəsindən uzunmüddətli uzaqlaşıb.'
        : 'İş vaxtı və nahar fasiləsi xaricində ev geofence-ində uzunmüddətli qalma qeydə alındı.';
    $db->prepare(
        'INSERT IGNORE INTO mobile_manager_alerts
            (alert_key,account_id,alert_type,severity,title,message,event_at,metadata_json)
         VALUES (?,? ,"home_dwell","high",?,?,?,?)'
    )->execute([
        'home_dwell:' . (int) $active['id'],
        $accountId,
        $title,
        $message,
        $now->format('Y-m-d H:i:s'),
        json_encode(['dwell_event_id' => (int) $active['id'], 'minutes' => intdiv($unauthorizedSeconds, 60)], JSON_UNESCAPED_UNICODE),
    ]);
    return ['event' => 'home_dwell_alert', 'minutes' => intdiv($unauthorizedSeconds, 60)];
}

function app_mobile_ticket_priority(array $ticket, int $repeatCount, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Baku'));
    $opened = new DateTimeImmutable((string) ($ticket['opened_at'] ?? 'now'), $now->getTimezone());
    $ageHours = max(0, ($now->getTimestamp() - $opened->getTimestamp()) / 3600);
    $score = min(300, (int) round($ageHours * 4));
    $reasons = [];
    if ($ageHours >= 24) $reasons[] = '24 saatdan köhnədir';
    if ($ageHours >= 72) $reasons[] = '3 gündən çox gecikib';

    $status = strtolower((string) ($ticket['status'] ?? ''));
    if (in_array($status, ['in_progress', 'icrada'], true)) {
        $score += 120;
        $reasons[] = 'icradadır, yarımçıq saxlanmamalıdır';
    }
    $metadata = json_decode((string) ($ticket['metadata_json'] ?? ''), true);
    $metadata = is_array($metadata) ? $metadata : [];
    $text = mb_strtolower(implode(' ', [(string) ($ticket['subject'] ?? ''), (string) ($ticket['message'] ?? '')]), 'UTF-8');
    $impact = max(1, (int) ($metadata['affected_customers'] ?? $metadata['impact_count'] ?? 1));
    if ($impact >= 10 || preg_match('/\b(olt|pon|core|uplink|kütləvi|kutlevi)\b/u', $text)) {
        $score += min(500, 180 + $impact * 4);
        $reasons[] = $impact > 1 ? $impact . ' müştəriyə təsir edir' : 'şəbəkə səviyyəli problemdir';
    }
    if ($repeatCount > 1) {
        $score += min(180, ($repeatCount - 1) * 45);
        $reasons[] = 'təkrar müraciətdir';
    }
    if (preg_match('/vip|business|korporativ/u', mb_strtolower((string) ($ticket['customer_plan'] ?? ''), 'UTF-8'))) {
        $score += 80;
        $reasons[] = 'yüksək xidmət səviyyəli müştəridir';
    }
    if (preg_match('/qosul|qoşul|installation|yeni müştəri|yeni musteri/u', $text)) {
        $score += 55;
        $reasons[] = 'yeni qoşulmadır';
    }
    foreach (['sla_due_at', 'promised_at', 'due_at'] as $dateKey) {
        if (empty($metadata[$dateKey])) continue;
        try {
            $due = new DateTimeImmutable((string) $metadata[$dateKey], $now->getTimezone());
            $remaining = $due->getTimestamp() - $now->getTimestamp();
            if ($remaining <= 0) {
                $score += 350;
                $reasons[] = 'vəd/SLA vaxtı keçib';
            } elseif ($remaining <= 7200) {
                $score += 160;
                $reasons[] = 'vəd/SLA vaxtına 2 saatdan az qalıb';
            }
        } catch (Throwable) {
        }
        break;
    }
    $label = $score >= 500 ? 'kritik' : ($score >= 280 ? 'yüksək' : ($score >= 140 ? 'orta' : 'normal'));
    if ($reasons === []) $reasons[] = 'açılma vaxtına görə növbələnib';
    return ['score' => $score, 'label' => $label, 'reasons' => array_slice($reasons, 0, 3)];
}
