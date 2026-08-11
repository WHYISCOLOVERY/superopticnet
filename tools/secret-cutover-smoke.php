<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

define('APP_SECRET_SOURCE_INVENTORY_LIBRARY_ONLY', true);
define('APP_SCRUB_LEGACY_SECRETS_LIBRARY_ONLY', true);
require __DIR__ . '/scrub-legacy-secrets.php';

$checks = [];
$failed = [];
$check = static function (string $name, bool $ok) use (&$checks, &$failed): void {
    $checks[$name] = $ok;
    if (!$ok) {
        $failed[] = $name;
    }
};

$temporaryPath = dirname(__DIR__) . '/tmp/secret-cutover-' . bin2hex(random_bytes(6)) . '.php';
$temporarySecret = bin2hex(random_bytes(18));
$temporaryConfig = [
    'db_host' => '127.0.0.1',
    'provider_api_token' => $temporarySecret,
    'app_timezone' => 'Asia/Baku',
];

try {
    $source = "<?php\nreturn " . var_export($temporaryConfig, true) . ";\n";
    $check('temporary_config_created', file_put_contents($temporaryPath, $source, LOCK_EX) !== false);
    $scrubbed = secret_scrub_config_file($temporaryPath, ['provider_api_token']);
    $after = require $temporaryPath;
    $check('one_config_key_scrubbed', $scrubbed === 1);
    $check('secret_value_removed', is_array($after) && ($after['provider_api_token'] ?? null) === '');
    $check('non_secret_values_preserved', is_array($after)
        && ($after['db_host'] ?? '') === '127.0.0.1'
        && ($after['app_timezone'] ?? '') === 'Asia/Baku');
    $check('temporary_file_contains_no_secret', !str_contains((string) file_get_contents($temporaryPath), $temporarySecret));
    $check('temporary_swap_file_removed', glob($temporaryPath . '.scrub-*') === []);
} catch (Throwable) {
    $failed[] = 'config_scrub_exception';
} finally {
    @unlink($temporaryPath);
}

$db = app_db();
$check('database_available', $db instanceof PDO);
if ($db instanceof PDO) {
    $settingKey = 'secret_cutover_smoke_token_' . bin2hex(random_bytes(4));
    try {
        $db->beginTransaction();
        $insert = $db->prepare(
            'INSERT INTO mgs_runtime_settings (setting_key, setting_value, updated_by_name)
             VALUES (:setting_key, :setting_value, "secret-cutover-smoke")'
        );
        $insert->execute(['setting_key' => $settingKey, 'setting_value' => bin2hex(random_bytes(16))]);
        $deleted = secret_scrub_runtime_settings($db, [$settingKey]);
        $select = $db->prepare('SELECT COUNT(*) FROM mgs_runtime_settings WHERE setting_key = :setting_key');
        $select->execute(['setting_key' => $settingKey]);
        $check('runtime_row_deleted', $deleted === 1 && (int) $select->fetchColumn() === 0);
    } catch (Throwable) {
        $failed[] = 'runtime_scrub_exception';
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

$record = secret_source_inventory_record(
    'config.local.php',
    'provider_api_token',
    $temporarySecret,
    secret_source_inventory_mapping()
);
$check('inventory_record_never_contains_value', !array_key_exists('value', $record));

echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => array_values(array_unique($failed)),
    'secrets_exposed' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
