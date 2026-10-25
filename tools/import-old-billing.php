<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../legacy-customer-secret-lib.php';

function old_billing_arg(string $name, ?string $default = null): ?string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }

    $env = getenv('OLD_BILLING_' . strtoupper(str_replace('-', '_', $name)));
    return $env !== false && $env !== '' ? (string) $env : $default;
}

function old_billing_has_flag(string $flag): bool
{
    global $argv;
    return in_array('--' . $flag, array_map('strval', $argv), true);
}

function old_billing_log(string $message): void
{
    fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $message . PHP_EOL);
}

function old_billing_request(string $method, string $url, array $data = [], array &$cookies = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required.');
    }

    $cookiePairs = [];
    foreach ($cookies as $name => $value) {
        $cookiePairs[] = $name . '=' . $value;
    }

    $ch = curl_init($url);
    $headers = [
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json, text/javascript, */*; q=0.01',
    ];
    if ($cookiePairs !== []) {
        $headers[] = 'Cookie: ' . implode('; ', $cookiePairs);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 SuperOptic-Migration/1.0',
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $resolveIp = trim((string) getenv('OLD_BILLING_RESOLVE_IP'));
    $urlHost = (string) (parse_url($url, PHP_URL_HOST) ?? '');
    $urlScheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? 'https'));
    if ($resolveIp !== '' && filter_var($resolveIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && $urlHost !== '') {
        $resolvePort = $urlScheme === 'http' ? 80 : 443;
        curl_setopt($ch, CURLOPT_RESOLVE, [$urlHost . ':' . $resolvePort . ':' . $resolveIp]);
    }

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    if (preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/mi', $headers, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $cookies[$match[1]] = $match[2];
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

function old_billing_json_request(string $method, string $url, array $data, array &$cookies): array
{
    $response = old_billing_request($method, $url, $data, $cookies);
    $decoded = json_decode((string) $response['body'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON response was not valid for ' . $url . ' status=' . $response['status']);
    }

    return $decoded;
}

function old_billing_extract_csrf(string $html): string
{
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $html, $match)) {
        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    throw new RuntimeException('Login CSRF token was not found.');
}

function old_billing_html_text(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function old_billing_extract_profile_code(string $html): string
{
    if (preg_match('/ppp_user_profile\.php\?id=([^"\']+)/i', $html, $match)) {
        return trim(rawurldecode($match[1]));
    }

    return '';
}

function old_billing_extract_edit_id(array $row): string
{
    $statusHtml = (string) ($row['status'] ?? '');
    if (preg_match('/class=["\'][^"\']*ppp_user_edit[^"\']*["\'][^>]*\sid=["\']([^"\']+)/i', $statusHtml, $match)) {
        return trim($match[1]);
    }
    if (preg_match('/\sid=["\'](\d+)["\'][^>]*class=["\'][^"\']*ppp_user_edit/i', $statusHtml, $match)) {
        return trim($match[1]);
    }

    return trim(old_billing_html_text((string) ($row['id_m'] ?? '')));
}

function old_billing_clean_amount(string $value): string
{
    return trim(str_replace(["\xc2\xa0", '₼', 'azn', 'AZN'], ' ', $value));
}

function old_billing_amount_float(string $value): float
{
    $value = old_billing_clean_amount($value);
    $value = preg_replace('/[^0-9,.\-]/u', '', $value) ?? '';
    $value = str_replace(',', '.', $value);
    return $value === '' || $value === '-' ? 0.0 : (float) $value;
}

function old_billing_first_int(string $value): int
{
    return preg_match('/(\d+)/', $value, $match) ? (int) $match[1] : 0;
}

function old_billing_extract_html_id(string $html, string $class): string
{
    $class = preg_quote($class, '/');
    if (preg_match('/class=["\'][^"\']*' . $class . '[^"\']*["\'][^>]*\sid=["\']([^"\']+)/i', $html, $match)) {
        return trim($match[1]);
    }
    if (preg_match('/\sid=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*' . $class . '/i', $html, $match)) {
        return trim($match[1]);
    }

    return '';
}

function old_billing_guess_service_type(array $edit): string
{
    $service = strtolower((string) ($edit['service'] ?? $edit['xidmet'] ?? ''));
    return str_contains($service, 'ipoe') ? 'ipoe' : 'pppoe';
}

function old_billing_redact_sensitive_payload(array $payload): array
{
    foreach ($payload as $key => $value) {
        $keyName = strtolower((string) $key);
        if (preg_match('/pass|password|secret|token|private|stabil|^login$/', $keyName) === 1) {
            $payload[$key] = $value === '' || $value === null ? $value : '[redacted]';
            continue;
        }
        if (is_array($value)) {
            $payload[$key] = old_billing_redact_sensitive_payload($value);
        }
    }

    return $payload;
}

function old_billing_mask_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) < 4) {
        return '';
    }

    return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
}

function old_billing_normalize_expiry(string $date, string $hour = ''): string
{
    $date = trim($date);
    if ($date === '') {
        return '';
    }

    $hour = preg_replace('/\D+/', '', trim($hour)) ?? '';
    $hour = $hour !== '' ? str_pad((string) min(23, (int) $hour), 2, '0', STR_PAD_LEFT) : '23';
    foreach (['!d-m-Y', '!d.m.Y', '!Y-m-d', '!d/m/Y'] as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $date, new DateTimeZone(app_timezone()));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed instanceof DateTimeImmutable
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
            return $parsed->setTime((int) $hour, 0)->format('Y-m-d H:i:s');
        }
    }

    return '';
}

function old_billing_extract_asset_hints(string $note): array
{
    $hints = [
        'cable_lengths_m' => [],
        'modem_brands' => [],
        'serial_numbers' => [],
        'mac_addresses' => [],
    ];
    if (trim($note) === '') {
        return $hints;
    }

    if (preg_match_all(
        '/\b(?:kabel|cable)\D{0,24}(\d+(?:[.,]\d+)?)\s*(?:m|metr|meter)\b/iu',
        $note,
        $matches
    )) {
        foreach ($matches[1] as $value) {
            $hints['cable_lengths_m'][] = (float) str_replace(',', '.', (string) $value);
        }
    }
    if (preg_match_all(
        '/\b(\d+(?:[.,]\d+)?)\s*(?:m|metr|meter)\D{0,16}(?:kabel|cable)\b/iu',
        $note,
        $matches
    )) {
        foreach ($matches[1] as $value) {
            $hints['cable_lengths_m'][] = (float) str_replace(',', '.', (string) $value);
        }
    }

    $brands = ['Huawei', 'ZTE', 'TP-Link', 'Tenda', 'MikroTik', 'Mercusys', 'Nokia', 'FiberHome', 'Netis', 'D-Link'];
    foreach ($brands as $brand) {
        if (preg_match('/\b' . preg_quote($brand, '/') . '\b/iu', $note) === 1) {
            $hints['modem_brands'][] = $brand;
        }
    }

    if (preg_match_all('/\b(?:sn|s\/n|serial)\s*[:#=-]?\s*([A-Z0-9][A-Z0-9._-]{4,63})/iu', $note, $matches)) {
        $hints['serial_numbers'] = array_map('strval', $matches[1]);
    }
    if (preg_match_all('/\b(?:[0-9A-F]{2}[:-]){5}[0-9A-F]{2}\b/iu', $note, $matches)) {
        $hints['mac_addresses'] = array_map(
            static fn(string $mac): string => strtoupper(str_replace('-', ':', $mac)),
            $matches[0]
        );
    }
    if (preg_match_all('/\b[0-9A-F]{12}\b/iu', $note, $matches)) {
        foreach ($matches[0] as $mac) {
            $normalized = strtoupper(implode(':', str_split((string) $mac, 2)));
            $hints['mac_addresses'][] = $normalized;
        }
    }

    foreach ($hints as $key => $values) {
        $hints[$key] = array_values(array_unique($values, SORT_REGULAR));
    }

    return $hints;
}

function old_billing_location_key(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, [
        'ə' => 'e',
        'ı' => 'i',
        'ö' => 'o',
        'ü' => 'u',
        'ğ' => 'g',
        'ş' => 's',
        'ç' => 'c',
    ]);
    return preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';
}

function old_billing_infer_area_from_address(string $address, array $areaNamesByZoneId): string
{
    $addressKey = trim(old_billing_location_key($address));
    if ($addressKey === '') {
        return '';
    }

    $canonical = [
        'astara' => 'Astara',
        'lenkeran' => 'Lənkəran',
        'sahagaci' => 'Şahağacı',
        'bileceri' => 'Biləcəri',
        'pirsagi' => 'Pirşağı',
        'zabrat' => 'Zabrat',
        'neftcala' => 'Neftçala',
        'masalli' => 'Masallı',
        'qaracuxur' => 'Qaraçuxur',
        'kurdexani' => 'Kürdəxanı',
    ];
    foreach ($canonical as $needle => $area) {
        if (preg_match('/(?:^|\s)' . preg_quote($needle, '/') . '(?:\s|$)/', $addressKey) === 1) {
            return $area;
        }
    }

    foreach ($areaNamesByZoneId as $zoneName) {
        $zoneName = trim((string) $zoneName);
        $zoneKey = trim(old_billing_location_key($zoneName));
        if ($zoneKey !== '' && (
            str_contains(' ' . $addressKey . ' ', ' ' . $zoneKey . ' ')
            || str_contains(' ' . $zoneKey . ' ', ' ' . $addressKey . ' ')
        )) {
            return $zoneName;
        }
    }

    return '';
}

function old_billing_map_customer(
    array $tableRow,
    array $edit,
    array $poolNamesByZoneId = [],
    array $areaNamesByZoneId = []
): array
{
    $subscriberCode = old_billing_extract_profile_code((string) ($tableRow['ad'] ?? ''));
    $subscriberCode = $subscriberCode !== '' ? $subscriberCode : trim((string) ($tableRow['id'] ?? $edit['login'] ?? ''));
    $fullName = trim((string) ($edit['ad'] ?? ''));
    if ($fullName === '') {
        $fullName = old_billing_html_text((string) ($tableRow['ad'] ?? ''));
    }
    $fullName = $fullName !== '' ? $fullName : $subscriberCode;

    $pppoeUsername = $subscriberCode;
    $rawPppoePassword = (string) ($edit['pass'] ?? '');
    $rawPanelPassword = (string) ($edit['stabil'] ?? $edit['panel_pass'] ?? '');
    $pppoePassword = trim($rawPppoePassword);
    $panelPassword = trim($rawPanelPassword);
    $serviceType = old_billing_guess_service_type($edit);
    $tariff = old_billing_html_text((string) ($tableRow['paket'] ?? ($edit['profile'] ?? '')));
    $statusText = old_billing_html_text((string) ($tableRow['status'] ?? ''));
    $legacyStatus = trim((string) ($edit['stat'] ?? ''));
    $isActive = in_array($legacyStatus, ['0', '1'], true)
        ? $legacyStatus === '1'
        : !preg_match('/deaktiv|ləğv|legv|passiv/i', $statusText . ' ' . $fullName);
    $expiresAtRaw = trim((string) ($edit['day'] ?? ''));
    $expiresAt = old_billing_normalize_expiry($expiresAtRaw, (string) ($edit['saat'] ?? ''));
    $ip = trim((string) ($edit['ip'] ?? ''));
    $ipType = strtolower(trim((string) ($edit['ip_type'] ?? 'local')));
    $poolZoneId = trim((string) ($edit['pool_zone_id'] ?? ''));
    $poolName = trim((string) ($poolNamesByZoneId[$poolZoneId] ?? ''));
    $isStaticIp = $ip !== '' && (str_contains($ipType, 'static') || app_ip_is_public_unicast($ip));
    if ($isStaticIp && $poolName !== '' && !str_starts_with(strtolower($poolName), 'static ')) {
        $poolName = 'Static ' . $poolName;
    }
    $rawPhone = (string) ($edit['telefon'] ?? old_billing_html_text((string) ($tableRow['telefon'] ?? '')));
    $phone = trim($rawPhone);
    $address = trim((string) ($edit['adress'] ?? old_billing_html_text((string) ($tableRow['unvan'] ?? ''))));
    $areaZoneId = trim((string) ($edit['zone_id'] ?? ''));
    $area = trim((string) ($areaNamesByZoneId[$areaZoneId] ?? $edit['zone_name'] ?? ''));
    $areaSource = $area !== '' ? 'zone_catalog' : '';
    if ($area === '') {
        $area = old_billing_infer_area_from_address($address, $areaNamesByZoneId);
        $areaSource = $area !== '' ? 'address_exact_match' : 'unavailable';
    }
    $fin = trim((string) ($edit['fin'] ?? old_billing_html_text((string) ($tableRow['fin'] ?? ''))));
    $sv = trim((string) ($edit['sv'] ?? ''));
    $balance = old_billing_clean_amount((string) ($edit['balance'] ?? old_billing_html_text((string) ($tableRow['balans'] ?? ''))));
    $debtMonth = trim((string) ($edit['borc_mounth'] ?? ''));
    $debtAmount = old_billing_clean_amount((string) ($edit['borc_amount'] ?? old_billing_html_text((string) ($tableRow['borc'] ?? ''))));
    $rawComment = (string) ($edit['comment'] ?? old_billing_html_text((string) ($tableRow['qeyd'] ?? '')));
    $comment = trim($rawComment);
    $assetHints = old_billing_extract_asset_hints($rawComment);
    $remainingDaysRaw = old_billing_html_text((string) ($tableRow['day'] ?? ''));

    $payload = [
        'profile' => [
            'full_name' => $fullName,
            'subscriber_code' => $subscriberCode,
            'contract_no' => $subscriberCode,
            'phone' => $phone,
            'area' => $area,
            'address' => $address,
            'street_address' => $address,
            'area_zone_id' => $areaZoneId,
            'area_source' => $areaSource,
            'fin' => $fin,
            'document_serial' => $sv,
            'plan' => $tariff,
            'status' => $isActive ? 'Aktiv' : 'Deaktiv',
            'old_billing_id' => (string) ($tableRow['id'] ?? ''),
            'remaining_days_raw' => $remainingDaysRaw,
        ],
        'billing' => [
            'service_type' => $serviceType,
            'pppoe_username' => $pppoeUsername,
            'customer_code' => $subscriberCode,
            'tariff' => $tariff,
            'balance' => $balance,
            'debt_month' => $debtMonth,
            'debt_amount' => $debtAmount,
            'status' => $isActive ? 'Aktiv' : 'Deaktiv',
            'radius_status' => $isActive ? 'Aktiv' : 'Deaktiv',
            'ip_address' => $ip,
            'assigned_ip' => $isStaticIp ? '' : $ip,
            'static_ip' => $isStaticIp ? $ip : '',
            'pool' => $poolName,
            'pool_zone_id' => $poolZoneId,
            'ip_assignment_mode' => $ip !== '' ? 'manual' : 'pool',
            'expires_at' => $expiresAt,
            'expires_at_raw' => $expiresAtRaw,
            'remaining_days_raw' => $remainingDaysRaw,
        ],
        'credentials' => [
            'source' => 'old_billing',
            'imported_at' => date('c'),
            'plaintext_stored' => false,
        ],
        'internet_services' => [[
            'service_type' => $serviceType,
            'username' => $pppoeUsername,
            'tariff' => $tariff,
            'pool' => $poolName,
            'pool_zone_id' => $poolZoneId,
            'ip_assignment_mode' => $ip !== '' ? 'manual' : 'pool',
            'ip_address' => $isStaticIp ? '' : $ip,
            'assigned_ip' => $isStaticIp ? '' : $ip,
            'static_ip' => $isStaticIp ? $ip : '',
            'status' => $isActive ? 'active' : 'disabled',
            'expires_at' => $expiresAt,
            'expires_at_raw' => $expiresAtRaw,
        ]],
        'notes' => $comment !== '' ? [[
            'source' => 'old_billing',
            'note' => $rawComment,
            'imported_at' => date('c'),
        ]] : [],
        'legacy_asset_hints' => $assetHints,
        'legacy_exact_fields' => [
            'phone' => $rawPhone,
            'area_zone_id' => $areaZoneId,
            'area' => $area,
            'area_source' => $areaSource,
            'street_address' => $address,
            'note' => $rawComment,
        ],
        'old_billing' => [
            'table_row' => $tableRow,
            'edit_json' => old_billing_redact_sensitive_payload($edit),
            'imported_at' => date('c'),
        ],
    ];

    $externalId = 'old-billing:' . $subscriberCode;
    if (strlen($externalId) > 64) {
        $externalId = 'old-billing:sha256:' . substr(hash('sha256', $subscriberCode), 0, 43);
    }

    return [
        'external_id' => $externalId,
        'login' => $subscriberCode,
        'password' => $panelPassword !== '' ? $panelPassword : $pppoePassword,
        'role' => 'customer',
        'account_type' => 'customer',
        'full_name' => $fullName,
        'subscriber_code' => $subscriberCode,
        'contract_no' => $subscriberCode,
        'fin_code' => $fin,
        'document_no' => $sv,
        'phone' => $phone,
        'category' => 'customer',
        'area' => $area,
        'address' => $address,
        'plan' => $tariff,
        'status' => $isActive ? 'Aktiv' : 'Deaktiv',
        'is_active' => $isActive ? 1 : 0,
        'payload' => $payload,
        'radius_password' => $pppoePassword,
    ];
}

function old_billing_login(string $baseUrl, string $email, string $password, array &$cookies): void
{
    $loginPage = old_billing_request('GET', rtrim($baseUrl, '/') . '/index.php', [], $cookies);
    $csrf = old_billing_extract_csrf((string) $loginPage['body']);
    $response = old_billing_json_request('POST', rtrim($baseUrl, '/') . '/core/main/funksiya.php', [
        'csrf_token' => $csrf,
        'email' => $email,
        'pass' => $password,
    ], $cookies);

    if (($response['status'] ?? '') !== 'success') {
        throw new RuntimeException('Old billing login failed.');
    }
}

function old_billing_fetch_users(string $baseUrl, array &$cookies, int $start, int $length, string $subscriber = ''): array
{
    $payload = [
        'draw' => 1,
        'start' => $start,
        'length' => $length,
        'router_open_id' => '',
        'user_status' => '',
        'internet_status' => '',
        'internet_paket' => '',
        'personal_search' => $subscriber,
        'personal_a_search' => '',
        'personal_ip_search' => '',
        'user_borc' => '',
        'zone_filter' => '',
        'static_ip_filter' => '',
        'search[value]' => '',
        'search[regex]' => 'false',
    ];
    foreach (['id', 'ad', 'fin', 'unvan', 'telefon', 'balans', 'paket', 'day', 'borc', 'status'] as $index => $column) {
        $payload["columns[$index][data]"] = $column;
        $payload["columns[$index][name]"] = '';
        $payload["columns[$index][searchable]"] = 'true';
        $payload["columns[$index][orderable]"] = 'false';
        $payload["columns[$index][search][value]"] = '';
        $payload["columns[$index][search][regex]"] = 'false';
    }

    return old_billing_json_request('POST', rtrim($baseUrl, '/') . '/backend/table/ppp_user.php', $payload, $cookies);
}

function old_billing_fetch_edit(string $baseUrl, array &$cookies, string $oldId): array
{
    return old_billing_json_request('POST', rtrim($baseUrl, '/') . '/backend/api/core/core.php', [
        'ppp_user_edit_id' => $oldId,
    ], $cookies);
}

function old_billing_fetch_packages(string $baseUrl, array &$cookies, int $start, int $length): array
{
    $payload = [
        'draw' => 1,
        'start' => $start,
        'length' => $length,
        'user_status' => '',
        'router_open_id' => '',
        'status_boxs' => '',
        'personal_search' => '',
        'personal_a_search' => '',
        'search[value]' => '',
        'search[regex]' => 'false',
    ];
    foreach (['id', 'ad', 'rate', 'qiymet', 'status'] as $index => $column) {
        $payload["columns[$index][data]"] = $column;
        $payload["columns[$index][name]"] = '';
        $payload["columns[$index][searchable]"] = 'true';
        $payload["columns[$index][orderable]"] = 'false';
        $payload["columns[$index][search][value]"] = '';
        $payload["columns[$index][search][regex]"] = 'false';
    }

    return old_billing_json_request('POST', rtrim($baseUrl, '/') . '/backend/table/ppp_paket.php', $payload, $cookies);
}

function old_billing_fetch_package_edit(string $baseUrl, array &$cookies, string $oldId): array
{
    $response = old_billing_request('POST', rtrim($baseUrl, '/') . '/backend/api/core/core.php', [
        'ppp_packet_edit_id' => $oldId,
    ], $cookies);
    $decoded = json_decode((string) $response['body'], true);
    return is_array($decoded) ? $decoded : [];
}

function old_billing_fetch_payments(string $baseUrl, array &$cookies, int $start, int $length): array
{
    $payload = [
        'draw' => 1,
        'start' => $start,
        'length' => $length,
        'personal_pay_search' => '',
        'pay_status' => '',
        'pay_zone' => '',
        'personal_pay_start' => '',
        'personal_pay_end' => '',
        'search[value]' => '',
        'search[regex]' => 'false',
    ];
    foreach (['id', 'user', 'terminal', 'odenis', 'transaction', 'date', 'action'] as $index => $column) {
        $payload["columns[$index][data]"] = $column;
        $payload["columns[$index][name]"] = '';
        $payload["columns[$index][searchable]"] = 'true';
        $payload["columns[$index][orderable]"] = 'false';
        $payload["columns[$index][search][value]"] = '';
        $payload["columns[$index][search][regex]"] = 'false';
    }

    return old_billing_json_request('POST', rtrim($baseUrl, '/') . '/backend/table/payment.php', $payload, $cookies);
}

function old_billing_fetch_profile_html(string $baseUrl, array &$cookies, string $subscriberCode): string
{
    $response = old_billing_request('GET', rtrim($baseUrl, '/') . '/backend/ppp_user_profile.php?id=' . rawurlencode($subscriberCode), [], $cookies);
    return (string) ($response['body'] ?? '');
}

function old_billing_parse_profile_tables(string $html): array
{
    if (!class_exists('DOMDocument')) {
        return ['raw_profile_html_available' => $html !== ''];
    }

    $document = new DOMDocument();
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($document);
    $sections = [
        'payment_history' => 'pay',
        'payment_statement' => 'history',
        'customer_logs' => 'user-logs',
        'radius_logs' => 'radius-logs',
    ];
    $parsed = [];

    foreach ($sections as $key => $id) {
        $nodes = $xpath->query('//*[@id="' . $id . '"]');
        if (!$nodes instanceof DOMNodeList || $nodes->length === 0) {
            $parsed[$key] = [];
            continue;
        }
        $tables = [];
        foreach ($xpath->query('.//table', $nodes->item(0)) ?: [] as $table) {
            $headers = [];
            foreach ($xpath->query('.//thead//th', $table) ?: [] as $header) {
                $headers[] = trim(preg_replace('/\s+/u', ' ', $header->textContent) ?? $header->textContent);
            }
            $rows = [];
            foreach ($xpath->query('.//tbody/tr', $table) ?: [] as $tr) {
                $cells = [];
                foreach ($xpath->query('./td', $tr) ?: [] as $td) {
                    $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent) ?? $td->textContent);
                }
                if ($cells !== [] && !preg_match('/tap.lmad|m.lumat yoxdur|no data/i', implode(' ', $cells))) {
                    $rows[] = $cells;
                }
            }
            $tables[] = ['headers' => $headers, 'rows' => $rows];
        }
        $parsed[$key] = $tables;
    }

    return $parsed;
}

function old_billing_extract_payment_user(string $html): array
{
    $subscriberCode = '';
    if (preg_match('/ppp_user_profile\.php\?id=([^"\']+)/i', $html, $match)) {
        $candidate = trim(rawurldecode($match[1]));
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $candidate) === 1) {
            $subscriberCode = $candidate;
        } else {
            $candidateText = preg_replace('/<[^>]*>/', ' ', $candidate) ?? $candidate;
            $candidateText = html_entity_decode($candidateText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $candidateText = preg_replace('/\s+/u', ' ', $candidateText) ?? $candidateText;
            if (preg_match_all('/\b\d{5,10}\b/', $candidateText, $codeMatches)) {
                $subscriberCode = (string) end($codeMatches[0]);
            }
        }
    }
    $name = '';
    if (preg_match('/class=["\']pay_name["\'][^>]*>(.*?)<\/span>/is', $html, $match)) {
        $name = old_billing_html_text($match[1]);
    }

    return [$subscriberCode, $name];
}

function old_billing_extract_terminal(string $html): string
{
    $lower = strtolower($html);
    if (str_contains($lower, 'kapitalbank')) {
        return 'kapitalbank';
    }
    if (str_contains($lower, 'million')) {
        return 'million';
    }
    if (str_contains($lower, 'emanat')) {
        return 'emanat';
    }
    if (str_contains($lower, 'hesab')) {
        return 'hesab.az';
    }
    if (preg_match('/img[^>]+src=["\'][^"\']*\/([^\/"\']+)\.(?:png|svg|jpg|jpeg)/i', $html, $match)) {
        return strtolower($match[1]);
    }

    return old_billing_html_text($html);
}

function old_billing_record_reject(PDO $db, string $sourceType, string $sourceKey, string $reason, array $row): void
{
    $sourceKey = trim($sourceKey);
    if ($sourceKey === '') {
        $sourceKey = 'sha256:' . hash('sha256', app_db_json_encode($row));
    }
    $statement = $db->prepare(
        'INSERT INTO legacy_import_rejects (
            source_type, source_key, rejection_reason, raw_payload_json
         ) VALUES (
            :source_type, :source_key, :rejection_reason, :raw_payload_json
         )
         ON DUPLICATE KEY UPDATE
            rejection_reason = VALUES(rejection_reason),
            raw_payload_json = VALUES(raw_payload_json),
            last_seen_at = NOW()'
    );
    $statement->execute([
        'source_type' => mb_substr(trim($sourceType), 0, 64, 'UTF-8'),
        'source_key' => mb_substr($sourceKey, 0, 191, 'UTF-8'),
        'rejection_reason' => mb_substr(trim($reason), 0, 128, 'UTF-8'),
        'raw_payload_json' => app_db_json_encode(old_billing_redact_sensitive_payload($row)),
    ]);
}

function old_billing_split_ip_range(string $range): array
{
    $parts = array_map('trim', explode('-', $range, 2));
    if (count($parts) !== 2
        || !filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || !filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || (app_ip_to_num($parts[0]) ?? 0) > (app_ip_to_num($parts[1]) ?? 0)) {
        return ['', ''];
    }

    return [$parts[0], $parts[1]];
}

function old_billing_infer_netmask(string $startIp, string $endIp): string
{
    $start = explode('.', $startIp);
    $end = explode('.', $endIp);
    if (count($start) !== 4 || count($end) !== 4) {
        return '';
    }
    if ($start[0] === $end[0] && $start[1] === $end[1] && $start[2] === $end[2]) {
        return '255.255.255.0';
    }
    if ($start[0] === $end[0] && $start[1] === $end[1]) {
        return '255.255.0.0';
    }

    return '255.0.0.0';
}

function old_billing_fetch_pool_catalog(string $baseUrl, array &$cookies): array
{
    $response = old_billing_json_request('POST', rtrim($baseUrl, '/') . '/backend/ppp_pool.php', [
        'action' => 'get_pool_data',
    ], $cookies);
    $pools = [];
    $zoneMap = [];
    $blacklist = [];

    foreach ($response as $key => $pool) {
        if (preg_match('/^pool\d+$/', (string) $key) !== 1 || !is_array($pool)) {
            continue;
        }
        $poolId = trim((string) ($pool['id'] ?? ''));
        $baseName = trim((string) ($pool['name'] ?? 'Pool ' . $poolId));
        $zones = $poolId !== '' ? old_billing_json_request('POST', rtrim($baseUrl, '/') . '/backend/ppp_pool.php', [
            'action' => 'get_zones',
            'pool_id' => $poolId,
        ], $cookies) : [];
        $zoneNames = [];
        foreach ((array) ($zones['zones'] ?? []) as $zone) {
            if (!is_array($zone)) {
                continue;
            }
            $zoneId = trim((string) ($zone['id'] ?? ''));
            $zoneName = trim((string) ($zone['zone_name'] ?? ''));
            if ($zoneId === '') {
                continue;
            }
            $resolvedName = $baseName . ($zoneName !== '' ? ' - ' . $zoneName : '');
            $zoneMap[$zoneId] = $resolvedName;
            $zoneNames[] = $resolvedName;
        }
        if ($zoneNames === []) {
            $zoneNames[] = $baseName;
        }

        [$localStart, $localEnd] = old_billing_split_ip_range((string) ($pool['local_ip_range'] ?? ''));
        [$staticStart, $staticEnd] = old_billing_split_ip_range((string) ($pool['static_ip_range'] ?? ''));
        foreach ($zoneNames as $resolvedName) {
            if ($localStart !== '') {
                $pools[] = [
                    'name' => $resolvedName,
                    'service_type' => 'pppoe',
                    'pool_type' => 'dynamic',
                    'range_start' => $localStart,
                    'range_end' => $localEnd,
                    'gateway' => '',
                    'netmask' => old_billing_infer_netmask($localStart, $localEnd),
                ];
            }
            if ($staticStart !== '') {
                $pools[] = [
                    'name' => 'Static ' . $resolvedName,
                    'service_type' => 'pppoe',
                    'pool_type' => 'static',
                    'range_start' => $staticStart,
                    'range_end' => $staticEnd,
                    'gateway' => '',
                    'netmask' => old_billing_infer_netmask($staticStart, $staticEnd),
                ];
            }
        }

        foreach (preg_split('/[\s,;]+/', (string) ($pool['blacklist_ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $blacklist[] = $ip;
            }
        }
    }

    return [
        'pools' => $pools,
        'zone_map' => $zoneMap,
        'blacklist_ips' => array_values(array_unique($blacklist)),
    ];
}

function old_billing_fetch_area_catalog(string $baseUrl, array &$cookies): array
{
    $response = old_billing_json_request('POST', rtrim($baseUrl, '/') . '/backend/zone.php', [
        'action' => 'get_zones',
    ], $cookies);
    if (($response['success'] ?? false) !== true || !is_array($response['zones'] ?? null)) {
        throw new RuntimeException('Old billing area catalog could not be loaded.');
    }

    $areas = [];
    foreach ($response['zones'] as $zone) {
        if (!is_array($zone)) {
            continue;
        }
        $zoneId = trim((string) ($zone['id'] ?? ''));
        $zoneName = trim((string) ($zone['zone_name'] ?? ''));
        if ($zoneId !== '' && $zoneName !== '') {
            $areas[$zoneId] = $zoneName;
        }
    }

    return $areas;
}

function old_billing_import_pools(PDO $db, array $catalog, bool $apply): array
{
    $processed = 0;
    $created = 0;
    $updated = 0;
    $select = $db->prepare('SELECT id FROM billing_ip_pools WHERE name = :name LIMIT 1');
    $upsert = $db->prepare(
        'INSERT INTO billing_ip_pools (name, service_type, pool_type, range_start, range_end, gateway, netmask, status)
         VALUES (:name, :service_type, :pool_type, :range_start, :range_end, :gateway, :netmask, "active")
         ON DUPLICATE KEY UPDATE
            service_type = VALUES(service_type),
            pool_type = VALUES(pool_type),
            range_start = VALUES(range_start),
            range_end = VALUES(range_end),
            gateway = VALUES(gateway),
            netmask = VALUES(netmask),
            status = "active"'
    );

    foreach ((array) ($catalog['pools'] ?? []) as $pool) {
        if (!is_array($pool) || trim((string) ($pool['name'] ?? '')) === '') {
            continue;
        }
        if ($apply) {
            $select->execute(['name' => (string) $pool['name']]);
            $exists = $select->fetchColumn() !== false;
            $upsert->execute([
                'name' => (string) $pool['name'],
                'service_type' => (string) ($pool['service_type'] ?? 'pppoe'),
                'pool_type' => (string) ($pool['pool_type'] ?? 'dynamic'),
                'range_start' => (string) ($pool['range_start'] ?? ''),
                'range_end' => (string) ($pool['range_end'] ?? ''),
                'gateway' => (string) ($pool['gateway'] ?? ''),
                'netmask' => (string) ($pool['netmask'] ?? ''),
            ]);
            $exists ? $updated++ : $created++;
        }
        $processed++;
    }

    if ($apply) {
        app_ip_sync_legacy_pools($db);
        foreach ((array) ($catalog['blacklist_ips'] ?? []) as $ip) {
            app_ip_mark_address($db, (string) $ip, 'blacklist', null, '', 'Old billing blacklist');
        }
    }

    return [
        'processed' => $processed,
        'created' => $created,
        'updated' => $updated,
        'blacklist_count' => count((array) ($catalog['blacklist_ips'] ?? [])),
    ];
}

function old_billing_upsert_service_snapshot(PDO $db, array $account, array $mapped): array
{
    $payload = is_array($mapped['payload'] ?? null) ? $mapped['payload'] : [];
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $services = is_array($payload['internet_services'] ?? null) ? $payload['internet_services'] : [];
    $service = is_array($services[0] ?? null) ? $services[0] : [];
    $accountId = (int) ($account['id'] ?? 0);
    $username = trim((string) ($service['username'] ?? $billing['pppoe_username'] ?? ''));
    if ($accountId <= 0 || $username === '') {
        return ['written' => 0, 'ip_written' => 0, 'ip_conflict' => 0];
    }

    $serviceType = (string) ($service['service_type'] ?? 'pppoe');
    $plan = trim((string) ($service['tariff'] ?? $mapped['plan'] ?? ''));
    $poolName = trim((string) ($service['pool'] ?? ''));
    $assignedIp = trim((string) ($service['assigned_ip'] ?? $service['ip_address'] ?? ''));
    $staticIp = trim((string) ($service['static_ip'] ?? ''));
    $expiresAt = trim((string) ($service['expires_at'] ?? ''));
    $active = !empty($mapped['is_active']);
    $expired = $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time();
    $status = $active && !$expired ? 'active' : ($expired ? 'expired' : 'suspended');

    $tariffId = null;
    if ($plan !== '') {
        $statement = $db->prepare('SELECT id FROM billing_tariffs WHERE name = :name AND service_type = :service_type LIMIT 1');
        $statement->execute(['name' => $plan, 'service_type' => $serviceType]);
        $value = $statement->fetchColumn();
        $tariffId = $value !== false ? (int) $value : null;
    }

    $poolId = null;
    if ($poolName !== '') {
        $statement = $db->prepare('SELECT id FROM billing_ip_pools WHERE name = :name AND service_type = :service_type LIMIT 1');
        $statement->execute(['name' => $poolName, 'service_type' => $serviceType]);
        $value = $statement->fetchColumn();
        $poolId = $value !== false ? (int) $value : null;
    }
    if ($poolId === null && ($assignedIp !== '' || $staticIp !== '')) {
        $candidate = $assignedIp !== '' ? $assignedIp : $staticIp;
        $candidateNum = app_ip_to_num($candidate);
        if ($candidateNum !== null) {
            $statement = $db->prepare(
                'SELECT id FROM billing_ip_pools
                 WHERE service_type = :service_type
                   AND INET_ATON(range_start) <= :ip_num
                   AND INET_ATON(range_end) >= :ip_num
                 ORDER BY (pool_type = "static") DESC, id LIMIT 1'
            );
            $statement->execute(['service_type' => $serviceType, 'ip_num' => $candidateNum]);
            $value = $statement->fetchColumn();
            $poolId = $value !== false ? (int) $value : null;
        }
    }

    $upsert = $db->prepare(
        'INSERT INTO billing_radius_services (
            account_id, username, service_type, tariff_id, ip_pool_id,
            assigned_ip, nat_public_ip, static_ip, status, expires_at, last_radius_sync_at
         ) VALUES (
            :account_id, :username, :service_type, :tariff_id, :ip_pool_id,
            :assigned_ip, NULL, :static_ip, :status, :expires_at, NULL
         )
         ON DUPLICATE KEY UPDATE
            account_id = VALUES(account_id),
            service_type = VALUES(service_type),
            tariff_id = VALUES(tariff_id),
            ip_pool_id = VALUES(ip_pool_id),
            assigned_ip = VALUES(assigned_ip),
            static_ip = VALUES(static_ip),
            status = VALUES(status),
            expires_at = VALUES(expires_at)'
    );
    $upsert->execute([
        'account_id' => $accountId,
        'username' => $username,
        'service_type' => $serviceType,
        'tariff_id' => $tariffId,
        'ip_pool_id' => $poolId,
        'assigned_ip' => $assignedIp !== '' ? $assignedIp : null,
        'static_ip' => $staticIp !== '' ? $staticIp : null,
        'status' => $status,
        'expires_at' => $expiresAt !== '' ? $expiresAt : null,
    ]);

    $ip = $assignedIp !== '' ? $assignedIp : $staticIp;
    if ($ip === '') {
        return ['written' => 1, 'ip_written' => 0, 'ip_conflict' => 0];
    }

    $conflict = $db->prepare(
        'SELECT COUNT(*) FROM billing_radius_services
         WHERE account_id <> :account_id
           AND status = "active"
           AND (assigned_ip = :ip OR static_ip = :ip OR nat_public_ip = :ip)'
    );
    $conflict->execute(['account_id' => $accountId, 'ip' => $ip]);
    $hasConflict = (int) $conflict->fetchColumn() > 0;

    $current = $db->prepare(
        'SELECT * FROM customer_ip_assignments
         WHERE customer_id = :customer_id AND service_username = :service_username AND active = 1
         ORDER BY id DESC LIMIT 1'
    );
    $current->execute(['customer_id' => $accountId, 'service_username' => $username]);
    $previous = $current->fetch(PDO::FETCH_ASSOC);
    $localIp = $staticIp === '' ? $assignedIp : '';
    $same = is_array($previous)
        && trim((string) ($previous['local_ip'] ?? '')) === $localIp
        && trim((string) ($previous['static_ip'] ?? '')) === $staticIp
        && trim((string) ($previous['nat_public_ip'] ?? '')) === '';
    if ($same) {
        $update = $db->prepare(
            'UPDATE customer_ip_assignments
             SET radius_synced = 0, metadata_json = :metadata_json
             WHERE id = :id'
        );
        $update->execute([
            'id' => (int) $previous['id'],
            'metadata_json' => app_db_json_encode(['reason' => 'old_billing_import', 'ip_conflict' => $hasConflict]),
        ]);
    } else {
        if (is_array($previous)) {
            $db->prepare('UPDATE customer_ip_assignments SET active = 0, released_at = NOW() WHERE id = :id')
                ->execute(['id' => (int) $previous['id']]);
        }
        $insert = $db->prepare(
            'INSERT INTO customer_ip_assignments (
                customer_id, service_username, local_ip, nat_public_ip, static_ip,
                assignment_type, radius_synced, active, metadata_json
             ) VALUES (
                :customer_id, :service_username, :local_ip, NULL, :static_ip,
                :assignment_type, 0, 1, :metadata_json
             )'
        );
        $insert->execute([
            'customer_id' => $accountId,
            'service_username' => $username,
            'local_ip' => $localIp !== '' ? $localIp : null,
            'static_ip' => $staticIp !== '' ? $staticIp : null,
            'assignment_type' => $staticIp !== '' ? 'static' : 'dynamic',
            'metadata_json' => app_db_json_encode(['reason' => 'old_billing_import', 'ip_conflict' => $hasConflict]),
        ]);
    }

    if (!$hasConflict) {
        app_ip_mark_address(
            $db,
            $ip,
            'used',
            $accountId,
            $username,
            'Old billing import',
            $staticIp !== '' ? 'static_public' : 'local'
        );
    }

    return ['written' => 1, 'ip_written' => 1, 'ip_conflict' => $hasConflict ? 1 : 0];
}

function old_billing_probe_account_failure(PDO $db, array $mapped): string
{
    if ($db->inTransaction()) {
        return 'probe_skipped_active_transaction';
    }

    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            'INSERT INTO portal_accounts (
                external_id, login, password_hash, role, account_type, employee_role, job_title, full_name,
                subscriber_code, contract_no, fin_code, document_no, email, phone, category, area, address,
                plan, status, is_active, payload_json
             ) VALUES (
                :external_id, :login, :password_hash, :role, :account_type, :employee_role, NULL, :full_name,
                :subscriber_code, :contract_no, :fin_code, :document_no, NULL, :phone, :category, :area, :address,
                :plan, :status, :is_active, :payload_json
             )'
        );
        $statement->execute([
            'external_id' => (string) ($mapped['external_id'] ?? ''),
            'login' => (string) ($mapped['login'] ?? ''),
            'password_hash' => password_hash((string) ($mapped['password'] ?? ''), PASSWORD_DEFAULT),
            'role' => 'customer',
            'account_type' => 'customer',
            'employee_role' => '',
            'full_name' => (string) ($mapped['full_name'] ?? ''),
            'subscriber_code' => (string) ($mapped['subscriber_code'] ?? ''),
            'contract_no' => (string) ($mapped['contract_no'] ?? ''),
            'fin_code' => trim((string) ($mapped['fin_code'] ?? '')) ?: null,
            'document_no' => trim((string) ($mapped['document_no'] ?? '')) ?: null,
            'phone' => trim((string) ($mapped['phone'] ?? '')) ?: null,
            'category' => trim((string) ($mapped['category'] ?? '')) ?: null,
            'area' => trim((string) ($mapped['area'] ?? '')) ?: null,
            'address' => trim((string) ($mapped['address'] ?? '')) ?: null,
            'plan' => trim((string) ($mapped['plan'] ?? '')) ?: null,
            'status' => trim((string) ($mapped['status'] ?? 'Aktiv')) ?: null,
            'is_active' => !empty($mapped['is_active']) ? 1 : 0,
            'payload_json' => app_db_json_encode((array) ($mapped['payload'] ?? [])),
        ]);
        $db->rollBack();
        return 'direct_insert_succeeded';
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return get_class($exception) . ': ' . $exception->getMessage();
    }
}

function old_billing_import_packages(PDO $db, string $baseUrl, array &$cookies, bool $apply): array
{
    $first = old_billing_fetch_packages($baseUrl, $cookies, 0, 500);
    $rows = is_array($first['aaData'] ?? null) ? $first['aaData'] : (is_array($first['data'] ?? null) ? $first['data'] : []);
    $total = (int) ($first['iTotalRecords'] ?? $first['recordsTotal'] ?? count($rows));
    $created = 0;
    $updated = 0;
    $processed = 0;
    $prepared = [];
    $nameGroups = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $oldId = old_billing_extract_html_id((string) ($row['status'] ?? ''), 'ppp_packet_edit');
        $oldId = $oldId !== '' ? $oldId : old_billing_html_text((string) ($row['id'] ?? ''));
        $edit = $oldId !== '' ? old_billing_fetch_package_edit($baseUrl, $cookies, $oldId) : [];
        $name = trim((string) ($edit['name'] ?? ''));
        if ($name === '') {
            $name = old_billing_html_text((string) ($row['ad'] ?? ''));
            $name = preg_replace('/^\d+\s*/', '', $name) ?? $name;
        }
        if ($name === '' || $oldId === '') {
            continue;
        }
        $key = mb_strtolower($name, 'UTF-8');
        $prepared[] = [
            'old_id' => $oldId,
            'source_name' => $name,
            'name_key' => $key,
            'speed' => old_billing_first_int((string) ($edit['rate'] ?? old_billing_html_text((string) ($row['rate'] ?? '')))),
            'price' => old_billing_amount_float((string) ($edit['price'] ?? old_billing_html_text((string) ($row['qiymet'] ?? '')))),
            'row' => $row,
            'edit' => $edit,
        ];
        $nameGroups[$key][] = $oldId;
    }

    $canonicalIds = [];
    foreach ($nameGroups as $key => $ids) {
        usort($ids, static function (string $left, string $right): int {
            if (ctype_digit($left) && ctype_digit($right)) {
                return (int) $right <=> (int) $left;
            }
            return strnatcasecmp($right, $left);
        });
        $canonicalIds[$key] = (string) ($ids[0] ?? '');
    }

    $select = $db->prepare('SELECT id FROM billing_tariffs WHERE external_id = :external_id OR (name = :name AND service_type = "pppoe") LIMIT 1');
    $insert = $db->prepare(
        'INSERT INTO billing_tariffs (external_id, name, service_type, download_mbps, upload_mbps, monthly_fee, radius_group, status, metadata_json)
         VALUES (:external_id, :name, "pppoe", :download_mbps, :upload_mbps, :monthly_fee, :radius_group, "active", :metadata_json)
         ON DUPLICATE KEY UPDATE
            external_id = VALUES(external_id),
            name = VALUES(name),
            download_mbps = VALUES(download_mbps),
            upload_mbps = VALUES(upload_mbps),
            monthly_fee = VALUES(monthly_fee),
            radius_group = VALUES(radius_group),
            status = "active",
            metadata_json = VALUES(metadata_json)'
    );

    foreach ($prepared as $package) {
        $oldId = (string) $package['old_id'];
        $sourceName = (string) $package['source_name'];
        $nameKey = (string) $package['name_key'];
        $isCanonical = hash_equals((string) ($canonicalIds[$nameKey] ?? ''), $oldId);
        $name = $isCanonical ? $sourceName : $sourceName . ' [legacy ' . $oldId . ']';
        $externalId = 'old-billing-package:' . $oldId;
        $radiusGroup = 'old_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', strtolower($name));
        $radiusGroup = trim($radiusGroup, '_') ?: ('old_package_' . $oldId);
        $metadata = app_db_json_encode([
            'old_billing' => [
                'id' => $oldId,
                'source_name' => $sourceName,
                'duplicate_name' => count($nameGroups[$nameKey] ?? []) > 1,
                'canonical' => $isCanonical,
                'row' => $package['row'],
                'edit_json' => $package['edit'],
                'imported_at' => date('c'),
            ],
        ]);

        if ($apply) {
            $select->execute(['external_id' => $externalId, 'name' => $name]);
            $exists = $select->fetchColumn() !== false;
            $insert->execute([
                'external_id' => $externalId,
                'name' => $name,
                'download_mbps' => (int) $package['speed'],
                'upload_mbps' => (int) $package['speed'],
                'monthly_fee' => (float) $package['price'],
                'radius_group' => $radiusGroup,
                'metadata_json' => $metadata,
            ]);
            $exists ? $updated++ : $created++;
        }
        $processed++;
    }

    return ['total' => $total, 'processed' => $processed, 'created' => $created, 'updated' => $updated];
}

function old_billing_import_payments(
    PDO $db,
    string $baseUrl,
    array &$cookies,
    bool $apply,
    int $offset,
    int $limit,
    int $batch,
    bool $skipExisting
): array
{
    $first = old_billing_fetch_payments($baseUrl, $cookies, $offset, $batch);
    $total = (int) ($first['iTotalRecords'] ?? $first['recordsTotal'] ?? 0);
    $target = $limit > 0 ? min($limit, max(0, $total - $offset)) : max(0, $total - $offset);
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $processed = 0;

    $accountByCode = $db->prepare('SELECT id FROM portal_accounts WHERE subscriber_code = :code OR login = :code LIMIT 1');
    $paymentExists = $db->prepare('SELECT id FROM billing_payments WHERE external_id = :external_id LIMIT 1');
    $deleteResolvedReject = $db->prepare(
        'DELETE FROM legacy_import_rejects WHERE source_type = "payment" AND source_key = :source_key'
    );
    $upsert = $db->prepare(
        'INSERT INTO billing_payments (external_id, account_id, subscriber_code, amount, payment_method, transaction_id, note, metadata_json, created_at)
         VALUES (:external_id, :account_id, :subscriber_code, :amount, :payment_method, :transaction_id, :note, :metadata_json, :created_at)
         ON DUPLICATE KEY UPDATE
            account_id = VALUES(account_id),
            subscriber_code = VALUES(subscriber_code),
            amount = VALUES(amount),
            payment_method = VALUES(payment_method),
            transaction_id = VALUES(transaction_id),
            note = VALUES(note),
            metadata_json = VALUES(metadata_json),
            created_at = VALUES(created_at)'
    );

    for ($start = $offset; $start < $offset + $target; $start += $batch) {
        $list = $start === $offset ? $first : old_billing_fetch_payments($baseUrl, $cookies, $start, min($batch, $offset + $target - $start));
        $rows = is_array($list['aaData'] ?? null) ? $list['aaData'] : (is_array($list['data'] ?? null) ? $list['data'] : []);
        foreach ($rows as $row) {
            if ($processed >= $target) {
                break 2;
            }
            if (!is_array($row)) {
                continue;
            }
            [$subscriberCode, $payerName] = old_billing_extract_payment_user((string) ($row['user'] ?? ''));
            $oldId = old_billing_html_text((string) ($row['id'] ?? ''));
            $externalId = 'old-billing-payment:' . $oldId;
            $amount = old_billing_amount_float((string) ($row['odenis'] ?? '0'));
            $terminal = old_billing_extract_terminal((string) ($row['terminal'] ?? ''));
            $transaction = old_billing_html_text((string) ($row['transaction'] ?? ''));
            $createdAt = old_billing_html_text((string) ($row['date'] ?? ''));
            $createdAt = strtotime($createdAt) !== false ? date('Y-m-d H:i:s', strtotime($createdAt)) : date('Y-m-d H:i:s');
            $processed++;

            if ($subscriberCode === '' || $oldId === '') {
                $skipped++;
                if ($apply) {
                    $reason = $subscriberCode === '' ? 'missing_subscriber_code' : 'missing_payment_id';
                    old_billing_record_reject(
                        $db,
                        'payment',
                        $oldId !== '' ? ('old-billing-payment:' . $oldId) : '',
                        $reason,
                        $row
                    );
                }
                continue;
            }
            $accountByCode->execute(['code' => $subscriberCode]);
            $accountId = (int) $accountByCode->fetchColumn();
            if ($accountId <= 0 && $apply) {
                $placeholder = app_db_upsert_account([
                    'external_id' => 'old-billing-payment-only:' . $subscriberCode,
                    'login' => $subscriberCode,
                    'password' => bin2hex(random_bytes(4)),
                    'role' => 'customer',
                    'account_type' => 'customer',
                    'full_name' => $payerName !== '' ? $payerName : ('Kohne billing musteri ' . $subscriberCode),
                    'subscriber_code' => $subscriberCode,
                    'contract_no' => $subscriberCode,
                    'status' => 'Arxiv',
                    'is_active' => 0,
                    'payload' => [
                        'profile' => [
                            'subscriber_code' => $subscriberCode,
                            'contract_no' => $subscriberCode,
                            'full_name' => $payerName,
                            'status' => 'Arxiv',
                        ],
                        'billing' => [
                            'customer_code' => $subscriberCode,
                            'status' => 'Arxiv',
                            'source' => 'old_billing_payment_only',
                        ],
                        'old_billing' => [
                            'payment_only' => true,
                            'imported_at' => date('c'),
                        ],
                    ],
                ], false);
                $accountId = (int) ($placeholder['id'] ?? 0);
            }
            if ($accountId <= 0) {
                $skipped++;
                if ($apply) {
                    old_billing_record_reject(
                        $db,
                        'payment',
                        'old-billing-payment:' . $oldId,
                        'customer_account_unresolved',
                        $row
                    );
                }
                continue;
            }
            if ($apply) {
                $paymentExists->execute(['external_id' => $externalId]);
                $exists = $paymentExists->fetchColumn() !== false;
                if ($exists && $skipExisting) {
                    $updated++;
                    if ($processed % 1000 === 0) {
                        old_billing_log('Payments processed ' . $processed . '/' . $target . ' skipped=' . $skipped);
                    }
                    continue;
                }
                $upsert->execute([
                    'external_id' => $externalId,
                    'account_id' => $accountId,
                    'subscriber_code' => $subscriberCode,
                    'amount' => number_format($amount, 2, '.', ''),
                    'payment_method' => $terminal !== '' ? $terminal : 'old_billing',
                    'transaction_id' => $transaction,
                    'note' => 'old_billing_import',
                    'metadata_json' => app_db_json_encode([
                        'old_billing' => [
                            'payment_id' => $oldId,
                            'payer_name' => $payerName,
                            'row' => $row,
                            'imported_at' => date('c'),
                        ],
                    ]),
                    'created_at' => $createdAt,
                ]);
                $deleteResolvedReject->execute(['source_key' => $externalId]);
                $exists ? $updated++ : $created++;
            }
            if ($processed % 1000 === 0) {
                old_billing_log('Payments processed ' . $processed . '/' . $target . ' skipped=' . $skipped);
            }
        }
    }

    return ['total' => $total, 'target' => $target, 'processed' => $processed, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped];
}

function old_billing_refresh_payment_summaries(PDO $db): int
{
    $rows = $db->query(
        "SELECT a.id, a.payload_json, COUNT(p.id) AS payment_count, COALESCE(SUM(p.amount),0) AS payment_total, MAX(p.created_at) AS last_payment_at
         FROM portal_accounts a
         LEFT JOIN billing_payments p ON p.account_id = a.id
         WHERE a.role = 'customer'
         GROUP BY a.id, a.payload_json"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $update = $db->prepare('UPDATE portal_accounts SET payload_json = :payload_json WHERE id = :id');
    $count = 0;
    foreach ($rows as $row) {
        $payload = app_db_json_decode((string) ($row['payload_json'] ?? ''), []);
        $payload['billing'] = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $payload['billing']['payment_count'] = (int) ($row['payment_count'] ?? 0);
        $payload['billing']['payment_total'] = number_format((float) ($row['payment_total'] ?? 0), 2, '.', '');
        $payload['billing']['last_payment_at'] = (string) ($row['last_payment_at'] ?? '');
        $update->execute(['payload_json' => app_db_json_encode($payload), 'id' => (int) $row['id']]);
        $count++;
    }

    return $count;
}

function old_billing_import_profile_tables(PDO $db, string $baseUrl, array &$cookies, bool $apply, int $offset, int $limit): array
{
    $total = (int) $db->query("SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer' AND external_id LIKE 'old-billing:%'")->fetchColumn();
    $target = $limit > 0 ? min($limit, max(0, $total - $offset)) : max(0, $total - $offset);
    $select = $db->prepare(
        "SELECT id, subscriber_code, payload_json
         FROM portal_accounts
         WHERE role = 'customer' AND external_id LIKE 'old-billing:%'
         ORDER BY id
         LIMIT :limit OFFSET :offset"
    );
    $select->bindValue('limit', $target, PDO::PARAM_INT);
    $select->bindValue('offset', $offset, PDO::PARAM_INT);
    $select->execute();
    $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $update = $db->prepare('UPDATE portal_accounts SET payload_json = :payload_json WHERE id = :id');
    $deleteSections = $db->prepare('DELETE FROM legacy_billing_profile_sections WHERE account_id = :account_id');
    $insertSection = $db->prepare(
        'INSERT INTO legacy_billing_profile_sections (
            account_id, subscriber_code, section_key, section_title, table_index,
            headers_json, rows_json, row_count, source, imported_at
         ) VALUES (
            :account_id, :subscriber_code, :section_key, :section_title, :table_index,
            :headers_json, :rows_json, :row_count, :source, NOW()
         )
         ON DUPLICATE KEY UPDATE
            section_title = VALUES(section_title),
            headers_json = VALUES(headers_json),
            rows_json = VALUES(rows_json),
            row_count = VALUES(row_count),
            source = VALUES(source),
            imported_at = NOW()'
    );
    $processed = 0;
    $updated = 0;
    $failed = 0;
    $sectionsWritten = 0;

    foreach ($rows as $row) {
        $subscriberCode = (string) ($row['subscriber_code'] ?? '');
        try {
            if ($subscriberCode === '') {
                throw new RuntimeException('Missing subscriber code.');
            }
            $html = old_billing_fetch_profile_html($baseUrl, $cookies, $subscriberCode);
            $tables = old_billing_parse_profile_tables($html);
            if ($apply) {
                $payload = app_db_json_decode((string) ($row['payload_json'] ?? ''), []);
                $payload['old_billing'] = is_array($payload['old_billing'] ?? null) ? $payload['old_billing'] : [];
                $payload['old_billing']['profile_tables'] = $tables;
                $payload['old_billing']['profile_tables_imported_at'] = date('c');
                $accountId = (int) $row['id'];
                $db->beginTransaction();
                try {
                    $deleteSections->execute(['account_id' => $accountId]);
                    foreach ($tables as $sectionKey => $sectionTables) {
                        foreach (array_values(is_array($sectionTables) ? $sectionTables : []) as $tableIndex => $table) {
                            $headers = is_array($table['headers'] ?? null) ? $table['headers'] : [];
                            $sectionRows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
                            $insertSection->execute([
                                'account_id' => $accountId,
                                'subscriber_code' => $subscriberCode,
                                'section_key' => (string) $sectionKey,
                                'section_title' => ucwords(str_replace('_', ' ', (string) $sectionKey)),
                                'table_index' => $tableIndex,
                                'headers_json' => app_db_json_encode(array_values($headers)),
                                'rows_json' => app_db_json_encode(array_values($sectionRows)),
                                'row_count' => count($sectionRows),
                                'source' => 'old-billing-live-profile',
                            ]);
                            $sectionsWritten++;
                        }
                    }
                    $update->execute(['payload_json' => app_db_json_encode($payload), 'id' => $accountId]);
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $exception;
                }
                $updated++;
            }
            $processed++;
            if ($processed % 100 === 0) {
                old_billing_log('Profile tables processed ' . $processed . '/' . $target);
            }
        } catch (Throwable $exception) {
            $failed++;
            old_billing_log('Profile table failed subscriber=' . $subscriberCode . ': ' . $exception->getMessage());
        }
    }

    return ['total' => $total, 'target' => $target, 'processed' => $processed, 'updated' => $updated, 'sections_written' => $sectionsWritten, 'failed' => $failed];
}

$baseUrl = rtrim((string) old_billing_arg('base-url', 'https://billing.superoptic.net'), '/');
$email = (string) old_billing_arg('email', '');
$password = (string) old_billing_arg('password', '');
$limit = max(0, (int) old_billing_arg('limit', '0'));
$offset = max(0, (int) old_billing_arg('offset', '0'));
$paymentLimit = max(0, (int) old_billing_arg('payment-limit', '0'));
$paymentOffset = max(0, (int) old_billing_arg('payment-offset', '0'));
$profileLimit = max(0, (int) old_billing_arg('profile-limit', '0'));
$profileOffset = max(0, (int) old_billing_arg('profile-offset', '0'));
$subscriber = trim((string) old_billing_arg('subscriber', ''));
$batch = max(1, min(5000, (int) old_billing_arg('batch', '500')));
$apply = old_billing_has_flag('apply');
$syncRadius = !old_billing_has_flag('no-radius-sync');
$skipUsers = old_billing_has_flag('skip-users');
$importPools = old_billing_has_flag('import-pools');
$importPackages = old_billing_has_flag('import-packages');
$importPayments = old_billing_has_flag('import-payments');
$skipExistingPayments = old_billing_has_flag('skip-existing-payments');
$importProfiles = old_billing_has_flag('import-profiles');
$hydrateServices = old_billing_has_flag('hydrate-services');

if ($email === '' || $password === '') {
    fwrite(STDERR, "Usage: OLD_BILLING_EMAIL=... OLD_BILLING_PASSWORD=... php tools/import-old-billing.php [--apply] [--subscriber=2320025] [--limit=100] [--offset=0] [--batch=500] [--import-pools] [--import-packages] [--hydrate-services] [--import-payments] [--skip-existing-payments] [--import-profiles] [--payment-limit=1000] [--profile-limit=1000] [--skip-users] [--no-radius-sync]\n");
    exit(2);
}
if ($subscriber !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $subscriber) !== 1) {
    fwrite(STDERR, "Subscriber filter is invalid.\n");
    exit(2);
}
if ($hydrateServices && $syncRadius) {
    fwrite(STDERR, "--hydrate-services requires --no-radius-sync so live RADIUS tables are not modified.\n");
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Local DB is not available.\n");
    exit(2);
}

$cookies = [];
$created = 0;
$updated = 0;
$failed = 0;
$processed = 0;
$preview = [];
$serviceStats = ['written' => 0, 'ip_written' => 0, 'ip_conflict' => 0, 'fin_conflict' => 0];
$secretStats = ['written' => 0, 'missing' => 0];

old_billing_log('Logging in to old billing...');
old_billing_login($baseUrl, $email, $password, $cookies);
$areaCatalog = [];
if (!$skipUsers) {
    if ($apply) {
        app_legacy_customer_secret_key();
    }
    old_billing_log('Fetching old billing area catalog...');
    $areaCatalog = old_billing_fetch_area_catalog($baseUrl, $cookies);
    old_billing_log('Area catalog loaded: ' . count($areaCatalog));
}
$poolCatalog = ['pools' => [], 'zone_map' => [], 'blacklist_ips' => []];
if ($importPools || $hydrateServices) {
    old_billing_log('Fetching old billing pools and zones...');
    $poolCatalog = old_billing_fetch_pool_catalog($baseUrl, $cookies);
}
if ($importPools) {
    $poolResult = old_billing_import_pools($db, $poolCatalog, $apply);
    old_billing_log('Pools done: ' . json_encode($poolResult, JSON_UNESCAPED_UNICODE));
}
if ($importPackages) {
    old_billing_log('Importing PPPoE packages before customer services...');
    $packageResult = old_billing_import_packages($db, $baseUrl, $cookies, $apply);
    old_billing_log('Packages done: ' . json_encode($packageResult, JSON_UNESCAPED_UNICODE));
    $importPackages = false;
}
old_billing_log('Login OK. Fetching PPPoE users...');

if (!$skipUsers) {
    $first = old_billing_fetch_users($baseUrl, $cookies, $offset, $subscriber !== '' ? 10 : $batch, $subscriber);
    $total = (int) ($first['recordsTotal'] ?? $first['recordsFiltered'] ?? $first['iTotalRecords'] ?? $first['iTotalDisplayRecords'] ?? count((array) ($first['data'] ?? $first['aaData'] ?? [])));
    $firstRows = is_array($first['data'] ?? null) ? $first['data'] : (is_array($first['aaData'] ?? null) ? $first['aaData'] : []);
    $target = $subscriber !== ''
        ? count($firstRows)
        : ($limit > 0 ? min($limit, max(0, $total - $offset)) : max(0, $total - $offset));
    old_billing_log('Old billing reports ' . $total . ' users. Offset: ' . $offset . '. Target: ' . $target . '. Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN'));

    for ($start = $offset; $start < $offset + $target; $start += $batch) {
        $list = $start === $offset ? $first : old_billing_fetch_users($baseUrl, $cookies, $start, min($batch, $offset + $target - $start), $subscriber);
        $rows = is_array($list['data'] ?? null) ? $list['data'] : (is_array($list['aaData'] ?? null) ? $list['aaData'] : []);
        foreach ($rows as $row) {
            if ($processed >= $target) {
                break 2;
            }
            if (!is_array($row)) {
                continue;
            }
            $oldId = old_billing_extract_edit_id($row);
            try {
                if ($oldId === '') {
                    throw new RuntimeException('Missing old row id.');
                }
                $edit = old_billing_fetch_edit($baseUrl, $cookies, $oldId);
                $mapped = old_billing_map_customer(
                    $row,
                    $edit,
                    (array) ($poolCatalog['zone_map'] ?? []),
                    $areaCatalog
                );
                if ($subscriber !== '' && !hash_equals($subscriber, (string) $mapped['subscriber_code'])) {
                    continue;
                }
                $preview[] = [
                    'subscriber_code' => $mapped['subscriber_code'],
                    'username' => $mapped['payload']['billing']['pppoe_username'] ?? '',
                    'phone_masked' => old_billing_mask_phone((string) ($mapped['phone'] ?? '')),
                    'area' => (string) ($mapped['area'] ?? ''),
                    'street_address_present' => trim((string) ($mapped['address'] ?? '')) !== '',
                    'note_present' => trim((string) ($mapped['payload']['legacy_exact_fields']['note'] ?? '')) !== '',
                    'asset_hint_count' => array_sum(array_map(
                        'count',
                        (array) ($mapped['payload']['legacy_asset_hints'] ?? [])
                    )),
                    'ip_present' => trim((string) ($mapped['payload']['billing']['ip_address'] ?? '')) !== '',
                    'expiry_normalized' => trim((string) ($mapped['payload']['billing']['expires_at'] ?? '')) !== '',
                    'status' => $mapped['status'],
                ];
                if ($apply) {
                    $existing = app_db_account_by_subscriber_code((string) $mapped['subscriber_code']) ?? app_db_account_by_login((string) $mapped['login']);
                    if (is_array($existing)) {
                        $existingPayload = app_db_account_payload($existing);
                        $mapped['payload'] = array_replace_recursive(
                            is_array($existingPayload) ? $existingPayload : [],
                            (array) ($mapped['payload'] ?? [])
                        );
                    }
                    $radiusPassword = (string) ($mapped['radius_password'] ?? '');
                    unset($mapped['radius_password']);
                    if ($existing === null && trim((string) ($mapped['password'] ?? '')) === '') {
                        $mapped['password'] = bin2hex(random_bytes(24)) . 'Aa1!';
                    }
                    $saved = app_db_upsert_account($mapped, false);
                    if ($saved === null && trim((string) ($mapped['fin_code'] ?? '')) !== '') {
                        $mapped['fin_code'] = '';
                        $saved = app_db_upsert_account($mapped, false);
                        if ($saved !== null) {
                            $serviceStats['fin_conflict']++;
                        }
                    }
                    if ($saved === null) {
                        if ((string) getenv('OLD_BILLING_DEBUG_SAVE') === '1') {
                            old_billing_log('Account save probe: ' . old_billing_probe_account_failure($db, $mapped));
                        }
                        throw new RuntimeException('Local account save failed.');
                    }
                    if ($radiusPassword !== '') {
                        app_legacy_customer_secret_store(
                            $db,
                            (int) ($saved['id'] ?? 0),
                            'pppoe_password',
                            $radiusPassword
                        );
                        $secretStats['written']++;
                    } else {
                        $secretStats['missing']++;
                    }
                    if ($syncRadius && !app_billing_sync_radius_account($saved, $radiusPassword !== '' ? $radiusPassword : null)) {
                        throw new RuntimeException('Radius sync failed.');
                    }
                    if ($hydrateServices) {
                        $snapshot = old_billing_upsert_service_snapshot($db, $saved, $mapped);
                        foreach ($serviceStats as $key => $value) {
                            $serviceStats[$key] = $value + (int) ($snapshot[$key] ?? 0);
                        }
                    }
                    $existing === null ? $created++ : $updated++;
                }
                $processed++;
                if ($processed % 100 === 0) {
                    old_billing_log('Processed ' . $processed . '/' . $target);
                }
            } catch (Throwable $exception) {
                $failed++;
                old_billing_log('Failed old_id=' . $oldId . ': ' . $exception->getMessage());
            }
        }
    }

    $preview = array_slice($preview, 0, 5);
    old_billing_log(
        'Users done. processed=' . $processed
        . ' created=' . $created
        . ' updated=' . $updated
        . ' failed=' . $failed
        . ' services=' . $serviceStats['written']
        . ' ips=' . $serviceStats['ip_written']
        . ' ip_conflicts=' . $serviceStats['ip_conflict']
        . ' fin_conflicts=' . $serviceStats['fin_conflict']
        . ' encrypted_secrets=' . $secretStats['written']
        . ' missing_secrets=' . $secretStats['missing']
    );
    if (!$apply) {
        old_billing_log('Dry-run preview:');
        fwrite(STDOUT, json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        old_billing_log('Run again with --apply to write into local DB.');
    }
}

if ($importPackages) {
    old_billing_log('Importing PPPoE packages...');
    $packageResult = old_billing_import_packages($db, $baseUrl, $cookies, $apply);
    old_billing_log('Packages done: ' . json_encode($packageResult, JSON_UNESCAPED_UNICODE));
}

if ($importPayments) {
    old_billing_log('Importing payments...');
    $paymentResult = old_billing_import_payments(
        $db,
        $baseUrl,
        $cookies,
        $apply,
        $paymentOffset,
        $paymentLimit,
        $batch,
        $skipExistingPayments
    );
    old_billing_log('Payments done: ' . json_encode($paymentResult, JSON_UNESCAPED_UNICODE));
    if ($apply) {
        old_billing_log('Refreshing customer payment summaries...');
        old_billing_log('Payment summaries updated for ' . old_billing_refresh_payment_summaries($db) . ' customers.');
    }
}

if ($importProfiles) {
    old_billing_log('Importing profile payment statements, customer logs and RADIUS logs...');
    $profileResult = old_billing_import_profile_tables($db, $baseUrl, $cookies, $apply, $profileOffset, $profileLimit);
    old_billing_log('Profile tables done: ' . json_encode($profileResult, JSON_UNESCAPED_UNICODE));
}
