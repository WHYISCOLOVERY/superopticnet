<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/finance-module-lib.php';

$user = billing_require_employee(false, 'finance');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div class="portal-alert is-error">Maliyye melumat bazasi aktiv deyil.</div>';
    exit;
}

$financeContext = app_finance_build_context($db, $user, $_GET);
header('Content-Type: text/html; charset=UTF-8');

if ((string) ($_GET['finance_fragment'] ?? '') === 'statistics-results' && ($financeContext['tab'] ?? '') === 'statistics') {
    $financeStatistics = (array) ($financeContext['data']['statistics'] ?? []);
    $statisticsDatasets = (array) ($financeStatistics['datasets'] ?? []);
    $selectedMetric = clean_text((string) ($_GET['finance_metric'] ?? 'all'));
    if ($selectedMetric !== 'all' && !array_key_exists($selectedMetric, $statisticsDatasets)) {
        $selectedMetric = 'all';
    }
    require __DIR__ . '/views/finance/_statistics-results.php';
    exit;
}

require __DIR__ . '/views/finance/module.php';
