<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/communication-provider.php';
require_once __DIR__ . '/live-cutover-lib.php';

$lang = app_lang();
$user = billing_require_employee(true);
$db = app_db();
if ($db instanceof PDO) {
    app_mgs_ensure_schema($db);
    app_communication_whatsapp_guard_schema($db);
}

function system_health_is_local_url(string $url): bool
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    if ($host === '') {
        return false;
    }

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_starts_with($host, '192.168.')
        || str_starts_with($host, '10.')
        || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host) === 1;
}

function system_health_is_https(string $url): bool
{
    return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
}

function system_health_is_placeholder(string $value): bool
{
    $value = strtoupper(trim($value));
    if ($value === '') {
        return true;
    }

    return str_contains($value, 'CHANGE_ME')
        || str_contains($value, 'YOUR_')
        || str_contains($value, 'EXAMPLE')
        || str_contains($value, 'TEST_SECRET')
        || $value === 'CORE_ROUTER_RADIUS_SOURCE_IP';
}

function system_health_secret_status(string $value, array $badDefaults = []): array
{
    $trimmed = trim($value);
    $ok = $trimmed !== '' && !system_health_is_placeholder($trimmed) && !in_array($trimmed, $badDefaults, true);

    return [
        'ok' => $ok,
        'label' => $trimmed === '' ? 'missing' : ($ok ? 'configured' : 'placeholder/default'),
    ];
}

function system_health_add(array &$checks, string $section, string $name, string $level, bool $ok, string $message): void
{
    $checks[] = [
        'section' => $section,
        'name' => $name,
        'level' => $level,
        'ok' => $ok,
        'message' => $message,
    ];
}

function system_health_badge_class(bool $ok, string $level): string
{
    if ($ok) {
        return 'portal-pill-success';
    }

    return $level === 'critical' ? 'portal-pill-danger' : 'system-health-warning';
}

function system_health_count_table(?PDO $db, string $table): array
{
    if (!$db instanceof PDO) {
        return ['ok' => false, 'count' => null, 'message' => 'DB connection unavailable'];
    }

    try {
        $safeTable = str_replace('`', '``', $table);
        $count = (int) $db->query('SELECT COUNT(*) FROM `' . $safeTable . '`')->fetchColumn();
        return ['ok' => true, 'count' => $count, 'message' => $count . ' rows'];
    } catch (Throwable) {
        return ['ok' => false, 'count' => null, 'message' => 'Required table unavailable'];
    }
}

function system_health_gateway_content(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function system_health_read_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $json = (string) file_get_contents($path);
    $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function system_health_windows_scheduled_task(string $taskName): ?array
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return null;
    }

    // Spawning powershell.exe costs several seconds per call; scheduled-task
    // state changes rarely, so cache the result per task for 5 minutes. (On the
    // Linux production server this whole function is skipped above.)
    $cacheFile = sys_get_temp_dir() . '/soc_sched_' . md5($taskName) . '.json';
    if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < 300) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $safeName = str_replace("'", "''", $taskName);
    $script = "\$ProgressPreference = 'SilentlyContinue';"
        . "\$name = '$safeName';"
        . "\$t = Get-ScheduledTask -TaskName \$name -ErrorAction SilentlyContinue;"
        . "if (\$null -eq \$t) { [pscustomobject]@{exists=\$false; taskName=\$name} | ConvertTo-Json -Compress; exit 0 };"
        . "[pscustomobject]@{"
        . "exists=\$true;"
        . "taskName=\$t.TaskName;"
        . "principal=[string]\$t.Principal.UserId;"
        . "actionCount=@(\$t.Actions).Count;"
        . "state=[string]\$t.State"
        . "} | ConvertTo-Json -Compress";
    $encoded = base64_encode((string) iconv('UTF-8', 'UTF-16LE', $script));
    $output = shell_exec('powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>NUL');
    if (!is_string($output) || trim($output) === '') {
        $result = ['exists' => false, 'taskName' => $taskName, 'error' => 'No PowerShell output'];
    } else {
        $decoded = json_decode($output, true);
        $result = is_array($decoded) ? $decoded : ['exists' => false, 'taskName' => $taskName, 'error' => trim($output)];
    }
    @file_put_contents($cacheFile, json_encode($result));
    return $result;
}

$checks = [];
$tableRows = [];
$gatewayRows = [];
$productionBlockers = [];

$appUrl = rtrim(app_config('app_url'), '/');
$ticketBase = rtrim(app_config('ticket_close_base_url'), '/');
$appUrlProdOk = $appUrl !== '' && system_health_is_https($appUrl) && !system_health_is_local_url($appUrl);
$ticketBaseProdOk = $ticketBase !== '' && system_health_is_https($ticketBase) && !system_health_is_local_url($ticketBase);

system_health_add(
    $checks,
    'URL',
    'app_url',
    'critical',
    $appUrl !== '',
    $appUrl === '' ? 'Application URL is missing.' : (system_health_is_local_url($appUrl) ? 'Local/private URL configured for test.' : 'Public URL configured.')
);
system_health_add(
    $checks,
    'URL',
    'ticket_close_base_url',
    'critical',
    $ticketBase !== '',
    $ticketBase === '' ? 'Ticket close URL is missing.' : (system_health_is_local_url($ticketBase) ? 'Local/private ticket close URL configured for test.' : 'Public ticket close URL configured.')
);

if (!$appUrlProdOk) {
    $productionBlockers[] = 'Production app_url must be HTTPS and non-local.';
}
if (!$ticketBaseProdOk) {
    $productionBlockers[] = 'Telegram ticket close URL must be HTTPS and non-local.';
}

system_health_add(
    $checks,
    'Database',
    'MySQL connection',
    'critical',
    $db instanceof PDO,
    $db instanceof PDO ? 'MySQL connection OK.' : 'MySQL connection failed.'
);

$dbUser = app_config('db_user');
$dbPassStatus = system_health_secret_status(app_environment_secret('DB_PASS'));
$dbProdOk = $dbUser !== '' && $dbPassStatus['ok'];
system_health_add(
    $checks,
    'Database',
    'DB credentials',
    'critical',
    $dbUser !== '',
    'DB user is ' . ($dbUser !== '' ? 'configured' : 'missing') . '; password is ' . $dbPassStatus['label'] . '.'
);
if (!$dbProdOk) {
    $productionBlockers[] = 'Production DB credentials must use real user/password.';
}

$requiredTables = [
    'portal_accounts',
    'billing_radius_services',
    'billing_tariffs',
    'billing_ip_pools',
    'radcheck',
    'radreply',
    'radacct',
    'portal_tickets',
    'communication_messages',
    'communication_delivery_attempts',
    'communication_worker_runs',
    'whatsapp_delivery_guard',
    'whatsapp_delivery_guard_global',
    'billing_maintenance_runs',
    'ip_pools',
    'ip_addresses',
    'customer_ip_assignments',
    'radius_session_actions',
    'mgs_alarm_events',
    'mgs_operation_actions',
    'mgs_runtime_settings',
    'mgs_process_registry',
    'mgs_dedup_registry',
    'device_learning_profiles',
    'telegram_bot_registry',
    'inventory_stock_items',
    'inventory_stock_movements',
    'employee_work_snapshots',
    'device_config_backups',
];
foreach ($requiredTables as $table) {
    $result = system_health_count_table($db, $table);
    $tableRows[] = [
        'table' => $table,
        'ok' => (bool) $result['ok'],
        'count' => $result['count'],
        'message' => (string) $result['message'],
    ];
    system_health_add($checks, 'Tables', $table, 'critical', (bool) $result['ok'], (string) $result['message']);
}

$radiusSecretStatus = system_health_secret_status(app_communication_environment_secret('RADIUS_SHARED_SECRET'), ['secret', 'menim_gizli_acarim123', 'testing123']);
$radiusCoreNasStatus = system_health_secret_status(app_config('radius_core_nas_ip'));
$telegramTokenStatus = system_health_secret_status(app_communication_environment_secret('TELEGRAM_TOKEN'));
$telegramChatStatus = system_health_secret_status(app_config('telegram_chat_id'));
$smsProviderStatus = app_communication_provider_config('sms', '994000000000');
$smsEndpointStatus = system_health_secret_status((string) ($smsProviderStatus['endpoint'] ?? ''));
$smsDriver = strtolower(trim((string) ($smsProviderStatus['driver'] ?? 'poctgoyercini_json')));
$smsUserStatus = system_health_secret_status(app_communication_environment_secret('SMS_PROVIDER_PUBLIC_KEY'));
$smsPasswordStatus = system_health_secret_status(app_communication_environment_secret('SMS_PROVIDER_PRIVATE_KEY'));
$smsCredentialsOk = $smsUserStatus['ok'] && $smsPasswordStatus['ok'];
$whatsappEndpointStatus = system_health_secret_status(app_config('whatsapp_provider_endpoint'));
$whatsappTokenStatus = system_health_secret_status(app_communication_environment_secret('WHATSAPP_PROVIDER_TOKEN'));
$whatsappEnabled = in_array(strtolower((string) app_setting('whatsapp_provider_enabled', '0')), ['1', 'true', 'yes', 'on'], true);
$whatsappBridgeStatus = $whatsappEnabled ? app_communication_whatsapp_bridge_status() : [
    'reachable' => false,
    'connected' => false,
    'status' => 'disabled',
    'reason' => 'provider_disabled',
];
$ticketSecret = app_communication_environment_secret('PORTAL_TICKET_CLOSE_SECRET');
$ticketSecretStatus = system_health_secret_status($ticketSecret);
$billingMode = app_config('billing_mode', 'mock');
$mygovEnabled = in_array(strtolower((string) app_setting('mygov_enabled', '0')), ['1', 'true', 'yes', 'on'], true);

system_health_add($checks, 'RADIUS', 'radius_shared_secret', 'critical', $radiusSecretStatus['ok'], 'RADIUS shared secret is ' . $radiusSecretStatus['label'] . '.');
system_health_add($checks, 'RADIUS', 'radius_core_nas_ip', 'warning', $radiusCoreNasStatus['ok'], 'Core NAS source IP is ' . $radiusCoreNasStatus['label'] . '.');
system_health_add($checks, 'Telegram', 'telegram_bot', 'warning', $telegramTokenStatus['ok'] && $telegramChatStatus['ok'], 'Telegram token/chat are ' . (($telegramTokenStatus['ok'] && $telegramChatStatus['ok']) ? 'configured' : 'missing or placeholder') . '.');
system_health_add($checks, 'Provider', 'sms_provider', 'warning', $smsEndpointStatus['ok'] && $smsCredentialsOk, 'SMS endpoint/credentials are ' . (($smsEndpointStatus['ok'] && $smsCredentialsOk) ? 'configured' : 'missing or placeholder') . '. Driver=' . ($smsDriver !== '' ? $smsDriver : 'json') . '.');
system_health_add(
    $checks,
    'Provider',
    'whatsapp_provider',
    'warning',
    !$whatsappEnabled || ($whatsappEndpointStatus['ok'] && $whatsappTokenStatus['ok']),
    $whatsappEnabled
        ? 'WhatsApp Web bridge endpoint/token are ' . (($whatsappEndpointStatus['ok'] && $whatsappTokenStatus['ok']) ? 'configured' : 'missing or placeholder') . '. Bridge=' . (string) ($whatsappBridgeStatus['status'] ?? 'unknown') . '.'
        : 'WhatsApp provider disabled; app/Telegram/SMS fallback remains active.'
);
system_health_add(
    $checks,
    'Provider',
    'whatsapp_bridge',
    'warning',
    !$whatsappEnabled || !empty($whatsappBridgeStatus['reachable']),
    $whatsappEnabled
        ? (!empty($whatsappBridgeStatus['reachable'])
            ? 'Bridge reachable; connected=' . (!empty($whatsappBridgeStatus['connected']) ? 'yes' : 'no') . '; status=' . (string) ($whatsappBridgeStatus['status'] ?? 'unknown') . '.'
            : 'Bridge unreachable; start whatsapp-bridge and scan QR in message center.')
        : 'Bridge disabled.'
);
system_health_add($checks, 'Security', 'ticket_close_secret', 'critical', $ticketSecretStatus['ok'], 'Ticket close signing secret is ' . $ticketSecretStatus['label'] . '.');
system_health_add($checks, 'Billing', 'billing_mode', 'warning', $billingMode !== 'mock', 'billing_mode=' . $billingMode . '.');
system_health_add($checks, 'Identity', 'myGov', 'info', true, $mygovEnabled ? 'myGov integration enabled; API credentials must be checked before use.' : 'myGov module is prepared but disabled.');

$windowsAutostartStatus = system_health_read_json_file(__DIR__ . '/tmp/windows-autostart-status.json');
$stackTask = system_health_windows_scheduled_task('SuperOptic MGS Stack Autostart');
$workerTask = system_health_windows_scheduled_task('SuperOptic MGS Worker Supervisor');
$windowsAutostartMarkerOk = ($windowsAutostartStatus['mode'] ?? '') === 'system_boot'
    && strtoupper((string) ($windowsAutostartStatus['principal'] ?? '')) === 'SYSTEM'
    && (int) ($windowsAutostartStatus['stack_actions'] ?? 0) === 1
    && (int) ($windowsAutostartStatus['worker_actions'] ?? 0) === 1
    && ($windowsAutostartStatus['stack_task'] ?? '') === 'SuperOptic MGS Stack Autostart'
    && ($windowsAutostartStatus['worker_task'] ?? '') === 'SuperOptic MGS Worker Supervisor';
$windowsStackTaskOk = is_array($stackTask)
    && ($stackTask['exists'] ?? false)
    && strtoupper((string) ($stackTask['principal'] ?? '')) === 'SYSTEM'
    && (int) ($stackTask['actionCount'] ?? 0) === 1;
$windowsWorkerTaskOk = is_array($workerTask)
    && ($workerTask['exists'] ?? false)
    && strtoupper((string) ($workerTask['principal'] ?? '')) === 'SYSTEM'
    && (int) ($workerTask['actionCount'] ?? 0) === 1;
$windowsAutostartOk = $windowsStackTaskOk && $windowsWorkerTaskOk;
$windowsAutostartDetails = sprintf(
    'stack=%s/%s, worker=%s/%s, marker=%s',
    !empty($stackTask['exists']) ? 'exists' : 'missing',
    (string) ($stackTask['principal'] ?? '-'),
    !empty($workerTask['exists']) ? 'exists' : 'missing',
    (string) ($workerTask['principal'] ?? '-'),
    $windowsAutostartMarkerOk ? 'system_boot' : 'not_confirmed'
);
system_health_add(
    $checks,
    'Autostart',
    'Windows boot tasks',
    'warning',
    $windowsAutostartOk,
    $windowsAutostartOk
        ? 'SYSTEM boot autostart is installed for stack and worker supervisor.'
        : 'Run deploy/windows/install-autostart.ps1 from Administrator PowerShell. ' . $windowsAutostartDetails
);

if (!$radiusSecretStatus['ok']) {
    $productionBlockers[] = 'RADIUS shared secret must be production-grade.';
}
if (!$radiusCoreNasStatus['ok']) {
    $productionBlockers[] = 'Core router NAS source IP must be configured.';
}
if (!$telegramTokenStatus['ok'] || !$telegramChatStatus['ok']) {
    $productionBlockers[] = 'Telegram token and chat must be configured for alarm delivery.';
}
if (!$smsEndpointStatus['ok'] || !$smsCredentialsOk) {
    $productionBlockers[] = 'SMS provider endpoint/credentials should be configured for fallback billing messages.';
}
if ($whatsappEnabled && (!$whatsappEndpointStatus['ok'] || !$whatsappTokenStatus['ok'])) {
    $productionBlockers[] = 'WhatsApp Web bridge endpoint/token should be configured for first-priority customer messages.';
}
if (!$windowsAutostartOk) {
    $productionBlockers[] = 'Windows boot autostart must be installed as SYSTEM before production use.';
}
if (!$ticketSecretStatus['ok']) {
    $productionBlockers[] = 'Ticket close signing secret must be configured.';
}
if ($billingMode === 'mock') {
    $productionBlockers[] = 'billing_mode must not remain mock in production.';
}

$root = __DIR__;
$nginxDir = $root . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'nginx';
$gatewayFiles = [
    'nginx.conf' => $nginxDir . DIRECTORY_SEPARATOR . 'nginx.conf',
    'core hardening' => $nginxDir . DIRECTORY_SEPARATOR . 'conf.d' . DIRECTORY_SEPARATOR . 'superoptic-core.conf',
    'access control' => $nginxDir . DIRECTORY_SEPARATOR . 'conf.d' . DIRECTORY_SEPARATOR . 'superoptic-access-control.conf',
    'rate limits' => $nginxDir . DIRECTORY_SEPARATOR . 'conf.d' . DIRECTORY_SEPARATOR . 'superoptic-rate-limits.conf',
    'upstreams' => $nginxDir . DIRECTORY_SEPARATOR . 'conf.d' . DIRECTORY_SEPARATOR . 'superoptic-upstreams.conf',
    'site routes' => $nginxDir . DIRECTORY_SEPARATOR . 'sites-available' . DIRECTORY_SEPARATOR . 'superoptic-gateway.conf',
    'proxy headers' => $nginxDir . DIRECTORY_SEPARATOR . 'snippets' . DIRECTORY_SEPARATOR . 'proxy-headers.conf',
    'security headers' => $nginxDir . DIRECTORY_SEPARATOR . 'snippets' . DIRECTORY_SEPARATOR . 'security-headers.conf',
    'ssl params' => $nginxDir . DIRECTORY_SEPARATOR . 'snippets' . DIRECTORY_SEPARATOR . 'ssl-params.conf',
    'install script' => $nginxDir . DIRECTORY_SEPARATOR . 'install-superoptic-nginx.sh',
    'health endpoint' => $root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'health.php',
    'status page' => $root . DIRECTORY_SEPARATOR . 'status.php',
];
foreach ($gatewayFiles as $label => $path) {
    $ok = is_file($path);
    $gatewayRows[] = ['name' => $label, 'ok' => $ok, 'message' => $ok ? 'present' : 'missing'];
    system_health_add($checks, 'Gateway', $label, 'warning', $ok, $ok ? 'File present.' : 'File missing.');
}

$site = system_health_gateway_content($gatewayFiles['site routes']);
$upstreams = system_health_gateway_content($gatewayFiles['upstreams']);
$rateLimits = system_health_gateway_content($gatewayFiles['rate limits']);
$securityHeaders = system_health_gateway_content($gatewayFiles['security headers']);
$sslParams = system_health_gateway_content($gatewayFiles['ssl params']);
$staticGatewayChecks = [
    'domains' => ['source' => $site, 'needles' => ['mgs.superoptic.net', 'billing.superoptic.net', 'api.superoptic.net', 'status.superoptic.net']],
    'api routes' => ['source' => $site, 'needles' => ['location /api/billing/', 'location /api/radius/', 'location /api/noc/', 'location /api/olt/', 'location /api/notification/', 'location /ws/']],
    'upstreams' => ['source' => $upstreams, 'needles' => ['upstream billing_service', 'upstream radius_service', 'upstream noc_service', 'upstream olt_service', 'upstream notification_service']],
    'rate limiting' => ['source' => $rateLimits, 'needles' => ['zone=login_limit', 'zone=otp_limit', 'zone=provider_api_limit', 'zone=diagnostics_limit']],
    'security headers' => ['source' => $securityHeaders, 'needles' => ['X-Frame-Options', 'Content-Security-Policy', 'Permissions-Policy']],
    'ssl policy' => ['source' => $sslParams, 'needles' => ['TLSv1.2', 'TLSv1.3', 'Strict-Transport-Security']],
];
foreach ($staticGatewayChecks as $name => $rule) {
    $missing = [];
    foreach ($rule['needles'] as $needle) {
        if (!str_contains((string) $rule['source'], $needle)) {
            $missing[] = $needle;
        }
    }
    $ok = $missing === [];
    $gatewayRows[] = ['name' => $name, 'ok' => $ok, 'message' => $ok ? 'ready' : 'missing: ' . implode(', ', $missing)];
    system_health_add($checks, 'Gateway', $name, 'warning', $ok, $ok ? 'Static gateway check OK.' : 'Static gateway check has missing rules.');
}

$criticalFailed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok'] && $check['level'] === 'critical'));
$warningFailed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok'] && $check['level'] === 'warning'));
$okCount = count(array_filter($checks, static fn (array $check): bool => (bool) $check['ok']));
$liveCutoverGates = app_live_cutover_gates($db);
$liveBlocked = array_values(array_filter($liveCutoverGates, static fn (array $gate): bool => (string) ($gate['status'] ?? '') === 'blocked'));
$liveManual = array_values(array_filter($liveCutoverGates, static fn (array $gate): bool => (string) ($gate['severity'] ?? '') === 'manual'));
$overall = $criticalFailed !== [] ? 'Blocked' : ($warningFailed !== [] ? 'Warning' : 'Ready');
$overallTone = $criticalFailed !== [] ? 'red' : ($warningFailed !== [] ? 'orange' : 'green');
$suffix = $lang !== 'az' ? '?lang=' . rawurlencode($lang) : '';
$showFullAudit = (string) ($_GET['view'] ?? '') === 'all';
$fullAuditSuffix = '?view=all' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : '');
$compactLimit = 12;
$failedChecks = array_values(array_filter($checks, static fn (array $check): bool => !(bool) $check['ok']));
$visibleChecks = $showFullAudit
    ? $checks
    : ($failedChecks !== [] ? array_slice($failedChecks, 0, $compactLimit) : array_slice($checks, 0, $compactLimit));
$hiddenCheckCount = max(0, count($checks) - count($visibleChecks));
$problemTables = array_values(array_filter($tableRows, static fn (array $row): bool => !(bool) $row['ok']));
$visibleTableRows = $showFullAudit
    ? $tableRows
    : ($problemTables !== [] ? array_slice($problemTables, 0, $compactLimit) : array_slice($tableRows, 0, 8));
$hiddenTableCount = max(0, count($tableRows) - count($visibleTableRows));
$problemGatewayRows = array_values(array_filter($gatewayRows, static fn (array $row): bool => !(bool) $row['ok']));
$visibleGatewayRows = $showFullAudit
    ? $gatewayRows
    : ($problemGatewayRows !== [] ? array_slice($problemGatewayRows, 0, $compactLimit) : array_slice($gatewayRows, 0, 8));
$hiddenGatewayCount = max(0, count($gatewayRows) - count($visibleGatewayRows));
$priorityCutoverGates = array_values(array_filter(
    $liveCutoverGates,
    static fn (array $gate): bool => (string) ($gate['status'] ?? '') === 'blocked'
        || in_array((string) ($gate['severity'] ?? ''), ['manual', 'critical'], true)
));
$visibleCutoverGates = $showFullAudit
    ? $liveCutoverGates
    : ($priorityCutoverGates !== [] ? array_slice($priorityCutoverGates, 0, $compactLimit) : array_slice($liveCutoverGates, 0, 6));
$hiddenGateCount = max(0, count($liveCutoverGates) - count($visibleCutoverGates));
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System health</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .system-health-warning{background:#fff5d6;color:#8b5a00}
        .system-health-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(320px,.9fr);gap:18px}
        .system-health-checks{display:grid;gap:10px}
        .system-health-row{display:grid;grid-template-columns:minmax(110px,.45fr) minmax(220px,1fr) minmax(90px,auto) minmax(260px,1.4fr);gap:12px;align-items:center;padding:12px;border:1px solid rgba(148,163,184,.24);border-radius:16px;background:#fff}
        .system-health-row>*{min-width:0}
        .system-health-row strong{font-size:.9rem;overflow-wrap:anywhere}
        .system-health-row small{overflow-wrap:anywhere}
        .system-health-row .portal-pill{justify-self:start;white-space:nowrap}
        .system-health-code{display:block;white-space:normal;word-break:break-word;padding:12px;border-radius:14px;background:#0f172a;color:#dbeafe;font-size:.78rem;line-height:1.5}
        .system-health-blockers{display:grid;gap:10px}
        .system-health-blocker{padding:12px 14px;border-radius:14px;background:#fff7ed;border:1px solid rgba(255,127,90,.22);color:#7c2d12}
        .system-health-compact-note{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px;padding:12px 14px;border:1px dashed rgba(14,116,144,.24);border-radius:16px;background:#ecfeff;color:#155e75}
        .system-health-compact-note strong{color:#0f172a}
        @media (max-width: 1100px){.system-health-grid{grid-template-columns:1fr}.system-health-row{grid-template-columns:1fr}}
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('System health', 'system-health', $lang); ?>

    <main class="portal-layout">
        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">Production readiness</span>
                <h1>DB / RADIUS / Gateway / URL hazirliq paneli</h1>
                <p>Bu sehife CLI readiness mentiqini admin UI-da gosterir. Secret, token ve parollar maskalanir; yalniz configured/missing statusu gorunur.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="status.php" target="_blank" rel="noopener">Public status</a>
                    <a class="portal-ghost-btn portal-inline-link" href="system-settings.php">Olke ve saat ayarlari</a>
                    <a class="portal-ghost-btn portal-inline-link" href="message-center.php<?= e($suffix); ?>">Message center</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-nas.php<?= e($suffix); ?>">RADIUS NAS</a>
                    <?php if ($showFullAudit): ?>
                        <a class="portal-ghost-btn portal-inline-link" href="system-health.php<?= e($suffix); ?>">Qisa baxış</a>
                    <?php else: ?>
                        <a class="portal-ghost-btn portal-inline-link" href="system-health.php<?= e($fullAuditSuffix); ?>">Tam audit</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>Umumi status</span>
                <strong><?= e($overall); ?></strong>
                <small><?= e((string) $okCount); ?>/<?= e((string) count($checks)); ?> check OK</small>
                <span>Production blocker</span>
                <strong><?= e((string) count($productionBlockers)); ?></strong>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Overall', $overall, 'critical: ' . count($criticalFailed) . ', warning: ' . count($warningFailed), 'fa-heart-pulse', $overallTone); ?>
            <?php billing_render_stat_card('DB tables', (string) count(array_filter($tableRows, static fn (array $row): bool => (bool) $row['ok'])) . '/' . count($tableRows), 'required schema', 'fa-database', 'blue'); ?>
            <?php billing_render_stat_card('Gateway', (string) count(array_filter($gatewayRows, static fn (array $row): bool => (bool) $row['ok'])) . '/' . count($gatewayRows), 'nginx bundle', 'fa-shield-halved', 'navy'); ?>
            <?php billing_render_stat_card('Production blockers', (string) count($productionBlockers), 'must be zero before cutover', 'fa-triangle-exclamation', count($productionBlockers) > 0 ? 'orange' : 'green'); ?>
            <?php billing_render_stat_card('Live gates', (string) (count($liveCutoverGates) - count($liveBlocked)) . '/' . count($liveCutoverGates), 'manual: ' . count($liveManual) . ', blocked: ' . count($liveBlocked), 'fa-toggle-on', count($liveBlocked) > 0 ? 'orange' : 'green'); ?>
        </section>

        <section class="system-health-grid portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Readiness checks</h2>
                    <small><?= $showFullAudit ? 'Butun audit check-leri gosterilir.' : 'Default olaraq yalniz problemli ve ilk vacib check-ler gosterilir.'; ?></small>
                </div>
                <div class="system-health-checks">
                    <?php foreach ($visibleChecks as $check): ?>
                        <div class="system-health-row">
                            <span class="portal-pill"><?= e($check['section']); ?></span>
                            <strong><?= e($check['name']); ?></strong>
                            <span class="portal-pill <?= e(system_health_badge_class((bool) $check['ok'], (string) $check['level'])); ?>"><?= $check['ok'] ? 'OK' : e(strtoupper((string) $check['level'])); ?></span>
                            <small><?= e($check['message']); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$showFullAudit && $hiddenCheckCount > 0): ?>
                    <div class="system-health-compact-note">
                        <span><strong><?= e((string) $hiddenCheckCount); ?></strong> OK/secondary check default baxışdan gizlədildi.</span>
                        <a class="portal-inline-link" href="system-health.php<?= e($fullAuditSuffix); ?>">Tam auditə bax</a>
                    </div>
                <?php endif; ?>
            </article>

            <div class="portal-card">
                <div class="portal-card-head">
                    <h2>Production cutover blockers</h2>
                    <small>Bu siyahi sifirlanmadan real domen/RADIUS cutover edilmeli deyil.</small>
                </div>
                <?php if ($productionBlockers === []): ?>
                    <?php billing_render_empty_state('Production blocker yoxdur', 'Cari konfiqurasiya production qaydalarina uygun gorunur.', 'fa-circle-check'); ?>
                <?php else: ?>
                    <div class="system-health-blockers">
                        <?php foreach ($productionBlockers as $blocker): ?>
                            <div class="system-health-blocker"><?= e($blocker); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="portal-section-gap">
                    <strong class="portal-kicker">CLI verification</strong>
                    <code class="system-health-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\production-readiness-check.php</code>
                    <code class="system-health-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\production-readiness-check.php --production</code>
                    <code class="system-health-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\validate-nginx-gateway.php</code>
                </div>
            </div>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Live cutover gates</h2>
                <small>Router, OLT, SMS, WhatsApp ve Telegram kimi real tesirli addimlar yalniz bu gate-ler hazir olanda apply edilmelidir.</small>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Gate</th><th>Status</th><th>Evidence</th><th>Next</th><th>Safe command</th></tr></thead>
                    <tbody>
                    <?php foreach ($visibleCutoverGates as $gate): ?>
                        <tr>
                            <td><strong><?= e((string) ($gate['title'] ?? '')); ?></strong></td>
                            <td><span class="portal-pill <?= e(app_live_cutover_gate_badge_class((string) ($gate['severity'] ?? 'manual'))); ?>"><?= e((string) ($gate['status'] ?? 'unknown')); ?></span></td>
                            <td><?= e((string) ($gate['evidence'] ?? '')); ?></td>
                            <td><?= e((string) ($gate['next'] ?? '')); ?></td>
                            <td>
                                <code class="system-health-code"><?= e((string) ($gate['command'] ?? '')); ?></code>
                                <?php if (!empty($gate['apply_command'])): ?>
                                    <small>Apply yalniz tesdiqli testde:</small>
                                    <code class="system-health-code"><?= e((string) $gate['apply_command']); ?></code>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$showFullAudit && $hiddenGateCount > 0): ?>
                <div class="system-health-compact-note">
                    <span><strong><?= e((string) $hiddenGateCount); ?></strong> live gate qisa baxışdan gizlədildi.</span>
                    <a class="portal-inline-link" href="system-health.php<?= e($fullAuditSuffix); ?>">Hamısını göstər</a>
                </div>
            <?php endif; ?>
            <div class="portal-section-gap">
                <strong class="portal-kicker">CLI checklist</strong>
                <code class="system-health-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\live-cutover-checklist.php</code>
                <code class="system-health-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\live-cutover-checklist.php --markdown</code>
                <small>`--strict` yalniz real go-live gunu ucun istifade olunur; blok gate varsa exit code 2 qaytarir.</small>
            </div>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Required DB tables</h2>
                    <small><?= $showFullAudit ? 'Butun schema table-lari.' : 'Problem varsa problemli table-lar; yoxdursa ilk vacib table-lar.'; ?></small>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Table</th><th>Status</th><th>Rows</th><th>Note</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibleTableRows as $row): ?>
                            <tr>
                                <td><strong><?= e($row['table']); ?></strong></td>
                                <td><span class="portal-pill <?= $row['ok'] ? 'portal-pill-success' : 'portal-pill-danger'; ?>"><?= $row['ok'] ? 'OK' : 'Missing'; ?></span></td>
                                <td><?= $row['count'] === null ? '-' : e((string) $row['count']); ?></td>
                                <td><?= e($row['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$showFullAudit && $hiddenTableCount > 0): ?>
                    <div class="system-health-compact-note">
                        <span><strong><?= e((string) $hiddenTableCount); ?></strong> table qisa baxışda gizlədildi.</span>
                        <a class="portal-inline-link" href="system-health.php<?= e($fullAuditSuffix); ?>">Tam schema siyahısı</a>
                    </div>
                <?php endif; ?>
            </article>

            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Nginx/API gateway bundle</h2>
                    <small><?= $showFullAudit ? 'Butun gateway yoxlamalari.' : 'Problem varsa problemli gateway yoxlamalari; yoxdursa qisa xulase.'; ?></small>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Check</th><th>Status</th><th>Note</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibleGatewayRows as $row): ?>
                            <tr>
                                <td><strong><?= e($row['name']); ?></strong></td>
                                <td><span class="portal-pill <?= $row['ok'] ? 'portal-pill-success' : 'portal-pill-danger'; ?>"><?= $row['ok'] ? 'OK' : 'Missing'; ?></span></td>
                                <td><?= e($row['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$showFullAudit && $hiddenGatewayCount > 0): ?>
                    <div class="system-health-compact-note">
                        <span><strong><?= e((string) $hiddenGatewayCount); ?></strong> gateway check qisa baxışda gizlədildi.</span>
                        <a class="portal-inline-link" href="system-health.php<?= e($fullAuditSuffix); ?>">Tam gateway audit</a>
                    </div>
                <?php endif; ?>
            </article>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
