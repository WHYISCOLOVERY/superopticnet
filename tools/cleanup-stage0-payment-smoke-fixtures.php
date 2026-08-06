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

$apply = in_array('--apply', $_SERVER['argv'] ?? [], true);
$predicate = "subscriber_code LIKE 'SMOKE-BILL-%' OR subscriber_code LIKE 'RDS%' OR subscriber_code LIKE 'WSLLIFE%'";
$counts = [
    'accounts' => (int) $db->query('SELECT COUNT(*) FROM portal_accounts WHERE ' . $predicate)->fetchColumn(),
    'payments' => (int) $db->query('SELECT COUNT(*) FROM billing_payments WHERE ' . $predicate)->fetchColumn(),
    'operations' => (int) $db->query('SELECT COUNT(*) FROM billing_payment_operations WHERE ' . $predicate)->fetchColumn(),
];

if (!$apply) {
    echo json_encode(['mode' => 'dry-run', 'counts' => $counts], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$operationPredicate = "o.subscriber_code LIKE 'SMOKE-BILL-%' OR o.subscriber_code LIKE 'RDS%' OR o.subscriber_code LIKE 'WSLLIFE%'";
$accountPredicate = "p.subscriber_code LIKE 'SMOKE-BILL-%' OR p.subscriber_code LIKE 'RDS%' OR p.subscriber_code LIKE 'WSLLIFE%'";
$usernamePredicate = "username LIKE 'SMOKE-BILL-%' OR username LIKE 'RDS%' OR username LIKE 'WSLLIFE%'";

$db->beginTransaction();
try {
    $db->exec('DELETE r FROM billing_payment_reversals r JOIN billing_payment_operations o ON o.id = r.operation_id WHERE ' . $operationPredicate);
    $db->exec('DELETE e FROM billing_payment_operation_events e JOIN billing_payment_operations o ON o.id = e.operation_id WHERE ' . $operationPredicate);
    $db->exec('DELETE a FROM billing_payment_service_attempts a JOIN billing_payment_service_actions s ON s.id = a.service_action_id JOIN billing_payment_operations o ON o.id = s.operation_id WHERE ' . $operationPredicate);
    $db->exec('DELETE b FROM billing_payment_outbox b JOIN billing_payment_service_actions s ON s.id = b.service_action_id JOIN billing_payment_operations o ON o.id = s.operation_id WHERE ' . $operationPredicate);
    $db->exec('DELETE s FROM billing_payment_service_actions s JOIN billing_payment_operations o ON o.id = s.operation_id WHERE ' . $operationPredicate);
    $db->exec('DELETE FROM billing_payment_operations WHERE ' . $predicate);
    $db->exec('DELETE FROM billing_payments WHERE ' . $predicate);
    foreach (['radcheck', 'radreply', 'radusergroup', 'radacct', 'radius_session_actions'] as $table) {
        $db->exec('DELETE FROM ' . $table . ' WHERE ' . $usernamePredicate);
    }
    $db->exec('DELETE r FROM billing_radius_services r JOIN portal_accounts p ON p.id = r.account_id WHERE ' . $accountPredicate);
    $db->exec('DELETE h FROM ip_assignment_history h JOIN portal_accounts p ON p.id = h.customer_id WHERE ' . $accountPredicate);
    $db->exec('DELETE a FROM customer_ip_assignments a JOIN portal_accounts p ON p.id = a.customer_id WHERE ' . $accountPredicate);
    $db->exec(
        "UPDATE ip_addresses i JOIN portal_accounts p ON p.id = i.customer_id
            SET i.status = 'free', i.customer_id = NULL, i.service_username = NULL,
                i.assigned_at = NULL, i.released_at = NOW()
          WHERE " . $accountPredicate
    );
    $db->exec('DELETE FROM portal_accounts WHERE ' . $predicate);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'fixture_cleanup_failed:' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$remaining = [
    'accounts' => (int) $db->query('SELECT COUNT(*) FROM portal_accounts WHERE ' . $predicate)->fetchColumn(),
    'payments' => (int) $db->query('SELECT COUNT(*) FROM billing_payments WHERE ' . $predicate)->fetchColumn(),
    'operations' => (int) $db->query('SELECT COUNT(*) FROM billing_payment_operations WHERE ' . $predicate)->fetchColumn(),
];
echo json_encode(['mode' => 'apply', 'before' => $counts, 'remaining' => $remaining], JSON_PRETTY_PRINT) . PHP_EOL;
exit(array_sum($remaining) === 0 ? 0 : 1);
