<?php
declare(strict_types=1);

$cases = (array) ($context['cases'] ?? []);
$devices = (array) ($context['devices'] ?? []);
$employees = (array) ($context['employees'] ?? []);
?>
<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>Manual case yarat</h3><small>Cavabsiz mesaj ehtimali, sikayet ve ya ticket ehtiyaci ucun.</small></div>
    <form method="post" class="portal-stacked-form">
        <input type="hidden" name="action" value="create_case">
        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
        <div class="wa-form-grid">
            <label>Cihaz
                <select name="device_id" required>
                    <option value="">Secin</option>
                    <?php foreach ($devices as $device): ?>
                        <option value="<?= e((string) ($device['id'] ?? 0)); ?>"><?= e((string) ($device['device_name'] ?? '-')); ?> / <?= e((string) ($device['employee_name'] ?? '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Nov <select name="case_type"><option value="unanswered">Cavab gozleyir</option><option value="complaint">Sikayet</option><option value="payment">Odenis</option><option value="technical">Texniki</option><option value="sales">Satis</option><option value="unknown">Diger</option></select></label>
            <label>Prioritet <select name="priority"><option value="normal">Normal</option><option value="high">Yuksek</option><option value="critical">Critical</option><option value="low">Asagi</option></select></label>
            <label>Mesul
                <select name="assigned_to">
                    <option value="">Cihazdaki emekdas</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= e((string) ($employee['id'] ?? 0)); ?>"><?= e((string) ($employee['full_name'] ?? '-')); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <textarea name="notes" rows="3" placeholder="Qisa qeyd"></textarea>
        </div>
        <button class="portal-primary-btn" type="submit">Case yarat</button>
    </form>
</section>

<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head">
        <h3>Case siyahisi</h3>
        <div class="portal-badge-list">
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('cases', ['status' => 'open'], $monitorLang)); ?>">Aciq</a>
            <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('cases', ['status' => 'resolved'], $monitorLang)); ?>">Hell edilen</a>
        </div>
    </div>
    <?php if ($cases === []): ?>
        <?php billing_render_empty_state('Case yoxdur', 'Manual case yaradildiqda burada gorunecek.', 'fa-message'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Case</th><th>Cihaz / isci</th><th>Musteri</th><th>Status</th><th>Qeyd</th><th>Emeliyyat</th></tr></thead>
                <tbody>
                    <?php foreach ($cases as $case): ?>
                        <tr>
                            <td>#<?= e((string) ($case['id'] ?? '')); ?><br><small><?= e((string) ($case['case_type'] ?? '')); ?> / <?= e((string) ($case['priority'] ?? '')); ?></small></td>
                            <td><strong><?= e((string) ($case['device_name'] ?? '-')); ?></strong><br><small><?= e((string) ($case['employee_name'] ?? '-')); ?></small></td>
                            <td><?= e((string) ($case['customer_name'] ?? '-')); ?><br><small><?= e((string) ($case['subscriber_code'] ?? $case['customer_phone'] ?? '')); ?></small></td>
                            <td><span class="portal-pill"><?= e((string) ($case['status'] ?? '-')); ?></span><br><small><?= e((string) ($case['opened_at'] ?? '')); ?></small></td>
                            <td><?= e(mb_substr((string) ($case['notes'] ?? ''), 0, 120)); ?></td>
                            <td>
                                <div class="wa-table-actions">
                                    <form method="post" class="wa-inline-form">
                                        <input type="hidden" name="action" value="case_status">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="case_id" value="<?= e((string) ($case['id'] ?? 0)); ?>">
                                        <select name="case_status">
                                            <option value="waiting_employee">Cavab gozleyir</option>
                                            <option value="in_progress">Isdedir</option>
                                            <option value="replied">Cavablandi</option>
                                            <option value="escalated">Eskalasiya</option>
                                            <option value="resolved">Hell edildi</option>
                                            <option value="ignored">Yox say</option>
                                        </select>
                                        <button class="portal-ghost-btn portal-inline-link" type="submit">Yenile</button>
                                    </form>
                                    <form method="post" class="wa-inline-form">
                                        <input type="hidden" name="action" value="link_customer">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="case_id" value="<?= e((string) ($case['id'] ?? 0)); ?>">
                                        <input name="customer_query" placeholder="kod/tel/ad" required>
                                        <button class="portal-ghost-btn portal-inline-link" type="submit">Musteriye bagla</button>
                                    </form>
                                    <form method="post" class="wa-inline-form">
                                        <input type="hidden" name="action" value="create_ticket">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="case_id" value="<?= e((string) ($case['id'] ?? 0)); ?>">
                                        <button class="portal-primary-btn portal-inline-link" type="submit">Ticket yarat</button>
                                    </form>
                                    <?php if (!empty($case['ticket_no'])): ?><a class="portal-ghost-btn portal-inline-link" href="crm.php?q=<?= e(rawurlencode((string) $case['ticket_no'])); ?>">Ticket <?= e((string) $case['ticket_no']); ?></a><?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
