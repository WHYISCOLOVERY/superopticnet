<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

app_session_boot();
$result = app_db_sync_all_current_state();

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}

echo $result
    ? "Super Optik portal data synchronized to MySQL successfully.\n"
    : "Super Optik portal data could not be synchronized to MySQL.\n";
