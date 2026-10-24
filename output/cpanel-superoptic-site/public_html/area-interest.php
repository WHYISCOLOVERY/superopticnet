<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php#coverage');
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
$district = clean_text($_POST['district'] ?? '');
$lang = $_POST['lang'] ?? app_lang();
$lang = is_string($lang) && in_array($lang, app_supported_langs(), true) ? $lang : 'az';

if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
    $status = 'csrf_failed';
    $query = '?status=' . rawurlencode($status) . ($lang === 'az' ? '' : '&lang=' . rawurlencode($lang));
    header('Location: index.php' . $query . '#coverage');
    exit;
}

$status = app_record_area_interest($district) ? 'area_interest_saved' : 'area_interest_failed';
$query = $lang === 'az' ? '' : '?lang=' . rawurlencode($lang) . '&status=' . rawurlencode($status);

if ($lang === 'az') {
    $query = '?status=' . rawurlencode($status);
}

header('Location: index.php' . $query . '#coverage');
exit;
