<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$suffix = date('YmdHis');
$subscriberCode = 'SMOKE-IPOE-' . $suffix;
$login = strtolower($subscriberCode);
$tariffName = 'Smoke IPoE Tariff ' . $suffix;
$radiusGroup = 'SMOKE_IPOE_' . $suffix;
$poolName = 'Smoke IPoE Pool ' . $suffix;
$accountId = 0;
$tariffId = 0;
$poolId = 0;

try {
    $tariffInsert = $db->prepare(
        'INSERT INTO billing_tariffs (
            name, service_type, download_mbps, upload_mbps, monthly_fee, radius_group, status, metadata_json
         ) VALUES (
            :name, "ipoe", 100, 25, 25.00, :radius_group, "active", :metadata_json
         )'
    );
    $tariffInsert->execute([
        'name' => $tariffName,
        'radius_group' => $radiusGroup,
        'metadata_json' => app_db_json_encode([
            'radius_reply_attributes' => [
                ['attribute' => 'Acct-Interim-Interval', 'op' => '=', 'value' => '600'],
            ],
        ]),
    ]);
    $tariffId = (int) $db->lastInsertId();

    $poolInsert = $db->prepare(
        'INSERT INTO billing_ip_pools (
            name, service_type, pool_type, range_start, range_end, gateway, netmask, status
         ) VALUES (
            :name, "ipoe", "dynamic", "10.250.110.10", "10.250.110.50", "10.250.110.1", "255.255.255.0", "active"
         )'
    );
    $poolInsert->execute(['name' => $poolName]);
    $poolId = (int) $db->lastInsertId();

    $payload = [
        'billing' => [
            'service_type' => 'ipoe',
            'tariff' => $tariffName,
            'pool' => $poolName,
            'status' => 'Aktiv',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+31 days')),
        ],
        'internet_services' => [
            [
                'service_type' => 'ipoe',
                'username' => $subscriberCode,
                'password' => '',
                'tariff' => $tariffName,
                'pool' => $poolName,
                'status' => 'active',
                'expires_at' => date('Y-m-d H:i:s', strtotime('+31 days')),
            ],
        ],
    ];

    $accountInsert = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, phone, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", :full_name, :subscriber_code, :phone, :plan, "Aktiv", 1, :payload_json
         )'
    );
    $accountInsert->execute([
        'login' => $login,
        'password_hash' => password_hash('SmokeIpoe123', PASSWORD_DEFAULT),
        'full_name' => 'Smoke IPoE Customer',
        'subscriber_code' => $subscriberCode,
        'phone' => '+994000000000',
        'plan' => $tariffName,
        'payload_json' => app_db_json_encode($payload),
    ]);
    $accountId = (int) $db->lastInsertId();

    $account = app_db_account_by_id($accountId);
    if (!is_array($account) || !app_billing_sync_radius_account($account)) {
        fwrite(STDERR, "radius_sync_failed\n");
        exit(3);
    }

    $radcheckStmt = $db->prepare('SELECT attribute, op, value FROM radcheck WHERE username = :username ORDER BY id');
    $radcheckStmt->execute(['username' => $subscriberCode]);
    $radcheck = $radcheckStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $radreplyStmt = $db->prepare('SELECT attribute, op, value FROM radreply WHERE username = :username ORDER BY id');
    $radreplyStmt->execute(['username' => $subscriberCode]);
    $radreply = $radreplyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $checkIndex = [];
    foreach ($radcheck as $row) {
        $checkIndex[(string) ($row['attribute'] ?? '')] = (string) ($row['value'] ?? '');
    }
    $replyIndex = [];
    foreach ($radreply as $row) {
        $replyIndex[] = [
            'attribute' => (string) ($row['attribute'] ?? ''),
            'op' => (string) ($row['op'] ?? ''),
            'value' => (string) ($row['value'] ?? ''),
        ];
    }

    foreach (['Cleartext-Password', 'Simultaneous-Use', 'Expiration'] as $attribute) {
        if (!array_key_exists($attribute, $checkIndex) || trim($checkIndex[$attribute]) === '') {
            fwrite(STDERR, 'missing_radcheck:' . $attribute . "\n");
            exit(4);
        }
    }

    $hasPool = false;
    $hasCiscoPool = false;
    $hasPppProtocol = false;
    $hasInterim = false;
    foreach ($replyIndex as $row) {
        if ($row['attribute'] === 'Framed-Pool' && $row['value'] === $poolName) {
            $hasPool = true;
        }
        if ($row['attribute'] === 'Cisco-AVPair' && str_contains($row['value'], 'ip:addr-pool=' . $poolName)) {
            $hasCiscoPool = true;
        }
        if ($row['attribute'] === 'Framed-Protocol' && strtoupper($row['value']) === 'PPP') {
            $hasPppProtocol = true;
        }
        if ($row['attribute'] === 'Acct-Interim-Interval' && $row['value'] === '600') {
            $hasInterim = true;
        }
    }

    if (!$hasPool) {
        fwrite(STDERR, "missing_radreply:Framed-Pool\n");
        exit(5);
    }
    if (!$hasCiscoPool) {
        fwrite(STDERR, "missing_radreply:Cisco-AVPair\n");
        exit(6);
    }
    if ($hasPppProtocol) {
        fwrite(STDERR, "unexpected_radreply:Framed-Protocol\n");
        exit(7);
    }
    if (!$hasInterim) {
        fwrite(STDERR, "missing_radreply:Acct-Interim-Interval\n");
        exit(8);
    }

    echo json_encode([
        'status' => 'ok',
        'subscriber_code' => $subscriberCode,
        'radcheck' => $radcheck,
        'radreply' => $radreply,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $username = $subscriberCode;
    foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
        $stmt = $db->prepare("DELETE FROM {$table} WHERE username = :username");
        $stmt->execute(['username' => $username]);
    }
    if ($accountId > 0) {
        $db->prepare('DELETE FROM billing_radius_services WHERE account_id = :account_id')->execute(['account_id' => $accountId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
    if ($poolId > 0) {
        $db->prepare('DELETE FROM billing_ip_pools WHERE id = :id')->execute(['id' => $poolId]);
    }
    if ($tariffId > 0) {
        $db->prepare('DELETE FROM billing_tariffs WHERE id = :id')->execute(['id' => $tariffId]);
    }
}
