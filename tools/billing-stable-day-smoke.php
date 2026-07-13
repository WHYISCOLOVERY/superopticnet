<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

if (!function_exists('app_billing_next_stable_due_datetime')) {
    fwrite(STDERR, "Missing helper: app_billing_next_stable_due_datetime\n");
    exit(2);
}

$cases = [
    [
        'label' => 'mid_month_to_first',
        'stable_day' => 1,
        'base' => '2026-05-20 19:00:00',
        'expected' => '2026-06-01 19:00:00',
    ],
    [
        'label' => 'exact_day_rolls_to_next_month',
        'stable_day' => 1,
        'base' => '2026-05-01 19:00:00',
        'expected' => '2026-06-01 19:00:00',
    ],
    [
        'label' => 'clamp_missing_day_in_february',
        'stable_day' => 31,
        'base' => '2026-01-31 11:15:00',
        'expected' => '2026-02-28 11:15:00',
    ],
];

$results = [];
foreach ($cases as $case) {
    $actual = app_billing_next_stable_due_datetime(
        (int) $case['stable_day'],
        strtotime((string) $case['base']) ?: 0
    );

    $results[] = [
        'label' => $case['label'],
        'expected' => $case['expected'],
        'actual' => $actual,
        'pass' => $actual === $case['expected'],
    ];
}

$failed = array_values(array_filter($results, static fn (array $row): bool => !$row['pass']));
echo json_encode([
    'status' => $failed === [] ? 'ok' : 'failed',
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 3);
