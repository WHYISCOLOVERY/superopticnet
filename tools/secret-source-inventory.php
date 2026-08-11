<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';

/** @return array<string,string> */
function secret_source_inventory_mapping(): array
{
    return [
        'db_pass' => 'DB_PASS',
        'radius_shared_secret' => 'RADIUS_SHARED_SECRET',
        'radius_router_secret' => 'RADIUS_ROUTER_SECRET',
        'radius_sql_password' => 'RADIUS_SQL_PASSWORD',
        'radius_db_pass' => 'RADIUS_DB_PASS',
        'radius_ssh_password' => 'RADIUS_SSH_PASSWORD',
        'radius_test_password' => 'RADIUS_TEST_PASSWORD',
        'import_api_token' => 'IMPORT_API_TOKEN',
        'provider_api_token' => 'PROVIDER_API_TOKEN',
        'provider_api_hmac_secret' => 'PROVIDER_API_HMAC_SECRET',
        'copilot_api_token' => 'COPILOT_API_TOKEN',
        'recaptcha_secret' => 'RECAPTCHA_SECRET',
        'telegram_token' => 'TELEGRAM_TOKEN',
        'telegram_network_bot_token' => 'TELEGRAM_NETWORK_BOT_TOKEN',
        'telegram_customer_bot_token' => 'TELEGRAM_CUSTOMER_BOT_TOKEN',
        'telegram_finance_bot_token' => 'TELEGRAM_FINANCE_BOT_TOKEN',
        'telegram_warehouse_bot_token' => 'TELEGRAM_WAREHOUSE_BOT_TOKEN',
        'telegram_backup_bot_token' => 'TELEGRAM_BACKUP_BOT_TOKEN',
        'telegram_diagnostics_bot_token' => 'TELEGRAM_DIAGNOSTICS_BOT_TOKEN',
        'telegram_modem_search_bot_token' => 'TELEGRAM_MODEM_SEARCH_BOT_TOKEN',
        'sms_provider_public_key' => 'SMS_PROVIDER_PUBLIC_KEY',
        'sms_provider_private_key' => 'SMS_PROVIDER_PRIVATE_KEY',
        'sms_provider_password' => 'SMS_PROVIDER_PRIVATE_KEY',
        'sms_provider_token' => 'SMS_PROVIDER_PRIVATE_KEY',
        'whatsapp_provider_token' => 'WHATSAPP_PROVIDER_TOKEN',
        'whatsapp_provider_api_key' => 'WHATSAPP_PROVIDER_TOKEN',
        'api_secret_key' => 'API_SECRET_KEY',
        'pairing_worker_token' => 'PAIRING_WORKER_TOKEN',
        'deepseek_api_key' => 'DEEPSEEK_API_KEY',
        'billing_sso_shared_secret' => 'BILLING_SSO_SHARED_SECRET',
        'mygov_client_secret' => 'MYGOV_CLIENT_SECRET',
        'mgs_service_shared_secret' => 'MGS_SERVICE_SHARED_SECRET',
        'mgs_service_secret' => 'MGS_SERVICE_SECRET',
        'mgs_jwt_secret' => 'MGS_JWT_SECRET',
        'mgs_secrets_master_key' => 'MGS_SECRETS_MASTER_KEY',
        'mgs_portal_shared_secret' => 'MGS_PORTAL_SHARED_SECRET',
        'mgs_telegram_webhook_secret' => 'MGS_TELEGRAM_WEBHOOK_SECRET',
        'olt_sso_shared_secret' => 'OLT_SSO_SHARED_SECRET',
        'olt_user_sync_secret' => 'OLT_USER_SYNC_SECRET',
        'clickhouse_password' => 'CLICKHOUSE_PASSWORD',
    ];
}

function secret_source_inventory_env_value(string $name): string
{
    $value = getenv($name, true);
    if ($value === false) {
        $value = getenv($name);
    }
    return is_string($value) ? trim($value) : '';
}

/** @return array<string,mixed> */
function secret_source_inventory_record(string $source, string $key, string $value, array $mapping): array
{
    $normalizedKey = strtolower(trim($key));
    $envKey = (string) ($mapping[$normalizedKey] ?? '');
    $envValue = $envKey !== '' ? secret_source_inventory_env_value($envKey) : '';

    return [
        'source' => $source,
        'key' => $normalizedKey,
        'environment_key' => $envKey !== '' ? $envKey : null,
        'environment_configured' => $envValue !== '',
        'environment_matches_legacy' => $envValue !== '' && hash_equals($value, $envValue),
        'migration_ready' => $envKey !== '' && $envValue !== '',
    ];
}

/**
 * @return array{entries:list<array{source:string,key:string,value:string}>,db_available:bool,db_error:?string}
 */
function secret_source_inventory_collect_legacy(?PDO $db = null): array
{
    $mapping = secret_source_inventory_mapping();
    $entries = [];
    $localConfig = app_local_config();
    foreach ($mapping as $configKey => $envKey) {
        $value = trim((string) ($localConfig[$configKey] ?? ''));
        if ($value !== '') {
            $entries[] = ['source' => 'config.local.php', 'key' => $configKey, 'value' => $value];
        }
    }

    $db ??= app_db();
    $dbAvailable = $db instanceof PDO;
    $dbError = null;
    if ($db instanceof PDO) {
        try {
            $rows = $db->query(
                "SELECT setting_key, setting_value
                 FROM mgs_runtime_settings
                 WHERE setting_value <> ''
                   AND setting_key REGEXP '(token|password|secret|private_key|api_key)'
                 ORDER BY setting_key"
            )->fetchAll() ?: [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $entries[] = [
                    'source' => 'mgs_runtime_settings',
                    'key' => strtolower(trim((string) ($row['setting_key'] ?? ''))),
                    'value' => (string) ($row['setting_value'] ?? ''),
                ];
            }
        } catch (Throwable) {
            $dbError = 'runtime_settings_query_failed';
        }
    }

    return [
        'entries' => $entries,
        'db_available' => $dbAvailable,
        'db_error' => $dbError,
    ];
}

if (defined('APP_SECRET_SOURCE_INVENTORY_LIBRARY_ONLY') && APP_SECRET_SOURCE_INVENTORY_LIBRARY_ONLY === true) {
    return;
}

$mapping = secret_source_inventory_mapping();
$records = [];
$collected = secret_source_inventory_collect_legacy();
foreach ($collected['entries'] as $entry) {
    $records[] = secret_source_inventory_record(
        $entry['source'],
        $entry['key'],
        $entry['value'],
        $mapping
    );
}

$unmapped = array_values(array_map(
    static fn (array $record): string => (string) $record['key'],
    array_filter($records, static fn (array $record): bool => $record['environment_key'] === null)
));
$notReady = array_values(array_map(
    static fn (array $record): string => (string) $record['key'],
    array_filter($records, static fn (array $record): bool => empty($record['migration_ready']))
));

$result = [
    'ok' => $collected['db_error'] === null,
    'db_available' => $collected['db_available'],
    'db_error' => $collected['db_error'],
    'summary' => [
        'legacy_sources' => count($records),
        'migration_ready' => count($records) - count($notReady),
        'not_ready' => count($notReady),
        'unmapped' => count($unmapped),
    ],
    'records' => $records,
    'not_ready_keys' => $notReady,
    'unmapped_keys' => $unmapped,
    'secrets_exposed' => false,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
