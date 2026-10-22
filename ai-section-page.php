<?php
declare(strict_types=1);

require_once __DIR__ . '/ai-module-lib.php';

$aiSection = app_ai_section(['ai_section' => (string) ($aiSection ?? 'overview')]);
$user = billing_require_employee(false, 'ai');
if (!app_ai_section_allowed($user, $aiSection)) {
    http_response_code(403);
    exit('Bu AI bolmesi ucun icazeniz yoxdur.');
}
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    exit('AI melumat bazasi aktiv deyil.');
}
$aiContext = app_ai_build_context($db, $user, $aiSection, $_GET);
$aiSection = (string) ($aiContext['section'] ?? 'overview');
$lang = (string) ($aiContext['lang'] ?? 'az');
$title = (string) (($aiContext['sections'] ?? [])[$aiSection] ?? 'AI');
?>
<!doctype html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title); ?> - AI</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <link rel="stylesheet" href="<?= e(app_asset('css/ai-center.css')); ?>">
</head>
<body class="portal-body">
<?php billing_render_header('AI', 'ai-' . $aiSection, $lang); ?>
<main class="portal-layout"><?php require __DIR__ . '/views/ai/module.php'; ?></main>
<script src="<?= e(app_asset('script/portal.js')); ?>"></script>
<script src="<?= e(app_asset('script/ai-center.js')); ?>"></script>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
