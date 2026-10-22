<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$options = getopt('', [
    'apply',
    'limit::',
    'notice-limit::',
    'queue-notices',
    'channel::',
    'sync-radius',
    'sync-limit::',
    'apply-balances',
    'fast',
    'range::',
    'help',
]);

if (isset($options['help'])) {
    echo implode(PHP_EOL, [
        'Usage:',
        '  php tools/billing-maintenance.php [--apply] [--limit=10000] [--apply-balances] [--sync-radius] [--range=1100000:1100040]',
        '  php tools/billing-maintenance.php --apply --queue-notices --channel=auto',
        '',
        'Default mode is dry-run: no account, payment or RADIUS table is changed.',
        '--apply       Run countdown refresh, expired suspension and optional RADIUS sync.',
        '--limit       Max customer accounts to process for countdown refresh. Default: 10000.',
        '--queue-notices Queue billing notice messages for expiring/expired services. Requires --apply.',
        '--notice-limit Max accounts to scan for notice queues. Default: 10000.',
        '--channel     Message channel: auto|whatsapp|app|telegram|sms. Default: auto.',
        '--apply-balances Debit sufficient prepaid balance, activate service and sync RADIUS.',
        '--sync-radius Sync RADIUS SQL rows after maintenance.',
        '--sync-limit  Max customer accounts to sync to RADIUS in this run. Default: unlimited.',
        '--range       Optional RADIUS sync range start:end.',
        '',
    ]) . PHP_EOL;
    exit(0);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL/PDO connection is not ready.\n");
    exit(2);
}

$allowedChannels = ['auto', 'whatsapp', 'app', 'telegram', 'sms'];

function billing_maintenance_fetch_notice_accounts(PDO $db, string $kind, int $limit): array
{
    $limit = max(1, min(100000, $limit));
    if ($kind === 'expiring_1d') {
        $sql = "SELECT a.*
                FROM portal_accounts a
                INNER JOIN (
                    SELECT account_id, MIN(expires_at) AS next_expires_at
                    FROM billing_radius_services
                    WHERE status = 'active'
                      AND expires_at IS NOT NULL
                      AND expires_at >= NOW()
                      AND expires_at <= DATE_ADD(NOW(), INTERVAL 1 DAY)
                    GROUP BY account_id
                ) s ON s.account_id = a.id
                WHERE a.role = 'customer'
                  AND a.is_active = 1
                ORDER BY s.next_expires_at ASC
                LIMIT :limit";
    } elseif ($kind === 'expired') {
        $sql = "SELECT a.*
                FROM portal_accounts a
                INNER JOIN (
                    SELECT account_id, MIN(expires_at) AS next_expires_at
                    FROM billing_radius_services
                    WHERE auto_suspend = 1
                      AND status = 'active'
                      AND expires_at IS NOT NULL
                      AND expires_at < NOW()
                    GROUP BY account_id
                ) s ON s.account_id = a.id
                WHERE a.role = 'customer'
                ORDER BY s.next_expires_at ASC
                LIMIT :limit";
    } else {
        return [];
    }

    $statement = $db->prepare($sql);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], 'is_array'));
}

function billing_maintenance_queue_notice_set(array $accounts, string $templateCode, string $channel): array
{
    $result = [
        'matched' => count($accounts),
        'queued' => 0,
        'duplicate_today' => 0,
        'failed' => 0,
    ];
    $actor = ['id' => 0, 'full_name' => 'Billing maintenance'];

    foreach ($accounts as $account) {
        $status = app_message_queue_unique_for_account($account, $templateCode, $channel, '', $actor, false);
        if (!array_key_exists($status, $result)) {
            $status = 'failed';
        }
        $result[$status]++;
    }

    return $result;
}

function billing_maintenance_notice_metric(?array $notices, string $metric): int
{
    if (!is_array($notices)) {
        return 0;
    }

    $total = 0;
    foreach ($notices as $row) {
        if (is_array($row)) {
            $total += (int) ($row[$metric] ?? 0);
        }
    }

    return $total;
}

function billing_maintenance_log_run(
    PDO $db,
    array $summary,
    string $startedAt,
    float $startedFloat,
    bool $apply,
    bool $syncRadius,
    bool $queueNotices,
    string $channel,
    int $limit,
    int $noticeLimit
): bool {
    $durationMs = max(0, (int) round((microtime(true) - $startedFloat) * 1000));
    $candidates = is_array($summary['candidates'] ?? null) ? $summary['candidates'] : [];
    $countdown = is_array($summary['countdown'] ?? null) ? $summary['countdown'] : [];
    $suspension = is_array($summary['suspension'] ?? null) ? $summary['suspension'] : [];
    $notices = is_array($summary['notices'] ?? null) ? $summary['notices'] : [];
    $radiusSync = is_array($summary['radius_sync'] ?? null) ? $summary['radius_sync'] : [];

    $noticeFailed = billing_maintenance_notice_metric($notices, 'failed');
    $failedCount = (int) ($countdown['failed'] ?? 0)
        + (int) ($suspension['failed'] ?? 0)
        + (int) ($radiusSync['failed'] ?? 0)
        + $noticeFailed;
    $status = $failedCount > 0 ? 'failed' : 'completed';
    $errorSummary = $failedCount > 0 ? 'Partial failures: ' . $failedCount : null;

    try {
        $statement = $db->prepare(
            'INSERT INTO billing_maintenance_runs (
                mode, status, apply_changes, sync_radius, queue_notices, channel,
                limit_count, notice_limit_count, countdown_candidates_count, expired_candidates_count,
                notice_expiring_count, notice_expired_count, countdown_checked_count,
                countdown_updated_count, countdown_expired_count, suspended_count,
                radius_synced_count, notices_queued_count, notices_duplicate_count,
                notices_failed_count, failed_count, duration_ms, summary_json,
                error_summary, started_at, finished_at
             ) VALUES (
                :mode, :status, :apply_changes, :sync_radius, :queue_notices, :channel,
                :limit_count, :notice_limit_count, :countdown_candidates_count, :expired_candidates_count,
                :notice_expiring_count, :notice_expired_count, :countdown_checked_count,
                :countdown_updated_count, :countdown_expired_count, :suspended_count,
                :radius_synced_count, :notices_queued_count, :notices_duplicate_count,
                :notices_failed_count, :failed_count, :duration_ms, :summary_json,
                :error_summary, :started_at, NOW()
             )'
        );
        $statement->execute([
            'mode' => (string) ($summary['mode'] ?? ($apply ? 'apply' : 'dry-run')),
            'status' => $status,
            'apply_changes' => $apply ? 1 : 0,
            'sync_radius' => $syncRadius ? 1 : 0,
            'queue_notices' => $queueNotices ? 1 : 0,
            'channel' => $channel,
            'limit_count' => $limit,
            'notice_limit_count' => $noticeLimit,
            'countdown_candidates_count' => (int) ($candidates['countdown_accounts'] ?? 0),
            'expired_candidates_count' => (int) ($candidates['expired_active_services'] ?? 0),
            'notice_expiring_count' => (int) ($candidates['notice_expiring_1d'] ?? 0),
            'notice_expired_count' => (int) ($candidates['notice_expired'] ?? 0),
            'countdown_checked_count' => (int) ($countdown['checked'] ?? 0),
            'countdown_updated_count' => (int) ($countdown['updated'] ?? 0),
            'countdown_expired_count' => (int) ($countdown['expired'] ?? 0),
            'suspended_count' => (int) ($suspension['suspended'] ?? 0),
            'radius_synced_count' => (int) ($radiusSync['synced'] ?? 0),
            'notices_queued_count' => billing_maintenance_notice_metric($notices, 'queued'),
            'notices_duplicate_count' => billing_maintenance_notice_metric($notices, 'duplicate_today'),
            'notices_failed_count' => $noticeFailed,
            'failed_count' => $failedCount,
            'duration_ms' => $durationMs,
            'summary_json' => app_db_json_encode($summary),
            'error_summary' => $errorSummary,
            'started_at' => $startedAt,
        ]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

$apply = isset($options['apply']);
$limit = max(1, min(100000, (int) ($options['limit'] ?? 10000)));
$noticeLimit = max(1, min(100000, (int) ($options['notice-limit'] ?? 10000)));
$queueNotices = isset($options['queue-notices']);
$channel = strtolower(trim((string) ($options['channel'] ?? 'auto')));
if (!in_array($channel, $allowedChannels, true)) {
    fwrite(STDERR, "Invalid --channel. Allowed: " . implode(',', $allowedChannels) . "\n");
    exit(3);
}
$syncRadius = isset($options['sync-radius']);
$applyBalances = isset($options['apply-balances']);
$fast = isset($options['fast']);
$syncLimit = max(0, min(5000, (int) ($options['sync-limit'] ?? 0)));
$startedAt = app_db_now();
$startedFloat = microtime(true);
$range = trim((string) ($options['range'] ?? ''));
$startCode = null;
$endCode = null;
if ($range !== '' && str_contains($range, ':')) {
    [$startCode, $endCode] = array_map('trim', explode(':', $range, 2));
    $startCode = $startCode !== '' ? $startCode : null;
    $endCode = $endCode !== '' ? $endCode : null;
}

$countdownCandidates = (int) $db->query(
    "SELECT COUNT(DISTINCT a.id)
     FROM portal_accounts a
     LEFT JOIN billing_radius_services s ON s.account_id = a.id
     WHERE a.role = 'customer'
       AND (
           JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.expires_at')) IS NOT NULL
           OR s.expires_at IS NOT NULL
       )"
)->fetchColumn();

$expiredServiceCandidates = (int) $db->query(
    "SELECT COUNT(DISTINCT a.id)
     FROM billing_radius_services s
     INNER JOIN portal_accounts a ON a.id = s.account_id
     WHERE s.auto_suspend = 1
       AND s.expires_at IS NOT NULL
       AND s.expires_at < NOW()
       AND s.status = 'active'"
)->fetchColumn();

$before = app_billing_overview();
$expiringNoticeAccounts = billing_maintenance_fetch_notice_accounts($db, 'expiring_1d', $noticeLimit);
$expiredNoticeAccounts = billing_maintenance_fetch_notice_accounts($db, 'expired', $noticeLimit);
$result = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'limit' => $limit,
    'notice_limit' => $noticeLimit,
    'queue_notices' => $queueNotices,
    'channel' => $channel,
    'before' => $before,
    'candidates' => [
        'countdown_accounts' => $countdownCandidates,
        'expired_active_services' => $expiredServiceCandidates,
        'notice_expiring_1d' => count($expiringNoticeAccounts),
        'notice_expired' => count($expiredNoticeAccounts),
    ],
    'countdown' => null,
    'suspension' => null,
    'notices' => null,
    'radius_sync' => null,
    'balance_auto_charge' => null,
    'after' => null,
    'time' => date(DATE_ATOM),
];

if ($apply) {
    if ($applyBalances) {
        $result['balance_auto_charge'] = app_billing_apply_available_balances($limit);
    }
    if ($queueNotices) {
        $result['notices'] = [
            'expiring_1d' => billing_maintenance_queue_notice_set($expiringNoticeAccounts, 'service_expiring_1d', $channel),
            'expired' => billing_maintenance_queue_notice_set($expiredNoticeAccounts, 'service_expired', $channel),
        ];
    }

    // Fast mode is used by the frequent timer: payment credit and messages
    // are handled without rewriting every customer record. Full countdown/
    // suspension work remains available for the scheduled daily audit.
    if (!$fast) {
        $result['countdown'] = app_billing_refresh_countdowns($limit, true);
        $result['suspension'] = app_billing_process_due_suspensions();
    } else {
        $result['countdown'] = ['checked' => 0, 'updated' => 0, 'expired' => 0, 'failed' => 0];
        $result['suspension'] = ['suspended' => 0, 'failed' => 0];
    }

    if ($syncRadius) {
        $result['radius_sync'] = app_billing_sync_radius_accounts($startCode, $endCode, $syncLimit);
    }

    $result['after'] = app_billing_overview();
}

if ($apply) {
    $result['run_logged'] = billing_maintenance_log_run(
        $db,
        $result,
        $startedAt,
        $startedFloat,
        $apply,
        $syncRadius,
        $queueNotices,
        $channel,
        $limit,
        $noticeLimit
    );
} else {
    $result['run_logged'] = false;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
