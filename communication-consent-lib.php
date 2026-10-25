<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Marketing/SMS opt-out registry (docs vision §11.1). Deliberately separate
 * from communication_messages/communication_campaign_* -- this only answers
 * "is this phone number allowed to receive this channel" and is checked at
 * the one real chokepoint before a campaign message is queued
 * (app_communication_campaign_queue_account() in communication-campaign-lib.php).
 * Transactional sends (payment receipts, OTP, ticket updates) are NOT routed
 * through this check -- opt-out only applies to marketing/campaign sends,
 * matching the transactional/marketing split the vision doc itself draws.
 */

function app_communication_opt_out(
    PDO $db,
    string $phone,
    string $channel,
    string $source,
    array $actor = [],
    string $reason = ''
): bool {
    $phoneNormalized = normalize_phone($phone);
    $channel = in_array($channel, ['all', 'sms', 'whatsapp'], true) ? $channel : 'all';
    if ($phoneNormalized === '') {
        return false;
    }

    $statement = $db->prepare(
        'INSERT INTO communication_opt_outs (
            phone_normalized, channel, source, reason, created_by_account_id, created_by_name
         ) VALUES (
            :phone_normalized, :channel, :source, :reason, :actor_id, :actor_name
         )
         ON DUPLICATE KEY UPDATE
            source = VALUES(source), reason = VALUES(reason),
            created_by_account_id = VALUES(created_by_account_id), created_by_name = VALUES(created_by_name)'
    );

    return $statement->execute([
        'phone_normalized' => $phoneNormalized,
        'channel' => $channel,
        'source' => clean_text($source) ?: 'unknown',
        'reason' => trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
        'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
        'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? '')) ?: null,
    ]);
}

function app_communication_opt_in(PDO $db, string $phone, string $channel = 'all'): bool
{
    $phoneNormalized = normalize_phone($phone);
    if ($phoneNormalized === '') {
        return false;
    }
    $channel = in_array($channel, ['all', 'sms', 'whatsapp'], true) ? $channel : 'all';

    return $db->prepare('DELETE FROM communication_opt_outs WHERE phone_normalized = :phone AND channel = :channel')
        ->execute(['phone' => $phoneNormalized, 'channel' => $channel]);
}

function app_communication_is_opted_out(PDO $db, string $phone, string $channel = 'sms'): bool
{
    $phoneNormalized = normalize_phone($phone);
    if ($phoneNormalized === '') {
        return false;
    }
    $channel = in_array($channel, ['all', 'sms', 'whatsapp'], true) ? $channel : 'sms';

    $statement = $db->prepare(
        'SELECT COUNT(*) FROM communication_opt_outs WHERE phone_normalized = :phone AND channel IN ("all", :channel)'
    );
    $statement->execute(['phone' => $phoneNormalized, 'channel' => $channel]);

    return (int) $statement->fetchColumn() > 0;
}
