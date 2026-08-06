<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/billing-customer-identity-lib.php';
require_once __DIR__ . '/portal-security-lib.php';

$lang = app_lang();
$user = billing_require_employee();
$db = app_db();
app_billing_ensure_identity_schema($db);

$status = clean_text((string) ($_GET['status'] ?? ''));
$flashMap = [
    'saved' => ['type' => 'success', 'message' => 'Musteri billing duzelisi saxlandi ve RADIUS sync yenilendi.'],
    'save_failed' => ['type' => 'error', 'message' => 'Duzelis saxlanmadi. Tarif, tarix ve billing payload melumatlarini yoxla.'],
    'duplicate_fin' => ['type' => 'error', 'message' => 'FIN kod basqa aktiv musteriye aiddir. FIN sahesini yoxlayin.'],
    'identity_required' => ['type' => 'error', 'message' => 'FIN, sexsiyyet vesiqesi, telefon ve unvan sahesi mutleqdir.'],
    'reason_required' => ['type' => 'error', 'message' => 'Tarif, gun, bitme tarixi ve ya status deyisirse sebeb mutleq yazilmalidir.'],
    'customer_not_found' => ['type' => 'error', 'message' => 'Musteri tapilmadi.'],
    'csrf_failed' => ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'],
    'password_reset' => ['type' => 'success', 'message' => 'Musteri ucun muveqqeti portal sifresi yaradildi. Sifre asagida yalniz bir defe gosterilir.'],
    'password_reset_failed' => ['type' => 'error', 'message' => 'Musteri sifresi yenilenmedi. Sebebi ve icazenizi yoxlayin.'],
    'password_reset_reason_required' => ['type' => 'error', 'message' => 'Sifre reseti ucun musteri muracietinin sebebi mutleq yazilmalidir.'],
];
$flash = $flashMap[$status] ?? null;

$accountId = (int) ($_GET['id'] ?? $_POST['account_id'] ?? 0);
$subscriberCode = clean_text((string) ($_GET['code'] ?? ''));
$account = $accountId > 0 ? app_db_account_by_id($accountId) : ($subscriberCode !== '' ? app_db_account_by_subscriber_code($subscriberCode) : null);

if (!$db instanceof PDO || $account === null || (string) ($account['role'] ?? '') !== 'customer') {
    header('Location: billing-customers.php?status=customer_not_found' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
    exit;
}
$temporaryPassword = is_array($_SESSION['billing_customer_temporary_password'] ?? null)
    ? $_SESSION['billing_customer_temporary_password']
    : null;
unset($_SESSION['billing_customer_temporary_password']);
if (is_array($temporaryPassword) && (int) ($temporaryPassword['account_id'] ?? 0) !== (int) $account['id']) {
    $temporaryPassword = null;
}
$oldInput=is_array($_SESSION['billing_customer_edit_old'][$accountId]??null)?$_SESSION['billing_customer_edit_old'][$accountId]:[];unset($_SESSION['billing_customer_edit_old'][$accountId]);

$payload = app_db_account_payload($account);
$profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
$billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
$services = is_array($payload['internet_services'] ?? null) ? array_values(array_filter($payload['internet_services'], 'is_array')) : [];
$primaryService = $services[0] ?? [];
foreach ($services as $service) {
    if (strcasecmp((string) ($service['username'] ?? ''), (string) ($billing['pppoe_username'] ?? '')) === 0) {
        $primaryService = $service;
        break;
    }
}

$serviceType = strtolower((string) ($primaryService['service_type'] ?? $billing['service_type'] ?? 'pppoe'));
$serviceType = $serviceType === 'ipoe' ? 'ipoe' : 'pppoe';
$currentTariff = app_billing_account_tariff($db, $account, $billing);
$currentTariffId = (int) ($currentTariff['id'] ?? 0);
$tariffStatement = $db->prepare(
    'SELECT *
     FROM billing_tariffs
     WHERE status = "active"
       AND service_type = :service_type
     ORDER BY download_mbps, monthly_fee, name'
);
$tariffStatement->execute(['service_type' => $serviceType]);
$tariffs = $tariffStatement->fetchAll() ?: [];

$stableDueDay = app_billing_account_stable_due_day($account, $billing, $profile);
$billingDays = app_billing_account_default_days($account, $billing, $currentTariff ?: null);
$currentExpiresAt = trim((string) ($billing['expires_at'] ?? ($primaryService['expires_at'] ?? '')));
$expiresInput = $currentExpiresAt !== '' && strtotime($currentExpiresAt) !== false
    ? date('Y-m-d\TH:i', strtotime($currentExpiresAt))
    : '';
$currentTariffName = (string) ($currentTariff['name'] ?? $billing['tariff'] ?? $account['plan'] ?? '-');
$currentTariffFee = app_billing_parse_money_amount($currentTariff['monthly_fee'] ?? ($billing['monthly_total'] ?? $billing['monthly_fee'] ?? 0));
$currentTariffSpeed = trim((string) ($currentTariff['download_mbps'] ?? '')) !== '' ? (string) ($currentTariff['download_mbps'] ?? '') . ' Mb/s' : '-';
$anchorPreview = app_billing_resolve_next_expires_at(
    $billingDays,
    $stableDueDay,
    $currentExpiresAt !== '' ? $currentExpiresAt : null,
    time()
);
$stablePresets = [1, 5, 10, 15, 20, 25, 30];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        header('Location: billing-customer-edit.php?id=' . rawurlencode((string) ($account['id'] ?? 0)) . '&status=csrf_failed' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
        exit;
    }

    $postedAction = clean_text((string) ($_POST['action'] ?? ''));
    if ($postedAction === 'operator_reset_customer_password') {
        $resetReason = clean_text((string) ($_POST['reset_reason'] ?? ''));
        if ($resetReason === '') {
            header('Location: billing-customer-edit.php?id=' . rawurlencode((string) $account['id']) . '&status=password_reset_reason_required');
            exit;
        }
        $reset = app_portal_privileged_password_reset($account, $user, $resetReason, 'customer_operator');
        if (!empty($reset['ok'])) {
            $_SESSION['billing_customer_temporary_password'] = [
                'account_id' => (int) $account['id'],
                'login' => (string) ($reset['login'] ?? $account['login'] ?? ''),
                'password' => (string) ($reset['password'] ?? ''),
            ];
        }
        header('Location: billing-customer-edit.php?id=' . rawurlencode((string) $account['id']) . '&status=' . (!empty($reset['ok']) ? 'password_reset' : 'password_reset_failed'));
        exit;
    }

    if ($postedAction !== 'save_billing_customer_edit') {
        header('Location: billing-customer-edit.php?id=' . rawurlencode((string) $account['id']) . '&status=save_failed');
        exit;
    }

    $tariffId = (int) ($_POST['tariff_id'] ?? 0);
    $lookupTariff = null;
    foreach ($tariffs as $tariff) {
        if ((int) ($tariff['id'] ?? 0) === $tariffId) {
            $lookupTariff = $tariff;
            break;
        }
    }

    if (!is_array($lookupTariff)) {
        header('Location: billing-customer-edit.php?id=' . rawurlencode((string) ($account['id'] ?? 0)) . '&status=save_failed' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
        exit;
    }

    $fullName = clean_text((string) ($_POST['full_name'] ?? ''));
    $contractNo = clean_text((string) ($_POST['contract_no'] ?? ''));
    $phone = clean_text((string) ($_POST['phone'] ?? ''));
    $finCode = clean_text((string) ($_POST['fin_code'] ?? ''));
    $documentNo = clean_text((string) ($_POST['document_no'] ?? ''));
    $email = clean_text((string) ($_POST['email'] ?? ''));
    $category = clean_text((string) ($_POST['category'] ?? ''));
    $area = clean_text((string) ($_POST['area'] ?? ''));
    $address = clean_text((string) ($_POST['address'] ?? ''));
    $changeReason = clean_text((string) ($_POST['change_reason'] ?? ''));
    $identityValidation = app_billing_validate_customer_identity($db, $_POST, (int)($account['id']??0));
    if (empty($identityValidation['ok'])) {
        $_SESSION['billing_customer_edit_old'][(int)$account['id']]=$_POST;
        $identityStatus=($identityValidation['error']??'')==='duplicate_fin'?'duplicate_fin':'identity_required';
        header('Location: billing-customer-edit.php?id=' . rawurlencode((string) ($account['id'] ?? 0)) . '&status='.$identityStatus . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
        exit;
    }
    $statusText = clean_text((string) ($_POST['status'] ?? 'Aktiv'));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $postedBillingDays = max(0, (int) ($_POST['billing_days'] ?? 0));
    $resolvedBillingDays = $postedBillingDays > 0 ? max(1, min(366, $postedBillingDays)) : app_billing_tariff_default_days($lookupTariff);
    $postedStableDueDay = max(0, (int) ($_POST['stable_due_day'] ?? 0));
    $resolvedStableDueDay = $postedStableDueDay >= 1 && $postedStableDueDay <= 31 ? $postedStableDueDay : 0;
    $postedExpiresAt = trim((string) ($_POST['expires_at'] ?? ''));
    $resolvedExpiresAt = $currentExpiresAt;

    if ($postedExpiresAt !== '') {
        $resolvedExpiresAt = date('Y-m-d H:i:s', strtotime($postedExpiresAt) ?: time());
    } elseif ($resolvedExpiresAt === '') {
        $resolvedExpiresAt = app_billing_resolve_next_expires_at($resolvedBillingDays, $resolvedStableDueDay, null, time());
    }

    $updatedProfile = $profile;
    $updatedProfile['full_name'] = $fullName;
    $updatedProfile['subscriber_code'] = (string) ($account['subscriber_code'] ?? '');
    $updatedProfile['contract_no'] = $contractNo !== '' ? $contractNo : (string) ($account['subscriber_code'] ?? '');
    $updatedProfile['phone'] = $phone;
    $updatedProfile['fin_code'] = (string)($identityValidation['data']['fin_code']??$finCode);
    $updatedProfile['document_no'] = (string)($identityValidation['data']['document_no']??$documentNo);
    $updatedProfile['email'] = $email;
    $updatedProfile['category'] = $category;
    $updatedProfile['area'] = $area;
    $updatedProfile['address'] = $address;
    $updatedProfile['plan'] = (string) ($lookupTariff['name'] ?? '');
    $updatedProfile['status'] = $statusText !== '' ? $statusText : ($isActive === 1 ? 'Aktiv' : 'Passiv');
    if ($resolvedStableDueDay > 0) {
        $updatedProfile['stable_due_day'] = $resolvedStableDueDay;
    } else {
        unset($updatedProfile['stable_due_day'], $updatedProfile['stable_day_of_month'], $updatedProfile['billing_anchor_day'], $updatedProfile['renewal_day']);
    }

    $updatedBilling = $billing;
    $updatedBilling['service_type'] = $serviceType;
    $updatedBilling['tariff'] = (string) ($lookupTariff['name'] ?? '');
    $updatedBilling['default_days'] = $resolvedBillingDays;
    $updatedBilling['billing_days'] = $resolvedBillingDays;
    $updatedBilling['expires_at'] = $resolvedExpiresAt;
    $updatedBilling['status'] = $statusText !== '' ? $statusText : ($isActive === 1 ? 'Aktiv' : 'Passiv');
    $updatedBilling['radius_status'] = $isActive === 1 ? 'Aktiv' : 'Passiv';
    $updatedBilling['days_left'] = app_billing_days_left($resolvedExpiresAt);
    $updatedBilling['remaining_days_raw'] = app_billing_remaining_days_text($resolvedExpiresAt);
    if ($resolvedStableDueDay > 0) {
        $updatedBilling['stable_due_day'] = $resolvedStableDueDay;
    } else {
        unset($updatedBilling['stable_due_day'], $updatedBilling['stable_day_of_month'], $updatedBilling['billing_anchor_day'], $updatedBilling['renewal_day']);
    }

    if ($services === []) {
        $services[] = [
            'service_type' => $serviceType,
            'username' => (string) ($billing['pppoe_username'] ?? $account['subscriber_code'] ?? $account['login'] ?? ''),
            'password' => (string) ($billing['pppoe_password'] ?? ''),
        ];
    }

    $primaryIndex = 0;
    foreach ($services as $index => $service) {
        if (strcasecmp((string) ($service['username'] ?? ''), (string) ($billing['pppoe_username'] ?? '')) === 0) {
            $primaryIndex = $index;
            break;
        }
    }

    $services[$primaryIndex]['service_type'] = $serviceType;
    $services[$primaryIndex]['tariff'] = (string) ($lookupTariff['name'] ?? '');
    $services[$primaryIndex]['expires_at'] = $resolvedExpiresAt;
    $services[$primaryIndex]['default_days'] = $resolvedBillingDays;
    $services[$primaryIndex]['status'] = $isActive === 1 ? 'active' : 'disabled';
    if ($resolvedStableDueDay > 0) {
        $services[$primaryIndex]['stable_due_day'] = $resolvedStableDueDay;
    } else {
        unset($services[$primaryIndex]['stable_due_day']);
    }

    $updatedPayload = $payload;
    $updatedPayload['profile'] = $updatedProfile;
    $updatedPayload['billing'] = $updatedBilling;
    $updatedPayload['internet_services'] = $services;
    $updatedPayload = app_billing_refresh_payload_totals($account, $updatedPayload, $lookupTariff);
    $sensitiveBefore = [
        'account' => [
            'plan' => (string) ($account['plan'] ?? ''),
            'status' => (string) ($account['status'] ?? ''),
            'is_active' => (int) ($account['is_active'] ?? 0),
        ],
        'profile' => $profile,
        'billing' => $billing,
        'service' => $primaryService,
    ];
    $sensitiveAfter = [
        'account' => [
            'plan' => (string) ($lookupTariff['name'] ?? ''),
            'status' => $statusText !== '' ? $statusText : ($isActive === 1 ? 'Aktiv' : 'Passiv'),
            'is_active' => $isActive,
        ],
        'profile' => $updatedProfile,
        'billing' => $updatedBilling,
        'service' => $services[$primaryIndex] ?? [],
    ];
    $sensitiveValidation = app_billing_validate_sensitive_change($sensitiveBefore, $sensitiveAfter, $changeReason);
    if (empty($sensitiveValidation['ok'])) {
        $_SESSION['billing_customer_edit_old'][(int)$account['id']] = $_POST;
        header('Location: billing-customer-edit.php?id=' . rawurlencode((string) ($account['id'] ?? 0)) . '&status=reason_required' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
        exit;
    }

    $saveResult = app_billing_upsert_customer_with_identity($db, [
        'id' => (int) ($account['id'] ?? 0),
        'login' => (string) ($account['login'] ?? ''),
        'password' => '',
        'role' => 'customer',
        'account_type' => 'customer',
        'employee_role' => '',
        'full_name' => $fullName,
        'subscriber_code' => (string) ($account['subscriber_code'] ?? ''),
        'contract_no' => $contractNo,
        'email' => $email,
        'phone' => $phone,
        'category' => $category,
        'area' => $area,
        'address' => $address,
        'plan' => (string) ($lookupTariff['name'] ?? ''),
        'status' => $statusText !== '' ? $statusText : ($isActive === 1 ? 'Aktiv' : 'Passiv'),
        'payload' => $updatedPayload,
        'is_active' => $isActive,
        'sensitive_change_audit' => [
            'before' => $sensitiveBefore,
            'after' => $sensitiveAfter,
            'reason' => $changeReason,
        ],
    ], $_POST, $user);
    $updatedAccount = !empty($saveResult['ok']) && is_array($saveResult['account'] ?? null) ? $saveResult['account'] : null;
    $synced = $updatedAccount !== null && !empty($saveResult['radius_synced']);

    header('Location: billing-customer-edit.php?id=' . rawurlencode((string) ($account['id'] ?? 0)) . '&status=' . ($synced ? 'saved' : 'save_failed') . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
    exit;
}

$areas = app_service_areas();
$profileHref = 'billing-customer-profile.php?id=' . rawurlencode((string) ($account['id'] ?? 0)) . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : '');
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Musteri billing duzelisi</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .billing-edit-shell{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:24px}
        .billing-edit-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .billing-edit-form-grid .wide{grid-column:1/-1}
        .billing-edit-aside .portal-profile-list div{background:#fff}
        .billing-edit-plan-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:14px 16px;border-radius:18px;background:rgba(248,250,252,.96);border:1px solid rgba(148,163,184,.2)}
        .billing-edit-plan-summary div{display:grid;gap:4px}
        .billing-edit-plan-summary span,.billing-edit-anchor-preview span,.billing-edit-stable-presets span{font-size:.78rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
        .billing-edit-plan-summary strong,.billing-edit-anchor-preview strong{color:#10233f}
        .billing-edit-anchor-preview{display:grid;grid-template-columns:1.2fr .8fr;gap:12px;padding:14px 16px;border-radius:18px;background:linear-gradient(135deg,rgba(11,123,135,.08),rgba(249,115,22,.08));border:1px solid rgba(11,123,135,.16)}
        .billing-edit-anchor-preview p{margin:0;color:#475569;font-size:.9rem;line-height:1.45}
        .billing-edit-stable-presets{display:grid;gap:10px}
        .billing-edit-stable-preset-row{display:flex;flex-wrap:wrap;gap:8px}
        .billing-edit-stable-preset{border:1px solid rgba(148,163,184,.24);background:#fff;color:#10233f;border-radius:999px;padding:8px 12px;font-weight:800;cursor:pointer}
        .billing-edit-stable-preset.is-clear{color:#64748b}
        .billing-edit-helper{display:grid;gap:6px;font-size:.85rem;color:#64748b}
        .billing-edit-helper strong{color:#10233f}
        @media (max-width:980px){.billing-edit-shell{grid-template-columns:1fr}.billing-edit-form-grid{grid-template-columns:1fr}}
        @media (max-width:900px){.billing-edit-plan-summary,.billing-edit-anchor-preview{grid-template-columns:1fr 1fr}}
        @media (max-width:720px){.billing-edit-plan-summary,.billing-edit-anchor-preview{grid-template-columns:1fr}}
    </style>
</head>
<body class="portal-body">
<?php billing_render_header('Musteri billing duzelisi', 'customers', $lang); ?>
<main class="portal-layout">
    <?php if ($flash !== null): ?>
        <div class="portal-alert portal-alert-<?= e($flash['type']); ?>" data-autohide-alert>
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?= e($flash['message']); ?></span>
        </div>
    <?php endif; ?>

    <section class="portal-hero portal-reveal">
        <div>
            <span class="portal-kicker">Billing edit</span>
            <h1>Musteri billing duzelisi</h1>
            <p>Bu sehife yalniz billing/customer edit ucundur. Tarif paketlerden secilir, stabil gun saxlanir ve novbeti bitme anchor-u burada idare olunur.</p>
        </div>
        <div class="portal-status-card">
            <span>Muqavile / kod</span>
            <strong><?= e((string) ($account['subscriber_code'] ?? '-')); ?></strong>
            <small><?= e((string) ($account['full_name'] ?? '-')); ?></small>
        </div>
    </section>

    <section class="billing-edit-shell portal-reveal">
        <article class="portal-card">
            <div class="portal-card-head">
                <h2>Esas billing melumatlari</h2>
                <small>Tarif paketlerden secilir. Stabil gun verilirse odenis istenilen tarixde gelse de novbeti bitme hemin ayin eyni gunune anchor olunur.</small>
            </div>
            <form method="post" class="portal-stacked-form">
                <input type="hidden" name="action" value="save_billing_customer_edit">
                <input type="hidden" name="account_id" value="<?= e((string) ($account['id'] ?? 0)); ?>">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <div class="billing-edit-form-grid">
                    <div class="portal-form-row">
                        <label>Musteri kodu</label>
                        <input type="text" value="<?= e((string) ($account['subscriber_code'] ?? '')); ?>" disabled>
                    </div>
                    <div class="portal-form-row">
                        <label>Esas xidmet tipi</label>
                        <input type="text" value="<?= e(strtoupper($serviceType)); ?>" disabled>
                    </div>
                    <div class="portal-form-row">
                        <label>Ad soyad / sirket</label>
                        <input type="text" name="full_name" value="<?= e((string) ($oldInput['full_name'] ?? $account['full_name'] ?? $profile['full_name'] ?? '')); ?>" required>
                    </div>
                    <div class="portal-form-row">
                        <label>Muqavile nomresi</label>
                        <input type="text" name="contract_no" value="<?= e((string) ($oldInput['contract_no'] ?? $account['contract_no'] ?? $profile['contract_no'] ?? '')); ?>">
                    </div>
                    <div class="portal-form-row">
                        <label>Telefon</label>
                        <input type="text" name="phone" value="<?= e((string) ($oldInput['phone'] ?? $account['phone'] ?? $profile['phone'] ?? '')); ?>" required>
                    </div>
                    <div class="portal-form-row"><label>FIN kod</label><input type="text" name="fin_code" maxlength="32" value="<?=e((string)($oldInput['fin_code']??$account['fin_code']??$profile['fin_code']??''));?>" required></div>
                    <div class="portal-form-row"><label>Sexsiyyet vesiqesi</label><input type="text" name="document_no" maxlength="64" value="<?=e((string)($oldInput['document_no']??$account['document_no']??$profile['document_no']??''));?>" required></div>
                    <div class="portal-form-row">
                        <label>Email</label>
                        <input type="email" name="email" value="<?= e((string) ($account['email'] ?? $profile['email'] ?? '')); ?>">
                    </div>
                    <div class="portal-form-row">
                        <label>Kateqoriya</label>
                        <input type="text" name="category" value="<?= e((string) ($account['category'] ?? $profile['category'] ?? '')); ?>">
                    </div>
                    <div class="portal-form-row">
                        <label>Erazi</label>
                        <select name="area">
                            <option value="">Secin</option>
                            <?php foreach ($areas as $area): ?>
                                <option value="<?= e($area); ?>" <?= ((string) ($account['area'] ?? $profile['area'] ?? '')) === $area ? 'selected' : ''; ?>><?= e($area); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row wide">
                        <label>Unvan</label>
                        <input type="text" name="address" value="<?= e((string) ($oldInput['address'] ?? $account['address'] ?? $profile['address'] ?? '')); ?>" required>
                    </div>
                    <div class="portal-form-row wide">
                        <label>Tarif / plan</label>
                        <select name="tariff_id" id="billing-tariff-select" required>
                            <?php foreach ($tariffs as $tariff): ?>
                                <?php $tariffId = (int) ($tariff['id'] ?? 0); ?>
                                <option
                                    value="<?= e((string) $tariffId); ?>"
                                    data-name="<?= e((string) ($tariff['name'] ?? '')); ?>"
                                    data-fee="<?= e((string) app_billing_parse_money_amount($tariff['monthly_fee'] ?? 0)); ?>"
                                    data-days="<?= e((string) app_billing_tariff_default_days($tariff)); ?>"
                                    data-speed="<?= e(trim((string) ($tariff['download_mbps'] ?? '')) !== '' ? (string) ($tariff['download_mbps'] ?? '') . ' Mb/s' : '-'); ?>"
                                    <?= $tariffId === $currentTariffId ? 'selected' : ''; ?>
                                >
                                    <?= e((string) ($tariff['name'] ?? '')); ?> / <?= e((string) ($tariff['monthly_fee'] ?? '0')); ?> AZN / <?= e((string) app_billing_tariff_default_days($tariff)); ?> gun
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wide billing-edit-plan-summary" data-billing-edit-summary>
                        <div>
                            <span>Tarif secimi ve aylıq anchor</span>
                            <strong data-plan-name><?= e($currentTariffName); ?></strong>
                        </div>
                        <div>
                            <span>Ayliq qiymet</span>
                            <strong data-plan-fee><?= e(number_format($currentTariffFee, 2)); ?> AZN</strong>
                        </div>
                        <div>
                            <span>Suret</span>
                            <strong data-plan-speed><?= e($currentTariffSpeed); ?></strong>
                        </div>
                        <div>
                            <span>Tarif default gun</span>
                            <strong data-plan-days><?= e((string) $billingDays); ?> gun</strong>
                        </div>
                    </div>
                    <div class="portal-form-row">
                        <label>Billing gun sayi</label>
                        <input type="number" id="billing-days-input" name="billing_days" min="1" max="366" value="<?= e((string) $billingDays); ?>">
                        <div class="billing-edit-helper">
                            <span>Bu xana bos qalarsa sistem secilen tarifin default gununu saxlayir.</span>
                        </div>
                    </div>
                    <div class="portal-form-row">
                        <label>Stabil gun (1-31)</label>
                        <input type="number" id="stable-due-day-input" name="stable_due_day" min="1" max="31" value="<?= e($stableDueDay > 0 ? (string) $stableDueDay : ''); ?>" placeholder="mes: 1">
                    </div>
                    <div class="wide billing-edit-stable-presets">
                        <span>Stabil gun presetleri</span>
                        <div class="billing-edit-stable-preset-row">
                            <?php foreach ($stablePresets as $preset): ?>
                                <button type="button" class="billing-edit-stable-preset" data-stable-preset="<?= e((string) $preset); ?>"><?= e((string) $preset); ?></button>
                            <?php endforeach; ?>
                            <button type="button" class="billing-edit-stable-preset is-clear" data-stable-clear="1">Stabil gunu sil</button>
                        </div>
                    </div>
                    <div class="portal-form-row">
                        <label>Bitme tarixi</label>
                        <input type="datetime-local" id="expires-at-input" name="expires_at" value="<?= e($expiresInput); ?>">
                    </div>
                    <div class="portal-form-row">
                        <label>Status</label>
                        <?php $currentStatus = (string) ($account['status'] ?? $billing['status'] ?? 'Aktiv'); ?>
                        <select name="status">
                            <option value="Aktiv" <?= $currentStatus === 'Aktiv' ? 'selected' : ''; ?>>Aktiv</option>
                            <option value="Passiv" <?= $currentStatus === 'Passiv' ? 'selected' : ''; ?>>Passiv</option>
                            <option value="Deaktiv" <?= $currentStatus === 'Deaktiv' ? 'selected' : ''; ?>>Deaktiv</option>
                        </select>
                    </div>
                    <div class="portal-form-row wide">
                        <label class="admin-users-inline">
                            <input type="checkbox" name="is_active" value="1" <?= ((int) ($account['is_active'] ?? 1) === 1) ? 'checked' : ''; ?>>
                            <span>Hesab aktivdir</span>
                        </label>
                    </div>
                    <div class="portal-form-row wide">
                        <label>Deyisiklik sebebi</label>
                        <textarea name="change_reason" rows="2" placeholder="Tarif, gun, bitme tarixi ve ya status deyisikliyi ucun sebeb"><?= e((string) ($oldInput['change_reason'] ?? '')); ?></textarea>
                    </div>
                    <div class="wide billing-edit-anchor-preview" data-billing-edit-anchor-preview>
                        <div>
                            <span>Novbeti anchor preview</span>
                            <strong data-anchor-date><?= e(billing_datetime($anchorPreview)); ?></strong>
                            <p data-anchor-note><?= e($stableDueDay > 0
                                ? 'Stabil gun aktivdir. Novbeti bitme stabil gun uzre qurulur.'
                                : 'Stabil gun bosdursa bitme gun sayi uzre hesablanir.'); ?></p>
                        </div>
                        <div>
                            <span>Cari istifade qaydasi</span>
                            <strong data-anchor-mode><?= e($stableDueDay > 0 ? ('Her ay ' . $stableDueDay . '-i') : ($billingDays . ' gunluk period')); ?></strong>
                            <p>Manual bitme tarixi yazilsin deyirsense, yuxaridaki tarix xanasini doldur. Eks halda sistem avtomatik hesablayir.</p>
                        </div>
                    </div>
                </div>
                <div class="portal-inline-inputs">
                    <button type="submit" class="portal-primary-btn">Duzelisi saxla</button>
                    <a class="portal-ghost-btn portal-inline-link" href="<?= e($profileHref); ?>">Profile qayit</a>
                    <a class="portal-ghost-btn portal-inline-link" href="billing-customers.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Siyahiya qayit</a>
                </div>
            </form>
        </article>

        <aside class="portal-stack billing-edit-aside">
            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Portal sifresini reset et</h2>
                    <small>Yalniz musteri operatora muraciet etdikde istifade edin. Emeliyyat jurnalda saxlanilir.</small>
                </div>
                <?php if (is_array($temporaryPassword)): ?>
                    <div class="portal-alert portal-alert-success">
                        <span>
                            Login: <strong><?= e((string) ($temporaryPassword['login'] ?? '')); ?></strong><br>
                            Muveqqeti sifre: <strong><?= e((string) ($temporaryPassword['password'] ?? '')); ?></strong><br>
                            Bu sifre sehife baglandiqdan sonra yeniden gosterilmeyecek.
                        </span>
                    </div>
                <?php endif; ?>
                <form method="post" class="portal-stacked-form" autocomplete="off">
                    <input type="hidden" name="action" value="operator_reset_customer_password">
                    <input type="hidden" name="account_id" value="<?= e((string) $account['id']); ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <div class="portal-form-row">
                        <label for="customerPasswordResetReason">Reset sebebi</label>
                        <textarea id="customerPasswordResetReason" name="reset_reason" rows="3" required placeholder="Meselen: Musteri zeng ederek sexsiyyet melumatlarini tesdiqledi"></textarea>
                    </div>
                    <button type="submit" class="portal-primary-btn">Muveqqeti sifre yarat</button>
                </form>
            </article>
            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Cari billing xulasesi</h2>
                    <small>Save etmeden evvel cari anchor ve tarif veziyyeti.</small>
                </div>
                <div class="portal-profile-list">
                    <div><span>Esas username</span><strong><?= e((string) ($primaryService['username'] ?? $billing['pppoe_username'] ?? '-')); ?></strong></div>
                    <div><span>Cari tarif</span><strong><?= e((string) ($billing['tariff'] ?? $account['plan'] ?? '-')); ?></strong></div>
                    <div><span>Ayliq toplam</span><strong><?= e((string) ($billing['monthly_total'] ?? $billing['monthly_fee'] ?? '-')); ?></strong></div>
                    <div><span>Bitme</span><strong><?= e($currentExpiresAt !== '' ? billing_datetime($currentExpiresAt) : '-'); ?></strong></div>
                    <div><span>Qalan gun</span><strong><?= e((string) (($billing['remaining_days_raw'] ?? '') ?: app_billing_remaining_days_text($currentExpiresAt))); ?></strong></div>
                    <div><span>Stabil gun</span><strong><?= e($stableDueDay > 0 ? (string) $stableDueDay : '-'); ?></strong></div>
                </div>
            </article>
            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Qayda</h2>
                    <small>Stabil gun behavior</small>
                </div>
                <div class="portal-note-card">
                    <p>Meselen stabil gun <strong>1</strong> olsa, musteri ayin 1-i, 7-si ve ya 20-si odenis etse bele, aktivlesme novbeti ayin <strong>1</strong>-ne anchor olunacaq. Cari bitme tarixi indi deyismelidirse onu ayrica yuxaridan yazmaq olar.</p>
                </div>
            </article>
        </aside>
    </section>
</main>
<script src="<?= e(app_asset('script/portal.js')); ?>"></script>
<script>
(() => {
  const tariffSelect = document.getElementById('billing-tariff-select');
  const billingDaysInput = document.getElementById('billing-days-input');
  const stableInput = document.getElementById('stable-due-day-input');
  const expiresInput = document.getElementById('expires-at-input');
  if (!tariffSelect || !billingDaysInput || !stableInput) return;

  const nameNode = document.querySelector('[data-plan-name]');
  const feeNode = document.querySelector('[data-plan-fee]');
  const speedNode = document.querySelector('[data-plan-speed]');
  const daysNode = document.querySelector('[data-plan-days]');
  const anchorDateNode = document.querySelector('[data-anchor-date]');
  const anchorNoteNode = document.querySelector('[data-anchor-note]');
  const anchorModeNode = document.querySelector('[data-anchor-mode]');

  const pad = (value) => String(value).padStart(2, '0');
  const formatDate = (date) => `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
  const parseDateInput = (value) => {
    if (!value) return new Date();
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? new Date() : parsed;
  };
  const lastDayOfMonth = (year, monthIndex) => new Date(year, monthIndex + 1, 0).getDate();
  const computeStablePreview = (stableDay, baseDate) => {
    const preview = new Date(baseDate.getTime());
    const desiredDay = Math.max(1, Math.min(31, stableDay));
    preview.setDate(Math.min(desiredDay, lastDayOfMonth(preview.getFullYear(), preview.getMonth())));
    if (preview.getTime() <= baseDate.getTime()) {
      preview.setMonth(preview.getMonth() + 1);
      preview.setDate(Math.min(desiredDay, lastDayOfMonth(preview.getFullYear(), preview.getMonth())));
    }
    return preview;
  };

  function computePreview() {
    const option = tariffSelect.options[tariffSelect.selectedIndex];
    const defaultDays = Number(option?.dataset.days || 30);
    const effectiveDays = Math.max(1, Number(billingDaysInput.value || defaultDays || 30));
    const stableDay = Number(stableInput.value || 0);
    const baseDate = parseDateInput(expiresInput?.value || '');

    if (nameNode) nameNode.textContent = option?.dataset.name || '-';
    if (feeNode) feeNode.textContent = `${Number(option?.dataset.fee || 0).toFixed(2)} AZN`;
    if (speedNode) speedNode.textContent = option?.dataset.speed || '-';
    if (daysNode) daysNode.textContent = `${defaultDays} gun`;

    let previewDate = new Date(baseDate.getTime());
    let previewNote = `${effectiveDays} gunluk billing period aktivdir.`;
    let previewMode = `${effectiveDays} gunluk period`;

    if (stableDay >= 1 && stableDay <= 31) {
        previewDate = computeStablePreview(stableDay, baseDate);
        previewNote = `Stabil gun ${stableDay} secilib. Odenis gec gelse bele novbeti bitme bu gun uzre anchor olunur.`;
        previewMode = `Her ay ${stableDay}-i`;
    } else {
        previewDate.setDate(previewDate.getDate() + effectiveDays);
    }

    if (anchorDateNode) anchorDateNode.textContent = formatDate(previewDate);
    if (anchorNoteNode) anchorNoteNode.textContent = previewNote;
    if (anchorModeNode) anchorModeNode.textContent = previewMode;
  }

  document.querySelectorAll('[data-stable-preset]').forEach((button) => {
    button.addEventListener('click', () => {
      stableInput.value = button.getAttribute('data-stable-preset') || '';
      computePreview();
    });
  });
  document.querySelectorAll('[data-stable-clear]').forEach((button) => {
    button.addEventListener('click', () => {
      stableInput.value = '';
      computePreview();
    });
  });
  tariffSelect.addEventListener('change', computePreview);
  billingDaysInput.addEventListener('input', computePreview);
  stableInput.addEventListener('input', computePreview);
  if (expiresInput) expiresInput.addEventListener('input', computePreview);
  computePreview();
})();
</script>
</body>
</html>
