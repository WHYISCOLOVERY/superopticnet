<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/mgs-integration-lib.php';
require_once dirname(__DIR__) . '/communication-provider.php';
require_once dirname(__DIR__) . '/live-cutover-lib.php';

$args = array_slice($_SERVER['argv'] ?? [], 1);
$productionMode = in_array('--production', $args, true) || in_array('--mode=production', $args, true);
$overrideConfigPath = '';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--config=')) {
        $overrideConfigPath = trim(substr($arg, strlen('--config=')));
        break;
    }
}
if ($overrideConfigPath !== '') {
    $candidatePath = $overrideConfigPath;
    if (!str_contains($candidatePath, ':') && !str_starts_with($candidatePath, DIRECTORY_SEPARATOR)) {
        $candidatePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $candidatePath;
    }
    if (is_file($candidatePath)) {
        $overrideConfig = require $candidatePath;
        if (is_array($overrideConfig)) {
            foreach ($overrideConfig as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    putenv(strtoupper((string) $key) . '=' . (string) $value);
                }
            }
        }
        $overrideConfigPath = $candidatePath;
    }
}

function readiness_add(array &$checks, string $name, string $level, bool $ok, string $message): void
{
    $checks[] = [
        'name' => $name,
        'level' => $level,
        'ok' => $ok,
        'message' => $message,
    ];
}

function readiness_is_local_url(string $url): bool
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

function readiness_is_https(string $url): bool
{
    return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
}

function readiness_masked_present(string $value): string
{
    return trim($value) !== '' ? 'configured' : 'missing';
}

function readiness_is_placeholder(string $value): bool
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

function readiness_windows_scheduled_task(string $taskName): ?array
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return null;
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
        . "logonType=[string]\$t.Principal.LogonType;"
        . "actionCount=@(\$t.Actions).Count;"
        . "triggerCount=@(\$t.Triggers).Count;"
        . "state=[string]\$t.State"
        . "} | ConvertTo-Json -Compress";
    $encoded = base64_encode((string) iconv('UTF-8', 'UTF-16LE', $script));
    $output = shell_exec('powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>NUL');
    if (!is_string($output) || trim($output) === '') {
        return ['exists' => false, 'taskName' => $taskName, 'error' => 'No PowerShell output'];
    }

    $decoded = json_decode($output, true);
    return is_array($decoded) ? $decoded : ['exists' => false, 'taskName' => $taskName, 'error' => trim($output)];
}

$checks = [];
$mode = $productionMode ? 'production' : 'local';

$appUrl = rtrim(app_config('app_url'), '/');
$publicSiteUrl = rtrim(app_config('public_site_url'), '/');
$ticketBase = rtrim(app_config('ticket_close_base_url'), '/');
$mygovEnabled = in_array(strtolower((string) app_setting('mygov_enabled', '0')), ['1', 'true', 'yes', 'on'], true);

readiness_add(
    $checks,
    'app_url',
    'critical',
    $appUrl !== '' && (!$productionMode || (!readiness_is_local_url($appUrl) && readiness_is_https($appUrl))),
    $productionMode
        ? 'Production app_url must be HTTPS and must not point to localhost/private IP.'
        : 'Local app_url can point to localhost/private IP.'
);

readiness_add(
    $checks,
    'ticket_close_base_url',
    'critical',
    $ticketBase !== '' && (!$productionMode || (!readiness_is_local_url($ticketBase) && readiness_is_https($ticketBase))),
    $productionMode
        ? 'Telegram ticket close links must use public/internal real HTTPS URL, not localhost.'
        : 'Local ticket close URL can be localhost for XAMPP tests.'
);

readiness_add(
    $checks,
    'public_site_url',
    'warning',
    !$productionMode || ($publicSiteUrl === 'https://superoptic.net' || $publicSiteUrl === 'https://www.superoptic.net'),
    $productionMode
        ? 'Public web site must remain superoptic.net; post-login operations live under billing.superoptic.net.'
        : 'Local mode can omit public_site_url or point it to localhost.'
);

$db = app_db();
$mgsSchemaOk = $db instanceof PDO ? app_mgs_ensure_schema($db) : false;
if ($db instanceof PDO) {
    app_communication_whatsapp_guard_schema($db);
}
readiness_add(
    $checks,
    'database_connection',
    'critical',
    $db instanceof PDO,
    $db instanceof PDO ? 'MySQL connection OK.' : 'MySQL connection failed.'
);

$expectedTimezone = 'Asia/Baku';
$expectedOffsetMinutes = intdiv((new DateTimeZone($expectedTimezone))->getOffset(new DateTimeImmutable('now', new DateTimeZone($expectedTimezone))), 60);
$dbOffsetMinutes = null;
if ($db instanceof PDO) {
    try {
        $dbOffsetMinutes = (int) $db->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())')->fetchColumn();
    } catch (Throwable) {
        $dbOffsetMinutes = null;
    }
}
$timezoneAligned = date_default_timezone_get() === $expectedTimezone
    && app_timezone_offset() === '+04:00'
    && $dbOffsetMinutes === $expectedOffsetMinutes;
readiness_add(
    $checks,
    'timezone_alignment',
    'critical',
    $timezoneAligned,
    'PHP=' . date_default_timezone_get()
        . '; app_offset=' . app_timezone_offset()
        . '; db_offset_minutes=' . ($dbOffsetMinutes === null ? 'unknown' : (string) $dbOffsetMinutes)
        . '; required=' . $expectedTimezone . '.'
);

$dbUser = app_config('db_user');
$dbPass = app_config('db_pass');
readiness_add(
    $checks,
    'database_credentials',
    'critical',
    !$productionMode || ($dbUser !== '' && !readiness_is_placeholder($dbPass)),
    $productionMode ? 'Production DB user/password must be configured and must not use placeholders.' : 'Local DB credentials are accepted for XAMPP tests.'
);

if ($db instanceof PDO) {
    foreach ([
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
    ] as $table) {
        try {
            $count = (int) $db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
            readiness_add($checks, 'table:' . $table, 'critical', true, $count . ' rows');
        } catch (Throwable) {
            readiness_add($checks, 'table:' . $table, 'critical', false, 'Required table unavailable.');
        }
    }
}

readiness_add(
    $checks,
    'mgs_schema',
    'critical',
    $mgsSchemaOk,
    $mgsSchemaOk ? 'MGS integration schema is ready.' : 'MGS integration schema could not be prepared.'
);

$radiusSecret = app_config('radius_shared_secret');
readiness_add(
    $checks,
    'radius_shared_secret',
    'critical',
    !$productionMode || (!readiness_is_placeholder($radiusSecret) && !in_array($radiusSecret, ['secret', 'menim_gizli_acarim123', 'testing123'], true)),
    $productionMode
        ? 'RADIUS shared secret must be configured and must not use sample/default secrets.'
        : 'Local mode does not require a production RADIUS shared secret.'
);

$coreNasIp = app_config('radius_core_nas_ip');
readiness_add(
    $checks,
    'radius_core_nas_ip',
    'warning',
    !$productionMode || !readiness_is_placeholder($coreNasIp),
    'Core router NAS source IP should be configured before FreeRADIUS cutover.'
);

$telegramToken = app_config('telegram_token');
$telegramChat = app_config('telegram_chat_id');
readiness_add(
    $checks,
    'telegram_bot',
    'warning',
    !$productionMode || (!readiness_is_placeholder($telegramToken) && !readiness_is_placeholder($telegramChat)),
    'Telegram token/chat are ' . (($telegramToken !== '' && $telegramChat !== '') ? 'configured' : 'missing') . '; required for alarm/ticket notifications.'
);

$smsProvider = app_communication_provider_config('sms', '994000000000');
$smsEndpoint = (string) ($smsProvider['endpoint'] ?? '');
$smsToken = (string) ($smsProvider['token'] ?? '');
$smsDriver = strtolower(trim((string) ($smsProvider['driver'] ?? '')));
$smsUser = (string) ($smsProvider['user'] ?? '');
$smsPassword = (string) ($smsProvider['password'] ?? '');
$smsCredentialsOk = in_array($smsDriver, ['poctgoyercini_json', 'poctgoyercini_xml'], true)
    ? (!readiness_is_placeholder($smsUser) && !readiness_is_placeholder($smsPassword))
    : !readiness_is_placeholder($smsToken);
readiness_add(
    $checks,
    'sms_provider',
    'warning',
    !$productionMode || (!empty($smsProvider['configured']) && !readiness_is_placeholder($smsEndpoint) && $smsCredentialsOk),
    $productionMode
        ? 'SMS provider endpoint and credentials should be configured for billing fallback messages. Driver=' . ($smsDriver !== '' ? $smsDriver : 'json') . '.'
        : 'Local mode does not require SMS provider.'
);

$whatsappEndpoint = app_config('whatsapp_provider_endpoint');
$whatsappToken = app_config('whatsapp_provider_token');
$whatsappEnabled = in_array(strtolower((string) app_setting('whatsapp_provider_enabled', '0')), ['1', 'true', 'yes', 'on'], true);
readiness_add(
    $checks,
    'whatsapp_provider',
    'warning',
    !$productionMode || !$whatsappEnabled || (!readiness_is_placeholder($whatsappEndpoint) && !readiness_is_placeholder($whatsappToken)),
    $productionMode
        ? ($whatsappEnabled
            ? 'WhatsApp Web bridge endpoint/token should be configured for first-priority customer messages.'
            : 'WhatsApp provider is explicitly disabled; SMS/app/Telegram fallback remains required.')
        : 'Local mode does not require WhatsApp provider.'
);

$ticketSecret = app_config('portal_ticket_close_secret', app_config('olt_sso_shared_secret'));
readiness_add(
    $checks,
    'ticket_close_secret',
    'critical',
    !$productionMode || !readiness_is_placeholder($ticketSecret),
    $productionMode ? 'Ticket close token signing secret must be configured.' : 'Local ticket close secret can use test defaults.'
);

$billingMode = app_config('billing_mode', 'mock');
readiness_add(
    $checks,
    'billing_mode',
    'warning',
    !$productionMode || $billingMode !== 'mock',
    'billing_mode=' . $billingMode . '; production should not depend on mock mode.'
);

$providerApiToken = app_config('provider_api_token');
$providerHmacSecret = app_config('provider_api_hmac_secret');
readiness_add(
    $checks,
    'provider_api_token',
    'critical',
    !$productionMode || !readiness_is_placeholder($providerApiToken),
    $productionMode
        ? 'Provider/terminal API token must be configured before billing.superoptic.net payment cutover.'
        : 'Local mode can leave provider API token empty unless endpoint smoke testing is needed.'
);
readiness_add(
    $checks,
    'provider_api_hmac_secret',
    'warning',
    !$productionMode || !readiness_is_placeholder($providerHmacSecret),
    $productionMode
        ? 'Provider HMAC secret is recommended so terminal callbacks cannot be replayed or forged.'
        : 'Local mode can omit provider HMAC secret.'
);
readiness_add(
    $checks,
    'provider_api_files',
    'critical',
    is_file(dirname(__DIR__) . '/api/provider/customer-lookup.php') && is_file(dirname(__DIR__) . '/api/provider/payment.php'),
    'Provider customer lookup and payment callback endpoints must exist under /api/provider/.'
);

$copilotApiToken = app_config('copilot_api_token');
readiness_add(
    $checks,
    'copilot_api_token',
    'warning',
    !$productionMode || !readiness_is_placeholder($copilotApiToken),
    $productionMode
        ? 'Copilot API token should be configured before Telegram/external AI query access is enabled.'
        : 'Local employee session can test Copilot without API token.'
);
readiness_add(
    $checks,
    'copilot_customer_analysis_files',
    'warning',
    is_file(dirname(__DIR__) . '/copilot-engine.php') && is_file(dirname(__DIR__) . '/copilot-agent.php') && is_file(dirname(__DIR__) . '/api/copilot/customer-analyze.php'),
    'Customer 360 Copilot analysis engine, action agent and endpoint should be present.'
);

$urlsToCheck = [
    'billing_customer_url' => app_config('billing_customer_url'),
    'billing_employee_url' => app_config('billing_employee_url'),
    'billing_sso_entrypoint' => app_config('billing_sso_entrypoint'),
    'olt_sso_entrypoint' => app_config('olt_sso_entrypoint'),
    'mygov_redirect_uri' => app_config('mygov_redirect_uri'),
];
foreach ($urlsToCheck as $key => $url) {
    if ($url === '') {
        $optionalBecauseDisabled = $key === 'mygov_redirect_uri' && !$mygovEnabled;
        $optionalBecauseFallback = $key === 'billing_sso_entrypoint';
        readiness_add(
            $checks,
            'url:' . $key,
            $optionalBecauseDisabled ? 'info' : 'warning',
            !$productionMode || $optionalBecauseDisabled || $optionalBecauseFallback,
            $key . ' is not configured' . ($optionalBecauseFallback ? '; fallback URL can still be used.' : '.')
        );
        continue;
    }

    $ok = !$productionMode || (!readiness_is_local_url($url) && readiness_is_https($url));
    readiness_add(
        $checks,
        'url:' . $key,
        str_contains($key, 'sso') || str_contains($key, 'mygov') ? 'warning' : 'info',
        $ok,
        $productionMode ? $key . ' should be HTTPS and non-local in production.' : $key . ' present.'
    );
}

$billingCustomerHost = strtolower((string) parse_url(app_config('billing_customer_url'), PHP_URL_HOST));
$billingEmployeeHost = strtolower((string) parse_url(app_config('billing_employee_url'), PHP_URL_HOST));
$billingCustomerPath = strtolower((string) parse_url(app_config('billing_customer_url'), PHP_URL_PATH));
$billingEmployeePath = strtolower((string) parse_url(app_config('billing_employee_url'), PHP_URL_PATH));
readiness_add(
    $checks,
    'billing_domain_split',
    'warning',
    !$productionMode || (
        $billingCustomerHost === 'billing.superoptic.net'
        && $billingEmployeeHost === 'billing.superoptic.net'
        && str_ends_with($billingCustomerPath, '/portal-login.php')
        && str_ends_with($billingEmployeePath, '/staff-login.php')
    ),
    $productionMode
        ? 'Customer portal must use billing.superoptic.net/portal-login.php and employee entry must use billing.superoptic.net/staff-login.php.'
        : 'Local mode does not enforce public billing domain split.'
);

$publicLoginSafetySmoke = dirname(__DIR__) . '/tools/portal-login-public-safety-smoke.php';
readiness_add(
    $checks,
    'public_login_safety_smoke',
    'warning',
    is_file($publicLoginSafetySmoke),
    is_file($publicLoginSafetySmoke) ? 'Public login safety smoke is present.' : 'Public login safety smoke missing.'
);

$billingSsoEntry = app_config('billing_sso_entrypoint');
$billingSsoSecret = app_config('billing_sso_shared_secret');
if ($billingSsoEntry !== '') {
    readiness_add(
        $checks,
        'billing_sso_secret',
        'warning',
        !$productionMode || !readiness_is_placeholder($billingSsoSecret),
        'Billing SSO entrypoint is configured; shared secret must also be real.'
    );
}

$oltSsoEntry = app_config('olt_sso_entrypoint');
$oltSsoSecret = app_config('olt_sso_shared_secret');
if ($oltSsoEntry !== '') {
    readiness_add(
        $checks,
        'olt_sso_secret',
        'warning',
        !$productionMode || !readiness_is_placeholder($oltSsoSecret),
        'OLT SSO entrypoint is configured; shared secret must also be real.'
    );
}

if ($mygovEnabled) {
    foreach (['mygov_client_id', 'mygov_client_secret', 'mygov_authorize_url', 'mygov_token_url', 'mygov_userinfo_url'] as $key) {
        $value = app_config($key);
        readiness_add($checks, 'mygov:' . $key, 'critical', trim($value) !== '', 'myGov integration enabled; ' . $key . ' is ' . readiness_masked_present($value) . '.');
    }
} else {
    readiness_add($checks, 'mygov', 'info', true, 'myGov integration is prepared but disabled.');
}

$gatewayValidator = dirname(__DIR__) . '/tools/validate-nginx-gateway.php';
$backupWorker = dirname(__DIR__) . '/tools/device-backup-worker.php';
$windowsStackStarter = dirname(__DIR__) . '/deploy/windows/start-superoptic-stack.ps1';
$windowsWorkerStarter = dirname(__DIR__) . '/deploy/windows/start-superoptic-workers.ps1';
$windowsAutostartInstaller = dirname(__DIR__) . '/deploy/windows/install-autostart.ps1';
$windowsAutostartStatus = dirname(__DIR__) . '/tmp/windows-autostart-status.json';
$ubuntuInstaller = dirname(__DIR__) . '/deploy/ubuntu-install-superoptic-mgs.sh';
$nativeNocPage = dirname(__DIR__) . '/noc-devices.php';
$mgsIntegrationPage = dirname(__DIR__) . '/mgs-integration.php';
$identityIntegrationsPage = dirname(__DIR__) . '/identity-integrations.php';
$liveCutoverLib = dirname(__DIR__) . '/live-cutover-lib.php';
$liveCutoverCli = dirname(__DIR__) . '/tools/live-cutover-checklist.php';
readiness_add(
    $checks,
    'gateway_validator',
    'warning',
    is_file($gatewayValidator),
    is_file($gatewayValidator) ? 'Nginx gateway validator is present.' : 'Nginx gateway validator missing.'
);
readiness_add(
    $checks,
    'device_backup_worker',
    'warning',
    is_file($backupWorker),
    is_file($backupWorker) ? 'Device backup worker CLI is present.' : 'Device backup worker CLI is missing.'
);
readiness_add(
    $checks,
    'windows_stack_autostart',
    'warning',
    is_file($windowsStackStarter) && is_file($windowsWorkerStarter) && is_file($windowsAutostartInstaller),
    (is_file($windowsStackStarter) && is_file($windowsWorkerStarter) && is_file($windowsAutostartInstaller))
        ? 'Windows autostart scripts are present for Apache/MySQL/MGS stack and worker startup.'
        : 'Windows autostart scripts are missing.'
);
$stackTask = readiness_windows_scheduled_task('SuperOptic MGS Stack Autostart');
$workerTask = readiness_windows_scheduled_task('SuperOptic MGS Worker Supervisor');
if ($stackTask !== null || $workerTask !== null) {
    $statusMarker = [];
    if (is_file($windowsAutostartStatus)) {
        $markerJson = (string) file_get_contents($windowsAutostartStatus);
        $markerJson = preg_replace('/^\xEF\xBB\xBF/', '', $markerJson) ?? $markerJson;
        $decodedMarker = json_decode($markerJson, true);
        if (is_array($decodedMarker)) {
            $statusMarker = $decodedMarker;
        }
    }
    $stackOk = is_array($stackTask)
        && ($stackTask['exists'] ?? false)
        && strtoupper((string) ($stackTask['principal'] ?? '')) === 'SYSTEM'
        && (int) ($stackTask['actionCount'] ?? 0) === 1;
    $workerOk = is_array($workerTask)
        && ($workerTask['exists'] ?? false)
        && strtoupper((string) ($workerTask['principal'] ?? '')) === 'SYSTEM'
        && (int) ($workerTask['actionCount'] ?? 0) === 1;
    $markerOk = ($statusMarker['mode'] ?? '') === 'system_boot'
        && strtoupper((string) ($statusMarker['principal'] ?? '')) === 'SYSTEM'
        && (int) ($statusMarker['stack_actions'] ?? 0) === 1
        && (int) ($statusMarker['worker_actions'] ?? 0) === 1
        && ($statusMarker['stack_task'] ?? '') === 'SuperOptic MGS Stack Autostart'
        && ($statusMarker['worker_task'] ?? '') === 'SuperOptic MGS Worker Supervisor';
    $markerScriptsOk = $markerOk
        && is_file((string) ($statusMarker['stack_script'] ?? ''))
        && is_file((string) ($statusMarker['worker_script'] ?? ''));
    $markerBackedWorkerOk = $stackOk && $markerScriptsOk;
    $stackStartsWorkerOk = $stackOk
        && is_file($windowsStackStarter)
        && is_file($windowsWorkerStarter)
        && stripos((string) file_get_contents($windowsStackStarter), 'start-superoptic-workers.ps1') !== false;
    $taskDetails = sprintf(
        'stack=%s/%s, worker=%s/%s, marker=%s, stack_starts_worker=%s',
        !empty($stackTask['exists']) ? 'exists' : 'missing',
        (string) ($stackTask['principal'] ?? '-'),
        !empty($workerTask['exists']) ? 'exists' : 'missing',
        (string) ($workerTask['principal'] ?? '-'),
        $markerOk ? 'system_boot' : 'not_confirmed',
        $stackStartsWorkerOk ? 'yes' : 'no'
    );
    $windowsBootOk = ($stackOk && $workerOk) || $markerBackedWorkerOk || $stackStartsWorkerOk;
    readiness_add(
        $checks,
        'windows_scheduled_tasks_runtime',
        'warning',
        $windowsBootOk,
        ($stackOk && $workerOk)
            ? 'Windows boot tasks are installed as SYSTEM: stack one-shot and worker supervisor.'
            : ($stackStartsWorkerOk
                ? 'Windows boot task is installed as SYSTEM and the stack starter launches the worker supervisor in background. ' . $taskDetails
                : ($markerBackedWorkerOk
                    ? 'Windows boot tasks are installed as SYSTEM. Current non-admin runtime cannot enumerate the worker task, but the Administrator install marker and worker script are present. ' . $taskDetails
                    : 'Windows boot tasks are not fully installed as SYSTEM. Rerun deploy/windows/install-autostart.ps1 from Administrator PowerShell. ' . $taskDetails))
    );
}
readiness_add(
    $checks,
    'ubuntu_stack_autostart',
    'warning',
    is_file($ubuntuInstaller),
    is_file($ubuntuInstaller)
        ? 'Ubuntu installer is present and writes systemd units/timers for stack startup.'
        : 'Ubuntu installer is missing.'
);
readiness_add(
    $checks,
    'native_noc_device_page',
    'warning',
    is_file($nativeNocPage),
    is_file($nativeNocPage) ? 'Native NOC device page is present.' : 'Native NOC device page is missing.'
);
readiness_add(
    $checks,
    'mgs_integration_page',
    'warning',
    is_file($mgsIntegrationPage),
    is_file($mgsIntegrationPage) ? 'SPoG integration page is present.' : 'SPoG integration page is missing.'
);
readiness_add(
    $checks,
    'identity_integrations_page',
    'warning',
    is_file($identityIntegrationsPage),
    is_file($identityIntegrationsPage) ? 'Identity integrations page is present.' : 'Identity integrations page is missing.'
);
readiness_add(
    $checks,
    'live_cutover_checklist_files',
    'warning',
    is_file($liveCutoverLib) && is_file($liveCutoverCli),
    (is_file($liveCutoverLib) && is_file($liveCutoverCli))
        ? 'Live cutover checklist library and CLI are present.'
        : 'Live cutover checklist files are missing.'
);

$liveCutoverGates = app_live_cutover_gates($db);
$blockedLiveGates = array_values(array_filter($liveCutoverGates, static fn (array $gate): bool => (string) ($gate['status'] ?? '') === 'blocked'));
readiness_add(
    $checks,
    'live_cutover_gates_runtime',
    'warning',
    true,
    'Live gates: total=' . count($liveCutoverGates) . ', blocked=' . count($blockedLiveGates) . '. Blocked gates are controlled pending, not schema failures.'
);

$copilotContextEndpoint = dirname(__DIR__) . '/mgs-copilot-context.php';
$copilotContextExport = dirname(__DIR__) . '/tools/export-mgs-copilot-context.php';
$copilotContext = $db instanceof PDO ? app_mgs_copilot_context($db) : ['ok' => false];
readiness_add(
    $checks,
    'copilot_context_files',
    'warning',
    is_file($copilotContextEndpoint) && is_file($copilotContextExport),
    (is_file($copilotContextEndpoint) && is_file($copilotContextExport))
        ? 'Copilot context endpoint and CLI exporter are present.'
        : 'Copilot context endpoint or CLI exporter is missing.'
);
readiness_add(
    $checks,
    'copilot_context_runtime',
    'warning',
    !empty($copilotContext['ok']) && (($copilotContext['safety']['secrets_exposed'] ?? true) === false),
    !empty($copilotContext['ok'])
        ? 'Copilot context generates read-only status without exposing secrets.'
        : 'Copilot context could not be generated.'
);

$criticalFailed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok'] && $check['level'] === 'critical'));
$warningsFailed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok'] && $check['level'] === 'warning'));
$status = $criticalFailed !== [] ? 'blocked' : ($warningsFailed !== [] ? 'warning' : 'ok');

$result = [
    'status' => $status,
    'mode' => $mode,
    'config_override' => $overrideConfigPath !== '' ? $overrideConfigPath : null,
    'checked_at' => date(DATE_ATOM),
    'summary' => [
        'checks_total' => count($checks),
        'critical_failed' => count($criticalFailed),
        'warnings_failed' => count($warningsFailed),
    ],
    'checks' => $checks,
    'next_commands' => [
        'local' => 'php tools/production-readiness-check.php',
        'production' => 'php tools/production-readiness-check.php --production',
        'gateway' => 'php tools/validate-nginx-gateway.php',
        'live_cutover' => 'php tools/live-cutover-checklist.php --markdown',
    ],
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($productionMode && $criticalFailed !== [] ? 1 : 0);
