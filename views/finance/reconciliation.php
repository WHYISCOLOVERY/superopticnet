<?php
declare(strict_types=1);

$pageData = (array) ($financeContext['data']['page'] ?? []);
$debtRows = (array) ($pageData['rows'] ?? []);
$openDebts = array_values(array_filter((array) ($financeContext['data']['open_debts'] ?? []), static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['open', 'partial'], true)));
$recentDebtPayments = array_slice((array) ($financeContext['data']['payments']['rows'] ?? []), 0, 8);
$pendingDebtWriteoffs = (array) ($financeContext['data']['pending_debt_writeoffs'] ?? []);
$canConfirmDebtWriteoff = !empty($financeContext['data']['can_confirm_maker_checker']);
$cashReconciliationRows = (array) ($financeContext['data']['cash_reconciliation']['rows'] ?? []);
?>
<div data-finance-reconciliation data-finance-debt-ledger data-finance-debt-payments>
    <div class="portal-card-head">
        <div><strong>Borc, alacaq ve uzlasdirma</strong><small>Qarsi teref qaligi, sened, son tarix ve baglanma odenisleri.</small></div>
        <div class="portal-badge-list"><button class="portal-ghost-btn" type="button" data-finance-dialog-open="debt-create">Yeni ledger</button><button class="portal-primary-btn" type="button" data-finance-dialog-open="debt-payment">Odenis yaz</button><button class="portal-ghost-btn" type="button" data-finance-dialog-open="debt-writeoff">Borcu sil (tesdiq telebi)</button></div>
    </div>
    <?php if ($pendingDebtWriteoffs !== []): ?>
    <article class="portal-muted-card" style="margin-top:14px">
        <strong>Gozleyen borc silinmesi sorgulari</strong>
        <div class="spog-list" style="margin-top:10px">
        <?php foreach ($pendingDebtWriteoffs as $writeoff): ?>
            <div class="spog-list-item">
                <strong><?= e((string) ($writeoff['counterparty_name'] ?? '-')); ?> / <?= e(billing_money($writeoff['requested_amount'] ?? 0)); ?></strong>
                <small><?= e((string) ($writeoff['reason'] ?? '-')); ?> - <?= e((string) ($writeoff['requested_by_name'] ?? '-')); ?></small>
                <?php if ($canConfirmDebtWriteoff): ?>
                <form class="portal-inline-form" method="post" action="operations-center.php" style="margin-top:6px">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="resolve_finance_debt_writeoff">
                    <input type="hidden" name="finance_tab" value="reconciliation">
                    <input type="hidden" name="writeoff_request_id" value="<?= e((string) ($writeoff['id'] ?? 0)); ?>">
                    <input type="text" name="writeoff_resolution_note" placeholder="Qeyd" style="width:140px">
                    <select name="writeoff_decision"><option value="approved">Tesdiqle</option><option value="rejected">Redd et</option></select>
                    <button class="portal-ghost-btn" type="submit">Qerar ver</button>
                </form>
                <?php else: ?>
                <small>Admin tesdiqi gozleyir</small>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </article>
    <?php endif; ?>
    <form class="finance-filter" method="get" action="operations-center.php" data-finance-filter>
        <input type="hidden" name="section" value="finance"><input type="hidden" name="finance_tab" value="reconciliation">
        <div class="portal-form-row"><label>Axtaris</label><input type="search" name="search" value="<?= e((string) ($_GET['search'] ?? '')); ?>" placeholder="Qarsi teref, sened"></div>
        <div class="portal-form-row"><label>Nov</label><select name="ledger_type"><option value="all">Butun</option><option value="receivable"<?= ($_GET['ledger_type'] ?? '') === 'receivable' ? ' selected' : ''; ?>>Bize borcu var</option><option value="payable"<?= ($_GET['ledger_type'] ?? '') === 'payable' ? ' selected' : ''; ?>>Biz borcluyuq</option></select></div>
        <div class="portal-form-row"><label>Status</label><select name="status"><option value="all">Butun</option><option value="open"<?= ($_GET['status'] ?? '') === 'open' ? ' selected' : ''; ?>>Aciq</option><option value="partial"<?= ($_GET['status'] ?? '') === 'partial' ? ' selected' : ''; ?>>Qismen</option><option value="closed"<?= ($_GET['status'] ?? '') === 'closed' ? ' selected' : ''; ?>>Bagli</option></select></div>
        <button class="portal-primary-btn" type="submit">Tetbiq et</button>
    </form>
    <div class="portal-table-wrap"><table class="portal-table"><thead><tr><th>ID</th><th>Qarsi teref</th><th>Nov</th><th>Sened</th><th>Ilkin</th><th>Qaliq</th><th>Son tarix</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($debtRows as $row): ?><tr data-finance-row><td>#<?= e((string) ($row['id'] ?? 0)); ?></td><td><?= e((string) ($row['counterparty_name'] ?? '-')); ?></td><td><?= e(app_ui_term((string) ($row['ledger_type'] ?? '-'))); ?></td><td><?= e((string) ($row['document_no'] ?? '-')); ?></td><td><?= e(billing_money($row['amount_original'] ?? 0)); ?></td><td><?= e(billing_money($row['amount_open'] ?? 0)); ?></td><td><?= e((string) ($row['due_date'] ?? '-')); ?></td><td><?= e(app_ui_status((string) ($row['status'] ?? '-'))); ?></td></tr><?php endforeach; ?>
        <?php if ($debtRows === []): ?><tr><td colspan="8" class="finance-empty">Ledger qeydi yoxdur.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php require __DIR__ . '/_pagination.php'; ?>
    <?php if ($recentDebtPayments !== []): ?><article class="portal-muted-card" style="margin-top:14px"><strong>Son bağlanma ödənişləri</strong><div class="spog-list" style="margin-top:10px"><?php foreach ($recentDebtPayments as $row): ?><div class="spog-list-item"><strong><?= e((string) ($row['counterparty_name'] ?? '-')); ?> / <?= e(billing_money($row['payment_amount'] ?? 0)); ?></strong><small><?= e(app_ui_term((string) ($row['payment_method'] ?? '-'))); ?> / <?= e((string) ($row['payment_date'] ?? '-')); ?></small></div><?php endforeach; ?></div></article><?php endif; ?>

    <div class="finance-dialog" data-finance-dialog="debt-create" hidden><div class="finance-dialog-backdrop" data-finance-dialog-close></div><section class="finance-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="debt-create-title"><div class="finance-dialog-head"><h3 id="debt-create-title">Yeni borc/alacaq qeydi</h3><button class="finance-dialog-close" type="button" data-finance-dialog-close aria-label="Bagla">X</button></div><form class="portal-stacked-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="create_finance_debt_record"><input type="hidden" name="finance_tab" value="reconciliation">
        <div class="portal-form-row portal-grid-slim"><select name="ledger_type" required><option value="receivable">Bize borcu var</option><option value="payable">Biz borcluyuq</option></select><select name="counterparty_type"><option value="customer">Musteri</option><option value="supplier">Tedarukcu</option><option value="employee">Emekdas</option><option value="other">Diger</option></select></div>
        <div class="portal-form-row portal-grid-slim"><input type="text" name="counterparty_name" placeholder="Qarsi teref" required><input type="number" step="0.01" min="0.01" name="debt_amount" placeholder="Mebleg" required></div>
        <div class="portal-form-row portal-grid-slim"><input type="text" name="document_no" placeholder="Qaime/sened no"><input type="date" name="due_date"></div><div class="portal-form-row"><textarea name="debt_note" placeholder="Qeyd"></textarea></div><button class="portal-primary-btn" type="submit">Saxla</button>
    </form></section></div>
    <div class="finance-dialog" data-finance-dialog="debt-payment" hidden><div class="finance-dialog-backdrop" data-finance-dialog-close></div><section class="finance-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="debt-payment-title"><div class="finance-dialog-head"><h3 id="debt-payment-title">Borc odenisi</h3><button class="finance-dialog-close" type="button" data-finance-dialog-close aria-label="Bagla">X</button></div><form class="portal-stacked-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="record_finance_debt_payment"><input type="hidden" name="finance_tab" value="reconciliation">
        <div class="portal-form-row"><select name="debt_id" required><option value="">Borc sec</option><?php foreach ($openDebts as $row): ?><option value="<?= e((string) ($row['id'] ?? 0)); ?>"><?= e((string) ($row['counterparty_name'] ?? '-')); ?> / <?= e(billing_money($row['amount_open'] ?? 0)); ?></option><?php endforeach; ?></select></div>
        <div class="portal-form-row portal-grid-slim"><input type="number" step="0.01" min="0.01" name="payment_amount" placeholder="Mebleg" required><input type="date" name="payment_date" value="<?= e(date('Y-m-d')); ?>"></div>
        <div class="portal-form-row portal-grid-slim"><select name="payment_method"><option value="cash">Nagd</option><option value="bank">Bank</option><option value="terminal">Terminal</option><option value="offset">Qarsiliqli silinme</option></select><input type="text" name="payment_reference_no" placeholder="Reference"></div><div class="portal-form-row"><textarea name="payment_note" placeholder="Qeyd"></textarea></div><button class="portal-primary-btn" type="submit">Odenisi yaz</button>
    </form></section></div>
    <div class="finance-dialog" data-finance-dialog="debt-writeoff" hidden><div class="finance-dialog-backdrop" data-finance-dialog-close></div><section class="finance-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="debt-writeoff-title"><div class="finance-dialog-head"><h3 id="debt-writeoff-title">Borcu sil (tesdiq telebi)</h3><button class="finance-dialog-close" type="button" data-finance-dialog-close aria-label="Bagla">X</button></div><form class="portal-stacked-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="request_finance_debt_writeoff"><input type="hidden" name="finance_tab" value="reconciliation">
        <p><small>Bu sorgu borcu deryal silmir - BASQA admin tesdiqleyene qeder borc acig qalir.</small></p>
        <div class="portal-form-row"><select name="debt_id" required><option value="">Borc sec</option><?php foreach ($openDebts as $row): ?><option value="<?= e((string) ($row['id'] ?? 0)); ?>"><?= e((string) ($row['counterparty_name'] ?? '-')); ?> / <?= e(billing_money($row['amount_open'] ?? 0)); ?></option><?php endforeach; ?></select></div>
        <div class="portal-form-row"><input type="number" step="0.01" min="0" name="writeoff_amount" placeholder="Mebleg (bos buraxsaniz butun qaliq)"></div>
        <div class="portal-form-row"><textarea name="writeoff_reason" placeholder="Sebeb (mecburidir)" required></textarea></div>
        <button class="portal-primary-btn" type="submit">Silinme sorgusu gonder</button>
    </form></section></div>

    <div class="portal-card portal-section-gap" data-finance-cash-reconciliation>
        <div class="portal-card-head">
            <div><strong>Kassa/bank uzlasdirmasi</strong><small>Beyan edilmis (bank ekstraktı) xalis herekat ile sistemin oz qeydlerinin muqayisesi. Yalniz melumat/qeyd xarakterlidir -- heç bir ledger avtomatik duzelmir.</small></div>
            <?php if (!empty($financeContext['data']['can_write'])): ?><button class="portal-primary-btn" type="button" data-finance-dialog-open="cash-reconciliation-create">Yeni yoxlama qeydi</button><?php endif; ?>
        </div>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Dovr</th><th>Menbe</th><th>Beyan (bank)</th><th>Sistem</th><th>Ferq</th><th>Qeyd</th><th>Tarix</th></tr></thead>
                <tbody>
                    <?php foreach ($cashReconciliationRows as $row): ?>
                        <tr data-finance-row>
                            <td><?= e((string) ($row['period_start'] ?? '-')); ?> - <?= e((string) ($row['period_end'] ?? '-')); ?></td>
                            <td><?= e((string) ($row['source_label'] ?? '-')); ?></td>
                            <td><?= e(billing_money($row['declared_net_movement'] ?? 0)); ?></td>
                            <td><?= e(billing_money($row['system_net_movement'] ?? 0)); ?></td>
                            <td><strong<?= abs((float) ($row['discrepancy_amount'] ?? 0)) > 0.01 ? ' class="dash-badge-warning"' : ''; ?>><?= e(billing_money($row['discrepancy_amount'] ?? 0)); ?></strong></td>
                            <td><?= e((string) ($row['note'] ?? '-')); ?></td>
                            <td><?= e((string) ($row['created_at'] ?? '-')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($cashReconciliationRows === []): ?><tr><td colspan="7" class="finance-empty">Uzlasdirma qeydi yoxdur.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (!empty($financeContext['data']['can_write'])): ?>
    <div class="finance-dialog" data-finance-dialog="cash-reconciliation-create" hidden>
        <div class="finance-dialog-backdrop" data-finance-dialog-close></div>
        <section class="finance-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="cash-reconciliation-create-title">
            <div class="finance-dialog-head"><h3 id="cash-reconciliation-create-title">Yeni kassa/bank yoxlamasi</h3><button class="finance-dialog-close" type="button" data-finance-dialog-close aria-label="Bagla">X</button></div>
            <form class="portal-stacked-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="record_finance_cash_reconciliation">
                <input type="hidden" name="finance_tab" value="reconciliation">
                <div class="portal-form-row portal-grid-slim"><input type="date" name="period_start" required><input type="date" name="period_end" required></div>
                <div class="portal-form-row"><input type="text" name="source_label" placeholder="Menbe (meselen: Kapital Bank hesabi)" required maxlength="96"></div>
                <div class="portal-form-row"><input type="number" step="0.01" name="declared_net_movement" placeholder="Bank ekstraktinda gorunen xalis herekat" required></div>
                <div class="portal-form-row"><textarea name="note" maxlength="255" placeholder="Qeyd"></textarea></div>
                <button class="portal-primary-btn" type="submit">Yoxlamani qeyd et</button>
            </form>
        </section>
    </div>
    <?php endif; ?>
</div>
