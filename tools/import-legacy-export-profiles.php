<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$options = getopt('', [
    'export-dir::',
    'apply',
    'offset::',
    'limit::',
    'help',
]);

if (isset($options['help'])) {
    echo implode(PHP_EOL, [
        'Usage:',
        '  php tools/import-legacy-export-profiles.php [--export-dir=data/legacy-billing] [--apply] [--offset=0] [--limit=0]',
        '',
        'Imports detailed profile tables from the local old billing export into portal_accounts.payload_json.',
        'Default mode is dry-run. Use --apply to write changes.',
        '',
    ]) . PHP_EOL;
    exit(0);
}

$exportDir = (string) ($options['export-dir'] ?? (dirname(__DIR__) . '/data/legacy-billing'));
$exportDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $exportDir), DIRECTORY_SEPARATOR);
$profilesFile = $exportDir . DIRECTORY_SEPARATOR . 'customers-profiles.jsonl';
$apply = isset($options['apply']);
$offset = max(0, (int) ($options['offset'] ?? 0));
$limit = max(0, (int) ($options['limit'] ?? 0));

function legacy_export_ascii(string $value): string
{
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($converted) && $converted !== '') {
        $value = $converted;
    }

    return strtolower($value);
}

function legacy_export_table_key(array $headers): string
{
    $text = legacy_export_ascii(implode(' | ', array_map('strval', $headers)));

    if (str_contains($text, 'avadanliq')) {
        return 'iptv_services';
    }
    if (str_contains($text, 'nomre') || str_contains($text, 'omr')) {
        return 'sip_services';
    }
    if (preg_match('/(^|[ |])ip([ |]|$)/', $text)) {
        return 'static_ip_services';
    }
    if (str_contains($text, 'port') && str_contains($text, 'qiym')) {
        return 'cable_tv_services';
    }
    if (str_contains($text, 'parametr') && (str_contains($text, 'deyer') || str_contains($text, 'dyr'))) {
        return 'billing_info_table';
    }
    if (str_contains($text, 'teriminal') || str_contains($text, 'terminal')) {
        return 'payment_history';
    }
    if (str_contains($text, 'evvel') || str_contains($text, 'vvl') || str_contains($text, 'mbl')) {
        return 'payment_statement';
    }
    if ((str_contains($text, 'sahe') || str_contains($text, 'sah')) && str_contains($text, 'admin')) {
        return 'customer_logs';
    }
    if (str_contains($text, 'download') && str_contains($text, 'upload')) {
        return 'radius_logs';
    }
    if (str_contains($text, 'tarix') && str_contains($text, 'status') && (str_contains($text, 'netic') || str_contains($text, 'ntic'))) {
        return 'login_attempts';
    }

    return 'other_tables';
}

function legacy_export_normalize_tables(array $tables): array
{
    $sections = [
        'iptv_services' => [],
        'sip_services' => [],
        'static_ip_services' => [],
        'cable_tv_services' => [],
        'billing_info_table' => [],
        'payment_history' => [],
        'payment_statement' => [],
        'customer_logs' => [],
        'radius_logs' => [],
        'login_attempts' => [],
        'other_tables' => [],
    ];

    foreach ($tables as $table) {
        if (!is_array($table)) {
            continue;
        }
        $headers = is_array($table['headers'] ?? null) ? $table['headers'] : [];
        $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
        $key = legacy_export_table_key($headers);
        $sections[$key][] = [
            'headers' => array_values(array_map('strval', $headers)),
            'rows' => array_values(array_filter($rows, 'is_array')),
        ];
    }

    return array_filter($sections, static fn (array $items): bool => $items !== []);
}

function legacy_export_json_decode(?string $value): array
{
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : [];
}

if (!is_file($profilesFile)) {
    fwrite(STDERR, 'Missing export file: ' . $profilesFile . PHP_EOL);
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL/PDO connection is not ready.\n");
    exit(2);
}

$select = $db->prepare(
    "SELECT id, payload_json
     FROM portal_accounts
     WHERE role = 'customer'
       AND subscriber_code = :subscriber_code
     LIMIT 1"
);
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
$sectionsWritten = 0;
$missing = 0;
$errors = 0;
$lineNo = 0;
$targetEnd = $limit > 0 ? $offset + $limit : PHP_INT_MAX;

$handle = fopen($profilesFile, 'rb');
if ($handle === false) {
    fwrite(STDERR, 'Cannot open export file: ' . $profilesFile . PHP_EOL);
    exit(2);
}

while (($line = fgets($handle)) !== false) {
    $lineNo++;
    if ($lineNo <= $offset) {
        continue;
    }
    if ($lineNo > $targetEnd) {
        break;
    }

    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $row = json_decode($line, true);
    if (!is_array($row)) {
        $errors++;
        continue;
    }

    $subscriberCode = trim((string) ($row['customer']['subscriber_code'] ?? ($row['profile']['subscriber_code'] ?? '')));
    if ($subscriberCode === '') {
        $errors++;
        continue;
    }

    $select->execute(['subscriber_code' => $subscriberCode]);
    $account = $select->fetch(PDO::FETCH_ASSOC);
    if (!is_array($account)) {
        $missing++;
        continue;
    }

    $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
    $profileTables = legacy_export_normalize_tables(is_array($profile['tables'] ?? null) ? $profile['tables'] : []);
    $payload = legacy_export_json_decode($account['payload_json'] ?? null);
    $payload['old_billing'] = is_array($payload['old_billing'] ?? null) ? $payload['old_billing'] : [];

    $keepPayloadSections = ['payment_history', 'payment_statement', 'customer_logs', 'radius_logs'];
    $existingTables = is_array($payload['old_billing']['profile_tables'] ?? null)
        ? $payload['old_billing']['profile_tables']
        : [];
    $payload['old_billing']['profile_tables'] = array_intersect_key($existingTables, array_flip($keepPayloadSections));
    unset($payload['old_billing']['profile_tables_full_imported_at'], $payload['old_billing']['export_profile']);
    $payload['old_billing']['export_profile_summary'] = [
        'source' => 'legacy-billing-export',
        'imported_at' => date('c'),
        'balance' => (string) ($profile['balance'] ?? ''),
        'remaining_days' => (string) ($profile['remaining_days'] ?? ''),
        'upload' => (string) ($profile['upload'] ?? ''),
        'download' => (string) ($profile['download'] ?? ''),
        'billing_info' => is_array($profile['billing_info'] ?? null) ? $profile['billing_info'] : [],
        'phone_block' => (string) ($profile['phone_block'] ?? ''),
        'address_block' => (string) ($profile['address_block'] ?? ''),
        'note_block' => (string) ($profile['note_block'] ?? ''),
        'table_sections' => array_keys($profileTables),
    ];

    $processed++;
    if ($apply) {
        $accountId = (int) $account['id'];
        $deleteSections->execute(['account_id' => $accountId]);
        foreach ($profileTables as $sectionKey => $tables) {
            foreach (array_values($tables) as $tableIndex => $table) {
                $headers = is_array($table['headers'] ?? null) ? $table['headers'] : [];
                $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
                $insertSection->execute([
                    'account_id' => $accountId,
                    'subscriber_code' => $subscriberCode,
                    'section_key' => (string) $sectionKey,
                    'section_title' => ucwords(str_replace('_', ' ', (string) $sectionKey)),
                    'table_index' => $tableIndex,
                    'headers_json' => app_db_json_encode(array_values($headers)),
                    'rows_json' => app_db_json_encode(array_values($rows)),
                    'row_count' => count($rows),
                    'source' => 'legacy-billing-export',
                ]);
                $sectionsWritten++;
            }
        }
        $update->execute([
            'id' => $accountId,
            'payload_json' => app_db_json_encode($payload),
        ]);
        $updated++;
    }
}
fclose($handle);

$result = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'processed' => $processed,
    'updated' => $updated,
    'sections_written' => $sectionsWritten,
    'missing_accounts' => $missing,
    'errors' => $errors,
    'offset' => $offset,
    'limit' => $limit,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === 0 ? 0 : 3);
