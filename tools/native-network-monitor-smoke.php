<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function native_network_monitor_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

function native_network_monitor_render(string $page = 'operations-center.php', string $query = '', ?array $scanState = null): string
{
    $db = app_db();
    if (!$db instanceof PDO) {
        native_network_monitor_fail('Database unavailable', 2);
    }

    $employee = $db->query(
        "SELECT *
         FROM portal_accounts
         WHERE role = 'employee' AND is_active = 1
         ORDER BY id ASC
         LIMIT 1"
    )?->fetch();

    if (!is_array($employee)) {
        native_network_monitor_fail('No active employee account', 3);
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
    if ($scanState !== null) {
        $_SESSION['noc_scan_state'] = $scanState;
    } else {
        unset($_SESSION['noc_scan_state']);
    }

    chdir(dirname(__DIR__));
    ob_start();
    include dirname(__DIR__) . '/' . $page;
    return (string) ob_get_clean();
}

$html = native_network_monitor_render('noc-devices.php', 'view=monitor');
$markers = [
    'data-native-monitor-board',
    'data-noc-shell',
    'data-noc-content',
    'data-noc-status',
    'SuperopticReloadNocContent',
    'SuperopticInitNocMonitor',
    'history.pushState',
    'popstate',
    'monitor-board',
    'monitor-node',
    'noc-monitor-side',
    'noc-monitor-side-card',
    'name="scan_target"',
    'value="scan_network"',
    'value="create_monitor_device"',
    'Cihaz elave et',
    'Sebekeni IP scan et',
    'data-noc-scan-dialog',
    'role="dialog"',
    'data-noc-scan-results',
    'data-scan-add-device',
    'data-scan-close',
    'Secilenleri monitora elave et',
    'Scan tamamlandi. Tapilan cihazlari secib monitorinqe elave edin',
    'noc-scan-dialog is-empty',
    'noc-scan-dialog.is-open',
    'maintenance',
];

foreach ($markers as $marker) {
    if (!str_contains($html, $marker)) {
        native_network_monitor_fail('NOC monitor missing marker: ' . $marker, 4);
    }
}

$scanModalHtml = native_network_monitor_render('noc-devices.php', 'view=monitor', [
    'target' => '172.16.5.0/24',
    'results' => [[
        'ipAddress' => '172.16.5.10',
        'hostname' => 'Smoke HSGQ',
        'deviceType' => 'olt',
        'vendor' => 'HSGQ',
        'openPorts' => ['22', '161'],
    ]],
]);
foreach ([
    'noc-scan-dialog is-open',
    'Smoke HSGQ',
    '172.16.5.10',
    'Bagla',
] as $marker) {
    if (!str_contains($scanModalHtml, $marker)) {
        native_network_monitor_fail('NOC scan modal open state missing marker: ' . $marker, 4);
    }
}

if (!str_contains($html, 'Management path')
    && !str_contains($html, 'VLAN 550')
    && !str_contains($html, 'offline spam blok')) {
    native_network_monitor_fail('NOC monitor missing management path alarm state marker', 4);
}

$nocDevicesHtml = native_network_monitor_render('noc-devices.php', 'view=devices');
$nocDeviceMarkers = [
    'Avadanliq oyrenme profilleri',
    'noc-device-card',
    'noc-device-meta is-summary',
    'data-noc-open-device-detail',
    'view=devices&amp;device=',
];

foreach ($nocDeviceMarkers as $marker) {
    if (!str_contains($nocDevicesHtml, $marker)) {
        native_network_monitor_fail('NOC devices view missing native marker: ' . $marker, 5);
    }
}

if (str_contains($nocDevicesHtml, 'data-noc-device-drawer')) {
    native_network_monitor_fail('NOC devices summary view should not pre-render every detail drawer', 5);
}

if (!preg_match('/view=devices&amp;device=([^"&#]+)/', $nocDevicesHtml, $match)) {
    native_network_monitor_fail('NOC devices view did not expose a device detail link', 5);
}

$selectedDeviceHtml = native_network_monitor_render('noc-devices.php', 'view=devices&device=' . html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
foreach ([
    'data-noc-device-drawer',
    'name="action" value="set_device_monitoring_mode"',
] as $marker) {
    if (!str_contains($selectedDeviceHtml, $marker)) {
        native_network_monitor_fail('NOC selected device drawer missing marker: ' . $marker, 5);
    }
}

$summaryPosition = strpos($selectedDeviceHtml, 'noc-device-meta is-summary');
$drawerPosition = strpos($selectedDeviceHtml, 'data-noc-device-drawer');
if ($summaryPosition === false || $drawerPosition === false || $drawerPosition < $summaryPosition) {
    native_network_monitor_fail('NOC selected device drawer is not behind the summary card', 5);
}

if (preg_match('/view=devices&amp;device=(olt-hsgq[^"&#]+)/', $nocDevicesHtml, $oltMatch)) {
    $selectedOltHtml = native_network_monitor_render('noc-devices.php', 'view=devices&device=' . html_entity_decode((string) $oltMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    foreach ([
        'data-live-ont-card-action',
        'name="action" value="live_ont_read"',
    ] as $marker) {
        if (!str_contains($selectedOltHtml, $marker)) {
            native_network_monitor_fail('NOC selected HSGQ OLT drawer missing marker: ' . $marker, 5);
        }
    }
}

echo json_encode([
    'status' => 'ok',
    'checks' => [
        'native_monitor_markers' => true,
        'noc_native_markers' => true,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
