<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee();
$db = app_db();

if (PHP_SAPI !== 'cli') {
    ob_start(static function (string $html): string {
        return str_replace(
            [
                'Sessiya yoxlamasÃ„Â± uÃ„Å¸ursuz oldu.',
                'MÃƒÂ¼Ã…Å¸tÃ‰â„¢ri kodu yazÃ„Â±lmalÃ„Â±dÃ„Â±r.',
                'Ãƒâ€“dÃ‰â„¢niÃ…Å¸ qÃ‰â„¢bul edildi, xidmÃ‰â„¢t aktivlÃ‰â„¢Ã…Å¸dirildi vÃ‰â„¢ Radius sync yenilÃ‰â„¢ndi.',
                'Ãƒâ€“dÃ‰â„¢niÃ…Å¸i tÃ‰â„¢tbiq etmÃ‰â„¢k alÃ„Â±nmadÃ„Â±. MÃƒÂ¼Ã…Å¸tÃ‰â„¢ri kodunu, tarif qiymÃ‰â„¢tini vÃ‰â„¢ DB/RADIUS sync-i yoxla.',
                'NaÃ„Å¸d ÃƒÂ¶dÃ‰â„¢niÃ…Å¸',
                'Kassa Ã‰â„¢mÃ‰â„¢liyyatÃ„Â±',
                'NaÃ„Å¸d/manual ÃƒÂ¶dÃ‰â„¢niÃ…Å¸ qÃ‰â„¢bul et',
                'Ãƒâ€“dÃ‰â„¢niÃ…Å¸ mÃƒÂ¼Ã…Å¸tÃ‰â„¢ri koduna yazÃ„Â±lÃ„Â±r, bitmÃ‰â„¢ tarixi uzadÃ„Â±lÃ„Â±r, servis aktiv edilir vÃ‰â„¢ FreeRADIUS SQL cavabÃ„Â± yenilÃ‰â„¢nir.',
                'SeÃƒÂ§ilmiÃ…Å¸ mÃƒÂ¼Ã…Å¸tÃ‰â„¢ri',
                'SeÃƒÂ§ilmÃ‰â„¢yib',
                'Kod yazÃ„Â±b yoxla',
                'MÃƒÂ¼Ã…Å¸tÃ‰â„¢ri yoxlamasÃ„Â±',
                'Kod yazÃ„Â±ldÃ„Â±qda balans, paket vÃ‰â„¢ bitmÃ‰â„¢ tarixi preview olur.',
                'Kassa Ã‰â„¢mÃ‰â„¢liyyatÃ„Â±',
                'NaÃ„Å¸d, manual vÃ‰â„¢ terminal qeydi eyni axÃ„Â±na dÃƒÂ¼Ã…Å¸ÃƒÂ¼r.',
                'GÃƒÂ¼n artÃ„Â±rma',
                'Ãƒâ€“dÃ‰â„¢niÃ…Å¸dÃ‰â„¢n sonra xidmÃ‰â„¢t mÃƒÂ¼ddÃ‰â„¢ti seÃƒÂ§ilÃ‰â„¢n gÃƒÂ¼n qÃ‰â„¢dÃ‰â„¢r uzadÃ„Â±lÃ„Â±r.',
                'Aktiv servis radcheck/radreply cavablarÃ„Â±na hazÃ„Â±rlanÃ„Â±r.',
                'Ãƒâ€“dÃ‰â„¢niÃ…Å¸ formu',
                'Referans sistemdÃ‰â„¢ki Ã¢â‚¬Å“NaÃ„Å¸d ÃƒÂ¶dÃ‰â„¢niÃ…Å¸Ã¢â‚¬Â axÃ„Â±nÃ„Â±na uyÃ„Å¸un sadÃ‰â„¢ kassa formu.',
                'MÃƒÂ¼Ã…Å¸tÃ‰â„¢ri kodu',
                'MÃ‰â„¢blÃ‰â„¢Ã„Å¸',
                'boÃ…Å¸ = aylÃ„Â±q toplam',
                'boÃ…Å¸ = tarif + xidmÃ‰â„¢t cÃ‰â„¢mi',
                'BoÃ…Å¸ vÃ‰â„¢ ya 0 saxlanarsa internet paketi vÃ‰â„¢ aktiv Ã‰â„¢lavÃ‰â„¢ xidmÃ‰â„¢tlÃ‰â„¢rin toplamÃ„Â± yazÃ„Â±lÃ„Â±r.',
                'GÃƒÂ¼n',
                '0 vÃ‰â„¢ ya boÃ…Å¸ = mÃƒÂ¼Ã…Å¸tÃ‰â„¢ri/tarif gÃƒÂ¼nÃƒÂ¼, default 30 gÃƒÂ¼n.',
                'Ãƒâ€“dÃ‰â„¢niÃ…Å¸ÃƒÂ§i/metod',
                'NaÃ„Å¸d',
                'Transaction / qÃ‰â„¢bz',
                'qÃ‰â„¢bz nÃƒÂ¶mrÃ‰â„¢si',
                'Ãƒâ€“dÃ‰â„¢niÃ…Å¸i yadda saxla vÃ‰â„¢ aktiv et',
                'MÃƒÂ¼Ã…Å¸tÃ‰â„¢ri xÃƒÂ¼lasÃ‰â„¢si',
                'Kod yazÃ„Â±lÃ„Â±bsa cari balans vÃ‰â„¢ bitmÃ‰â„¢ mÃ‰â„¢lumatÃ„Â±.',
                'MÃƒÂ¼Ã…Å¸tÃ‰â„¢ri seÃƒÂ§ilmÃ‰â„¢yib. Formda mÃƒÂ¼Ã…Å¸tÃ‰â„¢ri kodunu yaz.',
                'Internet qiymÃ‰â„¢ti',
                'Ã†ÂlavÃ‰â„¢ xidmÃ‰â„¢tlÃ‰â„¢r',
                'AylÃ„Â±q toplam',
                'Default gÃƒÂ¼n',
                'BitmÃ‰â„¢',
                'Qalan gÃƒÂ¼n',
                'ProfilÃ‰â„¢ keÃƒÂ§',
                'Son yenilÃ‰â„¢nÃ‰â„¢n mÃƒÂ¼Ã…Å¸tÃ‰â„¢rilÃ‰â„¢r',
                'Tez seÃƒÂ§im ÃƒÂ¼ÃƒÂ§ÃƒÂ¼n son 25 mÃƒÂ¼Ã…Å¸tÃ‰â„¢ri.',
                'MÃƒÂ¼Ã…Å¸tÃ‰â„¢ri',
                'Ã†Ârazi',
                'SeÃƒÂ§',
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
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasÄ± uÄŸursuz oldu.'];
    } elseif ($db instanceof PDO) {
        $amount = app_billing_parse_money_amount($_POST['amount'] ?? 0);
        $days = max(0, (int) ($_POST['days'] ?? 0));
        $method = clean_text((string) ($_POST['method'] ?? 'cash'));
        $transactionId = clean_text((string) ($_POST['transaction_id'] ?? ''));
        if ($lookupCode === '') {
            $flash = ['type' => 'error', 'message' => 'MÃ¼ÅŸtÉ™ri kodu yazÄ±lmalÄ±dÄ±r.'];
        } elseif (app_billing_apply_customer_payment($lookupCode, $amount, $method, $days, $transactionId)) {
            $lookupAccount = app_db_account_by_subscriber_code($lookupCode);
            $flash = ['type' => 'success', 'message' => 'Ã–dÉ™niÅŸ qÉ™bul edildi, xidmÉ™t aktivlÉ™ÅŸdirildi vÉ™ Radius sync yenilÉ™ndi.'];
        } else {
            $flash = ['type' => 'error', 'message' => 'Ã–dÉ™niÅŸi tÉ™tbiq etmÉ™k alÄ±nmadÄ±. MÃ¼ÅŸtÉ™ri kodunu, tarif qiymÉ™tini vÉ™ DB/RADIUS sync-i yoxla.'];
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
    <title>NaÄŸd Ã¶dÉ™niÅŸ</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
    <?php billing_render_header('NaÄŸd Ã¶dÉ™niÅŸ', 'cash', $lang); ?>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">Kassa É™mÉ™liyyatÄ±</span>
                <h1>NaÄŸd/manual Ã¶dÉ™niÅŸ qÉ™bul et</h1>
                <p>Ã–dÉ™niÅŸ mÃ¼ÅŸtÉ™ri koduna yazÄ±lÄ±r, bitmÉ™ tarixi uzadÄ±lÄ±r, servis aktiv edilir vÉ™ FreeRADIUS SQL cavabÄ± yenilÉ™nir.</p>
            </div>
            <div class="portal-status-card">
                <span>SeÃ§ilmiÅŸ mÃ¼ÅŸtÉ™ri</span>
                <strong><?= e(is_array($lookupAccount) ? (string) ($lookupAccount['subscriber_code'] ?? '-') : 'SeÃ§ilmÉ™yib'); ?></strong>
                <small><?= e(is_array($lookupAccount) ? (string) ($lookupAccount['full_name'] ?? '-') : 'Kod yazÄ±b yoxla'); ?></small>
            </div>
        </section>

        <section class="mgs-action-strip portal-reveal">
            <article class="mgs-action-tile"><i class="fa-solid fa-magnifying-glass"></i><strong>MÃ¼ÅŸtÉ™ri yoxlamasÄ±</strong><small>Kod yazÄ±ldÄ±qda balans, paket vÉ™ bitmÉ™ tarixi preview olur.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-cash-register"></i><strong>Kassa É™mÉ™liyyatÄ±</strong><small>NaÄŸd, manual vÉ™ terminal qeydi eyni axÄ±na dÃ¼ÅŸÃ¼r.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-calendar-plus"></i><strong>GÃ¼n artÄ±rma</strong><small>Ã–dÉ™niÅŸdÉ™n sonra xidmÉ™t mÃ¼ddÉ™ti seÃ§ilÉ™n gÃ¼n qÉ™dÉ™r uzadÄ±lÄ±r.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-satellite-dish"></i><strong>Radius sync</strong><small>Aktiv servis radcheck/radreply cavablarÄ±na hazÄ±rlanÄ±r.</small></article>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head"><h2>Ã–dÉ™niÅŸ formu</h2><small>Referans sistemdÉ™ki â€œNaÄŸd Ã¶dÉ™niÅŸâ€ axÄ±nÄ±na uyÄŸun sadÉ™ kassa formu.</small></div>
                <form method="post" class="portal-stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <div class="portal-form-row">
                        <label>MÃ¼ÅŸtÉ™ri kodu</label>
                        <input type="text" name="subscriber_code" value="<?= e($lookupCode); ?>" placeholder="1100000" required>
                    </div>
                    <div class="portal-form-row">
                        <label>MÉ™blÉ™ÄŸ</label>
                        <input type="number" name="amount" min="0" step="0.01" placeholder="<?= e($lookupDefaultAmount > 0 ? number_format($lookupDefaultAmount, 2, '.', '') . ' / boÅŸ = aylÄ±q toplam' : 'boÅŸ = tarif + xidmÉ™t cÉ™mi'); ?>">
                        <small>BoÅŸ vÉ™ ya 0 saxlanarsa internet paketi vÉ™ aktiv É™lavÉ™ xidmÉ™tlÉ™rin toplamÄ± yazÄ±lÄ±r.</small>
                    </div>
                    <div class="portal-form-row">
                        <label>GÃ¼n</label>
                        <input type="number" name="days" min="0" max="366" step="1" value="<?= e((string) $lookupDefaultDays); ?>">
                        <small>0 vÉ™ ya boÅŸ = mÃ¼ÅŸtÉ™ri/tarif gÃ¼nÃ¼, default 30 gÃ¼n.</small>
                    </div>
                    <div class="portal-form-row">
                        <label>Ã–dÉ™niÅŸÃ§i/metod</label>
                        <select name="method">
                            <option value="cash">NaÄŸd</option>
                            <option value="manual">Manual</option>
                            <option value="terminal">Terminal</option>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Transaction / qÉ™bz</label>
                        <input type="text" name="transaction_id" placeholder="qÉ™bz nÃ¶mrÉ™si">
                    </div>
                    <button type="submit" class="portal-primary-btn">Ã–dÉ™niÅŸi yadda saxla vÉ™ aktiv et</button>
                </form>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>MÃ¼ÅŸtÉ™ri xÃ¼lasÉ™si</h2><small>Kod yazÄ±lÄ±bsa cari balans vÉ™ bitmÉ™ mÉ™lumatÄ±.</small></div>
                <?php if (!is_array($lookupAccount)): ?>
                    <p>MÃ¼ÅŸtÉ™ri seÃ§ilmÉ™yib. Formda mÃ¼ÅŸtÉ™ri kodunu yaz.</p>
                <?php else: ?>
                    <div class="portal-profile-list">
                        <div><span>Ad</span><strong><?= e((string) ($lookupAccount['full_name'] ?? '-')); ?></strong></div>
                        <div><span>Telefon</span><strong><?= e((string) ($lookupAccount['phone'] ?? '-')); ?></strong></div>
                        <div><span>Tarif</span><strong><?= e((string) ($lookupBilling['tariff'] ?? $lookupAccount['plan'] ?? '-')); ?></strong></div>
                        <div><span>Internet qiymÉ™ti</span><strong><?= e(number_format((float) ($lookupMonthlyBreakdown['internet'] ?? 0), 2, '.', '')); ?> AZN</strong></div>
                        <div><span>ÆlavÉ™ xidmÉ™tlÉ™r</span><strong><?= e(number_format((float) ($lookupMonthlyBreakdown['extra'] ?? 0), 2, '.', '')); ?> AZN</strong></div>
                        <div><span>AylÄ±q toplam</span><strong><?= e($lookupDefaultAmount > 0 ? number_format($lookupDefaultAmount, 2, '.', '') . ' AZN' : '-'); ?></strong></div>
                        <div><span>Default gÃ¼n</span><strong><?= e((string) $lookupDefaultDays); ?> gÃ¼n</strong></div>
                        <div><span>Status</span><strong><?= e((string) ($lookupBilling['status'] ?? $lookupAccount['status'] ?? '-')); ?></strong></div>
                        <div><span>BitmÉ™</span><strong><?= e(billing_datetime($lookupBilling['expires_at'] ?? null)); ?></strong></div>
                        <div><span>Qalan gÃ¼n</span><strong><?= e($lookupRemainingDays); ?></strong></div>
                        <div><span>Balans</span><strong><?= e((string) ($lookupBilling['balance'] ?? '-')); ?></strong></div>
                    </div>
                    <a class="portal-ghost-btn portal-inline-link portal-section-gap" href="billing-customer-profile.php?id=<?= e((string) ($lookupAccount['id'] ?? 0)); ?>#profile-payments">ProfilÉ™ keÃ§</a>
                <?php endif; ?>
            </article>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head"><h2>Son yenilÉ™nÉ™n mÃ¼ÅŸtÉ™rilÉ™r</h2><small>Tez seÃ§im Ã¼Ã§Ã¼n son 25 mÃ¼ÅŸtÉ™ri.</small></div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Kod</th><th>MÃ¼ÅŸtÉ™ri</th><th>Telefon</th><th>Ærazi</th><th>Status</th><th>SeÃ§</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentCustomers as $customer): ?>
                            <tr>
                                <td><?= e((string) ($customer['subscriber_code'] ?? '-')); ?></td>
                                <td><?= e((string) ($customer['full_name'] ?? '-')); ?></td>
                                <td><?= e((string) ($customer['phone'] ?? '-')); ?></td>
                                <td><?= e((string) ($customer['area'] ?? '-')); ?></td>
                                <td><span class="portal-pill <?= e(billing_status_class((string) ($customer['status'] ?? ''))); ?>"><?= e((string) ($customer['status'] ?? '-')); ?></span></td>
                                <td><a class="portal-ghost-btn portal-inline-link" href="billing-cash-payment.php?subscriber_code=<?= rawurlencode((string) ($customer['subscriber_code'] ?? '')); ?>">SeÃ§</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
