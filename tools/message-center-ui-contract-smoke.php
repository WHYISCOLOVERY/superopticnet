<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/message-center.php');
$script = (string) file_get_contents($root . '/script/message-center.js');
$style = (string) file_get_contents($root . '/css/message-center.css');
$errors = [];

$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$expect(str_contains($page, "['overview', 'single', 'group', 'templates', 'history', 'audit', 'test', 'settings']"), 'settings_view_not_allowed');
$expect(str_contains($page, "in_array(\$messageView, ['group', 'test', 'settings'], true) && !app_portal_is_admin(\$user)"), 'restricted_view_guard_missing');
$expect(str_contains($page, "app_portal_is_admin(\$user) && \$messageView === 'settings'"), 'settings_admin_render_guard_missing');
$expect(str_contains($page, "\$messageView === 'settings'\n    ? app_communication_whatsapp_bridge_qr()"), 'qr_not_scoped_to_settings');
$expect(str_contains($page, "app_asset('css/message-center.css')"), 'message_css_not_loaded');
$expect(str_contains($page, "app_asset('script/message-center.js')"), 'message_js_not_loaded');

foreach ([
    'send_single',
    'send_group',
    'provider_test',
    'save_sms_provider_credentials',
    'retry_message',
    'bulk_retry_failed',
    'update_whatsapp_guard',
] as $action) {
    $expect(str_contains($page, 'name="action" value="' . $action . '"'), 'action_missing:' . $action);
}

$expect(substr_count($page, 'name="csrf_token"') === 7, 'csrf_field_count_changed');
$expect(substr_count($page, 'data-message-auto-filter') === 2, 'automatic_filter_hooks_missing');
$expect(str_contains($page, 'data-message-customer-code='), 'customer_prefill_hook_missing');
$expect(substr_count($page, '<details class="message-details">') === 4, 'technical_details_count_changed');
$expect(!str_contains($page, '<details class="message-details" open'), 'technical_details_open_by_default');
$expect(substr_count($page, 'data-label=') >= 25, 'mobile_table_labels_missing');

foreach ([
    'nextShell?.dataset.messageView || viewFromUrl(url)',
    'initializeContent()',
    'data-message-auto-filter',
    'form?.requestSubmit()',
    'data-message-customer-code',
    "shell.setAttribute('aria-busy', 'true')",
    "shell.removeAttribute('aria-busy')",
] as $marker) {
    $expect(str_contains($script, $marker), 'script_marker_missing:' . $marker);
}

foreach ([
    '.message-settings-section',
    '.message-customer-result',
    '.message-details',
    '@media (max-width: 560px)',
] as $marker) {
    $expect(str_contains($style, $marker), 'style_marker_missing:' . $marker);
}

foreach (['Ãƒ', 'Ã‚', 'Ã‰', 'Ã…', 'Ã†', 'Ã„', '�'] as $marker) {
    $expect(!str_contains($page . $script . $style, $marker), 'mojibake_found:' . $marker);
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
