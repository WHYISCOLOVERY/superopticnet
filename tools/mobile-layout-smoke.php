<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$root = dirname(__DIR__);
$css = file_get_contents($root . '/css/portal.css');
$portalJs = file_get_contents($root . '/script/portal.js');
$copilot = file_get_contents($root . '/copilot-widget.php');
$customers = file_get_contents($root . '/billing-customers.php');
$adminUsers = file_get_contents($root . '/admin-users.php');
$crm = file_get_contents($root . '/crm.php');
$radiusActive = file_get_contents($root . '/radius-active.php');
$radiusLogs = file_get_contents($root . '/radius-logs.php');
$operations = file_get_contents($root . '/operations-center.php');
$nocDevices = file_get_contents($root . '/noc-devices.php');

$checks = [
    'mobile_body_no_horizontal_drift' => is_string($css)
        && preg_match('/@media\s*\(max-width:\s*760px\)[\s\S]*?body\s*\{[\s\S]*?overflow-x:\s*hidden/s', $css) === 1,
    'mobile_table_card_mode' => is_string($css)
        && str_contains($css, '.portal-table td::before')
        && str_contains($css, 'content: attr(data-label)')
        && preg_match('/\.portal-table\s+thead\s*\{[\s\S]*?display:\s*none/s', $css) === 1,
    'mobile_table_labels_js' => is_string($portalJs)
        && str_contains($portalJs, 'syncMobileTables')
        && str_contains($portalJs, 'cell.dataset.label')
        && str_contains($portalJs, 'superoptic:table-refresh'),
    'mobile_nav_scrollbars_hidden' => is_string($css)
        && str_contains($css, '.billing-shell-nav::-webkit-scrollbar')
        && str_contains($css, '.billing-nav-group[open] .billing-nav-links::-webkit-scrollbar')
        && preg_match('/\.billing-shell-nav\s*\{[\s\S]*?scrollbar-width:\s*none/s', $css) === 1,
    'mobile_action_chips_fit_viewport' => is_string($css)
        && preg_match('/@media\s*\(max-width:\s*760px\)[\s\S]*?\.mgs-command-actions,[\s\S]*?\.mgs-inline-actions\s*\{[\s\S]*?max-width:\s*100%/s', $css) === 1
        && preg_match('/@media\s*\(max-width:\s*760px\)[\s\S]*?\.mgs-command-actions,[\s\S]*?\.mgs-inline-actions\s*\{[\s\S]*?grid-template-columns:\s*1fr/s', $css) === 1
        && str_contains($css, '.mgs-command-actions > *')
        && str_contains($css, 'width: 100% !important'),
    'superpilot_bottom_sheet_mobile' => is_string($copilot)
        && str_contains($copilot, '@media (max-width:680px)')
        && str_contains($copilot, '.billing-copilot-panel{left:8px;right:8px;top:auto;bottom:8px;width:auto'),
    'customer_create_mobile_modal' => is_string($customers)
        && str_contains($customers, '@media (max-width:700px)')
        && str_contains($customers, 'max-height:calc(100dvh - 78px)')
        && str_contains($customers, '.billing-create-panel .mgs-wizard-steps'),
    'no_mobile_column_hiding' => is_string($adminUsers)
        && is_string($crm)
        && is_string($radiusActive)
        && is_string($radiusLogs)
        && !preg_match('/@media\s*\(max-width:\s*7[02]0px\)[\s\S]*?td:nth-child\([^)]*\)\s*\{[^}]*display:\s*none/s', $adminUsers . $crm . $radiusActive . $radiusLogs),
    'mobile_monitor_list_mode' => is_string($operations)
        && is_string($nocDevices)
        && str_contains($operations, '@media (max-width:760px)')
        && str_contains($operations, '.monitor-stage{position:static;inset:auto;display:grid;grid-template-columns:1fr;gap:12px;transform:none!important}')
        && str_contains($nocDevices, '@media (max-width:760px)')
        && str_contains($nocDevices, '.monitor-stage{position:static;inset:auto;display:grid;grid-template-columns:1fr;gap:12px;transform:none!important}'),
];

$ok = !in_array(false, $checks, true);

echo json_encode([
    'status' => $ok ? 'ok' : 'failed',
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($ok ? 0 : 2);
