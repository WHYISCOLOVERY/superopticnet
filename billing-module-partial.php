<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-module-lib.php';

$user = billing_require_employee(false, 'billing');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    exit('<div class="portal-alert is-error">Billing melumat bazasi aktiv deyil.</div>');
}
$billingSection = app_billing_section($_GET);
$billingContext = app_billing_build_context($db, $user, $billingSection, $_GET);
$billingLang = (string) ($billingContext['lang'] ?? 'az');
header('Content-Type: text/html; charset=UTF-8');
if ((string) ($_GET['billing_fragment'] ?? '') === 'statistics-results' && $billingSection === 'statistics') {
    $billingStatistics = (array) ($billingContext['data']['statistics'] ?? []);
    require __DIR__ . '/views/billing/_statistics-results.php';
    exit;
}
require __DIR__ . '/views/billing/' . $billingSection . '.php';
