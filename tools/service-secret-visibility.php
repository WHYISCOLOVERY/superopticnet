<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/secret-visibility-lib.php';

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
$plan = in_array('--plan', $arguments, true);
$baseUrl = PHP_OS_FAMILY === 'Windows'
    ? 'http://localhost/' . rawurlencode(basename(dirname(__DIR__)))
    : 'http://localhost';
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--base-url=')) {
        $baseUrl = rtrim(trim(substr($argument, strlen('--base-url='))), '/');
    }
}

$parts = parse_url($baseUrl);
$host = strtolower((string) ($parts['host'] ?? ''));
$scheme = strtolower((string) ($parts['scheme'] ?? ''));
$localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
if (!$localHost || !in_array($scheme, ['http', 'https'], true)) {
    echo json_encode([
        'ok' => false,
        'reason' => 'base_url_must_be_local_http',
        'secrets_exposed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

$requiredKeys = app_service_secret_visibility_required_keys();

if ($plan) {
    echo json_encode([
        'ok' => true,
        'mode' => 'plan',
        'base_host' => $host,
        'required_count' => count($requiredKeys),
        'network_requests' => 0,
        'files_written' => 0,
        'secrets_exposed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$root = dirname(__DIR__);
$tmpDirectory = $root . '/tmp';
if (!is_dir($tmpDirectory) && !mkdir($tmpDirectory, 0770, true) && !is_dir($tmpDirectory)) {
    throw new RuntimeException('tmp_directory_unavailable');
}

$token = bin2hex(random_bytes(24));
$fileName = 'service-secret-visibility-' . bin2hex(random_bytes(8)) . '.php';
$probePath = $root . '/' . $fileName;
$evidencePath = $tmpDirectory . '/service-secret-visibility.json';
$probeUrl = $baseUrl . '/' . rawurlencode($fileName) . '?token=' . rawurlencode($token);
$exportedKeys = var_export($requiredKeys, true);
$exportedToken = var_export($token, true);
$probeSource = <<<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$expectedToken = __EXPECTED_TOKEN__;
$providedToken = (string) ($_GET['token'] ?? '');
if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}
$required = __REQUIRED_KEYS__;
$missing = [];
foreach ($required as $key) {
    $value = getenv($key, true);
    if ($value === false) {
        $value = getenv($key);
    }
    if (!is_string($value) || trim($value) === '') {
        $missing[] = $key;
    }
}
echo json_encode([
    'ok' => $missing === [],
    'context' => 'apache-http',
    'sapi' => PHP_SAPI,
    'required_count' => count($required),
    'missing_keys' => $missing,
    'secrets_exposed' => false,
], JSON_UNESCAPED_SLASHES);
PHP;
$probeSource = str_replace(
    ['__EXPECTED_TOKEN__', '__REQUIRED_KEYS__'],
    [$exportedToken, $exportedKeys],
    $probeSource
);

$responseBody = '';
$httpStatus = 0;
try {
    if (file_put_contents($probePath, $probeSource, LOCK_EX) === false) {
        throw new RuntimeException('probe_write_failed');
    }

    if (function_exists('curl_init')) {
        $handle = curl_init($probeUrl);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($handle);
        $responseBody = is_string($response) ? $response : '';
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);
        $response = @file_get_contents($probeUrl, false, $context);
        $responseBody = is_string($response) ? $response : '';
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', (string) $header, $matches) === 1) {
                $httpStatus = (int) $matches[1];
            }
        }
    }
} finally {
    @unlink($probePath);
}

$probeResult = json_decode($responseBody, true);
if ($httpStatus !== 200 || !is_array($probeResult) || ($probeResult['context'] ?? '') !== 'apache-http') {
    echo json_encode([
        'ok' => false,
        'reason' => 'apache_probe_failed',
        'http_status' => $httpStatus,
        'secrets_exposed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

$evidence = [
    'ok' => !empty($probeResult['ok']),
    'context' => 'apache-http',
    'sapi' => (string) ($probeResult['sapi'] ?? ''),
    'base_host' => $host,
    'required_count' => (int) ($probeResult['required_count'] ?? 0),
    'missing_keys' => array_values(array_map('strval', (array) ($probeResult['missing_keys'] ?? []))),
    'checked_at' => date(DATE_ATOM),
    'secrets_exposed' => false,
];

if ($evidence['ok']) {
    app_service_secret_visibility_write_evidence($evidencePath, $evidence);
}

echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($evidence['ok'] ? 0 : 2);
