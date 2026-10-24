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
];
$chatNumberGroups = [
    ['number_id' => 101, 'company_number' => '051 200 00 01', 'employee_name' => 'Test operator', 'qr_status' => 'connected', 'chat_count' => 1],
    ['number_id' => 102, 'company_number' => '051 200 00 02', 'employee_name' => 'Test operator 2', 'qr_status' => 'connected', 'chat_count' => 7],
];
$selectedChat = $chatRows[0];
$chatMessages = [
    ['id' => 500, 'direction' => 'inbound', 'message_body' => 'Salam, internet xidmeti ile bagli melumat lazimdir.', 'has_media' => 0, 'message_type' => 'chat', 'occurred_at' => '2026-07-13 10:14:00'],
    ['id' => 501, 'direction' => 'outbound', 'message_body' => 'Muracietiniz qeyde alindi.', 'has_media' => 0, 'message_type' => 'chat', 'occurred_at' => '2026-07-13 10:15:00'],
];

$renderDocument = static function (string $viewFile, array $context, string $name) use ($outputDir, $portalCss, $monitorCss, $monitorJs, $monitorLang): void {
    $fixtureName = $name;
    ob_start();
    require $viewFile;
    $viewHtml = (string) ob_get_clean();
    $html = '<!doctype html><html lang="az"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>WhatsApp visual smoke</title><style>' . $portalCss . "\n" . $monitorCss . '</style></head>'
        . '<body class="portal-body"><main class="portal-layout"><section class="portal-card whatsapp-monitor-module">'
        . $viewHtml . '</section></main><script>' . $monitorJs . '</script></body></html>';
    file_put_contents($outputDir . '/' . $fixtureName . '.html', $html);
};

$baseChatContext = [
    'chat_number_groups' => $chatNumberGroups,
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
    'chat_number_groups' => [[
        'number_id' => 201,
        'company_number' => '051 200 00 03',
        'employee_name' => 'Test operator 3',
        'qr_status' => 'connected',
        'chat_count' => 0,
    ]],
    'chat_rows' => [],
    'selected_number_id' => 201,
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

$employees = [
    ['id' => 201, 'full_name' => 'Test operator', 'employee_role' => 'operator'],
    ['id' => 202, 'full_name' => 'Test nəzarətçi', 'employee_role' => 'monitor'],
];
$numbers = [
    [
        'id' => 101,
        'employee_name' => 'Test operator',
        'employee_role' => 'operator',
        'phone_number' => '051 200 00 01',
        'whatsapp_account_name' => 'Test WhatsApp',
        'assigned_area_id' => 'Bakı',
        'status' => 'active',
        'qr_status' => 'connected',
        'monitoring_policy_accepted' => 1,
        'assigned_at' => '2026-07-13 09:00:00',
    ],
    [
        'id' => 102,
        'employee_name' => 'Test nəzarətçi',
        'employee_role' => 'monitor',
        'phone_number' => '051 200 00 02',
        'whatsapp_account_name' => 'Test WhatsApp 2',
        'assigned_area_id' => 'Astara',
        'status' => 'inactive',
        'qr_status' => 'not_connected',
        'monitoring_policy_accepted' => 0,
        'assigned_at' => '2026-07-13 09:05:00',
    ],
];
$devices = [
    [
        'id' => 301,
        'device_name' => 'Test Android 01',
        'device_code' => 'WA-TEST-01',
        'employee_name' => 'Test operator',
        'phone_number' => '051 200 00 01',
        'area_id' => 'Bakı',
        'status' => 'online',
        'last_seen_at' => '2026-07-13 10:30:00',
        'open_case_count' => 1,
        'monitoring_policy_accepted' => 1,
        'monitoring_enabled' => 1,
        'stream_url' => '',
    ],
    [
        'id' => 302,
        'device_name' => 'Test iPhone 02',
        'device_code' => 'WA-TEST-02',
        'employee_name' => 'Test nəzarətçi',
        'phone_number' => '051 200 00 02',
        'area_id' => 'Astara',
        'status' => 'warning',
        'last_seen_at' => '2026-07-13 10:25:00',
        'open_case_count' => 2,
        'monitoring_policy_accepted' => 0,
        'monitoring_enabled' => 1,
        'stream_url' => '',
    ],
];
$cases = [
    [
        'id' => 401,
        'case_type' => 'complaint',
        'priority' => 'critical',
        'status' => 'waiting_employee',
        'device_name' => 'Test Android 01',
        'employee_name' => 'Test operator',
        'assigned_name' => 'Test nəzarətçi',
        'customer_name' => 'Test müştəri',
        'subscriber_code' => '100001',
        'customer_phone' => '050 111 11 11',
        'opened_at' => '2026-07-13 10:20:00',
        'notes' => 'Cavab gözləyən test monitorinq qeydi.',
        'ticket_no' => 'CRM-TEST-1',
    ],
    [
        'id' => 402,
        'case_type' => 'technical',
        'priority' => 'normal',
        'status' => 'resolved',
        'device_name' => 'Test iPhone 02',
        'employee_name' => 'Test nəzarətçi',
        'assigned_name' => 'Test operator',
        'customer_name' => 'Test müştəri 2',
        'subscriber_code' => '100002',
        'customer_phone' => '050 222 22 22',
        'opened_at' => '2026-07-13 09:30:00',
        'notes' => 'Həll edilmiş test monitorinq qeydi.',
        'ticket_no' => '',
    ],
];
$settings = [
    'default_grid_columns' => ['description' => 'Standart sütun sayı', 'setting_value' => '4'],
    'stream_refresh_seconds' => ['description' => 'Yenilənmə intervalı (saniyə)', 'setting_value' => '10'],
];

$renderDocument($root . '/views/whatsapp-monitor/overview.php', [
    'metrics' => [
        'online_devices' => 1,
        'offline_devices' => 1,
        'active_numbers' => 1,
        'qr_connected_numbers' => 1,
        'qr_pending_numbers' => 1,
        'open_cases' => 1,
        'critical_cases' => 1,
        'tickets_created' => 1,
        'avg_response_minutes' => 12,
    ],
    'devices' => $devices,
    'cases' => $cases,
    'can_manage' => true,
], 'overview');
$renderDocument($root . '/views/whatsapp-monitor/screen-wall.php', ['devices' => $devices, 'settings' => $settings], 'screen-wall');
$renderDocument($root . '/views/whatsapp-monitor/numbers.php', ['numbers' => $numbers, 'employees' => $employees, 'can_manage' => true], 'numbers');
$renderDocument($root . '/views/whatsapp-monitor/devices.php', ['devices' => $devices, 'numbers' => $numbers, 'employees' => $employees, 'can_manage' => true], 'devices');
$renderDocument($root . '/views/whatsapp-monitor/cases.php', ['cases' => $cases, 'devices' => $devices, 'employees' => $employees], 'cases');
$renderDocument($root . '/views/whatsapp-monitor/performance.php', ['cases' => $cases], 'performance');
$renderDocument($root . '/views/whatsapp-monitor/settings.php', ['settings' => $settings, 'can_manage' => true], 'settings');
$renderDocument($root . '/views/whatsapp-monitor/audit.php', [
    'can_view_all' => true,
    'audit_rows' => [[
        'created_at' => '2026-07-13 10:35:00',
        'actor_name' => 'Test admin',
        'user_id' => 1,
        'action' => 'save_device',
        'entity_type' => 'device',
        'entity_id' => 301,
        'new_values' => '{"status":"online","device_code":"WA-TEST-01"}',
    ]],
], 'audit');

echo json_encode(['ok' => true, 'output_dir' => $outputDir], JSON_UNESCAPED_SLASHES) . PHP_EOL;
