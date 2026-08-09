<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/ui-language-lib.php';

$pageData = (array) ($financeContext['data']['page'] ?? []);
$invoiceRows = (array) ($pageData['rows'] ?? []);
$invoiceStatusOptions = app_ui_select_status_options(['imported', 'failed']);
$invoiceView = (string) ($financeContext['data']['invoice_view'] ?? 'imports');
?>
<div data-finance-invoices data-finance-einvoice-workflow>
    <div class="portal-tabs-inline">
        <a class="portal-pill<?= $invoiceView === 'imports' ? ' is-active' : ''; ?>" href="<?= e(app_finance_url('invoices', ['invoice_view' => 'imports'], $financeLang)); ?>">Xarici e-qaime importu</a>
        <a class="portal-pill<?= $invoiceView === 'issued' ? ' is-active' : ''; ?>" href="<?= e(app_finance_url('invoices', ['invoice_view' => 'issued'], $financeLang)); ?>">Tertib olunmus qaimeler</a>
    </div>
    <?php if ($invoiceView === 'issued'): ?>
        <?php require __DIR__ . '/_invoices-issued.php'; ?>
    <?php else: ?>
    <div class="portal-card-head">
        <div><strong>Elektron qaimeler</strong><small>Import olunan alis ve satis qaimeleri, borc qaligi ve son tarix.</small></div>
        <div class="portal-badge-list">
            <a class="portal-ghost-btn portal-inline-link" data-finance-einvoice-export href="<?= e(app_finance_url('invoices', ['finance_einvoice_export' => '1'], $financeLang)); ?>"><?= e(app_ui_label('export_csv')); ?></a>
            <button class="portal-primary-btn" type="button" data-finance-dialog-open="invoice-import">Qaime <?= e(strtolower(app_ui_action('import'))); ?></button>
        </div>
    </div>
    <form class="finance-filter" method="get" action="operations-center.php" data-finance-filter>
        <input type="hidden" name="section" value="finance"><input type="hidden" name="finance_tab" value="invoices">
        <div class="portal-form-row"><label>Axtaris</label><input type="search" name="search" value="<?= e((string) ($_GET['search'] ?? '')); ?>" placeholder="Qaime, sirket, batch"></div>
        <div class="portal-form-row"><label>Nov</label><select name="ledger_type"><option value="">Butun</option><option value="receivable"<?= ($_GET['ledger_type'] ?? '') === 'receivable' ? ' selected' : ''; ?>>Satis / alacaq</option><option value="payable"<?= ($_GET['ledger_type'] ?? '') === 'payable' ? ' selected' : ''; ?>>Alis / borc</option></select></div>
        <div class="portal-form-row"><label><?= e(app_ui_label('status')); ?></label><select name="status"><option value="">Butun</option><?php foreach ($invoiceStatusOptions as $key => $label): ?><option value="<?= e($key); ?>"<?= ($_GET['status'] ?? '') === $key ? ' selected' : ''; ?>><?= e($label); ?></option><?php endforeach; ?></select></div>
        <button class="portal-primary-btn" type="submit"><?= e(app_ui_action('apply')); ?></button>
    </form>
    <div class="portal-table-wrap"><table class="portal-table"><thead><tr><th>Qaime</th><th>Qarsi teref</th><th>Nov</th><th>Mebleg</th><th>Qaliq</th><th>Son tarix</th><th><?= e(app_ui_label('status')); ?></th><th><?= e(app_ui_label('imported_at')); ?></th></tr></thead><tbody>
        <?php foreach ($invoiceRows as $row): ?><tr data-finance-row><td><?= e((string) ($row['document_no'] ?? '-')); ?></td><td><?= e((string) ($row['counterparty_name'] ?? '-')); ?></td><td><?= e(app_ui_term((string) ($row['ledger_type'] ?? '-'))); ?></td><td><?= e(billing_money($row['amount'] ?? 0)); ?></td><td><?= e(billing_money($row['amount_open'] ?? 0)); ?></td><td><?= e((string) ($row['due_date'] ?? '-')); ?></td><td><?= e(app_ui_status((string) ($row['debt_status'] ?? $row['status'] ?? '-'))); ?></td><td><?= e((string) ($row['created_at'] ?? '-')); ?></td></tr><?php endforeach; ?>
        <?php if ($invoiceRows === []): ?><tr><td colspan="8" class="finance-empty">Qaime tapilmadi.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php require __DIR__ . '/_pagination.php'; ?>

    <div class="finance-dialog" data-finance-dialog="invoice-import" hidden>
        <div class="finance-dialog-backdrop" data-finance-dialog-close></div>
        <section class="finance-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="invoice-import-title">
            <div class="finance-dialog-head"><div><h3 id="invoice-import-title">Elektron qaime importu</h3><small>CSV sutunlari: ledger_type;document_no;counterparty_name;counterparty_type;amount;due_date</small></div><button class="finance-dialog-close" type="button" data-finance-dialog-close aria-label="Bagla">X</button></div>
            <form class="portal-stacked-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="import_finance_e_invoice"><input type="hidden" name="finance_tab" value="invoices">
                <div class="portal-form-row"><textarea name="e_invoice_rows" rows="10" required placeholder="ledger_type;document_no;counterparty_name;counterparty_type;amount;due_date"></textarea></div>
                <button class="portal-primary-btn" type="submit"><?= e(app_ui_action('import')); ?></button>
            </form>
        </section>
    </div>
    <?php endif; ?>
</div>
