<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mgs-integration-lib.php';

/**
 * Attendance scanner API — kart-reader / QR skanerləri (VLAN 550, IP+API) sistemə
 * "kim gəldi / kim gecikdi" hadisələrini göndərir. İki cihaz üçün iki açar.
 * Ödəniş provider API pattern-inə uyğun: bearer token auth + JSON.
 */

/**
 * İki skaner açarını qaytarır; yoxdursa təhlükəsiz təsadüfi yaradıb saxlayır.
 * @return array{attendance_api_key_1:string, attendance_api_key_2:string}
 */
function app_attendance_api_key_pair(): array
{
    // Read/write directly from mgs_runtime_settings (app_setting uses a separate
    // file-based store and would not see keys written by app_mgs_set_runtime_setting).
    $db = app_db();
    $keys = [];
    foreach (['attendance_api_key_1', 'attendance_api_key_2'] as $slot) {
        $value = '';
        if ($db instanceof PDO) {
            try {
                $statement = $db->prepare('SELECT setting_value FROM mgs_runtime_settings WHERE setting_key = :key LIMIT 1');
                $statement->execute(['key' => $slot]);
                $value = trim((string) ($statement->fetchColumn() ?: ''));
            } catch (Throwable) {
                $value = '';
            }
        }
        if ($value === '' && function_exists('app_mgs_set_runtime_setting')) {
            try {
                $value = 'ATT-' . bin2hex(random_bytes(20));
                app_mgs_set_runtime_setting($slot, $value, ['id' => 0, 'full_name' => 'system']);
            } catch (Throwable) {
                $value = '';
            }
        }
        $keys[$slot] = $value;
    }
    return $keys;
}

function app_attendance_request_headers(): array
{
    $headers = [];
    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $key => $value) {
            $headers[strtolower((string) $key)] = (string) $value;
        }
    }
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with((string) $key, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = (string) $value;
        }
    }
    return $headers;
}

/**
 * Bearer / X-Api-Key başlığını iki açardan biri ilə tutuşdurur.
 * @return array{ok:bool, device_slot?:string}
 */
function app_attendance_api_authenticate(?array $headers = null): array
{
    $headers = $headers ?? app_attendance_request_headers();
    $token = '';
    $auth = (string) ($headers['authorization'] ?? '');
    if (stripos($auth, 'bearer ') === 0) {
        $token = trim(substr($auth, 7));
    }
    if ($token === '') {
        $token = trim((string) ($headers['x-api-key'] ?? ''));
    }
    if ($token === '') {
        return ['ok' => false];
    }
    foreach (app_attendance_api_key_pair() as $slot => $value) {
        if ($value !== '' && hash_equals($value, $token)) {
            return ['ok' => true, 'device_slot' => $slot];
        }
    }
    return ['ok' => false];
}

function app_attendance_normalize_timestamp(mixed $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return date('Y-m-d H:i:s');
    }
    if (ctype_digit($raw)) {
        // Unix epoch (saniyə).
        return date('Y-m-d H:i:s', (int) $raw);
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

/**
 * Skan hadisəsini işçiyə bağlayıb davamiyyət kimi yazır.
 * Qəbul edilən sahələr: employee_account_id | employee_code | card_id | username,
 * direction (in/out/auto), timestamp.
 */
function app_attendance_api_record(array $payload, string $deviceSlot): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return ['ok' => false, 'error' => 'db_unavailable'];
    }

    $accountId = (int) ($payload['employee_account_id'] ?? 0);
    $cardId = trim((string) ($payload['card_id'] ?? $payload['card_code'] ?? $payload['rfid'] ?? ''));
    $code = trim((string) ($payload['employee_code'] ?? $payload['username'] ?? $payload['login'] ?? ''));
    $account = null;
    if ($accountId > 0 && function_exists('app_db_account_by_id')) {
        $account = app_db_account_by_id($accountId);
    }
    // RFID/kart kodu ilə həll: davamiyyət profilində saxlanan cardCode-a görə işçini tap.
    if (!is_array($account) && $cardId !== '') {
        try {
            $statement = $db->prepare(
                'SELECT entity_id FROM mgs_operation_actions
                  WHERE action_type = "attendance_profile_save"
                    AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.cardCode")) = :card
                  ORDER BY id DESC LIMIT 1'
            );
            $statement->execute(['card' => $cardId]);
            $cardAccountId = (int) ($statement->fetchColumn() ?: 0);
            if ($cardAccountId > 0 && function_exists('app_db_account_by_id')) {
                $account = app_db_account_by_id($cardAccountId);
            }
        } catch (Throwable) {
            $account = null;
        }
    }
    // Login / abonent kodu ilə həll (kart + kod eyni sahədə gələ bilər).
    if (!is_array($account)) {
        $lookup = $code !== '' ? $code : $cardId;
        if ($lookup !== '') {
            $account = app_db_account_by_login($lookup);
            if (!is_array($account) && function_exists('app_db_account_by_subscriber_code')) {
                $account = app_db_account_by_subscriber_code($lookup);
            }
        }
    }
    if (!is_array($account) || (int) ($account['id'] ?? 0) <= 0) {
        return ['ok' => false, 'error' => 'employee_not_found'];
    }
    $employeeId = (int) $account['id'];

    $eventAt = app_attendance_normalize_timestamp($payload['timestamp'] ?? $payload['event_at'] ?? '');
    $workDate = substr($eventAt, 0, 10);
    $direction = strtolower(trim((string) ($payload['direction'] ?? $payload['event_type'] ?? 'auto')));
    $isOut = in_array($direction, ['out', 'check_out', 'exit', 'cixis', 'çıxış'], true);
    $isIn = in_array($direction, ['in', 'check_in', 'entry', 'giris', 'giriş'], true);

    $actor = ['id' => 0, 'full_name' => 'scanner:' . $deviceSlot];
    $base = [
        'employee_account_id' => $employeeId,
        'work_date' => $workDate,
        'event_at' => $eventAt,
        'source' => 'scanner:' . $deviceSlot,
        'note' => 'Kart/QR skaner (' . $deviceSlot . ')',
        'branch_id' => clean_text((string) ($payload['branch_id'] ?? $payload['device_id'] ?? '')),
    ];

    // Explicit direction, yoxsa auto: əvvəl check_in, artıq varsa check_out.
    $order = $isOut ? ['check_out'] : ($isIn ? ['check_in'] : ['check_in', 'check_out']);
    $last = ['ok' => false, 'error' => 'invalid_attendance'];
    foreach ($order as $eventType) {
        $last = app_mgs_record_hr_attendance_event($base + ['event_type' => $eventType], $actor, $db);
        if (!empty($last['ok'])) {
            $last['event_type'] = $eventType;
            $last['employee_account_id'] = $employeeId;
            $last['employee_name'] = (string) ($account['full_name'] ?? $account['login'] ?? '');
            return $last;
        }
        // auto rejimində "already_checked_in" olarsa check_out-a keç.
        if (($last['error'] ?? '') !== 'already_checked_in') {
            break;
        }
    }
    return $last;
}
