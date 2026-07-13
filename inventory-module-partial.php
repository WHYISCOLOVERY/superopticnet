<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/inventory-module-lib.php';

$user = billing_require_employee(false, 'inventory');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div class="portal-alert is-error">Anbar melumat bazasi aktiv deyil.</div>';
    exit;
}

$inventoryContext = app_inventory_build_context($db, $user, $_GET);
header('Content-Type: text/html; charset=UTF-8');

if ((string) ($_GET['inventory_fragment'] ?? '') === 'statistics-results' && ($inventoryContext['tab'] ?? '') === 'statistics') {
    $inventoryTab = 'statistics';
    $inventoryLang = (string) ($inventoryContext['lang'] ?? 'az');
    $inventoryFormat = static function (mixed $value, string $kind = 'quantity'): string {
        $number = (float) $value;
        return $kind === 'count' ? number_format($number, 0, '.', ' ') : number_format($number, 2, '.', ' ') . ($kind === 'meters' ? ' m' : '');
    };
    $inventoryStatistics = (array) ($inventoryContext['data']['statistics'] ?? []);
    $statisticsDatasets = (array) ($inventoryStatistics['datasets'] ?? []);
    $selectedMetric = clean_text((string) ($_GET['inventory_metric'] ?? 'all'));
    if ($selectedMetric !== 'all' && !array_key_exists($selectedMetric, $statisticsDatasets)) {
        $selectedMetric = 'all';
    }
    require __DIR__ . '/views/inventory/_statistics-results.php';
    exit;
}

require __DIR__ . '/views/inventory/module.php';
