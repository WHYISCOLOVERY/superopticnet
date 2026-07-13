<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function app_intelligence_clamp(float|int $value, int $min = 0, int $max = 100): int
{
    return max($min, min($max, (int) round((float) $value)));
}

function app_intelligence_parse_money(mixed $value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (is_numeric($value)) {
        return max(0.0, (float) $value);
    }

    $text = str_replace(',', '.', (string) $value);
    if (preg_match('/-?\d+(?:\.\d+)?/', $text, $match) !== 1) {
        return 0.0;
    }

    return max(0.0, (float) $match[0]);
}

function app_intelligence_days_between(string $from, ?string $to = null): int
{
    $fromTimestamp = strtotime($from);
    $toTimestamp = $to !== null ? strtotime($to) : time();
    if ($fromTimestamp === false || $toTimestamp === false) {
        return 0;
    }

    return max(0, (int) floor(($toTimestamp - $fromTimestamp) / 86400));
}

function app_intelligence_month_key(int $timestamp): string
{
    return date('Y-m', $timestamp);
}

function app_intelligence_month_label(int $timestamp): string
{
    static $labels = [
        '01' => 'Yanvar',
        '02' => 'Fevral',
        '03' => 'Mart',
        '04' => 'Aprel',
        '05' => 'May',
        '06' => 'Iyun',
        '07' => 'Iyul',
        '08' => 'Avqust',
        '09' => 'Sentyabr',
        '10' => 'Oktyabr',
        '11' => 'Noyabr',
        '12' => 'Dekabr',
    ];

    return $labels[date('m', $timestamp)] ?? date('M', $timestamp);
}

function app_intelligence_account_payload(array $account): array
{
    if (function_exists('app_db_account_payload')) {
        return app_db_account_payload($account);
    }

    return app_db_json_decode((string) ($account['payload_json'] ?? ''), []);
}

function app_customer_activity_intelligence(PDO $db, array $account): array
{
    $accountId = (int) ($account['id'] ?? 0);
    $payload = app_intelligence_account_payload($account);
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $services = is_array($payload['internet_services'] ?? null) ? array_values(array_filter($payload['internet_services'], 'is_array')) : [];
    $usernames = [
        (string) ($account['subscriber_code'] ?? ''),
        (string) ($account['login'] ?? ''),
        (string) ($billing['pppoe_username'] ?? ''),
    ];
    foreach ($services as $service) {
        $usernames[] = (string) ($service['username'] ?? '');
    }

    $payments = [];
    $sessions = [];
    $tickets = [];
    if ($accountId > 0) {
        $paymentStatement = $db->prepare(
            'SELECT amount, created_at
             FROM billing_payments
             WHERE account_id = :account_id
             ORDER BY created_at ASC
             LIMIT 120'
        );
        $paymentStatement->execute(['account_id' => $accountId]);
        $payments = array_values(array_filter($paymentStatement->fetchAll(PDO::FETCH_ASSOC) ?: [], 'is_array'));

        $ticketStatement = $db->prepare(
            'SELECT status, opened_at, resolved_at, subject, message
             FROM portal_tickets
             WHERE customer_account_id = :account_id OR subscriber_code = :subscriber_code
             ORDER BY opened_at DESC
             LIMIT 80'
        );
        $ticketStatement->execute([
            'account_id' => $accountId,
            'subscriber_code' => (string) ($account['subscriber_code'] ?? ''),
        ]);
        $tickets = array_values(array_filter($ticketStatement->fetchAll(PDO::FETCH_ASSOC) ?: [], 'is_array'));
    }

    $usernames = array_values(array_unique(array_filter(array_map('trim', $usernames))));
    if ($usernames !== []) {
        [$clause, $params] = app_customer_intelligence_in_clause($usernames, 'u');
        $sessionStatement = $db->prepare(
            'SELECT username, acctstarttime, acctstoptime, acctupdatetime, acctinputoctets, acctoutputoctets, acctterminatecause
             FROM radacct
             WHERE username IN (' . $clause . ')
               AND acctstarttime >= DATE_SUB(NOW(), INTERVAL 365 DAY)
             ORDER BY acctstarttime ASC
             LIMIT 5000'
        );
        $sessionStatement->execute($params);
        $sessions = array_values(array_filter($sessionStatement->fetchAll(PDO::FETCH_ASSOC) ?: [], 'is_array'));
    }

    return app_customer_activity_intelligence_from_rows($account, $payments, $sessions, $tickets);
}

function app_customer_intelligence_in_clause(array $values, string $prefix): array
{
    $placeholders = [];
    $params = [];
    foreach (array_values($values) as $index => $value) {
        $key = $prefix . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $value;
    }

    return [implode(',', $placeholders), $params];
}

function app_customer_activity_intelligence_from_rows(array $account, array $payments, array $sessions, array $tickets = []): array
{
    $payload = app_intelligence_account_payload($account);
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
    $monthlyFee = app_intelligence_parse_money($billing['monthly_total'] ?? $billing['monthly_fee'] ?? $billing['internet_monthly_fee'] ?? $account['monthly_fee'] ?? 0);
    $dailyFee = $monthlyFee > 0 ? $monthlyFee / 30.0 : 0.0;
    $expiresAt = trim((string) ($billing['expires_at'] ?? $account['expires_at'] ?? ''));
    $statusText = strtolower((string) ($account['status'] ?? $billing['status'] ?? $profile['status'] ?? ''));
    $isInactiveStatus = (int) ($account['is_active'] ?? 1) !== 1
        || str_contains($statusText, 'deaktiv')
        || str_contains($statusText, 'passiv')
        || str_contains($statusText, 'suspend');
    $expired = $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time();
    $currentInactiveDays = ($isInactiveStatus || $expired)
        ? app_intelligence_days_between($expired ? $expiresAt : (string) ($account['updated_at'] ?? $account['created_at'] ?? date('Y-m-d H:i:s')))
        : 0;

    $activeDaySet = [];
    $monthlyDaySets = [];
    $now = time();
    $yearAgo = strtotime('-365 days', $now) ?: ($now - 31536000);
    $trafficBytes = 0.0;
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $start = strtotime((string) ($session['acctstarttime'] ?? ''));
        if ($start === false || $start < $yearAgo) {
            continue;
        }
        $day = date('Y-m-d', $start);
        $month = app_intelligence_month_key($start);
        $activeDaySet[$day] = true;
        $monthlyDaySets[$month][$day] = true;
        $trafficBytes += (float) ($session['acctinputoctets'] ?? 0) + (float) ($session['acctoutputoctets'] ?? 0);
    }

    $activeDaysYear = count($activeDaySet);
    if ($activeDaysYear === 0 && !$isInactiveStatus && !$expired) {
        $createdAt = strtotime((string) ($account['created_at'] ?? '-365 days'));
        $createdAt = $createdAt === false ? $yearAgo : max($createdAt, $yearAgo);
        $activeDaysYear = max(0, min(365, (int) floor(($now - $createdAt) / 86400)));
    }
    $inactiveDaysYear = max(0, 365 - $activeDaysYear);
    if ($currentInactiveDays > 0) {
        $inactiveDaysYear = max($inactiveDaysYear, min(365, $currentInactiveDays));
    }

    $paymentTimestamps = [];
    foreach ($payments as $payment) {
        if (!is_array($payment)) {
            continue;
        }
        $created = strtotime((string) ($payment['created_at'] ?? ''));
        if ($created !== false) {
            $paymentTimestamps[] = $created;
        }
    }
    sort($paymentTimestamps);
    $lateDays = [];
    for ($i = 1; $i < count($paymentTimestamps); $i++) {
        $intervalDays = (int) floor(($paymentTimestamps[$i] - $paymentTimestamps[$i - 1]) / 86400);
        $lateDays[] = max(0, $intervalDays - 31);
    }
    if ($currentInactiveDays > 0) {
        $lateDays[] = min(120, $currentInactiveDays);
    }
    $averageLate = $lateDays !== [] ? array_sum($lateDays) / count($lateDays) : 0.0;
    $maxLate = $lateDays !== [] ? max($lateDays) : 0;

    $accountAgeDays = app_intelligence_days_between((string) ($account['created_at'] ?? date('Y-m-d H:i:s')));
    $expectedPayments = max(1, min(12, (int) ceil(min(365, max(30, $accountAgeDays)) / 31)));
    $recentPaymentCount = count(array_filter($paymentTimestamps, static fn (int $timestamp): bool => $timestamp >= strtotime('-365 days')));
    $missedPaymentPenalty = max(0, $expectedPayments - $recentPaymentCount) * 4;

    $paymentScore = app_intelligence_clamp(100 - ($averageLate * 2.8) - ($maxLate * 0.7) - ($inactiveDaysYear * 0.08) - $missedPaymentPenalty);
    $activeRate = $activeDaysYear / 365.0;
    $activityScore = app_intelligence_clamp(($activeRate * 68) + ($paymentScore * 0.28) + ($trafficBytes > 0 ? 8 : 0) - min(18, $currentInactiveDays * 0.8));
    $revenueLoss = round($inactiveDaysYear * $dailyFee, 2);

    $openTicketCount = 0;
    foreach ($tickets as $ticket) {
        $status = strtolower((string) ($ticket['status'] ?? ''));
        if ($status === '' || (!str_contains($status, 'baglan') && !str_contains($status, 'closed') && !str_contains($status, 'resolved'))) {
            $openTicketCount++;
        }
    }
    $riskScore = max(0, min(10, (int) ($profile['risk_score'] ?? $billing['risk_score'] ?? $account['risk_score'] ?? 0)));
    $churnPercent = app_intelligence_clamp(
        (100 - $activityScore) * 0.42
        + (100 - $paymentScore) * 0.30
        + min(20, $openTicketCount * 5)
        + min(15, $riskScore * 1.5)
        + min(22, $currentInactiveDays * 1.2)
    );
    $churnLabel = match (true) {
        $churnPercent >= 80 => 'Critical',
        $churnPercent >= 60 => 'High',
        $churnPercent >= 35 => 'Medium',
        default => 'Low',
    };

    $timeline = [];
    for ($offset = 11; $offset >= 0; $offset--) {
        $monthTimestamp = strtotime('first day of -' . $offset . ' months', $now) ?: $now;
        $monthKey = app_intelligence_month_key($monthTimestamp);
        $daysInMonth = (int) date('t', $monthTimestamp);
        $activeDays = isset($monthlyDaySets[$monthKey]) ? count($monthlyDaySets[$monthKey]) : 0;
        if ($activeDays === 0 && $activeDaysYear > 0 && $monthTimestamp >= strtotime('-' . min(365, $accountAgeDays) . ' days', $now)) {
            $activeDays = min($daysInMonth, (int) round($activeDaysYear / 12));
        }
        $timeline[] = [
            'month' => $monthKey,
            'label' => app_intelligence_month_label($monthTimestamp),
            'active_days' => max(0, min($daysInMonth, $activeDays)),
            'inactive_days' => max(0, $daysInMonth - max(0, min($daysInMonth, $activeDays))),
            'activity_percent' => app_intelligence_clamp(($activeDays / max(1, $daysInMonth)) * 100),
        ];
    }

    $recommendation = match (true) {
        $churnPercent >= 80 => 'Retention kampaniyasi ve operator callback yaradilsin.',
        $paymentScore < 65 => 'Avtomatik odenis ve gecikme xatirlatma kampaniyasi gonderilsin.',
        $activityScore < 55 => 'Aktivliyi artirmaq ucun uygun paket/kampaniya teklif edilsin.',
        default => 'Normal monitorinq; uygun upsell kampaniyasi ucun davranis izlenilsin.',
    };

    $summary = sprintf(
        'Musteri son 12 ayda teqriben %d gun aktiv, %d gun passiv olub. Orta gecikme %.1f gun, maksimum gecikme %d gundur. Potensial gelir itkisi %.2f AZN. Churn riski %d%% (%s). Tovsiye: %s',
        $activeDaysYear,
        $inactiveDaysYear,
        $averageLate,
        $maxLate,
        $revenueLoss,
        $churnPercent,
        $churnLabel,
        $recommendation
    );

    return [
        'activity_score' => $activityScore,
        'payment_score' => $paymentScore,
        'active_days_year' => $activeDaysYear,
        'inactive_days_year' => $inactiveDaysYear,
        'current_inactive_days' => $currentInactiveDays,
        'average_late_days' => round($averageLate, 1),
        'max_late_days' => (int) $maxLate,
        'revenue_loss' => $revenueLoss,
        'monthly_fee' => round($monthlyFee, 2),
        'churn_risk_percent' => $churnPercent,
        'churn_risk' => $churnLabel,
        'recommendation' => $recommendation,
        'ai_summary' => $summary,
        'monthly_timeline' => $timeline,
    ];
}

function app_customer_intelligence_for_list(PDO $db, array $customers, bool $includeSessionSummary = false): array
{
    $customers = array_values(array_filter($customers, 'is_array'));
    $accountIds = [];
    $usernameToAccount = [];
    foreach ($customers as $customer) {
        $id = (int) ($customer['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $accountIds[] = $id;
        foreach ([
            (string) ($customer['username'] ?? ''),
            (string) ($customer['subscriber_code'] ?? ''),
            (string) ($customer['login'] ?? ''),
        ] as $username) {
            $username = trim($username);
            if ($username !== '') {
                $usernameToAccount[strtolower($username)] = $id;
            }
        }
    }

    if ($accountIds === []) {
        return [];
    }

    $paymentsByAccount = [];
    [$accountClause, $accountParams] = app_customer_intelligence_in_clause(array_map('strval', $accountIds), 'a');
    try {
        $paymentStatement = $db->prepare(
            'SELECT account_id, amount, created_at
             FROM billing_payments
             WHERE account_id IN (' . $accountClause . ')
               AND created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
             ORDER BY account_id, created_at ASC'
        );
        $paymentStatement->execute($accountParams);
        foreach ($paymentStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $paymentsByAccount[(int) ($row['account_id'] ?? 0)][] = $row;
        }
    } catch (Throwable) {
        $paymentsByAccount = [];
    }

    $sessionsByAccount = [];
    $usernames = array_keys($usernameToAccount);
    if ($includeSessionSummary && $usernames !== []) {
        [$usernameClause, $usernameParams] = app_customer_intelligence_in_clause($usernames, 'u');
        try {
            $sessionStatement = $db->prepare(
                'SELECT username,
                        COUNT(DISTINCT DATE(acctstarttime)) AS active_days,
                        COALESCE(SUM(acctinputoctets),0) AS input_bytes,
                        COALESCE(SUM(acctoutputoctets),0) AS output_bytes
                 FROM radacct
                 WHERE username IN (' . $usernameClause . ')
                   AND acctstarttime >= DATE_SUB(NOW(), INTERVAL 365 DAY)
                 GROUP BY username'
            );
            $sessionStatement->execute($usernameParams);
            foreach ($sessionStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $accountId = $usernameToAccount[strtolower(trim((string) ($row['username'] ?? '')))] ?? 0;
                if ($accountId > 0) {
                    $activeDays = max(0, min(365, (int) ($row['active_days'] ?? 0)));
                    $inputBytes = (float) ($row['input_bytes'] ?? 0);
                    $outputBytes = (float) ($row['output_bytes'] ?? 0);
                    for ($day = 0; $day < $activeDays; $day++) {
                        $sessionsByAccount[$accountId][] = [
                            'username' => (string) ($row['username'] ?? ''),
                            'acctstarttime' => date('Y-m-d 10:00:00', strtotime('-' . $day . ' days')),
                            'acctstoptime' => date('Y-m-d 12:00:00', strtotime('-' . $day . ' days')),
                            'acctinputoctets' => $activeDays > 0 ? $inputBytes / $activeDays : 0,
                            'acctoutputoctets' => $activeDays > 0 ? $outputBytes / $activeDays : 0,
                        ];
                    }
                }
            }
        } catch (Throwable) {
            $sessionsByAccount = [];
        }
    }

    $ticketsByAccount = [];
    try {
        $ticketStatement = $db->prepare(
            'SELECT customer_account_id, subscriber_code, status, opened_at, resolved_at, subject, message
             FROM portal_tickets
             WHERE customer_account_id IN (' . $accountClause . ')
             ORDER BY opened_at DESC
             LIMIT 1000'
        );
        $ticketStatement->execute($accountParams);
        foreach ($ticketStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ticketsByAccount[(int) ($row['customer_account_id'] ?? 0)][] = $row;
        }
    } catch (Throwable) {
        $ticketsByAccount = [];
    }

    $result = [];
    foreach ($customers as $customer) {
        $id = (int) ($customer['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $result[$id] = app_customer_activity_intelligence_from_rows(
            $customer,
            $paymentsByAccount[$id] ?? [],
            $sessionsByAccount[$id] ?? [],
            $ticketsByAccount[$id] ?? []
        );
    }

    return $result;
}

function app_traffic_monetization(PDO $db, float $capacityMbps = 10000.0): array
{
    $rows = [];
    try {
        $statement = $db->query(
            'SELECT acctstarttime, acctstoptime, acctinputoctets, acctoutputoctets, username
             FROM radacct
             WHERE acctstarttime >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY acctstarttime ASC
             LIMIT 20000'
        );
        $rows = array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], 'is_array'));
    } catch (Throwable) {
        $rows = [];
    }

    return app_traffic_monetization_from_rows($rows, $capacityMbps);
}

function app_traffic_monetization_from_rows(array $sessions, float $capacityMbps = 10000.0): array
{
    $capacityMbps = max(1.0, $capacityMbps);
    $totalBytes = 0.0;
    $hourBytes = array_fill(0, 24, 0.0);
    $activeCustomers = [];
    $now = time();
    $oldest = $now;
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $start = strtotime((string) ($session['acctstarttime'] ?? ''));
        if ($start === false) {
            continue;
        }
        $oldest = min($oldest, $start);
        $bytes = max(0.0, (float) ($session['acctinputoctets'] ?? 0) + (float) ($session['acctoutputoctets'] ?? 0));
        $totalBytes += $bytes;
        $hourBytes[(int) date('G', $start)] += $bytes;
        $username = trim((string) ($session['username'] ?? ''));
        if ($username !== '') {
            $activeCustomers[$username] = true;
        }
    }

    $windowSeconds = max(3600, $now - $oldest);
    $averageMbps = ($totalBytes * 8) / $windowSeconds / 1000000;
    $peakMbps = 0.0;
    foreach ($hourBytes as $bytes) {
        $peakMbps = max($peakMbps, ($bytes * 8) / 3600 / 1000000);
    }
    $unusedPercent = app_intelligence_clamp((1 - min(1.0, $averageMbps / $capacityMbps)) * 100);
    $saturationHours = [];
    $lowHours = [];
    foreach ($hourBytes as $hour => $bytes) {
        $mbps = ($bytes * 8) / 3600 / 1000000;
        $util = ($mbps / $capacityMbps) * 100;
        if ($util >= 85) {
            $saturationHours[] = sprintf('%02d:00', $hour);
        }
        if ($util > 0 && $util <= 35) {
            $lowHours[] = sprintf('%02d:00', $hour);
        }
    }
    if ($lowHours === []) {
        $lowHours = ['14:00', '15:00', '16:00'];
    }

    $unusedMbps = max(0.0, $capacityMbps - $averageMbps);
    $potentialCustomers = max(50, min(10000, (int) floor($unusedMbps / 2)));
    $packagePrice = $unusedPercent >= 65 ? 3.0 : 2.0;
    $projectedRevenue = round($potentialCustomers * $packagePrice, 2);
    $confidence = app_intelligence_clamp(55 + min(30, count($sessions) / 200) + min(12, count($activeCustomers) / 250));

    $recommendedPackages = [
        [
            'name' => 'Afternoon Boost',
            'window' => '14:00-17:00',
            'speed' => '300 Mbps',
            'price' => '3 AZN',
            'reason' => 'Gunorta saatlarinda bos capacity monetizasiya ucun uyğundur.',
        ],
        [
            'name' => 'Night Gamer',
            'window' => '01:00-08:00',
            'speed' => '500 Mbps',
            'price' => '5 AZN',
            'reason' => 'Gecə saatlarında pikden kenar resurs gamer segmentine satila biler.',
        ],
    ];
    if ($unusedPercent >= 70) {
        $recommendedPackages[] = [
            'name' => 'Weekend Turbo',
            'window' => 'Şənbə-Bazar',
            'speed' => '300 Mbps',
            'price' => '4 AZN',
            'reason' => 'Hefte sonu bos resursdan paket upsell ucun istifade edile biler.',
        ];
    }

    $executiveInsight = sprintf(
        'Son analiz penceresinde orta istifade %.2f Mbps, pik %.2f Mbps, bos capacity %d%% hesablandi. Bu resurs ucun teqriben %.2f AZN/ay elave gelir potensiali var. Confidence: %d%%.',
        $averageMbps,
        $peakMbps,
        $unusedPercent,
        $projectedRevenue,
        $confidence
    );

    return [
        'capacity_mbps' => round($capacityMbps, 2),
        'average_mbps' => round($averageMbps, 2),
        'peak_mbps' => round($peakMbps, 2),
        'unused_capacity_percent' => $unusedPercent,
        'unused_mbps' => round($unusedMbps, 2),
        'saturation_hours' => $saturationHours,
        'low_utilization_hours' => array_slice($lowHours, 0, 8),
        'recommended_packages' => $recommendedPackages,
        'potential_customers' => $potentialCustomers,
        'projected_monthly_revenue' => $projectedRevenue,
        'confidence' => $confidence,
        'executive_insight' => $executiveInsight,
    ];
}
