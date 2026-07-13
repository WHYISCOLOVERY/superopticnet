<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function mgs_sso_fail(string $message, array $extra = [], int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
        'extra' => $extra,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

function mgs_sso_http_request(string $url, array $options = []): array
{
    $method = strtoupper((string) ($options['method'] ?? 'GET'));
    $headers = $options['headers'] ?? [];
    $content = $options['content'] ?? null;
    $timeout = (float) ($options['timeout'] ?? 10.0);

    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => $timeout,
            'header' => implode("\r\n", $headerLines),
            'content' => $content,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $statusLine = $responseHeaders[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    $statusCode = isset($match[1]) ? (int) $match[1] : 0;

    return [
        'status_code' => $statusCode,
        'headers' => $responseHeaders,
        'body' => is_string($body) ? $body : '',
        'status_line' => $statusLine,
    ];
}

function mgs_sso_base_url(): string
{
    $entrypoint = trim((string) app_config('olt_sso_entrypoint', 'http://localhost:3000/api/auth/sso/consume'));
    $parts = parse_url($entrypoint);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return 'http://localhost:3000';
    }

    $base = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $base .= ':' . (int) $parts['port'];
    }

    return $base;
}

function mgs_sso_extract_header(array $headers, string $name): array
{
    $matches = [];
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) {
            $matches[] = trim(substr($header, strlen($name) + 1));
        }
    }
    return $matches;
}

function mgs_sso_extract_iframe_src(string $html): string
{
    if (!preg_match('/<iframe[^>]+src="([^"]+)"/i', $html, $match)) {
        return '';
    }
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
}

function mgs_sso_employee(PDO $db): array
{
    $statement = $db->query(
        "SELECT *
         FROM portal_accounts
         WHERE role = 'employee' AND is_active = 1
         ORDER BY FIELD(account_type, 'admin', 'operator', 'technician') ASC, id
         LIMIT 1"
    );
    $row = $statement ? $statement->fetch() : false;
    if (!is_array($row)) {
        mgs_sso_fail('No active employee account', [], 3);
    }
    return $row;
}

function mgs_sso_render_bridge(string $module): string
{
    $db = app_db();
    if (!$db instanceof PDO) {
        mgs_sso_fail('Database unavailable', [], 2);
    }
    $employee = mgs_sso_employee($db);

    $_GET = ['module' => $module];
    $_POST = [];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/superopticnet/mgs-bridge.php?module=' . rawurlencode($module);
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/mgs-bridge.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    app_session_boot();
    $_SESSION['portal_user'] = app_db_user_session_payload($employee);

    chdir(dirname(__DIR__));
    ob_start();
    include dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mgs-bridge.php';
    return (string) ob_get_clean();
}

$html = mgs_sso_render_bridge('inventory');
$iframeSrc = mgs_sso_extract_iframe_src($html);
if ($iframeSrc === '') {
    mgs_sso_fail('Iframe src not found in mgs-bridge render');
}
if (!str_contains($iframeSrc, '/api/auth/sso/consume')) {
    mgs_sso_fail('Bridge does not use SSO consume URL', ['iframe_src' => $iframeSrc]);
}

$ssoResponse = mgs_sso_http_request($iframeSrc);
if ($ssoResponse['status_code'] !== 302) {
    mgs_sso_fail('SSO consume did not redirect', ['status' => $ssoResponse['status_code'], 'status_line' => $ssoResponse['status_line']]);
}

$setCookies = mgs_sso_extract_header($ssoResponse['headers'], 'Set-Cookie');
if ($setCookies === []) {
    mgs_sso_fail('SSO consume did not return refresh cookie', ['headers' => $ssoResponse['headers']]);
}

$cookieHeader = [];
foreach ($setCookies as $cookieLine) {
    $parts = explode(';', $cookieLine);
    $cookieHeader[] = trim((string) ($parts[0] ?? ''));
}
$cookieHeaderValue = implode('; ', array_filter($cookieHeader));
if ($cookieHeaderValue === '') {
    mgs_sso_fail('Refresh cookie header could not be parsed', ['cookies' => $setCookies]);
}

$mgsBaseUrl = mgs_sso_base_url();

$refreshResponse = mgs_sso_http_request($mgsBaseUrl . '/api/auth/refresh', [
    'method' => 'POST',
    'headers' => [
        'Cookie' => $cookieHeaderValue,
        'Content-Type' => 'application/json',
    ],
    'content' => '{}',
]);
if ($refreshResponse['status_code'] !== 200) {
    mgs_sso_fail('Refresh endpoint did not return 200', ['status' => $refreshResponse['status_code'], 'body' => $refreshResponse['body']]);
}

$refreshPayload = json_decode($refreshResponse['body'], true);
if (!is_array($refreshPayload) || !is_string($refreshPayload['accessToken'] ?? null) || $refreshPayload['accessToken'] === '') {
    mgs_sso_fail('Access token missing after refresh', ['body' => $refreshResponse['body']]);
}

$dashboardResponse = mgs_sso_http_request($mgsBaseUrl . '/api/dashboard', [
    'method' => 'GET',
    'headers' => [
        'Authorization' => 'Bearer ' . $refreshPayload['accessToken'],
    ],
]);
if ($dashboardResponse['status_code'] !== 200) {
    mgs_sso_fail('Protected dashboard API still unauthorized', [
        'status' => $dashboardResponse['status_code'],
        'body' => substr($dashboardResponse['body'], 0, 400),
    ]);
}

$dashboardPayload = json_decode($dashboardResponse['body'], true);
if (!is_array($dashboardPayload)) {
    mgs_sso_fail('Dashboard payload is not valid JSON', ['body' => substr($dashboardResponse['body'], 0, 400)]);
}

echo json_encode([
    'status' => 'ok',
    'checks' => [
        'bridge_uses_sso_consume' => true,
        'sso_sets_refresh_cookie' => true,
        'refresh_returns_access_token' => true,
        'protected_dashboard_api' => true,
    ],
    'iframe_src' => $iframeSrc,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
