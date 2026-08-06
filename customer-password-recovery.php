<?php
declare(strict_types=1);

require_once __DIR__ . '/portal-security-lib.php';

app_enforce_https((bool) app_setting('portal_force_https', true));
app_session_boot();
$lang = app_lang();
if (app_portal_current_user() !== null) {
    header('Location: portal.php');
    exit;
}

if (isset($_GET['restart'])) {
    unset($_SESSION['customer_password_recovery_token']);
    header('Location: customer-password-recovery.php' . ($lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''));
    exit;
}

$step = ($_GET['step'] ?? '') === 'verify' ? 'verify' : 'request';
$status = clean_text((string) ($_GET['status'] ?? ''));
$scope = $step === 'verify' ? 'customer-password-recovery-verify' : 'customer-password-recovery-request';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = clean_text((string) ($_POST['action'] ?? ''));
    $step = $action === 'complete_recovery' ? 'verify' : 'request';
    $scope = $step === 'verify' ? 'customer-password-recovery-verify' : 'customer-password-recovery-request';
    $csrf = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrf) ? $csrf : null)) {
        header('Location: customer-password-recovery.php?step=' . $step . '&status=csrf_failed');
        exit;
    }

    $humanScope = 'portal-human-verification-ip:' . app_client_ip();
    $humanVerification = app_rate_limit_is_blocked($humanScope, 10, 600)
        ? ['ok' => false, 'mode' => app_portal_human_verification_mode(), 'reason' => 'rate_limited']
        : app_portal_human_verify($scope, $_POST);
    if (empty($humanVerification['ok'])) {
        app_rate_limit_hit($humanScope, 600);
        app_record_security_event([
            'event' => 'customer_password_recovery_human_verification_failed',
            'status' => 'warning',
            'role' => 'customer',
            'login' => '',
            'meta' => [
                'ip' => app_client_ip(),
                'step' => $step,
                'mode' => (string) ($humanVerification['mode'] ?? ''),
                'reason' => (string) ($humanVerification['reason'] ?? ''),
            ],
        ]);
        header('Location: customer-password-recovery.php?step=' . $step . '&status=captcha_failed');
        exit;
    }

    if ($action === 'request_recovery') {
        app_portal_recovery_request((string) ($_POST['identifier'] ?? ''));
        header('Location: customer-password-recovery.php?step=verify&status=request_accepted');
        exit;
    }

    if ($action === 'complete_recovery') {
        $result = app_portal_recovery_complete(
            trim((string) ($_POST['verification_code'] ?? '')),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        if (!empty($result['ok'])) {
            header('Location: portal-login.php?status=customer_password_reset_success');
            exit;
        }
        $reason = (string) ($result['reason'] ?? 'recovery_failed');
        $status = $reason === 'password_policy_failed'
            ? 'password_policy_failed'
            : ($reason === 'recovery_request_expired' || $reason === 'recovery_session_invalid'
                ? 'recovery_expired'
                : 'verification_failed');
        header('Location: customer-password-recovery.php?step=verify&status=' . rawurlencode($status));
        exit;
    }

    header('Location: customer-password-recovery.php?status=request_failed');
    exit;
}

$messages = [
    'request_accepted' => ['type' => 'success', 'text' => 'Məlumat hesabla uyğun gəlibsə, qeydiyyatdakı WhatsApp nömrəsinə 6 rəqəmli kod göndərildi.'],
    'captcha_failed' => ['type' => 'error', 'text' => '“Mən robot deyiləm” yoxlaması tamamlanmadı. Yenidən cəhd edin.'],
    'csrf_failed' => ['type' => 'error', 'text' => 'Sessiyanın vaxtı bitib. Səhifəni yeniləyib təkrar cəhd edin.'],
    'password_policy_failed' => ['type' => 'error', 'text' => 'Yeni şifrə ən az 14 simvol olmalı, böyük və kiçik hərf, rəqəm və xüsusi işarə saxlamalıdır.'],
    'verification_failed' => ['type' => 'error', 'text' => 'Kod düzgün deyil və ya cəhd limiti bitib. Məlumatları yoxlayın.'],
    'recovery_expired' => ['type' => 'error', 'text' => 'Kodun vaxtı bitib. Yeni kod tələb edin.'],
    'request_failed' => ['type' => 'error', 'text' => 'Sorğu tamamlanmadı. Yenidən cəhd edin.'],
];
$message = $messages[$status] ?? null;
$humanChallenge = app_portal_human_challenge($scope);
?>
<!doctype html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Müştəri şifrəsini yenilə | Super Optik</title>
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-auth-body">
<main class="portal-auth-shell portal-recovery-shell">
    <section class="portal-auth-copy portal-reveal">
        <a href="portal-login.php" class="portal-brand">
            <img src="images/favicon.png" alt="Super Optik">
            <span>Super Optik Portal</span>
        </a>
        <span class="portal-kicker">Təhlükəsiz şifrə yeniləmə</span>
        <h1>Şifrənizi WhatsApp kodu ilə yeniləyin</h1>
        <p>Kod yalnız hesabda əvvəlcədən qeydiyyata alınmış WhatsApp nömrəsinə göndərilir və 10 dəqiqə qüvvədə qalır.</p>
        <div class="portal-benefits">
            <div><span>6 rəqəmli, birdəfəlik kod</span></div>
            <div><span>Maksimum 5 yoxlama cəhdi</span></div>
            <div><span>Kod və şifrə açıq formada saxlanmır</span></div>
        </div>
    </section>

    <section class="portal-auth-card portal-reveal">
        <?php if ($message !== null): ?>
            <div class="portal-alert portal-alert-<?= e($message['type']); ?>">
                <span><?= e($message['text']); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($step === 'request'): ?>
            <div class="portal-demo-box">
                <strong>WhatsApp kodu tələb et</strong>
                <p>Müştəri kodunuzu daxil edin. Təhlükəsizlik üçün hesabın mövcudluğu barədə ayrıca məlumat göstərilmir.</p>
            </div>
            <form class="portal-auth-form" method="post" autocomplete="off">
                <input type="hidden" name="action" value="request_recovery">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <div class="portal-form-row">
                    <label for="recoveryIdentifier">Müştəri kodu</label>
                    <input id="recoveryIdentifier" type="text" name="identifier" required autocomplete="username" placeholder="Məsələn: 2320025">
                </div>
                <?php app_portal_render_human_challenge($humanChallenge); ?>
                <button type="submit" class="portal-submit-btn">Kodu WhatsApp-a göndər</button>
            </form>
        <?php else: ?>
            <div class="portal-demo-box">
                <strong>Kodu təsdiqlə və yeni şifrə seç</strong>
                <p>Kod gəlməyibsə nömrənin sistemdə düzgün qeydiyyatda olduğunu operatorla yoxlayın.</p>
            </div>
            <form class="portal-auth-form" method="post" autocomplete="off">
                <input type="hidden" name="action" value="complete_recovery">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <div class="portal-form-row">
                    <label for="verificationCode">6 rəqəmli kod</label>
                    <input id="verificationCode" type="text" name="verification_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code">
                </div>
                <div class="portal-form-row">
                    <label for="newPassword">Yeni şifrə</label>
                    <input id="newPassword" type="password" name="new_password" minlength="14" required autocomplete="new-password">
                </div>
                <div class="portal-form-row">
                    <label for="confirmPassword">Yeni şifrəni təkrar yazın</label>
                    <input id="confirmPassword" type="password" name="confirm_password" minlength="14" required autocomplete="new-password">
                    <small>Ən az 14 simvol, böyük və kiçik hərf, rəqəm və xüsusi işarə.</small>
                </div>
                <?php app_portal_render_human_challenge($humanChallenge); ?>
                <button type="submit" class="portal-submit-btn">Şifrəni yenilə</button>
            </form>
            <a class="portal-password-recovery-link" href="customer-password-recovery.php?restart=1">Yeni kod tələb et</a>
        <?php endif; ?>

        <div class="portal-auth-meta">
            <a href="portal-login.php">Giriş səhifəsinə qayıt</a>
            <span>Operator da şəxsiyyət yoxlamasından sonra müvəqqəti şifrə verə bilər.</span>
        </div>
    </section>
</main>
<?php if (($humanChallenge['mode'] ?? '') === 'google'): ?>
    <script src="https://www.google.com/recaptcha/api.js?hl=az" async defer></script>
<?php endif; ?>
</body>
</html>
