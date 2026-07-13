<?php
declare(strict_types=1);

$numbers = (array) ($context['numbers'] ?? []);
$employees = (array) ($context['employees'] ?? []);

$waNumberStatusLabel = static fn (string $status): string => match ($status) {
    'active' => 'Aktiv',
    'inactive' => 'Passiv',
    'blocked' => 'Bloklu',
    'lost' => 'İtib',
    default => $status,
};
?>
<?php if (!empty($context['can_manage'])): ?>
    <section class="portal-muted-card portal-section-gap">
        <div class="portal-card-head"><h3>İşçi WhatsApp nömrəsi əlavə et</h3><small>Bir nömrə yalnız bir aktiv əməkdaşa bağlı ola bilər.</small></div>
        <form method="post" class="portal-stacked-form">
            <input type="hidden" name="action" value="save_number">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <div class="wa-form-grid">
                <label>Əməkdaş
                    <select name="employee_id">
                        <option value="">Seçin</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= e((string) ($employee['id'] ?? 0)); ?>"><?= e((string) ($employee['full_name'] ?? '-')); ?> / <?= e((string) ($employee['employee_role'] ?? '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Nömrə <input name="phone_number" placeholder="0501234567" required></label>
                <label>WhatsApp adı <input name="whatsapp_account_name" placeholder="SuperOptik Operator"></label>
                <label>Status
                    <select name="status"><option value="active">Aktiv</option><option value="inactive">Passiv</option><option value="blocked">Bloklu</option><option value="lost">İtib</option></select>
                </label>
                <label class="portal-checkbox-line wide"><input type="checkbox" name="monitoring_policy_accepted" value="1"><span>Monitorinq razılığı alınıb</span></label>
            </div>
            <details class="wa-tech-details portal-section-gap">
                <summary>Əlavə seçimlər</summary>
                <div class="wa-form-grid portal-section-gap">
                    <label>Ərazi <input name="assigned_area_id" placeholder="Bakı / Astara"></label>
                    <label>Razılıq versiyası <input name="policy_version" value="2026-07"></label>
                    <label class="portal-checkbox-line"><input type="checkbox" name="is_company_owned" value="1" checked><span>Şirkət nömrəsidir</span></label>
                    <textarea name="notes" rows="3" placeholder="Qeyd"></textarea>
                </div>
            </details>
            <button class="portal-primary-btn portal-section-gap" type="submit">Nömrəni saxla</button>
        </form>
    </section>
<?php endif; ?>

<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>İşçi nömrələri</h3><small>Razılıq yoxdursa monitorinq panelində xəbərdarlıq görünür.</small></div>
    <?php if ($numbers === []): ?>
        <?php billing_render_empty_state('Nömrə yoxdur', 'Şirkət WhatsApp nömrələri əlavə ediləndə burada görünəcək.', 'fa-brands fa-whatsapp'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Əməkdaş</th><th>Nömrə</th><th>Ərazi</th><th>Status</th><th>QR</th><th>Razılıq</th><th>Təhkim</th><th>Əməliyyat</th></tr></thead>
                <tbody>
                    <?php foreach ($numbers as $number): ?>
                        <?php
                        $qrStatus = (string) ($number['qr_status'] ?? 'not_connected');
                        $numberStatus = (string) ($number['status'] ?? '-');
                        ?>
                        <tr>
                            <td data-label="Əməkdaş"><strong><?= e((string) ($number['employee_name'] ?? '-')); ?></strong><br><small><?= e((string) ($number['employee_role'] ?? '')); ?></small></td>
                            <td data-label="Nömrə"><?= e((string) ($number['phone_number'] ?? '-')); ?><br><small><?= e((string) ($number['whatsapp_account_name'] ?? '')); ?></small></td>
                            <td data-label="Ərazi"><?= e((string) ($number['assigned_area_id'] ?? '-')); ?></td>
                            <td data-label="Status"><span class="portal-pill"><?= e($waNumberStatusLabel($numberStatus)); ?></span></td>
                            <td data-label="QR"><span class="wa-status <?= e($qrStatus); ?>"><?= e(whatsapp_monitor_pairing_status_label($qrStatus)); ?></span></td>
                            <td data-label="Razılıq"><?= !empty($number['monitoring_policy_accepted']) ? 'Qəbul edilib' : 'Gözləyir'; ?></td>
                            <td data-label="Təhkim"><?= e((string) ($number['assigned_at'] ?? '-')); ?></td>
                            <td data-label="Əməliyyat">
                                <div class="wa-table-actions">
                                    <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('pairing', ['number_id' => (int) ($number['id'] ?? 0)], $monitorLang)); ?>">QR</a>
                                    <?php if (!empty($context['can_manage']) && (string) ($number['status'] ?? '') === 'active'): ?>
                                        <form method="post" class="wa-inline-form">
                                            <input type="hidden" name="action" value="return_number">
                                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                            <input type="hidden" name="number_id" value="<?= e((string) ($number['id'] ?? 0)); ?>">
                                            <button class="portal-ghost-btn portal-inline-link" type="submit">Geri al</button>
                                        </form>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
