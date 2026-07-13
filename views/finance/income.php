<?php
declare(strict_types=1);

$pageData = (array) ($financeContext['data']['page'] ?? []);
$rows = (array) ($pageData['rows'] ?? []);
$branches = (array) ($financeContext['data']['branches'] ?? []);
$counterparties = (array) ($financeContext['data']['counterparties'] ?? []);
$canWrite = !empty($financeContext['data']['can_write']);
?>
<div data-finance-income>
    <div class="portal-card-head">
        <div><strong>Gelirler</strong><small>Billing odenislerinden kenar manual gelir qeydleri.</small></div>
        <?php if ($canWrite): ?><button class="portal-primary-btn" type="button" data-finance-dialog-open="income-create">Yeni gelir</button><?php endif; ?>
    </div>
    <form class="finance-filter" method="get" action="operations-center.php" data-finance-filter>
        <input type="hidden" name="section" value="finance"><input type="hidden" name="finance_tab" value="income">
        <div class="portal-form-row"><label>Axtaris</label><input type="search" name="search" value="<?= e((string) ($_GET['search'] ?? '')); ?>"></div>
        <span></span><span></span><button class="portal-primary-btn">Axtar</button>
    </form>
    <div class="portal-table-wrap"><table class="portal-table"><thead><tr><th>Tarix</th><th>Basliq</th><th>Kateqoriya</th><th>Qarsi teref</th><th>Menbe</th><th>Mebleg</th><th>Status</th><th>Emeliyyat</th></tr></thead><tbody>
        <?php foreach ($rows as $row):
            $payload = [
                'id' => (int) ($row['id'] ?? 0), 'title' => $row['title'] ?? '', 'category' => $row['category'] ?? '',
                'amount' => $row['amount'] ?? '', 'currency' => $row['currency'] ?? 'AZN', 'transaction_date' => $row['transaction_date'] ?? '',
                'branch_id' => $row['branch_id'] ?? '', 'counterparty_id' => $row['counterparty_id'] ?? '',
                'payment_source' => $row['payment_source'] ?? '', 'reference_no' => $row['reference_no'] ?? '', 'note' => $row['note'] ?? '',
            ];
        ?><tr data-finance-row>
            <td><?= e((string) ($row['transaction_date'] ?? '-')); ?></td><td><?= e((string) ($row['title'] ?? '-')); ?></td><td><?= e((string) ($row['category'] ?? '-')); ?></td>
            <td><?= e((string) ($row['counterparty_name'] ?? '-')); ?></td><td><?= e((string) ($row['payment_source'] ?? '-')); ?></td>
            <td><?= e(billing_money($row['amount'] ?? 0)); ?> <?= e((string) ($row['currency'] ?? 'AZN')); ?></td><td><?= e((string) ($row['status'] ?? 'posted')); ?></td>
            <td><?php if ($canWrite && ($row['status'] ?? 'posted') === 'posted'): ?>
                <button class="portal-ghost-btn" type="button" data-finance-income-edit data-finance-dialog-open="income-create" data-income='<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'); ?>'>Duzelt</button>
                <form method="post" class="portal-inline-form" onsubmit="return confirm('Bu gelir qeydini legv etmek istediyinize eminsiniz?');"><input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="void_finance_income"><input type="hidden" name="entry_id" value="<?= e((string) ($row['id'] ?? 0)); ?>"><input name="reason" placeholder="Legv sebebi" required><button class="portal-ghost-btn" type="submit">Legv et</button></form>
            <?php else: ?><small>-</small><?php endif; ?></td>
        </tr><?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="8" class="finance-empty">Gelir qeydi yoxdur.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php require __DIR__ . '/_pagination.php'; ?>
    <?php if ($canWrite): ?><div class="finance-dialog" data-finance-dialog="income-create" hidden><div class="finance-dialog-backdrop" data-finance-dialog-close></div><section class="finance-dialog-panel" role="dialog" aria-modal="true"><div class="finance-dialog-head"><div><h3 data-income-dialog-title>Yeni gelir</h3><small>Manual gelir maliyye statistikasina daxil edilir.</small></div><button class="finance-dialog-close" type="button" data-finance-dialog-close>X</button></div><form class="portal-stacked-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="create_finance_income"><input type="hidden" name="finance_tab" value="income"><input type="hidden" name="entry_id" value="">
        <div class="portal-form-row portal-grid-slim"><input name="title" placeholder="Basliq" required><input name="category" placeholder="Kateqoriya" required></div>
        <div class="portal-form-row portal-grid-slim"><input type="number" min="0.01" step="0.01" name="amount" placeholder="Mebleg" required><select name="currency" required><option value="AZN">AZN</option><option value="USD">USD</option><option value="EUR">EUR</option></select></div>
        <div class="portal-form-row portal-grid-slim"><input type="date" name="transaction_date" value="<?= e(date('Y-m-d')); ?>" required><select name="branch_id"><option value="">Filial</option><?php foreach ($branches as $branch): ?><option value="<?= e((string) $branch['id']); ?>"><?= e((string) ($branch['name'] ?? '-')); ?></option><?php endforeach; ?></select></div>
        <div class="portal-form-row portal-grid-slim"><select name="counterparty_id"><option value="">Qarsi teref</option><?php foreach ($counterparties as $counterparty): ?><option value="<?= e((string) $counterparty['id']); ?>"><?= e((string) $counterparty['display_name']); ?></option><?php endforeach; ?></select><input name="payment_source" placeholder="Kassa/bank/terminal"></div>
        <input name="reference_no" placeholder="Istinad nomresi"><textarea name="note" placeholder="Qeyd"></textarea><textarea name="reason" placeholder="Duzelis sebebi (movcud qeyd deyisirse mutleqdir)"></textarea><button class="portal-primary-btn">Geliri saxla</button>
    </form></section></div><?php endif; ?>
</div>
