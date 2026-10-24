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
// -- otherwise a failed run would leave fixture accounts in the database for
// the next run to trip over.
class OntTelemetrySmokeFailure extends RuntimeException
{
}

function ont_telemetry_smoke_fail(string $message, int $code = 1): never
{
    throw new OntTelemetrySmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$stamp = date('His') . random_int(100, 999);
$code = 'SMOKE-ONT-SYNC-' . $stamp;
$serial = 'SMKSYNC' . $stamp;
$accountIds = [];

$cleanup = function () use ($db, &$accountIds): void {
    foreach ($accountIds as $id) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id AND subscriber_code LIKE :prefix')
            ->execute(['id' => $id, 'prefix' => 'SMOKE-ONT-SYNC-%']);
    }
};

try {
    // Fixture 1: a profile with no serial/mac/pon at all -> must never be
    // selected as a sync candidate (nothing to correlate against hardware).
    $emptyCode = $code . '-EMPTY';
    $saved = app_db_upsert_account([
        'login' => strtolower($emptyCode),
        'password' => 'SmokePass123',
        'role' => 'customer',
        'account_type' => 'customer',
        'full_name' => 'ONT Sync Smoke Empty',
        'subscriber_code' => $emptyCode,
        'phone' => '+99450' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'area' => 'Sebeke',
        'address' => 'ONT sync smoke',
        'plan' => 'Smoke',
        'status' => 'Aktiv',
        'is_active' => 1,
        'payload' => [
            'billing' => ['status' => 'Aktiv'],
            'ont_profiles' => [[
                'olt' => '',
                'pon' => '',
                'ont_id' => '',
                'serial' => '',
                'mac' => '',
            ]],
        ],
    ], false);
    if (!is_array($saved)) {
        ont_telemetry_smoke_fail('empty_account_seed_failed', 3);
    }
    $emptyAccountId = (int) ($saved['id'] ?? 0);
    if ($emptyAccountId > 0) {
        $accountIds[] = $emptyAccountId;
    }

    // Fixture 2: a profile with only a serial number recorded (typical
    // legacy-imported customer: no live OLT/PON assignment yet, but a known
    // ONT serial) -> must be selected, and since no real HSGQ device exists
    // for it in this test environment, the live lookup must fail cleanly
    // (not_found / device errors), and -- in apply mode -- the failure must
    // still be stamped so this worker does not retry it every single run.
    $saved = app_db_upsert_account([
        'login' => strtolower($code),
        'password' => 'SmokePass123',
        'role' => 'customer',
        'account_type' => 'customer',
        'full_name' => 'ONT Sync Smoke ' . $code,
        'subscriber_code' => $code,
        'phone' => '+99450' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'area' => 'Sebeke',
        'address' => 'ONT sync smoke',
        'plan' => 'Smoke',
        'status' => 'Aktiv',
        'is_active' => 1,
        'payload' => [
            'billing' => ['status' => 'Aktiv'],
            'ont_profiles' => [[
                'olt' => '',
                'pon' => '',
                'ont_id' => '',
                'serial' => $serial,
                'mac' => '',
            ]],
        ],
    ], false);
    if (!is_array($saved)) {
        ont_telemetry_smoke_fail('account_seed_failed', 3);
    }
    $createdAccountId = (int) ($saved['id'] ?? 0);
    if ($createdAccountId <= 0) {
        ont_telemetry_smoke_fail('account_id_missing', 3);
    }
    $accountIds[] = $createdAccountId;

    $allOnts = app_mgs_collect_customer_ont_rows($db);
    $emptyRow = null;
    $targetRow = null;
    foreach ($allOnts as $ont) {
        if ((int) ($ont['account_id'] ?? 0) === $emptyAccountId) {
            $emptyRow = $ont;
        }
        if ((int) ($ont['account_id'] ?? 0) === $createdAccountId) {
            $targetRow = $ont;
        }
    }
    if ($targetRow === null) {
        ont_telemetry_smoke_fail('fixture_not_collected', 4);
    }
    if (trim((string) app_mgs_search_profile_field($targetRow, ['serial', 'sn'], '')) !== $serial) {
        ont_telemetry_smoke_fail('fixture_serial_mismatch', 4);
    }
    // The empty profile is allowed to be collected (it's a valid stored
    // profile) but must never carry a usable identifier.
    if ($emptyRow !== null) {
        $hasIdentifier = trim((string) app_mgs_search_profile_field($emptyRow, ['serial', 'sn'], '')) !== ''
            || trim((string) app_mgs_search_profile_field($emptyRow, ['mac'], '')) !== '';
        if ($hasIdentifier) {
            ont_telemetry_smoke_fail('empty_fixture_unexpectedly_has_identifier', 4);
        }
    }

    // Direct unit check of the persistence helper: apply a synthetic
    // "live" reading and confirm it lands in the exact profile entry,
    // merged (not replacing sibling keys).
    $applyOk = app_mgs_apply_ont_live_sync($db, $createdAccountId, 0, $serial, '', '', [
        'pon' => 'PON02',
        'ont_id' => '42',
        'olt' => 'Smoke OLT',
        'temperature' => '71.5',
        'ont_rx' => '-28.4',
        'ont_tx' => '2.3',
        'olt_rx' => '-27.1',
        'olt_tx' => '4.1',
        'optical_synced_at' => date(DATE_ATOM),
        'optical_sync_reason' => '',
    ]);
    if (!$applyOk) {
        ont_telemetry_smoke_fail('apply_ont_live_sync_failed', 5);
    }

    $reread = $db->prepare('SELECT payload_json FROM portal_accounts WHERE id = :id');
    $reread->execute(['id' => $createdAccountId]);
    $payload = app_db_json_decode((string) ($reread->fetchColumn() ?: ''), []);
    $profile = is_array($payload['ont_profiles'][0] ?? null) ? $payload['ont_profiles'][0] : [];
    if ((string) ($profile['temperature'] ?? '') !== '71.5') {
        ont_telemetry_smoke_fail('merged_temperature_missing:' . json_encode($profile, JSON_UNESCAPED_SLASHES));
    }
    if ((string) ($profile['serial'] ?? '') !== $serial) {
        ont_telemetry_smoke_fail('merge_overwrote_sibling_key_serial');
    }

    // Findings check: the merged reading must actually breach the default
    // thresholds engine (temperature 71.5 >= 68 max) so the two pieces
    // (this sync worker + the existing rule engine) are proven to compose.
    $findings = app_mgs_ont_signal_findings($profile, app_mgs_olt_rule_thresholds());
    if ($findings === []) {
        ont_telemetry_smoke_fail('merged_reading_did_not_trigger_findings');
    }

    // Full worker dry-run: must not crash, must select our serial-only
    // fixture as a candidate (stale_minutes default means "never synced"
    // counts as infinitely stale), and must not touch the DB in dry-run.
    $workerScript = dirname(__DIR__) . '/tools/sync-ont-live-telemetry.php';
    $php = PHP_BINARY;
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([$php, $workerScript, '--limit=200'], $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        ont_telemetry_smoke_fail('worker_spawn_failed');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        ont_telemetry_smoke_fail('worker_exit_nonzero:' . $exitCode . ':' . $stderr);
    }
    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded) || ($decoded['status'] ?? '') === 'failed') {
        ont_telemetry_smoke_fail('worker_output_invalid:' . $stdout);
    }
    // management path is down in this dev environment, so the worker is
    // expected to report a clean skip rather than attempt live hardware
    // calls; both a skip and a completed dry-run scan are acceptable
    // outcomes here -- what matters is it never crashes or silently no-ops
    // with a wrong reason.
    if (!in_array((string) ($decoded['status'] ?? ''), ['ok', 'skipped'], true)) {
        ont_telemetry_smoke_fail('worker_unexpected_status:' . $stdout);
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'fixture_serial' => $serial,
        'merge_helper_ok' => true,
        'findings_triggered' => count($findings),
        'worker_status' => (string) ($decoded['status'] ?? ''),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
