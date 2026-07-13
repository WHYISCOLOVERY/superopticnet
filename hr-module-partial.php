<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/hr-module-lib.php';

$user = billing_require_employee(false, 'hr');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div class="portal-alert is-error">HR melumat bazasi aktiv deyil.</div>';
    exit;
}

$hrContext = app_hr_build_context($db, $user, $_GET);
header('Content-Type: text/html; charset=UTF-8');

if ((string) ($_GET['hr_fragment'] ?? '') === 'statistics-results' && ($hrContext['tab'] ?? '') === 'statistics') {
    $hrStatistics = (array) ($hrContext['data']['statistics'] ?? []);
    $statisticsDatasets = (array) ($hrStatistics['datasets'] ?? []);
    $selectedMetric = clean_text((string) ($_GET['hr_metric'] ?? 'all'));
    if ($selectedMetric !== 'all' && !array_key_exists($selectedMetric, $statisticsDatasets)) {
        $selectedMetric = 'all';
    }
    require __DIR__ . '/views/hr/_statistics-results.php';
    exit;
}

require __DIR__ . '/views/hr/module.php';
