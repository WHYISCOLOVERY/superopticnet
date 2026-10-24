<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function app_reporting_strict_local_date(mixed $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    $date = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed
        || (is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))
        || $parsed->format('Y-m-d') !== $date) {
        return null;
    }
    return $parsed;
}

function app_reporting_period(array $query, ?DateTimeImmutable $now = null): array
{
    $timezone = new DateTimeZone(app_timezone());
    $now = $now instanceof DateTimeImmutable ? $now->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);
    $today = $now->setTime(0, 0);
    $range = clean_text((string) ($query['range'] ?? 'month'));
    if (!in_array($range, ['today', 'yesterday', 'week', 'month', 'year', 'custom'], true)) {
        $range = 'month';
    }

    $start = $today->modify('-29 days');
    $end = $today;
    $semantic = 'last_30_days';
    if ($range === 'today') {
        $start = $today;
        $semantic = 'today';
    } elseif ($range === 'yesterday') {
        $start = $today->modify('-1 day');
        $end = $start;
        $semantic = 'yesterday';
    } elseif ($range === 'week') {
        $start = $today->modify('-6 days');
        $semantic = 'last_7_days';
    } elseif ($range === 'year') {
        $start = $today->modify('first day of this month')->modify('-11 months');
        $semantic = 'last_12_months';
    } elseif ($range === 'custom') {
        $customStart = app_reporting_strict_local_date($query['start_date'] ?? '', $timezone);
        $customEnd = app_reporting_strict_local_date($query['end_date'] ?? '', $timezone);
        if (!$customStart || !$customEnd) {
            return [
                'valid' => false,
                'error' => 'invalid_custom_date',
                'range' => 'custom',
                'timezone' => $timezone->getName(),
            ];
        }
        if ($customStart > $customEnd) {
            return [
                'valid' => false,
                'error' => 'reversed_custom_date_range',
                'range' => 'custom',
                'timezone' => $timezone->getName(),
            ];
        }
        $start = $customStart;
        $end = $customEnd;
        $semantic = 'custom';
    }

    $endExclusive = $end->modify('+1 day');
    $futureCutoff = $today->modify('+1 day');
    $operationalEndExclusive = $endExclusive < $futureCutoff ? $endExclusive : $futureCutoff;
    $utc = new DateTimeZone('UTC');

    return [
        'valid' => true,
        'error' => '',
        'range' => $range,
        'semantic' => $semantic,
        'timezone' => $timezone->getName(),
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
        'start_at' => $start->format('Y-m-d H:i:s'),
        'end_at_exclusive' => $endExclusive->format('Y-m-d H:i:s'),
        'operational_end_at_exclusive' => $operationalEndExclusive->format('Y-m-d H:i:s'),
        'future_cutoff_at' => $futureCutoff->format('Y-m-d H:i:s'),
        'start_utc' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
        'end_utc_exclusive' => $endExclusive->setTimezone($utc)->format('Y-m-d H:i:s'),
        'as_of_at' => $now->format(DATE_ATOM),
        'bucket' => $range === 'year' ? 'month' : 'day',
    ];
}
