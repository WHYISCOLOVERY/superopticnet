<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(64);
}

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
$json = in_array('--json', $arguments, true);
$contract = in_array('--contract', $arguments, true);
$definitions = [
    'DB_PASS' => 8,
    'RADIUS_DB_PASS' => 8,
    'RADIUS_ROUTER_SECRET' => 8,
    'DEEPSEEK_API_KEY' => 8,
    'SMS_PROVIDER_PUBLIC_KEY' => 8,
    'SMS_PROVIDER_PRIVATE_KEY' => 8,
    'TELEGRAM_TOKEN' => 24,
    'TELEGRAM_NETWORK_BOT_TOKEN' => 24,
    'TELEGRAM_CUSTOMER_BOT_TOKEN' => 24,
    'TELEGRAM_FINANCE_BOT_TOKEN' => 24,
    'TELEGRAM_WAREHOUSE_BOT_TOKEN' => 24,
    'TELEGRAM_BACKUP_BOT_TOKEN' => 24,
    'TELEGRAM_DIAGNOSTICS_BOT_TOKEN' => 24,
    'TELEGRAM_MODEM_SEARCH_BOT_TOKEN' => 24,
    'WHATSAPP_PROVIDER_TOKEN' => 8,
    'RADIUS_SSH_PASSWORD' => 8,
    'RADIUS_TEST_PASSWORD' => 24,
    'SNMP_COMMUNITY' => 8,
    'IMPORT_API_TOKEN' => 24,
    'COPILOT_API_TOKEN' => 24,
    'RECAPTCHA_SECRET' => 24,
    'BILLING_SSO_SHARED_SECRET' => 32,
    'OLT_SSO_SHARED_SECRET' => 32,
    'OLT_USER_SYNC_SECRET' => 32,
    'MYGOV_CLIENT_SECRET' => 24,
    'API_SECRET_KEY' => 32,
    'PAIRING_WORKER_TOKEN' => 32,
    'CLICKHOUSE_PASSWORD' => 24,
    'PORTAL_TICKET_CLOSE_SECRET' => 32,
    'PROVIDER_API_TOKEN' => 24,
    'PROVIDER_API_HMAC_SECRET' => 32,
    'EMANAT_PROVIDER_API_TOKEN' => 24,
    'EMANAT_PROVIDER_HMAC_SECRET' => 32,
    'UNITEDPAYMENT_PROVIDER_API_TOKEN' => 24,
    'UNITEDPAYMENT_PROVIDER_HMAC_SECRET' => 32,
    'MGS_SERVICE_SECRET' => 24,
    'MGS_JWT_SECRET' => 32,
    'MGS_SECRETS_MASTER_KEY' => 32,
    'MGS_PORTAL_SHARED_SECRET' => 24,
    'MGS_TELEGRAM_WEBHOOK_SECRET' => 24,
];
$aliases = [
    'RADIUS_SQL_PASSWORD' => 'RADIUS_DB_PASS',
    'RADIUS_SHARED_SECRET' => 'RADIUS_ROUTER_SECRET',
    'SMS_PROVIDER_USER' => 'SMS_PROVIDER_PUBLIC_KEY',
    'SMS_PROVIDER_PASSWORD' => 'SMS_PROVIDER_PRIVATE_KEY',
    'MGS_SERVICE_SHARED_SECRET' => 'MGS_SERVICE_SECRET',
    'SERVICE_SHARED_SECRET' => 'MGS_SERVICE_SECRET',
    'JWT_SECRET' => 'MGS_JWT_SECRET',
    'SECRETS_MASTER_KEY' => 'MGS_SECRETS_MASTER_KEY',
    'PORTAL_SHARED_SECRET' => 'MGS_PORTAL_SHARED_SECRET',
    'TELEGRAM_WEBHOOK_SECRET' => 'MGS_TELEGRAM_WEBHOOK_SECRET',
    'TELEGRAM_BOT_TOKEN' => 'TELEGRAM_TOKEN',
    'WHATSAPP_PROVIDER_API_KEY' => 'WHATSAPP_PROVIDER_TOKEN',
    'SMS_PROVIDER_API_KEY' => 'SMS_PROVIDER_PRIVATE_KEY',
];

function secret_readiness_is_placeholder(string $value): bool
{
    $normalized = strtoupper(trim($value));
    return str_contains($normalized, 'CHANGE_ME')
        || str_contains($normalized, 'CHANGEME')
        || str_contains($normalized, 'EXAMPLE')
        || str_contains($normalized, 'TEST_SECRET')
        || str_contains($normalized, 'DRY_RUN_SECRET')
        || in_array($normalized, ['SECRET', 'TESTING123', 'PUBLIC', 'PRIVATE'], true);
}

function secret_readiness_finish(array $checks, bool $json): never
{
    $ready = !in_array(false, array_column($checks, 'ok'), true);
    if ($json) {
        echo json_encode(['ready' => $ready, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        foreach ($checks as $check) {
            printf('[%s] %s: %s%s', $check['ok'] ? 'OK' : 'FAIL', $check['name'], $check['reason'], PHP_EOL);
        }
        echo $ready ? "secret_readiness_ok\n" : "secret_readiness_failed\n";
    }

    exit($ready ? 0 : 2);
}

function secret_readiness_contract_checks(array $definitions, array $aliases): array
{
    $root = dirname(__DIR__);
    $shellBootstrap = (string) file_get_contents($root . '/deploy/set-superoptic-secrets.sh');
    $windowsBootstrap = (string) file_get_contents($root . '/deploy/windows/Set-SuperOptic-Secrets.ps1');
    $ubuntuInstaller = (string) file_get_contents($root . '/deploy/ubuntu-install-superoptic-mgs.sh');
    $windowsInstaller = (string) file_get_contents($root . '/deploy/windows/install-superoptic-mgs.ps1');
    $visibilitySource = (string) file_get_contents($root . '/tools/secret-visibility-lib.php');
    $expected = array_keys($definitions);
    $checks = [];

    $shellNames = [];
    if (preg_match('/SUPEROPTIC_SECRET_KEYS=\(\s*(.*?)\s*\)/s', $shellBootstrap, $matches) === 1) {
        $shellNames = preg_split('/\s+/', trim($matches[1])) ?: [];
    }
    $checks[] = [
        'name' => 'shell_secret_contract',
        'ok' => $shellNames === $expected,
        'reason' => $shellNames === $expected ? 'aligned' : 'definition_mismatch',
    ];

    preg_match_all('/Name\s*=\s*"([A-Z0-9_]+)"/', $windowsBootstrap, $matches);
    $windowsNames = $matches[1] ?? [];
    $checks[] = [
        'name' => 'windows_secret_contract',
        'ok' => $windowsNames === $expected,
        'reason' => $windowsNames === $expected ? 'aligned' : 'definition_mismatch',
    ];

    $ubuntuMarkers = [
        'superoptic-pg365-status.service',
        'ExecStart=/usr/bin/php ${WEB_ROOT}/tools/pg365-status-worker.php --apply --limit=100',
        'superoptic-pg365-status.timer',
        'OnUnitActiveSec=2min',
        'Unit=superoptic-pg365-status.service',
        'systemctl enable --now superoptic-pg365-status.timer',
    ];
    $ubuntuOk = true;
    foreach ($ubuntuMarkers as $marker) {
        if (!str_contains($ubuntuInstaller, $marker)) {
            $ubuntuOk = false;
            break;
        }
    }
    $checks[] = [
        'name' => 'ubuntu_pg365_worker_contract',
        'ok' => $ubuntuOk,
        'reason' => $ubuntuOk ? 'service_timer_every_2_minutes' : 'service_timer_contract_missing',
    ];

    $windowsOk = str_contains($windowsInstaller, 'tools\pg365-status-worker.php')
        && preg_match('/pg365-status-worker\.php[^\r\n]*--apply[^\r\n]*--limit=100/', $windowsInstaller) === 1
        && preg_match('/Register-ScheduledTaskCompat\s+-Name\s+"SuperOptic MGS PG365 Status Worker"[^\r\n]*-Schedule\s+"MINUTE"[^\r\n]*-Modifier\s+"2"/', $windowsInstaller) === 1;
    $checks[] = [
        'name' => 'windows_pg365_worker_contract',
        'ok' => $windowsOk,
        'reason' => $windowsOk ? 'scheduled_every_2_minutes' : 'scheduled_task_contract_missing',
    ];

    $visibilityOk = true;
    foreach (array_merge($expected, array_keys($aliases)) as $name) {
        if (!str_contains($visibilitySource, "'" . $name . "'")) {
            $visibilityOk = false;
            break;
        }
    }
    $checks[] = [
        'name' => 'service_visibility_secret_contract',
        'ok' => $visibilityOk,
        'reason' => $visibilityOk ? 'canonical_and_alias_keys_aligned' : 'visibility_key_missing',
    ];

    $windowsServiceOk = str_contains($windowsInstaller, "New-ScheduledTaskPrincipal -UserId 'NT AUTHORITY\\LOCAL SERVICE'")
        && str_contains($windowsInstaller, "'*S-1-5-19:(OI)(CI)RX'")
        && str_contains($windowsInstaller, "'*S-1-5-19:(OI)(CI)M'")
        && is_file($root . '/deploy/windows/Test-SuperOptic-ServiceEnvironment.ps1');
    $checks[] = [
        'name' => 'windows_service_principal_contract',
        'ok' => $windowsServiceOk,
        'reason' => $windowsServiceOk ? 'local_service_with_scoped_runtime_acl' : 'windows_service_principal_contract_missing',
    ];

    return $checks;
}

if ($contract) {
    secret_readiness_finish(secret_readiness_contract_checks($definitions, $aliases), $json);
}

$checks = [];
$values = [];
foreach ($definitions as $name => $minLength) {
    $raw = getenv($name);
    $value = is_string($raw) ? $raw : '';
    $values[$name] = $value;
    $reason = 'configured';
    $ok = true;
    if ($value === '') {
        $reason = 'missing';
        $ok = false;
    } elseif (str_contains($value, "\r") || str_contains($value, "\n")) {
        $reason = 'contains_line_break';
        $ok = false;
    } elseif (strlen($value) < $minLength) {
        $reason = 'below_minimum_policy';
        $ok = false;
    } elseif (secret_readiness_is_placeholder($value)) {
        $reason = 'placeholder_or_default';
        $ok = false;
    }
    $checks[] = ['name' => $name, 'ok' => $ok, 'reason' => $reason];
}

foreach ($aliases as $alias => $canonical) {
    $raw = getenv($alias);
    $aliasValue = is_string($raw) ? $raw : '';
    $ok = $aliasValue !== '' && hash_equals((string) ($values[$canonical] ?? ''), $aliasValue);
    $checks[] = [
        'name' => $alias,
        'ok' => $ok,
        'reason' => $ok ? 'consistent_alias' : 'missing_or_inconsistent_alias',
    ];
}

$metadataDefaults = [
    'SUPEROPTIC_DB_HOST' => '127.0.0.1',
    'SUPEROPTIC_DB_PORT' => '3306',
    'SUPEROPTIC_DB_NAME' => 'superoptic_portal',
    'SUPEROPTIC_DB_USER' => 'superoptic_app',
    'SUPEROPTIC_RADIUS_DB_HOST' => '127.0.0.1',
    'SUPEROPTIC_RADIUS_DB_PORT' => '3306',
    'SUPEROPTIC_RADIUS_DB_NAME' => 'superoptic_portal',
    'SUPEROPTIC_RADIUS_DB_USER' => 'radius',
];
$metadata = [];
foreach ($metadataDefaults as $name => $default) {
    $raw = getenv($name);
    $value = is_string($raw) && $raw !== '' ? $raw : '';
    $metadata[$name] = $value;
    $ok = $value !== '';
    if (str_ends_with($name, '_PORT')) {
        $ok = ctype_digit($value) && (int) $value >= 1 && (int) $value <= 65535;
    } elseif (str_ends_with($name, '_NAME')) {
        $ok = preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }
    $checks[] = ['name' => $name, 'ok' => $ok, 'reason' => $ok ? 'configured' : 'missing_or_invalid'];
}

$buildDatabaseUrl = static function (string $prefix, string $password) use ($metadata): string {
    $host = $metadata[$prefix . '_HOST'] ?? '';
    if (str_contains($host, ':') && !str_starts_with($host, '[')) {
        $host = '[' . $host . ']';
    }
    return sprintf(
        'mysql://%s:%s@%s:%s/%s',
        rawurlencode($metadata[$prefix . '_USER'] ?? ''),
        rawurlencode($password),
        $host,
        $metadata[$prefix . '_PORT'] ?? '',
        rawurlencode($metadata[$prefix . '_NAME'] ?? '')
    );
};
$derivedUrls = [
    'DATABASE_URL' => $buildDatabaseUrl('SUPEROPTIC_DB', (string) ($values['DB_PASS'] ?? '')),
    'RADIUS_DATABASE_URL' => $buildDatabaseUrl('SUPEROPTIC_RADIUS_DB', (string) ($values['RADIUS_DB_PASS'] ?? '')),
];
foreach ($derivedUrls as $name => $expected) {
    $raw = getenv($name);
    $value = is_string($raw) ? $raw : '';
    $ok = $value !== '' && hash_equals($expected, $value);
    $checks[] = ['name' => $name, 'ok' => $ok, 'reason' => $ok ? 'consistent_derived_value' : 'missing_or_inconsistent'];
}

secret_readiness_finish($checks, $json);
