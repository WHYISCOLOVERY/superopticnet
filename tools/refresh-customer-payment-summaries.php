<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$rows = $db->query(
    "SELECT a.id, a.payload_json, COUNT(p.id) AS payment_count,
            COALESCE(SUM(p.amount),0) AS payment_total, MAX(p.created_at) AS last_payment_at
     FROM portal_accounts a
     LEFT JOIN billing_payments p ON p.account_id = a.id
     WHERE a.role = 'customer'
     GROUP BY a.id, a.payload_json"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$update = $db->prepare('UPDATE portal_accounts SET payload_json = :payload_json WHERE id = :id');
$updated = 0;
foreach ($rows as $row) {
    $payload = app_db_json_decode((string) ($row['payload_json'] ?? ''), []);
    $payload['billing'] = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $payload['billing']['payment_count'] = (int) ($row['payment_count'] ?? 0);
    $payload['billing']['payment_total'] = number_format((float) ($row['payment_total'] ?? 0), 2, '.', '');
    $payload['billing']['last_payment_at'] = (string) ($row['last_payment_at'] ?? '');
    $update->execute([
        'payload_json' => app_db_json_encode($payload),
        'id' => (int) $row['id'],
    ]);
    $updated++;
}

echo json_encode([
    'ok' => true,
    'updated_customers' => $updated,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
