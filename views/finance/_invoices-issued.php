<?php
declare(strict_types=1);

$issuedRows = (array) ($pageData['rows'] ?? []);
$recentPaymentsForInvoice = (array) ($financeContext['data']['recent_payments_for_invoice'] ?? []);
?>
<div class="portal-card-head">
    <div><strong>Tertib olunmus qaimeler</strong><small>Ardicil nomrelenme + ƏDV ile real musteri qaimesi. Yalniz sened qatidir -- odenis/RADIUS statusuna toxunmur.</small></div>
    <?php if (!empty($financeContext['data']['can_write'])): ?>
    <button class="portal-primary-btn" type="button" data-finance-dialog-open="invoice-issue">Yeni qaime tertib et</button>
    <?php endif; ?>
</div>
<form class="finance-filter" method="get" action="operations-center.php">
    <input type="hidden" name="section" value="finance"><input type="hidden" name="finance_tab" value="invoices"><input type="hidden" name="invoice_view" value="issued">
    <div class="portal-form-row"><label>Axtaris</label><input type="search" name="search" value="<?= e((string) ($_GET['search'] ?? '')); ?>" placeholder="Qaime nomresi, qarsi teref"></div>
    <div class="portal-form-row"><label><?= e(app_ui_label('status')); ?></label><select name="invoice_status"><option value="">Butun</option><option value="issued"<?= ($_GET['invoice_status'] ?? '') === 'issued' ? ' selected' : ''; ?>>Tertib olunub</option><option value="void"<?= ($_GET['invoice_status'] ?? '') === 'void' ? ' selected' : ''; ?>>Legv edilib</option></select></div>
    <button class="portal-primary-btn" type="submit"><?= e(app_ui_action('apply')); ?></button>
</form>
<div class="portal-table-wrap">
    <table class="portal-table">
        <thead><tr><th>Qaime No</th><th>Qarsi teref</th><th>Tarix</th><th>Meblegsiz</th><th>ƏDV</th><th>Cemi</th><th><?= e(app_ui_label('status')); ?></th><th></th></tr></thead>
        <tbody>
            <?php foreach ($issuedRows as $row): ?>
                <tr data-finance-row>
                    <td><?= e((string) ($row['invoice_no'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['counterparty_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['issue_date'] ?? '-')); ?></td>
                    <td><?= e(billing_money($row['subtotal_amount'] ?? 0)); ?></td>
                    <td><?= e(billing_money($row['vat_amount'] ?? 0)); ?> (<?= e((string) ($row['vat_rate'] ?? 0)); ?>%)</td>
                    <td><strong><?= e(billing_money($row['total_amount'] ?? 0)); ?></strong></td>
                    <td><span class="dash-badge<?= (string) ($row['status'] ?? '') === 'void' ? ' dash-badge-warning' : ''; ?>"><?= e((string) ($row['status'] ?? '-')); ?></span></td>
                    <td>
                        <?php if ((string) ($row['status'] ?? '') === 'issued' && !empty($financeContext['data']['can_write'])): ?>
                        <form class="portal-inline-form" method="post" onsubmit="return confirm('Bu qaimeni legv etmek istediyinize eminsiniz?');">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="void_finance_invoice">
                            <input type="hidden" name="invoice_id" value="<?= e((string) ($row['id'] ?? 0)); ?>">
                            <input name="reason" placeholder="Legv sebebi" required maxlength="255">
                            <button class="portal-ghost-btn" type="submit">Legv et</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($issuedRows === []): ?><tr><td colspan="8" class="finance-empty">Tertib olunmus qaime yoxdur.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/_pagination.php'; ?>

<?php if (!empty($financeContext['data']['can_write'])): ?>
<div class="finance-dialog" data-finance-dialog="invoice-issue" hidden>
    <div class="finance-dialog-backdrop" data-finance-dialog-close></div>
    <section class="finance-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="invoice-issue-title">
        <div class="finance-dialog-head"><div><h3 id="invoice-issue-title">Yeni qaime tertib et</h3><small>Ya mevcud odenisden, ya da manual mebleg ile.</small></div><button class="finance-dialog-close" type="button" data-finance-dialog-close aria-label="Bagla">X</button></div>
        <form class="portal-stacked-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="issue_finance_invoice">
            <input type="hidden" name="finance_tab" value="invoices">
            <div class="portal-form-row"><label>Menbe</label><select name="source_type"><option value="manual">Manual mebleg</option><option value="billing_payment">Movcud odenis (ID)</option></select></div>
            <div class="portal-form-row"><label>Odenis ID (menbe=odenis olarsa)</label><input name="source_id" type="number" min="1" list="invoice-recent-payments">
                <datalist id="invoice-recent-payments"><?php foreach ($recentPaymentsForInvoice as $paymentOption): ?><option value="<?= e((string) ($paymentOption['id'] ?? '')); ?>">#<?= e((string) ($paymentOption['id'] ?? '')); ?> - <?= e((string) ($paymentOption['subscriber_code'] ?? '')); ?> - <?= e(billing_money($paymentOption['amount'] ?? 0)); ?></option><?php endforeach; ?></datalist>
            </div>
            <div class="portal-form-row"><label>Qarsi teref adi</label><input name="counterparty_name" maxlength="191" placeholder="odenisden avtomatik alinacaq, ya da manual yazin"></div>
            <div class="portal-form-row"><label>Meblegsiz mebleg (manual olarsa)</label><input name="subtotal_amount" type="number" min="0" step="0.01"></div>
            <div class="portal-form-row"><label>ƏDV faizi (bos = defolt)</label><input name="vat_rate" type="number" min="0" max="100" step="0.01"></div>
            <div class="portal-form-row"><label>Tarix</label><input name="issue_date" type="date" value="<?= e(date('Y-m-d')); ?>"></div>
            <div class="portal-form-row"><label>Aciqlama</label><input name="description" maxlength="255"></div>
            <button class="portal-primary-btn" type="submit">Qaime tertib et</button>
        </form>
    </section>
</div>
<?php endif; ?>
