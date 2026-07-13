<?php
declare(strict_types=1);

require dirname(__DIR__) . '/communication-provider.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

function whatsapp_guard_smoke_out(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

$db = app_db();
if (!$db instanceof PDO) {
    whatsapp_guard_smoke_out(['ok' => false, 'reason' => 'db_unavailable'], 2);
}

try {
    app_communication_whatsapp_guard_schema($db);
    $destination = '99450123' . random_int(1000, 9999);
    $hash = hash('sha256', app_communication_whatsapp_normalize_destination($destination));
    $limits = app_communication_whatsapp_guard_limits();
    $globalOriginal = $db->query('SELECT * FROM whatsapp_delivery_guard_global WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    $delete = $db->prepare('DELETE FROM whatsapp_delivery_guard WHERE destination_hash = :destination_hash');
    $delete->execute(['destination_hash' => $hash]);
    $db->exec(
        'REPLACE INTO whatsapp_delivery_guard_global (
            id, sent_24h, sent_hour, last_day_window_at, last_hour_window_at, last_sent_at, blocked_until, note
         ) VALUES (
            1, 0, 0, NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 1 DAY), NULL, "smoke baseline"
         )'
    );

    $initial = app_communication_whatsapp_guard_check($destination);

    $seedDaily = $db->prepare(
        'INSERT INTO whatsapp_delivery_guard (
            destination_hash, known_contact, sent_24h, sent_total, first_seen_at, last_window_at, last_sent_at
         ) VALUES (
            :destination_hash, 0, :sent_24h, 0, NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 1 DAY)
         )
         ON DUPLICATE KEY UPDATE
            known_contact = 0,
            sent_24h = VALUES(sent_24h),
            sent_total = 0,
            last_window_at = NOW(),
            last_sent_at = DATE_SUB(NOW(), INTERVAL 1 DAY),
            blocked_until = NULL'
    );
    $seedDaily->execute([
        'destination_hash' => $hash,
        'sent_24h' => (int) $limits['unknown_daily_limit'],
    ]);
    $dailyLimited = app_communication_whatsapp_guard_check($destination);

    $clearBlock = $db->prepare(
        'UPDATE whatsapp_delivery_guard
         SET sent_24h = 0, blocked_until = NULL, last_window_at = NOW(), last_sent_at = NOW()
         WHERE destination_hash = :destination_hash'
    );
    $clearBlock->execute(['destination_hash' => $hash]);
    $gapLimited = app_communication_whatsapp_guard_check($destination);

    $clearBlock->execute(['destination_hash' => $hash]);
    $db->prepare(
        'UPDATE whatsapp_delivery_guard
         SET last_sent_at = DATE_SUB(NOW(), INTERVAL 1 DAY)
         WHERE destination_hash = :destination_hash'
    )->execute(['destination_hash' => $hash]);
    $seedGlobalHourly = $db->prepare(
        'REPLACE INTO whatsapp_delivery_guard_global (
            id, sent_24h, sent_hour, last_day_window_at, last_hour_window_at, last_sent_at, blocked_until, note
         ) VALUES (
            1, 0, :sent_hour, NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 1 DAY), NULL, "smoke hourly"
         )'
    );
    $seedGlobalHourly->execute(['sent_hour' => (int) $limits['global_hourly_limit']]);
    $globalHourlyLimited = app_communication_whatsapp_guard_check($destination);

    $clearBlock->execute(['destination_hash' => $hash]);
    $db->prepare(
        'UPDATE whatsapp_delivery_guard
         SET last_sent_at = DATE_SUB(NOW(), INTERVAL 1 DAY)
         WHERE destination_hash = :destination_hash'
    )->execute(['destination_hash' => $hash]);
    $db->exec(
        'REPLACE INTO whatsapp_delivery_guard_global (
            id, sent_24h, sent_hour, last_day_window_at, last_hour_window_at, last_sent_at, blocked_until, note
         ) VALUES (
            1, 0, 0, NOW(), NOW(), NOW(), NULL, "smoke gap"
         )'
    );
    $globalGapLimited = app_communication_whatsapp_guard_check($destination);

    $delete->execute(['destination_hash' => $hash]);
    if (is_array($globalOriginal)) {
        $restore = $db->prepare(
            'REPLACE INTO whatsapp_delivery_guard_global (
                id, sent_24h, sent_hour, last_day_window_at, last_hour_window_at, last_sent_at, blocked_until, note
             ) VALUES (
                1, :sent_24h, :sent_hour, :last_day_window_at, :last_hour_window_at, :last_sent_at, :blocked_until, :note
             )'
        );
        $restore->execute([
            'sent_24h' => (int) ($globalOriginal['sent_24h'] ?? 0),
            'sent_hour' => (int) ($globalOriginal['sent_hour'] ?? 0),
            'last_day_window_at' => $globalOriginal['last_day_window_at'] ?? null,
            'last_hour_window_at' => $globalOriginal['last_hour_window_at'] ?? null,
            'last_sent_at' => $globalOriginal['last_sent_at'] ?? null,
            'blocked_until' => $globalOriginal['blocked_until'] ?? null,
            'note' => $globalOriginal['note'] ?? null,
        ]);
    } else {
        $db->exec('DELETE FROM whatsapp_delivery_guard_global WHERE id = 1');
    }

    $ok = !empty($initial['ok'])
        && (string) ($dailyLimited['reason'] ?? '') === 'whatsapp_rate_limited_daily'
        && (string) ($gapLimited['reason'] ?? '') === 'whatsapp_rate_limited_gap'
        && (string) ($globalHourlyLimited['reason'] ?? '') === 'whatsapp_rate_limited_global_hourly'
        && (string) ($globalGapLimited['reason'] ?? '') === 'whatsapp_rate_limited_global_gap';

    whatsapp_guard_smoke_out([
        'ok' => $ok,
        'destination_masked' => substr($destination, 0, 5) . '****' . substr($destination, -2),
        'limits' => $limits,
        'initial' => $initial,
        'daily_limited' => $dailyLimited,
        'gap_limited' => $gapLimited,
        'global_hourly_limited' => $globalHourlyLimited,
        'global_gap_limited' => $globalGapLimited,
        'sms_fallback_expected_on_reasons' => [
            'whatsapp_rate_limited_daily',
            'whatsapp_rate_limited_gap',
            'whatsapp_rate_limited_blocked_until',
            'whatsapp_rate_limited_global_daily',
            'whatsapp_rate_limited_global_hourly',
            'whatsapp_rate_limited_global_gap',
            'whatsapp_rate_limited_global_blocked_until',
        ],
    ], $ok ? 0 : 3);
} catch (Throwable $exception) {
    whatsapp_guard_smoke_out(['ok' => false, 'reason' => $exception->getMessage()], 4);
}
