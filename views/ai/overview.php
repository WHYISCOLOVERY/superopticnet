<?php
declare(strict_types=1);

$data = (array) ($aiContext['data'] ?? []);
$summary = (array) ($data['summary'] ?? []);
$counts = (array) ($data['counts'] ?? []);
$bridge = (array) ($data['bridge'] ?? []);
$statusTotal = max(1, array_sum(array_map('intval', $counts)));
?>
<div data-ai-overview>
    <section class="ai-kpis">
        <article class="ai-kpi" data-ai-kpi><span>Tesdiq gozleyen</span><strong><?= (int) ($counts['pending'] ?? 0); ?></strong><small>tesdiq novbesi</small></article>
        <article class="ai-kpi" data-ai-kpi><span>Ugurla icra</span><strong><?= (int) ($counts['executed'] ?? 0); ?></strong><small>audit neticesi</small></article>
        <article class="ai-kpi" data-ai-kpi><span>Ugursuz</span><strong><?= (int) ($counts['failed'] ?? 0); ?></strong><small>yoxlanmalidir</small></article>
        <article class="ai-kpi" data-ai-kpi><span>Bu gun emeliyyat</span><strong><?= (int) ($summary['operations_today'] ?? 0); ?></strong><small>MGS emeliyyati</small></article>
        <article class="ai-kpi" data-ai-kpi><span>Proses hazirligi</span><strong><?= (int) ($summary['processes_ready'] ?? 0); ?>/<?= (int) ($summary['processes_total'] ?? 0); ?></strong><small>proses reyestri</small></article>
        <article class="ai-kpi" data-ai-kpi><span>MGS bridge</span><strong><?= !empty($bridge['online']) ? 'Online' : 'Offline'; ?></strong><small>canli kontekst</small></article>
    </section>
    <section class="ai-grid" style="margin-top:14px">
        <article class="ai-panel" data-ai-chart>
            <h3>AI emeliyyat statusu</h3>
            <div class="ai-bars">
                <?php foreach (['pending' => 'Gozleyir', 'executed' => 'Icra edilib', 'failed' => 'Ugursuz'] as $key => $label): ?>
                    <?php $value = (int) ($counts[$key] ?? 0); ?>
                    <div class="ai-bar-row"><span><?= e($label); ?></span><span class="ai-bar-track"><i class="ai-bar-fill <?= $key === 'failed' ? 'is-danger' : ($key === 'pending' ? 'is-warning' : ''); ?>" style="width:<?= round(($value / $statusTotal) * 100, 1); ?>%"></i></span><strong><?= $value; ?></strong></div>
                <?php endforeach; ?>
            </div>
        </article>
        <article class="ai-panel" data-ai-chart>
            <h3>Kontekst hazirligi</h3>
            <div class="ai-bars">
                <div class="ai-bar-row"><span>Prosesler</span><span class="ai-bar-track"><i class="ai-bar-fill" style="width:<?= (int) ($summary['processes_total'] ?? 0) > 0 ? round(((int) ($summary['processes_ready'] ?? 0) / (int) $summary['processes_total']) * 100, 1) : 0; ?>%"></i></span><strong><?= (int) ($summary['processes_ready'] ?? 0); ?></strong></div>
                <div class="ai-bar-row"><span>Bridge</span><span class="ai-bar-track"><i class="ai-bar-fill <?= empty($bridge['online']) ? 'is-warning' : ''; ?>" style="width:<?= !empty($bridge['online']) ? 100 : 15; ?>%"></i></span><strong><?= !empty($bridge['online']) ? 'Hazir' : 'Passiv'; ?></strong></div>
            </div>
        </article>
        <article class="ai-panel" data-ai-chart>
            <h3>Sebeke konteksti</h3>
            <div class="ai-bars">
                <div class="ai-bar-row"><span>Catimlidir</span><span class="ai-bar-track"><i class="ai-bar-fill" style="width:<?= (int) ($summary['device_profiles'] ?? 0) > 0 ? round(((int) ($summary['devices_reachable'] ?? 0) / (int) $summary['device_profiles']) * 100, 1) : 0; ?>%"></i></span><strong><?= (int) ($summary['devices_reachable'] ?? 0); ?></strong></div>
                <div class="ai-bar-row"><span>Bilinmir</span><span class="ai-bar-track"><i class="ai-bar-fill is-warning" style="width:<?= (int) ($summary['device_profiles'] ?? 0) > 0 ? round(((int) ($summary['devices_unknown'] ?? 0) / (int) $summary['device_profiles']) * 100, 1) : 0; ?>%"></i></span><strong><?= (int) ($summary['devices_unknown'] ?? 0); ?></strong></div>
            </div>
        </article>
    </section>
    <section class="ai-grid" style="margin-top:14px">
        <article class="ai-panel ai-panel-wide"><h2>Kritik AI xeberdarliqlari</h2>
            <?php if (($data['recommendations'] ?? []) === []): ?><div class="ai-empty">Aktiv AI xeberdarligi yoxdur.</div><?php endif; ?>
            <div class="ai-result-list"><?php foreach ((array) ($data['recommendations'] ?? []) as $item): ?><a class="ai-result-item is-<?= e((string) ($item['level'] ?? 'info')); ?>" href="<?= e((string) ($item['href'] ?? '#')); ?>"><strong><?= e((string) ($item['title'] ?? '')); ?></strong><p><?= e((string) ($item['text'] ?? '')); ?></p></a><?php endforeach; ?></div>
        </article>
    </section>
</div>
