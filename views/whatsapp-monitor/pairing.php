<?php
declare(strict_types=1);

$numbers = (array) ($context['numbers'] ?? []);
$devices = (array) ($context['devices'] ?? []);
$pairings = (array) ($context['pairings'] ?? []);
$latestPairing = $pairings[0] ?? null;
$canManage = !empty($context['can_manage']);
?>
<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head">
        <div>
            <h3>QR ilə WhatsApp bağlantısı</h3>
            <small>Hər şirkət nömrəsi ayrı QR sessiyası ilə qoşulur. QR oxudulmadan nömrə monitorinqə qoşulmuş sayılmır.</small>
        </div>
        <span class="portal-pill">Status avtomatik yenilənir</span>
    </div>
    <ol class="wa-process-steps">
        <li>Sessiya yaradılır: super admin nömrə üçün yeni QR sessiyası açır, əvvəlki açıq sessiya ləğv olunur.</li>
        <li>QR hazırlanır: sistem bir neçə saniyə ərzində QR kodu ekrana göndərir.</li>
        <li>Nömrə qoşulur: telefon WhatsApp-da QR oxudulur və status "Qoşulub" olur.</li>
    </ol>
</section>

<?php if ($canManage): ?>
    <section class="portal-muted-card portal-section-gap">
        <div class="portal-card-head"><h3>Yeni QR sessiyası</h3><small>Policy razılığı olan aktiv nömrə seçin.</small></div>
        <form method="post" class="portal-stacked-form">
            <input type="hidden" name="action" value="start_pairing">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <div class="wa-form-grid">
                <label>Nömrə
                    <select name="number_id" required>
                        <option value="">Seçin</option>
                        <?php foreach ($numbers as $number): ?>
                            <option value="<?= e((string) ($number['id'] ?? 0)); ?>">
                                <?= e((string) ($number['phone_number'] ?? '-')); ?> / <?= e((string) ($number['employee_name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Cihaz
                    <select name="device_id">
                        <option value="">Seçilməyib</option>
                        <?php foreach ($devices as $device): ?>
                            <option value="<?= e((string) ($device['id'] ?? 0)); ?>">
                                <?= e((string) ($device['device_name'] ?? '-')); ?> / <?= e((string) ($device['phone_number'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="portal-primary-btn" type="submit"><i class="fa-solid fa-qrcode"></i> QR sessiyası yarat</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php if (is_array($latestPairing)): ?>
    <?php
    $pairingStatus = (string) ($latestPairing['pairing_status'] ?? 'requested');
    $qrImage = trim((string) ($latestPairing['qr_image_data_uri'] ?? ''));
    $hasQrImage = str_starts_with($qrImage, 'data:image/');
    ?>
    <section class="portal-muted-card portal-section-gap" data-wa-pairing-live data-session-key="<?= e((string) ($latestPairing['session_key'] ?? '')); ?>" data-pairing-status="<?= e($pairingStatus); ?>">
        <div class="portal-card-head">
            <div>
                <h3>Son QR sessiyası</h3>
                <small><?= e((string) ($latestPairing['phone_number'] ?? '-')); ?> / <?= e((string) ($latestPairing['employee_name'] ?? '-')); ?></small>
            </div>
            <span class="wa-status <?= e($pairingStatus); ?>" data-wa-pairing-status><?= e(whatsapp_monitor_pairing_status_label($pairingStatus)); ?></span>
        </div>
        <div class="wa-qr-panel">
            <div class="wa-qr-box<?= $hasQrImage ? '' : ' is-loading'; ?>" data-wa-qr-box>
                <?php if ($hasQrImage): ?>
                    <img src="<?= e($qrImage); ?>" alt="WhatsApp QR">
                <?php else: ?>
                    <div>
                        <i class="fa-solid fa-qrcode wa-qr-icon" aria-hidden="true"></i>
                        <strong>QR kodu hazırlanır</strong>
                        <small class="wa-muted">Bir neçə saniyəyə QR kodu bu bloka avtomatik görünəcək.</small>
                    </div>
                <?php endif; ?>
            </div>
            <div class="wa-card">
                <small>Bitmə vaxtı: <?= e((string) ($latestPairing['expires_at'] ?? '-')); ?></small>
                <?php if (!empty($latestPairing['failure_reason'])): ?>
                    <small>Xəta: <?= e((string) ($latestPairing['failure_reason'] ?? '')); ?></small>
                <?php endif; ?>
                <details class="wa-tech-details">
                    <summary>Texniki məlumat</summary>
                    <strong>Session key</strong>
                    <div class="wa-session-key"><?= e((string) ($latestPairing['session_key'] ?? '')); ?></div>
                    <small>Worker ID: <span data-wa-worker><?= e((string) ($latestPairing['worker_id'] ?? '-')); ?></span></small>
                </details>
                <?php if ($canManage && in_array($pairingStatus, ['requested', 'qr_ready', 'scanned', 'connected'], true)): ?>
                    <form method="post" class="portal-section-gap">
                        <input type="hidden" name="action" value="revoke_pairing">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="number_id" value="<?= e((string) ($latestPairing['number_id'] ?? 0)); ?>">
                        <button class="portal-ghost-btn" type="submit">Bağlantını ləğv et</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>Nömrələrin QR vəziyyəti</h3><small>Qoşulmamış nömrələr monitorinqdə tam aktiv sayılmır.</small></div>
    <?php if ($numbers === []): ?>
        <?php billing_render_empty_state('Nömrə yoxdur', 'Əvvəl şirkət WhatsApp nömrəsini əlavə edin.', 'fa-brands fa-whatsapp'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Nömrə</th><th>Əməkdaş</th><th>QR status</th><th>Son sessiya</th><th>Policy</th><th>Əməliyyat</th></tr></thead>
                <tbody>
                    <?php foreach ($numbers as $number): ?>
                        <?php
                        $qrStatus = (string) ($number['qr_status'] ?? 'not_connected');
                        $numberId = (int) ($number['id'] ?? 0);
                        ?>
                        <tr>
                            <td><strong><?= e((string) ($number['phone_number'] ?? '-')); ?></strong><br><small><?= e((string) ($number['whatsapp_account_name'] ?? '')); ?></small></td>
                            <td><?= e((string) ($number['employee_name'] ?? '-')); ?></td>
                            <td><span class="wa-status <?= e($qrStatus); ?>"><?= e(whatsapp_monitor_pairing_status_label($qrStatus)); ?></span></td>
                            <td><?= e((string) ($number['qr_last_requested_at'] ?? '-')); ?><br><small><?= e((string) ($number['qr_session_key'] ?? '')); ?></small></td>
                            <td><?= !empty($number['monitoring_policy_accepted']) ? 'Razılıq var' : 'Razılıq yoxdur'; ?></td>
                            <td class="wa-table-actions">
                                <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('pairing', ['number_id' => $numberId], $monitorLang)); ?>">Sessiyalar</a>
                                <?php if ($canManage): ?>
                                    <form method="post" class="wa-inline-form">
                                        <input type="hidden" name="action" value="start_pairing">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="number_id" value="<?= e((string) $numberId); ?>">
                                        <button class="portal-primary-btn portal-inline-link" type="submit">QR yarat</button>
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

<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>QR sessiya jurnalı</h3><small>Worker və admin əməliyyatları audit üçün saxlanır.</small></div>
    <?php if ($pairings === []): ?>
        <?php billing_render_empty_state('Sessiya yoxdur', 'QR sessiyası yaradılanda burada görünəcək.', 'fa-qrcode'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Nömrə</th><th>Status</th><th>Cihaz</th><th>Worker</th><th>Tarix</th></tr></thead>
                <tbody>
                    <?php foreach ($pairings as $pairing): ?>
                        <?php $status = (string) ($pairing['pairing_status'] ?? 'requested'); ?>
                        <tr>
                            <td><?= e((string) ($pairing['phone_number'] ?? '-')); ?><br><small><?= e((string) ($pairing['session_key'] ?? '')); ?></small></td>
                            <td><span class="wa-status <?= e($status); ?>"><?= e(whatsapp_monitor_pairing_status_label($status)); ?></span></td>
                            <td><?= e((string) ($pairing['device_name'] ?? '-')); ?></td>
                            <td><?= e((string) ($pairing['worker_id'] ?? '-')); ?></td>
                            <td><?= e((string) ($pairing['created_at'] ?? '-')); ?><br><small><?= e((string) ($pairing['updated_at'] ?? '')); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
