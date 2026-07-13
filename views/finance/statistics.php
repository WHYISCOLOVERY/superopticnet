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
</div>
