<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config.php';
$now = strtotime('2026-10-10 00:00:00');
$result = app_billing_apply_borrowed_day_deduction('2026-11-09 00:00:00', '2026-10-10 00:00:00', 3, $now);
$errors = [];
if ($result['expires_at'] !== '2026-11-06 00:00:00') $errors[] = '30-day payment must become 27 days';
if ($result['deducted_days'] !== 3 || $result['pending_days'] !== 0) $errors[] = 'pending days must be consumed';
$partial = app_billing_apply_borrowed_day_deduction('2026-10-12 00:00:00', '2026-10-10 00:00:00', 3, $now);
if ($partial['deducted_days'] !== 2 || $partial['pending_days'] !== 1) $errors[] = 'deduction must never exceed extension';
if ($errors !== []) { fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL); exit(1); }
echo "Billing borrowed days: 4 checks passed.\n";
