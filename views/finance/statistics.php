<?php
declare(strict_types=1);

$financeStatistics = (array) ($financeContext['data']['statistics'] ?? []);
$selectedMetric = clean_text((string) ($_GET['finance_metric'] ?? 'all'));
$statisticsDatasets = (array) ($financeStatistics['datasets'] ?? []);
if ($selectedMetric !== 'all' && !array_key_exists($selectedMetric, $statisticsDatasets)) {
    $selectedMetric = 'all';
}
?>
<div data-finance-statistics>
    <div class="portal-card-head"><div><strong>Maliyye statistikasi</strong><small>Yalniz gelir, xerc, qaime, borc, odenis menbeyi ve qarsi teref melumatlari.</small></div></div>
    <form class="finance-filter" method="get" action="operations-center.php" data-finance-statistics-form>
        <input type="hidden" name="section" value="finance"><input type="hidden" name="finance_tab" value="statistics"><input type="hidden" name="report_type" value="<?= e((string) ($financeStatistics['report_type'] ?? 'overview')); ?>">
        <div class="portal-form-row"><label>Chart</label><select name="finance_metric"><option value="all"<?= $selectedMetric === 'all' ? ' selected' : ''; ?>>Butun hamisi</option><?php foreach ($statisticsDatasets as $metricKey => $dataset): ?><option value="<?= e((string) $metricKey); ?>"<?= $selectedMetric === $metricKey ? ' selected' : ''; ?>><?= e((string) ($dataset['title'] ?? $metricKey)); ?></option><?php endforeach; ?></select></div>
        <div class="portal-form-row"><label>Tarix araligi</label><select name="range"><option value="today"<?= ($financeStatistics['range'] ?? '') === 'today' ? ' selected' : ''; ?>>Bugun</option><option value="yesterday"<?= ($financeStatistics['range'] ?? '') === 'yesterday' ? ' selected' : ''; ?>>Dunen</option><option value="week"<?= ($financeStatistics['range'] ?? '') === 'week' ? ' selected' : ''; ?>>Son 1 hefte</option><option value="month"<?= ($financeStatistics['range'] ?? '') === 'month' ? ' selected' : ''; ?>>Son 1 ay</option><option value="year"<?= ($financeStatistics['range'] ?? '') === 'year' ? ' selected' : ''; ?>>Son 1 il</option><option value="custom"<?= ($financeStatistics['range'] ?? '') === 'custom' ? ' selected' : ''; ?>>Secilmis tarix</option></select></div>
        <div class="portal-form-row"><label>Ilkin tarix</label><input type="date" name="start_date" value="<?= e((string) ($financeStatistics['start_date'] ?? '')); ?>"></div>
        <div class="portal-form-row"><label>Son tarix</label><input type="date" name="end_date" value="<?= e((string) ($financeStatistics['end_date'] ?? '')); ?>"></div>
        <button class="portal-primary-btn" type="submit">Yenile</button>
    </form>
    <p role="status" aria-live="polite" data-finance-statistics-status></p>
    <?php require __DIR__ . '/_statistics-results.php'; ?>
    <?php $budgetVsActual = (array) ($financeContext['data']['budget_vs_actual'] ?? []); ?>
    <?php $cashflowForecast = (array) ($financeContext['data']['cashflow_forecast'] ?? []); ?>
    <?php if ($budgetVsActual !== []): ?>
    <div class="portal-card portal-section-gap" data-finance-budget-panel>
        <div class="portal-card-head"><div><strong>Buget vs faktiki</strong><small>Aylik plan/faktiki muqayisesi. Yalniz manual Finance ledger (gelir/xerc) kateqoriyalarini eks etdirir.</small></div></div>
        <form class="finance-filter" method="get" action="operations-center.php">
            <input type="hidden" name="section" value="finance"><input type="hidden" name="finance_tab" value="statistics">
            <div class="portal-form-row"><label>Ay</label><input type="month" name="budget_month" value="<?= e((string) ($budgetVsActual['period_month'] ?? '')); ?>"></div>
            <button class="portal-primary-btn" type="submit">Goster</button>
        </form>
        <div class="finance-kpis" style="margin-top:10px">
            <article class="finance-kpi"><span>Planlasdirilan gelir</span><strong><?= e(billing_money($budgetVsActual['totals']['budgeted_income'] ?? 0)); ?></strong></article>
            <article class="finance-kpi"><span>Faktiki gelir</span><strong><?= e(billing_money($budgetVsActual['totals']['actual_income'] ?? 0)); ?></strong></article>
            <article class="finance-kpi"><span>Planlasdirilan xerc</span><strong><?= e(billing_money($budgetVsActual['totals']['budgeted_expense'] ?? 0)); ?></strong></article>
            <article class="finance-kpi"><span>Faktiki xerc</span><strong><?= e(billing_money($budgetVsActual['totals']['actual_expense'] ?? 0)); ?></strong></article>
            <article class="finance-kpi"><span>Xalis (plan)</span><strong><?= e(billing_money($budgetVsActual['totals']['net_budgeted'] ?? 0)); ?></strong></article>
            <article class="finance-kpi"><span>Xalis (faktiki)</span><strong><?= e(billing_money($budgetVsActual['totals']['net_actual'] ?? 0)); ?></strong></article>
        </div>
        <div class="portal-table-wrap portal-section-gap">
            <table class="portal-table">
                <thead><tr><th>Nov</th><th>Kateqoriya</th><th>Buget</th><th>Faktiki</th><th>Ferq</th><th>Ferq %</th></tr></thead>
                <tbody>
                    <?php foreach ((array) ($budgetVsActual['rows'] ?? []) as $budgetRow): ?>
                        <tr>
                            <td><?= e((string) ($budgetRow['entry_type'] ?? '-')); ?></td>
                            <td><?= e((string) ($budgetRow['category'] ?? '-')); ?><?= empty($budgetRow['has_budget']) ? ' <span class="portal-pill">buget yoxdur</span>' : ''; ?></td>
                            <td><?= e(billing_money($budgetRow['budgeted'] ?? 0)); ?></td>
                            <td><?= e(billing_money($budgetRow['actual'] ?? 0)); ?></td>
                            <td><?= e(billing_money($budgetRow['variance'] ?? 0)); ?></td>
                            <td><?= $budgetRow['variance_percent'] === null ? '-' : e(number_format((float) $budgetRow['variance_percent'], 1)) . '%'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (($budgetVsActual['rows'] ?? []) === []): ?><tr><td colspan="6">Bu ay ucun buget setri ve faktiki qeyd yoxdur.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($financeContext['data']['can_write'])): ?>
        <form class="portal-stacked-form portal-section-gap" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="save_finance_budget">
            <div class="portal-form-row"><label>Ay</label><input type="month" name="period_month" value="<?= e((string) ($budgetVsActual['period_month'] ?? '')); ?>" required></div>
            <div class="portal-form-row"><label>Nov</label><select name="entry_type"><option value="income">Gelir</option><option value="expense">Xerc</option></select></div>
            <div class="portal-form-row"><label>Kateqoriya</label><input name="category" maxlength="96" required></div>
            <div class="portal-form-row"><label>Buget mebleg</label><input type="number" name="budgeted_amount" min="0" step="0.01" required></div>
            <div class="portal-form-row"><label>Qeyd</label><input name="note" maxlength="255"></div>
            <button class="portal-primary-btn" type="submit">Buget setrini yadda saxla</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($cashflowForecast !== []): ?>
    <div class="portal-card portal-section-gap" data-finance-cashflow-panel>
        <div class="portal-card-head"><div><strong>Pul axini proqnozu (30/60/90 gun)</strong><small>Son <?= e((string) ($cashflowForecast['trailing_window_days'] ?? 90)); ?> gunun gunluk ortasi esasinda xetti proqnoz.</small></div></div>
        <div class="finance-kpis">
            <?php foreach ((array) ($cashflowForecast['buckets'] ?? []) as $bucket): ?>
                <article class="finance-kpi">
                    <span><?= e((string) ($bucket['horizon_days'] ?? 0)); ?> gun</span>
                    <strong><?= e(billing_money($bucket['projected_net'] ?? 0)); ?></strong>
                    <small>gelir <?= e(billing_money($bucket['projected_income'] ?? 0)); ?> / xerc <?= e(billing_money($bucket['projected_expense'] ?? 0)); ?></small>
                </article>
            <?php endforeach; ?>
        </div>
        <small class="portal-section-gap"><?= e((string) ($cashflowForecast['notes']['method'] ?? '')); ?> <?= e((string) ($cashflowForecast['notes']['balance'] ?? '')); ?></small>
    </div>
    <?php endif; ?>
</div>
