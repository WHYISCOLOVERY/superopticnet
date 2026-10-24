<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

function release_smoke_remove_tree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        release_smoke_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function release_smoke_write(string $path, string $content): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create fixture directory.');
    }
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write fixture file.');
    }
}

function release_smoke_find_powershell(): ?string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
        $windowsPowerShell = $systemRoot . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (is_file($windowsPowerShell)) {
            return $windowsPowerShell;
        }
    }

    $lookup = PHP_OS_FAMILY === 'Windows' ? 'where pwsh.exe 2>NUL' : 'command -v pwsh 2>/dev/null';
    $lines = [];
    $status = 1;
    exec($lookup, $lines, $status);
    return $status === 0 && isset($lines[0]) ? trim($lines[0]) : null;
}

/** @return array{exit_code:int,stdout:string,stderr:string} */
function release_smoke_run_powershell(string $executable, string $script, array $arguments): array
{
    $command = array_merge([
        $executable,
        '-NoLogo',
        '-NoProfile',
        '-NonInteractive',
        '-ExecutionPolicy',
        'Bypass',
        '-File',
        $script,
    ], $arguments);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PowerShell.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

$checks = [];
$failed = [];
$check = static function (string $name, bool $ok) use (&$checks, &$failed): void {
    $checks[$name] = $ok;
    if (!$ok) {
        $failed[] = $name;
    }
};

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ubuntu-release-smoke-' . bin2hex(random_bytes(6));
$webRoot = $root . DIRECTORY_SEPARATOR . 'superopticnet';
$mgsRoot = $root . DIRECTORY_SEPARATOR . 'oltidareetme';
$outputRoot = $root . DIRECTORY_SEPARATOR . 'output';
$script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'build-ubuntu-release.ps1';
$powershell = release_smoke_find_powershell();

try {
    $check('builder_exists', is_file($script));
    $check('powershell_available', $powershell !== null);
    if ($powershell === null) {
        throw new RuntimeException('Required contract-test runtime is unavailable.');
    }

    $zipInspector = $root . DIRECTORY_SEPARATOR . 'inspect-zip.ps1';
    release_smoke_write($zipInspector, <<<'POWERSHELL'
param([Parameter(Mandatory = $true)][string]$ZipPath)
$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead($ZipPath)
try {
    function Read-ZipEntry {
        param([IO.Compression.ZipArchiveEntry]$Entry)
        $reader = New-Object IO.StreamReader($Entry.Open())
        try { return $reader.ReadToEnd() } finally { $reader.Dispose() }
    }

    $entries = @($archive.Entries | ForEach-Object FullName)
    $manifestEntry = $archive.GetEntry("manifest.json")
    $sumsEntry = $archive.GetEntry("SHA256SUMS")
    if ($null -eq $manifestEntry -or $null -eq $sumsEntry) {
        throw "Required metadata entry is missing."
    }
    $manifest = (Read-ZipEntry -Entry $manifestEntry) | ConvertFrom-Json
    $sumLines = (Read-ZipEntry -Entry $sumsEntry) -split "`r?`n" | Where-Object { $_ -ne "" }
    $valid = $true
    foreach ($line in $sumLines) {
        if ($line -notmatch "^([a-f0-9]{64})  (.+)$") {
            $valid = $false
            break
        }
        $entry = $archive.GetEntry($Matches[2])
        if ($null -eq $entry) {
            $valid = $false
            break
        }
        $stream = $entry.Open()
        $sha = [Security.Cryptography.SHA256]::Create()
        try {
            $actual = ([BitConverter]::ToString($sha.ComputeHash($stream))).Replace("-", "").ToLowerInvariant()
        } finally {
            $sha.Dispose()
            $stream.Dispose()
        }
        if ($actual -ne $Matches[1]) {
            $valid = $false
            break
        }
    }
    [pscustomobject]@{
        Entries = $entries
        Manifest = $manifest
        InternalChecksumsValid = $valid
    } | ConvertTo-Json -Depth 10
} finally {
    $archive.Dispose()
}
POWERSHELL
    );

    release_smoke_write($webRoot . '/app.php', "<?php\nreturn ['name' => 'fixture'];\n");
    release_smoke_write($webRoot . '/tests/kept-test.php', "<?php\nreturn true;\n");
    release_smoke_write($webRoot . '/data/mock-portal.php', "<?php\nreturn ['password' => 'Demo1234'];\n");
    release_smoke_write($webRoot . '/config.local.php', "<?php\nreturn [];\n");
    release_smoke_write($webRoot . '/.env.example', "DB_PASS=example\n");
    release_smoke_write($webRoot . '/tmp/cache.txt', "runtime\n");
    release_smoke_write($webRoot . '/logs/app.log', "runtime\n");
    release_smoke_write($webRoot . '/node_modules/package/index.js', "module.exports = {};\n");
    release_smoke_write($webRoot . '/test-results/report.xml', "<testsuite/>\n");
    release_smoke_write($webRoot . '/.git/config', "[core]\n");
    release_smoke_write(
        $webRoot . '/private.pem',
        implode('', ['-----BEGIN ', 'PRIVATE KEY-----', "\nfixture\n", '-----END ', 'PRIVATE KEY-----', "\n"])
    );
    release_smoke_write($mgsRoot . '/server/index.js', "console.log('fixture');\n");
    release_smoke_write($mgsRoot . '/.env', "MGS_VALUE=example\n");
    release_smoke_write($mgsRoot . '/mysql-start.out.log', "runtime\n");

    $commonArguments = [
        '-WebRoot', $webRoot,
        '-MgsRoot', $mgsRoot,
        '-OutputRoot', $outputRoot,
    ];
    $dryRun = release_smoke_run_powershell(
        $powershell,
        $script,
        array_merge($commonArguments, ['-Version', 'contract-dry', '-DryRun'])
    );
    $dryRunJson = json_decode(trim($dryRun['stdout']), true);
    $check('dry_run_exit_zero', $dryRun['exit_code'] === 0);
    $check('dry_run_status', is_array($dryRunJson) && ($dryRunJson['Status'] ?? null) === 'dry-run');
    $check('dry_run_creates_nothing', !file_exists($outputRoot));
    $check('dry_run_includes_both_components', is_array($dryRunJson)
        && ($dryRunJson['Components'][0]['Included'] ?? false) === true
        && ($dryRunJson['Components'][1]['Included'] ?? false) === true);

    $webOnlyOutput = $root . DIRECTORY_SEPARATOR . 'web-only-output';
    $webOnlyDryRun = release_smoke_run_powershell($powershell, $script, [
        '-WebRoot', $webRoot,
        '-MgsRoot', $root . DIRECTORY_SEPARATOR . 'missing-oltidareetme',
        '-OutputRoot', $webOnlyOutput,
        '-Version', 'contract-web-only',
        '-DryRun',
    ]);
    $webOnlyJson = json_decode(trim($webOnlyDryRun['stdout']), true);
    $check('missing_sibling_is_optional', $webOnlyDryRun['exit_code'] === 0
        && is_array($webOnlyJson)
        && ($webOnlyJson['Components'][1]['Included'] ?? true) === false
        && !file_exists($webOnlyOutput));

    $build = release_smoke_run_powershell(
        $powershell,
        $script,
        array_merge($commonArguments, ['-Version', 'contract-safe'])
    );
    $buildJson = json_decode(trim($build['stdout']), true);
    $zipPath = $outputRoot . DIRECTORY_SEPARATOR . 'superoptic-ubuntu-contract-safe.zip';
    $zipChecksumPath = $zipPath . '.sha256';
    $check('build_exit_zero', $build['exit_code'] === 0);
    $check('build_status_ok', is_array($buildJson) && ($buildJson['Status'] ?? null) === 'ok');
    $check('zip_created', is_file($zipPath));
    $check('zip_checksum_created', is_file($zipChecksumPath));

    $inspection = release_smoke_run_powershell($powershell, $zipInspector, ['-ZipPath', $zipPath]);
    $inspectionJson = json_decode(trim($inspection['stdout']), true);
    $opened = $inspection['exit_code'] === 0 && is_array($inspectionJson);
    $check('zip_opens', $opened);
    $entryNames = is_array($inspectionJson['Entries'] ?? null) ? $inspectionJson['Entries'] : [];
    $entryNames = array_map(static fn (string $name): string => str_replace('\\', '/', $name), $entryNames);

    $check('web_payload_present', in_array('payload/superopticnet/app.php', $entryNames, true));
    $check('test_source_preserved', in_array('payload/superopticnet/tests/kept-test.php', $entryNames, true));
    $check('mock_portal_fixture_excluded', !in_array('payload/superopticnet/data/mock-portal.php', $entryNames, true));
    $check('mgs_payload_present', in_array('payload/oltidareetme/server/index.js', $entryNames, true));
    $check('manifest_present', in_array('manifest.json', $entryNames, true));
    $check('internal_checksums_present', in_array('SHA256SUMS', $entryNames, true));

    $forbiddenFragments = [
        '/.git/', '/node_modules/', '/tmp/', '/logs/', '/test-results/',
        '/config.local.php', '/.env', '.log', '.pem',
    ];
    $forbiddenEntries = array_filter($entryNames, static function (string $name) use ($forbiddenFragments): bool {
        $normalized = '/' . strtolower($name);
        foreach ($forbiddenFragments as $fragment) {
            if (str_contains($normalized, strtolower($fragment))) {
                return true;
            }
        }
        return false;
    });
    $check('excluded_content_absent', $forbiddenEntries === []);

    $manifest = $opened && is_array($inspectionJson['Manifest'] ?? null) ? $inspectionJson['Manifest'] : null;
    $manifestPaths = is_array($manifest)
        ? array_column(is_array($manifest['Files'] ?? null) ? $manifest['Files'] : [], 'Path')
        : [];
    $check('manifest_contract', is_array($manifest)
        && ($manifest['SchemaVersion'] ?? null) === 1
        && ($manifest['Bundle'] ?? null) === 'superoptic-ubuntu-release'
        && ($manifest['Version'] ?? null) === 'contract-safe');
    $check('manifest_lists_payloads', in_array('payload/superopticnet/app.php', $manifestPaths, true)
        && in_array('payload/oltidareetme/server/index.js', $manifestPaths, true));

    $check('internal_checksums_verify', $opened && ($inspectionJson['InternalChecksumsValid'] ?? false) === true);

    $externalChecksum = is_file($zipChecksumPath) ? trim((string) file_get_contents($zipChecksumPath)) : '';
    $externalParts = explode('  ', $externalChecksum, 2);
    $check('archive_checksum_verifies', count($externalParts) === 2
        && is_file($zipPath)
        && hash_equals(strtolower($externalParts[0]), hash_file('sha256', $zipPath))
        && $externalParts[1] === basename($zipPath));

    $fixtureKey = 'api' . '_key';
    $sensitiveValue = 'prod_' . str_repeat('a', 40);
    release_smoke_write(
        $webRoot . '/settings.php',
        "<?php\nreturn [" . var_export($fixtureKey, true) . ' => ' . var_export($sensitiveValue, true) . "];\n"
    );
    $blocked = release_smoke_run_powershell(
        $powershell,
        $script,
        array_merge($commonArguments, ['-Version', 'contract-blocked'])
    );
    $blockedJson = json_decode(trim($blocked['stdout']), true);
    $blockedZip = $outputRoot . DIRECTORY_SEPARATOR . 'superoptic-ubuntu-contract-blocked.zip';
    $check('secret_scan_exit_two', $blocked['exit_code'] === 2);
    $check('secret_scan_status_blocked', is_array($blockedJson)
        && ($blockedJson['Status'] ?? null) === 'blocked'
        && ($blockedJson['FindingCount'] ?? 0) >= 1);
    $check('secret_scan_reports_no_value', !str_contains($blocked['stdout'], $sensitiveValue)
        && !str_contains($blocked['stderr'], $sensitiveValue));
    $check('secret_scan_creates_no_bundle', !file_exists($blockedZip) && !file_exists($blockedZip . '.sha256'));

    release_smoke_write(
        $webRoot . '/tools/provider-contract-smoke.php',
        "<?php\nreturn ['api_key' => 'SmokePass123!'];\n"
    );
    $deepSeekFixture = 'sk-' . str_repeat('z', 30);
    release_smoke_write(
        $webRoot . '/tools/model-provider-smoke.php',
        "<?php\nreturn ['api_key' => " . var_export($deepSeekFixture, true) . "];\n"
    );
    @unlink($webRoot . '/settings.php');
    $signatureBlocked = release_smoke_run_powershell(
        $powershell,
        $script,
        array_merge($commonArguments, ['-Version', 'contract-signature-blocked'])
    );
    $signatureBlockedJson = json_decode(trim($signatureBlocked['stdout']), true);
    $check('provider_signature_blocks_even_in_smoke_fixture', $signatureBlocked['exit_code'] === 2
        && is_array($signatureBlockedJson)
        && ($signatureBlockedJson['FindingCount'] ?? 0) >= 1
        && !str_contains($signatureBlocked['stdout'], $deepSeekFixture));
} catch (Throwable $exception) {
    $failed[] = 'contract_exception';
} finally {
    release_smoke_remove_tree($root);
}

$failed = array_values(array_unique($failed));
echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
