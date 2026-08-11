<?php
declare(strict_types=1);

require dirname(__DIR__) . '/billing-ui.php';
require dirname(__DIR__) . '/communication-campaign-lib.php';

$checks = [
    'latin_single_part' => app_communication_sms_parts(str_repeat('A', 160), 'LATIN') === 1,
    'latin_multi_part' => app_communication_sms_parts(str_repeat('A', 161), 'LATIN') === 2,
    'unicode_single_part' => app_communication_sms_parts(str_repeat('Ə', 70), 'UNICODE') === 1,
    'unicode_multi_part' => app_communication_sms_parts(str_repeat('Ə', 71), 'UNICODE') === 2,
    'encoding_detection' => app_communication_sms_encoding('Salam') === 'LATIN'
        && app_communication_sms_encoding('Salam, Əli') === 'UNICODE',
];

$db = app_db();
if (!$db instanceof PDO || !app_communication_campaign_ensure_schema($db)) {
    $checks['schema'] = false;
} else {
    $checks['schema'] = true;
    $key = hash('sha256', 'campaign-approval-smoke-' . bin2hex(random_bytes(8)));
    $actorId = 987654321;
    try {
        $insert = $db->prepare(
            'INSERT INTO communication_campaign_approvals (
                campaign_key, message_type, template_code, requested_channel, scope,
                recipient_account_ids_json, recipient_count, estimated_parts, estimated_cost,
                encoding, provider_purpose, approval_status, created_by_account_id, created_by_name
             ) VALUES (:campaign_key, "holiday", "holiday_smoke", "sms", "active", "[]", 0, 0, 0, "LATIN", "ADV", "pending_approval", :actor_id, "smoke")'
        );
        $insert->execute(['campaign_key' => $key, 'actor_id' => $actorId]);
        $id = (int) $db->lastInsertId();
        $result = app_communication_campaign_approve_and_queue($id, ['id' => $actorId, 'full_name' => 'smoke']);
        $statusStatement = $db->prepare('SELECT approval_status FROM communication_campaign_approvals WHERE id = :id');
        $statusStatement->execute(['id' => $id]);
        $checks['creator_cannot_approve'] = empty($result['ok'])
            && ($result['reason'] ?? '') === 'creator_cannot_approve'
            && $statusStatement->fetchColumn() === 'pending_approval';
    } finally {
        $delete = $db->prepare('DELETE FROM communication_campaign_approvals WHERE campaign_key = :campaign_key');
        $delete->execute(['campaign_key' => $key]);
    }
}

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['ok' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($failed === [] ? 0 : 1);
