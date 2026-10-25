<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

/**
 * This smoke test verifies the full billing-to-RADIUS lifecycle at scale:
 * expired customer -> RADIUS reject -> terminal/staff payment -> RADIUS accept -> online accounting row.
 */

$options = getopt('', ['count::', 'keep', 'docker-required', 'start-docker', 'runtime::', 'wsl-distro::']);
$count = max(2, min(250, (int) ($options['count'] ?? 24)));
$keep = array_key_exists('keep', $options);
$dockerRequired = array_key_exists('docker-required', $options);
$startDocker = array_key_exists('start-docker', $options);
$requestedRuntime = strtolower(trim((string) ($options['runtime'] ?? 'auto')));
if (!in_array($requestedRuntime, ['auto', 'docker', 'wsl'], true)) {
    fwrite(STDERR, "Unsupported --runtime. Use auto, docker or wsl.\n");
    exit(2);
}
$smokeWslDistro = trim((string) ($options['wsl-distro'] ?? 'Ubuntu-22.04'));
$smokeRadiusRuntime = 'docker';

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL/PDO connection is not ready.\n");
    exit(2);
}

$tariff = $db->query(
    'SELECT * FROM billing_tariffs
     WHERE status = "active"
       AND service_type IN ("pppoe", "ipoe")
       AND monthly_fee > 0
     ORDER BY service_type DESC, monthly_fee, download_mbps
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($tariff)) {
    fwrite(STDERR, "No active PPPoE/IPoE tariff with monthly fee found.\n");
    exit(3);
}

$runId = date('YmdHis') . '-' . bin2hex(random_bytes(2));
$created = [];
$failures = [];
$dockerResults = [];
$prePaymentRejects = 0;

function smoke_query_rows(PDO $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function smoke_first_value(PDO $db, string $sql, array $params = []): mixed
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

function smoke_docker_run(array $args, int $timeoutSeconds = 35): array
{
    $cwd = 'D:\\freeradius';
    if (!is_dir($cwd)) {
        return ['status' => 'skip', 'reason' => 'D:\\freeradius not found'];
    }

    $command = implode(' ', array_map('escapeshellarg', $args));
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        return ['status' => 'fail', 'stdout' => '', 'stderr' => 'proc_open_failed'];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    $timedOut = false;
    while (true) {
        $status = proc_get_status($process);
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        if (!$status['running']) {
            break;
        }
        if ((microtime(true) - $started) > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(150000);
    }

    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'status' => (!$timedOut && $exitCode === 0) ? 'ok' : ($timedOut ? 'timeout' : 'fail'),
        'exit_code' => $timedOut ? null : $exitCode,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
}

function smoke_docker_available(): bool
{
    $result = smoke_docker_run(['docker', 'ps', '--format', '{{.Names}}'], 12);
    return ($result['status'] ?? '') === 'ok';
}

function smoke_wsl_run(array $args, int $timeoutSeconds = 35): array
{
    global $smokeWslDistro;

    $helper = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'wsl-radius-client.sh';
    if (!is_file($helper)) {
        return ['status' => 'skip', 'stdout' => '', 'stderr' => 'wsl_radius_helper_missing'];
    }

    $linuxHelper = '/mnt/' . strtolower(substr($helper, 0, 1)) . str_replace('\\', '/', substr($helper, 2));
    if (preg_match('/^[A-Za-z0-9._-]+$/', $smokeWslDistro) !== 1
        || preg_match('#^/mnt/[a-z]/[A-Za-z0-9._/-]+$#', $linuxHelper) !== 1) {
        return ['status' => 'fail', 'stdout' => '', 'stderr' => 'unsafe_wsl_runtime_path'];
    }
    // WSL1 treats quoted launcher switches/paths as a Linux command. These validated tokens must stay unquoted.
    $command = 'wsl.exe -d ' . $smokeWslDistro . ' -u root -- bash ' . $linuxHelper;
    if ($args !== []) {
        $command .= ' ' . implode(' ', array_map('escapeshellarg', $args));
    }
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return ['status' => 'fail', 'stdout' => '', 'stderr' => 'proc_open_failed'];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    $timedOut = false;
    while (true) {
        $status = proc_get_status($process);
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        if (!$status['running']) {
            break;
        }
        if ((microtime(true) - $started) > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(150000);
    }

    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'status' => (!$timedOut && $exitCode === 0) ? 'ok' : ($timedOut ? 'timeout' : 'fail'),
        'exit_code' => $timedOut ? null : $exitCode,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
}

function smoke_wsl_available(): bool
{
    $result = smoke_wsl_run(['status'], 25);
    return ($result['status'] ?? '') === 'ok'
        && str_contains(strtolower((string) ($result['stdout'] ?? '')), 'running');
}

function smoke_radius_auth(string $username, string $password, string $expect): array
{
    global $smokeRadiusRuntime;

    $result = $smokeRadiusRuntime === 'wsl'
        ? smoke_wsl_run(['auth', $username, $password], 45)
        : smoke_docker_run([
            'docker',
            'compose',
            'exec',
            '-T',
            'freeradius',
            'radtest',
            $username,
            $password,
            '127.0.0.1',
            '0',
            'menim_gizli_acarim123',
        ], 45);

    $text = (string) ($result['stdout'] ?? '') . "\n" . (string) ($result['stderr'] ?? '');
    $result['expected'] = $expect;
    $result['matched'] = str_contains($text, $expect);
    $rejectMatched = $expect === 'Access-Reject' && $result['matched'];
    $result['status'] = ((($result['status'] ?? '') === 'ok' && $result['matched']) || $rejectMatched)
        ? 'ok'
        : ($result['status'] ?? 'fail');
    $result['stdout_excerpt'] = mb_substr((string) ($result['stdout'] ?? ''), 0, 900);
    unset($result['stdout']);
    return $result;
}

function smoke_add_online_accounting(PDO $db, string $username, string $ip, string $runId, int $index): void
{
    $statement = $db->prepare(
        'INSERT INTO radacct (
            acctsessionid, acctuniqueid, username, nasipaddress, nasportid, nasporttype,
            acctstarttime, acctupdatetime, acctsessiontime, acctauthentic,
            acctinputoctets, acctoutputoctets, servicetype, framedprotocol, framedipaddress, class
         ) VALUES (
            :session_id, :unique_id, :username, "85.132.57.218", :nasportid, "Ethernet",
            DATE_SUB(NOW(), INTERVAL :minutes MINUTE), NOW(), :session_time, "RADIUS",
            :in_octets, :out_octets, "Framed-User", "PPP", :ip, :class
         )'
    );
    $statement->execute([
        'session_id' => 'SMOKE-' . $runId . '-' . $index,
        'unique_id' => hash('sha1', $runId . ':' . $username . ':' . $index),
        'username' => $username,
        'nasportid' => 'test-vlan1100-' . $index,
        'minutes' => 5 + $index,
        'session_time' => (5 + $index) * 60,
        'in_octets' => 1000000 + ($index * 151000),
        'out_octets' => 3000000 + ($index * 251000),
        'ip' => $ip,
        'class' => 'radius-cycle-smoke:' . $runId,
    ]);
}

try {
    if ($startDocker) {
        $dockerResults['start'] = smoke_docker_run(['docker', 'compose', 'up', '-d', '--build'], 120);
    }
    $dockerAvailable = false;
    $wslAvailable = false;
    if (!$dockerRequired && in_array($requestedRuntime, ['auto', 'wsl'], true)) {
        $wslAvailable = smoke_wsl_available();
    }
    if ($wslAvailable) {
        $smokeRadiusRuntime = 'wsl';
        $radiusRuntimeAvailable = true;
    } else {
        $smokeRadiusRuntime = 'docker';
        $dockerAvailable = in_array($requestedRuntime, ['auto', 'docker'], true) || $dockerRequired
            ? smoke_docker_available()
            : false;
        $radiusRuntimeAvailable = $dockerAvailable;
    }
    if (!$radiusRuntimeAvailable && $dockerRequired) {
        $dockerResults['availability'] = ['status' => 'fail', 'reason' => 'Docker daemon is not reachable'];
    }
    if (!$radiusRuntimeAvailable && $requestedRuntime === 'wsl') {
        $failures[] = 'Ubuntu WSL FreeRADIUS runtime is not reachable';
    }

    $serviceType = (string) ($tariff['service_type'] ?? 'pppoe');
    $tariffName = (string) ($tariff['name'] ?? 'Smoke tariff');
    $nowExpired = date('Y-m-d H:i:s', strtotime('-2 days'));

    for ($i = 1; $i <= $count; $i++) {
        $code = 'RDS' . date('His') . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $password = 'Rad' . substr(hash('sha1', $runId . ':' . $i), 0, 9);
        $assignedIp = '10.250.' . intdiv($i, 200) . '.' . (20 + ($i % 180));
        $name = 'Radius Cycle Test ' . $i;
        $payload = [
            'profile' => [
                'subscriber_code' => $code,
                'full_name' => $name,
                'phone' => '+99450' . str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            ],
            'billing' => [
                'service_type' => $serviceType,
                'tariff' => $tariffName,
                'pppoe_username' => $code,
                'pppoe_password' => $password,
                'pool' => $serviceType === 'ipoe' ? 'IPoE Pool 1100' : 'Pool 1 - Core',
                'ip_address' => $assignedIp,
                'status' => 'Deaktiv',
                'radius_status' => 'Vaxt bitib',
                'expires_at' => $nowExpired,
                'default_days' => 30,
                'billing_days' => 30,
            ],
            'internet_services' => [
                [
                    'service_type' => $serviceType,
                    'username' => $code,
                    'password' => $password,
                    'tariff' => $tariffName,
                    'pool' => $serviceType === 'ipoe' ? 'IPoE Pool 1100' : 'Pool 1 - Core',
                    'ip_address' => $assignedIp,
                    'status' => 'expired',
                    'expires_at' => $nowExpired,
                ],
            ],
        ];

        $insert = $db->prepare(
            'INSERT INTO portal_accounts (
                login, password_hash, role, account_type, full_name, subscriber_code, phone, plan, status, is_active, payload_json
             ) VALUES (
                :login, :password_hash, "customer", "customer", :full_name, :subscriber_code, :phone, :plan, "Deaktiv", 0, :payload_json
             )'
        );
        $insert->execute([
            'login' => strtolower($code),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name' => $name,
            'subscriber_code' => $code,
            'phone' => '+99450' . str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            'plan' => $tariffName,
            'payload_json' => app_db_json_encode($payload),
        ]);

        $accountId = (int) $db->lastInsertId();
        $created[] = [
            'id' => $accountId,
            'subscriber_code' => $code,
            'password' => $password,
            'ip' => $assignedIp,
        ];

        $account = app_db_account_by_id($accountId);
        if ($account === null || !app_billing_sync_radius_account($account)) {
            $failures[] = $code . ': expired radius sync failed';
            continue;
        }

        $rejectCount = (int) smoke_first_value(
            $db,
            'SELECT COUNT(*) FROM radcheck WHERE username = :username AND attribute = "Auth-Type" AND value = "Reject"',
            ['username' => $code]
        );
        if ($rejectCount !== 1) {
            $failures[] = $code . ': expected Auth-Type Reject before payment';
        } else {
            $prePaymentRejects++;
        }
    }

    if ($radiusRuntimeAvailable && $created !== []) {
        $first = $created[0];
        $dockerResults['expired_radtest'] = smoke_radius_auth((string) $first['subscriber_code'], (string) $first['password'], 'Access-Reject');
        if (($dockerResults['expired_radtest']['status'] ?? '') !== 'ok') {
            $failures[] = $smokeRadiusRuntime . ' radtest did not return Access-Reject for expired customer';
        }
    }

    $terminalPayments = 0;
    $staffPayments = 0;
    foreach ($created as $index => $row) {
        $code = (string) $row['subscriber_code'];
        $method = ($index % 2 === 0) ? 'terminal:cycle-smoke' : 'staff:cashier-smoke';
        $externalId = strtoupper(str_replace('-', '', 'RDS-' . $runId . '-' . $index));
        $transactionId = 'RDS-TX-' . $runId . '-' . $index;
        $ok = app_billing_apply_customer_payment($code, 0.0, $method, 30, $transactionId, $externalId);
        if (!$ok) {
            $failures[] = $code . ': payment activation failed';
            continue;
        }
        if (str_starts_with($method, 'terminal:')) {
            $terminalPayments++;
        } else {
            $staffPayments++;
        }

        smoke_add_online_accounting($db, $code, (string) $row['ip'], $runId, $index);

        $activeAccount = app_db_account_by_id((int) $row['id']);
        if (!is_array($activeAccount) || empty($activeAccount['is_active'])) {
            $failures[] = $code . ': account not active after payment';
        }
        $rejectLeft = (int) smoke_first_value(
            $db,
            'SELECT COUNT(*) FROM radcheck WHERE username = :username AND attribute = "Auth-Type" AND value = "Reject"',
            ['username' => $code]
        );
        $passwordRows = (int) smoke_first_value(
            $db,
            'SELECT COUNT(*) FROM radcheck WHERE username = :username AND attribute = "Cleartext-Password"',
            ['username' => $code]
        );
        $replyRows = (int) smoke_first_value(
            $db,
            'SELECT COUNT(*) FROM radreply WHERE username = :username',
            ['username' => $code]
        );
        if ($rejectLeft !== 0 || $passwordRows < 1 || $replyRows < 2) {
            $failures[] = $code . ': active RADIUS rows incomplete';
        }
    }

    if ($radiusRuntimeAvailable && count($created) > 1) {
        $first = $created[0];
        $second = $created[1];
        $dockerResults['active_radtest_terminal'] = smoke_radius_auth((string) $first['subscriber_code'], (string) $first['password'], 'Access-Accept');
        $dockerResults['active_radtest_staff'] = smoke_radius_auth((string) $second['subscriber_code'], (string) $second['password'], 'Access-Accept');
        foreach (['active_radtest_terminal', 'active_radtest_staff'] as $key) {
            if (($dockerResults[$key]['status'] ?? '') !== 'ok') {
                $failures[] = $smokeRadiusRuntime . ' ' . $key . ' did not return Access-Accept';
            }
        }
    }

    $codes = array_column($created, 'subscriber_code');
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $metrics = [
        'created_customers' => count($created),
        'pre_payment_reject_verified' => $prePaymentRejects,
        'post_payment_reject_rows' => $codes === [] ? 0 : (int) smoke_first_value($db, 'SELECT COUNT(*) FROM radcheck WHERE username IN (' . $placeholders . ') AND attribute = "Auth-Type" AND value = "Reject"', $codes),
        'active_password_rows' => $codes === [] ? 0 : (int) smoke_first_value($db, 'SELECT COUNT(*) FROM radcheck WHERE username IN (' . $placeholders . ') AND attribute = "Cleartext-Password"', $codes),
        'active_reply_rows' => $codes === [] ? 0 : (int) smoke_first_value($db, 'SELECT COUNT(*) FROM radreply WHERE username IN (' . $placeholders . ')', $codes),
        'open_online_sessions' => $codes === [] ? 0 : (int) smoke_first_value($db, 'SELECT COUNT(*) FROM radacct WHERE username IN (' . $placeholders . ') AND acctstoptime IS NULL', $codes),
        'terminal_payments' => $terminalPayments,
        'staff_payments' => $staffPayments,
    ];
} finally {
    if (!$keep && $created !== []) {
        $codes = array_column($created, 'subscriber_code');
        $ids = array_column($created, 'id');
        $codePlaceholders = implode(',', array_fill(0, count($codes), '?'));
        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            $db->prepare("DELETE FROM {$table} WHERE username IN ({$codePlaceholders})")->execute($codes);
        }
        $db->prepare("DELETE FROM radacct WHERE username IN ({$codePlaceholders}) OR class = ?")->execute(array_merge($codes, ['radius-cycle-smoke:' . $runId]));
        $db->prepare("DELETE r FROM billing_payment_reversals r JOIN billing_payment_operations o ON o.id = r.operation_id WHERE o.account_id IN ({$idPlaceholders})")->execute($ids);
        $db->prepare("DELETE e FROM billing_payment_operation_events e JOIN billing_payment_operations o ON o.id = e.operation_id WHERE o.account_id IN ({$idPlaceholders})")->execute($ids);
        $db->prepare("DELETE a FROM billing_payment_service_attempts a JOIN billing_payment_service_actions s ON s.id = a.service_action_id JOIN billing_payment_operations o ON o.id = s.operation_id WHERE o.account_id IN ({$idPlaceholders})")->execute($ids);
        $db->prepare("DELETE b FROM billing_payment_outbox b JOIN billing_payment_service_actions s ON s.id = b.service_action_id JOIN billing_payment_operations o ON o.id = s.operation_id WHERE o.account_id IN ({$idPlaceholders})")->execute($ids);
        $db->prepare("DELETE s FROM billing_payment_service_actions s JOIN billing_payment_operations o ON o.id = s.operation_id WHERE o.account_id IN ({$idPlaceholders})")->execute($ids);
        $db->prepare("DELETE FROM billing_payment_operations WHERE account_id IN ({$idPlaceholders})")->execute($ids);
        $db->prepare("DELETE FROM billing_payments WHERE subscriber_code IN ({$codePlaceholders})")->execute($codes);
        $db->prepare("DELETE FROM billing_radius_services WHERE account_id IN ({$idPlaceholders})")->execute($ids);
        $db->prepare("DELETE FROM ip_assignment_history WHERE customer_id IN ({$idPlaceholders})")->execute($ids);
        $db->prepare("DELETE FROM customer_ip_assignments WHERE customer_id IN ({$idPlaceholders})")->execute($ids);
        $db->prepare("UPDATE ip_addresses SET status = 'free', customer_id = NULL, assigned_at = NULL, released_at = NOW(), note = CONCAT(COALESCE(note, ''), ' smoke cleanup') WHERE customer_id IN ({$idPlaceholders})")->execute($ids);
        $db->prepare("DELETE FROM portal_accounts WHERE id IN ({$idPlaceholders})")->execute($ids);
    }
}

$status = $failures === [] ? 'ok' : 'failed';
if (!$dockerAvailable && $dockerRequired) {
    $status = 'failed';
}

$report = [
    'status' => $status,
    'run_id' => $runId,
    'generated_at' => date(DATE_ATOM),
    'requested_count' => $count,
    'service_type' => (string) ($tariff['service_type'] ?? ''),
    'tariff' => (string) ($tariff['name'] ?? ''),
    'sample_users' => array_values(array_map(
        static fn (array $row): array => [
            'subscriber_code' => (string) ($row['subscriber_code'] ?? ''),
            'password' => (string) ($row['password'] ?? ''),
            'ip' => (string) ($row['ip'] ?? ''),
        ],
        array_slice($created, 0, 3)
    )),
    'metrics' => $metrics ?? [],
    'radius_runtime' => [
        'requested' => $requestedRuntime,
        'selected' => $smokeRadiusRuntime,
        'available' => $radiusRuntimeAvailable ?? false,
        'wsl_distro' => $smokeRadiusRuntime === 'wsl' ? $smokeWslDistro : null,
    ],
    'docker' => [
        'available' => $dockerAvailable ?? false,
        'required' => $dockerRequired,
        'results' => $dockerResults,
    ],
    'cleanup' => !$keep,
    'failures' => $failures,
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
