<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

putenv('COMMUNICATION_EXTERNAL_SEND_ENABLED=0');

require_once dirname(__DIR__) . '/communication-queue-lib.php';
require_once dirname(__DIR__) . '/mgs-integration-lib.php';

$root = dirname(__DIR__);
$errors = [];
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$catalog = app_portal_module_catalog('az');
$expect(isset($catalog['crm']), 'crm_module_missing');
$expect(($catalog['crm']['href'] ?? '') === 'crm.php', 'crm_module_route_invalid');
$expect(($catalog['statistics']['label'] ?? '') === 'Sirket statistikasi', 'company_statistics_label_missing');
$expect(app_portal_request_module('crm.php', []) === 'crm', 'crm_permission_route_missing');
$expect(app_portal_request_module('problem-statistics.php', []) === 'crm', 'ticket_analytics_permission_route_missing');

$explicitMessagingUser = [
    'role' => 'employee',
    'account_type' => 'operator',
    'employee_role' => 'operator',
    'module_keys' => ['messaging'],
];
$expect(
    app_portal_employee_module_keys($explicitMessagingUser) === ['crm', 'messaging'],
    'legacy_messaging_permission_did_not_gain_crm_alias'
);

$statisticsPage = (string) file_get_contents($root . '/statistics-center.php');
$messageCenter = (string) file_get_contents($root . '/message-center.php');
$crmPage = (string) file_get_contents($root . '/crm.php');
$botPage = (string) file_get_contents($root . '/mgs-integration.php');
$providerSource = (string) file_get_contents($root . '/communication-provider.php');
$workerSource = (string) file_get_contents($root . '/tools/process-communication-queue.php');

$expect(str_contains($statisticsPage, "'company-statistics'"), 'company_statistics_still_uses_billing_nav');
$expect(!str_contains($statisticsPage, "billing_render_header('Statistika', 'statistics'"), 'company_statistics_old_billing_header_remains');
$expect(str_contains($messageCenter, 'CRM ve ticketler'), 'message_center_crm_link_missing');
$expect(str_contains($messageCenter, 'mgs-integration.php?view=bots'), 'message_center_telegram_admin_link_missing');
$expect(str_contains($messageCenter, 'message-history-details'), 'message_history_detail_missing');
$expect(str_contains($messageCenter, 'Portal bildirişi yaradılıb'), 'internal_portal_status_label_missing');
$expect(str_contains($messageCenter, 'COMMUNICATION_EXTERNAL_SEND_ENABLED=1'), 'external_send_hold_explanation_missing');
$expect(str_contains($crmPage, 'Kohne ticketler'), 'legacy_ticket_link_missing');
$expect(str_contains($crmPage, 'Ticket analitikasi'), 'ticket_analytics_link_missing');
$expect(str_contains($crmPage, '$recentCustomers === [] && app_demo_fixtures_enabled()'), 'crm_production_mock_guard_missing');
$expect(str_contains($botPage, 'Telegram botunu yenilə'), 'telegram_bot_edit_mode_missing');
$expect(str_contains($botPage, 'Registry-ni yenilə'), 'telegram_registry_sync_missing');
$expect(str_contains($providerSource, 'function app_communication_external_delivery_enabled'), 'external_delivery_gate_missing');
$expect(str_contains($workerSource, "'paused' => 0"), 'worker_paused_counter_missing');

$smsResult = app_communication_send_provider('sms', '994501234567', 'NO EXTERNAL REQUEST');
$expect(empty($smsResult['ok']), 'paused_sms_request_was_allowed');
$expect(($smsResult['reason'] ?? '') === 'external_delivery_paused', 'paused_sms_reason_invalid');

$telegramResult = app_communication_queue_send_provider_for_message(
    ['channel' => 'telegram', 'message_body' => 'NO EXTERNAL REQUEST'],
    [
        'channel' => 'telegram',
        'configured' => true,
        'delivery' => 'telegram_bot',
        'endpoint' => 'https://127.0.0.1/never-call',
        'destination' => '-1000000000000',
    ]
);
$expect(empty($telegramResult['ok']), 'paused_telegram_request_was_allowed');
$expect(($telegramResult['reason'] ?? '') === 'external_delivery_paused', 'paused_telegram_reason_invalid');
$expect(($telegramResult['provider_status'] ?? '') === 'pending', 'paused_telegram_not_left_pending');

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
    'real_provider_requests' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
