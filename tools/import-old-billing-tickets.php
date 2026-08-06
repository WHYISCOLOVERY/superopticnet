<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function legacy_ticket_arg(string $name, ?string $default = null): ?string
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

function legacy_ticket_flag(string $flag): bool
{
    global $argv;
    return in_array('--' . $flag, array_map('strval', $argv), true);
}

function legacy_ticket_request(string $method, string $url, array $data = [], array &$cookies = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required.');
    }

    $cookiePairs = [];
    foreach ($cookies as $name => $value) {
        $cookiePairs[] = $name . '=' . $value;
    }

    $headers = [
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json, text/javascript, text/html, */*; q=0.01',
    ];
    if ($cookiePairs !== []) {
        $headers[] = 'Cookie: ' . implode('; ', $cookiePairs);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 SuperOptic-Legacy-Ticket-Import/1.0',
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

    $headersText = substr((string) $raw, 0, $headerSize);
    foreach (preg_split('/\r\n|\n|\r/', $headersText) ?: [] as $line) {
        if (stripos($line, 'Set-Cookie:') !== 0) {
            continue;
        }
        $cookie = trim(substr($line, strlen('Set-Cookie:')));
        [$pair] = explode(';', $cookie, 2);
        [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
        if ($name !== '') {
            $cookies[$name] = $value;
        }
    }

    return ['status' => $status, 'body' => substr((string) $raw, $headerSize)];
}

function legacy_ticket_json_request(string $method, string $url, array $data, array &$cookies): array
{
    $response = legacy_ticket_request($method, $url, $data, $cookies);
    $decoded = json_decode((string) $response['body'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON from ' . $url . ' status=' . $response['status']);
    }

    return $decoded;
}

function legacy_ticket_html_text(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function legacy_ticket_cut(string $value, int $limit): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    return substr($value, 0, $limit);
}

function legacy_ticket_normalize_key(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $map = [
        'ə' => 'e', 'ö' => 'o', 'ü' => 'u', 'ı' => 'i', 'ğ' => 'g', 'ş' => 's', 'ç' => 'c',
        'Ə' => 'e', 'Ö' => 'o', 'Ü' => 'u', 'İ' => 'i', 'I' => 'i', 'Ğ' => 'g', 'Ş' => 's', 'Ç' => 'c',
        'ə' => 'e', 'ö' => 'o', 'ü' => 'u', 'ı' => 'i', 'ğ' => 'g', 'ş' => 's', 'ç' => 'c',
        'Ə' => 'e', 'Ö' => 'o', 'Ü' => 'u', 'I' => 'i', 'Ğ' => 'g', 'Ş' => 's', 'Ç' => 'c',
    ];
    $value = strtr($value, $map);
    return preg_replace('/[^a-z0-9]+/i', '', $value) ?? '';
}

function legacy_ticket_strip_phone_numbers(string $text): string
{
    return preg_replace('/(?:\+?994|0)(?:10|50|51|55|60|70|77)[\s\-.]?\d{3}[\s\-.]?\d{2}[\s\-.]?\d{2}/u', ' ', $text) ?? $text;
}

function legacy_ticket_extract_first_html_id(string $html): string
{
    if (preg_match('/\bid=["\']?([0-9]+)["\']?/i', $html, $match)) {
        return $match[1];
    }

    return '';
}

function legacy_ticket_login(string $baseUrl, string $email, string $password, array &$cookies): void
{
    $loginPage = legacy_ticket_request('GET', rtrim($baseUrl, '/') . '/index.php', [], $cookies);
    if (!preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/i', (string) $loginPage['body'], $match)) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    $response = legacy_ticket_json_request('POST', rtrim($baseUrl, '/') . '/core/main/funksiya.php', [
        'csrf_token' => html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'email' => $email,
        'pass' => $password,
    ], $cookies);

    if (($response['status'] ?? '') !== 'success') {
        throw new RuntimeException('Old billing login failed.');
    }
}

function legacy_ticket_fetch_page(string $baseUrl, array &$cookies, int $start, int $length): array
{
    $payload = [
        'draw' => 1,
        'start' => $start,
        'length' => $length,
        'ticket_search' => '',
        'ticket_cotumer_s' => '',
        'ticket_type_s' => '',
        'ticket_type_status' => '',
        'ticket_zone_s' => '',
        'ticket_company' => '',
        'search[value]' => '',
        'search[regex]' => 'false',
    ];
    foreach (['id', 'zone', 'costumer', 'type', 'subject', 'close', 'status'] as $index => $column) {
        $payload["columns[$index][data]"] = $column;
        $payload["columns[$index][name]"] = '';
        $payload["columns[$index][searchable]"] = 'true';
        $payload["columns[$index][orderable]"] = 'false';
        $payload["columns[$index][search][value]"] = '';
        $payload["columns[$index][search][regex]"] = 'false';
    }

    return legacy_ticket_json_request('POST', rtrim($baseUrl, '/') . '/backend/ticket/table/ppp_ticket.php', $payload, $cookies);
}

function legacy_ticket_accounts(PDO $db): array
{
    $rows = $db->query(
        'SELECT id, subscriber_code, contract_no, full_name, phone, address, area, account_type, role, employee_role
         FROM portal_accounts'
    )->fetchAll();

    $customersByCode = [];
    $employees = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['subscriber_code'] ?? '') !== '') {
            $customersByCode[(string) $row['subscriber_code']] = $row;
        }
        $accountType = (string) ($row['account_type'] ?? '');
        $role = (string) ($row['role'] ?? '');
        $employeeRole = (string) ($row['employee_role'] ?? '');
        if ($accountType === 'employee' || ($accountType !== 'customer' && $role !== 'customer') || $employeeRole !== '') {
            $employees[] = $row;
        }
    }

    return [$customersByCode, $employees];
}

function legacy_ticket_find_customer_code(string $text, array $customersByCode): string
{
    $textWithoutPhones = legacy_ticket_strip_phone_numbers($text);
    preg_match_all('/\b\d{5,10}\b/', $textWithoutPhones, $matches);
    foreach ($matches[0] ?? [] as $candidate) {
        if (isset($customersByCode[$candidate])) {
            return $candidate;
        }
    }

    foreach ($matches[0] ?? [] as $candidate) {
        if (!preg_match('/^(?:050|051|055|070|077|010|060)/', $candidate)) {
            return $candidate;
        }
    }

    $digitsOnly = preg_replace('/\D+/', '', $textWithoutPhones) ?? '';
    if ($digitsOnly !== '') {
        $codes = array_keys($customersByCode);
        usort($codes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($codes as $rawCode) {
            $code = (string) $rawCode;
            if ($code !== '' && str_contains($digitsOnly, $code)) {
                return $code;
            }
        }
    }

    return '';
}

function legacy_ticket_find_phone(string $text): string
{
    if (preg_match('/(?:\+?994|0)(?:10|50|51|55|60|70|77)[\s\-.]?\d{3}[\s\-.]?\d{2}[\s\-.]?\d{2}/u', $text, $match)) {
        return preg_replace('/[^\d+]+/', '', $match[0]) ?? $match[0];
    }

    return '';
}

function legacy_ticket_find_employee(string $name, array $employees): array
{
    $needle = legacy_ticket_normalize_key($name);
    if ($needle === '') {
        return [null, $name];
    }

    foreach ($employees as $employee) {
        $key = legacy_ticket_normalize_key((string) ($employee['full_name'] ?? ''));
        if ($key !== '' && $key === $needle) {
            return [$employee, (string) ($employee['full_name'] ?? $name)];
        }
    }

    foreach ($employees as $employee) {
        $key = legacy_ticket_normalize_key((string) ($employee['full_name'] ?? ''));
        if ($key !== '' && (str_contains($key, $needle) || str_contains($needle, $key))) {
            return [$employee, (string) ($employee['full_name'] ?? $name)];
        }
    }

    $needleTokens = preg_split('/\s+/u', trim($name)) ?: [];
    $needleTokens = array_values(array_filter(array_map(
        static fn(string $token): string => legacy_ticket_normalize_key($token),
        $needleTokens
    ), static fn(string $token): bool => strlen($token) >= 5));
    if ($needleTokens !== []) {
        foreach ($employees as $employee) {
            $employeeTokens = preg_split('/\s+/u', trim((string) ($employee['full_name'] ?? ''))) ?: [];
            $employeeTokens = array_map(
                static fn(string $token): string => legacy_ticket_normalize_key($token),
                $employeeTokens
            );
            foreach ($needleTokens as $token) {
                if (in_array($token, $employeeTokens, true)) {
                    return [$employee, (string) ($employee['full_name'] ?? $name)];
                }
            }
        }
    }

    return [null, $name];
}

function legacy_ticket_parse_opened_at(string $zoneText): string
{
    if (preg_match('/(20\d{2}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})/', $zoneText, $match)) {
        return $match[1] . ' ' . $match[2];
    }

    return app_db_now();
}

function legacy_ticket_parse_area(string $zoneText): string
{
    $area = preg_replace('/20\d{2}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}.*/s', '', $zoneText) ?? $zoneText;
    return trim($area);
}

function legacy_ticket_area_from_numeric_id(string $area): string
{
    if (!preg_match('/^\d+$/', $area)) {
        return '';
    }

    return 'Legacy zona ' . $area;
}

function legacy_ticket_area_from_subscriber_code(string $subscriberCode): string
{
    $prefixMap = [
        '100' => 'Qaraçuxur',
        '110' => 'Biləcəri',
        '200' => 'Şahağacı',
        '232' => 'Kürdəxanı / Zabrat',
        '300' => 'Astara',
        '400' => 'Masallı',
        '500' => 'Lənkəran',
        '600' => 'Neftçala',
    ];
    foreach ($prefixMap as $rawPrefix => $area) {
        $prefix = (string) $rawPrefix;
        if (str_starts_with($subscriberCode, $prefix)) {
            return $area;
        }
    }

    return '';
}

function legacy_ticket_area_from_text(string $text): string
{
    $normalized = legacy_ticket_normalize_key($text);
    $keywordMap = [
        'Qaraçuxur' => ['qaracuxur', 'qaracxur', 'qarcuxur', 'ferman', 'kerimzade', 'keremismayilov', 'abbasqulu', 'sadlinski', 'gunasli', 'gunesli', 'suraxani', 'vagzal', 'nefciler'],
        'Astara' => ['astara', 'ercivan', 'artuba', 'artupa', 'siyaku', 'semedvurgun', 'nurupasa', 'nuru'],
        'Kürdəxanı / Zabrat' => ['kurdexani', 'kurddexani', 'pirsagi', 'savxoz', 'zabrat'],
        'Şahağacı' => ['sahagaci', 'sahagac'],
        'Masallı' => ['masalli'],
        'Lənkəran' => ['lenkeran', 'lankaran'],
        'Biləcəri' => ['bileceri'],
        'Neftçala' => ['neftcala', 'neftchala'],
    ];
    foreach ($keywordMap as $area => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return $area;
            }
        }
    }

    return '';
}

function legacy_ticket_parse_status(string $statusHtml): string
{
    $status = legacy_ticket_html_text($statusHtml);
    $statusRaw = legacy_ticket_normalize_key($status);
    $htmlRaw = legacy_ticket_normalize_key($statusHtml);
    if (str_contains($statusRaw, 'aciq')) {
        return 'Aciqdir';
    }
    if (str_contains($statusRaw, 'baglan') || str_contains($statusRaw, 'bagli') || str_contains($htmlRaw, 'bgsuccess') || str_contains($htmlRaw, 'reloadticket')) {
        return 'Baglandi';
    }
    if (str_contains($statusRaw, 'tesdiq')) {
        return 'Tesdiqlendi';
    }

    return 'Aciqdir';
}

function legacy_ticket_to_record(array $row, array $customersByCode, array $employees): ?array
{
    $oldId = legacy_ticket_html_text((string) ($row['id'] ?? ''));
    $idFromStatus = legacy_ticket_extract_first_html_id((string) ($row['status'] ?? ''));
    if ($idFromStatus !== '') {
        $oldId = $idFromStatus;
    }
    if ($oldId === '' || !preg_match('/^\d+$/', $oldId)) {
        $oldId = $idFromStatus;
    }
    if ($oldId === '') {
        return null;
    }

    $zoneText = legacy_ticket_html_text((string) ($row['zone'] ?? ''));
    $employeeText = legacy_ticket_html_text((string) ($row['costumer'] ?? ''));
    $problemText = legacy_ticket_html_text((string) ($row['type'] ?? ''));
    $messageText = legacy_ticket_html_text((string) ($row['subject'] ?? ''));
    $closeText = legacy_ticket_html_text((string) ($row['close'] ?? ''));
    $status = legacy_ticket_parse_status((string) ($row['status'] ?? ''));
    $openedAt = legacy_ticket_parse_opened_at($zoneText);
    $area = legacy_ticket_parse_area($zoneText);
    $subscriberCode = legacy_ticket_find_customer_code($messageText, $customersByCode);
    $customer = $subscriberCode !== '' && isset($customersByCode[$subscriberCode]) ? $customersByCode[$subscriberCode] : null;
    $phone = legacy_ticket_find_phone($messageText);
    if ($phone === '' && is_array($customer)) {
        $phone = (string) ($customer['phone'] ?? '');
    }
    $customerArea = is_array($customer) ? (string) ($customer['area'] ?? '') : '';
    $resolvedArea = $area;
    if ($customerArea !== '' && ($resolvedArea === '' || preg_match('/^\d+$/', $resolvedArea))) {
        $resolvedArea = $customerArea;
    }
    if ($resolvedArea === '' || preg_match('/^\d+$/', $resolvedArea)) {
        $prefixArea = legacy_ticket_area_from_subscriber_code($subscriberCode);
        if ($prefixArea !== '') {
            $resolvedArea = $prefixArea;
        }
    }
    if ($resolvedArea === '' || preg_match('/^\d+$/', $resolvedArea)) {
        $textArea = legacy_ticket_area_from_text($messageText);
        if ($textArea !== '') {
            $resolvedArea = $textArea;
        }
    }
    if ($resolvedArea === '' || preg_match('/^\d+$/', $resolvedArea)) {
        $legacyArea = legacy_ticket_area_from_numeric_id($area);
        if ($legacyArea !== '') {
            $resolvedArea = $legacyArea;
        }
    }
    [$employee, $employeeName] = legacy_ticket_find_employee($employeeText, $employees);
    $createdBy = '';
    if (preg_match('/Yaradan\s*:\s*([^0-9]+)/u', $messageText, $match)) {
        $createdBy = trim(preg_replace('/\s+/u', ' ', $match[1]) ?? $match[1]);
    }

    $resolvedAt = null;
    if ($status === 'Baglandi' && preg_match('/(20\d{2}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})/', $closeText, $match)) {
        $resolvedAt = $match[1] . ' ' . $match[2];
    }

    $metadata = [
        'legacy_source' => 'old_billing_ticket',
        'old_ticket_id' => $oldId,
        'raw' => $row,
        'raw_text' => [
            'zone' => $zoneText,
            'employee' => $employeeText,
            'problem' => $problemText,
            'message' => $messageText,
            'close' => $closeText,
            'status' => legacy_ticket_html_text((string) ($row['status'] ?? '')),
        ],
        'parsed' => [
            'subscriber_code' => $subscriberCode,
            'phone' => $phone,
            'created_by' => $createdBy,
        ],
        'imported_at' => date('c'),
    ];

    return [
        'ticket_no' => 'LEGACY-TICKET-' . $oldId,
        'customer_account_id' => is_array($customer) ? (int) ($customer['id'] ?? 0) : null,
        'subscriber_code' => $subscriberCode !== '' ? $subscriberCode : (is_array($customer) ? (string) ($customer['subscriber_code'] ?? '') : ''),
        'contract_no' => is_array($customer) ? (string) ($customer['contract_no'] ?? '') : '',
        'customer_name' => is_array($customer) ? (string) ($customer['full_name'] ?? '') : 'Legacy ticket customer',
        'phone' => $phone,
        'address' => is_array($customer) ? (string) ($customer['address'] ?? '') : '',
        'area' => $resolvedArea !== '' ? $resolvedArea : $area,
        'subject' => $problemText !== '' ? legacy_ticket_cut($problemText, 128) : 'Legacy ticket',
        'message' => legacy_ticket_cut($messageText !== '' ? $messageText : 'Legacy ticket import', 255),
        'status' => $status,
        'opened_at' => $openedAt,
        'updated_at' => $resolvedAt ?? $openedAt,
        'resolved_at' => $resolvedAt,
        'assigned_technician_account_id' => is_array($employee) ? (int) ($employee['id'] ?? 0) : null,
        'assigned_technician_name' => legacy_ticket_cut($employeeName, 191),
        'resolution_reason' => $status === 'Baglandi' ? 'legacy_closed' : '',
        'resolution_note' => $status === 'Baglandi' ? legacy_ticket_cut($closeText, 255) : '',
        'handled_by' => $status === 'Baglandi' ? legacy_ticket_cut($employeeName, 191) : '',
        'created_by_account_id' => null,
        'created_by_role' => 'legacy_billing',
        'created_by_name' => legacy_ticket_cut($createdBy, 191),
        'telegram_message_ids_json' => app_db_json_encode([]),
        'metadata_json' => app_db_json_encode($metadata),
    ];
}

function legacy_ticket_upsert(PDO $db, array $ticket): string
{
    $exists = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
    $exists->execute(['ticket_no' => $ticket['ticket_no']]);
    $existingId = $exists->fetchColumn();

    $sql = 'INSERT INTO portal_tickets (
            ticket_no, customer_account_id, subscriber_code, contract_no, customer_name, phone, address, area,
            subject, message, status, opened_at, updated_at, resolved_at, assigned_technician_account_id,
            assigned_technician_name, resolution_reason, resolution_note, handled_by, created_by_account_id,
            created_by_role, created_by_name, telegram_message_ids_json, metadata_json
        ) VALUES (
            :ticket_no, :customer_account_id, :subscriber_code, :contract_no, :customer_name, :phone, :address, :area,
            :subject, :message, :status, :opened_at, :updated_at, :resolved_at, :assigned_technician_account_id,
            :assigned_technician_name, :resolution_reason, :resolution_note, :handled_by, :created_by_account_id,
            :created_by_role, :created_by_name, :telegram_message_ids_json, :metadata_json
        )
        ON DUPLICATE KEY UPDATE
            customer_account_id = VALUES(customer_account_id),
            subscriber_code = VALUES(subscriber_code),
            contract_no = VALUES(contract_no),
            customer_name = VALUES(customer_name),
            phone = VALUES(phone),
            address = VALUES(address),
            area = VALUES(area),
            subject = VALUES(subject),
            message = VALUES(message),
            status = VALUES(status),
            opened_at = VALUES(opened_at),
            updated_at = VALUES(updated_at),
            resolved_at = VALUES(resolved_at),
            assigned_technician_account_id = VALUES(assigned_technician_account_id),
            assigned_technician_name = VALUES(assigned_technician_name),
            resolution_reason = VALUES(resolution_reason),
            resolution_note = VALUES(resolution_note),
            handled_by = VALUES(handled_by),
            created_by_account_id = VALUES(created_by_account_id),
            created_by_role = VALUES(created_by_role),
            created_by_name = VALUES(created_by_name),
            telegram_message_ids_json = VALUES(telegram_message_ids_json),
            metadata_json = VALUES(metadata_json)';

    $statement = $db->prepare($sql);
    $statement->execute($ticket);

    if ($existingId === false) {
        app_db_add_ticket_event((int) $db->lastInsertId(), 'legacy_imported', (string) ($ticket['message'] ?? ''), 0, 'Old billing import', [
            'ticket_no' => $ticket['ticket_no'],
        ]);
        return 'created';
    }

    return 'updated';
}

$baseUrl = legacy_ticket_arg('base-url', 'https://billing.superoptic.net') ?? 'https://billing.superoptic.net';
$email = legacy_ticket_arg('email', '') ?? '';
$password = legacy_ticket_arg('password', '') ?? '';
$limit = max(0, (int) (legacy_ticket_arg('limit', '20') ?? '20'));
$batch = max(1, (int) (legacy_ticket_arg('batch', '250') ?? '250'));
$apply = legacy_ticket_flag('apply');

if ($email === '' || $password === '') {
    fwrite(STDERR, "Set OLD_BILLING_EMAIL and OLD_BILLING_PASSWORD or pass --email/--password.\n");
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is not available.\n");
    exit(2);
}

$cookies = [];
legacy_ticket_login($baseUrl, $email, $password, $cookies);
[$customersByCode, $employees] = legacy_ticket_accounts($db);

$first = legacy_ticket_fetch_page($baseUrl, $cookies, 0, min($batch, $limit > 0 ? $limit : $batch));
$rows = is_array($first['aaData'] ?? null) ? $first['aaData'] : (is_array($first['data'] ?? null) ? $first['data'] : []);
$total = (int) ($first['iTotalRecords'] ?? $first['recordsTotal'] ?? count($rows));
$target = $limit > 0 ? min($limit, $total) : $total;

$summary = [
    'ok' => true,
    'apply' => $apply,
    'old_total' => $total,
    'target' => $target,
    'processed' => 0,
    'created' => 0,
    'updated' => 0,
    'skipped' => 0,
    'linked_customers' => 0,
    'unlinked_customers' => 0,
    'codes_without_local_customer' => 0,
    'linked_employees' => 0,
    'open' => 0,
    'closed' => 0,
    'samples' => [],
];

for ($start = 0; $start < $target; $start += $batch) {
    if ($start === 0) {
        $pageRows = $rows;
    } else {
        $page = legacy_ticket_fetch_page($baseUrl, $cookies, $start, min($batch, $target - $start));
        $pageRows = is_array($page['aaData'] ?? null) ? $page['aaData'] : (is_array($page['data'] ?? null) ? $page['data'] : []);
    }

    foreach ($pageRows as $row) {
        if (!is_array($row) || $summary['processed'] >= $target) {
            continue;
        }
        $ticket = legacy_ticket_to_record($row, $customersByCode, $employees);
        if ($ticket === null) {
            $summary['skipped']++;
            continue;
        }
        $summary['processed']++;
        if (!empty($ticket['customer_account_id'])) {
            $summary['linked_customers']++;
        } else {
            $summary['unlinked_customers']++;
            if (trim((string) ($ticket['subscriber_code'] ?? '')) !== '') {
                $summary['codes_without_local_customer']++;
            }
        }
        if (!empty($ticket['assigned_technician_account_id'])) {
            $summary['linked_employees']++;
        }
        if ($ticket['status'] === 'Baglandi') {
            $summary['closed']++;
        } else {
            $summary['open']++;
        }
        if (count($summary['samples']) < 5) {
            $summary['samples'][] = [
                'ticket_no' => $ticket['ticket_no'],
                'subscriber_code' => $ticket['subscriber_code'],
                'phone' => $ticket['phone'],
                'area' => $ticket['area'],
                'employee' => $ticket['assigned_technician_name'],
                'subject' => $ticket['subject'],
                'status' => $ticket['status'],
                'opened_at' => $ticket['opened_at'],
            ];
        }
        if (!$apply) {
            continue;
        }
        $result = legacy_ticket_upsert($db, $ticket);
        if ($result === 'created') {
            $summary['created']++;
        } else {
            $summary['updated']++;
        }
    }
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
