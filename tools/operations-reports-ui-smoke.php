<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$source = (string) file_get_contents(dirname(__DIR__) . '/operations-center.php');
$errors = [];
$check = static function (bool $ok, string $message) use (&$errors): void { if (!$ok) $errors[] = $message; };
$check(!str_contains($source, 'Statistics context not configured'), 'missing-context-placeholder-remains');
$check(!str_contains($source, 'native api'), 'technical-native-api-label-remains');
$check(!str_contains($source, 'api yoxlanir'), 'technical-api-check-label-remains');
foreach (['finance_tab=statistics', 'inventory_tab=statistics', 'hr_tab=statistics', 'billing-statistics.php'] as $route) {
    $check(str_contains($source, $route), 'report-route-missing:' . $route);
}
$check(str_contains($source, 'portal-report-action-panel'), 'report-action-panel-missing');
$check(str_contains($source, 'data-report-action-group="primary"'), 'report-primary-action-group-missing');
$check(str_contains($source, 'data-report-action-group="statistics"'), 'report-statistics-action-group-missing');
$check(str_contains($source, 'Modul statistikalari'), 'report-statistics-group-title-missing');
$check(str_contains($source, 'spog-backup-form .portal-primary-btn'), 'backup-button-responsive-css-missing');
echo json_encode(['status'=>$errors===[]?'passed':'failed','errors'=>$errors], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors===[]?0:1);
