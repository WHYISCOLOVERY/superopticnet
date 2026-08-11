<?php
declare(strict_types=1);

require_once __DIR__ . '/statistics-center-lib.php';

$user = billing_require_employee(false, 'statistics');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    exit('Statistika məlumat bazası aktiv deyil.');
}
$statisticsContext = app_statistics_center_context($db, $user, $_GET);
$lang = app_lang();
?>
<!doctype html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sirket statistikasi - SuperOptik</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <link rel="stylesheet" href="<?= e(app_asset('css/statistics-center.css')); ?>">
</head>
<body class="portal-body">
<?php billing_render_header('Sirket statistikasi', 'company-statistics', $lang); ?>
<main class="portal-layout"><?php require __DIR__ . '/views/statistics/module.php'; ?></main>
<script src="<?= e(app_asset('script/portal.js')); ?>"></script>
<script src="<?= e(app_asset('script/statistics-center.js')); ?>"></script>
</body>
</html>
