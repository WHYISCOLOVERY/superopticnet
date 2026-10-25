<?php
declare(strict_types=1);

function app_legacy_customer_secret_key(): string
{
    $encoded = app_environment_secret('LEGACY_IMPORT_ENCRYPTION_KEY');
    if ($encoded === '') {
        throw new RuntimeException('LEGACY_IMPORT_ENCRYPTION_KEY is not configured.');
    }

    $key = base64_decode($encoded, true);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('LEGACY_IMPORT_ENCRYPTION_KEY must be a base64 encoded 32-byte key.');
    }

    return $key;
}

function app_legacy_customer_secret_aad(
    int $accountId,
    string $secretType,
    string $source,
    string $keyVersion
): string {
    return implode(':', [
        'superoptic',
        'legacy-customer-secret',
        $keyVersion,
        (string) $accountId,
        $secretType,
        $source,
    ]);
}

function app_legacy_customer_secret_store(
    PDO $db,
    int $accountId,
    string $secretType,
    string $plaintext,
    string $source = 'old_billing',
    string $keyVersion = 'v1'
): void {
    $secretType = trim($secretType);
    $source = trim($source);
    $keyVersion = trim($keyVersion);
    if ($accountId <= 0 || $secretType === '' || $plaintext === '') {
        return;
    }
    if (strlen($secretType) > 64 || strlen($source) > 64 || strlen($keyVersion) > 32) {
        throw new InvalidArgumentException('Legacy secret metadata is invalid.');
    }

    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        app_legacy_customer_secret_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        app_legacy_customer_secret_aad($accountId, $secretType, $source, $keyVersion),
        16
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('Customer secret encryption failed.');
    }

    $statement = $db->prepare(
        'INSERT INTO legacy_customer_secret_vault (
            account_id, secret_type, algorithm, key_version, ciphertext_b64,
            nonce_b64, auth_tag_b64, source
         ) VALUES (
            :account_id, :secret_type, "aes-256-gcm", :key_version, :ciphertext_b64,
            :nonce_b64, :auth_tag_b64, :source
         )
         ON DUPLICATE KEY UPDATE
            algorithm = VALUES(algorithm),
            key_version = VALUES(key_version),
            ciphertext_b64 = VALUES(ciphertext_b64),
            nonce_b64 = VALUES(nonce_b64),
            auth_tag_b64 = VALUES(auth_tag_b64),
            source = VALUES(source)'
    );
    $statement->execute([
        'account_id' => $accountId,
        'secret_type' => $secretType,
        'key_version' => $keyVersion,
        'ciphertext_b64' => base64_encode($ciphertext),
        'nonce_b64' => base64_encode($nonce),
        'auth_tag_b64' => base64_encode($tag),
        'source' => $source,
    ]);
}

function app_legacy_customer_secret_reveal(
    PDO $db,
    int $accountId,
    string $secretType
): ?string {
    if ($accountId <= 0 || trim($secretType) === '') {
        return null;
    }

    $statement = $db->prepare(
        'SELECT secret_type, algorithm, key_version, ciphertext_b64, nonce_b64, auth_tag_b64, source
         FROM legacy_customer_secret_vault
         WHERE account_id = :account_id AND secret_type = :secret_type
         LIMIT 1'
    );
    $statement->execute([
        'account_id' => $accountId,
        'secret_type' => trim($secretType),
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (string) ($row['algorithm'] ?? '') !== 'aes-256-gcm') {
        return null;
    }

    $ciphertext = base64_decode((string) ($row['ciphertext_b64'] ?? ''), true);
    $nonce = base64_decode((string) ($row['nonce_b64'] ?? ''), true);
    $tag = base64_decode((string) ($row['auth_tag_b64'] ?? ''), true);
    if (!is_string($ciphertext) || !is_string($nonce) || !is_string($tag)) {
        return null;
    }

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        app_legacy_customer_secret_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        app_legacy_customer_secret_aad(
            $accountId,
            (string) $row['secret_type'],
            (string) $row['source'],
            (string) $row['key_version']
        )
    );

    return is_string($plaintext) ? $plaintext : null;
}
