<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/business-rules-lib.php';

$lang = app_lang();
$user = billing_require_employee(true);
$db = app_db();
$canEdit = app_portal_is_super_admin($user);
$flash = null;
$ruleCategoryLabels = ['ai' => 'AI / analiz', 'billing' => 'Billing', 'marketing' => 'Marketinq', 'general' => 'Ümumi'];
$profiles = [
    'AZ' => ['label' => 'Azerbaycan', 'zones' => ['Asia/Baku']],
    'TR' => ['label' => 'Turkiye', 'zones' => ['Europe/Istanbul']],
    'GE' => ['label' => 'Gurcustan', 'zones' => ['Asia/Tbilisi']],
    'GB' => ['label' => 'UTC / beynelxalq', 'zones' => ['UTC', 'Europe/London']],
];
$envTimezoneLocked = is_string(getenv('APP_TIMEZONE')) && getenv('APP_TIMEZONE') !== '';

$postAction = (string) ($_POST['action'] ?? 'save_system_settings');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'message' => 'Bu ayari yalniz Super Admin deyise biler.'];
    } elseif ($postAction === 'save_system_settings') {
        if ($envTimezoneLocked) {
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
    } elseif ($postAction === 'save_business_rule' && $db instanceof PDO) {
        $ruleResult = app_business_rule_set(
            $db,
            (string) ($_POST['rule_key'] ?? ''),
            (string) ($_POST['value_text'] ?? ''),
            $user,
            (string) ($_POST['reason'] ?? '')
        );
        if (!empty($ruleResult['ok'])) {
            header('Location: system-settings.php?rule_saved=1' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
            exit;
        }
        $ruleErrors = [
            'reason_required' => 'Deyisiklik sebebi mecburidir.',
            'rule_not_found' => 'Qayda tapilmadi.',
            'value_must_be_integer' => 'Deyer tam eded olmalidir.',
            'value_must_be_decimal' => 'Deyer eded olmalidir.',
            'value_must_be_boolean' => 'Deyer aciq/bagli olmalidir.',
            'value_below_minimum' => 'Deyer minimum heddin altindadir.',
            'value_above_maximum' => 'Deyer maksimum heddi asir.',
            'value_unchanged' => 'Deyer deyismeyib.',
        ];
        $flash = ['type' => 'error', 'message' => $ruleErrors[(string) ($ruleResult['reason'] ?? '')] ?? 'Qayda saxlanmadi.'];
    } elseif ($postAction === 'rollback_business_rule' && $db instanceof PDO) {
        $rollbackResult = app_business_rule_rollback(
            $db,
            (string) ($_POST['rule_key'] ?? ''),
            (int) ($_POST['history_id'] ?? 0),
            $user,
            (string) ($_POST['reason'] ?? '')
        );
        if (!empty($rollbackResult['ok'])) {
            header('Location: system-settings.php?rule_saved=1' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
            exit;
        }
        $flash = ['type' => 'error', 'message' => 'Qaydanin geri qaytarilmasi ugursuz oldu: ' . (string) ($rollbackResult['reason'] ?? 'namelum xeta')];
    }
}

if (isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'message' => 'Olke ve saat qursagi yenilendi. Yeni emeliyyatlar bu saatla saxlanacaq.'];
}
if (isset($_GET['rule_saved'])) {
    $flash = ['type' => 'success', 'message' => 'Biznes qaydasi yenilendi.'];
}
$businessRules = $db instanceof PDO ? app_business_rules_list($db) : [];
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
            <input type="hidden" name="action" value="save_system_settings">
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
    <?php if ($db instanceof PDO && $businessRules !== []): ?>
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <div><h2>Biznes qaydalari</h2><small>Bu deyerler evvelce kodda sabit rexem idi. Deyisiklik derhal tetbiq olunur, her deyisiklik sebeble qeyd olunur ve geri qaytarila biler.</small></div>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Qayda</th><th>Kateqoriya</th><th>Cari deyer</th><th>Son deyisiklik</th><th>Emeliyyat</th></tr></thead>
                    <tbody>
                    <?php foreach ($businessRules as $rule): ?>
                        <?php $ruleKey = (string) ($rule['rule_key'] ?? ''); $history = app_business_rule_history($db, $ruleKey, 10); ?>
                        <tr data-inventory-row>
                            <td><strong><?= e((string) ($rule['title'] ?? $ruleKey)); ?></strong><br><small><?= e((string) ($rule['description'] ?? '')); ?></small></td>
                            <td><?= e($ruleCategoryLabels[(string) ($rule['category'] ?? 'general')] ?? (string) ($rule['category'] ?? '-')); ?></td>
                            <td><span class="portal-pill"><?= e((string) ($rule['value_text'] ?? '-')); ?></span></td>
                            <td><?php if (!empty($rule['updated_by_name'])): ?><?= e((string) $rule['updated_by_name']); ?><br><small><?= e((string) ($rule['updated_at'] ?? '')); ?></small><?php else: ?>-<?php endif; ?></td>
                            <td>
                                <details class="inventory-inline-action">
                                    <summary class="portal-ghost-btn portal-inline-link">Deyis</summary>
                                    <form method="post" action="system-settings.php" class="portal-stacked-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="save_business_rule">
                                        <input type="hidden" name="rule_key" value="<?= e($ruleKey); ?>">
                                        <div class="portal-form-row">
                                            <label>Yeni deyer<?php if ($rule['min_value'] !== null || $rule['max_value'] !== null): ?> (<?= e((string) ($rule['min_value'] ?? '')); ?>-<?= e((string) ($rule['max_value'] ?? '')); ?>)<?php endif; ?></label>
                                            <input type="text" name="value_text" maxlength="255" required value="<?= e((string) ($rule['value_text'] ?? '')); ?>">
                                        </div>
                                        <div class="portal-form-row"><label>Sebeb</label><input type="text" name="reason" maxlength="255" required placeholder="Bu deyisikligin sebebi"></div>
                                        <button class="portal-primary-btn" type="submit">Saxla</button>
                                    </form>
                                </details>
                                <?php if ($history !== []): ?>
                                    <details class="inventory-inline-action">
                                        <summary class="portal-ghost-btn portal-inline-link">Tarixce (<?= e((string) count($history)); ?>)</summary>
                                        <table class="portal-table">
                                            <thead><tr><th>Tarix</th><th>Kim</th><th>Kohne</th><th>Yeni</th><th>Sebeb</th><th></th></tr></thead>
                                            <tbody>
                                            <?php foreach ($history as $entry): ?>
                                                <tr>
                                                    <td><?= e((string) ($entry['changed_at'] ?? '')); ?></td>
                                                    <td><?= e((string) ($entry['changed_by_name'] ?? '-')); ?></td>
                                                    <td><?= e((string) ($entry['previous_value_text'] ?? '-')); ?></td>
                                                    <td><?= e((string) ($entry['new_value_text'] ?? '-')); ?><?php if (!empty($entry['is_rollback'])): ?> <span class="portal-pill">geri qaytarma</span><?php endif; ?></td>
                                                    <td><?= e((string) ($entry['reason'] ?? '-')); ?></td>
                                                    <td>
                                                        <?php if ((string) ($entry['previous_value_text'] ?? '') !== (string) ($rule['value_text'] ?? '') && (string) ($entry['previous_value_text'] ?? '') !== ''): ?>
                                                        <details class="inventory-inline-action">
                                                            <summary class="portal-ghost-btn portal-inline-link">Bu deyisikliyi legv et (&larr; <?= e((string) ($entry['previous_value_text'] ?? '')); ?>)</summary>
                                                            <form method="post" action="system-settings.php" class="portal-stacked-form">
                                                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="rollback_business_rule">
                                                                <input type="hidden" name="rule_key" value="<?= e($ruleKey); ?>">
                                                                <input type="hidden" name="history_id" value="<?= e((string) ($entry['id'] ?? 0)); ?>">
                                                                <div class="portal-form-row"><label>Sebeb</label><input type="text" name="reason" maxlength="255" required placeholder="Geri qaytarma sebebi"></div>
                                                                <button class="portal-primary-btn" type="submit">Tesdiqle</button>
                                                            </form>
                                                        </details>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
