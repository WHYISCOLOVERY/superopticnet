<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function render_login_page(string $script, array $get = []): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'portal_login_smoke_');
    if ($tmp === false) {
        fwrite(STDERR, 'Cannot create temp render file.' . PHP_EOL);
        exit(1);
    }

    $renderFile = $tmp . '.php';
    rename($tmp, $renderFile);
    $code = "<?php\n"
        . '$_GET = ' . var_export($get, true) . ";\n"
        . '$_POST = [];' . "\n"
        . '$_REQUEST = $_GET;' . "\n"
        . '$_SERVER["REQUEST_METHOD"] = "GET";' . "\n"
        . '$_SERVER["HTTPS"] = "on";' . "\n"
        . '$_SERVER["HTTP_HOST"] = "billing.superoptic.net";' . "\n"
        . '$_SERVER["REQUEST_URI"] = "/' . basename($script) . '";' . "\n"
        . 'require ' . var_export($script, true) . ";\n";
    file_put_contents($renderFile, $code);

    $process = proc_open(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($renderFile),
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    if (!is_resource($process)) {
        @unlink($renderFile);
        fwrite(STDERR, 'Cannot start PHP render process.' . PHP_EOL);
        exit(1);
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($renderFile);

    if ($exitCode !== 0) {
        fwrite(STDERR, 'Render failed: ' . trim((string) $stderr) . PHP_EOL);
        exit(1);
    }

    return (string) $stdout;
}

function assert_not_contains(string $html, array $needles, string $context): void
{
    foreach ($needles as $needle) {
        if (stripos($html, $needle) !== false) {
            fwrite(STDERR, $context . ' leaks "' . $needle . '"' . PHP_EOL);
            exit(1);
        }
    }
}

function assert_contains(string $html, array $needles, string $context): void
{
    foreach ($needles as $needle) {
        if (stripos($html, $needle) === false) {
            fwrite(STDERR, $context . ' is missing "' . $needle . '"' . PHP_EOL);
            exit(1);
        }
    }
}

$publicHtml = render_login_page($root . '/portal-login.php');
assert_contains($publicHtml, [
    'Musteri portali',
    'Super Optik musteri portalina daxil olun',
    'Abonent kodu',
], 'public portal-login');
assert_not_contains($publicHtml, [
    'Emekdas',
    'employee',
    'NOC',
    'OLT',
    'billing',
    'MyGov ID ile daxil ol',
    'Hazirliq rejimi',
], 'public portal-login');

$staffHtml = render_login_page($root . '/staff-login.php');
assert_contains($staffHtml, [
    'Emekdas',
    'Istifadeci adi',
    'Daxili emekdas girisi',
], 'staff-login');
assert_not_contains($staffHtml, [
    'Musteri portali',
    'Super Optik musteri portalina daxil olun',
    'Abonent kodu',
], 'staff-login');

echo json_encode([
    'status' => 'ok',
    'public_login' => 'customer_only',
    'staff_login' => 'separate_endpoint',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
