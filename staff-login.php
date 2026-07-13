<?php
declare(strict_types=1);

define('SUPEROPTIC_STAFF_LOGIN_ENDPOINT', true);
$_GET['role'] = $_GET['role'] ?? 'employee';

require __DIR__ . '/portal-login.php';
