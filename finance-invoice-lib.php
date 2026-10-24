<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finance-schema-lib.php';
require_once __DIR__ . '/finance-operations-lib.php';
require_once __DIR__ . '/business-rules-lib.php';

function app_finance_invoice_require_schema(PDO $db): void
{
    $missing = app_finance_schema_missing_tables($db, ['finance_invoices', 'finance_invoice_counters']);
    if ($missing !== []) {
        throw new RuntimeException('finance_invoice_schema_not_migrated:' . implode(',', $missing));
    }
}

function app_finance_invoice_default_vat_rate(PDO $db): float
{
    return (float) app_business_rule_get($db, 'finance_invoice_default_vat_rate_percent', 18.00);
}

/**
 * Atomic per-fiscal-year sequence via the MySQL LAST_INSERT_ID() upsert idiom
 * -- avoids a GET_LOCK round trip and cannot produce a duplicate number even
 * under concurrent issuance.
 */
function app_finance_invoice_next_number(PDO $db, int $fiscalYear): string
{
    $statement = $db->prepare(
        'INSERT INTO finance_invoice_counters (fiscal_year, next_seq) VALUES (:fiscal_year, 2)
         ON DUPLICATE KEY UPDATE next_seq = LAST_INSERT_ID(next_seq + 1)'
    );
    $statement->execute(['fiscal_year' => $fiscalYear]);
    $seq = (int) $db->lastInsertId();

    return sprintf('INV-%d-%06d', $fiscalYear, $seq);
}

/**
 * Generates a real customer-facing invoice document (sequential number +
 * VAT breakdown). This is a REPORTING/DOCUMENT layer only -- it never
 * touches billing_payments, RADIUS activation, or any live service state,
 * so issuing/voiding an invoice cannot affect a customer's connectivity.
 */
function app_finance_invoice_issue(PDO $db, array $actor, array $input): array
{
    if (!app_finance_actor_can_write($actor)) {
        throw new RuntimeException('finance_invoice_forbidden');
    }
    app_finance_invoice_require_schema($db);

    $sourceType = (string) ($input['source_type'] ?? 'manual');
    if (!in_array($sourceType, ['billing_payment', 'manual'], true)) {
        throw new InvalidArgumentException('invalid_source_type');
    }

    $accountId = null;
    $counterpartyName = mb_substr(clean_text((string) ($input['counterparty_name'] ?? '')), 0, 191);
    $subtotal = null;
    $sourceId = null;

    if ($sourceType === 'billing_payment') {
        $paymentId = (int) ($input['source_id'] ?? 0);
        if ($paymentId <= 0) {
            throw new InvalidArgumentException('source_id_required');
        }
        $paymentStatement = $db->prepare(
            'SELECT p.id, p.account_id, p.amount, a.full_name
               FROM billing_payments p
               LEFT JOIN portal_accounts a ON a.id = p.account_id
              WHERE p.id = :id'
        );
        $paymentStatement->execute(['id' => $paymentId]);
        $paymentRow = $paymentStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($paymentRow)) {
            throw new RuntimeException('billing_payment_not_found');
        }
        $existingStatement = $db->prepare(
            "SELECT id FROM finance_invoices WHERE source_type = 'billing_payment' AND source_id = :source_id AND status = 'issued'"
        );
        $existingStatement->execute(['source_id' => $paymentId]);
        if ($existingStatement->fetchColumn() !== false) {
            throw new RuntimeException('billing_payment_already_invoiced');
        }
        $sourceId = $paymentId;
        $accountId = (int) $paymentRow['account_id'];
        $counterpartyName = $counterpartyName !== '' ? $counterpartyName : (string) ($paymentRow['full_name'] ?? '');
        $subtotal = (float) $paymentRow['amount'];
    }

    if ($subtotal === null) {
        $subtotal = round((float) ($input['subtotal_amount'] ?? 0), 2);
    }
    if ($subtotal <= 0) {
        throw new InvalidArgumentException('subtotal_amount_must_be_positive');
    }
    if ($counterpartyName === '') {
        throw new InvalidArgumentException('counterparty_name_required');
    }

    $vatRate = array_key_exists('vat_rate', $input) && $input['vat_rate'] !== ''
        ? round((float) $input['vat_rate'], 2)
        : app_finance_invoice_default_vat_rate($db);
    if ($vatRate < 0 || $vatRate > 100) {
        throw new InvalidArgumentException('invalid_vat_rate');
    }
    $vatAmount = round($subtotal * ($vatRate / 100), 2);
    $totalAmount = round($subtotal + $vatAmount, 2);

    $issueDate = clean_text((string) ($input['issue_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
        $issueDate = date('Y-m-d');
    }
    $fiscalYear = (int) substr($issueDate, 0, 4);
    $description = mb_substr(clean_text((string) ($input['description'] ?? '')), 0, 255);

    $db->beginTransaction();
    try {
        $invoiceNo = app_finance_invoice_next_number($db, $fiscalYear);
        $actorId = (int) ($actor['id'] ?? 0) ?: null;
        $actorName = (string) ($actor['full_name'] ?? $actor['login'] ?? '');

        $insert = $db->prepare(
            'INSERT INTO finance_invoices
                (invoice_no, account_id, counterparty_name, source_type, source_id, issue_date,
                 description, subtotal_amount, vat_rate, vat_amount, total_amount, currency, status,
                 created_by_account_id, created_by_name)
             VALUES
                (:invoice_no, :account_id, :counterparty_name, :source_type, :source_id, :issue_date,
                 :description, :subtotal_amount, :vat_rate, :vat_amount, :total_amount, :currency, "issued",
                 :actor_id, :actor_name)'
        );
        $insert->execute([
            'invoice_no' => $invoiceNo,
            'account_id' => $accountId,
            'counterparty_name' => $counterpartyName,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'issue_date' => $issueDate,
            'description' => $description,
            'subtotal_amount' => $subtotal,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
            'currency' => clean_text((string) ($input['currency'] ?? 'AZN')) ?: 'AZN',
            'actor_id' => $actorId,
            'actor_name' => $actorName !== '' ? $actorName : null,
        ]);
        $invoiceId = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }

    return [
        'ok' => true,
        'id' => $invoiceId,
        'invoice_no' => $invoiceNo,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vatAmount,
        'total_amount' => $totalAmount,
    ];
}

/**
 * Voiding is the safe/corrective direction (matches void_finance_income's
 * existing precedent) -- applies immediately, requires a reason, no
 * maker-checker needed. An already-void invoice cannot be voided again.
 */
function app_finance_invoice_void(PDO $db, array $actor, int $invoiceId, string $reason): array
{
    if (!app_finance_actor_can_write($actor)) {
        throw new RuntimeException('finance_invoice_forbidden');
    }
    app_finance_invoice_require_schema($db);
    $reason = mb_substr(clean_text($reason), 0, 255);
    if ($reason === '') {
        throw new InvalidArgumentException('reason_required');
    }

    $statement = $db->prepare('SELECT id, status FROM finance_invoices WHERE id = :id');
    $statement->execute(['id' => $invoiceId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('invoice_not_found');
    }
    if ((string) $row['status'] === 'void') {
        throw new RuntimeException('invoice_already_void');
    }

    $actorId = (int) ($actor['id'] ?? 0) ?: null;
    $actorName = (string) ($actor['full_name'] ?? $actor['login'] ?? '');
    $update = $db->prepare(
        'UPDATE finance_invoices
            SET status = "void", void_reason = :reason, voided_by_account_id = :actor_id,
                voided_by_name = :actor_name, voided_at = NOW()
          WHERE id = :id'
    );
    $update->execute([
        'reason' => $reason,
        'actor_id' => $actorId,
        'actor_name' => $actorName !== '' ? $actorName : null,
        'id' => $invoiceId,
    ]);

    return ['ok' => true, 'id' => $invoiceId];
}
