<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require dirname(__DIR__) . '/security-anomaly-lib.php';

function security_anomaly_smoke_out(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO || !app_security_anomaly_ensure_schema($db)) {
    security_anomaly_smoke_out(['status' => 'skipped', 'reason' => 'db_unavailable']);
}

$stamp = date('His') . random_int(100, 999);
$subscriberCode = 'SEC-SMOKE-' . $stamp;
$username = $subscriberCode;
$sourceIp = '10.250.' . random_int(10, 99) . '.' . random_int(10, 220);
$accountId = 0;
$serviceId = 0;
$radacctIds = [];
$natHashes = [];

try {
    $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, phone, area, address, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", "Security Smoke Customer", :subscriber_code, "0500000099", "Security zona", "Security unvan", "Security 100", "Aktiv", 1, :payload_json
         )'
    )->execute([
        'login' => $subscriberCode,
        'password_hash' => password_hash('security-smoke', PASSWORD_DEFAULT),
        'subscriber_code' => $subscriberCode,
        'payload_json' => app_db_json_encode(['billing' => ['pppoe_username' => $username]]),
    ]);
    $accountId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO billing_radius_services (
            account_id, username, service_type, assigned_ip, status, expires_at
         ) VALUES (
            :account_id, :username, "pppoe", :assigned_ip, "active", DATE_ADD(NOW(), INTERVAL 30 DAY)
         )'
    )->execute([
        'account_id' => $accountId,
        'username' => $username,
        'assigned_ip' => $sourceIp,
    ]);
    $serviceId = (int) $db->lastInsertId();

    for ($i = 0; $i < 6; $i++) {
        $sessionId = 'SEC' . $stamp . '-' . $i;
        $db->prepare(
            'INSERT INTO radacct (
                acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime, acctupdatetime, acctstoptime,
                acctsessiontime, acctinputoctets, acctoutputoctets, framedipaddress, callingstationid
             ) VALUES (
                :session_id, :unique_id, :username, "85.132.57.218", DATE_SUB(NOW(), INTERVAL 30 MINUTE), NOW(), NULL,
                1800, :input_octets, :output_octets, :framed_ip, "AA:BB:CC:DD:EE:99"
             )'
        )->execute([
            'session_id' => $sessionId,
            'unique_id' => hash('sha1', $sessionId),
            'username' => $username,
            'input_octets' => 40 * 1073741824,
            'output_octets' => 1 * 1073741824,
            'framed_ip' => $sourceIp,
        ]);
        $radacctIds[] = (int) $db->lastInsertId();
    }

    for ($i = 0; $i < 18; $i++) {
        $hash = hash('sha256', 'security-smoke|' . $stamp . '|' . $i);
        $natHashes[] = $hash;
        $db->prepare(
            'INSERT INTO radius_nat_logs (
                logged_at, node_name, protocol, source_ip, source_port, nat_ip, nat_port,
                destination_ip, destination_port, username, subscriber_code, raw_message, message_hash
             ) VALUES (
                NOW(), "security-smoke-node", "tcp", :source_ip, :source_port, "94.20.46.250", :nat_port,
                :destination_ip, :destination_port, :username, :subscriber_code, :raw_message, :message_hash
             )'
        )->execute([
            'source_ip' => $sourceIp,
            'source_port' => 40000 + $i,
            'nat_port' => 50000 + $i,
            'destination_ip' => '198.51.100.' . ($i + 1),
            'destination_port' => 1000 + $i,
            'username' => $username,
            'subscriber_code' => $subscriberCode,
            'raw_message' => 'security smoke flow ' . $i,
            'message_hash' => $hash,
        ]);
    }

    $securityActor = app_noc_system_actor('security-anomaly-worker');
    $result = app_security_anomaly_run($db, [
        'high_total_gb' => 10.0,
        'high_upload_gb' => 5.0,
        'active_session_threshold' => 3,
        'nat_destination_threshold' => 10,
        'nat_port_threshold' => 10,
        'max_findings' => 250,
        'record' => true,
        'test_mode' => true,
    ], $securityActor);
    $answer = app_security_anomaly_answer('sebeke doludur kim doldurur virus ola biler?', $db, $securityActor);

    $findingTypes = array_values(array_unique(array_map(
        static fn (array $finding): string => (string) ($finding['signal_type'] ?? ''),
        array_filter((array) ($result['findings'] ?? []), 'is_array')
    )));
    $recorded = (int) $db->query('SELECT COUNT(*) FROM security_anomaly_events WHERE subject_id IN (' . $db->quote($subscriberCode) . ',' . $db->quote($username) . ',' . $db->quote($sourceIp) . ')')->fetchColumn();

    $ok = !empty($result['ok'])
        && (int) ($result['summary']['findings'] ?? 0) >= 3
        && in_array('upload_abuse', $findingTypes, true)
        && in_array('session_storm', $findingTypes, true)
        && in_array('nat_fanout', $findingTypes, true)
        && $recorded >= 1
        && !empty($answer['ok'])
        && str_contains((string) ($answer['text'] ?? ''), 'SECURITY/NOC ANALIZI');

    security_anomaly_smoke_out([
        'status' => $ok ? 'ok' : 'failed',
        'subscriber_code' => $subscriberCode,
        'source_ip' => $sourceIp,
        'finding_types' => $findingTypes,
        'finding_count' => (int) ($result['summary']['findings'] ?? 0),
        'recorded_events' => $recorded,
        'answer_ok' => !empty($answer['ok']),
    ], $ok ? 0 : 4);
} catch (Throwable $exception) {
    security_anomaly_smoke_out(['status' => 'failed', 'reason' => $exception->getMessage()], 2);
} finally {
    if ($natHashes !== []) {
        $in = implode(',', array_map(static fn (string $hash): string => app_db()->quote($hash), $natHashes));
        $db->exec('DELETE FROM radius_nat_logs WHERE message_hash IN (' . $in . ')');
    }
    if ($radacctIds !== []) {
        $in = implode(',', array_map('intval', $radacctIds));
        $db->exec('DELETE FROM radacct WHERE radacctid IN (' . $in . ')');
    }
    $db->prepare('DELETE FROM security_anomaly_events WHERE username = :username OR subscriber_code = :subscriber_code OR ip_address = :ip')->execute([
        'username' => $username,
        'subscriber_code' => $subscriberCode,
        'ip' => $sourceIp,
    ]);
    $db->prepare('DELETE FROM mgs_alarm_events WHERE source = "security_anomaly_engine" AND object_id IN (:subscriber_code, :username, :ip)')->execute([
        'subscriber_code' => $subscriberCode,
        'username' => $username,
        'ip' => $sourceIp,
    ]);
    $db->prepare('DELETE FROM mgs_operation_actions WHERE action_type IN ("security_anomaly_scan","alarm_recorded") AND payload_json LIKE :needle')->execute([
        'needle' => '%' . $subscriberCode . '%',
    ]);
    if ($serviceId > 0) {
        $db->prepare('DELETE FROM billing_radius_services WHERE id = :id')->execute(['id' => $serviceId]);
    }
    if ($accountId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}
