<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$options = getopt('', ['limit::', 'worker::']);
$limit = max(1, min(1000, (int) ($options['limit'] ?? 25)));
$worker = trim((string) ($options['worker'] ?? 'billing-outbox-' . gethostname() . '-' . getmypid()));
$summary = ['processed' => 0, 'completed' => 0, 'retrying' => 0, 'manual_required' => 0, 'errors' => []];

for ($index = 0; $index < $limit; $index++) {
    $result = app_billing_payment_process_next($db, $worker);
    if (($result['reason'] ?? '') === 'queue_empty') {
        break;
    }
    $summary['processed']++;
    if (!empty($result['ok'])) {
        $summary['completed']++;
    } elseif (($result['reason'] ?? '') === 'manual_reconciliation_required') {
        $summary['manual_required']++;
    } elseif (($result['reason'] ?? '') === 'service_action_retrying') {
        $summary['retrying']++;
    } else {
        $summary['errors'][] = [
            'action_id' => (int) ($result['action_id'] ?? 0),
            'reason' => (string) ($result['reason'] ?? 'unknown'),
        ];
    }
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($summary['errors'] === [] ? 0 : 1);
