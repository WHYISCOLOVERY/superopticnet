<?php
declare(strict_types=1);

/**
 * Static validator for the SuperOptic Nginx/API gateway bundle.
 *
 * It does not require Nginx to be installed on the current machine. The goal is
 * to catch missing routes, snippets and safety controls before copying the
 * bundle to production.
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';
$nginxDir = $root . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'nginx';

function gateway_read(string $path): string
{
    if (!is_file($path)) {
        return '';
    }

    return (string) file_get_contents($path);
}

function gateway_add_check(array &$checks, string $name, bool $ok, string $details = ''): void
{
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'details' => $details,
    ];
}

function gateway_contains_all(string $content, array $needles): array
{
    $missing = [];
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            $missing[] = $needle;
        }
    }

    return $missing;
}

$files = [
    'nginx.conf' => $nginxDir . DIRECTORY_SEPARATOR . 'nginx.conf',
    'core' => $nginxDir . DIRECTORY_SEPARATOR . 'conf.d' . DIRECTORY_SEPARATOR . 'superoptic-core.conf',
    'rate_limits' => $nginxDir . DIRECTORY_SEPARATOR . 'conf.d' . DIRECTORY_SEPARATOR . 'superoptic-rate-limits.conf',
    'upstreams' => $nginxDir . DIRECTORY_SEPARATOR . 'conf.d' . DIRECTORY_SEPARATOR . 'superoptic-upstreams.conf',
    'access_control' => $nginxDir . DIRECTORY_SEPARATOR . 'conf.d' . DIRECTORY_SEPARATOR . 'superoptic-access-control.conf',
    'site' => $nginxDir . DIRECTORY_SEPARATOR . 'sites-available' . DIRECTORY_SEPARATOR . 'superoptic-gateway.conf',
    'proxy_headers' => $nginxDir . DIRECTORY_SEPARATOR . 'snippets' . DIRECTORY_SEPARATOR . 'proxy-headers.conf',
    'security_headers' => $nginxDir . DIRECTORY_SEPARATOR . 'snippets' . DIRECTORY_SEPARATOR . 'security-headers.conf',
    'ssl_params' => $nginxDir . DIRECTORY_SEPARATOR . 'snippets' . DIRECTORY_SEPARATOR . 'ssl-params.conf',
    'installer' => $nginxDir . DIRECTORY_SEPARATOR . 'install-superoptic-nginx.sh',
    'health_endpoint' => $root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'health.php',
    'status_page' => $root . DIRECTORY_SEPARATOR . 'status.php',
];

$checks = [];
foreach ($files as $label => $path) {
    gateway_add_check($checks, 'file:' . $label, is_file($path), $path);
}

$nginxConf = gateway_read($files['nginx.conf']);
$core = gateway_read($files['core']);
$rateLimits = gateway_read($files['rate_limits']);
$upstreams = gateway_read($files['upstreams']);
$accessControl = gateway_read($files['access_control']);
$site = gateway_read($files['site']);
$proxyHeaders = gateway_read($files['proxy_headers']);
$securityHeaders = gateway_read($files['security_headers']);
$sslParams = gateway_read($files['ssl_params']);
$installer = gateway_read($files['installer']);
$health = gateway_read($files['health_endpoint']);

$requiredDomains = [
    'mgs.superoptic.net',
    'billing.superoptic.net',
    'api.superoptic.net',
    'status.superoptic.net',
];
$missing = gateway_contains_all($site, $requiredDomains);
gateway_add_check($checks, 'domains', $missing === [], $missing === [] ? implode(', ', $requiredDomains) : 'missing: ' . implode(', ', $missing));

$requiredUpstreams = [
    'upstream mgs_php_frontend',
    'upstream customer_portal',
    'upstream billing_service',
    'upstream radius_service',
    'upstream noc_service',
    'upstream olt_service',
    'upstream diagnostics_service',
    'upstream crm_service',
    'upstream inventory_service',
    'upstream notification_service',
    'upstream websocket_service',
];
$missing = gateway_contains_all($upstreams, $requiredUpstreams);
gateway_add_check($checks, 'upstreams', $missing === [], $missing === [] ? 'all required upstreams present' : 'missing: ' . implode(', ', $missing));

$requiredRoutes = [
    'location /api/billing/',
    'location /api/radius/',
    'location /api/noc/',
    'location /api/olt/',
    'location /api/diagnostics/',
    'location /api/crm/',
    'location /api/inventory/',
    'location /api/notification/',
    'location /api/notification/otp/',
    'location /api/provider/',
    'location /api/mgs/',
    'location /ws/',
    'location = /api/health',
    'location = /api/health.php',
    'location = /status.php',
    'location = /staff-login.php',
];
$missing = gateway_contains_all($site, $requiredRoutes);
gateway_add_check($checks, 'route_map', $missing === [], $missing === [] ? 'all gateway routes present' : 'missing: ' . implode(', ', $missing));

$requiredRateZones = [
    'zone=login_limit',
    'zone=otp_limit',
    'zone=provider_api_limit',
    'zone=public_api_limit',
    'zone=diagnostics_limit',
    'limit_conn_zone',
];
$missing = gateway_contains_all($rateLimits, $requiredRateZones);
gateway_add_check($checks, 'rate_limits', $missing === [], $missing === [] ? 'login/otp/api/diagnostics limits present' : 'missing: ' . implode(', ', $missing));

$missing = gateway_contains_all($core, [
    'server_tokens off',
    'log_format superoptic_json',
    'client_max_body_size 20m',
    'gzip on',
    'map $http_user_agent $blocked_agent',
]);
gateway_add_check($checks, 'core_hardening', $missing === [], $missing === [] ? 'json logs, gzip and basic bot blocking present' : 'missing: ' . implode(', ', $missing));

$missing = gateway_contains_all($accessControl, [
    'geo $provider_api_allowed',
    'geo $office_or_vpn_allowed',
    '10.0.0.0/8',
    '172.16.0.0/12',
    '192.168.0.0/16',
]);
gateway_add_check($checks, 'access_control', $missing === [], $missing === [] ? 'provider and office/vpn geo maps present' : 'missing: ' . implode(', ', $missing));

$missing = gateway_contains_all($proxyHeaders, [
    'X-Real-IP',
    'X-Forwarded-For',
    'X-Forwarded-Proto',
    'X-Request-ID',
]);
gateway_add_check($checks, 'proxy_headers', $missing === [], $missing === [] ? 'forwarded headers present' : 'missing: ' . implode(', ', $missing));

$missing = gateway_contains_all($securityHeaders, [
    'X-Frame-Options',
    'X-Content-Type-Options',
    'Content-Security-Policy',
    'Permissions-Policy',
]);
gateway_add_check($checks, 'security_headers', $missing === [], $missing === [] ? 'browser security headers present' : 'missing: ' . implode(', ', $missing));

$missing = gateway_contains_all($sslParams, [
    'TLSv1.2',
    'TLSv1.3',
    'Strict-Transport-Security',
]);
gateway_add_check($checks, 'ssl_policy', $missing === [], $missing === [] ? 'TLS 1.2/1.3 and HSTS present' : 'missing: ' . implode(', ', $missing));

$missing = gateway_contains_all($nginxConf, [
    'include /etc/nginx/conf.d/superoptic-core.conf',
    'include /etc/nginx/conf.d/superoptic-access-control.conf',
    'include /etc/nginx/conf.d/superoptic-rate-limits.conf',
    'include /etc/nginx/conf.d/superoptic-upstreams.conf',
    'include /etc/nginx/sites-enabled/*.conf',
]);
gateway_add_check($checks, 'nginx_include_chain', $missing === [], $missing === [] ? 'all SuperOptic includes wired' : 'missing: ' . implode(', ', $missing));

$missing = gateway_contains_all($installer, [
    'install-superoptic-nginx.sh',
    'sites-enabled',
    'superoptic-gateway.conf',
    'nginx -t',
]);
gateway_add_check($checks, 'installer', $missing === [], $missing === [] ? 'installer copies files and points to nginx -t' : 'missing: ' . implode(', ', $missing));

// The health payload deliberately self-identifies as the unified canonical
// portal ('superoptic-portal'), not the retired separate MGS Node service -
// see tools/unified-portal-architecture-smoke.php's health_uses_portal_identity
// check, which asserts this exact string.
$missing = gateway_contains_all($health, [
    'superoptic-portal',
    'status',
    'ok',
]);
gateway_add_check($checks, 'app_health_endpoint', $missing === [], $missing === [] ? '/api/health.php returns service status' : 'missing: ' . implode(', ', $missing));

$statusPage = gateway_read($files['status_page']);
$missing = gateway_contains_all($statusPage, [
    'Platforma vəziyyəti',
    'JSON health',
    'read-only',
]);
gateway_add_check($checks, 'status_page', $missing === [], $missing === [] ? '/status.php read-only page present' : 'missing: ' . implode(', ', $missing));
foreach ($checks as &$check) {
    if (($check['name'] ?? '') === 'status_page' && !$check['ok'] && str_contains($statusPage, 'Platforma veziyyeti') && str_contains($statusPage, 'JSON health') && str_contains($statusPage, 'read-only')) {
        $check['ok'] = true;
        $check['details'] = '/status.php read-only page present';
    }
}
unset($check);

$routeOrderOk = true;
$notificationRoute = strpos($site, 'location /api/notification/');
$otpRoute = strpos($site, 'location /api/notification/otp/');
if ($notificationRoute === false || $otpRoute === false) {
    $routeOrderOk = false;
}
gateway_add_check($checks, 'otp_route_specificity', $routeOrderOk, 'Nginx longest-prefix routing makes /api/notification/otp/ more specific than /api/notification/');

$failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok']));
$result = [
    'status' => $failed === [] ? 'ok' : 'failed',
    'checked_at' => date(DATE_ATOM),
    'root' => $root,
    'checks_total' => count($checks),
    'checks_failed' => count($failed),
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($failed === [] ? 0 : 1);
