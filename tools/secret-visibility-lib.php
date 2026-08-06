<?php
declare(strict_types=1);

/** @return list<string> */
function app_service_secret_visibility_required_keys(): array
{
    return [
        'DB_PASS',
        'RADIUS_DB_PASS',
        'RADIUS_ROUTER_SECRET',
        'DEEPSEEK_API_KEY',
        'SMS_PROVIDER_PUBLIC_KEY',
        'SMS_PROVIDER_PRIVATE_KEY',
        'TELEGRAM_TOKEN',
        'TELEGRAM_NETWORK_BOT_TOKEN',
        'TELEGRAM_CUSTOMER_BOT_TOKEN',
        'TELEGRAM_FINANCE_BOT_TOKEN',
        'TELEGRAM_WAREHOUSE_BOT_TOKEN',
        'TELEGRAM_BACKUP_BOT_TOKEN',
        'TELEGRAM_DIAGNOSTICS_BOT_TOKEN',
        'TELEGRAM_MODEM_SEARCH_BOT_TOKEN',
        'WHATSAPP_PROVIDER_TOKEN',
        'RADIUS_SSH_PASSWORD',
        'RADIUS_TEST_PASSWORD',
        'SNMP_COMMUNITY',
        'IMPORT_API_TOKEN',
        'COPILOT_API_TOKEN',
        'RECAPTCHA_SECRET',
        'BILLING_SSO_SHARED_SECRET',
        'OLT_SSO_SHARED_SECRET',
        'OLT_USER_SYNC_SECRET',
        'MYGOV_CLIENT_SECRET',
        'API_SECRET_KEY',
        'PAIRING_WORKER_TOKEN',
        'CLICKHOUSE_PASSWORD',
        'PORTAL_TICKET_CLOSE_SECRET',
        'PROVIDER_API_TOKEN',
        'PROVIDER_API_HMAC_SECRET',
        'EMANAT_PROVIDER_API_TOKEN',
        'EMANAT_PROVIDER_HMAC_SECRET',
        'UNITEDPAYMENT_PROVIDER_API_TOKEN',
        'UNITEDPAYMENT_PROVIDER_HMAC_SECRET',
        'MGS_SERVICE_SECRET',
        'MGS_JWT_SECRET',
        'MGS_SECRETS_MASTER_KEY',
        'MGS_PORTAL_SHARED_SECRET',
        'MGS_TELEGRAM_WEBHOOK_SECRET',
        'RADIUS_SQL_PASSWORD',
        'RADIUS_SHARED_SECRET',
        'SMS_PROVIDER_USER',
        'SMS_PROVIDER_PASSWORD',
        'MGS_SERVICE_SHARED_SECRET',
        'SERVICE_SHARED_SECRET',
        'JWT_SECRET',
        'SECRETS_MASTER_KEY',
        'PORTAL_SHARED_SECRET',
        'TELEGRAM_WEBHOOK_SECRET',
        'TELEGRAM_BOT_TOKEN',
        'WHATSAPP_PROVIDER_API_KEY',
        'SMS_PROVIDER_API_KEY',
    ];
}

/** @return list<string> */
function app_service_secret_visibility_missing_keys(array $requiredKeys): array
{
    $missing = [];
    foreach ($requiredKeys as $key) {
        $value = getenv($key, true);
        if ($value === false) {
            $value = getenv($key);
        }
        if (!is_string($value) || trim($value) === '') {
            $missing[] = (string) $key;
        }
    }
    return $missing;
}

function app_service_secret_visibility_write_evidence(string $path, array $evidence): void
{
    $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(6));
    $encoded = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false || !@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('evidence_write_failed');
    }
}
