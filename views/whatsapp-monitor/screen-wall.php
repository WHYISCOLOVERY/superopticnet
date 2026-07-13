<?php
declare(strict_types=1);

$devices = (array) ($context['devices'] ?? []);
$settings = (array) ($context['settings'] ?? []);
$defaultCols = (int) whatsapp_monitor_setting_value($settings, 'default_grid_columns', '4');
$cols = (int) ($_GET['cols'] ?? $defaultCols);
$cols = in_array($cols, [2, 3, 4, 6], true) ? $cols : 4;

$waDeviceStatusLabel = static fn (string $status): string => match ($status) {
    'online' => 'Onlayn',
    'warning' => 'Xəbərdarlıq',
    'disabled' => 'Deaktiv',
    default => 'Oflayn',
};
?>
<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head">
        <div>
            <h3>Canlı monitor</h3>
            <small>Yalnız icazəli şirkət cihazları göstərilir. Video DB-də saxlanmır.</small>
        </div>
        <div class="portal-badge-list">
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('screen-wall', ['status' => 'online', 'cols' => $cols], $monitorLang)); ?>">Onlayn</a>
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('screen-wall', ['policy_missing' => 1, 'cols' => $cols], $monitorLang)); ?>">Razılıq gözləyən</a>
            <button class="portal-primary-btn" type="button" data-screen-wall-fullscreen><i class="fa-solid fa-expand"></i> Tam ekran</button>
        </div>
    </div>
    <div class="wa-screen-toolbar">
        <div class="portal-badge-list">
            <?php foreach ([2, 3, 4, 6] as $candidate): ?>
                <a class="portal-ghost-btn portal-inline-link<?= $cols === $candidate ? ' is-active' : ''; ?>" href="<?= e(whatsapp_monitor_url('screen-wall', ['cols' => $candidate], $monitorLang)); ?>"><?= e((string) $candidate); ?> sütun</a>
            <?php endforeach; ?>
        </div>
        <small class="wa-muted">Yenilənmə: <?= e(whatsapp_monitor_setting_value($settings, 'stream_refresh_seconds', '10')); ?> saniyə</small>
    </div>
    <?php if ($devices === []): ?>
        <?php billing_render_empty_state('Monitor cihazı yoxdur', 'Cihaz əlavə edin və stream URL yazın.', 'fa-display'); ?>
    <?php else: ?>
        <div class="wa-screen-grid" style="--wa-cols:<?= e((string) $cols); ?>" data-screen-wall>
            <?php foreach ($devices as $device): ?>
                <?php
                $status = (string) ($device['status'] ?? 'offline');
                $policyAccepted = !empty($device['monitoring_policy_accepted']);
                $streamUrl = trim((string) ($device['stream_url'] ?? ''));
                $canShowStream = $streamUrl !== '' && $policyAccepted && !empty($device['monitoring_enabled']) && $status !== 'disabled';
                ?>
                <article class="wa-card wa-screen-card is-<?= e($status); ?>">
                    <div class="portal-card-head">
                        <div>
                            <strong><?= e((string) ($device['employee_name'] ?? 'Təyin edilməyib')); ?></strong>
                            <small><?= e((string) ($device['phone_number'] ?? '-')); ?> / <?= e((string) ($device['area_id'] ?? '-')); ?></small>
                        </div>
                        <span class="wa-status <?= e($status); ?>"><?= e($waDeviceStatusLabel($status)); ?></span>
                    </div>
                    <div class="wa-screen-meta">
                        <small><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i> <?= e((string) ($device['device_name'] ?? '-')); ?> / <?= e((string) ($device['device_code'] ?? '')); ?></small>
                        <small><i class="fa-solid fa-clock" aria-hidden="true"></i> Son aktivlik: <?= e((string) ($device['last_seen_at'] ?? '-')); ?></small>
                        <small><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Açıq qeyd: <?= e((string) ($device['open_case_count'] ?? 0)); ?></small>
                    </div>
                    <?php if (!$policyAccepted): ?>
                        <small class="portal-pill message-waiting">Monitorinq razılığı gözləyir</small>
                    <?php endif; ?>
                    <div class="wa-screen-frame">
                        <?php if ($canShowStream): ?>
                            <iframe src="<?= e($streamUrl); ?>" loading="lazy" referrerpolicy="no-referrer" title="<?= e((string) ($device['device_name'] ?? 'WhatsApp stream')); ?>"></iframe>
                        <?php else: ?>
                            <span><?= !$policyAccepted ? 'Razılıq yoxdur' : ($streamUrl === '' ? 'Stream URL yoxdur' : 'Stream deaktivdir'); ?></span>
                        <?php endif; ?>
                    </div>
                    <form method="post" class="portal-stacked-form portal-section-gap">
                        <input type="hidden" name="action" value="create_case">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="device_id" value="<?= e((string) ($device['id'] ?? 0)); ?>">
                        <div class="wa-form-grid">
                            <label>Növ <select name="case_type"><option value="unanswered">Cavab gözləyir</option><option value="technical">Texniki</option><option value="payment">Ödəniş</option><option value="complaint">Şikayət</option><option value="sales">Satış</option></select></label>
                            <label>Prioritet <select name="priority"><option value="normal">Normal</option><option value="high">Yüksək</option><option value="critical">Kritik</option><option value="low">Aşağı</option></select></label>
                            <textarea name="notes" rows="2" placeholder="Qısa qeyd"></textarea>
                        </div>
                        <button class="portal-primary-btn" type="submit">Qeyd yarat</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
