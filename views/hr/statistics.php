<?php
declare(strict_types=1);
$hrStatistics = (array) ($hrContext['data']['statistics'] ?? []);
$selectedMetric = clean_text((string) (($hrContext['query']['hr_metric'] ?? 'all')));
$statisticsDatasets = (array) ($hrStatistics['datasets'] ?? []);
if ($selectedMetric !== 'all' && !array_key_exists($selectedMetric, $statisticsDatasets)) { $selectedMetric = 'all'; }
?>
<div data-hr-statistics>
    <div class="portal-card-head"><div><strong>HR statistikasi</strong><small>Yalniz maas, vergi, mezuniyyet, ezamiyyet, sened ve performans melumatlari.</small></div></div>
    <form class="hr-filter" method="get" action="operations-center.php" data-hr-statistics-form><input type="hidden" name="section" value="hr"><input type="hidden" name="hr_tab" value="statistics"><div class="portal-form-row"><label>Chart</label><select name="hr_metric"><option value="all"<?= $selectedMetric === 'all' ? ' selected' : ''; ?>>Butun hamisi</option><?php foreach ($statisticsDatasets as $metricKey => $dataset): ?><option value="<?= e((string) $metricKey); ?>"<?= $selectedMetric === $metricKey ? ' selected' : ''; ?>><?= e((string) ($dataset['title'] ?? $metricKey)); ?></option><?php endforeach; ?></select></div><div class="portal-form-row"><label>Tarix araligi</label><select name="range"><option value="today"<?= ($hrStatistics['range'] ?? '') === 'today' ? ' selected' : ''; ?>>Bugun</option><option value="week"<?= ($hrStatistics['range'] ?? '') === 'week' ? ' selected' : ''; ?>>Son 1 hefte</option><option value="month"<?= ($hrStatistics['range'] ?? '') === 'month' ? ' selected' : ''; ?>>Son 1 ay</option><option value="year"<?= ($hrStatistics['range'] ?? '') === 'year' ? ' selected' : ''; ?>>Son 1 il</option><option value="custom"<?= ($hrStatistics['range'] ?? '') === 'custom' ? ' selected' : ''; ?>>Secilmis tarix</option></select></div><div class="portal-form-row"><label>Ilkin tarix</label><input type="date" name="start_date" value="<?= e((string) ($hrStatistics['start_date'] ?? '')); ?>"></div><div class="portal-form-row"><label>Son tarix</label><input type="date" name="end_date" value="<?= e((string) ($hrStatistics['end_date'] ?? '')); ?>"></div><button class="portal-primary-btn" type="submit">Yenile</button></form>
    <p role="status" aria-live="polite" data-hr-statistics-status></p>
    <?php require __DIR__ . '/_statistics-results.php'; ?>
</div>
