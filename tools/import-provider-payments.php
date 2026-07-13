<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$options = getopt('', [
    'file:',
    'apply',
    'method::',
    'days::',
    'delimiter::',
    'help',
]);

if (isset($options['help']) || !isset($options['file'])) {
    echo implode(PHP_EOL, [
        'Usage:',
        '  php tools/import-provider-payments.php --file=payments.csv [--apply] [--method=provider] [--days=0] [--delimiter=,]',
        '',
        'Default mode is dry-run. Supported CSV columns:',
        '  subscriber_code, customer_code, abonent_kodu, username, code',
        '  amount, mebleg, payment_amount',
        '  transaction_id, transaction, receipt_no, external_id',
        '  paid_at, created_at, date',
        '',
        'When --days=0, customer default tariff day count is used.',
    ]) . PHP_EOL;
    exit(isset($options['help']) ? 0 : 1);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL/PDO connection is not ready.\n");
    exit(2);
}

$file = (string) $options['file'];
if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "CSV file is not readable: {$file}\n");
    exit(3);
}

$apply = isset($options['apply']);
$method = trim((string) ($options['method'] ?? 'provider'));
$method = $method !== '' ? $method : 'provider';
$days = max(0, min(366, (int) ($options['days'] ?? 0)));
$delimiter = (string) ($options['delimiter'] ?? ',');
$delimiter = $delimiter !== '' ? $delimiter[0] : ',';

$normalizeKey = static function (string $key): string {
    $key = preg_replace('/^\xEF\xBB\xBF/', '', $key) ?? $key;
    $key = strtolower(trim($key));
    $key = str_replace([' ', '-', '.', '/', '\\'], '_', $key);
    return preg_replace('/_+/', '_', $key) ?? $key;
};

$parseAmount = static function (mixed $value): float {
    if (is_numeric($value)) {
        return (float) $value;
    }
    $text = trim((string) $value);
    $text = str_replace(['AZN', 'azn', '₼', ' '], '', $text);
    if (str_contains($text, ',') && str_contains($text, '.')) {
        $text = str_replace(',', '', $text);
    } else {
        $text = str_replace(',', '.', $text);
    }
    return is_numeric($text) ? (float) $text : 0.0;
};

$pick = static function (array $row, array $keys): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') {
            return trim((string) $row[$key]);
        }
    }
    return '';
};

$handle = fopen($file, 'rb');
if ($handle === false) {
    fwrite(STDERR, "CSV file open failed: {$file}\n");
    exit(4);
}

$header = fgetcsv($handle, 0, $delimiter);
if (!is_array($header) || $header === []) {
    fclose($handle);
    fwrite(STDERR, "CSV header is empty.\n");
    exit(5);
}

$columns = array_map(static fn (string $key): string => $normalizeKey($key), $header);
$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'file' => $file,
    'method' => $method,
    'days' => $days,
    'processed' => 0,
    'would_apply' => 0,
    'applied' => 0,
    'duplicates' => 0,
    'missing_customer' => 0,
    'invalid_amount' => 0,
    'failed' => 0,
    'rows' => [],
];

while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
    if ($data === [null] || $data === []) {
        continue;
    }

    $row = [];
    foreach ($columns as $index => $column) {
        $row[$column] = $data[$index] ?? '';
    }

    $summary['processed']++;
    $subscriberCode = $pick($row, ['subscriber_code', 'customer_code', 'abonent_kodu', 'abonent_kod', 'username', 'user_name', 'code', 'kod']);
    $amount = $parseAmount($pick($row, ['amount', 'mebleg', 'payment_amount', 'odenis', 'odeme', 'sum']));
    $transactionId = $pick($row, ['transaction_id', 'transaction', 'receipt_no', 'receipt', 'provider_tx', 'tx']);
    $externalId = $pick($row, ['external_id', 'provider_id']);
    $paidAt = $pick($row, ['paid_at', 'created_at', 'date', 'tarix', 'payment_date']);
    $createdAt = $paidAt !== '' && strtotime($paidAt) !== false ? date('Y-m-d H:i:s', strtotime($paidAt)) : null;

    if ($externalId === '') {
        $externalId = hash('sha256', implode('|', [$method, $transactionId, $subscriberCode, number_format($amount, 2, '.', ''), $createdAt ?? '']));
    }

    $rowResult = [
        'line' => $summary['processed'] + 1,
        'subscriber_code' => $subscriberCode,
        'amount' => $amount,
        'transaction_id' => $transactionId,
        'external_id' => $externalId,
        'status' => 'pending',
    ];

    if ($subscriberCode === '' || app_db_account_by_subscriber_code($subscriberCode) === null) {
        $summary['missing_customer']++;
        $rowResult['status'] = 'missing_customer';
        $summary['rows'][] = $rowResult;
        continue;
    }

    if ($amount <= 0) {
        $summary['invalid_amount']++;
        $rowResult['status'] = 'invalid_amount';
        $summary['rows'][] = $rowResult;
        continue;
    }

    if (app_billing_payment_external_exists($externalId)) {
        $summary['duplicates']++;
        $rowResult['status'] = 'duplicate';
        $summary['rows'][] = $rowResult;
        continue;
    }

    if (!$apply) {
        $summary['would_apply']++;
        $rowResult['status'] = 'would_apply';
        $summary['rows'][] = $rowResult;
        continue;
    }

    $ok = app_billing_apply_customer_payment($subscriberCode, $amount, $method, $days, $transactionId, $externalId, $createdAt);
    if ($ok) {
        $summary['applied']++;
        $rowResult['status'] = 'applied';
    } else {
        $summary['failed']++;
        $rowResult['status'] = 'failed';
    }
    $summary['rows'][] = $rowResult;
}

fclose($handle);

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
