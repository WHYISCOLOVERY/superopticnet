<?php
declare(strict_types=1);

$pageData = (array) ($financeContext['data']['page'] ?? []);
$paymentRows = (array) ($pageData['rows'] ?? []);
?>
<div data-finance-payments>
    <div class="portal-card-head"><div><strong>Odenis hereketleri</strong><small>Billing operatorlarinin qebul etdiyi musteri odenislerinin Maliyyede read-only ledger gorunusu.</small></div></div>
    <form class="finance-filter" method="get" action="operations-center.php" data-finance-filter>
        <input type="hidden" name="section" value="finance"><input type="hidden" name="finance_tab" value="payments">
        <div class="portal-form-row"><label>Axtaris</label><input type="search" name="search" value="<?= e((string) ($_GET['search'] ?? '')); ?>" placeholder="Abuneci, tranzaksiya, metod"></div>
        <div class="portal-form-row"><label>Ilkin tarix</label><input type="date" name="start_date" value="<?= e((string) ($_GET['start_date'] ?? '')); ?>"></div>
        <div class="portal-form-row"><label>Son tarix</label><input type="date" name="end_date" value="<?= e((string) ($_GET['end_date'] ?? '')); ?>"></div>
        <button class="portal-primary-btn" type="submit">Tetbiq et</button>
    </form>
    <div class="portal-table-wrap"><table class="portal-table"><thead><tr><th>ID</th><th>Abuneci</th><th>Ad</th><th>Mebleg</th><th>Metod</th><th>Tranzaksiya</th><th>Tarix</th></tr></thead><tbody>
        <?php foreach ($paymentRows as $row): ?><tr data-finance-row><td>#<?= e((string) ($row['id'] ?? 0)); ?></td><td><?= e((string) ($row['subscriber_code'] ?? '-')); ?></td><td><?= e((string) ($row['customer_name'] ?? '-')); ?></td><td><?= e(billing_money($row['amount'] ?? 0)); ?></td><td><?= e((string) ($row['payment_method'] ?? '-')); ?></td><td><?= e((string) ($row['transaction_id'] ?? '-')); ?></td><td><?= e((string) ($row['created_at'] ?? '-')); ?></td></tr><?php endforeach; ?>
        <?php if ($paymentRows === []): ?><tr><td colspan="7" class="finance-empty">Odenis tapilmadi.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php require __DIR__ . '/_pagination.php'; ?>
</div>
