<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$adminPage = (string) file_get_contents($root . '/admin-users.php');
$operationsPage = (string) file_get_contents($root . '/operations-center.php');
$portalCss = (string) file_get_contents($root . '/css/portal.css');
$adminPageCompact = preg_replace('/\s+/', '', $adminPage) ?? $adminPage;
$portalCssCompact = preg_replace('/\s+/', '', $portalCss) ?? $portalCss;

foreach ([
    'data-admin-employee-modal',
    'data-admin-modal-panel',
    'data-responsible-area-grid',
    'name="responsible_areas[]"',
    '.admin-users-tools.portal-reveal.is-visible{transform:none!important}',
] as $marker) {
    if (!str_contains($adminPage, $marker)) {
        $errors[] = 'admin_' . $marker . '_missing';
    }
}

if (str_contains($adminPage, 'select id="responsibleAreas" name="responsible_areas[]" multiple')) {
    $errors[] = 'admin_nested_multi_select_scroll_present';
}
if (str_contains($adminPage, '<details class="admin-users-modal"') || str_contains($adminPage, '<summary class="admin-users-modal-summary"')) {
    $errors[] = 'admin_native_details_modal_present';
}
if (str_contains($adminPage, '.admin-users-modal[open] .admin-users-modal-inner{max-height:')) {
    $errors[] = 'admin_modal_inner_scroll_present';
}
if (!str_contains($adminPage, '.admin-users-modal[open]{')
    || !str_contains($adminPage, 'display:flex')
    || !str_contains($adminPage, 'align-items:center')
) {
    $errors[] = 'admin_modal_overlay_layout_missing';
}
foreach ([
    'box-sizing:border-box',
    'width:100%',
    'max-width:1060px',
    '.admin-users-modal[open].admin-users-modal-summary',
    '.admin-users-modal[open].admin-users-modal-inner',
    '.admin-users-forminput',
    '.admin-users-formselect',
    '.admin-users-formtextarea',
    '.admin-area-checkspan',
] as $marker) {
    if (!str_contains($adminPageCompact, $marker)) {
        $errors[] = 'admin_layout_marker_' . $marker . '_missing';
    }
}

if (substr_count($operationsPage, 'mgs-progress-row-stacked') < 5) {
    $errors[] = 'operations_payroll_stacked_rows_missing';
}
foreach ([
    '.mgs-progress-row-stacked',
    '.mgs-progress-row-stacked .mgs-progress-track',
] as $marker) {
    if (!str_contains($portalCss, $marker)) {
        $errors[] = 'css_' . $marker . '_missing';
    }
}
foreach ([
    'grid-template-columns:1fr',
    'min-width:0',
] as $marker) {
    if (!str_contains($portalCssCompact, $marker)) {
        $errors[] = 'css_' . $marker . '_missing';
    }
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
