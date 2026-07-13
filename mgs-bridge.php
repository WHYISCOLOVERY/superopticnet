<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$user = billing_require_employee();
$lang = app_lang();
$moduleKey = strtolower(clean_text((string) ($_GET['module'] ?? 'monitor')));
$targetUrl = app_mgs_native_module_url($moduleKey);

if ($lang !== 'az') {
    $separator = str_contains($targetUrl, '?') ? '&' : '?';
    $targetUrl .= $separator . 'lang=' . rawurlencode($lang);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $targetUrl, true, 302);
exit;
