<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function mgs_sso_burst_fail(string $message, array $extra = [], int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
        'extra' => $extra,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

function mgs_sso_burst_http_request(string $url, array $options = []): array
{
    $context = stream_context_create([
        'http' => [
            'method' => strtoupper((string) ($options['method'] ?? 'GET')),
            'ignore_errors' => true,
            'timeout' => (float) ($options['timeout'] ?? 10.0),
            'header' => '',
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

function mgs_sso_burst_extract_iframe_src(string $html): string
{
    if (!preg_match('/<iframe[^>]+src="([^"]+)"/i', $html, $match)) {
        return '';
    }

    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
}

function mgs_sso_burst_render_bridge(): string
{
    $db = app_db();
    if (!$db instanceof PDO) {
        mgs_sso_burst_fail('Database unavailable', [], 2);
    }

    $statement = $db->query(
        "SELECT *
         FROM portal_accounts
         WHERE role = 'employee' AND is_active = 1
         ORDER BY FIELD(account_type, 'admin', 'operator', 'technician') ASC, id
         LIMIT 1"
    );
    $employee = $statement ? $statement->fetch() : false;
    if (!is_array($employee)) {
        mgs_sso_burst_fail('No active employee account', [], 3);
    }

    $_GET = ['module' => 'inventory'];
    $_POST = [];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/superopticnet/mgs-bridge.php?module=inventory';
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/mgs-bridge.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    app_session_boot();
    $_SESSION['portal_user'] = app_db_user_session_payload($employee);

    chdir(dirname(__DIR__));
    ob_start();
    include dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mgs-bridge.php';
    return (string) ob_get_clean();
}

$attempts = 40;
$success = 0;
$failures = [];

for ($i = 1; $i <= $attempts; $i++) {
    $html = mgs_sso_burst_render_bridge();
    $iframeSrc = mgs_sso_burst_extract_iframe_src($html);
    if ($iframeSrc === '') {
        mgs_sso_burst_fail('Iframe src not found', ['attempt' => $i]);
    }

    $response = mgs_sso_burst_http_request($iframeSrc);
    if ($response['status_code'] !== 302) {
        $failures[] = [
            'attempt' => $i,
            'status' => $response['status_code'],
            'status_line' => $response['status_line'],
            'body' => substr($response['body'], 0, 300),
        ];
        break;
    }

    $success++;
}

if ($success !== $attempts) {
    mgs_sso_burst_fail('SSO consume burst limit reached before expected threshold', [
        'success' => $success,
        'attempts' => $attempts,
        'failures' => $failures,
    ]);
}

echo json_encode([
    'status' => 'ok',
    'attempts' => $attempts,
    'success' => $success,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
