<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function app_clickhouse_config(): array
{
    $host = app_config('clickhouse_host', '');
    $secure = in_array(strtolower(app_config('clickhouse_secure', '0')), ['1', 'true', 'yes'], true);
    $port = (int) app_config('clickhouse_http_port', app_config('clickhouse_port', $secure ? '8443' : '8123'));

    return [
        'host' => $host,
        'port' => $port,
        'user' => app_config('clickhouse_user', 'default'),
        'password' => app_config('clickhouse_password', ''),
        'database' => app_config('clickhouse_database', 'superoptic_analytics'),
        'secure' => $secure,
        'timeout' => max(1, (int) app_config('clickhouse_timeout_seconds', '20')),
    ];
}

function app_clickhouse_configured(?array $config = null): bool
{
    $config ??= app_clickhouse_config();
    return trim((string) ($config['host'] ?? '')) !== '';
}

function app_clickhouse_url(array $config): string
{
    $scheme = !empty($config['secure']) ? 'https' : 'http';
    $host = (string) $config['host'];
    $port = (int) $config['port'];
    $database = rawurlencode((string) $config['database']);
    return "{$scheme}://{$host}:{$port}/?database={$database}";
}

function app_clickhouse_request(string $query, string $body = '', ?array $config = null): array
{
    $config ??= app_clickhouse_config();
    if (!app_clickhouse_configured($config)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'clickhouse_not_configured'];
    }

    $payload = $query . ($body !== '' ? "\n" . $body : '');
    $headers = [
        'Content-Type: text/plain; charset=utf-8',
        'X-ClickHouse-User: ' . (string) $config['user'],
    ];
    if ((string) $config['password'] !== '') {
        $headers[] = 'X-ClickHouse-Key: ' . (string) $config['password'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init(app_clickhouse_url($config));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, (int) $config['timeout']),
            CURLOPT_TIMEOUT => (int) $config['timeout'],
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $response !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => is_string($response) ? $response : '',
            'error' => $response === false ? $error : ($status >= 400 ? (string) $response : ''),
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => (int) $config['timeout'],
        ],
    ]);
    $response = @file_get_contents(app_clickhouse_url($config), false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $header, $match) === 1) {
            $status = (int) $match[1];
            break;
        }
    }

    return [
        'ok' => is_string($response) && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_string($response) ? $response : '',
        'error' => is_string($response) && $status >= 400 ? $response : '',
    ];
}

function app_clickhouse_quote(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
}

function app_clickhouse_json_each_row(array $rows): string
{
    $lines = [];
    foreach ($rows as $row) {
        $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return implode("\n", array_filter($lines, 'is_string'));
}
