<?php
declare(strict_types=1);

$devices = (array) ($context['devices'] ?? []);
$settings = (array) ($context['settings'] ?? []);
$defaultCols = (int) whatsapp_monitor_setting_value($settings, 'default_grid_columns', '4');
$cols = (int) ($_GET['cols'] ?? $defaultCols);
$cols = in_array($cols, [2, 3, 4, 6], true) ? $cols : 4;
?>
<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head">
        <div>
            <h3>Canli monitor / Screen Wall</h3>
            <small>Yalniz icazeli sirket cihazlari gosterilir. Video DB-de saxlanmir.</small>
        </div>
        <div class="portal-badge-list">
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('screen-wall', ['status' => 'online', 'cols' => $cols], $monitorLang)); ?>">Online</a>
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('screen-wall', ['policy_missing' => 1, 'cols' => $cols], $monitorLang)); ?>">Raziliq gozleyen</a>
            <button class="portal-primary-btn" type="button" data-screen-wall-fullscreen><i class="fa-solid fa-expand"></i> Tam ekran</button>
        </div>
    </div>
    <div class="wa-screen-toolbar">
        <div class="portal-badge-list">
            <?php foreach ([2, 3, 4, 6] as $candidate): ?>
                <a class="portal-ghost-btn portal-inline-link<?= $cols === $candidate ? ' is-active' : ''; ?>" href="<?= e(whatsapp_monitor_url('screen-wall', ['cols' => $candidate], $monitorLang)); ?>"><?= e((string) $candidate); ?> sutun</a>
            <?php endforeach; ?>
        </div>
        <small class="wa-muted">Stream refresh: <?= e(whatsapp_monitor_setting_value($settings, 'stream_refresh_seconds', '10')); ?> saniye</small>
    </div>
    <?php if ($devices === []): ?>
        <?php billing_render_empty_state('Monitor cihaz yoxdur', 'Cihaz elave edin ve stream URL yazin.', 'fa-display'); ?>
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
                            <strong><?= e((string) ($device['employee_name'] ?? 'Teyin edilmeyib')); ?></strong>
                            <small><?= e((string) ($device['phone_number'] ?? '-')); ?> / <?= e((string) ($device['area_id'] ?? '-')); ?></small>
                        </div>
                        <span class="wa-status <?= e($status); ?>"><?= e($status); ?></span>
                    </div>
                    <small><?= e((string) ($device['device_name'] ?? '-')); ?> / <?= e((string) ($device['device_code'] ?? '')); ?></small>
                    <small>Son aktivlik: <?= e((string) ($device['last_seen_at'] ?? '-')); ?></small>
                    <small>Aciq case: <?= e((string) ($device['open_case_count'] ?? 0)); ?></small>
                    <?php if (!$policyAccepted): ?>
                        <small class="portal-pill message-waiting">Monitorinq raziligi gozleyir</small>
                    <?php endif; ?>
                    <div class="wa-screen-frame">
                        <?php if ($canShowStream): ?>
                            <iframe src="<?= e($streamUrl); ?>" loading="lazy" referrerpolicy="no-referrer" title="<?= e((string) ($device['device_name'] ?? 'WhatsApp stream')); ?>"></iframe>
                        <?php else: ?>
                            <span><?= !$policyAccepted ? 'Raziliq yoxdur' : ($streamUrl === '' ? 'Stream URL yoxdur' : 'Stream deaktivdir'); ?></span>
                        <?php endif; ?>
                    </div>
                    <form method="post" class="portal-stacked-form portal-section-gap">
                        <input type="hidden" name="action" value="create_case">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="device_id" value="<?= e((string) ($device['id'] ?? 0)); ?>">
                        <div class="wa-form-grid">
                            <label>Nov <select name="case_type"><option value="unanswered">Cavab gozleyir</option><option value="technical">Texniki</option><option value="payment">Odenis</option><option value="complaint">Sikayet</option><option value="sales">Satis</option></select></label>
                            <label>Prioritet <select name="priority"><option value="normal">Normal</option><option value="high">Yuksek</option><option value="critical">Critical</option><option value="low">Asagi</option></select></label>
                            <textarea name="notes" rows="2" placeholder="Qisa qeyd"></textarea>
                        </div>
                        <button class="portal-primary-btn" type="submit">Case yarat</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
