<?php
declare(strict_types=1);

/**
 * Read-only monitoring snapshot for the AI dashboard's continuous watch loop.
 * GET, service-token. Returns compact numeric health signals from existing
 * advisor read-models. Never writes.
 */

require_once dirname(__DIR__, 2) . '/advisor-agent-lib.php';
require_once dirname(__DIR__, 2) . '/crew-service-auth-lib.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function mon_fail(int $code, string $reason): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    mon_fail(405, 'method_not_allowed');
}
$expected = getenv('CREW_INGEST_TOKEN');
$expected = is_string($expected) ? trim($expected) : '';
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $hName => $hVal) {
        if (strcasecmp($hName, 'Authorization') === 0) { $auth = (string) $hVal; break; }
    }
}
$presented = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1 ? trim($m[1]) : '';
$ingestAuthorized = $expected !== '' && strlen($expected) >= 24 && $presented !== '' && hash_equals($expected, $presented);
$serviceActor = null;
$db = null;
if (!$ingestAuthorized) {
    // Allow the scoped NOC service account to read the same compact network
    // snapshot. This keeps the agent on its least-privilege service token and
    // avoids requiring a second untracked ingest credential.
    $db = app_db();
    if (!$db instanceof PDO) {
        mon_fail(503, 'database_unavailable');
    }
    $serviceActor = app_crew_service_actor($db, app_crew_service_bearer());
    if ($serviceActor === null) {
        mon_fail($expected === '' || strlen($expected) < 24 ? 503 : 401,
            $expected === '' || strlen($expected) < 24 ? 'ingest_disabled' : 'auth_required');
    }
    if (!app_portal_can_access_module($serviceActor, 'network')) {
        mon_fail(403, 'module_denied');
    }
}

$db = $db instanceof PDO ? $db : app_db();
if (!$db instanceof PDO) {
    mon_fail(503, 'database_unavailable');
}

$safe = static function (callable $fn) {
    try {
        $v = $fn();
        return $v === null ? null : (int) $v;
    } catch (Throwable) {
        return null;
    }
};
$safeValue = static function (callable $fn) {
    try {
        $v = $fn();
        return is_scalar($v) || $v === null ? $v : null;
    } catch (Throwable) {
        return null;
    }
};

$signals = [
    'network' => [
        'open_tickets' => $safe(fn() => app_advisor_read_model_open_network_tickets($db)),
        'unassigned'   => $safe(fn() => app_advisor_read_model_unassigned_open_tickets($db)),
        'blocked'      => $safe(fn() => app_advisor_read_model_dept_blocked_tickets($db)),
        'stale'        => $safe(fn() => app_advisor_read_model_stale_open_tickets($db, 3)),
        'devices' => [
            'monitored' => $safe(fn() => $db->query("SELECT COUNT(*) FROM device_learning_profiles WHERE monitoring_enabled = 1")->fetchColumn()),
            'reachable' => $safe(fn() => $db->query("SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = 'reachable'")->fetchColumn()),
            'unreachable' => $safe(fn() => $db->query("SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state IN ('unreachable_confirmed','unreachable_because_monitor_path_down')")->fetchColumn()),
            'unknown' => $safe(fn() => $db->query("SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = 'unknown'")->fetchColumn()),
        ],
        'alarms' => [
            'open' => $safe(fn() => $db->query("SELECT COUNT(*) FROM mgs_alarm_events WHERE status = 'open'")->fetchColumn()),
            'critical' => $safe(fn() => $db->query("SELECT COUNT(*) FROM mgs_alarm_events WHERE status = 'open' AND severity = 'critical'")->fetchColumn()),
            'high' => $safe(fn() => $db->query("SELECT COUNT(*) FROM mgs_alarm_events WHERE status = 'open' AND severity = 'high'")->fetchColumn()),
        ],
        'traffic' => [
            'samples_last_15m' => $safe(fn() => $db->query("SELECT COUNT(*) FROM device_interface_traffic_samples WHERE sampled_at >= (NOW() - INTERVAL 15 MINUTE)")->fetchColumn()),
            'last_sample_at' => $safeValue(fn() => $db->query("SELECT MAX(sampled_at) FROM device_interface_traffic_samples")->fetchColumn()),
        ],
    ],
    'inventory' => [
        'critical_stock' => $safe(fn() => app_advisor_read_model_critical_stock_count($db)),
    ],
    'crm' => [
        'inactive_customers' => $safe(fn() => app_advisor_read_model_long_inactive_customer_count($db, 60)),
    ],
];

if ($serviceActor !== null) {
    // A NOC service token receives only network signals, never inventory/CRM
    // counts outside its module scope.
    $signals = ['network' => $signals['network']];
}

echo json_encode(['ok' => true, 'ts' => date('Y-m-d H:i:s'), 'signals' => $signals],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
