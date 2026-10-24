<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// NOTE: PHP does not run try{}finally{} blocks after exit()/die() inside the
// try body, so every failure path below throws instead of exiting directly
// -- otherwise a failed run would leave its fixture rows in the database for
// the next run to trip over.
class FinanceReportSmokeFailure extends RuntimeException
{
}

function finance_report_smoke_fail(string $message, int $code = 1): never
{
    throw new FinanceReportSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$stamp = date('His') . random_int(100, 999);
$refPrefix = 'SMOKE-FIN-REPORT-' . $stamp;
$yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
$insertedRefs = [];
$dedupKey = 'finance-report:daily:' . $yesterday;
$nocActor = app_noc_system_actor('finance-report-worker');

$cleanup = function () use ($db, &$insertedRefs, $dedupKey): void {
    $db->prepare('DELETE FROM mgs_dedup_registry WHERE dedup_key = :dedup')->execute(['dedup' => $dedupKey]);
    $db->prepare('DELETE FROM mgs_alarm_events WHERE dedup_key = :dedup')->execute(['dedup' => $dedupKey]);
    if ($insertedRefs !== []) {
        $placeholders = implode(',', array_fill(0, count($insertedRefs), '?'));
        $db->prepare('DELETE FROM finance_entries WHERE reference_no IN (' . $placeholders . ')')->execute($insertedRefs);
    }
};

try {
    // Any leftover fixture rows from a previous interrupted run would
    // silently double the totals below -- clear them first.
    $db->exec("DELETE FROM finance_entries WHERE reference_no LIKE 'SMOKE-FIN-REPORT-%'");
    $db->prepare('DELETE FROM mgs_dedup_registry WHERE dedup_key = :dedup')->execute(['dedup' => $dedupKey]);
    $db->prepare('DELETE FROM mgs_alarm_events WHERE dedup_key = :dedup')->execute(['dedup' => $dedupKey]);

    $rows = [
        ['entry_type' => 'income', 'title' => 'Smoke income 1', 'amount' => '150.00'],
        ['entry_type' => 'income', 'title' => 'Smoke income 2', 'amount' => '50.50'],
        ['entry_type' => 'expense', 'title' => 'Smoke expense 1', 'amount' => '40.25'],
    ];
    $insert = $db->prepare(
        'INSERT INTO finance_entries (entry_type, title, category, amount, currency, transaction_date, status, reference_no, created_by_name)
         VALUES (:entry_type, :title, "smoke", :amount, "AZN", :transaction_date, "posted", :reference_no, "smoke-test")'
    );
    foreach ($rows as $index => $row) {
        $ref = $refPrefix . '-' . $index;
        $insert->execute([
            'entry_type' => $row['entry_type'],
            'title' => $row['title'],
            'amount' => $row['amount'],
            'transaction_date' => $yesterday,
            'reference_no' => $ref,
        ]);
        $insertedRefs[] = $ref;
    }

    // Direct function check: test_mode=true, queue_messages=false -- no
    // Telegram send, no alarm row persisted, only the arithmetic + dedup
    // contract is exercised.
    $result = app_mgs_finance_period_report('daily', $db, $nocActor, true, false);
    if (empty($result['ok'])) {
        finance_report_smoke_fail('engine_failed');
    }
    if ((string) ($result['to'] ?? '') !== $yesterday) {
        finance_report_smoke_fail('unexpected_period_window:' . json_encode($result, JSON_UNESCAPED_SLASHES));
    }
    $income = (float) ($result['income'] ?? -1);
    $expense = (float) ($result['expense'] ?? -1);
    $net = (float) ($result['net'] ?? -1);
    if (abs($income - 200.50) > 0.001 || abs($expense - 40.25) > 0.001 || abs($net - 160.25) > 0.001) {
        finance_report_smoke_fail('unexpected_totals:' . json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    // Dedup contract: same period+day must not be reported twice even in
    // apply mode (protects against a cron firing more than once the same
    // day).
    $first = app_mgs_finance_period_report('daily', $db, $nocActor, false, false);
    if (empty($first['ok'])) {
        finance_report_smoke_fail('first_apply_run_failed');
    }
    $dedupExists = $db->prepare('SELECT COUNT(*) FROM mgs_dedup_registry WHERE dedup_key = :dedup');
    $dedupExists->execute(['dedup' => $dedupKey]);
    if ((int) $dedupExists->fetchColumn() < 1) {
        finance_report_smoke_fail('dedup_row_missing_after_first_run');
    }

    // Worker subprocess dry-run: must not crash and must report ok status
    // without needing --apply.
    $workerScript = dirname(__DIR__) . '/tools/process-finance-daily-report.php';
    $php = PHP_BINARY;
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([$php, $workerScript, '--period=daily'], $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        finance_report_smoke_fail('worker_spawn_failed');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        finance_report_smoke_fail('worker_exit_nonzero:' . $exitCode . ':' . $stderr);
    }
    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'ok') {
        finance_report_smoke_fail('worker_output_invalid:' . $stdout);
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'income' => $income,
        'expense' => $expense,
        'net' => $net,
        'dedup_key' => $dedupKey,
        'worker_status' => (string) ($decoded['status'] ?? ''),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
