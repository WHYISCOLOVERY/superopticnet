<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$errors = [];
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$operations = $read('operations-center.php');
$billing = $read('billing-section-page.php');
$statistics = $read('script/statistics-center.js');
$ai = $read('script/ai-center.js');
$message = $read('script/message-center.js');
$portal = $read('script/portal.js');

foreach ([$operations, $billing] as $index => $source) {
    $prefix = $index === 0 ? 'operations' : 'billing';
    $expect(str_contains($source, 'const isTextFilter'), $prefix . '_text_filter_guard_missing');
    $expect(str_contains($source, "['search', 'text'].includes"), $prefix . '_text_filter_types_missing');
    $expect(str_contains($source, "addEventListener('change'"), $prefix . '_change_autosubmit_missing');
}

$expect(str_contains($statistics, "dateInput.addEventListener('change'"), 'statistics_date_change_missing');
$expect(!str_contains($statistics, "dateInput.addEventListener('input'"), 'statistics_date_still_submits_while_typing');
$expect(str_contains($statistics, 'controller.abort()'), 'statistics_abort_controller_missing');

foreach (['scheduleFilter', "form.dataset.filterHistory = 'replace'", 'history.replaceState', 'AbortController'] as $marker) {
    $expect(str_contains($ai, $marker), 'ai_filter_contract_missing:' . $marker);
}
foreach (['data-message-auto-filter', 'filterTimer', 'controller.abort()', 'requestSubmit()'] as $marker) {
    $expect(str_contains($message, $marker), 'message_filter_contract_missing:' . $marker);
}

foreach (['AbortController', 'DOMParser', 'syncAutoFilterForm', "addEventListener('input'", "addEventListener('change'", "addEventListener('popstate'", 'history.replaceState'] as $marker) {
    $expect(str_contains($portal, $marker), 'legacy_ajax_filter_contract_missing:' . $marker);
}

$inputHandlerStart = strpos($portal, "document.addEventListener('input'");
$changeHandlerStart = strpos($portal, "document.addEventListener('change'", $inputHandlerStart === false ? 0 : $inputHandlerStart);
$inputCancelPosition = strpos($portal, 'cancelAutoFilterRequest();', $inputHandlerStart === false ? 0 : $inputHandlerStart);
$inputDebouncePosition = strpos($portal, 'autoFilterTimer = window.setTimeout', $inputHandlerStart === false ? 0 : $inputHandlerStart);
$changeCancelPosition = strpos($portal, 'cancelAutoFilterRequest();', $changeHandlerStart === false ? 0 : $changeHandlerStart);
$changeLoadPosition = strpos($portal, 'loadAutoFilterResults(', $changeHandlerStart === false ? 0 : $changeHandlerStart);
$inputHandlerSource = ($inputHandlerStart !== false && $changeHandlerStart !== false)
    ? substr($portal, $inputHandlerStart, $changeHandlerStart - $inputHandlerStart)
    : '';

$expect(
    $inputCancelPosition !== false
        && $inputDebouncePosition !== false
        && $inputCancelPosition < $inputDebouncePosition,
    'legacy_ajax_filter_input_does_not_abort_before_debounce'
);
$expect(
    $changeCancelPosition !== false
        && $changeLoadPosition !== false
        && $changeCancelPosition < $changeLoadPosition,
    'legacy_ajax_filter_change_does_not_abort_before_load'
);
$expect(!str_contains($inputHandlerSource, 'input[type="date"]'), 'legacy_ajax_filter_date_still_runs_on_input');

foreach (['autoFilterRequestId', 'const isCurrentRequest', 'if (!isCurrentRequest()) return;', 'signal: requestController.signal'] as $marker) {
    $expect(str_contains($portal, $marker), 'legacy_ajax_filter_stale_guard_missing:' . $marker);
}
$responseTextPosition = strpos($portal, 'const responseText = await response.text();');
$responseGuardPosition = strpos($portal, 'if (!isCurrentRequest()) return;', $responseTextPosition === false ? 0 : $responseTextPosition);
$formSyncPosition = strpos($portal, 'syncAutoFilterForm(form, parsed);', $responseTextPosition === false ? 0 : $responseTextPosition);
$expect(
    $responseTextPosition !== false
        && $responseGuardPosition !== false
        && $formSyncPosition !== false
        && $responseTextPosition < $responseGuardPosition
        && $responseGuardPosition < $formSyncPosition,
    'legacy_ajax_filter_stale_response_can_mutate_form_or_dom'
);

$legacyPages = [
    'admin-users.php' => ['admin-users-filter', 'admin-users-results'],
    'billing-customers.php' => ['billing-customers-filter', 'billing-customers-results'],
    'billing-payments.php' => ['billing-payments-filter', 'billing-payments-results'],
    'billing-requests.php' => ['billing-requests-filter', 'billing-requests-results'],
    'campaigns.php' => ['campaign-filter', 'campaign-candidates'],
    'crm.php' => ['crm-filter', 'crm-results'],
    'network-definitions.php' => ['network-definitions-filter', 'network-definitions-results'],
    'problem-statistics.php' => ['problem-statistics-filter', 'problem-tickets'],
    'radius-active.php' => ['radius-active-filter', 'radius-active-results'],
    'radius-logs.php' => ['radius-logs-filter', 'radius-logs-results'],
    'radius-natlog.php' => ['radius-nat-filter', 'radius-nat-results'],
];
foreach ($legacyPages as $path => [$formId, $resultKey]) {
    $source = $read($path);
    $expect(str_contains($source, 'id="' . $formId . '"'), 'legacy_filter_form_missing:' . $path);
    $expect(str_contains($source, 'data-auto-filter'), 'legacy_auto_filter_marker_missing:' . $path);
    $expect(str_contains($source, 'data-auto-filter-results="' . $resultKey . '"'), 'legacy_filter_result_missing:' . $path);
}

$adminUsers = $read('admin-users.php');
$customers = $read('billing-customers.php');
foreach (['focusableItems', "event.key !== 'Tab'", 'modalOpeners'] as $marker) {
    $expect(str_contains($adminUsers, $marker), 'admin_modal_a11y_missing:' . $marker);
}
foreach (['createFocusable', "event.key === 'Tab'", "document.addEventListener('click'", "event.target.closest('[data-refresh-customer]')"] as $marker) {
    $expect(str_contains($customers, $marker), 'customer_dynamic_ui_contract_missing:' . $marker);
}

if ($errors !== []) {
    echo json_encode(['status' => 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

echo json_encode([
    'status' => 'passed',
    'checks' => [
        'text_search_debounced' => true,
        'date_inputs_submit_on_change_only' => true,
        'active_requests_abort_before_debounce' => true,
        'ajax_requests_abort_stale_responses' => true,
        'automatic_filters_replace_history' => true,
        'legacy_list_pages_refresh_without_reload' => true,
        'ajax_results_keep_dynamic_actions_and_modal_focus' => true,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
