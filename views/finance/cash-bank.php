<?php
declare(strict_types=1);

$financeStatistics = (array) ($financeContext['data']['statistics'] ?? []);
$methodDataset = (array) ($financeStatistics['datasets']['payment_methods'] ?? []);
$methodRows = (array) ($methodDataset['rows'] ?? []);
$methodMax = 1.0;
foreach ($methodRows as $row) {
    $methodMax = max($methodMax, (float) ($row['value'] ?? 0));
}
?>
<div data-finance-cash-bank>
    <div class="portal-card-head"><div><strong>Kassa ve bank</strong><small>Secilmis dovrde odenis menbeleri ve terminal/bank/kassa uzre daxilolmalar.</small></div></div>
    <form class="finance-filter" method="get" action="operations-center.php" data-finance-filter>
        <input type="hidden" name="section" value="finance"><input type="hidden" name="finance_tab" value="cash-bank">
        <div class="portal-form-row"><label>Tarix araligi</label><select name="range"><option value="today"<?= ($financeStatistics['range'] ?? '') === 'today' ? ' selected' : ''; ?>>Bugun</option><option value="week"<?= ($financeStatistics['range'] ?? '') === 'week' ? ' selected' : ''; ?>>Son 1 hefte</option><option value="month"<?= ($financeStatistics['range'] ?? '') === 'month' ? ' selected' : ''; ?>>Son 1 ay</option><option value="year"<?= ($financeStatistics['range'] ?? '') === 'year' ? ' selected' : ''; ?>>Son 1 il</option><option value="custom"<?= ($financeStatistics['range'] ?? '') === 'custom' ? ' selected' : ''; ?>>Secilmis tarix</option></select></div>
        <div class="portal-form-row"><label>Ilkin tarix</label><input type="date" name="start_date" value="<?= e((string) ($financeStatistics['start_date'] ?? '')); ?>"></div>
        <div class="portal-form-row"><label>Son tarix</label><input type="date" name="end_date" value="<?= e((string) ($financeStatistics['end_date'] ?? '')); ?>"></div>
        <button class="portal-primary-btn" type="submit">Yenile</button>
    </form>
    <article class="finance-chart">
        <strong>Odenis menbeleri</strong>
        <?php foreach ($methodRows as $row): ?><div class="finance-chart-row"><span><?= e((string) ($row['label'] ?? '-')); ?></span><i class="finance-chart-track"><b style="width:<?= e((string) max(3, min(100, round(((float) ($row['value'] ?? 0) / $methodMax) * 100, 2)))); ?>%"></b></i><strong><?= e(billing_money($row['value'] ?? 0)); ?></strong></div><?php endforeach; ?>
        <?php if ($methodRows === []): ?><p class="finance-empty">Bu dovr ucun kassa/bank hereketi yoxdur.</p><?php endif; ?>
    </article>
</div>
