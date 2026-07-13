<?php
declare(strict_types=1);

$cssPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'portal.css';
$css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';

$portalRevealRule = '';
if (preg_match('/\.portal-reveal\s*\{(?P<body>[^}]*)\}/s', $css, $matches)) {
    $portalRevealRule = (string) ($matches['body'] ?? '');
}

$checks = [
    'portal_reveal_rule_exists' => $portalRevealRule !== '',
    'portal_reveal_not_hidden_by_default' => !preg_match('/opacity\s*:\s*0\b/i', $portalRevealRule),
    'portal_reveal_no_translate_gate' => !preg_match('/transform\s*:\s*translateY\(/i', $portalRevealRule),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));

echo json_encode([
    'status' => $failed === [] ? 'ok' : 'failed',
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
