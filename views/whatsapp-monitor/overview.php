<?php
declare(strict_types=1);

$metrics = (array) ($context['metrics'] ?? []);
$devices = array_slice((array) ($context['devices'] ?? []), 0, 6);
$cases = array_slice((array) ($context['cases'] ?? []), 0, 6);
?>
<div class="wa-kpis">
    <article class="wa-kpi"><span>Online cihaz</span><strong><?= e((string) ($metrics['online_devices'] ?? 0)); ?></strong><small>Canli baxisa hazir</small></article>
    <article class="wa-kpi"><span>Offline / warning</span><strong><?= e((string) ($metrics['offline_devices'] ?? 0)); ?></strong><small>Yoxlama teleb edir</small></article>
    <article class="wa-kpi"><span>Aktiv nomre</span><strong><?= e((string) ($metrics['active_numbers'] ?? 0)); ?></strong><small>Sirket nomresi</small></article>
    <article class="wa-kpi"><span>QR qosulu</span><strong><?= e((string) ($metrics['qr_connected_numbers'] ?? 0)); ?></strong><small>WhatsApp Web aktiv</small></article>
    <article class="wa-kpi"><span>QR gozleyir</span><strong><?= e((string) ($metrics['qr_pending_numbers'] ?? 0)); ?></strong><small>Oxudulacaq sessiya</small></article>
    <article class="wa-kpi"><span>Aciq case</span><strong><?= e((string) ($metrics['open_cases'] ?? 0)); ?></strong><small>Cavab gozleyen</small></article>
    <article class="wa-kpi"><span>Critical</span><strong><?= e((string) ($metrics['critical_cases'] ?? 0)); ?></strong><small>Gecikme riski</small></article>
    <article class="wa-kpi"><span>Bugunku ticket</span><strong><?= e((string) ($metrics['tickets_created'] ?? 0)); ?></strong><small>Case-den yaradilan</small></article>
    <article class="wa-kpi"><span>Orta cavab</span><strong><?= e((string) ($metrics['avg_response_minutes'] ?? 0)); ?> deq</strong><small>Baglanmis case-ler</small></article>
    <article class="wa-kpi"><span>Rejim</span><strong><?= !empty($context['can_manage']) ? 'Idare' : 'Baxis'; ?></strong><small>Rola gore icaze</small></article>
</div>

<div class="wa-grid">
    <article class="wa-card">
        <strong>Monitorinq qaydasi</strong>
        <small>Bu modul yalniz sirket nomreleri ve sirket cihazlari ucundur. Mesaj gondermir, mesaj silmir, isci yerine cavab yazmir.</small>
    </article>
    <article class="wa-card">
        <strong>Canli ekran</strong>
        <small>Stream URL varsa kartda iframe kimi gosterilir. Video DB-de saxlanmir.</small>
    </article>
    <article class="wa-card">
        <strong>CRM elaqesi</strong>
        <small>Case musteriyye baglanir ve lazim olduqda movcud CRM ticket-e cevrilir.</small>
    </article>
</div>

<div class="portal-grid portal-grid-2 portal-section-gap">
    <section class="portal-muted-card">
        <div class="portal-card-head">
            <h3>Son cihazlar</h3>
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('devices', [], $monitorLang)); ?>">Hamisi</a>
        </div>
        <?php if ($devices === []): ?>
            <?php billing_render_empty_state('Cihaz yoxdur', 'Super admin cihaz elave etdikde burada gorunecek.', 'fa-mobile-screen'); ?>
        <?php else: ?>
            <div class="wa-grid">
                <?php foreach ($devices as $device): ?>
                    <?php $status = (string) ($device['status'] ?? 'offline'); ?>
                    <article class="wa-card is-<?= e($status); ?>">
                        <strong><?= e((string) ($device['device_name'] ?? '-')); ?></strong>
                        <small><?= e((string) ($device['employee_name'] ?? 'Teyin edilmeyib')); ?> / <?= e((string) ($device['area_id'] ?? '-')); ?></small>
                        <span class="wa-status <?= e($status); ?>"><?= e($status); ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="portal-muted-card">
        <div class="portal-card-head">
            <h3>Aciq case-ler</h3>
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('cases', [], $monitorLang)); ?>">Hamisi</a>
        </div>
        <?php if ($cases === []): ?>
            <?php billing_render_empty_state('Aciq case yoxdur', 'Cavabsiz mesaj flag edildikde burada gorunecek.', 'fa-circle-check'); ?>
        <?php else: ?>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Case</th><th>Cihaz</th><th>Status</th><th>Mesul</th></tr></thead>
                    <tbody>
                        <?php foreach ($cases as $case): ?>
                            <tr>
                                <td>#<?= e((string) ($case['id'] ?? '')); ?><br><small><?= e((string) ($case['priority'] ?? 'normal')); ?></small></td>
                                <td><?= e((string) ($case['device_name'] ?? '-')); ?></td>
                                <td><span class="portal-pill"><?= e((string) ($case['status'] ?? '-')); ?></span></td>
                                <td><?= e((string) ($case['assigned_name'] ?? $case['employee_name'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
