<?php
declare(strict_types=1);

$cases = (array) ($context['cases'] ?? []);
$byEmployee = [];
foreach ($cases as $case) {
    $name = (string) ($case['employee_name'] ?? 'Teyin edilmeyib');
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
    <div class="portal-card-head"><h3>Emekdas performansi</h3><small>MVP-de manual case-lere esaslanir. OCR/API inteqrasiyasi sonraki merheledir.</small></div>
    <?php if ($byEmployee === []): ?>
        <?php billing_render_empty_state('Performans datasi yoxdur', 'Case yarandıqca emekdas neticeleri gorunecek.', 'fa-chart-line'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Emekdas</th><th>Aciq/cemi case</th><th>Critical</th><th>Cavab/Hell</th></tr></thead>
                <tbody>
                    <?php foreach ($byEmployee as $name => $row): ?>
                        <tr>
                            <td><strong><?= e($name); ?></strong></td>
                            <td><?= e((string) $row['total']); ?></td>
                            <td><?= e((string) $row['critical']); ?></td>
                            <td><?= e((string) $row['resolved']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
