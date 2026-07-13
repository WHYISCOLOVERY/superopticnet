<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function app_provider_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function app_provider_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $normalized = [];
            foreach ($headers as $key => $value) {
                $normalized[strtolower((string) $key)] = (string) $value;
            }
            return $normalized;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with((string) $key, 'HTTP_')) {
            continue;
        }
        $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
        $headers[$name] = (string) $value;
    }

    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
    }

    return $headers;
}

function app_provider_bearer_token(array $headers): string
{
    $authorization = trim((string) ($headers['authorization'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1) {
        return trim((string) $match[1]);
    }

    return trim((string) ($headers['x-provider-token'] ?? ''));
}

function app_provider_bool_setting(string $key, bool $default = false): bool
{
    $value = app_setting($key, $default ? '1' : '0');
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function app_provider_is_production(): bool
{
    return in_array(strtolower(trim(app_config('app_env', 'local'))), ['prod', 'production'], true);
}

function app_provider_requires_hmac_for_request(): bool
{
    $path = strtolower((string) ($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
    $moneyMoving = str_contains($path, '/payment') || str_contains($path, 'insert-payment');
    return app_provider_bool_setting('provider_api_require_hmac', false)
        || ($moneyMoving && app_provider_is_production());
}

function app_provider_authenticate(string $rawBody = ''): array
{
    $expectedToken = app_config('provider_api_token');
    if ($expectedToken === '') {
        app_record_security_event([
            'event' => 'provider_api_not_configured',
            'status' => 'warning',
            'meta' => ['path' => (string) ($_SERVER['REQUEST_URI'] ?? '')],
        ]);
        app_provider_json_response(['success' => false, 'code' => 'provider_api_not_configured'], 503);
    }

    $headers = app_provider_request_headers();
    $token = app_provider_bearer_token($headers);
    if ($token === '' && !app_provider_is_production() && app_provider_bool_setting('provider_api_allow_query_token', false)) {
        $token = trim((string) ($_GET['token'] ?? ''));
    }
    if ($token === '' || !hash_equals($expectedToken, $token)) {
        app_record_security_event([
            'event' => 'provider_api_auth_failed',
            'status' => 'warning',
            'meta' => [
                'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
                'ip' => app_client_ip(),
            ],
        ]);
        app_provider_json_response(['success' => false, 'code' => 'unauthorized'], 401);
    }

    $hmacSecret = app_config('provider_api_hmac_secret');
    $hmacRequired = app_provider_requires_hmac_for_request();
    if ($hmacRequired && $hmacSecret === '') {
        app_record_security_event([
            'event' => 'provider_api_hmac_not_configured',
            'status' => 'warning',
            'meta' => ['path' => (string) ($_SERVER['REQUEST_URI'] ?? '')],
        ]);
        app_provider_json_response(['success' => false, 'code' => 'provider_hmac_not_configured'], 503);
    }
    if ($hmacSecret !== '') {
        $timestamp = trim((string) ($headers['x-superoptic-timestamp'] ?? ''));
        $signature = strtolower(trim((string) ($headers['x-superoptic-signature'] ?? '')));
        $timestampValue = ctype_digit($timestamp) ? (int) $timestamp : 0;
        if ($timestampValue <= 0 || abs(time() - $timestampValue) > 300) {
            app_provider_json_response(['success' => false, 'code' => 'invalid_timestamp'], 401);
        }

        $expectedSignature = hash_hmac('sha256', $timestamp . "\n" . $rawBody, $hmacSecret);
        if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
            app_record_security_event([
                'event' => 'provider_api_hmac_failed',
                'status' => 'warning',
                'meta' => [
                    'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
                    'ip' => app_client_ip(),
                ],
            ]);
            app_provider_json_response(['success' => false, 'code' => 'invalid_signature'], 401);
        }
    }

    return [
        'provider' => clean_text((string) ($headers['x-provider-name'] ?? $_GET['provider'] ?? 'provider')),
        'ip' => app_client_ip(),
    ];
}

function app_provider_read_json_body(string $rawBody): array
{
    $contentType = strtolower((string) (app_provider_request_headers()['content-type'] ?? ''));
    if ($rawBody === '') {
        return $_POST;
    }

    if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
        $parsed = [];
        parse_str($rawBody, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    if (str_contains($contentType, 'multipart/form-data')) {
        return $_POST;
    }

    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        app_provider_json_response(['success' => false, 'code' => 'invalid_json'], 400);
    }

    return $decoded;
}

function app_provider_customer_by_code(string $code): ?array
{
    $db = app_db();
    $code = clean_login_identifier($code);
    if (!$db instanceof PDO || $code === '') {
        return null;
    }

    $statement = $db->prepare(
        'SELECT * FROM portal_accounts
         WHERE role = "customer"
           AND (
                subscriber_code = :code
                OR contract_no = :code
                OR login = :code
           )
         LIMIT 1'
    );
    $statement->execute(['code' => $code]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function app_provider_customer_summary(array $account): array
{
    $payload = app_db_account_payload($account);
    $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $statements = is_array($payload['statements'] ?? null) ? $payload['statements'] : [];
    $lastStatement = is_array($statements[0] ?? null) ? $statements[0] : [];
    $balanceText = (string) ($billing['balance'] ?? $profile['balance'] ?? $lastStatement['balance'] ?? '0 AZN');
    $expiresAt = (string) ($billing['expires_at'] ?? '');

    return [
        'customer_code' => (string) ($account['subscriber_code'] ?? ''),
        'contract_no' => (string) ($account['contract_no'] ?? ''),
        'full_name' => (string) ($account['full_name'] ?? ''),
        'status' => (string) ($account['status'] ?? $billing['status'] ?? ''),
        'is_active' => (bool) ($account['is_active'] ?? false),
        'package' => (string) ($account['plan'] ?? $billing['tariff'] ?? ''),
        'balance' => $balanceText,
        'balance_amount' => app_billing_parse_money_amount($balanceText),
        'remaining_days' => app_billing_days_left($expiresAt),
        'remaining_days_text' => $expiresAt !== '' ? app_billing_remaining_days_text($expiresAt) : (string) ($billing['remaining_days_raw'] ?? ''),
        'expires_at' => $expiresAt,
    ];
}

function app_provider_external_id(string $provider, string $transactionId): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', trim($provider))) . ':' . trim($transactionId);
}

function app_provider_is_mpay(string $provider): bool
{
    $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $provider));
    return $normalized === 'mpay' || $normalized === 'mpayterminal';
}

function app_provider_payment_by_external_id(string $externalId): ?array
{
    $externalId = trim($externalId);
    $db = app_db();
    if (!$db instanceof PDO || $externalId === '') {
        return null;
    }

    $statement = $db->prepare('SELECT * FROM billing_payments WHERE external_id = :external_id LIMIT 1');
    $statement->execute(['external_id' => $externalId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function app_up_provider_response(string $statusCode, string $statusDesc, array $extra = [], int $httpStatus = 200): never
{
    app_provider_json_response(array_merge([
        'StatusCode' => $statusCode,
        'StatusDesc' => $statusDesc,
    ], $extra), $httpStatus);
}

function app_up_provider_approved(array $extra = []): never
{
    app_up_provider_response('00', 'APPROVED', $extra);
}

function app_up_provider_not_found(string $description = 'No Records Found'): never
{
    app_up_provider_response('E000', $description);
}

function app_up_provider_invalid(string $description = 'Invalid transaction, try again please'): never
{
    app_up_provider_response('E202', $description);
}

function app_up_provider_duplicate(string $description = 'Duplicate transaction received'): never
{
    app_up_provider_response('E209', $description);
}

function app_up_provider_name_from_request(array $payload = []): string
{
    $headers = app_provider_request_headers();
    $provider = clean_text((string) (
        $payload['provider']
        ?? $headers['x-provider-name']
        ?? $_GET['provider']
        ?? 'unitedpayment'
    ));

    return $provider !== '' ? $provider : 'unitedpayment';
}

function app_up_provider_balance_value(array $summary): string
{
    $amount = (float) ($summary['balance_amount'] ?? 0);
    return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
}

function app_up_provider_member_payload(array $account): array
{
    $summary = app_provider_customer_summary($account);
    $fullName = trim((string) ($summary['full_name'] ?? ''));
    $parts = preg_split('/\s+/', $fullName, 2) ?: [];

    return [
        'memberInfo' => $parts[0] ?? $fullName,
        'additionalInfo' => $parts[1] ?? '',
        'description' => (string) ($summary['customer_code'] ?? ''),
        'balance' => app_up_provider_balance_value($summary),
        'customer' => $summary,
    ];
}
