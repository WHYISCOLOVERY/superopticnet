<?php
declare(strict_types=1);

require dirname(__DIR__) . '/communication-campaign-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// NOTE: PHP does not run try{}finally{} blocks after exit()/die() inside the
// try body -- every failure path below throws instead of exiting directly.
class CommunicationOptOutSmokeFailure extends RuntimeException
{
}

function communication_opt_out_smoke_fail(string $message, int $code = 1): never
{
    throw new CommunicationOptOutSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$stamp = date('His') . random_int(100, 999);
$phone = '+99450' . substr($stamp, 0, 7);
$accountId = 0;
$sentMessageIds = [];

$cleanup = function () use ($db, $phone, &$accountId, &$sentMessageIds): void {
    foreach ($sentMessageIds as $id) {
        $db->prepare('DELETE FROM communication_messages WHERE id = :id')->execute(['id' => $id]);
    }
    $db->prepare('DELETE FROM communication_opt_outs WHERE phone_normalized = :phone')->execute(['phone' => normalize_phone($phone)]);
    if ($accountId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
};

try {
    $accountSaved = app_db_upsert_account([
        'login' => 'smoke-optout-' . strtolower($stamp),
        'password' => 'SmokePass123',
        'role' => 'customer',
        'account_type' => 'customer',
        'full_name' => 'Opt-Out Smoke Customer',
        'subscriber_code' => 'SMOKE-OPTOUT-' . strtoupper($stamp),
        'phone' => $phone,
        'area' => 'Smoke',
        'status' => 'Aktiv',
        'is_active' => 1,
    ], false);
    if (!is_array($accountSaved)) {
        communication_opt_out_smoke_fail('account_seed_failed');
    }
    $accountId = (int) ($accountSaved['id'] ?? 0);
    $account = app_db_account_by_id($accountId);
    if (!is_array($account)) {
        communication_opt_out_smoke_fail('account_reload_failed');
    }
    $actor = ['id' => 0, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'support', 'full_name' => 'Opt-Out Smoke'];
    $campaign = ['template_code' => '', 'custom_body' => 'Smoke campaign message', 'id' => 0, 'message_type' => 'holiday'];

    // 1. Not opted out by default -> campaign queue must succeed.
    if (app_communication_is_opted_out($db, $phone, 'sms')) {
        communication_opt_out_smoke_fail('fresh_phone_unexpectedly_opted_out');
    }
    $firstMessageId = app_communication_campaign_queue_account($db, $campaign, $account, $actor);
    if ($firstMessageId <= 0) {
        communication_opt_out_smoke_fail('queue_should_succeed_before_opt_out');
    }
    $sentMessageIds[] = $firstMessageId;

    // 2. Opt out -> is_opted_out true, and the SAME queue path must now refuse.
    if (!app_communication_opt_out($db, $phone, 'sms', 'smoke_test', $actor, 'smoke reason')) {
        communication_opt_out_smoke_fail('opt_out_write_failed');
    }
    if (!app_communication_is_opted_out($db, $phone, 'sms')) {
        communication_opt_out_smoke_fail('opt_out_not_reflected_in_check');
    }
    $blockedMessageId = app_communication_campaign_queue_account($db, $campaign, $account, $actor);
    if ($blockedMessageId !== 0) {
        communication_opt_out_smoke_fail('campaign_queue_did_not_respect_opt_out:' . $blockedMessageId);
    }

    // 3. 'all' channel opt-out must also block 'sms'-specific checks.
    app_communication_opt_in($db, $phone, 'sms');
    if (app_communication_is_opted_out($db, $phone, 'sms')) {
        communication_opt_out_smoke_fail('opt_in_did_not_clear_sms_opt_out');
    }
    app_communication_opt_out($db, $phone, 'all', 'smoke_test', $actor, 'blanket opt-out');
    if (!app_communication_is_opted_out($db, $phone, 'sms')) {
        communication_opt_out_smoke_fail('all_channel_opt_out_did_not_cover_sms');
    }
    if (!app_communication_is_opted_out($db, $phone, 'whatsapp')) {
        communication_opt_out_smoke_fail('all_channel_opt_out_did_not_cover_whatsapp');
    }

    // 4. Opting back in (channel-specific) must clear the 'all' block too --
    //    matches the customer-portal toggle's only real control (sms).
    app_communication_opt_in($db, $phone, 'all');
    if (app_communication_is_opted_out($db, $phone, 'sms')) {
        communication_opt_out_smoke_fail('opt_in_all_did_not_restore_sms');
    }
    $restoredMessageId = app_communication_campaign_queue_account($db, $campaign, $account, $actor);
    if ($restoredMessageId <= 0) {
        communication_opt_out_smoke_fail('queue_should_succeed_after_opt_in');
    }
    $sentMessageIds[] = $restoredMessageId;

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'phone' => $phone,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
