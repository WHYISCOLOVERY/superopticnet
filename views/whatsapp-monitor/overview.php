<?php
declare(strict_types=1);

$metrics = (array) ($context['metrics'] ?? []);
$devices = array_slice((array) ($context['devices'] ?? []), 0, 6);
$cases = array_slice((array) ($context['cases'] ?? []), 0, 6);

$waDeviceStatusLabel = static fn (string $status): string => match ($status) {
    'online' => 'Onlayn',
    'warning' => 'Xəbərdarlıq',
    'disabled' => 'Deaktiv',
    default => 'Oflayn',
};
$waPriorityLabel = static fn (string $priority): string => match ($priority) {
    'critical' => 'Kritik',
    'high' => 'Yüksək',
    'low' => 'Aşağı',
    default => 'Normal',
};
$waCaseStatusLabel = static fn (string $status): string => match ($status) {
    'new' => 'Yeni',
    'waiting_employee' => 'Cavab gözləyir',
    'in_progress' => 'İşdədir',
    'replied' => 'Cavablandı',
    'escalated' => 'Eskalasiya',
    'resolved' => 'Həll edildi',
    'ignored' => 'Nəzərə alınmadı',
    default => $status,
};
?>
<div class="wa-kpi-groups">
    <section class="wa-kpi-group">
        <div class="wa-kpi-group-head">
            <h4>Nömrələr və QR</h4>
            <span class="portal-pill"><?= !empty($context['can_manage']) ? 'Rejim: İdarə' : 'Rejim: Baxış'; ?></span>
        </div>
        <div class="wa-kpis">
            <article class="wa-kpi"><span>Aktiv nömrə</span><strong><?= e((string) ($metrics['active_numbers'] ?? 0)); ?></strong><small>Şirkət nömrəsi</small></article>
            <article class="wa-kpi"><span>QR qoşulu</span><strong><?= e((string) ($metrics['qr_connected_numbers'] ?? 0)); ?></strong><small>WhatsApp Web aktiv</small></article>
            <article class="wa-kpi"><span>QR gözləyir</span><strong><?= e((string) ($metrics['qr_pending_numbers'] ?? 0)); ?></strong><small>Oxunacaq sessiya</small></article>
        </div>
    </section>

    <section class="wa-kpi-group">
        <div class="wa-kpi-group-head"><h4>Cihazlar</h4></div>
        <div class="wa-kpis">
            <article class="wa-kpi"><span>Onlayn cihaz</span><strong><?= e((string) ($metrics['online_devices'] ?? 0)); ?></strong><small>Canlı baxışa hazır</small></article>
            <article class="wa-kpi"><span>Oflayn / xəbərdarlıq</span><strong><?= e((string) ($metrics['offline_devices'] ?? 0)); ?></strong><small>Yoxlama tələb edir</small></article>
        </div>
    </section>

    <section class="wa-kpi-group">
        <div class="wa-kpi-group-head"><h4>Monitorinq qeydləri və performans</h4></div>
        <div class="wa-kpis">
            <article class="wa-kpi"><span>Açıq qeyd</span><strong><?= e((string) ($metrics['open_cases'] ?? 0)); ?></strong><small>Cavab gözləyən</small></article>
            <article class="wa-kpi"><span>Kritik</span><strong><?= e((string) ($metrics['critical_cases'] ?? 0)); ?></strong><small>Gecikmə riski</small></article>
            <article class="wa-kpi"><span>Bugünkü CRM müraciəti</span><strong><?= e((string) ($metrics['tickets_created'] ?? 0)); ?></strong><small>Qeydlərdən yaradılan</small></article>
            <article class="wa-kpi"><span>Orta cavab</span><strong><?= e((string) ($metrics['avg_response_minutes'] ?? 0)); ?> dəq</strong><small>Bağlanmış qeydlər</small></article>
        </div>
    </section>
</div>

<div class="wa-grid">
    <article class="wa-card">
        <strong>Monitorinq qaydası</strong>
        <small>Bu modul yalnız şirkət nömrələri və şirkət cihazları üçündür. Mesaj göndərmir, mesaj silmir, işçi yerinə cavab yazmır.</small>
    </article>
    <article class="wa-card">
        <strong>Canlı ekran</strong>
        <small>Stream URL varsa, kartda iframe kimi göstərilir. Video DB-də saxlanmır.</small>
    </article>
    <article class="wa-card">
        <strong>CRM əlaqəsi</strong>
        <small>Monitorinq qeydi müştəriyə bağlanır və lazım olduqda CRM müraciətinə çevrilir.</small>
    </article>
</div>

<div class="portal-grid portal-grid-2 portal-section-gap">
    <section class="portal-muted-card">
        <div class="portal-card-head">
            <h3>Son cihazlar</h3>
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('devices', [], $monitorLang)); ?>">Hamısı</a>
        </div>
        <?php if ($devices === []): ?>
            <?php billing_render_empty_state('Cihaz yoxdur', 'Cihaz əlavə edildikdə burada görünəcək.', 'fa-mobile-screen'); ?>
        <?php else: ?>
            <div class="wa-grid">
                <?php foreach ($devices as $device): ?>
                    <?php $status = (string) ($device['status'] ?? 'offline'); ?>
                    <article class="wa-card is-<?= e($status); ?>">
                        <strong><?= e((string) ($device['device_name'] ?? '-')); ?></strong>
                        <small><?= e((string) ($device['employee_name'] ?? 'Təyin edilməyib')); ?> / <?= e((string) ($device['area_id'] ?? '-')); ?></small>
                        <span class="wa-status <?= e($status); ?>"><?= e($waDeviceStatusLabel($status)); ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="portal-muted-card">
        <div class="portal-card-head">
            <h3>Açıq monitorinq qeydləri</h3>
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('cases', [], $monitorLang)); ?>">Hamısı</a>
        </div>
        <?php if ($cases === []): ?>
            <?php billing_render_empty_state('Açıq qeyd yoxdur', 'Cavabsız mesaj qeyd edildikdə burada görünəcək.', 'fa-circle-check'); ?>
        <?php else: ?>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Qeyd</th><th>Cihaz</th><th>Status</th><th>Məsul</th></tr></thead>
                    <tbody>
                        <?php foreach ($cases as $case): ?>
                            <tr>
                                <td data-label="Qeyd">#<?= e((string) ($case['id'] ?? '')); ?><br><small><?= e($waPriorityLabel((string) ($case['priority'] ?? 'normal'))); ?></small></td>
                                <td data-label="Cihaz"><?= e((string) ($case['device_name'] ?? '-')); ?></td>
                                <td data-label="Status"><span class="portal-pill"><?= e($waCaseStatusLabel((string) ($case['status'] ?? '-'))); ?></span></td>
                                <td data-label="Məsul"><?= e((string) ($case['assigned_name'] ?? $case['employee_name'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
