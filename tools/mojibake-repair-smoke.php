<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/config.php';

$decode = static function (string $json): string {
    $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    return is_string($value) ? $value : '';
};

$cases = [
    $decode('"M\\u00fc\\u015ft\\u00c9\\u2122ri"') => 'Müştəri',
    $decode('"Na\\u00c4\\u0178d"') => 'Nağd',
    $decode('"Axtar\\u0131\\u015f v\\u00c9\\u2122 filtr"') => 'Axtarış və filtr',
    'â†“ 25 â‚¼' => '↓ 25 ₼',
    'ÃƒÂ¼ÃƒÂ§ÃƒÂ¼n' => 'üçün',
    '`customer-${nextCode}`' => '`customer-${nextCode}`',
];

$errors = [];
foreach ($cases as $input => $expected) {
    $actual = app_repair_mojibake($input);
    if ($actual !== $expected) {
        $errors[] = ['input' => $input, 'expected' => $expected, 'actual' => $actual];
    }
}

echo json_encode([
    'status' => $errors === [] ? 'passed' : 'failed',
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
