<?php
declare(strict_types=1);

require_once __DIR__ . '/statistics-center-lib.php';

$user = billing_require_employee(false, 'statistics');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    exit('<div class="portal-alert is-error">Statistika məlumat bazası aktiv deyil.</div>');
}
$statisticsContext = app_statistics_center_context($db, $user, $_GET);
header('Content-Type: text/html; charset=UTF-8');

if (clean_text((string) ($_GET['statistics_fragment'] ?? '')) === 'results') {
    require __DIR__ . '/views/statistics/_results.php';
    exit;
}
require __DIR__ . '/views/statistics/module.php';
