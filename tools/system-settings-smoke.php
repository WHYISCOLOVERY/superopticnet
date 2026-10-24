<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superoptic-system-settings-' . bin2hex(random_bytes(8)) . '.json';
$checks = [];
try {
    $checks['valid_write'] = app_write_public_runtime_settings([
        'app_country_code' => 'AZ',
        'app_timezone' => 'Asia/Baku',
        'deepseek_api_key' => 'must-not-be-written',
    ], $path);
    $settings = app_public_runtime_settings($path);
    $raw = is_file($path) ? (string) file_get_contents($path) : '';
    $checks['allowlist_only'] = ($settings['app_country_code'] ?? '') === 'AZ'
        && ($settings['app_timezone'] ?? '') === 'Asia/Baku'
        && !array_key_exists('deepseek_api_key', $settings)
        && !str_contains($raw, 'must-not-be-written');
    $checks['invalid_country_rejected'] = !app_write_public_runtime_settings(['app_country_code' => 'AZE', 'app_timezone' => 'Asia/Baku'], $path . '.bad-country');
    $checks['invalid_timezone_rejected'] = !app_write_public_runtime_settings(['app_country_code' => 'AZ', 'app_timezone' => 'Invalid/Zone'], $path . '.bad-zone');
} finally {
    foreach ([$path, $path . '.bad-country', $path . '.bad-zone'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['ok' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
