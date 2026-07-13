<?php
declare(strict_types=1);

$devices = (array) ($context['devices'] ?? []);
$numbers = (array) ($context['numbers'] ?? []);
$employees = (array) ($context['employees'] ?? []);

$waDeviceStatusLabel = static fn (string $status): string => match ($status) {
    'online' => 'Onlayn',
    'warning' => 'Xəbərdarlıq',
    'disabled' => 'Deaktiv',
    default => 'Oflayn',
};
?>
<?php if (!empty($context['can_manage'])): ?>
    <section class="portal-muted-card portal-section-gap">
        <div class="portal-card-head"><h3>Cihaz əlavə et</h3><small>Stream URL opsionaldır; real video servisi ayrıca qoşulur.</small></div>
        <form method="post" class="portal-stacked-form">
            <input type="hidden" name="action" value="save_device">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <div class="wa-form-grid">
                <label>Cihaz adı <input name="device_name" placeholder="Operator Android 01" required></label>
                <label>Cihaz kodu <input name="device_code" placeholder="WA-OP-001" required></label>
                <label>Əməkdaş
                    <select name="employee_id"><option value="">Seçin</option><?php foreach ($employees as $employee): ?><option value="<?= e((string) ($employee['id'] ?? 0)); ?>"><?= e((string) ($employee['full_name'] ?? '-')); ?></option><?php endforeach; ?></select>
                </label>
                <label>Nömrə
                    <select name="phone_number_id"><option value="">Seçin</option><?php foreach ($numbers as $number): ?><option value="<?= e((string) ($number['id'] ?? 0)); ?>"><?= e((string) ($number['phone_number'] ?? '-')); ?> / <?= e((string) ($number['employee_name'] ?? '')); ?></option><?php endforeach; ?></select>
                </label>
                <label>Status <select name="status"><option value="offline">Oflayn</option><option value="online">Onlayn</option><option value="warning">Xəbərdarlıq</option><option value="disabled">Deaktiv</option></select></label>
                <label class="portal-checkbox-line wide"><input type="checkbox" name="monitoring_enabled" value="1" checked><span>Monitorinq aktivdir</span></label>
            </div>
            <details class="wa-tech-details portal-section-gap">
                <summary>Əlavə seçimlər</summary>
                <div class="wa-form-grid portal-section-gap">
                    <label>Ərazi <input name="area_id" placeholder="Bakı"></label>
                    <label>Cihaz tipi <select name="device_type"><option value="android">Android</option><option value="iphone">iPhone</option><option value="web">Veb</option><option value="other">Digər</option></select></label>
                    <label>Bağlantı <select name="connection_type"><option value="stream_url">Stream URL</option><option value="usb">USB</option><option value="tcpip">TCP/IP</option><option value="mdm">MDM</option><option value="manual">Əl ilə</option></select></label>
                    <label>Stream URL <input name="stream_url" placeholder="https://..."></label>
                    <textarea name="notes" rows="3" placeholder="Qeyd"></textarea>
                </div>
            </details>
            <button class="portal-primary-btn portal-section-gap" type="submit">Cihazı saxla</button>
        </form>
    </section>
<?php endif; ?>

<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>Cihazlar</h3><small>Operator baxa bilər, super admin dəyişə bilər.</small></div>
    <?php if ($devices === []): ?>
        <?php billing_render_empty_state('Cihaz yoxdur', 'Cihaz əlavə ediləndə screen wall-da görünəcək.', 'fa-mobile-screen'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Cihaz</th><th>Əməkdaş</th><th>Nömrə</th><th>Ərazi</th><th>Status</th><th>Son aktivlik</th><th>Əməliyyat</th></tr></thead>
                <tbody>
                    <?php foreach ($devices as $device): ?>
                        <?php $status = (string) ($device['status'] ?? 'offline'); ?>
                        <tr>
                            <td data-label="Cihaz"><strong><?= e((string) ($device['device_name'] ?? '-')); ?></strong><br><small><?= e((string) ($device['device_code'] ?? '')); ?></small></td>
                            <td data-label="Əməkdaş"><?= e((string) ($device['employee_name'] ?? '-')); ?></td>
                            <td data-label="Nömrə"><?= e((string) ($device['phone_number'] ?? '-')); ?></td>
                            <td data-label="Ərazi"><?= e((string) ($device['area_id'] ?? '-')); ?></td>
                            <td data-label="Status"><span class="wa-status <?= e($status); ?>"><?= e($waDeviceStatusLabel($status)); ?></span></td>
                            <td data-label="Son aktivlik"><?= e((string) ($device['last_seen_at'] ?? '-')); ?></td>
                            <td data-label="Əməliyyat" class="wa-table-actions">
                                <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('screen-wall', ['device' => (int) ($device['id'] ?? 0)], $monitorLang)); ?>">Aç</a>
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
