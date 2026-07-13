<?php
declare(strict_types=1);

$numbers = (array) ($context['numbers'] ?? []);
$employees = (array) ($context['employees'] ?? []);
?>
<?php if (!empty($context['can_manage'])): ?>
    <section class="portal-muted-card portal-section-gap">
        <div class="portal-card-head"><h3>Isci WhatsApp nomresi elave et</h3><small>Bir nomre yalniz bir aktiv emekdasa bagli ola biler.</small></div>
        <form method="post" class="portal-stacked-form">
            <input type="hidden" name="action" value="save_number">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <div class="wa-form-grid">
                <label>Emekdas
                    <select name="employee_id">
                        <option value="">Secin</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= e((string) ($employee['id'] ?? 0)); ?>"><?= e((string) ($employee['full_name'] ?? '-')); ?> / <?= e((string) ($employee['employee_role'] ?? '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Nomre <input name="phone_number" placeholder="0501234567" required></label>
                <label>WhatsApp adi <input name="whatsapp_account_name" placeholder="SuperOptik Operator"></label>
                <label>Erazi <input name="assigned_area_id" placeholder="Baki / Astara"></label>
                <label>Status
                    <select name="status"><option value="active">Aktiv</option><option value="inactive">Passiv</option><option value="blocked">Bloklu</option><option value="lost">Itib</option></select>
                </label>
                <label>Policy versiya <input name="policy_version" value="2026-07"></label>
                <label class="portal-checkbox-line"><input type="checkbox" name="is_company_owned" value="1" checked><span>Sirket nomresidir</span></label>
                <label class="portal-checkbox-line"><input type="checkbox" name="monitoring_policy_accepted" value="1"><span>Monitorinq raziligi alinib</span></label>
                <textarea name="notes" rows="3" placeholder="Qeyd"></textarea>
            </div>
            <button class="portal-primary-btn" type="submit">Nomreni saxla</button>
        </form>
    </section>
<?php endif; ?>

<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>Isci nomreleri</h3><small>Raziliq yoxdursa monitorinq panelinde warning gorunur.</small></div>
    <?php if ($numbers === []): ?>
        <?php billing_render_empty_state('Nomre yoxdur', 'Sirket WhatsApp nomreleri elave edilende burada gorunecek.', 'fa-brands fa-whatsapp'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Emekdas</th><th>Nomre</th><th>Erazi</th><th>Status</th><th>QR</th><th>Raziliq</th><th>Tehkim</th><th>Emeliyyat</th></tr></thead>
                <tbody>
                    <?php foreach ($numbers as $number): ?>
                        <?php $qrStatus = (string) ($number['qr_status'] ?? 'not_connected'); ?>
                        <tr>
                            <td><strong><?= e((string) ($number['employee_name'] ?? '-')); ?></strong><br><small><?= e((string) ($number['employee_role'] ?? '')); ?></small></td>
                            <td><?= e((string) ($number['phone_number'] ?? '-')); ?><br><small><?= e((string) ($number['whatsapp_account_name'] ?? '')); ?></small></td>
                            <td><?= e((string) ($number['assigned_area_id'] ?? '-')); ?></td>
                            <td><span class="portal-pill"><?= e((string) ($number['status'] ?? '-')); ?></span></td>
                            <td><span class="wa-status <?= e($qrStatus); ?>"><?= e(whatsapp_monitor_pairing_status_label($qrStatus)); ?></span></td>
                            <td><?= !empty($number['monitoring_policy_accepted']) ? 'Qebul edilib' : 'Gozleyir'; ?></td>
                            <td><?= e((string) ($number['assigned_at'] ?? '-')); ?></td>
                            <td>
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
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
