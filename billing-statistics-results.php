<?php
declare(strict_types=1);

if (!isset($visibleStatDatasets, $startDate, $endDate)) {
    return;
}
?>
<section
    class="stat-chart-stack portal-reveal is-visible"
    aria-label="Secilen statistika chartlari"
    aria-live="polite"
    data-statistics-results
    data-start-date="<?= e((string) $startDate); ?>"
    data-end-date="<?= e((string) $endDate); ?>"
>
    <?php foreach ($visibleStatDatasets as $statMetricKey => $statDataset): ?>
        <?php
        $statRows = is_array($statDataset['rows'] ?? null) ? $statDataset['rows'] : [];
        $statKind = (string) ($statDataset['kind'] ?? 'money');
        $statTotal = billing_stat_dataset_total($statRows);
        $statMax = billing_stat_dataset_max($statRows);
        $statRing = billing_stat_ring_gradient($statRows);
        ?>
        <article class="stat-chart-panel" data-stat-chart-panel data-stat-chart="<?= e((string) $statMetricKey); ?>">
            <div class="stat-chart-head">
                <div>
                    <h3><?= e((string) ($statDataset['title'] ?? $statMetricKey)); ?></h3>
                    <p><?= e((string) ($statDataset['subtitle'] ?? '')); ?></p>
                </div>
                <span class="portal-pill"><?= e((string) $startDate); ?> - <?= e((string) $endDate); ?></span>
            </div>
            <?php if ($statRows === [] || $statTotal <= 0.0): ?>
                <div class="stat-chart-empty"><?= e((string) ($statDataset['empty'] ?? 'Data yoxdur.')); ?></div>
            <?php else: ?>
                <div class="stat-chart-body">
                    <div class="stat-donut" style="--stat-ring:<?= e($statRing); ?>">
                        <div class="stat-donut-core">
                            <span>Cem</span>
                            <strong><?= e(billing_stat_format_value($statTotal, $statKind)); ?></strong>
                        </div>
                    </div>
                    <div class="stat-chart-bars">
                        <?php foreach ($statRows as $statRow): ?>
                            <?php
                            $rowValue = billing_stat_metric_value($statRow);
                            $rowPercent = billing_percent($rowValue, $statMax);
                            $rowCount = (int) ($statRow['count'] ?? 0);
                            ?>
                            <div class="stat-chart-row">
                                <span title="<?= e((string) ($statRow['label'] ?? '-')); ?>"><?= e((string) ($statRow['label'] ?? '-')); ?></span>
                                <i class="stat-chart-track"><b class="stat-chart-fill" style="--stat-bar:<?= e((string) ($statRow['color'] ?? '#0e848d')); ?>;--stat-width:<?= e((string) $rowPercent); ?>%"></b></i>
                                <strong class="stat-chart-value">
                                    <?= e(billing_stat_format_value($rowValue, $statKind)); ?>
                                    <?php if ($rowCount > 0): ?><small><?= e((string) $rowCount); ?> qeyd</small><?php endif; ?>
                                </strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
