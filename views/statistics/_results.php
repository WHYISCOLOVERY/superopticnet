<?php
declare(strict_types=1);

$datasets = (array) ($statisticsContext['datasets'] ?? []);
$kpis = (array) ($statisticsContext['kpis'] ?? []);
$selectedMetric = (string) ($statisticsContext['selected_metric'] ?? 'all');
$startDate = (string) ($statisticsContext['start_date'] ?? '');
$endDate = (string) ($statisticsContext['end_date'] ?? '');
$sourceNotes = (array) ($statisticsContext['source_notes'] ?? []);

$visibleDatasets = $selectedMetric === 'all'
    ? $datasets
    : array_intersect_key($datasets, [$selectedMetric => true]);
$visibleKpis = $selectedMetric === 'all'
    ? $kpis
    : array_intersect_key($kpis, [$selectedMetric => true]);

$formatValue = static function (string $kind, float $value): string {
    return $kind === 'money' ? billing_money($value) : number_format($value, 0, '.', ' ');
};

$ringRadius = 52;
$circumference = 2 * M_PI * $ringRadius;
?>
<div class="statistics-results" data-statistics-results>
    <p class="statistics-range-note"><?= e($startDate); ?> &ndash; <?= e($endDate); ?></p>
    <?php if ($visibleKpis !== []): ?>
        <div class="statistics-kpis">
            <?php foreach ($visibleKpis as $kpi): ?>
                <article class="statistics-kpi">
                    <span><?= e((string) ($kpi['label'] ?? '-')); ?></span>
                    <strong><?= e($formatValue((string) ($kpi['kind'] ?? 'count'), (float) ($kpi['value'] ?? 0))); ?></strong>
                    <small><?= (int) ($kpi['count'] ?? 0); ?> qeyd</small>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($visibleDatasets === []): ?>
        <div class="portal-empty-state"><p>Bu dövr və seçim üzrə göstəriləcək məlumat yoxdur.</p></div>
    <?php endif; ?>

    <?php foreach ($visibleDatasets as $dataset): ?>
        <?php
        $kind = (string) ($dataset['kind'] ?? 'count');
        $rows = array_values((array) ($dataset['rows'] ?? []));
        $total = array_sum(array_column($rows, 'value'));
        $maxValue = 0.0;
        foreach ($rows as $row) {
            $maxValue = max($maxValue, (float) ($row['value'] ?? 0));
        }
        $maxValue = $maxValue > 0 ? $maxValue : 1.0;
        $cumulative = 0.0;
        ?>
        <section class="statistics-metric" data-statistics-chart data-metric="<?= e((string) ($dataset['key'] ?? '')); ?>">
            <h2><?= e((string) ($dataset['title'] ?? '')); ?></h2>
            <?php if (isset($sourceNotes[(string) ($dataset['key'] ?? '')])): ?>
                <p class="statistics-source-note"><?= e((string) $sourceNotes[(string) $dataset['key']]); ?></p>
            <?php endif; ?>
            <?php if ($rows === []): ?>
                <div class="portal-empty-state"><p>Seçilmiş dövrdə məlumat yoxdur.</p></div>
            <?php else: ?>
                <div class="statistics-ring-wrap">
                    <svg class="statistics-ring" viewBox="0 0 120 120" role="img" aria-label="<?= e((string) ($dataset['title'] ?? '')); ?> bölgüsü, cəmi <?= e($formatValue($kind, $total)); ?>">
                        <g transform="rotate(-90 60 60)">
                            <circle class="statistics-ring-track" cx="60" cy="60" r="<?= $ringRadius; ?>" fill="none" stroke-width="16"></circle>
                            <?php foreach ($rows as $index => $row): ?>
                                <?php
                                $value = (float) ($row['value'] ?? 0);
                                $share = $total > 0 ? $value / $total : 0;
                                $segmentLength = $share * $circumference;
                                $offset = $cumulative;
                                $cumulative += $segmentLength;
                                $colorIndex = $index % 8;
                                ?>
                                <?php if ($segmentLength > 0.01): ?>
                                    <circle class="statistics-ring-segment" style="--row-color:var(--stat-c<?= $colorIndex; ?>)" cx="60" cy="60" r="<?= $ringRadius; ?>" fill="none" stroke-width="16" stroke-dasharray="<?= round($segmentLength, 2); ?> <?= round($circumference - $segmentLength, 2); ?>" stroke-dashoffset="<?= round(-$offset, 2); ?>"></circle>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </g>
                    </svg>
                    <div class="statistics-ring-center"><strong><?= e($formatValue($kind, $total)); ?></strong><span>Cəmi</span></div>
                </div>
                <ul class="statistics-bars">
                    <?php foreach ($rows as $index => $row): ?>
                        <?php
                        $value = (float) ($row['value'] ?? 0);
                        $colorIndex = $index % 8;
                        $percent = max(3, min(100, round(($value / $maxValue) * 100, 1)));
                        ?>
                        <li class="statistics-bar-row" style="--row-color:var(--stat-c<?= $colorIndex; ?>)">
                            <span class="statistics-bar-label"><?= e((string) ($row['label'] ?? '-')); ?></span>
                            <span class="statistics-bar-track"><i class="statistics-bar-fill" style="width:<?= $percent; ?>%"></i></span>
                            <strong class="statistics-bar-value"><?= e($formatValue($kind, $value)); ?></strong>
                            <small class="statistics-bar-count"><?= (int) ($row['count'] ?? 0); ?> qeyd</small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
