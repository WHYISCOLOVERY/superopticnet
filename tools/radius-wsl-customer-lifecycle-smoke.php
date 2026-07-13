<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$options = getopt('', ['wsl-distro::', 'keep']);
$wslDistro = trim((string) ($options['wsl-distro'] ?? 'Ubuntu-22.04'));
$keep = array_key_exists('keep', $options);
if (preg_match('/^[A-Za-z0-9._-]+$/', $wslDistro) !== 1) {
    fwrite(STDERR, "Invalid WSL distro name.\n");
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL/PDO connection is not ready.\n");
    exit(2);
}

$stamp = date('YmdHis') . bin2hex(random_bytes(2));
$subscriberCode = 'WSLLIFE' . substr($stamp, -12);
$login = strtolower($subscriberCode);
$oldPassword = 'LifeA' . substr(hash('sha256', $stamp), 0, 8);
$newPassword = 'LifeB' . substr(hash('sha256', $stamp), 8, 8);
$sessionId = 'WSL-LIFE-' . substr($stamp, -16);
$accountId = 0;
$tariffIds = [];
$billingPoolId = 0;
$enginePoolId = 0;
$checks = [];
$errors = [];
$assignedIp = '';

function lifecycle_run_process(string $command, int $timeoutSeconds = 45): array
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return ['ok' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'proc_open_failed'];
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
        usleep(100000);
    }
    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'ok' => !$timedOut && $exitCode === 0,
        'exit_code' => $timedOut ? 124 : $exitCode,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
}

function lifecycle_wsl(string $distro, array $args, int $timeoutSeconds = 45): array
{
    $helper = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'wsl-radius-client.sh';
    $linuxHelper = '/mnt/' . strtolower(substr($helper, 0, 1)) . str_replace('\\', '/', substr($helper, 2));
    if (preg_match('#^/mnt/[a-z]/[A-Za-z0-9._/-]+$#', $linuxHelper) !== 1) {
        return ['ok' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'unsafe_wsl_helper_path'];
    }
    $command = 'wsl.exe -d ' . $distro . ' -u root -- bash ' . $linuxHelper;
    if ($args !== []) {
        $command .= ' ' . implode(' ', array_map('escapeshellarg', $args));
    }
    return lifecycle_run_process($command, $timeoutSeconds);
}

function lifecycle_auth(string $distro, string $username, string $password, string $expected): array
{
    $result = lifecycle_wsl($distro, ['auth', $username, $password]);
    $combined = (string) ($result['stdout'] ?? '') . "\n" . (string) ($result['stderr'] ?? '');
    $matched = str_contains($combined, $expected);
    return [
        'ok' => $matched && ($expected === 'Access-Reject' || !empty($result['ok'])),
        'expected' => $expected,
        'matched' => $matched,
        'exit_code' => (int) ($result['exit_code'] ?? -1),
        'reply_message_present' => str_contains($combined, 'Reply-Message'),
    ];
}

function lifecycle_check(array &$checks, string $name, bool $ok, array $details = []): void
{
    $checks[$name] = ['ok' => $ok, 'details' => $details];
    if (!$ok) {
        throw new RuntimeException('check_failed:' . $name);
    }
}

function lifecycle_value(PDO $db, string $sql, array $params = []): mixed
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

try {
    $radiusStatus = lifecycle_wsl($wslDistro, ['status'], 30);
    lifecycle_check(
        $checks,
        'wsl_freeradius_running',
        !empty($radiusStatus['ok']) && str_contains(strtolower((string) ($radiusStatus['stdout'] ?? '')), 'running')
    );

    $poolOctet = 10 + (hexdec(substr(hash('sha256', $stamp), 0, 2)) % 200);
    $poolName = 'WSL Lifecycle Pool ' . $stamp;
    $poolInsert = $db->prepare(
        'INSERT INTO billing_ip_pools (
            name, service_type, pool_type, range_start, range_end, gateway, netmask, status
         ) VALUES (
            :name, "pppoe", "dynamic", :range_start, :range_end, :gateway, "255.255.255.0", "active"
         )'
    );
    $poolInsert->execute([
        'name' => $poolName,
        'range_start' => '10.253.' . $poolOctet . '.10',
        'range_end' => '10.253.' . $poolOctet . '.20',
        'gateway' => '10.253.' . $poolOctet . '.1',
    ]);
    $billingPoolId = (int) $db->lastInsertId();
    app_ip_sync_legacy_pools($db);
    $enginePoolId = (int) lifecycle_value(
        $db,
        'SELECT id FROM ip_pools WHERE legacy_billing_pool_id = :legacy_id LIMIT 1',
        ['legacy_id' => $billingPoolId]
    );
    $poolStatement = $db->prepare('SELECT * FROM billing_ip_pools WHERE id = :id LIMIT 1');
    $poolStatement->execute(['id' => $billingPoolId]);
    $pool = $poolStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($pool)) {
        throw new RuntimeException('temporary_pppoe_pool_not_created');
    }
    lifecycle_check(
        $checks,
        'ip_pool_create',
        $billingPoolId > 0 && $enginePoolId > 0,
        ['billing_pool_id' => $billingPoolId, 'engine_pool_id' => $enginePoolId]
    );

    $tariffInsert = $db->prepare(
        'INSERT INTO billing_tariffs (
            external_id, name, service_type, download_mbps, upload_mbps, monthly_fee,
            radius_group, status, metadata_json
         ) VALUES (
            :external_id, :name, "pppoe", :download_mbps, :upload_mbps, :monthly_fee,
            :radius_group, "active", :metadata_json
         )'
    );
    $tariffs = [];
    foreach ([
        ['suffix' => 'A', 'download' => 50, 'upload' => 25, 'fee' => 19.90],
        ['suffix' => 'B', 'download' => 100, 'upload' => 50, 'fee' => 29.90],
    ] as $definition) {
        $name = 'WSL Lifecycle ' . $definition['suffix'] . ' ' . $stamp;
        $group = 'wsl-life-' . strtolower($definition['suffix']) . '-' . strtolower($stamp);
        $tariffInsert->execute([
            'external_id' => 'WSL-LIFE-' . $definition['suffix'] . '-' . $stamp,
            'name' => $name,
            'download_mbps' => $definition['download'],
            'upload_mbps' => $definition['upload'],
            'monthly_fee' => $definition['fee'],
            'radius_group' => $group,
            'metadata_json' => app_db_json_encode([
                'source' => 'radius-wsl-customer-lifecycle-smoke',
                'temporary' => true,
            ]),
        ]);
        $id = (int) $db->lastInsertId();
        $tariffIds[] = $id;
        $tariffs[] = ['id' => $id, 'name' => $name, 'group' => $group, 'fee' => (float) $definition['fee']];
    }
    lifecycle_check($checks, 'package_create', count($tariffIds) === 2, ['tariff_ids' => $tariffIds]);

    $expiredAt = date('Y-m-d H:i:s', time() - 3600);
    $payload = [
        'profile' => [
            'subscriber_code' => $subscriberCode,
            'full_name' => 'WSL Radius Lifecycle Test',
            'phone' => '+994000000000',
        ],
        'billing' => [
            'service_type' => 'pppoe',
            'tariff' => $tariffs[0]['name'],
            'pppoe_username' => $subscriberCode,
            'pppoe_password' => $oldPassword,
            'pool' => (string) $pool['name'],
            'ip_assignment_mode' => 'manual',
            'status' => 'Deaktiv',
            'radius_status' => 'Vaxt bitib',
            'expires_at' => $expiredAt,
            'default_days' => 30,
            'billing_days' => 30,
        ],
        'internet_services' => [[
            'service_type' => 'pppoe',
            'username' => $subscriberCode,
            'password' => $oldPassword,
            'tariff' => $tariffs[0]['name'],
            'pool' => (string) $pool['name'],
            'ip_assignment_mode' => 'manual',
            'status' => 'expired',
            'expires_at' => $expiredAt,
        ]],
    ];

    $accountInsert = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code,
            phone, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", :full_name, :subscriber_code,
            :phone, :plan, "Deaktiv", 0, :payload_json
         )'
    );
    $accountInsert->execute([
        'login' => $login,
        'password_hash' => password_hash($oldPassword, PASSWORD_DEFAULT),
        'full_name' => 'WSL Radius Lifecycle Test',
        'subscriber_code' => $subscriberCode,
        'phone' => '+994000000000',
        'plan' => $tariffs[0]['name'],
        'payload_json' => app_db_json_encode($payload),
    ]);
    $accountId = (int) $db->lastInsertId();
    $account = app_db_account_by_id($accountId);
    lifecycle_check($checks, 'customer_create', is_array($account) && $accountId > 0, ['account_id' => $accountId]);
    lifecycle_check($checks, 'initial_radius_sync', app_billing_sync_radius_account($account));
    lifecycle_check($checks, 'expired_auth_reject', lifecycle_auth($wslDistro, $subscriberCode, $oldPassword, 'Access-Reject')['ok']);

    $paymentStarted = microtime(true);
    $paymentOk = app_billing_apply_customer_payment(
        $subscriberCode,
        0.0,
        'terminal:wsl-lifecycle',
        30,
        'WSL-LIFE-TX1-' . $stamp,
        'WSL-LIFE-EXT1-' . $stamp
    );
    $activeAuth = lifecycle_auth($wslDistro, $subscriberCode, $oldPassword, 'Access-Accept');
    $activationMs = (int) round((microtime(true) - $paymentStarted) * 1000);
    lifecycle_check($checks, 'payment_immediate_activation', $paymentOk && $activeAuth['ok'], ['activation_ms' => $activationMs]);

    $serviceStatement = $db->prepare('SELECT * FROM billing_radius_services WHERE account_id = :id LIMIT 1');
    $serviceStatement->execute(['id' => $accountId]);
    $service = $serviceStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $assignedIp = trim((string) ($service['assigned_ip'] ?? ''));
    $replyIp = (string) lifecycle_value(
        $db,
        'SELECT value FROM radreply WHERE username = :username AND attribute = "Framed-IP-Address" ORDER BY id DESC LIMIT 1',
        ['username' => $subscriberCode]
    );
    lifecycle_check(
        $checks,
        'ip_assignment_and_radius_reply',
        $assignedIp !== '' && $replyIp === $assignedIp,
        ['assigned_ip' => $assignedIp, 'pool' => (string) $pool['name']]
    );

    $acctStart = lifecycle_wsl($wslDistro, ['accounting-start', $subscriberCode, 'unused', $assignedIp, $sessionId]);
    $openAccounting = (int) lifecycle_value(
        $db,
        'SELECT COUNT(*) FROM radacct WHERE acctsessionid = :session_id AND username = :username AND acctstoptime IS NULL',
        ['session_id' => $sessionId, 'username' => $subscriberCode]
    );
    lifecycle_check($checks, 'accounting_start', !empty($acctStart['ok']) && $openAccounting === 1);
    $acctStop = lifecycle_wsl($wslDistro, ['accounting-stop', $subscriberCode, 'unused', $assignedIp, $sessionId]);
    $closedAccounting = (int) lifecycle_value(
        $db,
        'SELECT COUNT(*) FROM radacct WHERE acctsessionid = :session_id AND username = :username AND acctstoptime IS NOT NULL',
        ['session_id' => $sessionId, 'username' => $subscriberCode]
    );
    lifecycle_check($checks, 'accounting_stop', !empty($acctStop['ok']) && $closedAccounting === 1);

    $account = app_db_account_by_id($accountId);
    $payload = app_db_account_payload($account);
    $payload['billing']['pppoe_password'] = $newPassword;
    $payload['internet_services'][0]['password'] = $newPassword;
    lifecycle_check($checks, 'portal_password_change', app_db_update_password($account, $oldPassword, $newPassword));
    lifecycle_check($checks, 'radius_password_payload_save', app_billing_save_account_payload($account, $payload, 'Aktiv'));
    $account = app_db_account_by_id($accountId);
    lifecycle_check($checks, 'radius_password_sync', app_billing_sync_radius_account($account, $newPassword));
    $oldAuth = lifecycle_auth($wslDistro, $subscriberCode, $oldPassword, 'Access-Reject');
    $newAuth = lifecycle_auth($wslDistro, $subscriberCode, $newPassword, 'Access-Accept');
    $portalLogin = app_db_login('customer', $login, $newPassword);
    lifecycle_check(
        $checks,
        'password_change_enforced',
        $oldAuth['ok'] && $newAuth['ok'] && is_array($portalLogin),
        ['old_password_rejected' => $oldAuth['ok'], 'new_password_accepted' => $newAuth['ok']]
    );

    $account = app_db_account_by_id($accountId);
    $payload = app_db_account_payload($account);
    $payload['billing']['tariff'] = $tariffs[1]['name'];
    $payload['internet_services'][0]['tariff'] = $tariffs[1]['name'];
    $change = $db->prepare('UPDATE portal_accounts SET plan = :plan, payload_json = :payload_json, updated_at = NOW() WHERE id = :id');
    $change->execute([
        'plan' => $tariffs[1]['name'],
        'payload_json' => app_db_json_encode($payload),
        'id' => $accountId,
    ]);
    $account = app_db_account_by_id($accountId);
    lifecycle_check($checks, 'package_change_radius_sync', app_billing_sync_radius_account($account, $newPassword));
    $group = (string) lifecycle_value(
        $db,
        'SELECT groupname FROM radusergroup WHERE username = :username ORDER BY priority, id LIMIT 1',
        ['username' => $subscriberCode]
    );
    $serviceTariffId = (int) lifecycle_value(
        $db,
        'SELECT tariff_id FROM billing_radius_services WHERE account_id = :id LIMIT 1',
        ['id' => $accountId]
    );
    lifecycle_check(
        $checks,
        'package_change_enforced',
        $group === $tariffs[1]['group']
            && $serviceTariffId === $tariffs[1]['id']
            && lifecycle_auth($wslDistro, $subscriberCode, $newPassword, 'Access-Accept')['ok'],
        ['radius_group' => $group, 'tariff_id' => $serviceTariffId]
    );

    $past = date('Y-m-d H:i:s', time() - 60);
    $account = app_db_account_by_id($accountId);
    $payload = app_db_account_payload($account);
    $payload['billing']['expires_at'] = $past;
    $payload['billing']['status'] = 'Aktiv';
    $payload['internet_services'][0]['expires_at'] = $past;
    $payload['internet_services'][0]['status'] = 'active';
    lifecycle_check($checks, 'expiry_payload_save', app_billing_save_account_payload($account, $payload, 'Aktiv'));
    $expireService = $db->prepare(
        'UPDATE billing_radius_services
         SET status = "active", auto_suspend = 1, expires_at = :expires_at
         WHERE account_id = :account_id'
    );
    $expireService->execute(['expires_at' => $past, 'account_id' => $accountId]);
    $suspension = app_billing_process_due_suspensions();
    $expiredAccount = app_db_account_by_id($accountId);
    $expiredAuth = lifecycle_auth($wslDistro, $subscriberCode, $newPassword, 'Access-Reject');
    lifecycle_check(
        $checks,
        'automatic_expiry_suspension',
        (int) ($suspension['suspended'] ?? 0) >= 1
            && (int) ($expiredAccount['is_active'] ?? 1) === 0
            && $expiredAuth['ok'],
        ['suspended' => (int) ($suspension['suspended'] ?? 0)]
    );

    $reactivateStarted = microtime(true);
    $reactivated = app_billing_apply_customer_payment(
        $subscriberCode,
        0.0,
        'staff:wsl-lifecycle',
        30,
        'WSL-LIFE-TX2-' . $stamp,
        'WSL-LIFE-EXT2-' . $stamp
    );
    $reactivatedAuth = lifecycle_auth($wslDistro, $subscriberCode, $newPassword, 'Access-Accept');
    lifecycle_check(
        $checks,
        'payment_after_expiry_reactivation',
        $reactivated && $reactivatedAuth['ok'],
        ['activation_ms' => (int) round((microtime(true) - $reactivateStarted) * 1000)]
    );
} catch (Throwable $exception) {
    $errors[] = $exception->getMessage();
} finally {
    if (!$keep && $accountId > 0) {
        try {
            app_ip_release_customer_assignment($db, $accountId, $subscriberCode, 0, 'WSL lifecycle smoke cleanup');
            foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
                $statement = $db->prepare("DELETE FROM {$table} WHERE username = :username");
                $statement->execute(['username' => $subscriberCode]);
            }
            $db->prepare('DELETE FROM radacct WHERE username = :username OR acctsessionid = :session_id')->execute([
                'username' => $subscriberCode,
                'session_id' => $sessionId,
            ]);
            $db->prepare('DELETE FROM radius_session_actions WHERE username = :username')->execute(['username' => $subscriberCode]);
            $db->prepare('DELETE FROM billing_payments WHERE account_id = :id OR subscriber_code = :code')->execute([
                'id' => $accountId,
                'code' => $subscriberCode,
            ]);
            $db->prepare('DELETE FROM billing_radius_services WHERE account_id = :id')->execute(['id' => $accountId]);
            $db->prepare('DELETE FROM ip_assignment_history WHERE customer_id = :id')->execute(['id' => $accountId]);
            $db->prepare('DELETE FROM customer_ip_assignments WHERE customer_id = :id')->execute(['id' => $accountId]);
            if ($assignedIp !== '') {
                $db->prepare(
                    'UPDATE ip_addresses
                     SET status = "free", customer_id = NULL, service_username = NULL,
                         assigned_at = NULL, released_at = NOW()
                     WHERE ip_address = :ip AND customer_id = :id'
                )->execute(['ip' => $assignedIp, 'id' => $accountId]);
            }
            $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
        } catch (Throwable $cleanupException) {
            $errors[] = 'cleanup:' . $cleanupException->getMessage();
        }
    }
    if (!$keep && $tariffIds !== []) {
        $placeholders = implode(',', array_fill(0, count($tariffIds), '?'));
        $db->prepare('DELETE FROM billing_tariffs WHERE id IN (' . $placeholders . ')')->execute($tariffIds);
    }
    if (!$keep && $enginePoolId > 0) {
        $db->prepare('DELETE FROM ip_pools WHERE id = :id')->execute(['id' => $enginePoolId]);
    }
    if (!$keep && $billingPoolId > 0) {
        $db->prepare('DELETE FROM billing_ip_pools WHERE id = :id')->execute(['id' => $billingPoolId]);
    }
}

$failedChecks = array_keys(array_filter($checks, static fn (array $check): bool => empty($check['ok'])));
$ok = $errors === [] && $failedChecks === [] && count($checks) >= 16;
$report = [
    'status' => $ok ? 'ok' : 'failed',
    'run_id' => $stamp,
    'wsl_distro' => $wslDistro,
    'subscriber_code' => $subscriberCode,
    'checks_total' => count($checks),
    'checks_passed' => count(array_filter($checks, static fn (array $check): bool => !empty($check['ok']))),
    'checks' => $checks,
    'errors' => $errors,
    'cleanup' => !$keep,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 3);
