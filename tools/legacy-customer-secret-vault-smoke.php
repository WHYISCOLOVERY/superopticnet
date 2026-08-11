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
            login, password_hash, role, account_type, employee_role, full_name, status, is_active
         ) VALUES (
            :login, :password_hash, "customer", "customer", "", "Vault Smoke", "Deaktiv", 0
         )'
    );
    $insert->execute([
        'login' => $login,
        'password_hash' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT),
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
} finally {
    if ($accountId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}

$ok = !in_array(false, $checks, true);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 4);
