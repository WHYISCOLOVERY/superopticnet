<?php
declare(strict_types=1);

require_once __DIR__ . '/ai-module-lib.php';

$user = billing_require_employee(false, 'ai');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    exit('<div class="portal-alert is-error">AI melumat bazasi aktiv deyil.</div>');
}
$aiSection = app_ai_section($_GET);
if (!app_ai_section_allowed($user, $aiSection)) {
    http_response_code(403);
    exit('<div class="portal-alert is-error">Bu AI bölməsi üçün icazəniz yoxdur.</div>');
}
$aiContext = app_ai_build_context($db, $user, $aiSection, $_GET);
header('Content-Type: text/html; charset=UTF-8');
require __DIR__ . '/views/ai/' . $aiSection . '.php';
