<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$failures = [];
$passes = [];

function stage2_check(string $name, bool $condition, array &$passes, array &$failures, string $detail = ''): void
{
    if ($condition) {
        $passes[] = $name;
        return;
    }
    $failures[] = $name . ($detail !== '' ? ' (' . $detail . ')' : '');
}

// 1. Shared asset files exist.
$css = @file_get_contents($root . '/css/design-system.css') ?: '';
$js = @file_get_contents($root . '/script/design-system.js') ?: '';
$lib = @file_get_contents($root . '/design-system-lib.php') ?: '';
stage2_check('design-system.css exists', $css !== '', $passes, $failures);
stage2_check('design-system.js exists', $js !== '', $passes, $failures);
stage2_check('design-system-lib.php exists', $lib !== '', $passes, $failures);

// 2. Token and component marker coverage in CSS.
foreach ([
    '--ui-color-primary', '--ui-color-danger', '--ui-color-success',
    '--ui-space-', '--ui-radius-',
    '.ui-button', '.ui-dialog', '.ui-toast', '.ui-state', '.ui-overlay',
] as $marker) {
    stage2_check('css marker ' . $marker, str_contains($css, $marker), $passes, $failures);
}

// 3. Runtime contract markers in JS.
foreach ([
    'data-ui-confirm', 'data-ui-toast', 'data-ui-open', 'data-ui-close',
    'SuperOpticUI', 'aria-modal', 'beforeunload',
] as $marker) {
    stage2_check('js marker ' . $marker, str_contains($js, $marker), $passes, $failures);
}

// 4. Pilot pages load the shared foundation.
$pilots = ['portal.php', 'admin-users.php', 'billing-customers.php', 'operations-center.php'];
foreach ($pilots as $pilot) {
    $content = @file_get_contents($root . '/' . $pilot) ?: '';
    stage2_check($pilot . ' loads design-system.css', str_contains($content, 'css/design-system.css'), $passes, $failures);
    stage2_check($pilot . ' loads design-system.js', str_contains($content, 'script/design-system.js'), $passes, $failures);
    stage2_check($pilot . ' emits body attribute', str_contains($content, 'app_design_system_body_attribute'), $passes, $failures);
}

// 5. Native confirm() must be gone from product PHP pages.
$nativeConfirms = [];
foreach (glob($root . '/*.php') ?: [] as $file) {
    $content = (string) file_get_contents($file);
    if (preg_match('/onsubmit\s*=\s*"[^"]*\bconfirm\s*\(/i', $content) === 1
        || preg_match('/onclick\s*=\s*"[^"]*\bconfirm\s*\(/i', $content) === 1) {
        $nativeConfirms[] = basename($file);
    }
}
stage2_check('native confirm() count is zero', $nativeConfirms === [], $passes, $failures, implode(',', $nativeConfirms));

// 6. Stage 2 core files add no inline style attributes.
foreach (['design-system-lib.php'] as $file) {
    $content = (string) file_get_contents($root . '/' . $file);
    stage2_check($file . ' has no inline style', !str_contains($content, ' style='), $passes, $failures);
}
stage2_check('design-system.js has no inline style writes', !str_contains($js, '.style.'), $passes, $failures);

// 7. Migration file contract.
$migration = @file_get_contents($root . '/db/migrations/20260723_030_stage2_design_system.php') ?: '';
stage2_check('stage2 migration exists', $migration !== '', $passes, $failures);
stage2_check('stage2 migration seeds disabled flag', str_contains($migration, 'stage2_design_system'), $passes, $failures);

// 8. Cutover tool contract.
$cutover = @file_get_contents($root . '/tools/stage2-feature-cutover.php') ?: '';
stage2_check('cutover tool exists', $cutover !== '', $passes, $failures);
stage2_check('cutover tool guards migration', str_contains($cutover, 'stage2_migration_not_ready'), $passes, $failures);

// 9. Optional DB checks when a database is reachable.
require_once $root . '/design-system-lib.php';
require_once $root . '/db.php';
$db = function_exists('app_db') ? app_db() : null;
if ($db instanceof PDO) {
    try {
        $statement = $db->query(
            'SELECT COUNT(*) FROM app_feature_flags WHERE flag_key = "stage2_design_system"'
        );
        stage2_check('feature flag row seeded', (int) $statement->fetchColumn() === 1, $passes, $failures);
        stage2_check(
            'body attribute matches flag state',
            (app_design_system_body_attribute($db) !== '') === app_design_system_enabled($db),
            $passes,
            $failures
        );
    } catch (Throwable $error) {
        $failures[] = 'db check failed (' . $error->getMessage() . ')';
    }
} else {
    $passes[] = 'db checks skipped (database unavailable)';
}

echo 'stage2_contract_pass=' . count($passes) . PHP_EOL;
if ($failures !== []) {
    echo 'stage2_contract_fail=' . count($failures) . PHP_EOL;
    foreach ($failures as $failure) {
        echo 'FAIL: ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'stage2_contract_fail=0' . PHP_EOL;
exit(0);
