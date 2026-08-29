<?php
declare(strict_types=1);

/**
 * AI-crew service-token → employee actor resolver.
 *
 * A department's API token maps (via SHA-256 hash) to an existing employee
 * (portal_accounts) service account. The agent authenticates AS that employee,
 * so every read/action is attributed to that department account for audit and
 * scoped to the modules its POSITION grants (least privilege). Token-only:
 * these accounts have a random password hash — no human can log in.
 */

require_once __DIR__ . '/config.php';

/** Resolve a bearer token to a portal session-user actor, or null. */
function app_crew_service_actor(PDO $db, string $token): ?array
{
    $token = trim($token);
    if (strlen($token) < 24) {
        return null;
    }
    try {
        $stmt = $db->prepare(
            'SELECT account_id FROM crew_service_tokens
              WHERE token_hash = :h AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['h' => hash('sha256', $token)]);
        $accountId = (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return null;
    }
    if ($accountId <= 0) {
        return null;
    }
    $account = function_exists('app_db_account_by_id') ? app_db_account_by_id($accountId) : null;
    if (!is_array($account) || (string) ($account['role'] ?? '') !== 'employee') {
        return null;
    }
    $actor = function_exists('app_db_user_session_payload') ? app_db_user_session_payload($account) : null;
    if (!is_array($actor)) {
        return null;
    }
    $actor['crew_service'] = true;
    try {
        $db->prepare('UPDATE crew_service_tokens SET last_used_at = NOW() WHERE token_hash = :h')
            ->execute(['h' => hash('sha256', $token)]);
    } catch (Throwable) {
    }
    return $actor;
}

/** Read the bearer token from the request (with Apache header fallback). */
function app_crew_service_bearer(): string
{
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $auth = (string) $v;
                break;
            }
        }
    }
    return preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1 ? trim($m[1]) : '';
}

/** Register (or rotate) a token for an employee. Returns the plaintext token ONCE. */
function app_crew_service_issue_token(PDO $db, int $accountId, string $department, string $label): string
{
    $token = bin2hex(random_bytes(24));
    $db->prepare(
        'INSERT INTO crew_service_tokens (token_hash, account_id, department, label, is_active, created_at)
         VALUES (:h, :aid, :dep, :lbl, 1, NOW())
         ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), is_active = 1'
    )->execute([
        'h' => hash('sha256', $token),
        'aid' => $accountId,
        'dep' => mb_substr($department, 0, 48),
        'lbl' => mb_substr($label, 0, 96),
    ]);
    return $token;
}
