<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function update_package_zip_slip_smoke_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

if (!class_exists(ZipArchive::class)) {
    echo json_encode([
        'status' => 'skipped',
        'message' => 'ZipArchive unavailable on this runtime; zip-slip guard is only exercised via ZipArchive path.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mgs-zip-slip-' . bin2hex(random_bytes(6));
$targetDir = $tmpRoot . DIRECTORY_SEPARATOR . 'extract';
$outsideMarker = $tmpRoot . DIRECTORY_SEPARATOR . 'zip-slip-canary.txt';
$archivePath = $tmpRoot . DIRECTORY_SEPARATOR . 'malicious.zip';

@mkdir($tmpRoot, 0777, true);
@mkdir($targetDir, 0777, true);

$zip = new ZipArchive();
if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    update_package_zip_slip_smoke_fail('Could not create malicious archive fixture', 2);
}
$zip->addFromString('regular.txt', "benign\n");
$zip->addFromString('../zip-slip-canary.txt', "escaped\n");
$zip->close();

$result = app_mgs_expand_archive($archivePath, $targetDir);

if (!empty($result['ok'])) {
    update_package_zip_slip_smoke_fail('Zip-slip archive was accepted instead of rejected', 3);
}

if (($result['stderr'] ?? '') !== 'zip_entry_path_unsafe') {
    update_package_zip_slip_smoke_fail('Unexpected rejection reason: ' . (string) ($result['stderr'] ?? ''), 4);
}

if (is_file($outsideMarker)) {
    update_package_zip_slip_smoke_fail('Zip-slip entry escaped target directory onto disk', 5);
}

echo json_encode([
    'status' => 'ok',
    'message' => 'Zip-slip archive correctly rejected before extraction',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

@unlink($archivePath);
@unlink($outsideMarker);
app_mgs_delete_tree($targetDir);
@rmdir($tmpRoot);
