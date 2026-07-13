<?php
declare(strict_types=1);

require __DIR__ . '/../mgs-integration-lib.php';

$errors = [];
$db = app_db();

foreach ([
    'app_mgs_record_finance_debt_payment',
    'app_mgs_finance_debt_payment_rows',
    'app_mgs_parse_e_invoice_rows',
    'app_mgs_import_finance_e_invoice_rows',
    'app_mgs_finance_e_invoice_import_rows',
    'app_mgs_export_finance_e_invoice_csv',
] as $functionName) {
    if (!function_exists($functionName)) {
        $errors[] = $functionName . '_missing';
    }
}

if (!$db instanceof PDO) {
    $errors[] = 'db_not_ready';
} elseif ($errors === []) {
    app_mgs_ensure_schema($db);
    foreach (['finance_debt_payments', 'finance_e_invoice_imports'] as $table) {
        try {
            $statement = $db->prepare('SHOW TABLES LIKE :table_name');
            $statement->execute(['table_name' => $table]);
            if (!$statement->fetchColumn()) {
                $errors[] = $table . '_missing';
            }
        } catch (Throwable $exception) {
            $errors[] = $table . '_check_failed:' . $exception->getMessage();
        }
    }

    $debtId = 0;
    $importedDebtIds = [];
    $paymentId = 0;
    $batchId = '';
    $runId = bin2hex(random_bytes(4));

    try {
        $debt = app_mgs_save_finance_debt_record([
            'ledger_type' => 'receivable',
            'counterparty_name' => 'Smoke Payment Customer',
            'counterparty_type' => 'customer',
            'amount' => 500,
            'document_no' => 'PAY-' . $runId,
            'due_date' => '2026-12-31',
        ], ['id' => 987654, 'full_name' => 'Smoke Finance'], $db);
        $debtId = (int) ($debt['id'] ?? 0);
        if (empty($debt['ok']) || $debtId <= 0) {
            $errors[] = 'debt_seed_failed';
        }

        if ($debtId > 0) {
            $payment = app_mgs_record_finance_debt_payment($debtId, [
                'payment_amount' => 200,
                'payment_date' => '2026-06-25',
                'payment_method' => 'cash',
                'reference_no' => 'PAYREF-' . $runId,
                'note' => 'Smoke partial payment',
            ], ['id' => 987654, 'full_name' => 'Smoke Finance'], $db);
            $paymentId = (int) ($payment['id'] ?? 0);
            if (empty($payment['ok']) || $paymentId <= 0) {
                $errors[] = 'debt_payment_save_failed';
            }

            $statement = $db->prepare('SELECT amount_open, status FROM finance_debt_ledger WHERE id = :id');
            $statement->execute(['id' => $debtId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if (abs((float) ($row['amount_open'] ?? 0) - 300.0) > 0.01 || (string) ($row['status'] ?? '') !== 'partial') {
                $errors[] = 'debt_payment_partial_status_failed';
            }

            $closing = app_mgs_record_finance_debt_payment($debtId, [
                'payment_amount' => 300,
                'payment_date' => '2026-06-26',
                'payment_method' => 'bank',
                'reference_no' => 'CLOSEREF-' . $runId,
            ], ['id' => 987654, 'full_name' => 'Smoke Finance'], $db);
            if (empty($closing['ok'])) {
                $errors[] = 'debt_closing_payment_failed';
            }

            $statement->execute(['id' => $debtId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if (abs((float) ($row['amount_open'] ?? 1)) > 0.01 || (string) ($row['status'] ?? '') !== 'closed') {
                $errors[] = 'debt_payment_close_status_failed';
            }

            $payments = app_mgs_finance_debt_payment_rows($db, 10);
            $paymentHit = false;
            foreach ($payments as $paymentRow) {
                if ((int) ($paymentRow['debt_id'] ?? 0) === $debtId
                    && in_array((string) ($paymentRow['reference_no'] ?? ''), ['PAYREF-' . $runId, 'CLOSEREF-' . $runId], true)
                ) {
                    $paymentHit = true;
                    break;
                }
            }
            if (!$paymentHit) {
                $errors[] = 'payment_rows_missing';
            }
        }

        $eInvoiceText = implode("\n", [
            'ledger_type;document_no;counterparty_name;counterparty_type;amount;due_date',
            'payable;EQAIME-' . $runId . ';Smoke Supplier;supplier;180.50;2026-12-20',
            'receivable;EQAIME-R-' . $runId . ';Smoke Customer;customer;90;2026-12-21',
        ]);
        $parsed = app_mgs_parse_e_invoice_rows($eInvoiceText);
        if (count($parsed) !== 2 || (string) ($parsed[0]['document_no'] ?? '') !== 'EQAIME-' . $runId) {
            $errors[] = 'e_invoice_parse_failed';
        }

        $import = app_mgs_import_finance_e_invoice_rows($eInvoiceText, ['id' => 987654, 'full_name' => 'Smoke Finance'], $db);
        $batchId = (string) ($import['batch_id'] ?? '');
        $importedDebtIds = array_map('intval', (array) ($import['debt_ids'] ?? []));
        if (empty($import['ok']) || (int) ($import['imported'] ?? 0) !== 2 || count($importedDebtIds) !== 2) {
            $errors[] = 'e_invoice_import_failed';
        }

        $importRows = app_mgs_finance_e_invoice_import_rows($db, 10);
        $importHit = false;
        foreach ($importRows as $row) {
            if ((string) ($row['import_batch'] ?? '') === $batchId
                && (string) ($row['document_no'] ?? '') === 'EQAIME-' . $runId
            ) {
                $importHit = true;
                break;
            }
        }
        if (!$importHit) {
            $errors[] = 'e_invoice_import_rows_missing';
        }

        $csv = app_mgs_export_finance_e_invoice_csv($db, 'all');
        if (!str_contains($csv, 'document_no,counterparty_name,ledger_type,amount_original,amount_open,status')
            || !str_contains($csv, 'EQAIME-' . $runId)
            || !str_contains($csv, 'Smoke Supplier')
        ) {
            $errors[] = 'e_invoice_export_csv_failed';
        }
    } catch (Throwable $exception) {
        $errors[] = 'roundtrip_exception:' . $exception->getMessage();
    } finally {
        $ids = array_filter(array_merge([$debtId], $importedDebtIds), static fn (int $id): bool => $id > 0);
        foreach ($ids as $id) {
            try {
                $cleanup = $db->prepare('DELETE FROM finance_debt_payments WHERE debt_id = :id');
                $cleanup->execute(['id' => $id]);
            } catch (Throwable) {
            }
            try {
                $cleanup = $db->prepare('DELETE FROM finance_e_invoice_imports WHERE debt_id = :id');
                $cleanup->execute(['id' => $id]);
            } catch (Throwable) {
            }
            try {
                $cleanup = $db->prepare('DELETE FROM finance_debt_ledger WHERE id = :id');
                $cleanup->execute(['id' => $id]);
            } catch (Throwable) {
            }
        }
        if ($batchId !== '') {
            try {
                $cleanup = $db->prepare('DELETE FROM finance_e_invoice_imports WHERE import_batch = :batch_id');
                $cleanup->execute(['batch_id' => $batchId]);
            } catch (Throwable) {
            }
        }
        try {
            $cleanup = $db->prepare(
                'DELETE FROM mgs_operation_actions
                  WHERE action_type IN ("finance_debt_payment","finance_e_invoice_import","finance_debt_record")
                    AND (entity_id LIKE :pay_doc OR entity_id LIKE :einvoice_doc OR entity_id = :batch_id)'
            );
            $cleanup->execute([
                'pay_doc' => '%' . $runId,
                'einvoice_doc' => 'EQAIME-%' . $runId,
                'batch_id' => $batchId,
            ]);
        } catch (Throwable) {
        }
    }
}

$operationsPage = (string) file_get_contents(__DIR__ . '/../operations-center.php')
    . (string) file_get_contents(__DIR__ . '/../views/finance/invoices.php')
    . (string) file_get_contents(__DIR__ . '/../views/finance/reconciliation.php');
foreach ([
    'data-finance-debt-payments',
    'data-finance-einvoice-workflow',
    'data-finance-einvoice-export',
    'name="action" value="record_finance_debt_payment"',
    'name="action" value="import_finance_e_invoice"',
    'name="payment_amount"',
    'name="debt_id"',
    'name="e_invoice_rows"',
] as $marker) {
    if (!str_contains($operationsPage, $marker)) {
        $errors[] = $marker . '_missing';
    }
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
