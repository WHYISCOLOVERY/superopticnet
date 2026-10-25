<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee();
$canTakePayment = billing_actor_can_modify_customer($user);
$isPaymentPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPaymentPost && !$canTakePayment) {
    billing_reject_customer_write($user);
}
$db = app_db();

if (PHP_SAPI !== 'cli') {
    ob_start(static function (string $html): string {
        return str_replace(
            [
                'Sessiya yoxlaması uğursuz oldu.',
                'Müştəri kodu yazılmalıdır.',
                'Ödəniş qəbul edildi, xidmət aktivləşdirildi və Radius sync yeniləndi.',
                'Ödənişi tətbiq etmək alınmadı. Müştəri kodunu, tarif qiymətini və DB/RADIUS sync-i yoxla.',
                'Nağd ödəniş',
                'Kassa əməliyyatı',
                'Nağd/manual ödəniş qəbul et',
                'Ödəniş müştəri koduna yazılır, bitmə tarixi uzadılır, servis aktiv edilir və FreeRADIUS SQL cavabı yenilənir.',
                'Seçilmiş müştəri',
                'Seçilməyib',
                'Kod yazıb yoxla',
                'Müştəri yoxlaması',
                'Kod yazıldıqda balans, paket və bitmə tarixi preview olur.',
                'Kassa əməliyyatı',
                'Nağd, manual və terminal qeydi eyni axına düşür.',
                'Gün artırma',
                'Ödənişdən sonra xidmət müddəti seçilən gün qədər uzadılır.',
                'Aktiv servis radcheck/radreply cavablarına hazırlanır.',
                'Ödəniş formu',
                'Referans sistemdəki "Nağd ödəniş" axınına uyğun sadə kassa formu.',
                'Müştəri kodu',
                'Məbləğ',
                'boş = aylıq toplam',
                'boş = tarif + xidmət cəmi',
                'Boş və ya 0 saxlanarsa internet paketi və aktiv əlavə xidmətlərin toplamı yazılır.',
                'Gün',
                '0 və ya boş = müştəri/tarif günü, default 30 gün.',
                'Ödənişçi/metod',
                'Nağd',
                'Transaction / qəbz',
                'qəbz nömrəsi',
                'Ödənişi yadda saxla və aktiv et',
                'Müştəri xülasəsi',
                'Kod yazılıbsa cari balans və bitmə məlumatı.',
                'Müştəri seçilməyib. Formda müştəri kodunu yaz.',
                'Internet qiyməti',
                'Əlavə xidmətlər',
                'Aylıq toplam',
                'Default gün',
                'Bitmə',
                'Qalan gün',
                'Profilə keç',
                'Son yenilənən müştərilər',
                'Tez seçim üçün son 25 müştəri.',
                'Müştəri',
                'Ərazi',
                'Seç',
            ],
            [
                'Sessiya yoxlamasi ugursuz oldu.',
                'Musteri kodu yazilmalidir.',
                'Odenis qebul edildi, xidmet aktivlesdirildi ve Radius sync yenilendi.',
                'Odenisi tetbiq etmek alinmadi. Musteri kodunu, tarif qiymetini ve DB/RADIUS sync-i yoxla.',
                'Nagd odenis',
                'Kassa emeliyyati',
                'Nagd/manual odenis qebul et',
                'Odenis musteri koduna yazilir, bitme tarixi uzadilir, servis aktiv edilir ve FreeRADIUS SQL cavabi yenilenir.',
                'Secilmis musteri',
                'Secilmeyib',
                'Kod yazib yoxla',
                'Musteri yoxlamasi',
                'Kod yazildiqda balans, paket ve bitme tarixi preview olur.',
                'Kassa emeliyyati',
                'Nagd, manual ve terminal qeydi eyni axina dusur.',
                'Gun artirma',
                'Odenisden sonra xidmet muddeti secilen gun qeder uzadilir.',
                'Aktiv servis radcheck/radreply cavablarina hazirlanir.',
                'Odenis formu',
                'Referans sistemdeki nagd odenis axinina uygun sade kassa formu.',
                'Musteri kodu',
                'Mebleg',
                'bos = ayliq toplam',
                'bos = tarif + xidmet cemi',
                'Bos ve ya 0 saxlanarsa internet paketi ve aktiv elave xidmetlerin toplami yazilir.',
                'Gun',
                '0 ve ya bos = musteri/tarif gunu, default 30 gun.',
                'Odenisci/metod',
                'Nagd',
                'Transaction / qebz',
                'qebz nomresi',
                'Odenisi yadda saxla ve aktiv et',
                'Musteri xulasesi',
                'Kod yazilibsa cari balans ve bitme melumati.',
                'Musteri secilmeyib. Formda musteri kodunu yaz.',
                'Internet qiymeti',
                'Elave xidmetler',
                'Ayliq toplam',
                'Default gun',
                'Bitme',
                'Qalan gun',
                'Profile kec',
                'Son yenilenen musteriler',
                'Tez secim ucun son 25 musteri.',
                'Musteri',
                'Erazi',
                'Sec',
            ],
            $html
        );
    });
}
$flash = null;
$lookupCode = clean_text((string) ($_GET['subscriber_code'] ?? ($_POST['subscriber_code'] ?? '')));
$lookupAccount = $lookupCode !== '' ? app_db_account_by_subscriber_code($lookupCode) : null;
$recentCustomers = [];
$billingDataErrorReference = $db instanceof PDO ? '' : app_runtime_error_reference('billing_cash_payment_database_unavailable');
$cashPaymentIdempotency = (string) ($_SESSION['billing_cash_payment_idempotency'] ?? '');
if ($cashPaymentIdempotency === '') {
    $cashPaymentIdempotency = bin2hex(random_bytes(24));
    $_SESSION['billing_cash_payment_idempotency'] = $cashPaymentIdempotency;
}

if ($lookupAccount === null && $lookupCode !== '' && !($db instanceof PDO)) {
    foreach (billing_mock_customer_rows() as $mockCustomer) {
        if ((string) ($mockCustomer['subscriber_code'] ?? '') === $lookupCode || (string) ($mockCustomer['login'] ?? '') === $lookupCode) {
            $lookupAccount = $mockCustomer;
            $lookupAccount['payload_json'] = app_db_json_encode([
                'billing' => [
                    'tariff' => (string) ($mockCustomer['tariff_name'] ?? $mockCustomer['plan'] ?? ''),
                    'status' => (string) ($mockCustomer['status'] ?? ''),
                    'expires_at' => (string) ($mockCustomer['expires_at'] ?? ''),
                    'balance' => (string) ($mockCustomer['debt_raw'] ?? '0 AZN'),
                ],
            ]);
            break;
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlaması uğursuz oldu.'];
    } elseif ($db instanceof PDO) {
        $amount = app_billing_parse_money_amount($_POST['amount'] ?? 0);
        $days = max(0, (int) ($_POST['days'] ?? 0));
        $method = clean_text((string) ($_POST['method'] ?? 'cash'));
        $transactionId = clean_text((string) ($_POST['transaction_id'] ?? ''));
        $submittedIdempotency = trim((string) ($_POST['idempotency_key'] ?? ''));
        if ($lookupCode === '') {
            $flash = ['type' => 'error', 'message' => 'Müştəri kodu yazılmalıdır.'];
        } elseif ($submittedIdempotency === '' || !hash_equals($cashPaymentIdempotency, $submittedIdempotency)) {
            $flash = ['type' => 'error', 'message' => 'Odenis sorğusunun tesdiq acarini yenileyib tekrar cehd edin.'];
        } elseif (app_billing_apply_customer_payment(
            $lookupCode,
            $amount,
            $method,
            $days,
            $transactionId,
            '',
            null,
            $submittedIdempotency
        )) {
            $lookupAccount = app_db_account_by_subscriber_code($lookupCode);
            $cashPaymentIdempotency = bin2hex(random_bytes(24));
            $_SESSION['billing_cash_payment_idempotency'] = $cashPaymentIdempotency;
            $flash = ['type' => 'success', 'message' => 'Ödəniş qəbul edildi, xidmət aktivləşdirildi və Radius sync yeniləndi.'];
        } else {
            $flash = ['type' => 'error', 'message' => 'Ödənişi tətbiq etmək alınmadı. Müştəri kodunu, tarif qiymətini və DB/RADIUS sync-i yoxla.'];
        }
    }
}

if ($db instanceof PDO) {
    $recentCustomers = $db->query(
        "SELECT id, subscriber_code, full_name, phone, area, status
         FROM portal_accounts
         WHERE role = 'customer'
         ORDER BY updated_at DESC
         LIMIT 25"
    )->fetchAll() ?: [];
} else {
    $recentCustomers = array_slice(billing_mock_customer_rows(), 0, 25);
}

$lookupPayload = is_array($lookupAccount) ? app_db_account_payload($lookupAccount) : [];
$lookupBilling = is_array($lookupPayload['billing'] ?? null) ? $lookupPayload['billing'] : [];
$lookupTariff = ($db instanceof PDO && is_array($lookupAccount)) ? app_billing_account_tariff($db, $lookupAccount, $lookupBilling) : null;
$lookupDefaultDays = is_array($lookupAccount) ? app_billing_account_default_days($lookupAccount, $lookupBilling, $lookupTariff) : 30;
$lookupMonthlyBreakdown = is_array($lookupAccount) ? app_billing_account_monthly_total($lookupAccount, $lookupBilling, $lookupTariff, $lookupPayload) : ['internet' => 0.0, 'extra' => 0.0, 'total' => 0.0];
$lookupDefaultAmount = (float) ($lookupMonthlyBreakdown['total'] ?? 0.0);
$lookupExpiresAt = (string) ($lookupBilling['expires_at'] ?? '');
$lookupRemainingDays = $lookupExpiresAt !== '' ? app_billing_remaining_days_text($lookupExpiresAt) : '-';
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nağd ödəniş</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
    <?php billing_render_header('Nağd ödəniş', 'cash', $lang); ?>

    <main class="portal-layout">
        <?php if ($billingDataErrorReference !== ''): ?>
            <div class="portal-alert portal-alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span>Odenis melumatlari yuklenmedi. Xeta arayisi: <?= e($billingDataErrorReference); ?></span>
            </div>
        <?php endif; ?>
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">Kassa əməliyyatı</span>
                <h1>Nağd/manual ödəniş qəbul et</h1>
                <p>Ödəniş müştəri koduna yazılır, bitmə tarixi uzadılır, servis aktiv edilir və FreeRADIUS SQL cavabı yenilənir.</p>
            </div>
            <div class="portal-status-card">
                <span>Seçilmiş müştəri</span>
                <strong><?= e(is_array($lookupAccount) ? (string) ($lookupAccount['subscriber_code'] ?? '-') : 'Seçilməyib'); ?></strong>
                <small><?= e(is_array($lookupAccount) ? (string) ($lookupAccount['full_name'] ?? '-') : 'Kod yazıb yoxla'); ?></small>
            </div>
        </section>

        <section class="mgs-action-strip portal-reveal">
            <article class="mgs-action-tile"><i class="fa-solid fa-magnifying-glass"></i><strong>Müştəri yoxlaması</strong><small>Kod yazıldıqda balans, paket və bitmə tarixi preview olur.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-cash-register"></i><strong>Kassa əməliyyatı</strong><small>Nağd, manual və terminal qeydi eyni axına düşür.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-calendar-plus"></i><strong>Gün artırma</strong><small>Ödənişdən sonra xidmət müddəti seçilən gün qədər uzadılır.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-satellite-dish"></i><strong>Radius sync</strong><small>Aktiv servis radcheck/radreply cavablarına hazırlanır.</small></article>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head"><h2>Ödəniş formu</h2><small>Referans sistemdəki "Nağd ödəniş" axınına uyğun sadə kassa formu.</small></div>
                <form method="post" class="portal-stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="idempotency_key" value="<?= e($cashPaymentIdempotency); ?>">
                    <div class="portal-form-row">
                        <label>Müştəri kodu</label>
                        <input type="text" name="subscriber_code" value="<?= e($lookupCode); ?>" placeholder="1100000" required>
                    </div>
                    <div class="portal-form-row">
                        <label>Məbləğ</label>
                        <input type="number" name="amount" min="0" step="0.01" placeholder="<?= e($lookupDefaultAmount > 0 ? number_format($lookupDefaultAmount, 2, '.', '') . ' / boş = aylıq toplam' : 'boş = tarif + xidmət cəmi'); ?>">
                        <small>Boş və ya 0 saxlanarsa internet paketi və aktiv əlavə xidmətlərin toplamı yazılır.</small>
                    </div>
                    <div class="portal-form-row">
                        <label>Gün</label>
                        <input type="number" name="days" min="0" max="366" step="1" value="<?= e((string) $lookupDefaultDays); ?>">
                        <small>0 və ya boş = müştəri/tarif günü, default 30 gün.</small>
                    </div>
                    <div class="portal-form-row">
                        <label>Ödənişçi/metod</label>
                        <select name="method">
                            <option value="cash">Nağd</option>
                            <option value="manual">Manual</option>
                            <option value="terminal">Terminal</option>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Transaction / qəbz</label>
                        <input type="text" name="transaction_id" placeholder="qəbz nömrəsi">
                    </div>
                    <button type="submit" class="portal-primary-btn">Ödənişi yadda saxla və aktiv et</button>
                </form>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>Müştəri xülasəsi</h2><small>Kod yazılıbsa cari balans və bitmə məlumatı.</small></div>
                <?php if (!is_array($lookupAccount)): ?>
                    <p>Müştəri seçilməyib. Formda müştəri kodunu yaz.</p>
                <?php else: ?>
                    <div class="portal-profile-list">
                        <div><span>Ad</span><strong><?= e((string) ($lookupAccount['full_name'] ?? '-')); ?></strong></div>
                        <div><span>Telefon</span><strong><?= e((string) ($lookupAccount['phone'] ?? '-')); ?></strong></div>
                        <div><span>Tarif</span><strong><?= e((string) ($lookupBilling['tariff'] ?? $lookupAccount['plan'] ?? '-')); ?></strong></div>
                        <div><span>Internet qiyməti</span><strong><?= e(number_format((float) ($lookupMonthlyBreakdown['internet'] ?? 0), 2, '.', '')); ?> AZN</strong></div>
                        <div><span>Əlavə xidmətlər</span><strong><?= e(number_format((float) ($lookupMonthlyBreakdown['extra'] ?? 0), 2, '.', '')); ?> AZN</strong></div>
                        <div><span>Aylıq toplam</span><strong><?= e($lookupDefaultAmount > 0 ? number_format($lookupDefaultAmount, 2, '.', '') . ' AZN' : '-'); ?></strong></div>
                        <div><span>Default gün</span><strong><?= e((string) $lookupDefaultDays); ?> gün</strong></div>
                        <div><span>Status</span><strong><?= e((string) ($lookupBilling['status'] ?? $lookupAccount['status'] ?? '-')); ?></strong></div>
                        <div><span>Bitmə</span><strong><?= e(billing_datetime($lookupBilling['expires_at'] ?? null)); ?></strong></div>
                        <div><span>Qalan gün</span><strong><?= e($lookupRemainingDays); ?></strong></div>
                        <div><span>Balans</span><strong><?= e((string) ($lookupBilling['balance'] ?? '-')); ?></strong></div>
                    </div>
                    <a class="portal-ghost-btn portal-inline-link portal-section-gap" href="billing-customer-profile.php?id=<?= e((string) ($lookupAccount['id'] ?? 0)); ?>#profile-payments">Profilə keç</a>
                <?php endif; ?>
            </article>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head"><h2>Son yenilənən müştərilər</h2><small>Tez seçim üçün son 25 müştəri.</small></div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Kod</th><th>Müştəri</th><th>Telefon</th><th>Ərazi</th><th>Status</th><th>Seç</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentCustomers as $customer): ?>
                            <tr>
                                <td><?= e((string) ($customer['subscriber_code'] ?? '-')); ?></td>
                                <td><?= e((string) ($customer['full_name'] ?? '-')); ?></td>
                                <td><?= e((string) ($customer['phone'] ?? '-')); ?></td>
                                <td><?= e((string) ($customer['area'] ?? '-')); ?></td>
                                <td><span class="portal-pill <?= e(billing_status_class((string) ($customer['status'] ?? ''))); ?>"><?= e((string) ($customer['status'] ?? '-')); ?></span></td>
                                <td><a class="portal-ghost-btn portal-inline-link" href="billing-cash-payment.php?subscriber_code=<?= rawurlencode((string) ($customer['subscriber_code'] ?? '')); ?>">Seç</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
