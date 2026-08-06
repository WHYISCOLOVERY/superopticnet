<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/audit-log-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

function legacy_payment_repair_arg(string $name): string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $argument) {
        if (str_starts_with((string) $argument, $prefix)) {
            return trim(substr((string) $argument, strlen($prefix)));
        }
    }
    return '';
}

$paymentId = legacy_payment_repair_arg('payment-id');
$subscriberCode = legacy_payment_repair_arg('subscriber-code');
$reason = legacy_payment_repair_arg('reason');
if ($paymentId === '' || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $paymentId) !== 1
    || $subscriberCode === '' || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $subscriberCode) !== 1
    || mb_strlen($reason, 'UTF-8') < 10) {
    fwrite(STDERR, "Usage: php tools/repair-legacy-payment-link.php --payment-id=... --subscriber-code=... --reason='...'\n");
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$externalId = str_starts_with($paymentId, 'old-billing-payment:')
    ? $paymentId
    : 'old-billing-payment:' . $paymentId;
$paymentQuery = $db->prepare('SELECT * FROM billing_payments WHERE external_id = :external_id LIMIT 1');
$paymentQuery->execute(['external_id' => $externalId]);
$payment = $paymentQuery->fetch(PDO::FETCH_ASSOC);
if (!is_array($payment)) {
    fwrite(STDERR, "Payment was not found.\n");
    exit(3);
}

$targetQuery = $db->prepare(
    'SELECT * FROM portal_accounts
     WHERE role = "customer" AND (subscriber_code = :code OR login = :code)
     ORDER BY (subscriber_code = :code) DESC, id
     LIMIT 1'
);
$targetQuery->execute(['code' => $subscriberCode]);
$target = $targetQuery->fetch(PDO::FETCH_ASSOC);
if (!is_array($target)) {
    fwrite(STDERR, "Target customer was not found.\n");
    exit(3);
}

$source = app_db_account_by_id((int) ($payment['account_id'] ?? 0));
$sourceId = (int) ($source['id'] ?? 0);
$targetId = (int) ($target['id'] ?? 0);
if ($targetId <= 0) {
    fwrite(STDERR, "Target account is invalid.\n");
    exit(3);
}

if ($sourceId > 0 && $sourceId !== $targetId
    && !str_starts_with((string) ($source['external_id'] ?? ''), 'old-billing-payment-only:')) {
    fwrite(STDERR, "Current account is not a disposable payment-only placeholder.\n");
    exit(4);
}

$metadata = app_db_json_decode((string) ($payment['metadata_json'] ?? ''), []);
$metadata['old_billing']['link_repair'] = [
    'from_account_id' => $sourceId,
    'from_subscriber_code' => (string) ($payment['subscriber_code'] ?? ''),
    'to_account_id' => $targetId,
    'to_subscriber_code' => $subscriberCode,
    'reason' => $reason,
    'repaired_at' => date(DATE_ATOM),
];

$db->beginTransaction();
try {
    $update = $db->prepare(
        'UPDATE billing_payments
         SET account_id = :account_id,
             subscriber_code = :subscriber_code,
             metadata_json = :metadata_json
         WHERE id = :id'
    );
    $update->execute([
        'account_id' => $targetId,
        'subscriber_code' => $subscriberCode,
        'metadata_json' => app_db_json_encode($metadata),
        'id' => (int) $payment['id'],
    ]);

    if ($sourceId > 0 && $sourceId !== $targetId) {
        $dependencyChecks = [
            'payments' => 'SELECT COUNT(*) FROM billing_payments WHERE account_id = :id',
            'services' => 'SELECT COUNT(*) FROM billing_radius_services WHERE account_id = :id',
            'ip_assignments' => 'SELECT COUNT(*) FROM customer_ip_assignments WHERE customer_id = :id',
            'tickets' => 'SELECT COUNT(*) FROM portal_tickets WHERE customer_account_id = :id',
        ];
        foreach ($dependencyChecks as $name => $sql) {
            $statement = $db->prepare($sql);
            $statement->execute(['id' => $sourceId]);
            if ((int) $statement->fetchColumn() > 0) {
                throw new RuntimeException('Placeholder still has dependent ' . $name . '.');
            }
        }
        $delete = $db->prepare(
            'DELETE FROM portal_accounts
             WHERE id = :id AND external_id LIKE "old-billing-payment-only:%"'
        );
        $delete->execute(['id' => $sourceId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('Payment-only placeholder was not deleted.');
        }
    }

    app_audit_record($db, [
        'module_key' => 'billing',
        'operation_class' => 'legacy_migration_reconciliation',
        'action_type' => 'legacy_payment_link_repaired',
        'entity_type' => 'billing_payment',
        'entity_id' => $externalId,
        'status' => 'executed',
        'actor_name' => 'Legacy migration worker',
        'summary' => 'Malformed legacy payment customer link repaired',
        'reason' => $reason,
        'before' => [
            'account_id' => $sourceId,
            'subscriber_code' => (string) ($payment['subscriber_code'] ?? ''),
        ],
        'after' => [
            'account_id' => $targetId,
            'subscriber_code' => $subscriberCode,
        ],
        'sensitivity' => 'sensitive',
        'context' => [
            'request_ip' => '127.0.0.1',
            'user_agent' => 'legacy-payment-repair-cli',
            'request_id' => 'legacy-payment-repair-' . bin2hex(random_bytes(8)),
        ],
    ]);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Repair failed: ' . $exception->getMessage() . PHP_EOL);
    exit(5);
}

echo json_encode([
    'ok' => true,
    'external_id' => $externalId,
    'subscriber_code' => $subscriberCode,
    'target_account_id' => $targetId,
    'removed_placeholder_account_id' => $sourceId !== $targetId ? $sourceId : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
