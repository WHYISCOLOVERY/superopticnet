<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function mgs_bridge_modules_fail(string $message, array $extra = [], int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
        'extra' => $extra,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

function mgs_bridge_modules_http_request(string $url, array $options = []): array
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

function mgs_bridge_modules_base_url(): string
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

function mgs_bridge_modules_extract_header(array $headers, string $name): array
{
    $matches = [];
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) {
            $matches[] = trim(substr($header, strlen($name) + 1));
        }
    }

    return $matches;
}

function mgs_bridge_modules_extract_iframe_src(string $html): string
{
    if (!preg_match('/<iframe[^>]+src="([^"]+)"/i', $html, $match)) {
        return '';
    }

    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
}

function mgs_bridge_modules_employee(PDO $db): array
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
        mgs_bridge_modules_fail('No active employee account', [], 3);
    }

    return $row;
}

function mgs_bridge_modules_render(string $module): string
{
    $db = app_db();
    if (!$db instanceof PDO) {
        mgs_bridge_modules_fail('Database unavailable', [], 2);
    }
    $employee = mgs_bridge_modules_employee($db);

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

function mgs_bridge_modules_refresh_token(string $cookieHeader): string
{
    $refreshResponse = mgs_bridge_modules_http_request('http://127.0.0.1:3000/api/auth/refresh', [
        'method' => 'POST',
        'headers' => [
            'Cookie' => $cookieHeader,
            'Content-Type' => 'application/json',
        ],
        'content' => '{}',
    ]);

    if ($refreshResponse['status_code'] !== 200) {
        mgs_bridge_modules_fail('Refresh endpoint did not return 200', [
            'status' => $refreshResponse['status_code'],
            'body' => $refreshResponse['body'],
        ]);
    }

    $refreshPayload = json_decode($refreshResponse['body'], true);
    $token = is_array($refreshPayload) ? (string) ($refreshPayload['accessToken'] ?? '') : '';
    if ($token === '') {
        mgs_bridge_modules_fail('Access token missing after refresh', ['body' => $refreshResponse['body']]);
    }

    return $token;
}

function mgs_bridge_modules_build_cookie_header(array $setCookies): string
{
    $cookieHeader = [];
    foreach ($setCookies as $cookieLine) {
        $parts = explode(';', $cookieLine);
        $cookieHeader[] = trim((string) ($parts[0] ?? ''));
    }

    return implode('; ', array_filter($cookieHeader));
}

$mgsBaseUrl = mgs_bridge_modules_base_url();

$moduleChecks = [
    'monitor' => [
        'page' => 'dashboard',
        'api' => $mgsBaseUrl . '/api/dashboard',
        'expect' => 'summary',
    ],
    'ont' => [
        'page' => 'onts',
        'api' => $mgsBaseUrl . '/api/search/ont-diagnostics?q=1100000&live=0',
        'expect' => 'query',
    ],
    'mpls' => [
        'page' => 'mpls',
        'api' => $mgsBaseUrl . '/api/mpls',
        'expect' => 'services',
    ],
    'inventory' => [
        'page' => 'inventory',
        'api' => $mgsBaseUrl . '/api/inventory',
        'expect' => 'products',
    ],
    'finance' => [
        'page' => 'finance',
        'api' => $mgsBaseUrl . '/api/finance',
        'expect' => 'entries',
    ],
    'technical' => [
        'page' => 'technical',
        'api' => $mgsBaseUrl . '/api/technical-tasks',
        'expect' => 'tasks',
    ],
    'kpi' => [
        'page' => 'kpi',
        'api' => $mgsBaseUrl . '/api/kpi',
        'expect' => 'summary',
    ],
    'reports' => [
        'page' => 'reports',
        'api' => $mgsBaseUrl . '/api/reports',
        'expect' => 'summary',
    ],
    'stats' => [
        'page' => 'stats',
        'api' => $mgsBaseUrl . '/api/statistics?period=monthly',
        'expect' => 'period',
    ],
    'logs' => [
        'page' => 'logs',
        'api' => $mgsBaseUrl . '/api/noc/intelligence',
        'expect' => 'summary',
    ],
    'users' => [
        'page' => 'users',
        'api' => $mgsBaseUrl . '/api/users',
        'expect' => 'users',
    ],
    'hr' => [
        'page' => 'users',
        'api' => $mgsBaseUrl . '/api/users',
        'expect' => 'users',
    ],
];

$results = [];

foreach ($moduleChecks as $module => $check) {
    $html = mgs_bridge_modules_render($module);
    $iframeSrc = mgs_bridge_modules_extract_iframe_src($html);
    if ($iframeSrc === '') {
        mgs_bridge_modules_fail('Iframe src not found', ['module' => $module]);
    }
    if (!str_contains($iframeSrc, '/api/auth/sso/consume')) {
        mgs_bridge_modules_fail('Bridge does not use SSO consume URL', ['module' => $module, 'iframe_src' => $iframeSrc]);
    }

    $ssoResponse = mgs_bridge_modules_http_request($iframeSrc);
    if ($ssoResponse['status_code'] !== 302) {
        mgs_bridge_modules_fail('SSO consume did not redirect', [
            'module' => $module,
            'status' => $ssoResponse['status_code'],
            'status_line' => $ssoResponse['status_line'],
        ]);
    }

    $setCookies = mgs_bridge_modules_extract_header($ssoResponse['headers'], 'Set-Cookie');
    if ($setCookies === []) {
        mgs_bridge_modules_fail('SSO consume did not return refresh cookie', ['module' => $module]);
    }

    $locationHeaders = mgs_bridge_modules_extract_header($ssoResponse['headers'], 'Location');
    $location = (string) ($locationHeaders[0] ?? '');
    if ($location === '' || !str_contains($location, '?mgs_page=' . $check['page'])) {
        mgs_bridge_modules_fail('SSO redirect target mismatch', [
            'module' => $module,
            'expected_page' => $check['page'],
            'location' => $location,
        ]);
    }

    $cookieHeader = mgs_bridge_modules_build_cookie_header($setCookies);
    if ($cookieHeader === '') {
        mgs_bridge_modules_fail('Refresh cookie header could not be parsed', ['module' => $module, 'cookies' => $setCookies]);
    }

    $appResponse = mgs_bridge_modules_http_request($mgsBaseUrl . $location, [
        'headers' => [
            'Cookie' => $cookieHeader,
        ],
    ]);
    if ($appResponse['status_code'] !== 200 || !str_contains($appResponse['body'], '<div class="app-shell">')) {
        mgs_bridge_modules_fail('Module shell did not load', [
            'module' => $module,
            'status' => $appResponse['status_code'],
            'status_line' => $appResponse['status_line'],
        ]);
    }

    $accessToken = mgs_bridge_modules_refresh_token($cookieHeader);
    $apiResponse = mgs_bridge_modules_http_request($check['api'], [
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
        ],
    ]);
    if ($apiResponse['status_code'] !== 200) {
        mgs_bridge_modules_fail('Protected module API did not return 200', [
            'module' => $module,
            'api' => $check['api'],
            'status' => $apiResponse['status_code'],
            'body' => substr($apiResponse['body'], 0, 500),
        ]);
    }

    $payload = json_decode($apiResponse['body'], true);
    if (!is_array($payload) || !array_key_exists((string) $check['expect'], $payload)) {
        mgs_bridge_modules_fail('Module API payload missing expected key', [
            'module' => $module,
            'api' => $check['api'],
            'expect' => $check['expect'],
            'body' => substr($apiResponse['body'], 0, 500),
        ]);
    }

    $results[$module] = [
        'page' => $check['page'],
        'api' => $check['api'],
        'redirect' => $location,
        'expected_key' => $check['expect'],
    ];
}

echo json_encode([
    'status' => 'ok',
    'checked_modules' => array_keys($moduleChecks),
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
