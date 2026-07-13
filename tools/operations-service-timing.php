<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require dirname(__DIR__) . '/config.php';

$paths = [
    '/api/service/network-dashboard',
    '/api/service/devices',
    '/api/service/platform-summary',
    '/api/service/inventory',
    '/api/service/finance',
    '/api/service/technical',
    '/api/service/attendance',
    '/api/service/kpi',
    '/api/service/reports',
    '/api/service/telegram-bots',
];

$results = [];
foreach ($paths as $path) {
    $startedAt = microtime(true);
    $response = app_mgs_service_get_json($path, [], 25);
    $results[] = [
        'path' => $path,
        'seconds' => round(microtime(true) - $startedAt, 3),
        'ok' => (bool) ($response['ok'] ?? false),
        'status' => (int) ($response['status'] ?? 0),
        'error' => (string) ($response['error'] ?? ''),
    ];
}

echo json_encode([
    'status' => 'ok',
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
