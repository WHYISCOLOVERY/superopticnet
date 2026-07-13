<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$outputDir = $root . '/tmp/message-center-visual';
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "fixture_output_failed\n");
    exit(1);
}

$styles = implode("\n", [
    (string) file_get_contents($root . '/css/fontawesome-lite.css'),
    (string) file_get_contents($root . '/css/portal.css'),
    (string) file_get_contents($root . '/css/message-center.css'),
]);
$scripts = implode("\n", [
    (string) file_get_contents($root . '/script/portal.js'),
    (string) file_get_contents($root . '/script/message-center.js'),
]);

$render = static function (string $view) use ($root, $outputDir, $styles, $scripts): void {
    $query = http_build_query(['view' => $view]);
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/tools/billing-ui-smoke.php')
        . ' --render=message-center.php --query=' . escapeshellarg($query);
    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);
    $html = implode("\n", $lines);
    if ($exitCode !== 0 || !str_contains($html, 'data-message-shell')) {
        throw new RuntimeException('render_failed:' . $view . ':' . $exitCode);
    }

    $html = preg_replace('/<link\b[^>]*rel="stylesheet"[^>]*>/i', '', $html) ?? $html;
    $html = preg_replace('/<script\b[^>]*src="[^"]+"[^>]*><\/script>/i', '', $html) ?? $html;
    $html = str_replace('</head>', '<style>' . $styles . '</style></head>', $html);
    $html = str_replace('</body>', '<script>' . $scripts . '</script></body>', $html);
    file_put_contents($outputDir . '/' . $view . '.html', $html);
};

foreach (['overview', 'single', 'group', 'templates', 'history', 'audit', 'test', 'settings'] as $view) {
    $render($view);
}

echo json_encode([
    'ok' => true,
    'output_dir' => $outputDir,
    'views' => 8,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
