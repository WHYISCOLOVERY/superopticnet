<?php
declare(strict_types=1);

$visibleDatasets = $selectedMetric === 'all'
    ? array_slice($statisticsDatasets, 0, 4, true)
    : [$selectedMetric => $statisticsDatasets[$selectedMetric]];
$chartColors = ['#0e848d', '#ff8b52', '#6366f1', '#22c55e', '#ef4444', '#14b8a6'];
?>
<div data-finance-statistics-panel data-report-type="<?= e((string) ($financeStatistics['report_type'] ?? 'overview')); ?>">
    <div class="finance-kpis">
        <?php foreach (array_slice((array) ($financeStatistics['kpis'] ?? []), 0, 6) as $kpi): ?><article class="finance-kpi"><span><?= e((string) ($kpi['label'] ?? '-')); ?></span><strong><?= ($kpi['kind'] ?? 'money') === 'count' ? e(number_format((float) ($kpi['value'] ?? 0), 0, '.', ' ')) : e(billing_money($kpi['value'] ?? 0)); ?></strong></article><?php endforeach; ?>
    </div>
    <div class="finance-chart-grid" style="margin-top:14px">
        <?php foreach ($visibleDatasets as $dataset): ?>
            <?php
            $rows = (array) ($dataset['rows'] ?? []);
            $total = array_sum(array_map(static fn (array $row): float => (float) ($row['value'] ?? 0), $rows));
            $maxValue = 1.0;
            foreach ($rows as $row) {
                $maxValue = max($maxValue, (float) ($row['value'] ?? 0));
            }
            $gradientParts = [];
            $cursor = 0.0;
            foreach (array_values($rows) as $index => $row) {
                $next = $total > 0 ? min(100, $cursor + (((float) ($row['value'] ?? 0) / $total) * 100)) : $cursor;
                $gradientParts[] = $chartColors[$index % count($chartColors)] . ' ' . round($cursor, 2) . '% ' . round($next, 2) . '%';
                $cursor = $next;
            }
            $ring = $gradientParts !== [] ? implode(',', $gradientParts) : '#e5e7eb 0 100%';
            ?>
            <article class="finance-chart" data-finance-stat-chart>
                <div class="portal-card-head"><strong><?= e((string) ($dataset['title'] ?? '-')); ?></strong><span class="portal-pill"><?= e((string) ($financeStatistics['start_date'] ?? '')); ?> - <?= e((string) ($financeStatistics['end_date'] ?? '')); ?></span></div>
                <div style="display:grid;grid-template-columns:120px 1fr;gap:16px;align-items:center">
                    <div style="width:116px;aspect-ratio:1;border-radius:50%;background:conic-gradient(<?= e($ring); ?>);display:grid;place-items:center"><div style="width:72px;aspect-ratio:1;border-radius:50%;background:#fff;display:grid;place-items:center;text-align:center;font-weight:800;font-size:.76rem"><?= e(billing_money($total)); ?></div></div>
                    <div><?php foreach ($rows as $row): ?><div class="finance-chart-row"><span><?= e((string) ($row['label'] ?? '-')); ?></span><i class="finance-chart-track"><b style="width:<?= e((string) max(3, min(100, round(((float) ($row['value'] ?? 0) / $maxValue) * 100, 2)))); ?>%"></b></i><strong><?= e(billing_money($row['value'] ?? 0)); ?></strong></div><?php endforeach; ?><?php if ($rows === []): ?><p class="finance-empty">Secilmis dovrde melumat yoxdur.</p><?php endif; ?></div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
