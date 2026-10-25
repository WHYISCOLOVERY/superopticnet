<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require_once $root . '/huawei-olt-lib.php';
require_once $root . '/db.php';

$passes = [];
$failures = [];

function stage6_check(string $name, bool $condition, array &$passes, array &$failures, string $detail = ''): void
{
    if ($condition) {
        $passes[] = $name;
        return;
    }
    $failures[] = $name . ($detail !== '' ? ' (' . $detail . ')' : '');
}

/* 1. Both OLT profiles and both ONT models are present with the right release. */
$profiles = app_huawei_olt_profiles();
stage6_check('MA5800-X7 profile present', ($profiles['MA5800-X7']['release'] ?? '') === 'V100R024C10', $passes, $failures);
stage6_check('MA5608T profile present', ($profiles['MA5608T']['release'] ?? '') === 'V800R019C10', $passes, $failures);
$onts = app_huawei_ont_models();
stage6_check('EG8141A5 model present', isset($onts['EG8141A5']), $passes, $failures);
stage6_check('EG8245W5-6T model present', isset($onts['EG8245W5-6T']), $passes, $failures);
stage6_check('EG8245W5-6T is dual-band', str_contains((string) ($onts['EG8245W5-6T']['wifi'] ?? ''), '5GHz'), $passes, $failures);

/* 2. Command matrix covers every operator intent. */
$matrix = app_huawei_command_matrix();
foreach (['read_olt', 'read_ont', 'config_change', 'vlan_push', 'ont_control'] as $category) {
    stage6_check('matrix has category ' . $category, isset($matrix[$category]) && $matrix[$category] !== [], $passes, $failures);
}
$flat = [];
foreach ($matrix as $rows) {
    foreach ($rows as $row) {
        $flat[$row['key']] = $row;
    }
}
foreach (['version', 'board_all', 'ont_info', 'ont_optical', 'ont_add', 'service_port', 'vlan_create', 'ont_reset'] as $expectedKey) {
    stage6_check('command exists: ' . $expectedKey, isset($flat[$expectedKey]), $passes, $failures);
}

/* 3. Read commands are never dangerous; config/vlan/control mutations are. */
foreach ($matrix['read_olt'] as $row) {
    stage6_check('read_olt safe: ' . $row['key'], $row['dangerous'] === false, $passes, $failures);
}
foreach ($matrix['read_ont'] as $row) {
    stage6_check('read_ont safe: ' . $row['key'], $row['dangerous'] === false, $passes, $failures);
}
stage6_check('ont_add is dangerous', $flat['ont_add']['dangerous'] === true, $passes, $failures);
stage6_check('service_port is dangerous', $flat['service_port']['dangerous'] === true, $passes, $failures);
stage6_check('ont_delete is dangerous', $flat['ont_delete']['dangerous'] === true, $passes, $failures);

/* 4. SNMP OIDs are standard-tree only (no Huawei enterprise 1.3.6.1.4.1.2011). */
$oids = app_huawei_snmp_oids();
stage6_check('snmp oid set non-empty', $oids !== [], $passes, $failures);
$enterpriseLeak = false;
foreach ($oids as $entry) {
    if (str_starts_with((string) $entry['oid'], '1.3.6.1.4.1.2011')) {
        $enterpriseLeak = true;
    }
}
stage6_check('no Huawei enterprise OID leak', !$enterpriseLeak, $passes, $failures);
stage6_check('sys_descr scalar present', ($oids['sys_descr']['oid'] ?? '') === '1.3.6.1.2.1.1.1.0', $passes, $failures);
stage6_check('entity board walk present', ($oids['ent_phys_descr']['oid'] ?? '') === '1.3.6.1.2.1.47.1.1.1.1.2', $passes, $failures);

/* 5. Live-test plan renders every command with no leftover placeholders. */
foreach (['MA5800-X7', 'MA5608T'] as $model) {
    $plan = app_huawei_live_test_plan($model, [
        'slot' => 2, 'port' => 3, 'ont' => 7, 'vlan' => 305, 'gem' => 2,
        'uservlan' => 305, 'sportidx' => 42, 'sn' => '48575443AABBCCDD',
    ]);
    stage6_check($model . ': four intents present', count($plan['intents']) === 4, $passes, $failures);
    $unrendered = 0;
    $totalSteps = 0;
    foreach ($plan['intents'] as $intent) {
        foreach ($intent['steps'] as $step) {
            $totalSteps++;
            if (app_huawei_has_unrendered((string) $step['command'])) {
                $unrendered++;
            }
            foreach ($step['context_commands'] as $contextCommand) {
                if (app_huawei_has_unrendered((string) $contextCommand)) {
                    $unrendered++;
                }
            }
        }
    }
    stage6_check($model . ': no unrendered placeholders', $unrendered === 0, $passes, $failures, 'leftover=' . $unrendered);
    stage6_check($model . ': plan has steps', $totalSteps >= 12, $passes, $failures, 'steps=' . $totalSteps);
    // A representative rendered command is exact.
    $ontRead = null;
    foreach ($plan['intents']['ont_read']['steps'] as $step) {
        if ($step['key'] === 'ont_info') {
            $ontRead = $step;
        }
    }
    stage6_check($model . ': ont_info renders port/ont', $ontRead !== null && $ontRead['command'] === 'display ont info 3 7', $passes, $failures, (string) ($ontRead['command'] ?? ''));
    stage6_check($model . ': gpon context rendered', $ontRead !== null && in_array('interface gpon 0/2', $ontRead['context_commands'], true), $passes, $failures);
}

/* 6. Hard live gate stays closed until real hardware is validated. */
$db = app_db();
$gateDefault = app_huawei_live_ready('MA5800-X7', [], $db);
stage6_check('live gate closed by default', empty($gateDefault['ok']), $passes, $failures, (string) ($gateDefault['reason'] ?? ''));
$unsupported = app_huawei_live_ready('MA5600T-UNKNOWN', ['hardware_validated' => true], $db);
stage6_check('unsupported model rejected', empty($unsupported['ok']) && $unsupported['reason'] === 'unsupported_model', $passes, $failures);

// Even with the flag on, an unvalidated device must not go live.
if ($db instanceof PDO) {
    $flagRow = $db->query('SELECT is_enabled FROM app_feature_flags WHERE flag_key = "stage6_huawei_adapter"')->fetchColumn();
    $flagOn = $flagRow !== false && (int) $flagRow === 1;
    $gateUnvalidated = app_huawei_live_ready('MA5800-X7', ['hardware_validated' => false], $db);
    stage6_check('unvalidated device blocked regardless of flag', empty($gateUnvalidated['ok']), $passes, $failures, (string) ($gateUnvalidated['reason'] ?? ''));
    if ($flagOn) {
        stage6_check('flag on: reason is hardware-not-validated', ($gateUnvalidated['reason'] ?? '') === 'real_hardware_not_validated', $passes, $failures, (string) ($gateUnvalidated['reason'] ?? ''));
        $gateValidated = app_huawei_live_ready('MA5800-X7', ['hardware_validated' => true], $db);
        stage6_check('flag on + validated device is live', !empty($gateValidated['ok']), $passes, $failures, (string) ($gateValidated['reason'] ?? ''));
    } else {
        stage6_check('flag off: reason is feature-flag-disabled', ($gateUnvalidated['reason'] ?? '') === 'feature_flag_disabled', $passes, $failures, (string) ($gateUnvalidated['reason'] ?? ''));
    }
} else {
    $passes[] = 'db-dependent gate checks skipped (database unavailable)';
}

/* 7. Migration + cutover contracts. */
$migration = (string) @file_get_contents($root . '/db/migrations/20260725_070_stage6_huawei_adapter.php');
stage6_check('stage6 migration exists', $migration !== '', $passes, $failures);
stage6_check('stage6 migration refuses enabled rollback', str_contains($migration, 'stage6_rollback_requires_disabled_flag'), $passes, $failures);
$cutover = (string) @file_get_contents($root . '/tools/stage6-feature-cutover.php');
stage6_check('stage6 cutover guards migration', str_contains($cutover, 'stage6_migration_not_ready'), $passes, $failures);

echo 'stage6_matrix_pass=' . count($passes) . PHP_EOL;
if ($failures !== []) {
    echo 'stage6_matrix_fail=' . count($failures) . PHP_EOL;
    foreach ($failures as $failure) {
        echo 'FAIL: ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'stage6_matrix_fail=0' . PHP_EOL;
exit(0);
