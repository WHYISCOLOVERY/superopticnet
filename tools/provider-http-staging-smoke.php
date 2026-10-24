<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$options = getopt('', ['base-url::', 'host::']);
$baseUrl = rtrim((string) ($options['base-url'] ?? 'http://127.0.0.1'), '/');
$host = trim((string) ($options['host'] ?? '127.0.0.1'));

function provider_http_smoke_request(
    string $url,
    string $host,
    string $provider,
    string $token,
    string $hmacSecret
): array {
    if ($token === '' || $hmacSecret === '') {
        return ['ok' => false, 'status' => 0, 'code' => 'credential_missing'];
    }

    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . "\n", $hmacSecret);
    $handle = curl_init($url);
    if ($handle === false) {
        return ['ok' => false, 'status' => 0, 'code' => 'curl_init_failed'];
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Host: ' . $host,
            'Authorization: Bearer ' . $token,
            'X-Provider-Name: ' . $provider,
            'X-Superoptic-Timestamp: ' . $timestamp,
            'X-Superoptic-Signature: ' . $signature,
            'X-Correlation-ID: staging-smoke-' . $provider . '-' . bin2hex(random_bytes(5)),
        ],
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    $payload = is_string($body) ? json_decode($body, true) : null;
    return [
        'ok' => is_array($payload),
        'status' => $status,
        'code' => is_array($payload)
            ? (string) ($payload['code'] ?? $payload['StatusCode'] ?? '')
            : ($error !== '' ? 'transport_error' : 'invalid_json'),
        'correlation_id_present' => is_array($payload)
            && trim((string) ($payload['correlation_id'] ?? '')) !== '',
    ];
}

$emanat = provider_http_smoke_request(
    $baseUrl . '/api/provider/customer-lookup.php?provider=emanat&customer_code=CONTRACT-NOT-FOUND',
    $host,
    'emanat',
    trim((string) getenv('EMANAT_PROVIDER_API_TOKEN')),
    trim((string) getenv('EMANAT_PROVIDER_HMAC_SECRET'))
);
$united = provider_http_smoke_request(
    $baseUrl . '/api/provider/up/?memberId=CONTRACT-NOT-FOUND',
    $host,
    'unitedpayment',
    trim((string) getenv('UNITEDPAYMENT_PROVIDER_API_TOKEN')),
    trim((string) getenv('UNITEDPAYMENT_PROVIDER_HMAC_SECRET'))
);

$checks = [
    'emanat_authenticated_not_found' => $emanat['ok']
        && $emanat['status'] === 404
        && $emanat['code'] === 'customer_not_found',
    'emanat_correlation_id' => !empty($emanat['correlation_id_present']),
    'united_authenticated_not_found' => $united['ok']
        && $united['status'] === 200
        && $united['code'] === 'E000',
    'united_correlation_id' => !empty($united['correlation_id_present']),
];
$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));

echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'responses' => [
        'emanat' => $emanat,
        'unitedpayment' => $united,
    ],
    'failed' => $failed,
    'secrets_exposed' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failed === [] ? 0 : 1);
