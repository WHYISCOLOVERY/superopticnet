<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

function billing_ui_smoke_json(array $payload, int $exitCode = 0): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function billing_ui_smoke_error_patterns(): array
{
    return [
        '/(^|\n)\s*(PHP )?Fatal error:/',
        '/(^|\n)\s*(PHP )?Parse error:/',
        '/(^|\n)\s*(PHP )?Warning:/',
        '/(^|\n)\s*(PHP )?Notice:/',
        '/(^|\n)\s*(PHP )?Deprecated:/',
        '/Undefined variable/i',
        '/Uncaught /i',
        '/Database baglantisi aktiv deyil/i',
        '/auth_required/i',
    ];
}

function billing_ui_smoke_employee(PDO $db): ?array
{
    $statement = $db->query(
        "SELECT *
         FROM portal_accounts
         WHERE role = 'employee' AND is_active = 1
         ORDER BY CASE
             WHEN employee_role = 'super_admin' THEN 0
             WHEN account_type = 'admin' THEN 1
             WHEN account_type = 'operator' THEN 2
             WHEN account_type = 'technician' THEN 3
             ELSE 9
         END, id
         LIMIT 1"
    );
    $row = $statement ? $statement->fetch() : false;

    return is_array($row) ? $row : null;
}

function billing_ui_smoke_customer(PDO $db): ?array
{
    $statement = $db->query(
        "SELECT a.*
         FROM portal_accounts a
         WHERE a.role = 'customer'
           AND a.subscriber_code IS NOT NULL
           AND a.subscriber_code <> ''
         ORDER BY a.is_active DESC, a.id
         LIMIT 1"
    );
    $row = $statement ? $statement->fetch() : false;

    return is_array($row) ? $row : null;
}

function billing_ui_smoke_render_page(string $page, string $query): void
{
    $allowedPages = [
        'billing-dashboard.php',
        'billing-customers.php',
        'billing-customer-profile.php',
        'billing-customer-edit.php',
        'billing-cash-payment.php',
        'billing-payments.php',
        'billing-packages.php',
        'billing-balances.php',
        'billing-services.php',
        'billing-providers.php',
        'billing-documents.php',
        'billing-reports.php',
        'billing-statistics.php',
        'statistics-center.php',
        'radius-active.php',
        'radius-logs.php',
        'radius-nas.php',
        'message-center.php',
        'ip-pools.php',
        'noc-devices.php',
        'operations-center.php',
        'mgs-copilot-context.php',
        'ai-dashboard.php',
        'ai-copilot.php',
        'ai-customer-analytics.php',
        'ai-network-analytics.php',
        'ai-finance-analytics.php',
        'ai-risks.php',
        'ai-recommendations.php',
        'ai-automations.php',
        'ai-audit.php',
        'ai-model-status.php',
        'mgs-integration.php',
        'identity-integrations.php',
        'billing-operations-check.php',
        'crm.php',
    ];

    $page = basename($page);
    if (!in_array($page, $allowedPages, true)) {
        echo 'Unsupported page';
        exit(20);
    }

    $db = app_db();
    if (!$db instanceof PDO) {
        echo 'Database unavailable';
        exit(21);
    }

    $employee = billing_ui_smoke_employee($db);
    if ($employee === null) {
        echo 'No active employee account';
        exit(22);
    }

    parse_str($query, $params);
    $_GET = array_map(static fn ($value) => is_scalar($value) ? (string) $value : '', $params);
    $_POST = [];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/superopticnet/' . $page . ($query !== '' ? '?' . $query : '');
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/' . $page;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    app_session_boot();
    $_SESSION['portal_user'] = app_db_user_session_payload($employee);

    chdir(dirname(__DIR__));
    ob_start();
    include dirname(__DIR__) . DIRECTORY_SEPARATOR . $page;
    $html = (string) ob_get_clean();
    echo $html;
}

function billing_ui_smoke_run_process(string $page, string $query): array
{
    $startedAt = microtime(true);
    $command = [
        PHP_BINARY,
        __FILE__,
        '--render=' . $page,
        '--query=' . $query,
    ];

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return ['exit_code' => 99, 'stdout' => '', 'stderr' => 'Unable to start render process.', 'duration_ms' => 0];
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => is_int($exitCode) ? $exitCode : 98,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ];
}

foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--render=')) {
        $renderPage = substr($arg, strlen('--render='));
        $renderQuery = '';
        foreach (array_slice($_SERVER['argv'] ?? [], 1) as $queryArg) {
            if (str_starts_with($queryArg, '--query=')) {
                $renderQuery = substr($queryArg, strlen('--query='));
                break;
            }
        }
        billing_ui_smoke_render_page($renderPage, $renderQuery);
        exit(0);
    }
}

$db = app_db();
if (!$db instanceof PDO) {
    billing_ui_smoke_json([
        'status' => 'failed',
        'message' => 'MySQL/PDO connection is not ready.',
    ], 2);
}

$customer = billing_ui_smoke_customer($db);
if ($customer === null) {
    billing_ui_smoke_json([
        'status' => 'failed',
        'message' => 'No customer account is available for UI smoke.',
    ], 3);
}

$customerId = (int) ($customer['id'] ?? 0);
$customerCode = (string) ($customer['subscriber_code'] ?? '');
$pages = [
    [
        'name' => 'dashboard',
        'page' => 'billing-dashboard.php',
        'query' => '',
        'markers' => ['data-billing-overview', 'data-billing-kpi', 'Diqqet teleb edenler', 'Billing statistikasi'],
    ],
    [
        'name' => 'customers_list',
        'page' => 'billing-customers.php',
        'query' => http_build_query(['q' => $customerCode, 'per_page' => '50']),
        'markers' => ['Musteri siyahisi', $customerCode, 'billing-customer-profile.php', 'Yenile', 'Duzelis', 'AI risk', 'Activity'],
    ],
    [
        'name' => 'customers_create',
        'page' => 'billing-customers.php',
        'query' => http_build_query(['create' => '1']),
        'markers' => ['Yeni musteri yarat', 'name="stable_due_day"', 'data-area-select', 'data-main-pool-select', 'Pooldan IP al'],
    ],
    [
        'name' => 'customer_profile',
        'page' => 'billing-customer-profile.php',
        'query' => http_build_query(['id' => (string) $customerId]),
        'markers' => ['Tam musteri karti', 'tab=payments', 'tab=network', 'tab=devices', 'tab=communication', 'tab=ai', 'profile-main', $customerCode],
    ],
    [
        'name' => 'customer_profile_network',
        'page' => 'billing-customer-profile.php',
        'query' => http_build_query(['id' => (string) $customerId, 'tab' => 'network']),
        'markers' => ['profile-services', 'profile-radius', 'profile-radius-accounting', 'name="stable_due_day"', 'RADIUS sync'],
    ],
    [
        'name' => 'customer_profile_communication',
        'page' => 'billing-customer-profile.php',
        'query' => http_build_query(['id' => (string) $customerId, 'tab' => 'communication']),
        'markers' => ['customer-message-layout', 'profile-message-form', 'data-message-char-count', 'WhatsApp -> App -> Telegram -> SMS'],
    ],
    [
        'name' => 'customer_profile_payments',
        'page' => 'billing-customer-profile.php',
        'query' => http_build_query(['id' => (string) $customerId, 'tab' => 'payments']),
        'markers' => ['Odenis cixarisi', 'Kohne billing tarixcesi', 'legacy-profile-shell'],
    ],
    [
        'name' => 'customer_profile_ai',
        'page' => 'billing-customer-profile.php',
        'query' => http_build_query(['id' => (string) $customerId, 'tab' => 'ai']),
        'markers' => ['Customer Activity Intelligence', 'Churn riski', 'Revenue loss'],
    ],
    [
        'name' => 'customer_edit',
        'page' => 'billing-customer-edit.php',
        'query' => http_build_query(['id' => (string) $customerId]),
        'markers' => ['Musteri billing duzelisi', 'name="tariff_id"', 'name="stable_due_day"', 'name="billing_days"', 'Duzelisi saxla'],
    ],
    [
        'name' => 'cash_payment',
        'page' => 'billing-cash-payment.php',
        'query' => http_build_query(['subscriber_code' => $customerCode]),
        'markers' => ['cash-register', 'name="amount"', 'subscriber_code'],
    ],
    [
        'name' => 'payments',
        'page' => 'billing-payments.php',
        'query' => http_build_query(['per_page' => '50']),
        'markers' => ['Ã–dÉ™niÅŸ siyahÄ±sÄ±', 'portal-table', 'billing-customer-profile.php', 'Provider import'],
    ],
    [
        'name' => 'payments_import',
        'page' => 'billing-payments.php',
        'query' => http_build_query(['import' => '1', 'per_page' => '50']),
        'markers' => ['Provider/terminal', 'name="provider_csv"', 'provider_import'],
    ],
    [
        'name' => 'statistics',
        'page' => 'billing-statistics.php',
        'query' => '',
        'markers' => ['data-billing-statistics', 'data-billing-statistics-results', 'Billing statistikasi'],
    ],
    [
        'name' => 'radius_active',
        'page' => 'radius-active.php',
        'query' => http_build_query(['q' => $customerCode]),
        'markers' => ['sessiyalar', 'RADIUS', 'mgs-data-card'],
    ],
    [
        'name' => 'radius_logs',
        'page' => 'radius-logs.php',
        'query' => http_build_query(['q' => $customerCode]),
        'markers' => ['accounting', 'mgs-data-card'],
    ],
    [
        'name' => 'radius_nas',
        'page' => 'radius-nas.php',
        'query' => '',
        'markers' => ['NAS', 'Router / BNG', 'portal-card'],
    ],
    [
        'name' => 'message_center',
        'page' => 'message-center.php',
        'query' => http_build_query(['view' => 'overview']),
        'markers' => ['data-message-command-strip', 'Kanal ve novbe veziyyeti', 'data-message-channel-chart', 'Kanal ve provider ayarlarina kec'],
    ],
    [
        'name' => 'message_center_settings',
        'page' => 'message-center.php',
        'query' => http_build_query(['view' => 'settings']),
        'markers' => ['Kanal ve provider ayarlari', 'data-whatsapp-provider-panel', 'sms-provider-settings', 'mgs-integration.php?view=bots'],
    ],
    [
        'name' => 'message_center_single',
        'page' => 'message-center.php',
        'query' => http_build_query(['view' => 'single', 'q' => $customerCode]),
        'markers' => ['Ferdi mesaj gonder', 'Musteri axtarisi', $customerCode],
    ],
    [
        'name' => 'message_center_test',
        'page' => 'message-center.php',
        'query' => http_build_query(['view' => 'test']),
        'markers' => ['data-message-command-strip', 'Kanal ve novbe veziyyeti', 'data-message-channel-chart'],
        'forbidden_markers' => ['Provider test paneli', 'providerDestination'],
    ],
    [
        'name' => 'ip_pools',
        'page' => 'ip-pools.php',
        'query' => '',
        'markers' => ['RADIUS IP engine', 'Static IP', 'ip-progress'],
    ],
    [
        'name' => 'noc_devices',
        'page' => 'noc-devices.php',
        'query' => 'view=monitor',
        'markers' => ['NOC cihaz paneli', 'data-native-monitor-board', 'data-monitor-node', 'Tehlukesiz emeliyyatlar', 'Servis qati'],
    ],
    [
        'name' => 'noc_devices_learning',
        'page' => 'noc-devices.php',
        'query' => 'view=devices',
        'markers' => ['NOC cihaz paneli', 'Avadanliq oyrenme profilleri', 'data-noc-open-device-detail'],
    ],
    [
        'name' => 'noc_devices_ont_search',
        'page' => 'noc-devices.php',
        'query' => 'view=ont-search',
        'markers' => ['NOC cihaz paneli', 'ONT/SN/MAC native axtarisi', 'name="ont_q"'],
    ],
    [
        'name' => 'noc_devices_backups',
        'page' => 'noc-devices.php',
        'query' => 'view=backups',
        'markers' => ['NOC cihaz paneli', 'Son backup versiyalari', 'Son alarm/dedup veziyyeti'],
    ],
    [
        'name' => 'operations_center',
        'page' => 'operations-center.php',
        'query' => '',
        'markers' => ['Operations Center', 'Servis statusu'],
    ],
    [
        'name' => 'operations_inventory',
        'page' => 'operations-center.php',
        'query' => 'section=inventory',
        'markers' => ['data-inventory-module', 'data-inventory-overview'],
    ],
    [
        'name' => 'operations_inventory_handover',
        'page' => 'operations-center.php',
        'query' => 'section=inventory&inventory_tab=handover',
        'markers' => ['data-inventory-handover', 'name="action" value="create_inventory_handover_local"'],
    ],
    [
        'name' => 'operations_finance',
        'page' => 'operations-center.php',
        'query' => 'section=finance',
        'markers' => ['data-finance-module', 'data-finance-overview'],
    ],
    [
        'name' => 'operations_hr',
        'page' => 'operations-center.php',
        'query' => 'section=hr',
        'markers' => ['data-hr-module', 'data-hr-overview'],
    ],
    [
        'name' => 'operations_hr_attendance',
        'page' => 'operations-center.php',
        'query' => 'section=hr&hr_tab=attendance',
        'markers' => ['data-hr-attendance', 'name="action" value="save_attendance_profile"'],
    ],
    [
        'name' => 'crm_board',
        'page' => 'crm.php',
        'query' => '',
        'markers' => ['Kanban', 'mgs-kanban', 'Filtr ve axtaris'],
    ],
    [
        'name' => 'crm',
        'page' => 'crm.php',
        'query' => http_build_query(['create' => '1']),
        'markers' => ['data-crm-customer-lookup', 'data-crm-recent-customers', 'crmCustomerOptions', 'data-crm-area-select'],
    ],
    [
        'name' => 'operations_check',
        'page' => 'billing-operations-check.php',
        'query' => '',
        'markers' => ['Billing operations readiness', 'Critical failed: 0'],
    ],
    [
        'name' => 'mgs_integration',
        'page' => 'mgs-integration.php',
        'query' => '',
        'markers' => ['Sistem servislari', 'Sistem servislari bolmeleri', 'Single pane of glass', 'view=processes', 'view=actions', 'view=bots', 'view=devices', 'view=backups', 'view=logs'],
    ],
    [
        'name' => 'mgs_integration_processes',
        'page' => 'mgs-integration.php',
        'query' => 'view=processes',
        'markers' => ['Vahid proses registry', 'Dedup ve spam qoruma registry'],
    ],
    [
        'name' => 'mgs_integration_actions',
        'page' => 'mgs-integration.php',
        'query' => 'view=actions',
        'markers' => ['Test ve sync emeliyyatlari', 'Telegram bot test', 'name="action" value="sync_bots"', 'Inventar hereket testi', 'Copilot konteksti'],
    ],
    [
        'name' => 'mgs_integration_bots',
        'page' => 'mgs-integration.php',
        'query' => 'view=bots',
        'markers' => ['Telegram bot registry'],
    ],
    [
        'name' => 'mgs_integration_devices',
        'page' => 'mgs-integration.php',
        'query' => 'view=devices',
        'markers' => ['Avadanliq oyrenme profilleri'],
    ],
    [
        'name' => 'mgs_integration_backups',
        'page' => 'mgs-integration.php',
        'query' => 'view=backups',
        'markers' => ['Device backup lifecycle'],
    ],
    [
        'name' => 'mgs_integration_logs',
        'page' => 'mgs-integration.php',
        'query' => 'view=logs',
        'markers' => ['Son alarmlar', 'Son emeliyyatlar', 'Isci nezareti', 'Inventar current state'],
    ],
    [
        'name' => 'identity_integrations',
        'page' => 'identity-integrations.php',
        'query' => '',
        'markers' => ['MyGov ID inteqrasiyasi', 'Identity-ni hesaba bagla', 'Bagli MyGov identity'],
    ],
    [
        'name' => 'ai_overview',
        'page' => 'ai-dashboard.php',
        'query' => '',
        'markers' => ['data-ai-module', 'data-ai-overview', 'Kritik AI xeberdarliqlari'],
    ],
    [
        'name' => 'ai_network_analytics',
        'page' => 'ai-network-analytics.php',
        'query' => '',
        'markers' => ['data-ai-network-analytics', 'Sebeke analiz serhedi'],
    ],
    [
        'name' => 'mgs_copilot_context_api',
        'page' => 'mgs-copilot-context.php',
        'query' => 'format=json',
        'markers' => ['read_only_copilot_context', 'next_safe_actions', 'telegram_bots', 'devices'],
    ],
];

$results = [];
$failed = 0;
$errorPatterns = billing_ui_smoke_error_patterns();
$suiteStartedAt = microtime(true);
foreach ($pages as $pageSpec) {
    $render = billing_ui_smoke_run_process((string) $pageSpec['page'], (string) $pageSpec['query']);
    $html = (string) $render['stdout'];
    $stderr = trim((string) $render['stderr']);
    $missingMarkers = [];
    $expectedMarkers = (array) $pageSpec['markers'];
    if (($pageSpec['name'] ?? '') === 'payments') {
        $expectedMarkers = ['data-billing-legacy-module', 'data-payment-kpi', 'portal-table', 'billing-customer-profile.php', 'Provider import'];
    }
    foreach ($expectedMarkers as $marker) {
        $marker = (string) $marker;
        if ($marker !== '' && !str_contains($html, $marker)) {
            $missingMarkers[] = $marker;
        }
    }

    $unexpectedMarkers = [];
    foreach ((array) ($pageSpec['forbidden_markers'] ?? []) as $marker) {
        $marker = (string) $marker;
        if ($marker !== '' && str_contains($html, $marker)) {
            $unexpectedMarkers[] = $marker;
        }
    }

    $matchedErrors = [];
    foreach ($errorPatterns as $pattern) {
        if (preg_match($pattern, $html . "\n" . $stderr) === 1) {
            $matchedErrors[] = $pattern;
        }
    }

    $ok = (int) $render['exit_code'] === 0
        && $missingMarkers === []
        && $unexpectedMarkers === []
        && $matchedErrors === [];
    if (!$ok) {
        $failed++;
    }

    $results[] = [
        'name' => (string) $pageSpec['name'],
        'page' => (string) $pageSpec['page'],
        'ok' => $ok,
        'exit_code' => (int) $render['exit_code'],
        'duration_ms' => (int) ($render['duration_ms'] ?? 0),
        'html_bytes' => strlen($html),
        'missing_markers' => $missingMarkers,
        'unexpected_markers' => $unexpectedMarkers,
        'matched_errors' => $matchedErrors,
        'stderr' => $stderr,
    ];
}

billing_ui_smoke_json([
    'status' => $failed === 0 ? 'ok' : 'failed',
    'sample_customer' => [
        'id' => $customerId,
        'subscriber_code' => $customerCode,
        'name' => (string) ($customer['full_name'] ?? ''),
    ],
    'checked_pages' => count($pages),
    'failed' => $failed,
    'duration_ms' => (int) round((microtime(true) - $suiteStartedAt) * 1000),
    'results' => $results,
    'generated_at' => date(DATE_ATOM),
], $failed === 0 ? 0 : 4);
