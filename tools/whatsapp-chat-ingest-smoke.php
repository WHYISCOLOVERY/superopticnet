<?php
declare(strict_types=1);

require dirname(__DIR__) . '/whatsapp-monitor-lib.php';

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}
whatsapp_monitor_ensure_schema($db);
$pairing = $db->query('SELECT * FROM whatsapp_monitor_pairings ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$actorStatement = $db->prepare('SELECT * FROM portal_accounts WHERE login = :login OR full_name = :full_name ORDER BY id DESC LIMIT 1');
$actorStatement->execute(['login' => 's.abdullayev@superoptic.net', 'full_name' => 'Serdar Abdullayev']);
$actor = $actorStatement->fetch(PDO::FETCH_ASSOC);
if (!is_array($pairing) || !is_array($actor)) {
    fwrite(STDOUT, json_encode(['ok' => true, 'skipped' => 'pairing_or_actor_missing']) . PHP_EOL);
    exit(0);
}

$messageId = 'smoke-' . bin2hex(random_bytes(8));
$chatId = '994500000000@c.us';
$payload = [
    'session_key' => (string) $pairing['session_key'],
    'message_id' => $messageId,
    'chat_id' => $chatId,
    'from' => $chatId,
    'to' => '994512055574@c.us',
    'from_me' => false,
    'contact_name' => 'Smoke contact',
    'type' => 'chat',
    'body' => 'Smoke incoming message',
    'timestamp' => time(),
];
$errors = [];
try {
    $first = whatsapp_monitor_worker_ingest_message($db, $payload);
    $second = whatsapp_monitor_worker_ingest_message($db, $payload);
    if (empty($first['ok']) || empty($second['ok'])) {
        $errors[] = 'ingest_failed';
    }
    $countStatement = $db->prepare('SELECT COUNT(*) FROM whatsapp_monitor_messages WHERE whatsapp_message_id = :message_id');
    $countStatement->execute(['message_id' => $messageId]);
    if ((int) $countStatement->fetchColumn() !== 1) {
        $errors[] = 'dedup_failed';
    }
    $messages = whatsapp_monitor_chat_messages($db, $actor, $chatId);
    if (!array_filter($messages, static fn (array $row): bool => (string) ($row['whatsapp_message_id'] ?? '') === $messageId)) {
        $errors[] = 'chat_message_missing';
    }
    $chats = whatsapp_monitor_chat_rows($db, $actor);
    if (!array_filter($chats, static fn (array $row): bool => (string) ($row['chat_id'] ?? '') === $chatId)) {
        $errors[] = 'chat_summary_missing';
    }
    $context = ['chat_rows' => $chats, 'selected_chat_id' => $chatId, 'chat_messages' => $messages];
    $monitorLang = 'az';
    ob_start();
    require dirname(__DIR__) . '/views/whatsapp-monitor/chats.php';
    $html = (string) ob_get_clean();
    foreach (['WhatsApp cat monitoru', 'data-wa-chat-thread', 'Smoke incoming message'] as $marker) {
        if (!str_contains($html, $marker)) {
            $errors[] = 'chat_render_missing:' . $marker;
        }
    }
} finally {
    $db->prepare('DELETE FROM whatsapp_monitor_messages WHERE whatsapp_message_id = :message_id')->execute(['message_id' => $messageId]);
}

fwrite(STDOUT, json_encode(['ok' => $errors === [], 'errors' => $errors], JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($errors === [] ? 0 : 1);
