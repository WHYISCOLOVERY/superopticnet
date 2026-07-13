<?php
declare(strict_types=1);

$financeKpis = array_slice((array) ($financeContext['data']['kpis'] ?? []), 0, 6);
$recentPayments = array_slice((array) ($financeContext['data']['recent_payments']['rows'] ?? []), 0, 5);
$overdueDebts = array_slice((array) ($financeContext['data']['overdue_debts']['rows'] ?? []), 0, 5);
?>
<div data-finance-overview>
    <div class="finance-kpis">
        <?php foreach ($financeKpis as $kpi): ?>
            <article class="finance-kpi">
                <span><?= e((string) ($kpi['label'] ?? '-')); ?></span>
                <strong><?= ($kpi['kind'] ?? 'money') === 'count' ? e(number_format((float) ($kpi['value'] ?? 0), 0, '.', ' ')) : e(billing_money($kpi['value'] ?? 0)); ?></strong>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="finance-shortcuts">
        <?php foreach (array_slice($financeTabs, 1) as $tabKey => $tabLabel): ?>
            <a class="finance-shortcut" data-finance-nav href="<?= e(app_finance_url((string) $tabKey, [], $financeLang)); ?>">
                <strong><?= e((string) $tabLabel); ?></strong>
                <small>Bolmeni ac</small>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="portal-grid portal-grid-2" style="margin-top:14px">
        <article class="portal-muted-card">
            <strong>Son odenisler</strong>
            <div class="spog-list" style="margin-top:10px">
                <?php foreach ($recentPayments as $row): ?>
                    <div class="spog-list-item"><strong><?= e((string) ($row['subscriber_code'] ?? '-')); ?> / <?= e(billing_money($row['amount'] ?? 0)); ?></strong><small><?= e((string) ($row['payment_method'] ?? '-')); ?> / <?= e((string) ($row['created_at'] ?? '-')); ?></small></div>
                <?php endforeach; ?>
                <?php if ($recentPayments === []): ?><div class="finance-empty">Odenis yoxdur.</div><?php endif; ?>
            </div>
        </article>
        <article class="portal-muted-card">
            <strong>Aciq borc ve alacaqlar</strong>
            <div class="spog-list" style="margin-top:10px">
                <?php foreach ($overdueDebts as $row): ?>
                    <div class="spog-list-item"><strong><?= e((string) ($row['counterparty_name'] ?? '-')); ?> / <?= e(billing_money($row['amount_open'] ?? 0)); ?></strong><small><?= e((string) ($row['ledger_type'] ?? '-')); ?> / <?= e((string) ($row['due_date'] ?? '-')); ?></small></div>
                <?php endforeach; ?>
                <?php if ($overdueDebts === []): ?><div class="finance-empty">Aciq ledger qeydi yoxdur.</div><?php endif; ?>
            </div>
        </article>
    </div>
</div>
