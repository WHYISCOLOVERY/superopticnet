<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/statistics-center-lib.php';

$db = app_db();
$checks = [];
$errors = [];
$expect = static function (bool $condition, string $name) use (&$checks, &$errors): void {
    $checks[$name] = $condition;
    if (!$condition) {
        $errors[] = $name;
    }
};

$timezone = new DateTimeZone('Asia/Baku');
$now = new DateTimeImmutable('2026-07-23 12:00:00', $timezone);
$today = app_reporting_period(['range' => 'today'], $now);
$week = app_reporting_period(['range' => 'week'], $now);
$month = app_reporting_period(['range' => 'month'], $now);
$year = app_reporting_period(['range' => 'year'], $now);
$leap = app_reporting_period([
    'range' => 'custom',
    'start_date' => '2024-02-29',
    'end_date' => '2024-03-01',
], $now);
$invalid = app_reporting_period([
    'range' => 'custom',
    'start_date' => '2026-02-31',
    'end_date' => '2026-03-01',
], $now);
$reversed = app_reporting_period([
    'range' => 'custom',
    'start_date' => '2026-07-23',
    'end_date' => '2026-07-01',
], $now);

$expect(
    !empty($today['valid'])
    && ($today['start_date'] ?? '') === '2026-07-23'
    && ($today['end_date'] ?? '') === '2026-07-23'
    && ($today['end_at_exclusive'] ?? '') === '2026-07-24 00:00:00',
    'today_is_inclusive_date_half_open_datetime'
);
$expect(
    ($week['start_date'] ?? '') === '2026-07-17'
    && ($week['end_date'] ?? '') === '2026-07-23'
    && ($week['semantic'] ?? '') === 'last_7_days',
    'week_is_last_seven_days'
);
$expect(
    ($month['start_date'] ?? '') === '2026-06-24'
    && ($month['semantic'] ?? '') === 'last_30_days',
    'month_is_explicit_last_thirty_days'
);
$expect(
    ($year['start_date'] ?? '') === '2025-08-01'
    && ($year['bucket'] ?? '') === 'month',
    'year_is_last_twelve_calendar_month_buckets'
);
$expect(
    !empty($leap['valid'])
    && ($leap['start_date'] ?? '') === '2024-02-29'
    && ($leap['end_at_exclusive'] ?? '') === '2024-03-02 00:00:00',
    'valid_leap_day_is_accepted'
);
$expect(
    empty($invalid['valid']) && ($invalid['error'] ?? '') === 'invalid_custom_date',
    'invalid_calendar_date_is_rejected'
);
$expect(
    empty($reversed['valid']) && ($reversed['error'] ?? '') === 'reversed_custom_date_range',
    'reversed_custom_range_is_rejected'
);
$expect(
    ($today['timezone'] ?? '') === 'Asia/Baku'
    && ($today['start_utc'] ?? '') === '2026-07-22 20:00:00',
    'timezone_and_utc_boundary_are_explicit'
);

$flagState = null;
if (!$db instanceof PDO) {
    $errors[] = 'db_not_ready';
} else {
    try {
        $loadFlag = $db->prepare(
            'SELECT flag_key,is_enabled,rollout_percent,role_scope,user_scope_json,note,source
               FROM app_feature_flags WHERE flag_key = "stage1_statistics_date_contract"'
        );
        $loadFlag->execute();
        $flagState = $loadFlag->fetch(PDO::FETCH_ASSOC);
        if (!is_array($flagState)) {
            throw new RuntimeException('statistics_feature_flag_missing');
        }
        $db->exec(
            'UPDATE app_feature_flags
                SET is_enabled = 1,rollout_percent = 100,note = "Temporary Stage 1 statistics smoke"
              WHERE flag_key = "stage1_statistics_date_contract"'
        );

        $query = [
            'range' => 'custom',
            'start_date' => '2026-07-23',
            'end_date' => '2099-12-31',
        ];
        $billing = app_billing_statistics_context($db, $query);
        $finance = app_finance_statistics_context($db, $query);
        $admin = [
            'id' => 0,
            'role' => 'employee',
            'account_type' => 'admin',
            'employee_role' => 'admin',
        ];
        $center = app_statistics_center_context($db, $admin, $query);
        $cutoff = (string) ($billing['future_cutoff_at'] ?? '');
        $futureCount = app_billing_scalar(
            $db,
            'SELECT COUNT(*) FROM billing_payments p
              WHERE ' . app_billing_operational_payment_sql('p') . '
                AND p.created_at >= :future_cutoff_at',
            ['future_cutoff_at' => $cutoff]
        );
        $expect(
            !empty($billing['data_quality']['future_rows_excluded'])
            && (int) ($billing['data_quality']['future_billing_payment_rows'] ?? -1) === (int) $futureCount,
            'billing_future_anomaly_count_matches_database'
        );
        $expect(
            !empty($finance['data_quality']['future_rows_excluded'])
            && (int) ($finance['data_quality']['future_billing_payment_rows'] ?? -1) === (int) $futureCount,
            'finance_future_anomaly_count_matches_database'
        );
        $expect(
            ($center['status'] ?? '') === 'ok'
            && !empty($center['data_quality']['future_rows_excluded'])
            && (int) ($center['data_quality']['future_billing_payment_rows'] ?? -1) === (int) $futureCount,
            'statistics_center_propagates_date_contract_quality'
        );

        $invalidCenter = app_statistics_center_context($db, $admin, [
            'range' => 'custom',
            'start_date' => '2026-02-31',
            'end_date' => '2026-03-01',
        ]);
        $expect(
            ($invalidCenter['status'] ?? '') === 'error'
            && ($invalidCenter['error'] ?? '') === 'invalid_custom_date'
            && ($invalidCenter['datasets'] ?? null) === [],
            'statistics_center_returns_error_state_for_invalid_date'
        );
    } catch (Throwable $exception) {
        $errors[] = 'database_contract_exception:' . $exception->getMessage();
    } finally {
        if (is_array($flagState)) {
            try {
                $restore = $db->prepare(
                    'UPDATE app_feature_flags
                        SET is_enabled = :is_enabled,rollout_percent = :rollout_percent,
                            role_scope = :role_scope,user_scope_json = :user_scope_json,
                            note = :note,source = :source
                      WHERE flag_key = :flag_key'
                );
                $restore->execute([
                    'is_enabled' => (int) $flagState['is_enabled'],
                    'rollout_percent' => (float) $flagState['rollout_percent'],
                    'role_scope' => $flagState['role_scope'],
                    'user_scope_json' => $flagState['user_scope_json'],
                    'note' => $flagState['note'],
                    'source' => $flagState['source'],
                    'flag_key' => (string) $flagState['flag_key'],
                ]);
            } catch (Throwable $restoreException) {
                $errors[] = 'feature_flag_restore_failed:' . $restoreException->getMessage();
            }
        }
    }
}

$errors = array_values(array_unique($errors));
echo json_encode([
    'ok' => $errors === [],
    'checks' => $checks,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
