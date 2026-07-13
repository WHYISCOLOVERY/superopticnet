<?php
declare(strict_types=1);

$devices = (array) ($context['devices'] ?? []);
$numbers = (array) ($context['numbers'] ?? []);
$employees = (array) ($context['employees'] ?? []);
?>
<?php if (!empty($context['can_manage'])): ?>
    <section class="portal-muted-card portal-section-gap">
        <div class="portal-card-head"><h3>Cihaz elave et</h3><small>Stream URL optionaldir; real video servisi ayrica qosulur.</small></div>
        <form method="post" class="portal-stacked-form">
            <input type="hidden" name="action" value="save_device">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <div class="wa-form-grid">
                <label>Cihaz adi <input name="device_name" placeholder="Operator Android 01" required></label>
                <label>Cihaz kodu <input name="device_code" placeholder="WA-OP-001" required></label>
                <label>Emekdas
                    <select name="employee_id"><option value="">Secin</option><?php foreach ($employees as $employee): ?><option value="<?= e((string) ($employee['id'] ?? 0)); ?>"><?= e((string) ($employee['full_name'] ?? '-')); ?></option><?php endforeach; ?></select>
                </label>
                <label>Nomre
                    <select name="phone_number_id"><option value="">Secin</option><?php foreach ($numbers as $number): ?><option value="<?= e((string) ($number['id'] ?? 0)); ?>"><?= e((string) ($number['phone_number'] ?? '-')); ?> / <?= e((string) ($number['employee_name'] ?? '')); ?></option><?php endforeach; ?></select>
                </label>
                <label>Erazi <input name="area_id" placeholder="Baki"></label>
                <label>Cihaz tipi <select name="device_type"><option value="android">Android</option><option value="iphone">iPhone</option><option value="web">Web</option><option value="other">Diger</option></select></label>
                <label>Baglanti <select name="connection_type"><option value="stream_url">Stream URL</option><option value="usb">USB</option><option value="tcpip">TCP/IP</option><option value="mdm">MDM</option><option value="manual">Manual</option></select></label>
                <label>Status <select name="status"><option value="offline">Offline</option><option value="online">Online</option><option value="warning">Warning</option><option value="disabled">Disabled</option></select></label>
                <label>Stream URL <input name="stream_url" placeholder="https://..."></label>
                <label class="portal-checkbox-line"><input type="checkbox" name="monitoring_enabled" value="1" checked><span>Monitorinq aktivdir</span></label>
                <textarea name="notes" rows="3" placeholder="Qeyd"></textarea>
            </div>
            <button class="portal-primary-btn" type="submit">Cihazi saxla</button>
        </form>
    </section>
<?php endif; ?>

<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>Cihazlar</h3><small>Operator baxa biler, super admin deyise biler.</small></div>
    <?php if ($devices === []): ?>
        <?php billing_render_empty_state('Cihaz yoxdur', 'Cihaz elave edilende screen wall-da gorunecek.', 'fa-mobile-screen'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Cihaz</th><th>Emekdas</th><th>Nomre</th><th>Erazi</th><th>Status</th><th>Son aktivlik</th><th>Emeliyyat</th></tr></thead>
                <tbody>
                    <?php foreach ($devices as $device): ?>
                        <?php $status = (string) ($device['status'] ?? 'offline'); ?>
                        <tr>
                            <td><strong><?= e((string) ($device['device_name'] ?? '-')); ?></strong><br><small><?= e((string) ($device['device_code'] ?? '')); ?></small></td>
                            <td><?= e((string) ($device['employee_name'] ?? '-')); ?></td>
                            <td><?= e((string) ($device['phone_number'] ?? '-')); ?></td>
                            <td><?= e((string) ($device['area_id'] ?? '-')); ?></td>
                            <td><span class="wa-status <?= e($status); ?>"><?= e($status); ?></span></td>
                            <td><?= e((string) ($device['last_seen_at'] ?? '-')); ?></td>
                            <td class="wa-table-actions">
                                <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('screen-wall', ['device' => (int) ($device['id'] ?? 0)], $monitorLang)); ?>">Ac</a>
                                <?php if (!empty($context['can_manage']) && $status !== 'disabled'): ?>
                                    <form method="post" class="wa-inline-form">
                                        <input type="hidden" name="action" value="disable_device">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="device_id" value="<?= e((string) ($device['id'] ?? 0)); ?>">
                                        <button class="portal-ghost-btn portal-inline-link" type="submit">Deaktiv et</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
