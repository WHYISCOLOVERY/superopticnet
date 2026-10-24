<?php
declare(strict_types=1);
require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/customer-operator-policy.php';
require_once __DIR__ . '/portal-security-lib.php';
require_once __DIR__ . '/audit-log-lib.php';
$user = billing_require_employee();
if (!app_customer_pppoe_allowed($user)) { http_response_code(403); exit('İcazə yoxdur.'); }
$id = (int) ($_GET['id'] ?? 0);
$row = app_db_account_by_id($id);
if (!$row || ($row['role'] ?? '') !== 'customer') { http_response_code(404); exit('Müştəri tapılmadı.'); }
header('Cache-Control: no-store');
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!app_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('Sessiya yoxlaması uğursuz oldu.'); }
    $result = ($_POST['action'] ?? '') === 'reset_password'
        ? app_portal_privileged_password_reset($row, $user, trim((string) ($_POST['reason'] ?? '')), 'customer_operator')
        : app_customer_operator_change(app_db(), $id, $user, $_POST);
    $message = !empty($result['ok'])
        ? (isset($result['pppoe_password'])
            ? 'PPPoE şifrəsi RADIUS-a yazıldı: ' . $result['pppoe_password']
            : (isset($result['password']) ? 'Müvəqqəti şifrə: ' . $result['password'] . '. İlk girişdə dəyişdirilməlidir.' : 'Əməliyyat saxlanıldı.'))
        : ($result['error'] ?? $result['reason'] ?? 'Əməliyyat alınmadı.');
}
$tariffs = app_db()->query('SELECT id,name,service_type,monthly_fee FROM billing_tariffs WHERE status="active" ORDER BY service_type,download_mbps,monthly_fee')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$payload = app_db_account_payload($row);
$billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
$primaryService = is_array($payload['internet_services'][0] ?? null) ? $payload['internet_services'][0] : [];
$pppoeUsername = trim((string) ($billing['pppoe_username'] ?? $primaryService['username'] ?? $row['subscriber_code'] ?? ''));
$pppoePassword = trim((string) ($billing['pppoe_password'] ?? $primaryService['password'] ?? ''));
try {
    $pppoePassword = trim((string) (app_legacy_customer_secret_reveal(app_db(), (int) $row['id'], 'pppoe_password') ?? $pppoePassword));
} catch (Throwable) {
}
$forms = ['reset_pppoe_password'=>'PPPoE şifrəsini yenilə'];
if (app_customer_operator_allowed($user)) {
    $forms += ['phone'=>'Telefonu yenilə','package'=>'Paketi dəyiş','cycle'=>'Ödəniş rejimi','debt'=>'Xidmət / avadanlıq borcu','gift_days'=>'Hədiyyə günü','borrow_days'=>'Borc günü','reset_password'=>'Panel şifrəsini yenilə'];
}
?>
<!doctype html><html lang="az"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Müştəri əməliyyatları</title><link rel="stylesheet" href="css/portal.css"></head><body><main class="portal-shell">
<section class="portal-card"><h1><?= e((string) $row['full_name']); ?></h1><p>Panel girişi: <?= e((string) $row['login']); ?></p><p>PPPoE: <strong><?= e($pppoeUsername); ?></strong> / <strong><?= e($pppoePassword !== '' ? $pppoePassword : 'Şifrə saxlanmayıb'); ?></strong></p><p><?= e($message); ?></p><a href="billing-customer-profile.php?id=<?= $id; ?>">Müştəri profilinə qayıt</a></section>
<?php foreach ($forms as $action=>$label): ?><section class="portal-card"><h2><?= e($label); ?></h2><form method="post" class="portal-stacked-form">
<input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="request_id" value="<?= bin2hex(random_bytes(16)); ?>"><input type="hidden" name="action" value="<?= $action; ?>">
<?php if ($action==='phone'): ?><label>Telefon<input name="phone" type="tel" required></label><?php endif; ?>
<?php if ($action==='reset_pppoe_password'): ?><label>Yeni PPPoE şifrəsi<input name="pppoe_password" type="text" minlength="8" maxlength="64" placeholder="Boş saxlanarsa avtomatik yaradılacaq"></label><?php endif; ?>
<?php if ($action==='package'): ?><label>Paket<select name="tariff_id" required><?php foreach ($tariffs as $tariff): ?><option value="<?= (int) $tariff['id']; ?>"><?= e((string) $tariff['service_type'] . ' / ' . (string) $tariff['name'] . ' / ' . (string) $tariff['monthly_fee'] . ' AZN'); ?></option><?php endforeach; ?></select></label><?php endif; ?>
<?php if ($action==='cycle'): ?><label>Rejim<select name="mode"><option value="30_days">30 günlük</option><option value="stable">Ayın stabil günü</option></select></label><label>Stabil gün (1–31)<input name="day" type="number" min="1" max="31"></label><p>Rejim növbəti ödənişə tətbiq olunur. Cari bitmə tarixi dəyişmir.</p><?php endif; ?>
<?php if ($action==='debt'): ?><label>Borc növü<select name="kind"><option value="service">Əlavə xidmət</option><option value="equipment">Avadanlıq</option></select></label><label>Məbləğ (AZN)<input name="amount" type="number" min="0.01" step="0.01" required></label><label>Sənəd nömrəsi<input name="document_no" required></label><?php endif; ?>
<?php if ($action==='gift_days'): ?><label>Gün sayı<input name="days" type="number" min="1" max="3" required></label><p>Hədiyyə günləri tarixçəyə yazılır; bir müştəriyə Bakı təqvim ayında ümumilikdə ən çox 3 gün verilir.</p><?php endif; ?>
<?php if ($action==='borrow_days'): ?><label>Gün sayı<input name="days" type="number" min="1" max="3" required></label><p>Müştəriyə Bakı təqvim ayı ərzində yalnız bir dəfə verilir. Növbəti tam ödənişdən çıxılır.</p><?php endif; ?>
<label>Səbəb<textarea name="reason" required></textarea></label><button class="portal-primary-btn" type="submit">Təsdiqlə</button></form></section><?php endforeach; ?>
</main></body></html>
