<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/legacy-customer-secret-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$existingKey = getenv('LEGACY_IMPORT_ENCRYPTION_KEY');
if (!is_string($existingKey) || trim($existingKey) === '') {
    putenv('LEGACY_IMPORT_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
}

$login = 'vault-smoke-' . bin2hex(random_bytes(6));
$accountId = 0;
$secret = 'smoke-' . bin2hex(random_bytes(16));
$checks = [];

try {
    $insert = $db->prepare(
        'INSERT INTO portal_accounts (
            login, subscriber_code, password_hash, role, account_type, employee_role,
            full_name, status, is_active, payload_json
         ) VALUES (
            :login, :subscriber_code, :password_hash, "customer", "customer", "",
            "Vault Smoke", "Aktiv", 1, :payload_json
         )'
    );
    $insert->execute([
        'login' => $login,
        'subscriber_code' => $login,
        'password_hash' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT),
        'payload_json' => app_db_json_encode([
            'billing' => [
                'service_type' => 'pppoe',
                'pppoe_username' => $login,
                'status' => 'Aktiv',
            ],
            'internet_services' => [[
                'service_type' => 'pppoe',
                'username' => $login,
                'status' => 'active',
            ]],
        ]),
    ]);
    $accountId = (int) $db->lastInsertId();

    app_legacy_customer_secret_store($db, $accountId, 'pppoe_password', $secret);
    $row = $db->prepare(
        'SELECT ciphertext_b64, nonce_b64, auth_tag_b64
         FROM legacy_customer_secret_vault
         WHERE account_id = :account_id AND secret_type = "pppoe_password"'
    );
    $row->execute(['account_id' => $accountId]);
    $stored = $row->fetch(PDO::FETCH_ASSOC);
    $checks['row_written'] = is_array($stored);
    $checks['plaintext_not_stored'] = is_array($stored)
        && !str_contains(implode('|', array_map('strval', $stored)), $secret);
    $checks['round_trip'] = hash_equals(
        $secret,
        (string) app_legacy_customer_secret_reveal($db, $accountId, 'pppoe_password')
    );
    $checks['unique_per_account_type'] = (int) $db->query(
        'SELECT COUNT(*) FROM legacy_customer_secret_vault WHERE account_id = ' . $accountId
    )->fetchColumn() === 1;
    $account = app_db_account_by_id($accountId);
    $checks['radius_sync_uses_vault'] = is_array($account)
        && app_billing_sync_radius_account($account, null, $db);
    $radius = $db->prepare(
        'SELECT value FROM radcheck
         WHERE username = :username AND attribute = "Cleartext-Password"
         ORDER BY id DESC LIMIT 1'
    );
    $radius->execute(['username' => $login]);
    $checks['radius_password_matches'] = hash_equals($secret, (string) $radius->fetchColumn());
} finally {
    if ($accountId > 0) {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            $db->prepare("DELETE FROM {$table} WHERE username = :username")->execute(['username' => $login]);
        }
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}

$ok = !in_array(false, $checks, true);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 4);
