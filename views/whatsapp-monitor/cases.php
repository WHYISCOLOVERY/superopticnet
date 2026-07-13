<?php
declare(strict_types=1);

$cases = (array) ($context['cases'] ?? []);
$devices = (array) ($context['devices'] ?? []);
$employees = (array) ($context['employees'] ?? []);

$waCaseTypeLabel = static fn (string $type): string => match ($type) {
    'unanswered' => 'Cavabsız',
    'complaint' => 'Şikayət',
    'payment' => 'Ödəniş',
    'technical' => 'Texniki',
    'sales' => 'Satış',
    default => 'Digər',
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
<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>Əl ilə monitorinq qeydi yarat</h3><small>Cavabsız mesaj, şikayət və ya CRM müraciəti üçün qeyd yaradın.</small></div>
    <form method="post" class="portal-stacked-form">
        <input type="hidden" name="action" value="create_case">
        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
        <div class="wa-form-grid">
            <label>Cihaz
                <select name="device_id" required>
                    <option value="">Seçin</option>
                    <?php foreach ($devices as $device): ?>
                        <option value="<?= e((string) ($device['id'] ?? 0)); ?>"><?= e((string) ($device['device_name'] ?? '-')); ?> / <?= e((string) ($device['employee_name'] ?? '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Növ <select name="case_type"><option value="unanswered">Cavabsız</option><option value="complaint">Şikayət</option><option value="payment">Ödəniş</option><option value="technical">Texniki</option><option value="sales">Satış</option><option value="unknown">Digər</option></select></label>
            <label>Prioritet <select name="priority"><option value="normal">Normal</option><option value="high">Yüksək</option><option value="critical">Kritik</option><option value="low">Aşağı</option></select></label>
            <label>Məsul
                <select name="assigned_to">
                    <option value="">Cihazdakı əməkdaş</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= e((string) ($employee['id'] ?? 0)); ?>"><?= e((string) ($employee['full_name'] ?? '-')); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <textarea name="notes" rows="3" placeholder="Qısa qeyd"></textarea>
        </div>
        <button class="portal-primary-btn" type="submit">Qeyd yarat</button>
    </form>
</section>

<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head">
        <h3>Monitorinq qeydləri</h3>
        <div class="portal-badge-list">
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('cases', ['status' => 'open'], $monitorLang)); ?>">Açıq</a>
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('cases', ['status' => 'resolved'], $monitorLang)); ?>">Həll edilən</a>
        </div>
    </div>
    <?php if ($cases === []): ?>
        <?php billing_render_empty_state('Monitorinq qeydi yoxdur', 'Əl ilə monitorinq qeydi yaradıldıqda burada görünəcək.', 'fa-message'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Qeyd №</th><th>Cihaz / işçi</th><th>Müştəri</th><th>Status</th><th>Qeyd mətni</th><th>Əməliyyat</th></tr></thead>
                <tbody>
                    <?php foreach ($cases as $case): ?>
                        <tr>
                            <td data-label="Qeyd №">#<?= e((string) ($case['id'] ?? '')); ?><br><small><?= e($waCaseTypeLabel((string) ($case['case_type'] ?? ''))); ?> / <?= e($waPriorityLabel((string) ($case['priority'] ?? ''))); ?></small></td>
                            <td data-label="Cihaz / işçi"><strong><?= e((string) ($case['device_name'] ?? '-')); ?></strong><br><small><?= e((string) ($case['employee_name'] ?? '-')); ?></small></td>
                            <td data-label="Müştəri"><?= e((string) ($case['customer_name'] ?? '-')); ?><br><small><?= e((string) ($case['subscriber_code'] ?? $case['customer_phone'] ?? '')); ?></small></td>
                            <td data-label="Status"><span class="portal-pill"><?= e($waCaseStatusLabel((string) ($case['status'] ?? '-'))); ?></span><br><small><?= e((string) ($case['opened_at'] ?? '')); ?></small></td>
                            <td data-label="Qeyd mətni"><?= e(mb_substr((string) ($case['notes'] ?? ''), 0, 120)); ?></td>
                            <td data-label="Əməliyyat">
                                <div class="wa-case-actions">
                                    <form method="post" class="wa-inline-form wa-case-actions__row">
                                        <input type="hidden" name="action" value="case_status">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="case_id" value="<?= e((string) ($case['id'] ?? 0)); ?>">
                                        <select name="case_status">
                                            <option value="waiting_employee">Cavab gözləyir</option>
                                            <option value="in_progress">İşdədir</option>
                                            <option value="replied">Cavablandı</option>
                                            <option value="escalated">Eskalasiya</option>
                                            <option value="resolved">Həll edildi</option>
                                            <option value="ignored">Nəzərə alınmadı</option>
                                        </select>
                                        <button class="portal-ghost-btn portal-inline-link" type="submit">Yenilə</button>
                                    </form>
                                    <form method="post" class="wa-inline-form wa-case-actions__row">
                                        <input type="hidden" name="action" value="link_customer">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="case_id" value="<?= e((string) ($case['id'] ?? 0)); ?>">
                                        <input name="customer_query" placeholder="kod/tel/ad" required>
                                        <button class="portal-ghost-btn portal-inline-link" type="submit">Müştəriyə bağla</button>
                                    </form>
                                    <div class="wa-case-actions__row wa-case-actions__row--tickets">
                                        <form method="post" class="wa-inline-form">
                                            <input type="hidden" name="action" value="create_ticket">
                                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                            <input type="hidden" name="case_id" value="<?= e((string) ($case['id'] ?? 0)); ?>">
                                            <button class="portal-primary-btn portal-inline-link" type="submit">CRM müraciəti yarat</button>
                                        </form>
                                        <?php if (!empty($case['ticket_no'])): ?><a class="portal-ghost-btn portal-inline-link" href="crm.php?q=<?= e(rawurlencode((string) $case['ticket_no'])); ?>">CRM <?= e((string) $case['ticket_no']); ?></a><?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
