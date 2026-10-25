<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';

/** @return list<string> */
function portal_migration_files(): array
{
    $files = glob(dirname(__DIR__) . '/db/migrations/*.php') ?: [];
    sort($files, SORT_STRING);
    return array_values($files);
}

/** @return array{version:string,description:string,up:callable,down:callable} */
function portal_migration_load(string $path): array
{
    $migration = require $path;
    if (!is_array($migration)
        || trim((string) ($migration['version'] ?? '')) === ''
        || !is_callable($migration['up'] ?? null)
        || !is_callable($migration['down'] ?? null)) {
        throw new RuntimeException('Invalid migration: ' . basename($path));
    }
    return $migration;
}

function portal_migration_registry(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS portal_schema_migrations (
            version_key VARCHAR(96) NOT NULL PRIMARY KEY,
            description VARCHAR(255) NOT NULL,
            checksum_sha256 CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable. Ensure DB environment references are configured.\n");
    exit(2);
}

$options = getopt('', ['list', 'dry-run', 'rollback::']);
$listOnly = array_key_exists('list', $options);
$dryRun = array_key_exists('dry-run', $options);
$rollbackRequested = array_key_exists('rollback', $options);
$rollbackVersion = $rollbackRequested ? trim((string) ($options['rollback'] ?? '')) : '';

$lock = $db->query("SELECT GET_LOCK('superoptic_portal_migrations', 30)");
if ((int) ($lock?->fetchColumn() ?: 0) !== 1) {
    fwrite(STDERR, "Could not acquire migration lock.\n");
    exit(3);
}

try {
    portal_migration_registry($db);
    $appliedRows = $db->query(
        'SELECT version_key, checksum_sha256 FROM portal_schema_migrations ORDER BY version_key'
    )->fetchAll() ?: [];
    $applied = [];
    foreach ($appliedRows as $row) {
        if (is_array($row)) {
            $applied[(string) $row['version_key']] = (string) $row['checksum_sha256'];
        }
    }

    $catalog = [];
    foreach (portal_migration_files() as $path) {
        $migration = portal_migration_load($path);
        $version = trim((string) $migration['version']);
        if (isset($catalog[$version])) {
            throw new RuntimeException('Duplicate migration version: ' . $version);
        }
        $catalog[$version] = [
            'path' => $path,
            'migration' => $migration,
            'checksum' => hash_file('sha256', $path),
        ];
    }

    foreach ($applied as $version => $checksum) {
        if (!isset($catalog[$version])) {
            throw new RuntimeException('Applied migration file is missing: ' . $version);
        }
        if (!hash_equals($checksum, (string) $catalog[$version]['checksum'])) {
            throw new RuntimeException('Applied migration checksum changed: ' . $version);
        }
    }

    if ($listOnly) {
        foreach ($catalog as $version => $entry) {
            $status = isset($applied[$version]) ? 'applied' : 'pending';
            echo $version . "\t" . $status . "\t" . (string) $entry['migration']['description'] . PHP_EOL;
        }
        exit(0);
    }

    if ($rollbackRequested) {
        if ($rollbackVersion === '') {
            $versions = array_keys($applied);
            rsort($versions, SORT_STRING);
            $rollbackVersion = (string) ($versions[0] ?? '');
        }
        if ($rollbackVersion === '' || !isset($applied[$rollbackVersion], $catalog[$rollbackVersion])) {
            throw new RuntimeException('Rollback migration is not applied: ' . $rollbackVersion);
        }
        echo ($dryRun ? '[DRY-RUN] ' : '') . 'rollback ' . $rollbackVersion . PHP_EOL;
        if (!$dryRun) {
            ($catalog[$rollbackVersion]['migration']['down'])($db);
            $delete = $db->prepare('DELETE FROM portal_schema_migrations WHERE version_key = :version_key');
            $delete->execute(['version_key' => $rollbackVersion]);
        }
        exit(0);
    }

    $appliedCount = 0;
    foreach ($catalog as $version => $entry) {
        if (isset($applied[$version])) {
            continue;
        }
        echo ($dryRun ? '[DRY-RUN] ' : '') . 'apply ' . $version . ' - '
            . (string) $entry['migration']['description'] . PHP_EOL;
        if ($dryRun) {
            continue;
        }
        ($entry['migration']['up'])($db);
        $insert = $db->prepare(
            'INSERT INTO portal_schema_migrations (version_key, description, checksum_sha256)
             VALUES (:version_key, :description, :checksum_sha256)'
        );
        $insert->execute([
            'version_key' => $version,
            'description' => mb_substr((string) $entry['migration']['description'], 0, 255),
            'checksum_sha256' => (string) $entry['checksum'],
        ]);
        $appliedCount++;
    }
    echo 'portal_migrations_applied=' . $appliedCount . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'migration_failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        $db->query("SELECT RELEASE_LOCK('superoptic_portal_migrations')");
    } catch (Throwable) {
    }
}

