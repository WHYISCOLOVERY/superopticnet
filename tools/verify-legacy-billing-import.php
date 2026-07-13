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
    'json',
    'show-samples',
    'help',
]);

if (isset($options['help'])) {
    echo implode(PHP_EOL, [
        'Usage:',
        '  php tools/verify-legacy-billing-import.php [--export-dir=data/legacy-billing] [--json] [--show-samples]',
        '',
        'Checks old billing export files against the local MySQL import without printing customer PII by default.',
        '--json          Output machine-readable JSON only.',
        '--show-samples  Include subscriber-code samples for missing/extra rows.',
        '',
    ]) . PHP_EOL;
    exit(0);
}

$exportDir = (string) ($options['export-dir'] ?? (dirname(__DIR__) . '/data/legacy-billing'));
$exportDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $exportDir), DIRECTORY_SEPARATOR);
$baseFile = $exportDir . DIRECTORY_SEPARATOR . 'customers-base.json';
$profilesFile = $exportDir . DIRECTORY_SEPARATOR . 'customers-profiles.jsonl';
$jsonOnly = isset($options['json']);
$showSamples = isset($options['show-samples']);

function verify_read_json_file(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing file: ' . $path);
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON file: ' . $path);
    }

    return $data;
}

function verify_safe_code(mixed $value): string
{
    return trim((string) $value);
}

try {
    $base = verify_read_json_file($baseFile);
    $sourceCodes = [];
    foreach (($base['customers'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = verify_safe_code($row['subscriber_code'] ?? '');
        if ($code !== '') {
            $sourceCodes[$code] = true;
        }
    }

    if (!is_file($profilesFile)) {
        throw new RuntimeException('Missing file: ' . $profilesFile);
    }

    $profileLines = 0;
    $profileParseErrors = 0;
    $profileErrors = 0;
    $profileCodes = [];
    $profileRowsWithTables = 0;

    $handle = fopen($profilesFile, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Cannot open file: ' . $profilesFile);
    }

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $profileLines++;
        $row = json_decode($line, true);
        if (!is_array($row)) {
            $profileParseErrors++;
            continue;
        }

        if (!empty($row['error'])) {
            $profileErrors++;
        }

        $code = verify_safe_code($row['customer']['subscriber_code'] ?? ($row['profile']['subscriber_code'] ?? ''));
        if ($code !== '') {
            $profileCodes[$code] = true;
        }

        if (is_array($row['profile']['tables'] ?? null) && count($row['profile']['tables']) > 0) {
            $profileRowsWithTables++;
        }
    }
    fclose($handle);

    $db = app_db();
    if (!$db instanceof PDO) {
        throw new RuntimeException('MySQL/PDO connection is not ready.');
    }

    $dbRows = $db->query(
        "SELECT subscriber_code, external_id
         FROM portal_accounts
         WHERE role = 'customer'
           AND external_id LIKE 'old-billing:%'"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dbCodes = [];
    foreach ($dbRows as $row) {
        $code = verify_safe_code($row['subscriber_code'] ?? '');
        if ($code !== '') {
            $dbCodes[$code] = true;
        }
    }

    $sectionStats = [];
    $sectionRows = $db->query(
        'SELECT section_key,
                COUNT(*) AS table_count,
                COUNT(DISTINCT account_id) AS account_count,
                COALESCE(SUM(row_count), 0) AS row_count
         FROM legacy_billing_profile_sections
         GROUP BY section_key'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($sectionRows as $row) {
        $sectionStats[(string) ($row['section_key'] ?? '')] = [
            'accounts' => (int) ($row['account_count'] ?? 0),
            'tables' => (int) ($row['table_count'] ?? 0),
            'rows' => (int) ($row['row_count'] ?? 0),
        ];
    }

    $dbFullProfileImports = (int) $db->query('SELECT COUNT(DISTINCT account_id) FROM legacy_billing_profile_sections')->fetchColumn();
    $dbPayloads = (int) $db->query(
        "SELECT COUNT(*)
         FROM portal_accounts
         WHERE role = 'customer'
           AND external_id LIKE 'old-billing:%'
           AND payload_json LIKE '%\"old_billing\"%'"
    )->fetchColumn();

    $missingInDb = array_values(array_diff(array_keys($sourceCodes), array_keys($dbCodes)));
    $extraInDb = array_values(array_diff(array_keys($dbCodes), array_keys($sourceCodes)));
    $profilesMissingForBase = array_values(array_diff(array_keys($sourceCodes), array_keys($profileCodes)));

    $totalCustomers = (int) $db->query("SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer'")->fetchColumn();
    $oldPayments = (int) $db->query("SELECT COUNT(*) FROM billing_payments WHERE external_id LIKE 'old-billing-payment:%'")->fetchColumn();
    $oldTariffs = (int) $db->query("SELECT COUNT(*) FROM billing_tariffs WHERE external_id LIKE 'old-billing-package:%'")->fetchColumn();

    $result = [
        'checked_at' => date('c'),
        'export_dir' => $exportDir,
        'source' => [
            'declared_customers' => (int) ($base['count'] ?? 0),
            'customer_rows' => count($sourceCodes),
            'profile_lines' => $profileLines,
            'profile_unique_codes' => count($profileCodes),
            'profile_parse_errors' => $profileParseErrors,
            'profile_errors' => $profileErrors,
            'profile_rows_with_tables' => $profileRowsWithTables,
        ],
        'database' => [
            'total_customers' => $totalCustomers,
            'old_customers' => count($dbCodes),
            'old_customers_with_payload' => $dbPayloads,
            'old_customers_with_profile_tables' => $dbFullProfileImports,
            'old_customers_with_full_profile_sections' => $dbFullProfileImports,
            'old_payments' => $oldPayments,
            'old_tariffs' => $oldTariffs,
            'profile_table_sections' => [
                'iptv_services' => $sectionStats['iptv_services']['accounts'] ?? 0,
                'sip_services' => $sectionStats['sip_services']['accounts'] ?? 0,
                'static_ip_services' => $sectionStats['static_ip_services']['accounts'] ?? 0,
                'cable_tv_services' => $sectionStats['cable_tv_services']['accounts'] ?? 0,
                'billing_info_table' => $sectionStats['billing_info_table']['accounts'] ?? 0,
                'payment_history' => $sectionStats['payment_history']['accounts'] ?? 0,
                'payment_statement' => $sectionStats['payment_statement']['accounts'] ?? 0,
                'customer_logs' => $sectionStats['customer_logs']['accounts'] ?? 0,
                'radius_logs_or_connection_history' => $sectionStats['radius_logs']['accounts'] ?? 0,
                'login_attempts' => $sectionStats['login_attempts']['accounts'] ?? 0,
            ],
            'profile_table_rows' => $sectionStats,
        ],
        'diff' => [
            'missing_customers_in_db' => count($missingInDb),
            'extra_old_customers_in_db' => count($extraInDb),
            'profiles_missing_for_base_customers' => count($profilesMissingForBase),
        ],
        'ok' => count($missingInDb) === 0
            && $profileParseErrors === 0
            && $profileErrors === 0
            && count($profilesMissingForBase) === 0,
    ];

    if ($showSamples) {
        $result['samples'] = [
            'missing_customers_in_db' => array_slice($missingInDb, 0, 20),
            'extra_old_customers_in_db' => array_slice($extraInDb, 0, 20),
            'profiles_missing_for_base_customers' => array_slice($profilesMissingForBase, 0, 20),
        ];
    }

    if ($jsonOnly) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo 'Legacy billing import verification' . PHP_EOL;
        echo 'Export customers: ' . $result['source']['customer_rows'] . PHP_EOL;
        echo 'Export profiles: ' . $result['source']['profile_lines'] . ' / unique ' . $result['source']['profile_unique_codes'] . PHP_EOL;
        echo 'DB old customers: ' . $result['database']['old_customers'] . PHP_EOL;
        echo 'DB old profile tables: ' . $result['database']['old_customers_with_profile_tables'] . PHP_EOL;
        echo 'DB old payments: ' . $result['database']['old_payments'] . PHP_EOL;
        echo 'DB old tariffs: ' . $result['database']['old_tariffs'] . PHP_EOL;
        echo 'Missing customers in DB: ' . $result['diff']['missing_customers_in_db'] . PHP_EOL;
        echo 'Profiles missing for base customers: ' . $result['diff']['profiles_missing_for_base_customers'] . PHP_EOL;
        echo 'Parse/profile errors: ' . $result['source']['profile_parse_errors'] . '/' . $result['source']['profile_errors'] . PHP_EOL;
        echo 'Status: ' . ($result['ok'] ? 'OK' : 'CHECK_REQUIRED') . PHP_EOL;
    }

    exit($result['ok'] ? 0 : 4);
} catch (Throwable $exception) {
    $error = [
        'ok' => false,
        'error' => $exception->getMessage(),
    ];

    if ($jsonOnly) {
        echo json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, 'Legacy billing verification failed: ' . $exception->getMessage() . PHP_EOL);
    }

    exit(2);
}
