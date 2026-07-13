<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../mgs-integration-lib.php';

$db = app_db();
if ($db instanceof PDO && app_mgs_ensure_schema($db)) {
    $sync = app_mgs_sync_telegram_bot_registry($db);
    echo 'registry: synced=' . (int) ($sync['synced'] ?? 0)
        . ', configured=' . (int) ($sync['configured'] ?? 0)
        . PHP_EOL;
}

$bots = ['network', 'customer', 'finance', 'warehouse', 'backup', 'diagnostics', 'modem_search', 'superpilot'];

foreach ($bots as $bot) {
    $config = app_mgs_telegram_bot_config($bot);
    echo $bot
        . ': configured=' . (!empty($config['configured']) ? 'yes' : 'no')
        . ', chat=' . (string) ($config['destination_masked'] ?? '-')
        . ', token_fallback=' . (!empty($config['fallback_token_used']) ? 'yes' : 'no')
        . ', chat_fallback=' . (!empty($config['fallback_chat_allowed']) ? 'yes' : 'no')
        . ', reason=' . ((string) ($config['reason'] ?? '') ?: '-')
        . PHP_EOL;
}
