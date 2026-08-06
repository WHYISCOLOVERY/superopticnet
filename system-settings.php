<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee(true);
$canEdit = app_portal_is_super_admin($user);
$flash = null;
$profiles = [
    'AZ' => ['label' => 'Azerbaycan', 'zones' => ['Asia/Baku']],
    'TR' => ['label' => 'Turkiye', 'zones' => ['Europe/Istanbul']],
    'GE' => ['label' => 'Gurcustan', 'zones' => ['Asia/Tbilisi']],
    'GB' => ['label' => 'UTC / beynelxalq', 'zones' => ['UTC', 'Europe/London']],
];
$envTimezoneLocked = is_string(getenv('APP_TIMEZONE')) && getenv('APP_TIMEZONE') !== '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'message' => 'Bu ayari yalniz Super Admin deyise biler.'];
    } elseif ($envTimezoneLocked) {
        $flash = ['type' => 'error', 'message' => 'APP_TIMEZONE server environment-de kilidlenib. Deyisiklik serverden edilmelidir.'];
    } else {
        $country = strtoupper(clean_text((string) ($_POST['app_country_code'] ?? 'AZ')));
        $timezone = clean_text((string) ($_POST['app_timezone'] ?? 'Asia/Baku'));
        $allowedZones = (array) ($profiles[$country]['zones'] ?? []);
        if (!in_array($timezone, $allowedZones, true)) {
            $flash = ['type' => 'error', 'message' => 'Olke ve saat qursagi secimi uygun deyil.'];
        } elseif (app_write_public_runtime_settings(['app_country_code' => $country, 'app_timezone' => $timezone])) {
            header('Location: system-settings.php?saved=1' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
            exit;
        } else {
            $flash = ['type' => 'error', 'message' => 'Sistem ayarlari saxlanmadi. Data qovlugu icazesini yoxlayin.'];
        }
    }
}

if (isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'message' => 'Olke ve saat qursagi yenilendi. Yeni emeliyyatlar bu saatla saxlanacaq.'];
}
$country = strtoupper((string) app_setting('app_country_code', 'AZ'));
$timezone = app_timezone();
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem ayarlari</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
<?php billing_render_header('Sistem ayarlari', 'system-health', $lang); ?>
<main class="portal-layout">
    <?php if ($flash !== null): ?><div class="portal-alert portal-alert-<?= e($flash['type']); ?>"><span><?= e($flash['message']); ?></span></div><?php endif; ?>
    <section class="portal-card portal-reveal">
        <div class="portal-card-head">
            <h1>Olke ve saat qursagi</h1>
            <small>Tarixler serverin qurasdirildigi olkeye uygun saxlanir. API acarlari ve parollar bu bolmede saxlanmir.</small>
        </div>
        <form method="post" class="portal-stacked-form portal-section-gap">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <div class="portal-form-row portal-grid-slim">
                <div><label for="countryCode">Olke</label><select id="countryCode" name="app_country_code" <?= !$canEdit || $envTimezoneLocked ? 'disabled' : ''; ?>><?php foreach ($profiles as $code => $profile): ?><option value="<?= e($code); ?>" <?= $country === $code ? 'selected' : ''; ?>><?= e((string) $profile['label']); ?></option><?php endforeach; ?></select></div>
                <div><label for="timezone">Saat qursagi</label><select id="timezone" name="app_timezone" <?= !$canEdit || $envTimezoneLocked ? 'disabled' : ''; ?>><?php foreach ($profiles as $code => $profile): ?><?php foreach ((array) $profile['zones'] as $zone): ?><option value="<?= e((string) $zone); ?>" data-country="<?= e($code); ?>" <?= $timezone === $zone ? 'selected' : ''; ?>><?= e((string) $zone); ?></option><?php endforeach; ?><?php endforeach; ?></select></div>
            </div>
            <div class="message-worker-row"><strong>Aktiv vaxt</strong><span class="portal-pill"><?= e(date('Y-m-d H:i:s')); ?> / <?= e(app_timezone_offset()); ?></span></div>
            <?php if ($envTimezoneLocked): ?><small>Bu serverde APP_TIMEZONE environment deyiseni ustunluk edir.</small><?php endif; ?>
            <?php if ($canEdit && !$envTimezoneLocked): ?><button class="portal-primary-btn" type="submit">Ayarlari saxla</button><?php endif; ?>
        </form>
    </section>
    <?php if ($canEdit): ?>
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <div><h2>API inteqrasiyalari</h2><small>Odenis providerleri ucun unikal public key ve birdefelik private secret yaradin.</small></div>
                <a class="portal-primary-btn portal-inline-link" href="api-integrations.php">API acarlarini idare et</a>
            </div>
        </section>
    <?php endif; ?>
</main>
<script>
(() => {
    const country = document.getElementById('countryCode');
    const timezone = document.getElementById('timezone');
    if (!country || !timezone) return;
    const sync = () => {
        const options = [...timezone.options];
        let selectedVisible = false;
        options.forEach((option) => {
            option.hidden = option.dataset.country !== country.value;
            if (!option.hidden && option.selected) selectedVisible = true;
        });
        if (!selectedVisible) {
            const first = options.find((option) => !option.hidden);
            if (first) first.selected = true;
        }
    };
    country.addEventListener('change', sync);
    sync();
})();
</script>
</body>
</html>
