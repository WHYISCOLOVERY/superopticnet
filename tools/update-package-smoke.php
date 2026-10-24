<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function update_package_smoke_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

function update_package_smoke_create_archive(string $packageRoot, string $archivePath): bool
{
    if (class_exists(ZipArchive::class)) {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($packageRoot) + 1));
            if (!$zip->addFile($file->getPathname(), $relativePath)) {
                $zip->close();
                @unlink($archivePath);
                return false;
            }
        }

        return $zip->close() && is_file($archivePath);
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }

    $ps = "\$ErrorActionPreference = 'Stop'; " .
        'Compress-Archive -Path ' . escapeshellarg($packageRoot . DIRECTORY_SEPARATOR . '*') .
        ' -DestinationPath ' . escapeshellarg($archivePath) . ' -CompressionLevel Optimal -Force;';
    $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ' . escapeshellarg($ps);
    exec($cmd, $out, $zipCode);

    return $zipCode === 0 && is_file($archivePath);
}

$db = app_db();
if (!$db instanceof PDO) {
    update_package_smoke_fail('Database unavailable', 2);
}

$employee = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'employee' AND is_active = 1
     ORDER BY FIELD(account_type, 'admin', 'operator', 'technician') ASC, id
     LIMIT 1"
)?->fetch();

if (!is_array($employee)) {
    update_package_smoke_fail('No active employee', 3);
}

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mgs-update-pkg-' . bin2hex(random_bytes(6));
$packageRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'package';
$archivePath = $tmpRoot . DIRECTORY_SEPARATOR . 'superoptic-update-test.zip';

@mkdir($packageRoot . DIRECTORY_SEPARATOR . 'app', 0777, true);
@mkdir($packageRoot . DIRECTORY_SEPARATOR . 'billing', 0777, true);
file_put_contents($packageRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'README.md', "# test app\n");
file_put_contents($packageRoot . DIRECTORY_SEPARATOR . 'billing' . DIRECTORY_SEPARATOR . 'portal.php', "<?php echo 'test';\n");
file_put_contents($packageRoot . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
    'version' => 'test-1.0.0',
    'notes' => 'synthetic test package',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

if (!update_package_smoke_create_archive($packageRoot, $archivePath)) {
    update_package_smoke_fail('Synthetic update package could not be created', 4);
}

$result = app_mgs_backup_center_action('verify_update_package', [
    'update_package' => $archivePath,
], $employee);

if (empty($result['ok'])) {
    update_package_smoke_fail('verify_update_package action failed: ' . ($result['stderr'] ?? $result['summary'] ?? 'unknown'), 5);
}

$decoded = json_decode((string) ($result['stdout'] ?? ''), true);
if (!is_array($decoded)) {
    update_package_smoke_fail('verify_update_package stdout is not valid JSON', 6);
}

if (empty($decoded['has_app']) || empty($decoded['has_billing'])) {
    update_package_smoke_fail('Update package verify did not report both app and billing payloads', 7);
}

if (trim((string) ($decoded['version'] ?? '')) !== 'test-1.0.0') {
    update_package_smoke_fail('Update package version was not detected', 8);
}

app_mgs_record_operation_action([
    'action_type' => 'update_package_smoke',
    'entity_type' => 'update_package',
    'entity_id' => basename($archivePath),
    'actor' => $employee,
    'payload' => ['smoke' => true],
    'status' => 'executed',
    'summary' => 'Synthetic update package verify smoke passed.',
]);

echo json_encode([
    'status' => 'ok',
    'archive' => $archivePath,
    'version' => $decoded['version'] ?? '',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

@unlink($archivePath);
@unlink($packageRoot . DIRECTORY_SEPARATOR . 'manifest.json');
@unlink($packageRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'README.md');
@unlink($packageRoot . DIRECTORY_SEPARATOR . 'billing' . DIRECTORY_SEPARATOR . 'portal.php');
@rmdir($packageRoot . DIRECTORY_SEPARATOR . 'app');
@rmdir($packageRoot . DIRECTORY_SEPARATOR . 'billing');
@rmdir($packageRoot);
@rmdir($tmpRoot);
