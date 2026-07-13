<?php
declare(strict_types=1);

$cases = (array) ($context['cases'] ?? []);
$byEmployee = [];
foreach ($cases as $case) {
    $name = (string) ($case['employee_name'] ?? 'Təyin edilməyib');
    $byEmployee[$name] ??= ['total' => 0, 'critical' => 0, 'resolved' => 0];
    $byEmployee[$name]['total']++;
    if ((string) ($case['priority'] ?? '') === 'critical') {
        $byEmployee[$name]['critical']++;
    }
    if (in_array((string) ($case['status'] ?? ''), ['resolved', 'replied'], true)) {
        $byEmployee[$name]['resolved']++;
    }
}
?>
<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>Əməkdaş performansı</h3><small>MVP-də əl ilə yaradılan monitorinq qeydlərinə əsaslanır. OCR/API inteqrasiyası sonrakı mərhələdir.</small></div>
    <?php if ($byEmployee === []): ?>
        <?php billing_render_empty_state('Performans məlumatı yoxdur', 'Monitorinq qeydi yarandıqca əməkdaş nəticələri görünəcək.', 'fa-chart-line'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Əməkdaş</th><th>Açıq/cəmi qeyd</th><th>Kritik</th><th>Cavab/Həll</th></tr></thead>
                <tbody>
                    <?php foreach ($byEmployee as $name => $row): ?>
                        <tr>
                            <td data-label="Əməkdaş"><strong><?= e($name); ?></strong></td>
                            <td data-label="Açıq/cəmi qeyd"><?= e((string) $row['total']); ?></td>
                            <td data-label="Kritik"><?= e((string) $row['critical']); ?></td>
                            <td data-label="Cavab/Həll"><?= e((string) $row['resolved']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
