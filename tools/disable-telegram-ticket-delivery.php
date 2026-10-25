<?php
declare(strict_types=1);

$root = '/var/www/superopticnet';
require_once $root . '/config.php';
require_once $root . '/db.php';

$db = app_db();
if (!$db instanceof PDO) {
    throw new RuntimeException('Database unavailable');
}
$statement = $db->prepare(
    'INSERT INTO mgs_runtime_settings (setting_key,setting_value,updated_by_name)
     VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by_name=VALUES(updated_by_name)'
);
$statement->execute(['channel_telegram_enabled', '0', 'SuperOptic operational policy']);

$file = $root . '/config.php';
$source = file_get_contents($file);
$needle = "function app_portal_send_ticket_to_telegram(array \$ticket): array\n{\n";
$insert = $needle . "    if (!app_message_channel_is_enabled('telegram')) {\n        return [];\n    }\n\n";
if (!is_string($source) || !str_contains($source, $needle)) {
    throw new RuntimeException('Ticket notification function not found');
}
if (!str_contains($source, "if (!app_message_channel_is_enabled('telegram'))")) {
    copy($file, $file . '.before-telegram-disable-20261021');
    file_put_contents($file, str_replace($needle, $insert, $source), LOCK_EX);
}
echo "telegram_ticket_delivery=disabled\n";
