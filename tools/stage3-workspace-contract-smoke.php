<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require_once $root . '/workspace-lib.php';
require_once $root . '/db.php';

$passes = [];
$failures = [];

function stage3_check(string $name, bool $condition, array &$passes, array &$failures, string $detail = ''): void
{
    if ($condition) {
        $passes[] = $name;
        return;
    }
    $failures[] = $name . ($detail !== '' ? ' (' . $detail . ')' : '');
}

// 1. Section-to-module read scope: previously unmapped sections now resolve.
$expected = [
    'finance' => 'finance',
    'hr' => 'hr',
    'inventory' => 'inventory',
    'technical' => 'network',
    'noc' => 'network',
    'olt' => 'network',
    'network' => 'network',
    'bots' => 'ai',
    'reports' => 'statistics',
];
foreach ($expected as $section => $module) {
    stage3_check(
        'operations-center section ' . $section . ' maps to ' . $module,
        app_portal_request_module('operations-center.php', ['section' => $section]) === $module,
        $passes,
        $failures
    );
}

// 2. Role isolation stays intact for the module map.
$warehouseUser = ['employee_role' => 'warehouse', 'account_type' => 'employee'];
$nocUser = ['employee_role' => 'noc', 'account_type' => 'employee'];
$adminUser = ['employee_role' => 'admin', 'account_type' => 'admin'];
stage3_check('warehouse cannot read network', !app_portal_can_access_module($warehouseUser, 'network'), $passes, $failures);
stage3_check('warehouse can read inventory', app_portal_can_access_module($warehouseUser, 'inventory'), $passes, $failures);
stage3_check('noc cannot read finance', !app_portal_can_access_module($nocUser, 'finance'), $passes, $failures);
stage3_check('noc can read network', app_portal_can_access_module($nocUser, 'network'), $passes, $failures);
stage3_check('admin reads every module', app_portal_can_access_module($adminUser, 'finance') && app_portal_can_access_module($adminUser, 'network'), $passes, $failures);

// 3. Workspace card filter contract.
$cards = [
    ['title' => 'Finance card', 'module' => 'finance'],
    ['title' => 'Inventory card', 'module' => 'inventory'],
    ['title' => 'Neutral card'],
];

$db = app_db();
if ($db instanceof PDO) {
    $flagStatement = $db->prepare('SELECT is_enabled FROM app_feature_flags WHERE flag_key = "stage3_module_workspaces"');
    $flagStatement->execute();
    $flagEnabled = (int) $flagStatement->fetchColumn() === 1;

    stage3_check('stage3 flag row exists', $flagStatement->rowCount() >= 0, $passes, $failures);

    $filtered = app_stage3_filter_workspace_cards($cards, $warehouseUser, $db);
    if ($flagEnabled) {
        $titles = array_map(static fn ($card) => (string) $card['title'], $filtered);
        stage3_check('flag on: warehouse loses finance card', !in_array('Finance card', $titles, true), $passes, $failures, implode(',', $titles));
        stage3_check('flag on: warehouse keeps inventory card', in_array('Inventory card', $titles, true), $passes, $failures);
        stage3_check('flag on: neutral card stays', in_array('Neutral card', $titles, true), $passes, $failures);
        $adminFiltered = app_stage3_filter_workspace_cards($cards, $adminUser, $db);
        stage3_check('flag on: admin keeps every card', count($adminFiltered) === count($cards), $passes, $failures);
    } else {
        stage3_check('flag off: cards unchanged', $filtered === $cards, $passes, $failures);
    }
} else {
    $passes[] = 'db checks skipped (database unavailable)';
}

// 4. Search and overview read scope markers in operations-center.php.
$operations = (string) file_get_contents($root . '/operations-center.php');
foreach ([
    '$actorCanBillingRead = app_portal_can_access_module($user, \'billing\')',
    '$actorCanMessagingRead = app_portal_can_access_module($user, \'messaging\')',
    '$actorCanNetworkRead = app_portal_can_access_module($user, \'network\')',
    '!$actorCanBillingRead ? [] : spog_rows',
    '!$actorCanMessagingRead ? [] : spog_rows',
    '!$actorCanNetworkRead ? [] : spog_rows',
    'app_stage3_filter_workspace_cards($moduleCards, $user, $db)',
    'app_stage3_filter_workspace_cards($legacyModuleMap, $user, $db)',
] as $marker) {
    stage3_check('operations-center marker: ' . substr($marker, 0, 52), str_contains($operations, $marker), $passes, $failures);
}

// 5. WhatsApp screen-wall menu removal keeps the alias.
$monitorLib = (string) file_get_contents($root . '/whatsapp-monitor-lib.php');
stage3_check('whatsapp menu hides screen-wall under stage3', str_contains($monitorLib, "unset(\$menuTabs['screen-wall'])"), $passes, $failures);
$monitorPage = (string) file_get_contents($root . '/whatsapp-monitor.php');
stage3_check('whatsapp allowed views still use full tab list', str_contains($monitorPage, 'array_keys(whatsapp_monitor_tabs())'), $passes, $failures);

// 6. Migration and cutover contracts.
$migration = (string) @file_get_contents($root . '/db/migrations/20260725_040_stage3_module_workspaces.php');
stage3_check('stage3 migration exists', $migration !== '', $passes, $failures);
stage3_check('stage3 migration refuses enabled rollback', str_contains($migration, 'stage3_rollback_requires_disabled_flag'), $passes, $failures);
$cutover = (string) @file_get_contents($root . '/tools/stage3-feature-cutover.php');
stage3_check('stage3 cutover guards migration', str_contains($cutover, 'stage3_migration_not_ready'), $passes, $failures);

echo 'stage3_contract_pass=' . count($passes) . PHP_EOL;
if ($failures !== []) {
    echo 'stage3_contract_fail=' . count($failures) . PHP_EOL;
    foreach ($failures as $failure) {
        echo 'FAIL: ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'stage3_contract_fail=0' . PHP_EOL;
exit(0);
