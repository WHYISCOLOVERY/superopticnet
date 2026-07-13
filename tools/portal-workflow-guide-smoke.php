<?php
declare(strict_types=1);

require dirname(__DIR__) . '/billing-ui.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$errors = [];
$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) {
        $errors[] = $error;
    }
};

$expect(function_exists('billing_render_workflow_guide'), 'workflow_helper_missing');

$billingUi = (string) file_get_contents(dirname(__DIR__) . '/billing-ui.php');
$portal = (string) file_get_contents(dirname(__DIR__) . '/portal.php');
$whatsapp = (string) file_get_contents(dirname(__DIR__) . '/whatsapp-monitor.php');
$css = (string) file_get_contents(dirname(__DIR__) . '/css/portal.css');

foreach ([
    [$billingUi, 'portal-workflow-guide'],
    [$billingUi, 'Sade is axini'],
    [$portal, "require_once __DIR__ . '/billing-ui.php'"],
    [$portal, 'Gundelik isi buradan basladin'],
    [$portal, 'Saheni sec'],
    [$whatsapp, 'WhatsApp nomresini nezarete gotur'],
    [$whatsapp, 'QR oxut'],
    [$css, '.portal-workflow-guide'],
    [$css, '.portal-workflow-step'],
] as [$source, $marker]) {
    $expect(str_contains($source, $marker), 'marker_missing:' . $marker);
}

ob_start();
billing_render_workflow_guide('Smoke guide', [
    ['title' => 'First', 'text' => 'One', 'icon' => 'fa-check'],
    ['title' => 'Brand', 'text' => 'Two', 'icon' => 'fa-brands fa-whatsapp'],
], 'Smoke note');
$html = (string) ob_get_clean();
$expect(str_contains($html, 'portal-workflow-guide'), 'render_missing_wrapper');
$expect(str_contains($html, 'fa-solid fa-check'), 'solid_icon_class_missing');
$expect(str_contains($html, 'fa-brands fa-whatsapp'), 'brand_icon_class_missing');

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
