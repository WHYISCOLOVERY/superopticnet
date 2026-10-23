<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/audit-log-lib.php';

$apply = in_array('--apply', $argv, true);
$cutoff = '2026-10-21 00:00:00';
$marker = 'cutover-credit-v1-20261023';
$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database unavailable\n");
    exit(2);
}

$accounts = $db->query(
    'SELECT * FROM portal_accounts
      WHERE role="customer" AND (
        payload_json LIKE "%\\\"cutover-credit-v1-20261023\\\":true%"
        OR (
          CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.billing.borrowed_days_pending")), "0") AS UNSIGNED) > 0
          AND STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.billing.expires_at")), "%Y-%m-%d %H:%i:%s") <= DATE_ADD(NOW(), INTERVAL 4 DAY)
        )
      )
      ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$paymentQuery = $db->prepare(
    'SELECT amount, metadata_json, created_at
       FROM billing_payments
      WHERE account_id=:account_id AND created_at>=:cutoff
        AND payment_method NOT IN ("old_billing", "emanat", "million", "kapitalbank", "hesab.az", "UP")
      ORDER BY created_at,id'
);
$result = ['mode' => $apply ? 'apply' : 'dry_run', 'checked' => count($accounts), 'candidates' => [], 'repaired' => [], 'failed' => []];

foreach ($accounts as $account) {
    $payload = app_db_account_payload($account);
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    if (($payload['reconciliation_markers'][$marker] ?? false) === true) {
        if ($apply) {
            $auditExists = $db->prepare('SELECT COUNT(*) FROM mgs_operation_actions WHERE action_type="cutover_credit_repaired" AND entity_type="customer" AND entity_id=?');
            $auditExists->execute([(string) $account['id']]);
            if ((int) $auditExists->fetchColumn() === 0) {
                $history = is_array($payload['credit_reconciliation_history'] ?? null) ? end($payload['credit_reconciliation_history']) : [];
                $db->beginTransaction();
                try {
                    app_audit_record($db, [
                        'module_key' => 'billing', 'operation_class' => 'reconciliation',
                        'action_type' => 'cutover_credit_repaired', 'entity_type' => 'customer',
                        'entity_id' => (string) $account['id'], 'status' => 'executed',
                        'actor_name' => 'Cutover reconciliation',
                        'summary' => 'Documented cutover credit restored and applied to service',
                        'reason' => 'Payment credit was debited without a matching service extension',
                        'payload' => is_array($history) ? $history : [], 'sensitivity' => 'high',
                    ]);
                    $db->commit();
                } catch (Throwable $auditException) {
                    if ($db->inTransaction()) $db->rollBack();
                    $result['failed'][] = ['account_id' => (int) $account['id'], 'subscriber_code' => (string) $account['subscriber_code'], 'reason' => 'audit_backfill_' . $auditException->getMessage()];
                }
            }
        }
        continue;
    }
    $required = app_billing_required_payment_amount($account, $payload, $billing, app_billing_account_tariff($db, $account, $billing));
    $currentBalance = max(0.0, app_billing_parse_money_amount($billing['balance'] ?? 0));
    $paymentQuery->execute(['account_id' => (int) $account['id'], 'cutoff' => $cutoff]);
    $payments = $paymentQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($payments === []) continue;

    $sum = 0.0;
    $openingBalance = 0.0;
    foreach ($payments as $index => $payment) {
        $amount = round((float) ($payment['amount'] ?? 0), 2);
        $sum += $amount;
        if ($index === 0) {
            $metadata = app_db_json_decode((string) ($payment['metadata_json'] ?? ''), []);
            $remaining = max(0.0, (float) ($metadata['remaining_balance'] ?? $amount));
            $openingBalance = max(0.0, $remaining - $amount);
        }
    }
    $documentedCredit = round($openingBalance + $sum, 2);
    if ($documentedCredit + 0.0001 < $required || $currentBalance + 0.0001 >= $required) continue;

    // Restore every documented manat, including overpayment that must remain
    // as prepaid credit after the monthly tariff is consumed.
    $restore = round($documentedCredit - $currentBalance, 2);
    $candidate = [
        'account_id' => (int) $account['id'],
        'subscriber_code' => (string) $account['subscriber_code'],
        'required' => $required,
        'opening_balance' => $openingBalance,
        'payments_since_cutoff' => round($sum, 2),
        'documented_credit' => $documentedCredit,
        'balance_before' => $currentBalance,
        'restore' => $restore,
        'borrowed_days_pending' => (int) ($billing['borrowed_days_pending'] ?? 0),
        'expires_at_before' => (string) ($billing['expires_at'] ?? ''),
    ];
    $result['candidates'][] = $candidate;
    if (!$apply) continue;

    try {
        $db->beginTransaction();
        $lock = $db->prepare('SELECT * FROM portal_accounts WHERE id=? FOR UPDATE');
        $lock->execute([(int) $account['id']]);
        $fresh = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($fresh)) throw new RuntimeException('account_missing');
        $freshPayload = app_db_account_payload($fresh);
        if (($freshPayload['reconciliation_markers'][$marker] ?? false) === true) {
            $db->rollBack();
            continue;
        }
        $freshBilling = is_array($freshPayload['billing'] ?? null) ? $freshPayload['billing'] : [];
        $freshBalance = max(0.0, app_billing_parse_money_amount($freshBilling['balance'] ?? 0));
        if (abs($freshBalance - $currentBalance) > 0.0001) throw new RuntimeException('balance_changed_concurrently');
        $freshBilling['balance'] = number_format($freshBalance + $restore, 2, '.', '') . ' AZN';
        $freshPayload['billing'] = $freshBilling;
        $freshPayload['reconciliation_markers'][$marker] = 'credit_restored_pending_settlement';
        $freshPayload['credit_reconciliation_history'][] = $candidate + ['at' => app_db_now(), 'reason' => 'Cutover debit without matching service extension'];
        $db->prepare('UPDATE portal_accounts SET payload_json=?,updated_at=NOW() WHERE id=?')
            ->execute([app_db_json_encode($freshPayload), (int) $account['id']]);
        $db->commit();

        $updated = app_db_account_by_id((int) $account['id']);
        $settlement = is_array($updated) ? app_billing_apply_available_balance($updated, $db) : ['ok' => false, 'reason' => 'reload_failed'];
        if (empty($settlement['ok'])) throw new RuntimeException('settlement_' . (string) ($settlement['reason'] ?? 'failed'));
        $settledAccount = app_db_account_by_id((int) $account['id']);
        if (!is_array($settledAccount)) throw new RuntimeException('settled_account_reload_failed');
        $settledPayload = app_db_account_payload($settledAccount);
        $settledPayload['reconciliation_markers'][$marker] = true;
        $db->prepare('UPDATE portal_accounts SET payload_json=?,updated_at=NOW() WHERE id=?')
            ->execute([app_db_json_encode($settledPayload), (int) $account['id']]);
        $db->beginTransaction();
        app_audit_record($db, [
            'module_key' => 'billing', 'operation_class' => 'reconciliation',
            'action_type' => 'cutover_credit_repaired', 'entity_type' => 'customer',
            'entity_id' => (string) $account['id'], 'status' => 'executed',
            'actor_name' => 'Cutover reconciliation',
            'summary' => 'Documented cutover credit restored and applied to service',
            'reason' => 'Payment credit was debited without a matching service extension',
            'payload' => $candidate + ['settlement' => $settlement], 'sensitivity' => 'high',
        ]);
        $db->commit();
        $result['repaired'][] = $candidate + ['settlement' => $settlement];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        $result['failed'][] = $candidate + ['reason' => $exception->getMessage()];
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['failed'] === [] ? 0 : 1);
