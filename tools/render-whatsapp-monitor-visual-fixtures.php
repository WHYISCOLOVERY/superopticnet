<?php
declare(strict_types=1);

require dirname(__DIR__) . '/whatsapp-monitor-lib.php';

$root = dirname(__DIR__);
$outputDir = $root . '/tmp/whatsapp-monitor-visual';
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "fixture_output_failed\n");
    exit(1);
}

$portalCss = (string) file_get_contents($root . '/css/portal.css');
$monitorCss = (string) file_get_contents($root . '/css/whatsapp-monitor.css');
$monitorJs = (string) file_get_contents($root . '/script/whatsapp-monitor.js');
$monitorLang = 'az';

$chatRows = [
    [
        'number_id' => 101,
        'chat_id' => '994501111111@c.us',
        'contact_name' => 'Test elaqe 1',
        'company_number' => '051 200 00 01',
        'employee_name' => 'Test operator',
        'last_message' => 'Salam, internet xidmeti ile bagli melumat lazimdir.',
        'last_message_at' => '2026-07-13 10:15:00',
        'last_message_id' => 501,
        'message_count' => 12,
    ],
    [
        'number_id' => 102,
        'chat_id' => '994502222222@c.us',
        'contact_name' => 'Test elaqe 2',
        'company_number' => '051 200 00 02',
        'employee_name' => 'Test operator 2',
        'last_message' => 'Odenis melumati yoxlanilir.',
        'last_message_at' => '2026-07-13 10:12:00',
        'last_message_id' => 402,
        'message_count' => 7,
    ],
];
$selectedChat = $chatRows[0];
$chatMessages = [
    ['id' => 500, 'direction' => 'inbound', 'message_body' => 'Salam, internet xidmeti ile bagli melumat lazimdir.', 'has_media' => 0, 'message_type' => 'chat', 'occurred_at' => '2026-07-13 10:14:00'],
    ['id' => 501, 'direction' => 'outbound', 'message_body' => 'Muracietiniz qeyde alindi.', 'has_media' => 0, 'message_type' => 'chat', 'occurred_at' => '2026-07-13 10:15:00'],
];

$renderDocument = static function (string $viewFile, array $context, string $name) use ($outputDir, $portalCss, $monitorCss, $monitorJs, $monitorLang): void {
    ob_start();
    require $viewFile;
    $viewHtml = (string) ob_get_clean();
    $html = '<!doctype html><html lang="az"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>WhatsApp visual smoke</title><style>' . $portalCss . "\n" . $monitorCss . '</style></head>'
        . '<body class="portal-body"><main class="portal-layout"><section class="portal-card whatsapp-monitor-module">'
        . $viewHtml . '</section></main><script>' . $monitorJs . '</script></body></html>';
    file_put_contents($outputDir . '/' . $name . '.html', $html);
};

$baseChatContext = [
    'chat_rows' => $chatRows,
    'selected_number_id' => 101,
    'selected_chat_id' => '994501111111@c.us',
    'selected_chat' => $selectedChat,
    'chat_messages' => $chatMessages,
];

$defaultContext = $baseChatContext + ['chat_selection_explicit' => false];
$renderDocument($root . '/views/whatsapp-monitor/chats.php', $defaultContext, 'chats-default');

$selectedContext = $baseChatContext + ['chat_selection_explicit' => true];
$renderDocument($root . '/views/whatsapp-monitor/chats.php', $selectedContext, 'chats-selected');

$emptyContext = [
    'chat_rows' => [],
    'selected_number_id' => 0,
    'selected_chat_id' => '',
    'selected_chat' => null,
    'chat_messages' => [],
    'chat_selection_explicit' => false,
];
$renderDocument($root . '/views/whatsapp-monitor/chats.php', $emptyContext, 'chats-empty');

$pairingContext = [
    'numbers' => [[
        'id' => 101,
        'phone_number' => '051 200 00 01',
        'whatsapp_account_name' => 'Test WhatsApp',
        'employee_name' => 'Test operator',
        'qr_status' => 'requested',
        'qr_last_requested_at' => '2026-07-13 10:10:00',
        'qr_session_key' => 'visual-session',
        'monitoring_policy_accepted' => 1,
    ]],
    'devices' => [],
    'pairings' => [[
        'id' => 1,
        'number_id' => 101,
        'phone_number' => '051 200 00 01',
        'employee_name' => 'Test operator',
        'pairing_status' => 'requested',
        'session_key' => 'visual-session',
        'expires_at' => '2026-07-13 10:20:00',
        'worker_id' => 'visual-worker',
        'created_at' => '2026-07-13 10:10:00',
        'updated_at' => '2026-07-13 10:10:00',
    ]],
    'can_manage' => false,
];
$renderDocument($root . '/views/whatsapp-monitor/pairing.php', $pairingContext, 'pairing');

echo json_encode(['ok' => true, 'output_dir' => $outputDir], JSON_UNESCAPED_SLASHES) . PHP_EOL;
