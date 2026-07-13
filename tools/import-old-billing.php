<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

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

function old_billing_map_customer(array $tableRow, array $edit): array
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
    $rawLegacyPanelPasswordHash = (string) ($edit['login'] ?? '');
    $rawPanelPassword = (string) ($edit['stabil'] ?? $edit['panel_pass'] ?? '');
    $pppoePassword = trim($rawPppoePassword);
    $legacyPanelPasswordHash = trim($rawLegacyPanelPasswordHash);
    $panelPassword = trim($rawPanelPassword);
    $serviceType = old_billing_guess_service_type($edit);
    $tariff = old_billing_html_text((string) ($tableRow['paket'] ?? ($edit['profile'] ?? '')));
    $statusText = old_billing_html_text((string) ($tableRow['status'] ?? ($edit['stat'] ?? '')));
    $isActive = !preg_match('/deaktiv|ləğv|legv|passiv/i', $statusText . ' ' . $fullName);
    $expiresAt = trim((string) ($edit['day'] ?? ''));
    $ip = trim((string) ($edit['ip'] ?? ''));
    $rawPhone = (string) ($edit['telefon'] ?? old_billing_html_text((string) ($tableRow['telefon'] ?? '')));
    $phone = trim($rawPhone);
    $address = trim((string) ($edit['adress'] ?? old_billing_html_text((string) ($tableRow['unvan'] ?? ''))));
    $fin = trim((string) ($edit['fin'] ?? old_billing_html_text((string) ($tableRow['fin'] ?? ''))));
    $sv = trim((string) ($edit['sv'] ?? ''));
    $balance = old_billing_clean_amount((string) ($edit['balance'] ?? old_billing_html_text((string) ($tableRow['balans'] ?? ''))));
    $debtMonth = trim((string) ($edit['borc_mounth'] ?? ''));
    $debtAmount = old_billing_clean_amount((string) ($edit['borc_amount'] ?? old_billing_html_text((string) ($tableRow['borc'] ?? ''))));
    $rawComment = (string) ($edit['comment'] ?? '');
    $comment = trim($rawComment);
    $remainingDaysRaw = old_billing_html_text((string) ($tableRow['day'] ?? ''));

    $payload = [
        'profile' => [
            'full_name' => $fullName,
            'subscriber_code' => $subscriberCode,
            'contract_no' => $subscriberCode,
            'phone' => $phone,
            'address' => $address,
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
            'pppoe_password' => $pppoePassword,
            'panel_password' => $panelPassword,
            'legacy_panel_password_hash' => $legacyPanelPasswordHash,
            'customer_code' => $subscriberCode,
            'tariff' => $tariff,
            'balance' => $balance,
            'debt_month' => $debtMonth,
            'debt_amount' => $debtAmount,
            'status' => $isActive ? 'Aktiv' : 'Deaktiv',
            'radius_status' => $isActive ? 'Aktiv' : 'Deaktiv',
            'ip_address' => $ip,
            'expires_at_raw' => $expiresAt,
            'remaining_days_raw' => $remainingDaysRaw,
        ],
        'credentials' => [
            'source' => 'old_billing',
            'pppoe_username' => $pppoeUsername,
            'pppoe_password' => $pppoePassword,
            'customer_portal_password' => $panelPassword,
            'legacy_customer_portal_password_hash' => $legacyPanelPasswordHash,
            'imported_at' => date('c'),
        ],
        'internet_services' => [[
            'service_type' => $serviceType,
            'username' => $pppoeUsername,
            'password' => $pppoePassword,
            'tariff' => $tariff,
            'ip_address' => $ip,
            'static_ip' => '',
            'status' => $isActive ? 'active' : 'disabled',
            'expires_at_raw' => $expiresAt,
        ]],
        'notes' => $comment !== '' ? [[
            'source' => 'old_billing',
            'note' => $rawComment,
            'imported_at' => date('c'),
        ]] : [],
        'legacy_exact_fields' => [
            'phone' => $rawPhone,
            'note' => $rawComment,
            'pppoe_password' => $rawPppoePassword,
            'customer_portal_password' => $rawPanelPassword,
            'legacy_customer_portal_password_hash' => $rawLegacyPanelPasswordHash,
        ],
        'old_billing' => [
            'table_row' => $tableRow,
            'edit_json' => $edit,
            'imported_at' => date('c'),
        ],
    ];

    return [
        'external_id' => 'old-billing:' . (string) ($tableRow['id'] ?? $subscriberCode),
        'login' => $subscriberCode,
        'password' => $panelPassword !== '' ? $panelPassword : ($pppoePassword !== '' ? $pppoePassword : bin2hex(random_bytes(4))),
        'role' => 'customer',
        'account_type' => 'customer',
        'full_name' => $fullName,
        'subscriber_code' => $subscriberCode,
        'contract_no' => $subscriberCode,
        'phone' => $phone,
        'category' => 'customer',
        'area' => '',
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

function old_billing_fetch_users(string $baseUrl, array &$cookies, int $start, int $length): array
{
    $payload = [
        'draw' => 1,
        'start' => $start,
        'length' => $length,
        'router_open_id' => '',
        'user_status' => '',
        'internet_status' => '',
        'internet_paket' => '',
        'personal_search' => '',
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
        $subscriberCode = trim(rawurldecode($match[1]));
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

function old_billing_import_packages(PDO $db, string $baseUrl, array &$cookies, bool $apply): array
{
    $first = old_billing_fetch_packages($baseUrl, $cookies, 0, 500);
    $rows = is_array($first['aaData'] ?? null) ? $first['aaData'] : (is_array($first['data'] ?? null) ? $first['data'] : []);
    $total = (int) ($first['iTotalRecords'] ?? $first['recordsTotal'] ?? count($rows));
    $created = 0;
    $updated = 0;
    $processed = 0;

    $select = $db->prepare('SELECT id FROM billing_tariffs WHERE external_id = :external_id OR (name = :name AND service_type = "pppoe") LIMIT 1');
    $insert = $db->prepare(
        'INSERT INTO billing_tariffs (external_id, name, service_type, download_mbps, upload_mbps, monthly_fee, radius_group, status, metadata_json)
         VALUES (:external_id, :name, "pppoe", :download_mbps, :upload_mbps, :monthly_fee, :radius_group, "active", :metadata_json)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            download_mbps = VALUES(download_mbps),
            upload_mbps = VALUES(upload_mbps),
            monthly_fee = VALUES(monthly_fee),
            radius_group = VALUES(radius_group),
            status = "active",
            metadata_json = VALUES(metadata_json)'
    );

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
        $rateText = trim((string) ($edit['rate'] ?? old_billing_html_text((string) ($row['rate'] ?? ''))));
        $priceText = trim((string) ($edit['price'] ?? old_billing_html_text((string) ($row['qiymet'] ?? ''))));
        $speed = old_billing_first_int($rateText);
        $price = old_billing_amount_float($priceText);
        $externalId = 'old-billing-package:' . $oldId;
        $radiusGroup = 'old_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', strtolower($name));
        $radiusGroup = trim($radiusGroup, '_') ?: ('old_package_' . $oldId);
        $metadata = app_db_json_encode([
            'old_billing' => [
                'id' => $oldId,
                'row' => $row,
                'edit_json' => $edit,
                'imported_at' => date('c'),
            ],
        ]);

        if ($name === '') {
            continue;
        }
        if ($apply) {
            $select->execute(['external_id' => $externalId, 'name' => $name]);
            $exists = $select->fetchColumn() !== false;
            $insert->execute([
                'external_id' => $externalId,
                'name' => $name,
                'download_mbps' => $speed,
                'upload_mbps' => $speed,
                'monthly_fee' => $price,
                'radius_group' => $radiusGroup,
                'metadata_json' => $metadata,
            ]);
            $exists ? $updated++ : $created++;
        }
        $processed++;
    }

    return ['total' => $total, 'processed' => $processed, 'created' => $created, 'updated' => $updated];
}

function old_billing_import_payments(PDO $db, string $baseUrl, array &$cookies, bool $apply, int $offset, int $limit, int $batch): array
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
                continue;
            }
            if ($apply) {
                $paymentExists->execute(['external_id' => $externalId]);
                $exists = $paymentExists->fetchColumn() !== false;
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
$batch = max(1, min(5000, (int) old_billing_arg('batch', '500')));
$apply = old_billing_has_flag('apply');
$syncRadius = !old_billing_has_flag('no-radius-sync');
$skipUsers = old_billing_has_flag('skip-users');
$importPackages = old_billing_has_flag('import-packages');
$importPayments = old_billing_has_flag('import-payments');
$importProfiles = old_billing_has_flag('import-profiles');

if ($email === '' || $password === '') {
    fwrite(STDERR, "Usage: OLD_BILLING_EMAIL=... OLD_BILLING_PASSWORD=... php tools/import-old-billing.php [--apply] [--limit=100] [--offset=0] [--batch=500] [--import-packages] [--import-payments] [--import-profiles] [--payment-limit=1000] [--profile-limit=1000] [--skip-users]\n");
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

old_billing_log('Logging in to old billing...');
old_billing_login($baseUrl, $email, $password, $cookies);
old_billing_log('Login OK. Fetching PPPoE users...');

if (!$skipUsers) {
    $first = old_billing_fetch_users($baseUrl, $cookies, $offset, $batch);
    $total = (int) ($first['recordsTotal'] ?? $first['recordsFiltered'] ?? $first['iTotalRecords'] ?? $first['iTotalDisplayRecords'] ?? count((array) ($first['data'] ?? $first['aaData'] ?? [])));
    $target = $limit > 0 ? min($limit, max(0, $total - $offset)) : max(0, $total - $offset);
    old_billing_log('Old billing reports ' . $total . ' users. Offset: ' . $offset . '. Target: ' . $target . '. Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN'));

    for ($start = $offset; $start < $offset + $target; $start += $batch) {
        $list = $start === $offset ? $first : old_billing_fetch_users($baseUrl, $cookies, $start, min($batch, $offset + $target - $start));
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
                $mapped = old_billing_map_customer($row, $edit);
                $preview[] = [
                    'subscriber_code' => $mapped['subscriber_code'],
                    'full_name' => $mapped['full_name'],
                    'username' => $mapped['payload']['billing']['pppoe_username'] ?? '',
                    'phone' => $mapped['phone'],
                    'status' => $mapped['status'],
                ];
                if ($apply) {
                    $existing = app_db_account_by_subscriber_code((string) $mapped['subscriber_code']) ?? app_db_account_by_login((string) $mapped['login']);
                    $radiusPassword = (string) ($mapped['radius_password'] ?? '');
                    unset($mapped['radius_password']);
                    $saved = app_db_upsert_account($mapped, false);
                    if ($saved === null) {
                        throw new RuntimeException('Local account save failed.');
                    }
                    if ($syncRadius && !app_billing_sync_radius_account($saved, $radiusPassword !== '' ? $radiusPassword : null)) {
                        throw new RuntimeException('Radius sync failed.');
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
    old_billing_log('Users done. processed=' . $processed . ' created=' . $created . ' updated=' . $updated . ' failed=' . $failed);
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
    $paymentResult = old_billing_import_payments($db, $baseUrl, $cookies, $apply, $paymentOffset, $paymentLimit, $batch);
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
