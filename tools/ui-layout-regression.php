<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [];

$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: {$relative}");
    }
    return (string) file_get_contents($path);
};

$assertContains = static function (string $haystack, string $needle, string $label) use (&$checks): void {
    $checks[] = [$label, str_contains($haystack, $needle)];
};

$css = $read('css/portal.css');
$message = $read('message-center.php');
$natlog = $read('radius-natlog.php');

$checks[] = ['customer KPI cards render before customer list', preg_match('/\.billing-customer-stats\s*\{[^}]*order:\s*2\s*;/s', $css) === 1];
$checks[] = ['customer list renders after header and KPI cards', preg_match('/\.billing-customer-list-card\s*\{[^}]*order:\s*3\s*;/s', $css) === 1];
$assertContains($message, 'class="portal-layout message-center-layout"', 'message center uses full-width page layout');
$assertContains($natlog, 'nat-results-table', 'NAT log results use compact non-overlapping table layout');

$failed = 0;
foreach ($checks as [$label, $ok]) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

if ($failed > 0) {
    fwrite(STDERR, "{$failed} UI layout regression check(s) failed." . PHP_EOL);
    exit(1);
}

echo 'UI layout regression checks passed.' . PHP_EOL;
