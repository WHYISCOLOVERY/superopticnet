<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/audit-log-lib.php';

function app_integration_api_table_exists(PDO $db): bool
{
    static $cache = [];
    $key = spl_object_id($db);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => 'integration_api_clients']);
    return $cache[$key] = (int) $statement->fetchColumn() > 0;
}

/** @return list<string> */
function app_integration_api_allowed_scopes(): array
{
    return ['customer:read', 'payment:write', 'payment:status'];
}

function app_integration_api_provider_slug(string $value): string
{
    $value = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim($value)));
    return match ($value) {
        'up', 'united', 'unitedpayments' => 'unitedpayment',
        'emanatterminal' => 'emanat',
        default => substr($value, 0, 64),
    };
}

/** @return list<string> */
function app_integration_api_normalize_scopes(array $scopes): array
{
    $allowed = app_integration_api_allowed_scopes();
    $normalized = [];
    foreach ($scopes as $scope) {
        $scope = strtolower(trim((string) $scope));
        if (in_array($scope, $allowed, true)) {
            $normalized[$scope] = true;
        }
    }
    return array_keys($normalized);
}

/** @return list<string> */
function app_integration_api_normalize_allowed_ips(string|array $values): array
{
    $parts = is_array($values) ? $values : preg_split('/[\s,;]+/', trim($values));
    $normalized = [];
    foreach ($parts ?: [] as $part) {
        $value = trim((string) $part);
        if ($value === '') {
            continue;
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            $normalized[$value] = true;
            continue;
        }
        if (preg_match('/^([^\/]+)\/(\d{1,3})$/', $value, $match) !== 1
            || filter_var($match[1], FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('allowed_ip_invalid');
        }
        $maximum = str_contains($match[1], ':') ? 128 : 32;
        if ((int) $match[2] < 0 || (int) $match[2] > $maximum) {
            throw new InvalidArgumentException('allowed_ip_invalid');
        }
        $normalized[$match[1] . '/' . (int) $match[2]] = true;
    }
    return array_keys($normalized);
}

function app_integration_api_ip_matches(string $ip, string $rule): bool
{
    if (!str_contains($rule, '/')) {
        return hash_equals($rule, $ip);
    }

    [$network, $prefixText] = explode('/', $rule, 2);
    $ipBinary = @inet_pton($ip);
    $networkBinary = @inet_pton($network);
    if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
        return false;
    }

    $prefix = (int) $prefixText;
    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
        return false;
    }
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ipBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
}

function app_integration_api_ip_allowed(string $ip, array $allowedIps): bool
{
    if ($allowedIps === []) {
        return true;
    }
    foreach ($allowedIps as $rule) {
        if (app_integration_api_ip_matches($ip, (string) $rule)) {
            return true;
        }
    }
    return false;
}

function app_integration_api_decode_list(mixed $value): array
{
    if (is_array($value)) {
        return array_values(array_map('strval', $value));
    }
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
}

function app_integration_api_secret_hash(string $secret): string
{
    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash($secret, $algorithm);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('secret_hash_failed');
    }
    return $hash;
}

function app_integration_api_generate_material(string $providerSlug): array
{
    $providerPart = substr((string) preg_replace('/[^a-z0-9]/', '', $providerSlug), 0, 16);
    $keyId = 'sop_live_' . ($providerPart !== '' ? $providerPart : 'client') . '_' . bin2hex(random_bytes(8));
    $secret = 'sop_secret_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    return ['key_id' => $keyId, 'secret' => $secret];
}

function app_integration_api_client_by_key_id(PDO $db, string $keyId): ?array
{
    if (!app_integration_api_table_exists($db) || $keyId === '') {
        return null;
    }
    $statement = $db->prepare('SELECT * FROM integration_api_clients WHERE key_id = :key_id LIMIT 1');
    $statement->execute(['key_id' => $keyId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return list<array<string,mixed>> */
function app_integration_api_clients(PDO $db): array
{
    if (!app_integration_api_table_exists($db)) {
        return [];
    }
    $rows = $db->query(
        'SELECT id, provider_slug, display_name, key_id, scopes_json, allowed_ips_json,
                status, expires_at, last_used_at, last_used_ip, created_by_account_id,
                rotated_from_id, created_at, revoked_at, revoke_reason
           FROM integration_api_clients
          ORDER BY id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function app_integration_api_insert(
    PDO $db,
    array $actor,
    string $providerSlug,
    string $displayName,
    array $scopes,
    array $allowedIps,
    ?string $expiresAt,
    ?int $rotatedFromId = null
): array {
    $material = app_integration_api_generate_material($providerSlug);
    $statement = $db->prepare(
        'INSERT INTO integration_api_clients (
            provider_slug, display_name, key_id, secret_hash, scopes_json, allowed_ips_json,
            expires_at, created_by_account_id, rotated_from_id
         ) VALUES (
            :provider_slug, :display_name, :key_id, :secret_hash, :scopes_json, :allowed_ips_json,
            :expires_at, :created_by_account_id, :rotated_from_id
         )'
    );
    $statement->execute([
        'provider_slug' => $providerSlug,
        'display_name' => $displayName,
        'key_id' => $material['key_id'],
        'secret_hash' => app_integration_api_secret_hash($material['secret']),
        'scopes_json' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
        'allowed_ips_json' => $allowedIps === [] ? null : json_encode($allowedIps, JSON_UNESCAPED_SLASHES),
        'expires_at' => $expiresAt,
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'rotated_from_id' => $rotatedFromId,
    ]);
    return [
        'id' => (int) $db->lastInsertId(),
        'provider_slug' => $providerSlug,
        'display_name' => $displayName,
        'key_id' => $material['key_id'],
        'secret' => $material['secret'],
        'scopes' => $scopes,
        'allowed_ips' => $allowedIps,
        'expires_at' => $expiresAt,
    ];
}

function app_integration_api_create(PDO $db, array $actor, array $input): array
{
    if (!app_portal_is_super_admin($actor)) {
        throw new RuntimeException('super_admin_required');
    }
    if (!app_integration_api_table_exists($db)) {
        throw new RuntimeException('integration_api_migration_required');
    }

    $providerSlug = app_integration_api_provider_slug((string) ($input['provider_slug'] ?? ''));
    $displayName = trim(clean_text((string) ($input['display_name'] ?? '')));
    $scopes = app_integration_api_normalize_scopes((array) ($input['scopes'] ?? []));
    $allowedIps = app_integration_api_normalize_allowed_ips($input['allowed_ips'] ?? []);
    $expiresAt = trim((string) ($input['expires_at'] ?? ''));
    if ($providerSlug === '' || strlen($providerSlug) < 2) {
        throw new InvalidArgumentException('provider_slug_required');
    }
    if ($displayName === '' || mb_strlen($displayName) < 2) {
        throw new InvalidArgumentException('display_name_required');
    }
    if ($scopes === []) {
        throw new InvalidArgumentException('scope_required');
    }
    if ($expiresAt !== '') {
        $timestamp = strtotime($expiresAt);
        if ($timestamp === false || $timestamp <= time()) {
            throw new InvalidArgumentException('expiry_invalid');
        }
        $expiresAt = date('Y-m-d H:i:s', $timestamp);
    } else {
        $expiresAt = null;
    }

    $db->beginTransaction();
    try {
        $created = app_integration_api_insert(
            $db,
            $actor,
            $providerSlug,
            mb_substr($displayName, 0, 191),
            $scopes,
            $allowedIps,
            $expiresAt
        );
        app_audit_record($db, [
            'module_key' => 'system',
            'operation_class' => 'credential_management',
            'action_type' => 'integration_api_client_created',
            'entity_type' => 'integration_api_client',
            'entity_id' => (string) $created['id'],
            'status' => 'executed',
            'actor_account_id' => (int) ($actor['id'] ?? 0),
            'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? ''),
            'summary' => 'Integration API client created',
            'after' => [
                'provider_slug' => $providerSlug,
                'display_name' => $displayName,
                'key_id' => $created['key_id'],
                'scopes' => $scopes,
                'allowed_ips' => $allowedIps,
                'expires_at' => $expiresAt,
            ],
            'sensitivity' => 'restricted',
        ]);
        $db->commit();
        return $created;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function app_integration_api_revoke(PDO $db, array $actor, int $clientId, string $reason): void
{
    if (!app_portal_is_super_admin($actor)) {
        throw new RuntimeException('super_admin_required');
    }
    $reason = trim(clean_text($reason));
    if ($reason === '') {
        throw new InvalidArgumentException('revoke_reason_required');
    }

    $db->beginTransaction();
    try {
        $select = $db->prepare('SELECT * FROM integration_api_clients WHERE id = :id FOR UPDATE');
        $select->execute(['id' => $clientId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('integration_api_client_not_found');
        }
        if ((string) $row['status'] === 'active') {
            $update = $db->prepare(
                "UPDATE integration_api_clients
                    SET status = 'revoked', revoked_at = NOW(), revoked_by_account_id = :actor_id,
                        revoke_reason = :reason
                  WHERE id = :id"
            );
            $update->execute([
                'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
                'reason' => mb_substr($reason, 0, 255),
                'id' => $clientId,
            ]);
        }
        app_audit_record($db, [
            'module_key' => 'system',
            'operation_class' => 'credential_management',
            'action_type' => 'integration_api_client_revoked',
            'entity_type' => 'integration_api_client',
            'entity_id' => (string) $clientId,
            'status' => 'executed',
            'actor_account_id' => (int) ($actor['id'] ?? 0),
            'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? ''),
            'summary' => 'Integration API client revoked',
            'reason' => $reason,
            'before' => ['status' => (string) $row['status'], 'key_id' => (string) $row['key_id']],
            'after' => ['status' => 'revoked', 'key_id' => (string) $row['key_id']],
            'sensitivity' => 'restricted',
        ]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function app_integration_api_rotate(PDO $db, array $actor, int $clientId): array
{
    if (!app_portal_is_super_admin($actor)) {
        throw new RuntimeException('super_admin_required');
    }

    $db->beginTransaction();
    try {
        $select = $db->prepare('SELECT * FROM integration_api_clients WHERE id = :id FOR UPDATE');
        $select->execute(['id' => $clientId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (string) $row['status'] !== 'active') {
            throw new RuntimeException('active_integration_api_client_not_found');
        }
        $scopes = app_integration_api_decode_list($row['scopes_json'] ?? null);
        $allowedIps = app_integration_api_decode_list($row['allowed_ips_json'] ?? null);
        $created = app_integration_api_insert(
            $db,
            $actor,
            (string) $row['provider_slug'],
            (string) $row['display_name'],
            $scopes,
            $allowedIps,
            $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            $clientId
        );
        $update = $db->prepare(
            "UPDATE integration_api_clients
                SET status = 'revoked', revoked_at = NOW(), revoked_by_account_id = :actor_id,
                    revoke_reason = 'Rotated'
              WHERE id = :id"
        );
        $update->execute(['actor_id' => (int) ($actor['id'] ?? 0) ?: null, 'id' => $clientId]);
        app_audit_record($db, [
            'module_key' => 'system',
            'operation_class' => 'credential_management',
            'action_type' => 'integration_api_client_rotated',
            'entity_type' => 'integration_api_client',
            'entity_id' => (string) $created['id'],
            'status' => 'executed',
            'actor_account_id' => (int) ($actor['id'] ?? 0),
            'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? ''),
            'summary' => 'Integration API client rotated',
            'before' => ['id' => $clientId, 'key_id' => (string) $row['key_id'], 'status' => 'active'],
            'after' => ['id' => $created['id'], 'key_id' => $created['key_id'], 'status' => 'active'],
            'sensitivity' => 'restricted',
        ]);
        $db->commit();
        return $created;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function app_integration_api_parse_bearer(string $token): ?array
{
    $separator = strpos($token, '.');
    if ($separator === false) {
        return null;
    }
    $keyId = substr($token, 0, $separator);
    $secret = substr($token, $separator + 1);
    if (!str_starts_with($keyId, 'sop_live_') || !str_starts_with($secret, 'sop_secret_')) {
        return null;
    }
    return ['key_id' => $keyId, 'secret' => $secret];
}

function app_integration_api_verify_bearer(
    PDO $db,
    string $token,
    string $clientIp,
    string $requiredScope = '',
    string $providerHint = ''
): array {
    $parsed = app_integration_api_parse_bearer($token);
    if ($parsed === null) {
        return ['ok' => false, 'matched' => false, 'code' => 'not_registry_token'];
    }
    $row = app_integration_api_client_by_key_id($db, (string) $parsed['key_id']);
    if (!is_array($row)) {
        return ['ok' => false, 'matched' => true, 'code' => 'unknown_key_id'];
    }
    if ((string) ($row['status'] ?? '') !== 'active') {
        return ['ok' => false, 'matched' => true, 'code' => 'credential_revoked'];
    }
    if ((string) ($row['expires_at'] ?? '') !== '' && strtotime((string) $row['expires_at']) <= time()) {
        return ['ok' => false, 'matched' => true, 'code' => 'credential_expired'];
    }
    if (!password_verify((string) $parsed['secret'], (string) ($row['secret_hash'] ?? ''))) {
        return ['ok' => false, 'matched' => true, 'code' => 'invalid_secret'];
    }

    $provider = app_integration_api_provider_slug((string) ($row['provider_slug'] ?? ''));
    $providerHint = app_integration_api_provider_slug($providerHint);
    if ($providerHint !== '' && !hash_equals($provider, $providerHint)) {
        return ['ok' => false, 'matched' => true, 'code' => 'provider_credential_mismatch'];
    }
    $scopes = app_integration_api_decode_list($row['scopes_json'] ?? null);
    if ($requiredScope !== '' && !in_array($requiredScope, $scopes, true)) {
        return ['ok' => false, 'matched' => true, 'code' => 'scope_denied'];
    }
    $allowedIps = app_integration_api_decode_list($row['allowed_ips_json'] ?? null);
    if (!app_integration_api_ip_allowed($clientIp, $allowedIps)) {
        return ['ok' => false, 'matched' => true, 'code' => 'source_ip_not_allowed'];
    }

    return [
        'ok' => true,
        'matched' => true,
        'client_id' => (int) $row['id'],
        'key_id' => (string) $row['key_id'],
        'provider' => $provider,
        'secret' => (string) $parsed['secret'],
        'scopes' => $scopes,
        'allowed_ips' => $allowedIps,
    ];
}

function app_integration_api_mark_used(PDO $db, int $clientId, string $clientIp): void
{
    try {
        $statement = $db->prepare(
            'UPDATE integration_api_clients
                SET last_used_at = NOW(), last_used_ip = :last_used_ip
              WHERE id = :id'
        );
        $statement->execute(['last_used_ip' => substr($clientIp, 0, 45), 'id' => $clientId]);
    } catch (Throwable) {
        // Authentication must not fail solely because usage telemetry could not be updated.
    }
}
