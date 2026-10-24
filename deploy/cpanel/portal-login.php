<?php
declare(strict_types=1);

$lang = strtolower(trim((string) ($_GET['lang'] ?? 'az')));
$query = in_array($lang, ['en', 'ru'], true) ? '?lang=' . rawurlencode($lang) : '';
header('Location: https://mgt.superoptic.net/portal-login.php' . $query, true, 302);
exit;
