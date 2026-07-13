<?php
declare(strict_types=1);

require dirname(__DIR__) . '/copilot-agent.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function telegram_command_smoke_out(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    telegram_command_smoke_out(['status' => 'skipped', 'reason' => 'db_unavailable']);
}

$stamp = date('His') . random_int(100, 999);
$subscriberCode = 'TGACT-' . $stamp;
$accountId = 0;
$employeeId = 0;
$actionIds = [];

try {
    $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, phone, area, address, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", "Telegram Action Customer", :subscriber_code, "994501112233", "TG zona", "TG unvan", "TG 100", "Aktiv", 1, :payload_json
         )'
    )->execute([
        'login' => $subscriberCode,
        'password_hash' => password_hash('tg-pass', PASSWORD_DEFAULT),
        'subscriber_code' => $subscriberCode,
        'payload_json' => app_db_json_encode([
            'billing' => [
                'pppoe_username' => $subscriberCode,
                'pppoe_password' => 'tg-pass',
                'status' => 'Aktiv',
                'expires_at' => date('Y-m-d H:i:s', strtotime('+20 days')),
            ],
            'ont' => [
                'serial' => 'TGONTSN' . $stamp,
                'mac' => 'AA:11:22:33:44:55',
                'olt' => 'Role QA OLT',
                'pon' => '1/1',
                'ont_id' => '7',
            ],
        ]),
    ]);
    $accountId = (int) $db->lastInsertId();

    $telegramChatId = '-1003976043672';
    $telegramUserId = '777' . substr($stamp, -6);
    $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, employee_role, full_name, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "employee", "operator", "operator", "Telegram Smoke Operator", "Aktiv", 1, :payload_json
         )'
    )->execute([
        'login' => 'tg-operator-' . $stamp,
        'password_hash' => password_hash('tg-operator-pass', PASSWORD_DEFAULT),
        'payload_json' => app_db_json_encode([
            'employee' => ['telegram_chat_id' => $telegramChatId],
            'telegram' => ['chat_id' => $telegramChatId, 'user_id' => $telegramUserId],
        ]),
    ]);
    $employeeId = (int) $db->lastInsertId();

    $actor = app_copilot_agent_actor_from_telegram_message([
        'from' => [
            'id' => $telegramUserId,
            'username' => 'role_tg_agent',
            'first_name' => 'Role',
            'last_name' => 'Telegram',
        ],
        'chat' => ['id' => $telegramChatId],
    ]);

    $smsPending = app_copilot_agent_handle_message($subscriberCode . ' sms gonder xidmet yoxlama mesaji', $actor, $db);
    $actionIds[] = (int) ($smsPending['action_id'] ?? 0);
    $smsConfirm = app_copilot_agent_handle_message('TESDIQ ' . (int) ($smsPending['action_id'] ?? 0), $actor, $db);
    $smsCountStatement = $db->prepare(
        'SELECT COUNT(*) FROM communication_messages
         WHERE account_id = :account_id AND channel = "sms" AND message_body LIKE :needle'
    );
    $smsCountStatement->execute([
        'account_id' => $accountId,
        'needle' => '%xidmet yoxlama mesaji%',
    ]);
    $smsQueued = (int) $smsCountStatement->fetchColumn();

    $restartPending = app_copilot_agent_handle_message($subscriberCode . ' modem restart et', $actor, $db);
    $actionIds[] = (int) ($restartPending['action_id'] ?? 0);
    $restartConfirm = app_copilot_agent_handle_message('TESDIQ ' . (int) ($restartPending['action_id'] ?? 0), $actor, $db);
    $restartCountStatement = $db->prepare(
        'SELECT COUNT(*) FROM mgs_operation_actions
         WHERE action_type = "ont_restart_request" AND entity_id = :entity_id'
    );
    $restartCountStatement->execute(['entity_id' => $subscriberCode]);
    $restartQueued = (int) $restartCountStatement->fetchColumn();

    $help = app_copilot_agent_handle_message('hansi suallara cavab verirsen', $actor, $db);
    $helpText = (string) ($help['text'] ?? '');

    $ok = !empty($smsPending['ok'])
        && !empty($smsConfirm['ok'])
        && $smsQueued >= 1
        && !empty($restartPending['ok'])
        && !empty($restartConfirm['ok'])
        && $restartQueued >= 1
        && str_contains($helpText, 'SMS')
        && str_contains($helpText, 'restart');

    telegram_command_smoke_out([
        'status' => $ok ? 'ok' : 'failed',
        'subscriber_code' => $subscriberCode,
        'sms' => [
            'pending_id' => (int) ($smsPending['action_id'] ?? 0),
            'confirmed' => !empty($smsConfirm['ok']),
            'queued' => $smsQueued,
        ],
        'restart' => [
            'pending_id' => (int) ($restartPending['action_id'] ?? 0),
            'confirmed' => !empty($restartConfirm['ok']),
            'queued' => $restartQueued,
        ],
        'help_mentions_sms_restart' => str_contains($helpText, 'SMS') && str_contains($helpText, 'restart'),
    ], $ok ? 0 : 4);
} catch (Throwable $exception) {
    telegram_command_smoke_out(['status' => 'failed', 'reason' => $exception->getMessage()], 2);
} finally {
    foreach (array_filter($actionIds) as $actionId) {
        $db->prepare('DELETE FROM mgs_operation_actions WHERE id = :id')->execute(['id' => (int) $actionId]);
    }
    if ($accountId > 0) {
        $db->prepare('DELETE FROM communication_messages WHERE account_id = :account_id')->execute(['account_id' => $accountId]);
        $db->prepare('DELETE FROM mgs_operation_actions WHERE action_type = "ont_restart_request" AND entity_id = :entity_id')->execute(['entity_id' => $subscriberCode]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
    if ($employeeId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $employeeId]);
    }
}
