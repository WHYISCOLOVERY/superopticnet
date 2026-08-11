<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

if (!defined('APP_SECRET_SOURCE_INVENTORY_LIBRARY_ONLY')) {
    define('APP_SECRET_SOURCE_INVENTORY_LIBRARY_ONLY', true);
}
require __DIR__ . '/secret-source-inventory.php';

function secret_scrub_runtime_settings(PDO $db, array $keys): int
{
    if ($keys === []) {
        return 0;
    }

    $deleted = 0;
    $delete = $db->prepare('DELETE FROM mgs_runtime_settings WHERE setting_key = :setting_key');
    foreach ($keys as $key) {
        $delete->execute(['setting_key' => (string) $key]);
        $deleted += $delete->rowCount();
    }
    return $deleted;
}

function secret_scrub_config_file(string $configPath, array $keys): int
{
    if ($keys === []) {
        return 0;
    }
    if (!is_file($configPath)) {
        throw new RuntimeException('config_local_missing');
    }

    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('config_local_invalid');
    }

    $scrubbed = 0;
    foreach ($keys as $key) {
        $key = (string) $key;
        if (array_key_exists($key, $config) && trim((string) $config[$key]) !== '') {
            $config[$key] = '';
            $scrubbed++;
        }
    }

    $serialized = "<?php\ndeclare(strict_types=1);\n\nreturn "
        . var_export($config, true)
        . ";\n";
    $temporaryPath = $configPath . '.scrub-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporaryPath, $serialized, LOCK_EX) === false) {
        throw new RuntimeException('config_local_temporary_write_failed');
    }
    $permissions = fileperms($configPath);
    if (is_int($permissions)) {
        @chmod($temporaryPath, $permissions & 0777);
    }
    if (!@rename($temporaryPath, $configPath)) {
        @unlink($temporaryPath);
        throw new RuntimeException('config_local_atomic_replace_failed');
    }

    return $scrubbed;
}

if (defined('APP_SCRUB_LEGACY_SECRETS_LIBRARY_ONLY') && APP_SCRUB_LEGACY_SECRETS_LIBRARY_ONLY === true) {
    return;
}

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
$apply = in_array('--apply', $arguments, true);
$configPath = dirname(__DIR__) . '/config.local.php';
$mapping = secret_source_inventory_mapping();
$db = app_db();
$collected = secret_source_inventory_collect_legacy($db);
$records = [];
foreach ($collected['entries'] as $entry) {
    $records[] = secret_source_inventory_record(
        $entry['source'],
        $entry['key'],
        $entry['value'],
        $mapping
    );
}

$blockedKeys = array_values(array_unique(array_map(
    static fn (array $record): string => (string) $record['key'],
    array_filter($records, static fn (array $record): bool => empty($record['migration_ready']))
)));
$unmappedKeys = array_values(array_unique(array_map(
    static fn (array $record): string => (string) $record['key'],
    array_filter($records, static fn (array $record): bool => $record['environment_key'] === null)
)));
$ready = $collected['db_error'] === null
    && $db instanceof PDO
    && $blockedKeys === []
    && $unmappedKeys === [];

$result = [
    'ok' => !$apply || $ready,
    'mode' => $apply ? 'apply' : 'dry-run',
    'ready_to_scrub' => $ready,
    'summary' => [
        'legacy_sources' => count($records),
        'blocked' => count($blockedKeys),
        'unmapped' => count($unmappedKeys),
        'config_keys_scrubbed' => 0,
        'runtime_rows_deleted' => 0,
    ],
    'blocked_keys' => $blockedKeys,
    'unmapped_keys' => $unmappedKeys,
    'secrets_exposed' => false,
];

if (!$apply || !$ready) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['ok'] ? 0 : 2);
}

$configKeys = array_values(array_unique(array_map(
    static fn (array $record): string => (string) $record['key'],
    array_filter($records, static fn (array $record): bool => $record['source'] === 'config.local.php')
)));
$runtimeKeys = array_values(array_unique(array_map(
    static fn (array $record): string => (string) $record['key'],
    array_filter($records, static fn (array $record): bool => $record['source'] === 'mgs_runtime_settings')
)));

try {
    if ($runtimeKeys !== []) {
        $db->beginTransaction();
        $result['summary']['runtime_rows_deleted'] = secret_scrub_runtime_settings($db, $runtimeKeys);
        $db->commit();
    }

    if ($configKeys !== []) {
        $result['summary']['config_keys_scrubbed'] = secret_scrub_config_file($configPath, $configKeys);
    }
} catch (Throwable $exception) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $result['ok'] = false;
    $result['error'] = $exception->getMessage();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$result['ok'] = true;
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
