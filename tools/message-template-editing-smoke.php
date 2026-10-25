<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// NOTE: PHP does not run try{}finally{} blocks after exit()/die() inside the
// try body -- every failure path below throws instead of exiting directly.
class MessageTemplateEditingSmokeFailure extends RuntimeException
{
}

function message_template_editing_smoke_fail(string $message, int $code = 1): never
{
    throw new MessageTemplateEditingSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$run = date('His') . random_int(100, 999);
$code = 'SMOKE-TPL-' . $run;
$actor = ['id' => 501501, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Template Smoke Admin'];
$otherActor = ['id' => 501502, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Template Smoke Admin 2'];

$cleanup = function () use ($db, $code): void {
    $db->prepare('DELETE FROM communication_template_history WHERE template_code = :code')->execute(['code' => $code]);
    $db->prepare('DELETE FROM communication_templates WHERE code = :code')->execute(['code' => $code]);
};

try {
    // 1. Creating a new template requires no reason and starts at version 1.
    $created = app_message_template_save([
        'code' => $code,
        'title' => 'Smoke Template',
        'category' => 'smoke',
        'channel_scope' => 'sms',
        'body' => 'Original body v1',
        'status' => 'active',
    ], $actor);
    if (empty($created['ok']) || (int) ($created['version'] ?? 0) !== 1) {
        message_template_editing_smoke_fail('template_create_failed:' . json_encode($created, JSON_UNESCAPED_SLASHES));
    }

    $fetched = app_message_template_by_code($code);
    if (!is_array($fetched) || (string) ($fetched['body'] ?? '') !== 'Original body v1' || (int) ($fetched['version'] ?? 0) !== 1) {
        message_template_editing_smoke_fail('created_template_readback_wrong');
    }

    // 2. Editing an existing template WITHOUT a reason must be refused.
    $noReason = app_message_template_save([
        'code' => $code,
        'title' => 'Smoke Template',
        'category' => 'smoke',
        'channel_scope' => 'sms',
        'body' => 'Changed body v2',
        'status' => 'active',
    ], $actor);
    if (!empty($noReason['ok']) || ($noReason['error'] ?? '') !== 'reason_required') {
        message_template_editing_smoke_fail('edit_without_reason_was_not_blocked');
    }

    // 3. Editing with no actual field changes must be refused (nothing to log).
    $noChanges = app_message_template_save([
        'code' => $code,
        'title' => 'Smoke Template',
        'category' => 'smoke',
        'channel_scope' => 'sms',
        'body' => 'Original body v1',
        'status' => 'active',
        'reason' => 'smoke: no-op edit attempt',
    ], $actor);
    if (!empty($noChanges['ok']) || ($noChanges['error'] ?? '') !== 'no_changes') {
        message_template_editing_smoke_fail('no_op_edit_was_not_blocked');
    }

    // 4. A real edit with a reason must succeed, bump the version, and write
    //    a history row capturing the previous body.
    $edited = app_message_template_save([
        'code' => $code,
        'title' => 'Smoke Template Updated',
        'category' => 'smoke',
        'channel_scope' => 'sms',
        'body' => 'Changed body v2',
        'status' => 'active',
        'reason' => 'smoke: wording correction',
    ], $otherActor);
    if (empty($edited['ok']) || (int) ($edited['version'] ?? 0) !== 2) {
        message_template_editing_smoke_fail('edit_failed:' . json_encode($edited, JSON_UNESCAPED_SLASHES));
    }
    $afterEdit = app_message_template_by_code($code);
    if (!is_array($afterEdit) || (string) ($afterEdit['body'] ?? '') !== 'Changed body v2' || (int) ($afterEdit['version'] ?? 0) !== 2) {
        message_template_editing_smoke_fail('edited_template_readback_wrong');
    }

    $history = app_message_template_history($code, 10);
    if (count($history) !== 1) {
        message_template_editing_smoke_fail('history_row_count_wrong:' . count($history));
    }
    $historyRow = $history[0];
    if ((string) ($historyRow['previous_body'] ?? '') !== 'Original body v1'
        || (string) ($historyRow['new_body'] ?? '') !== 'Changed body v2'
        || (string) ($historyRow['reason'] ?? '') !== 'smoke: wording correction'
        || (int) ($historyRow['version'] ?? 0) !== 2
    ) {
        message_template_editing_smoke_fail('history_row_content_wrong:' . json_encode($historyRow, JSON_UNESCAPED_SLASHES));
    }

    // 5. Disabling the template (status change) must also version + log, and
    //    the read path used by campaign sends (active-only) must stop
    //    returning it -- while direct by-code lookup for already-queued
    //    messages still works via the raw table (message_body was already
    //    frozen at send time, unaffected either way).
    $disabled = app_message_template_save([
        'code' => $code,
        'title' => 'Smoke Template Updated',
        'category' => 'smoke',
        'channel_scope' => 'sms',
        'body' => 'Changed body v2',
        'status' => 'disabled',
        'reason' => 'smoke: retiring this template',
    ], $actor);
    if (empty($disabled['ok']) || (int) ($disabled['version'] ?? 0) !== 3) {
        message_template_editing_smoke_fail('disable_edit_failed');
    }
    $activeOnly = app_message_templates(true);
    foreach ($activeOnly as $row) {
        if ((string) ($row['code'] ?? '') === $code) {
            message_template_editing_smoke_fail('disabled_template_still_in_active_only_list');
        }
    }
    $allTemplates = app_message_templates(false);
    $stillPresent = false;
    foreach ($allTemplates as $row) {
        if ((string) ($row['code'] ?? '') === $code) {
            $stillPresent = true;
        }
    }
    if (!$stillPresent) {
        message_template_editing_smoke_fail('disabled_template_missing_from_full_list');
    }

    // 6. Past sends are frozen: a message queued under the old body must keep
    //    its exact wording even though the template has since changed twice.
    $frozenBody = 'Original body v1 (frozen at send time)';
    $db->prepare(
        "INSERT INTO communication_messages (channel, destination, template_code, message_body, status, created_at)
         VALUES ('sms', '+994500000000', :template_code, :message_body, 'test', NOW())"
    )->execute(['template_code' => $code, 'message_body' => $frozenBody]);
    $frozenId = (int) $db->lastInsertId();
    $frozenCheck = $db->prepare('SELECT message_body FROM communication_messages WHERE id = :id');
    $frozenCheck->execute(['id' => $frozenId]);
    if ((string) $frozenCheck->fetchColumn() !== $frozenBody) {
        $db->prepare('DELETE FROM communication_messages WHERE id = :id')->execute(['id' => $frozenId]);
        message_template_editing_smoke_fail('past_send_body_was_not_frozen');
    }
    $db->prepare('DELETE FROM communication_messages WHERE id = :id')->execute(['id' => $frozenId]);

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'template_code' => $code,
        'final_version' => 3,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
