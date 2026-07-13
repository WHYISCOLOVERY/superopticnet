<?php
declare(strict_types=1);

require __DIR__ . '/../mgs-integration-lib.php';

$errors = [];
$db = app_db();

foreach ([
    'app_mgs_save_hr_employee_compensation',
    'app_mgs_hr_employee_compensation',
    'app_mgs_record_hr_score_event',
    'app_mgs_hr_score_summary',
    'app_mgs_hr_scoreboard_rows',
    'app_mgs_hr_compensation_report_rows',
] as $functionName) {
    if (!function_exists($functionName)) {
        $errors[] = $functionName . '_missing';
    }
}

if (!$db instanceof PDO) {
    $errors[] = 'db_not_ready';
} elseif ($errors === []) {
    app_mgs_ensure_schema($db);
    foreach (['hr_employee_compensations', 'hr_score_events'] as $table) {
        try {
            $statement = $db->prepare('SHOW TABLES LIKE :table_name');
            $statement->execute(['table_name' => $table]);
            if (!$statement->fetchColumn()) {
                $errors[] = $table . '_missing';
            }
        } catch (Throwable $exception) {
            $errors[] = $table . '_check_failed:' . $exception->getMessage();
        }
    }

    $employeeId = 0;
    $scoreEventIds = [];
    $runId = bin2hex(random_bytes(4));

    try {
        $insertEmployee = $db->prepare(
            'INSERT INTO portal_accounts (
                login, password_hash, role, account_type, employee_role, full_name,
                subscriber_code, status, is_active, payload_json
             ) VALUES (
                :login, :password_hash, "employee", "technician", "technician", :full_name,
                NULL, "Aktiv", 1, "{}"
             )'
        );
        $insertEmployee->execute([
            'login' => 'smoke-score-' . $runId . '@local',
            'password_hash' => password_hash('smoke', PASSWORD_DEFAULT),
            'full_name' => 'Smoke Score Employee',
        ]);
        $employeeId = (int) $db->lastInsertId();
        if ($employeeId <= 0) {
            $errors[] = 'employee_seed_failed';
        }

        if ($employeeId > 0) {
            $compensation = app_mgs_save_hr_employee_compensation([
                'employee_account_id' => $employeeId,
                'salary_mode' => 'gross',
                'gross_salary' => 1000,
                'effective_from' => '2026-06-01',
                'note' => 'Smoke salary',
            ], ['id' => 987654, 'full_name' => 'Smoke HR'], $db);
            if (empty($compensation['ok'])
                || abs((float) ($compensation['record']['gross_salary'] ?? 0) - 1000.0) > 0.01
                || abs((float) ($compensation['record']['net_salary'] ?? 0) - 865.0) > 0.01
                || abs((float) ($compensation['record']['employer_total_cost'] ?? 0) - 1189.0) > 0.01
            ) {
                $errors[] = 'compensation_gross_roundtrip_failed';
            }

            $current = app_mgs_hr_employee_compensation($employeeId, $db);
            if ((int) ($current['employee_account_id'] ?? 0) !== $employeeId
                || (string) ($current['salary_mode'] ?? '') !== 'gross'
            ) {
                $errors[] = 'compensation_current_missing';
            }

            foreach ([
                ['event_type' => 'penalty', 'reason_code' => 'absence', 'event_date' => '2026-06-10'],
                ['event_type' => 'penalty', 'reason_code' => 'late', 'event_date' => '2026-06-11'],
                ['event_type' => 'reward', 'reason_code' => 'bonus', 'points_delta' => 10, 'event_date' => '2026-06-12'],
            ] as $eventInput) {
                $event = app_mgs_record_hr_score_event($eventInput + [
                    'employee_account_id' => $employeeId,
                    'note' => 'Smoke score',
                ], ['id' => 987654, 'full_name' => 'Smoke HR'], $db);
                $scoreEventIds[] = (int) ($event['id'] ?? 0);
                if (empty($event['ok'])) {
                    $errors[] = 'score_event_save_failed_' . (string) ($eventInput['reason_code'] ?? '');
                }
            }

            $summary = app_mgs_hr_score_summary($employeeId, '2026-01-01', '2026-12-31', $db);
            if ((int) ($summary['base_score'] ?? 0) !== 100
                || (int) ($summary['score'] ?? 0) !== 85
                || (float) ($summary['penalty_points'] ?? 0) !== -25.0
                || (float) ($summary['reward_points'] ?? 0) !== 10.0
            ) {
                $errors[] = 'score_summary_failed';
            }

            $nextYear = app_mgs_hr_score_summary($employeeId, '2027-01-01', '2027-12-31', $db);
            if ((int) ($nextYear['score'] ?? 0) !== 100 || (int) ($nextYear['event_count'] ?? -1) !== 0) {
                $errors[] = 'score_period_reset_failed';
            }

            $scoreboard = app_mgs_hr_scoreboard_rows($db, 200, '2026-01-01', '2026-12-31');
            $scoreHit = false;
            foreach ($scoreboard as $row) {
                if ((int) ($row['employee_account_id'] ?? 0) === $employeeId
                    && (int) ($row['score'] ?? 0) === 85
                    && abs((float) ($row['gross_salary'] ?? 0) - 1000.0) < 0.01
                ) {
                    $scoreHit = true;
                    break;
                }
            }
            if (!$scoreHit) {
                $errors[] = 'scoreboard_row_missing';
            }

            $reportRows = app_mgs_hr_compensation_report_rows($db, 50);
            $reportHit = false;
            foreach ($reportRows as $row) {
                if ((int) ($row['employee_account_id'] ?? 0) === $employeeId
                    && abs((float) ($row['net_salary'] ?? 0) - 865.0) < 0.01
                ) {
                    $reportHit = true;
                    break;
                }
            }
            if (!$reportHit) {
                $errors[] = 'compensation_report_missing';
            }
        }
    } catch (Throwable $exception) {
        $errors[] = 'roundtrip_exception:' . $exception->getMessage();
    } finally {
        foreach ($scoreEventIds as $id) {
            if ($id <= 0) {
                continue;
            }
            try {
                $cleanup = $db->prepare('DELETE FROM hr_score_events WHERE id = :id');
                $cleanup->execute(['id' => $id]);
            } catch (Throwable) {
            }
        }
        if ($employeeId > 0) {
            try {
                $cleanup = $db->prepare('DELETE FROM hr_employee_compensations WHERE employee_account_id = :employee_account_id');
                $cleanup->execute(['employee_account_id' => $employeeId]);
            } catch (Throwable) {
            }
            try {
                $cleanup = $db->prepare('DELETE FROM portal_accounts WHERE id = :id');
                $cleanup->execute(['id' => $employeeId]);
            } catch (Throwable) {
            }
        }
        try {
            $cleanup = $db->prepare(
                'DELETE FROM mgs_operation_actions
                  WHERE action_type IN ("hr_employee_compensation_save","hr_score_event")
                    AND (entity_id = :employee_id OR summary LIKE "%Smoke Score Employee%")'
            );
            $cleanup->execute(['employee_id' => (string) $employeeId]);
        } catch (Throwable) {
        }
    }
}

$operationsPage = (string) file_get_contents(__DIR__ . '/../operations-center.php');
foreach ([
    'data-hr-employee-compensation',
    'data-hr-score-system',
    'name="action" value="save_hr_employee_compensation"',
    'name="action" value="record_hr_score_event"',
    'name="salary_mode"',
    'name="score_reason_code"',
    'absence',
    'late',
    'task_missed',
] as $marker) {
    if (!str_contains($operationsPage, $marker)) {
        $errors[] = 'operations_' . $marker . '_missing';
    }
}

$adminUsersPage = (string) file_get_contents(__DIR__ . '/../admin-users.php');
foreach ([
    'data-admin-employee-compensation',
    'name="employee_salary_mode"',
    'name="employee_gross_salary"',
    'name="employee_net_salary"',
    'Sicil bali',
] as $marker) {
    if (!str_contains($adminUsersPage, $marker)) {
        $errors[] = 'admin_' . $marker . '_missing';
    }
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
