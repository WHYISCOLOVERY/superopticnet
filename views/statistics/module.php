<?php
declare(strict_types=1);

$availableMetrics = (array) ($statisticsContext['available_metrics'] ?? []);
$selectedMetric = (string) ($statisticsContext['selected_metric'] ?? 'all');
$range = (string) ($statisticsContext['range'] ?? 'month');
$startDate = (string) ($statisticsContext['start_date'] ?? '');
$endDate = (string) ($statisticsContext['end_date'] ?? '');
$rangeOptions = [
    'today' => 'Bu gün',
    'yesterday' => 'Dünən',
    'week' => 'Son 1 həftə',
    'month' => 'Son 1 ay',
    'year' => 'Son 1 il',
    'custom' => 'Seçilmiş tarix',
];
?>
<section class="statistics-center portal-reveal" data-statistics-module>
    <header class="statistics-head">
        <span class="portal-kicker">Şirkət statistikası</span>
        <h1>Bütün şöbələrin ümumi analitikası</h1>
        <p>Maliyyə, müştəri, mesaj, HR və şəbəkə göstəriciləri seçilən tarix üzrə avtomatik yenilənir.</p>
    </header>
    <form class="statistics-filters" method="get" action="statistics-center.php" data-statistics-filter>
        <div class="statistics-field">
            <label for="statistics-metric">Göstərici</label>
            <select id="statistics-metric" name="metric" data-statistics-filter-control>
                <option value="all"<?= $selectedMetric === 'all' ? ' selected' : ''; ?>>Bütün göstəricilər</option>
                <?php foreach ($availableMetrics as $metricKey => $metric): ?>
                    <option value="<?= e((string) $metricKey); ?>"<?= $selectedMetric === $metricKey ? ' selected' : ''; ?>><?= e((string) ($metric['label'] ?? $metricKey)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="statistics-field">
            <label for="statistics-range">Dövr</label>
            <select id="statistics-range" name="range" data-statistics-range data-statistics-filter-control>
                <?php foreach ($rangeOptions as $value => $label): ?>
                    <option value="<?= e($value); ?>"<?= $range === $value ? ' selected' : ''; ?>><?= e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="statistics-field">
            <label for="statistics-start">İlkin tarix</label>
            <input type="date" id="statistics-start" name="start_date" value="<?= e($startDate); ?>" data-statistics-date>
        </div>
        <div class="statistics-field">
            <label for="statistics-end">Son tarix</label>
            <input type="date" id="statistics-end" name="end_date" value="<?= e($endDate); ?>" data-statistics-date>
        </div>
        <noscript><button class="portal-primary-btn" type="submit">Nəticələri göstər</button></noscript>
    </form>
    <p class="statistics-status" role="status" aria-live="polite" data-statistics-status></p>
    <?php require __DIR__ . '/_results.php'; ?>
</section>
