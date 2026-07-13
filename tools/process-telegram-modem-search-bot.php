<?php
declare(strict_types=1);

require dirname(__DIR__) . '/copilot-agent.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$once = in_array('--once', $_SERVER['argv'] ?? [], true);
$timeout = 5;
$maxLoops = $once ? 1 : 30;
$chatFilter = trim((string) app_config('telegram_modem_search_chat_id', ''));
$actor = ['id' => 0, 'full_name' => 'modem_search_worker'];

function app_superpilot_employee_by_telegram_chat_id(PDO $db, string $chatId): ?array
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return null;
    }

    $rows = $db->query('SELECT * FROM portal_accounts WHERE role = "employee" AND is_active = 1 ORDER BY id DESC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $payload = app_db_account_payload($row);
        $employee = is_array($payload['employee'] ?? null) ? $payload['employee'] : [];
        $telegram = is_array($payload['telegram'] ?? null) ? $payload['telegram'] : [];
        $allowedChatIds = array_filter([
            (string) ($employee['telegram_chat_id'] ?? ''),
            (string) ($telegram['chat_id'] ?? ''),
        ]);
        if (in_array($chatId, $allowedChatIds, true)) {
            return $row;
        }
    }

    return null;
}

for ($loop = 0; $loop < $maxLoops; $loop++) {
    $offset = (int) app_mgs_runtime_setting('telegram_modem_search_offset', '0');
    $updates = app_mgs_telegram_bot_fetch_updates('modem_search', $offset, $timeout);
    if (empty($updates['ok'])) {
        app_mgs_record_operation_action([
            'action_type' => 'telegram_modem_bot_poll',
            'entity_type' => 'telegram_bot',
            'entity_id' => 'modem_search',
            'status' => 'failed',
            'summary' => 'Modem bot polling failed',
            'payload' => $updates,
        ]);
        if ($once) {
            fwrite(STDERR, 'poll_failed:' . (string) ($updates['reason'] ?? 'unknown') . "\n");
            exit(3);
        }
        sleep(3);
        continue;
    }

    $maxUpdateId = $offset;
    foreach ((array) ($updates['updates'] ?? []) as $update) {
        if (!is_array($update)) {
            continue;
        }
        $updateId = (int) ($update['update_id'] ?? 0);
        $maxUpdateId = max($maxUpdateId, $updateId);
        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $employeeAccount = app_superpilot_employee_by_telegram_chat_id($db, $chatId);
        if ($chatFilter !== '' && $chatId !== '' && $chatId !== $chatFilter && $employeeAccount === null) {
            continue;
        }

        $messageActor = app_copilot_agent_actor_from_telegram_message($message);
        if (is_array($employeeAccount)) {
            $messageActor['id'] = (int) ($employeeAccount['id'] ?? 0);
            $messageActor['full_name'] = (string) ($employeeAccount['full_name'] ?? $messageActor['full_name'] ?? 'employee');
            $messageActor['login'] = (string) ($employeeAccount['login'] ?? '');
            $messageActor['telegram_chat_id'] = $chatId;
            $messageActor['source'] = 'telegram_superpilot_bot';
        }

        if ($text === '/start' || $text === '/help') {
            $replyText = "Superpilot hazirdir.\n"
                . "Sorgular: SN/MAC/PPPoE, musteri problemi, inventar, emekdas KPI.\n"
                . "Action numuneleri:\n"
                . "- 2320025 ucun bilet ac internet yoxdur\n"
                . "- 2320025 radius yenile\n"
                . "- CP-MODEM-001 kimdedir\n"
                . "- Samir Abdullayev adinda hansi mallar var\n"
                . "Icra ucun bot TESDIQ isteyecek: TESDIQ 123 / LEGV 123";
        } else {
            $reply = app_copilot_agent_handle_message($text, $messageActor, $db);
            $replyText = !empty($reply['ok'])
                ? (string) ($reply['text'] ?? 'Netice yoxdur')
                : ('Tapilmadi: ' . $text);
        }

        $send = app_mgs_telegram_bot_send_message('modem_search', $replyText, $chatId);
        app_mgs_record_operation_action([
            'action_type' => 'telegram_modem_bot_reply',
            'entity_type' => 'telegram_bot',
            'entity_id' => 'modem_search',
            'status' => !empty($send['ok']) ? 'executed' : 'failed',
            'summary' => 'Modem bot query: ' . mb_substr($text, 0, 80),
            'payload' => [
                'chat_id' => $chatId,
                'query' => $text,
                'send' => $send,
            ],
        ]);
    }

    if ($maxUpdateId >= $offset) {
        app_mgs_set_runtime_setting('telegram_modem_search_offset', (string) ($maxUpdateId + 1), $actor);
    }

    if ($once) {
        echo json_encode([
            'status' => 'ok',
            'processed' => count((array) ($updates['updates'] ?? [])),
            'next_offset' => $maxUpdateId + 1,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
}

exit(0);
