<?php
declare(strict_types=1);

require_once __DIR__ . '/customer-intelligence-lib.php';
require_once __DIR__ . '/communication-consent-lib.php';

function app_communication_campaign_ensure_schema(PDO $db): bool
{
    try {
        $statement = $db->query(
            'SELECT COUNT(*)
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ("communication_campaign_approvals", "communication_campaign_recipients")'
        );
        return (int) ($statement !== false ? $statement->fetchColumn() : 0) === 2;
    } catch (Throwable) {
        return false;
    }
}

function app_communication_sms_encoding(string $text): string
{
    return preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', $text) === 1 ? 'LATIN' : 'UNICODE';
}

function app_communication_sms_parts(string $text, string $encoding = ''): int
{
    $encoding = $encoding !== '' ? strtoupper($encoding) : app_communication_sms_encoding($text);
    $length = mb_strlen($text);
    if ($length <= 0) {
        return 0;
    }
    $single = $encoding === 'UNICODE' ? 70 : 160;
    $joined = $encoding === 'UNICODE' ? 67 : 153;
    return $length <= $single ? 1 : (int) ceil($length / $joined);
}

function app_communication_template_message_type(?array $template): string
{
    $category = strtolower(trim((string) ($template['category'] ?? '')));
    $code = strtolower(trim((string) ($template['code'] ?? '')));
    return match (true) {
        $category === 'holiday' || str_starts_with($code, 'holiday_') => 'holiday',
        str_contains($code, 'payment') => 'payment',
        str_contains($code, 'activat') || str_contains($code, 'reconnect') => 'reconnect',
        str_contains($code, 'suspend') || str_contains($code, 'disconnect') => 'disconnect',
        default => 'general',
    };
}

function app_communication_campaign_prepare_holiday(
    string $scope,
    string $area,
    string $templateCode,
    string $requestedChannel,
    string $customBody,
    array $actor
): array {
    $db = app_db();
    if (!$db instanceof PDO || !app_communication_campaign_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'campaign_schema_unavailable'];
    }

    $template = app_message_template_by_code($templateCode);
    if (app_communication_template_message_type($template) !== 'holiday') {
        return ['ok' => false, 'reason' => 'holiday_template_required'];
    }
    $requestedChannel = strtolower(trim($requestedChannel));
    if ($requestedChannel !== 'sms') {
        return ['ok' => false, 'reason' => 'holiday_sms_channel_required'];
    }

    $accounts = app_message_group_accounts($scope, $area, 500);
    if ($accounts === []) {
        return ['ok' => false, 'reason' => 'no_recipients'];
    }

    $accountIds = [];
    $estimatedParts = 0;
    $encoding = 'LATIN';
    foreach ($accounts as $account) {
        $accountId = (int) ($account['id'] ?? 0);
        if ($accountId <= 0 || trim((string) ($account['phone'] ?? '')) === '') {
            continue;
        }
        $body = trim($customBody) !== '' ? trim($customBody) : (string) ($template['body'] ?? '');
        $rendered = app_message_render_body($body, $account);
        $messageEncoding = app_communication_sms_encoding($rendered);
        if ($messageEncoding === 'UNICODE') {
            $encoding = 'UNICODE';
        }
        $estimatedParts += app_communication_sms_parts($rendered, $messageEncoding);
        $accountIds[] = $accountId;
    }
    $accountIds = array_values(array_unique($accountIds));
    if ($accountIds === []) {
        return ['ok' => false, 'reason' => 'no_valid_phone_recipients'];
    }

    $costPerPart = max(0.0, (float) (getenv('SMS_ESTIMATED_SEGMENT_COST') ?: 0));
    $campaignKey = hash('sha256', implode('|', [
        'holiday', $templateCode, $scope, $area, hash('sha256', trim($customBody)), date('Y-m-d'),
    ]));
    try {
        $statement = $db->prepare(
            'INSERT INTO communication_campaign_approvals (
                campaign_key, message_type, template_code, requested_channel, scope, area, custom_body,
                recipient_account_ids_json, recipient_count, estimated_parts, estimated_cost, encoding,
                provider_purpose, approval_status, created_by_account_id, created_by_name
             ) VALUES (
                :campaign_key, "holiday", :template_code, "sms", :scope, :area, :custom_body,
                :recipient_ids, :recipient_count, :estimated_parts, :estimated_cost, :encoding,
                "ADV", "pending_approval", :created_by_account_id, :created_by_name
             )'
        );
        $statement->execute([
            'campaign_key' => $campaignKey,
            'template_code' => $templateCode,
            'scope' => $scope,
            'area' => $area !== '' ? $area : null,
            'custom_body' => trim($customBody) !== '' ? trim($customBody) : null,
            'recipient_ids' => app_db_json_encode($accountIds),
            'recipient_count' => count($accountIds),
            'estimated_parts' => $estimatedParts,
            'estimated_cost' => round($estimatedParts * $costPerPart, 4),
            'encoding' => $encoding,
            'created_by_account_id' => (int) ($actor['id'] ?? 0),
            'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
        ]);
        return [
            'ok' => true,
            'id' => (int) $db->lastInsertId(),
            'recipient_count' => count($accountIds),
            'estimated_parts' => $estimatedParts,
            'encoding' => $encoding,
            'status' => 'pending_approval',
        ];
    } catch (PDOException $exception) {
        return ['ok' => false, 'reason' => (string) $exception->getCode() === '23000' ? 'duplicate_campaign_today' : 'campaign_insert_failed'];
    }
}

/**
 * Active customers whose last billing payment is older than the given
 * threshold (or who never paid). Read-only selection for the long-inactive
 * marketing campaign proposal; never sends anything by itself.
 */
function app_communication_campaign_long_inactive_candidates(PDO $db, int $inactiveDays, int $limit = 500): array
{
    $inactiveDays = max(15, min(365, $inactiveDays));
    $limit = max(1, min($limit, 500));
    try {
        $statement = $db->prepare(
            'SELECT a.*, lp.last_payment_at
               FROM portal_accounts a
               LEFT JOIN (
                   SELECT account_id, MAX(created_at) AS last_payment_at
                     FROM billing_payments
                    GROUP BY account_id
               ) lp ON lp.account_id = a.id
              WHERE a.role = "customer" AND a.is_active = 1
                AND (lp.last_payment_at IS NULL OR lp.last_payment_at < DATE_SUB(NOW(), INTERVAL :days DAY))
              ORDER BY (lp.last_payment_at IS NULL) DESC, lp.last_payment_at ASC, a.id DESC
              LIMIT ' . $limit
        );
        $statement->execute(['days' => $inactiveDays]);
        return array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], 'is_array'));
    } catch (Throwable) {
        return [];
    }
}

/**
 * Long-inactive-customer campaign proposal (marketing advisor next action).
 * Mirrors `app_communication_campaign_prepare_holiday()`'s propose-then-approve
 * contract exactly: this only ever inserts a `pending_approval` row and
 * per-recipient audit rows. Nothing is queued or sent until a *different*
 * admin actor calls the existing `app_communication_campaign_approve_and_queue()`
 * (which already refuses `creator_cannot_approve`).
 */
function app_communication_campaign_prepare_long_inactive(
    int $inactiveDays,
    string $templateCode,
    string $customBody,
    array $actor
): array {
    $actorId = (int) ($actor['id'] ?? 0);
    if ($actorId <= 0) {
        return ['ok' => false, 'reason' => 'actor_required'];
    }
    if (!function_exists('app_portal_can_access_module') || !app_portal_can_access_module($actor, 'messaging')) {
        return ['ok' => false, 'reason' => 'messaging_permission_denied'];
    }

    $db = app_db();
    if (!$db instanceof PDO || !app_communication_campaign_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'campaign_schema_unavailable'];
    }

    $template = app_message_template_by_code($templateCode);
    if (!is_array($template) || trim((string) ($template['code'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'template_not_found'];
    }

    $inactiveDays = max(15, min(365, $inactiveDays));
    $candidates = app_communication_campaign_long_inactive_candidates($db, $inactiveDays, 500);
    if ($candidates === []) {
        return ['ok' => false, 'reason' => 'no_recipients'];
    }

    $recipients = [];
    $estimatedParts = 0;
    $encoding = 'LATIN';
    foreach ($candidates as $account) {
        $accountId = (int) ($account['id'] ?? 0);
        $phone = trim((string) ($account['phone'] ?? ''));
        if ($accountId <= 0 || $phone === '') {
            continue;
        }
        $body = trim($customBody) !== '' ? trim($customBody) : (string) ($template['body'] ?? '');
        $rendered = app_message_render_body($body, $account);
        $messageEncoding = app_communication_sms_encoding($rendered);
        if ($messageEncoding === 'UNICODE') {
            $encoding = 'UNICODE';
        }
        $estimatedParts += app_communication_sms_parts($rendered, $messageEncoding);
        $lastPaymentAt = trim((string) ($account['last_payment_at'] ?? ''));
        $recipients[$accountId] = [
            'account_id' => $accountId,
            'subscriber_code' => (string) ($account['subscriber_code'] ?? ''),
            'inactivity_days' => $lastPaymentAt !== '' ? app_intelligence_days_between($lastPaymentAt) : $inactiveDays,
            'last_payment_at' => $lastPaymentAt !== '' ? $lastPaymentAt : null,
        ];
    }
    if ($recipients === []) {
        return ['ok' => false, 'reason' => 'no_valid_phone_recipients'];
    }

    $accountIds = array_keys($recipients);
    $costPerPart = max(0.0, (float) (getenv('SMS_ESTIMATED_SEGMENT_COST') ?: 0));
    $idempotencyKey = hash('sha256', implode('|', [
        'long_inactive', $templateCode, $inactiveDays, hash('sha256', trim($customBody)), date('Y-m-d'), (int) ($actor['id'] ?? 0),
    ]));

    try {
        $db->beginTransaction();
        $statement = $db->prepare(
            'INSERT INTO communication_campaign_approvals (
                campaign_key, idempotency_key, message_type, template_code, requested_channel, scope, area, custom_body,
                recipient_account_ids_json, recipient_count, estimated_parts, estimated_cost, encoding,
                eligibility_json, provider_purpose, approval_status, created_by_account_id, created_by_name
             ) VALUES (
                :campaign_key, :idempotency_key, "long_inactive", :template_code, "sms", "long_inactive", :area, :custom_body,
                :recipient_ids, :recipient_count, :estimated_parts, :estimated_cost, :encoding,
                :eligibility_json, "ADV", "pending_approval", :created_by_account_id, :created_by_name
             )'
        );
        $statement->execute([
            'campaign_key' => $idempotencyKey,
            'idempotency_key' => $idempotencyKey,
            'template_code' => $templateCode,
            'area' => 'inactive_' . $inactiveDays . 'd',
            'custom_body' => trim($customBody) !== '' ? trim($customBody) : null,
            'recipient_ids' => app_db_json_encode($accountIds),
            'recipient_count' => count($accountIds),
            'estimated_parts' => $estimatedParts,
            'estimated_cost' => round($estimatedParts * $costPerPart, 4),
            'encoding' => $encoding,
            'eligibility_json' => app_db_json_encode([
                'inactive_threshold_days' => $inactiveDays,
                'evaluated_at' => app_db_now(),
                'evaluated_by' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
            ]),
            'created_by_account_id' => (int) ($actor['id'] ?? 0),
            'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
        ]);
        $campaignId = (int) $db->lastInsertId();

        $recipientInsert = $db->prepare(
            'INSERT INTO communication_campaign_recipients (
                campaign_id, account_id, subscriber_code, eligibility_status, eligibility_reason,
                inactivity_days, last_payment_at, queue_status
             ) VALUES (
                :campaign_id, :account_id, :subscriber_code, "eligible", :eligibility_reason,
                :inactivity_days, :last_payment_at, "proposed"
             )'
        );
        foreach ($recipients as $recipient) {
            $recipientInsert->execute([
                'campaign_id' => $campaignId,
                'account_id' => $recipient['account_id'],
                'subscriber_code' => $recipient['subscriber_code'] !== '' ? $recipient['subscriber_code'] : null,
                'eligibility_reason' => sprintf('%d gun odenissiz', $recipient['inactivity_days']),
                'inactivity_days' => $recipient['inactivity_days'],
                'last_payment_at' => $recipient['last_payment_at'],
            ]);
        }
        $db->commit();

        return [
            'ok' => true,
            'id' => $campaignId,
            'recipient_count' => count($accountIds),
            'estimated_parts' => $estimatedParts,
            'encoding' => $encoding,
            'status' => 'pending_approval',
        ];
    } catch (PDOException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => (string) $exception->getCode() === '23000' ? 'duplicate_campaign_today' : 'campaign_insert_failed'];
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => 'campaign_insert_failed'];
    }
}

/**
 * @return int Inserted `communication_messages` id, or 0 when the recipient
 *             could not be queued (no destination/body).
 */
function app_communication_campaign_queue_account(PDO $db, array $campaign, array $account, array $actor): int
{
    $template = app_message_template_by_code((string) ($campaign['template_code'] ?? ''));
    $body = trim((string) ($campaign['custom_body'] ?? ''));
    if ($body === '') {
        $body = (string) ($template['body'] ?? '');
    }
    $body = mb_substr(app_message_render_body($body, $account), 0, 1000);
    $channel = app_message_channel_for_account($account, 'sms');
    $destination = trim((string) ($channel['destination'] ?? ''));
    if ($body === '' || $destination === '') {
        return 0;
    }
    if (app_communication_is_opted_out($db, $destination, 'sms')) {
        return 0;
    }

    $messageType = (string) ($campaign['message_type'] ?? 'holiday');
    $statement = $db->prepare(
        'INSERT INTO communication_messages (
            account_id, subscriber_code, recipient_name, destination, channel, fallback_chain,
            template_code, message_body, status, sent_at, created_by_account_id, metadata_json
         ) VALUES (
            :account_id, :subscriber_code, :recipient_name, :destination, "sms", "sms",
            :template_code, :message_body, "queued", NULL, :created_by_account_id, :metadata_json
         )'
    );
    $ok = $statement->execute([
        'account_id' => (int) ($account['id'] ?? 0),
        'subscriber_code' => (string) ($account['subscriber_code'] ?? ''),
        'recipient_name' => (string) ($account['full_name'] ?? ''),
        'destination' => (string) ($channel['destination'] ?? ''),
        'template_code' => (string) ($campaign['template_code'] ?? ''),
        'message_body' => $body,
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'metadata_json' => app_db_json_encode([
            'campaign_id' => (int) ($campaign['id'] ?? 0),
            'message_type' => $messageType,
            'provider_purpose' => 'ADV',
            'encoding' => app_communication_sms_encoding($body),
            'estimated_parts' => app_communication_sms_parts($body),
            'approval' => [
                'created_by_account_id' => (int) ($campaign['created_by_account_id'] ?? 0),
                'approved_by_account_id' => (int) ($actor['id'] ?? 0),
                'approved_at' => app_db_now(),
            ],
        ]),
    ]);
    return $ok ? (int) $db->lastInsertId() : 0;
}

function app_communication_campaign_approve_and_queue(int $campaignId, array $actor): array
{
    $db = app_db();
    $actorId = (int) ($actor['id'] ?? 0);
    if (!$db instanceof PDO || $campaignId <= 0 || $actorId <= 0 || !app_communication_campaign_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'invalid_approval_request'];
    }

    try {
        $db->beginTransaction();
        $statement = $db->prepare('SELECT * FROM communication_campaign_approvals WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $campaignId]);
        $campaign = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($campaign) || (string) ($campaign['approval_status'] ?? '') !== 'pending_approval') {
            $db->rollBack();
            return ['ok' => false, 'reason' => 'campaign_not_pending'];
        }
        if ((int) ($campaign['created_by_account_id'] ?? 0) === $actorId) {
            $db->rollBack();
            return ['ok' => false, 'reason' => 'creator_cannot_approve'];
        }
        // Long-inactive campaigns are advisor-originated (see
        // app_advisor_marketing_briefing() / app_advisor_council_run()) and
        // require actual super-admin authority to approve/queue, not just a
        // distinct actor id. The pre-existing holiday-campaign flow keeps its
        // original app_portal_is_admin() gate (message-center.php), which this
        // does not touch.
        if ((string) ($campaign['message_type'] ?? '') === 'long_inactive'
            && (!function_exists('app_portal_is_super_admin') || !app_portal_is_super_admin($actor))
        ) {
            $db->rollBack();
            return ['ok' => false, 'reason' => 'super_admin_required'];
        }

        $ids = app_db_json_decode((string) ($campaign['recipient_account_ids_json'] ?? ''), []);
        $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : []), static fn(int $id): bool => $id > 0));
        $recipientUpdate = $db->prepare(
            'UPDATE communication_campaign_recipients
                SET queue_status = :queue_status, message_id = :message_id, updated_at = NOW()
              WHERE campaign_id = :campaign_id AND account_id = :account_id'
        );
        $queued = 0;
        $failed = 0;
        foreach ($ids as $accountId) {
            $account = app_db_account_by_id($accountId);
            $messageId = is_array($account) ? app_communication_campaign_queue_account($db, $campaign, $account, $actor) : 0;
            if ($messageId > 0) {
                $queued++;
            } else {
                $failed++;
            }
            // No-op when this campaign has no per-recipient audit rows (e.g. the
            // existing holiday flow, which only tracks recipients via the
            // aggregate recipient_account_ids_json list).
            $recipientUpdate->execute([
                'queue_status' => $messageId > 0 ? 'queued' : 'failed',
                'message_id' => $messageId > 0 ? $messageId : null,
                'campaign_id' => $campaignId,
                'account_id' => $accountId,
            ]);
        }

        $status = $queued > 0 && $failed === 0 ? 'queued' : ($queued > 0 ? 'queued_partial' : 'queue_failed');
        $update = $db->prepare(
            'UPDATE communication_campaign_approvals
             SET approval_status = :status, approved_by_account_id = :approved_by_account_id,
                 approved_by_name = :approved_by_name, approved_at = NOW(), queued_at = IF(:queued > 0, NOW(), NULL)
             WHERE id = :id AND approval_status = "pending_approval"'
        );
        $update->execute([
            'status' => $status,
            'approved_by_account_id' => $actorId,
            'approved_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
            'queued' => $queued,
            'id' => $campaignId,
        ]);
        $db->commit();
        return ['ok' => $queued > 0, 'status' => $status, 'queued' => $queued, 'failed' => $failed];
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => 'campaign_approval_failed'];
    }
}

function app_communication_campaign_recent(PDO $db, int $limit = 20): array
{
    if (!app_communication_campaign_ensure_schema($db)) {
        return [];
    }
    $statement = $db->query(
        'SELECT id, message_type, template_code, scope, area, recipient_count, estimated_parts,
                estimated_cost, encoding, provider_purpose, approval_status,
                created_by_account_id, created_by_name, approved_by_name, approved_at, created_at
         FROM communication_campaign_approvals
         ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min($limit, 50))
    );
    return $statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}
