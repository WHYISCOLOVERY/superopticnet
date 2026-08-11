<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/portal-security-lib.php';

app_enforce_https((bool) app_setting('portal_force_https', true));
app_session_boot();
$lang = app_lang();
$status = $_GET['status'] ?? '';

if (app_portal_current_user() !== null) {
    $returnTo = (string) ($_SESSION['portal_login_return_to'] ?? '');
    unset($_SESSION['portal_login_return_to']);
    $returnTo = preg_match('/^technician-close\.php\?token=[A-Za-z0-9._%~-]+$/D', $returnTo) === 1 ? $returnTo : '';
    header('Location: ' . ($returnTo !== '' ? $returnTo : ('portal.php' . ($lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''))));
    exit;
}

$flash = app_flash_message();
$staffLoginEndpoint = defined('SUPEROPTIC_STAFF_LOGIN_ENDPOINT') && SUPEROPTIC_STAFF_LOGIN_ENDPOINT === true;
$staffLoginEnabled = $staffLoginEndpoint || (bool) app_setting('portal_employee_login_public', false);
$showRoleSwitch = !$staffLoginEndpoint && $staffLoginEnabled;
$requestedRole = ($_POST['portal_role'] ?? $_GET['role'] ?? ($staffLoginEndpoint ? 'employee' : 'customer')) === 'employee' ? 'employee' : 'customer';
$selectedRole = $staffLoginEndpoint ? 'employee' : (($staffLoginEnabled && $requestedRole === 'employee') ? 'employee' : 'customer');
$loginPath = $staffLoginEndpoint ? 'staff-login.php' : 'portal-login.php';
$loginAction = $loginPath . ($lang !== 'az' ? '?lang=' . rawurlencode($lang) : '');
$humanVerificationScope = $staffLoginEndpoint ? 'staff-login' : 'customer-login';
$publicSiteUrl = app_config('public_site_url', 'index.php' . ($lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $login = clean_login_identifier((string) ($_POST['login'] ?? ''));
    $password = clean_password_input((string) ($_POST['password'] ?? ''));
    $clientIp = app_client_ip();
    $userAgent = clean_text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        app_record_security_event([
            'event' => 'portal_login_csrf_failed',
            'status' => 'warning',
            'role' => $selectedRole,
            'login' => $login,
            'meta' => [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
            ],
        ]);
        header('Location: ' . $loginPath . '?status=csrf_failed&role=' . rawurlencode($selectedRole) . '&lang=' . rawurlencode($lang));
        exit;
    }

    $humanRateScope = 'portal-human-verification-ip:' . $clientIp;
    $humanVerification = app_rate_limit_is_blocked($humanRateScope, 10, 600)
        ? ['ok' => false, 'mode' => app_portal_human_verification_mode(), 'reason' => 'rate_limited']
        : app_portal_human_verify($humanVerificationScope, $_POST);
    if (empty($humanVerification['ok'])) {
        app_rate_limit_hit($humanRateScope, 600);
        app_record_security_event([
            'event' => 'portal_login_human_verification_failed',
            'status' => 'warning',
            'role' => $selectedRole,
            'login' => $login,
            'meta' => [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'verification_mode' => (string) ($humanVerification['mode'] ?? ''),
                'reason' => (string) ($humanVerification['reason'] ?? ''),
            ],
        ]);
        header('Location: ' . $loginPath . '?status=captcha_failed&role=' . rawurlencode($selectedRole) . '&lang=' . rawurlencode($lang));
        exit;
    }

    $rateLimitScopes = [
        'portal-login-ip:' . app_client_ip(),
        'portal-login-role-ip:' . $selectedRole . ':' . app_client_ip(),
        'portal-login-user:' . $selectedRole . ':' . strtolower($login),
        'portal-login:' . $selectedRole . ':' . app_client_ip() . ':' . strtolower($login),
    ];
    $blocked = false;
    $retryAfter = 0;
    foreach ($rateLimitScopes as $scope) {
        if (app_rate_limit_is_blocked($scope, 5, 300)) {
            $blocked = true;
            $retryAfter = max($retryAfter, app_rate_limit_retry_after($scope, 300));
            break;
        }
    }
    if ($blocked) {
        if ($retryAfter > 0 && !headers_sent()) {
            header('Retry-After: ' . $retryAfter);
        }
        app_record_security_event([
            'event' => 'portal_login_rate_limited',
            'status' => 'warning',
            'role' => $selectedRole,
            'login' => $login,
            'meta' => [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'retry_after' => $retryAfter,
            ],
        ]);
        header('Location: ' . $loginPath . '?status=portal_rate_limited&role=' . rawurlencode($selectedRole) . '&lang=' . rawurlencode($lang));
        exit;
    }

    $user = app_portal_login($selectedRole, $login, $password);

    if ($user !== null) {
        foreach ($rateLimitScopes as $scope) {
            app_rate_limit_clear($scope);
        }
        app_record_security_event([
            'event' => 'portal_login_success',
            'status' => 'success',
            'role' => $selectedRole,
            'login' => $login,
            'meta' => [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'account_id' => (int) ($user['id'] ?? 0),
            ],
        ]);
        app_portal_sign_in($user);
        $returnTo = (string) ($_SESSION['portal_login_return_to'] ?? '');
        unset($_SESSION['portal_login_return_to']);
        $returnTo = preg_match('/^technician-close\.php\?token=[A-Za-z0-9._%~-]+$/D', $returnTo) === 1 ? $returnTo : '';
        $destination = $returnTo !== '' ? $returnTo : app_portal_login_destination($user);
        header('Location: ' . $destination . ($lang !== 'az' ? (str_contains($destination, '?') ? '&' : '?') . 'lang=' . rawurlencode($lang) : ''));
        exit;
    }

    foreach ($rateLimitScopes as $scope) {
        app_rate_limit_hit($scope, 300);
    }
    app_record_security_event([
        'event' => 'portal_login_failed',
        'status' => 'warning',
        'role' => $selectedRole,
        'login' => $login,
        'meta' => [
            'ip' => $clientIp,
            'user_agent' => $userAgent,
        ],
    ]);
    header('Location: ' . $loginPath . '?status=portal_login_failed&role=' . rawurlencode($selectedRole) . '&lang=' . rawurlencode($lang));
    exit;
}

$texts = [
    'az' => [
        'title' => 'Müştəri və əməkdaş girişi',
        'heading' => 'Super Optik portalına daxil olun',
        'text' => 'Müştəri hesabı ilə ödəniş, paket və müraciət məlumatlarını görün. Əməkdaş hesabı ilə billing və OLT keçidlərinə rolunuza uyğun daxil olun.',
        'benefits' => ['Ödəniş və paket görünüşü', 'Dəstək müraciətləri və profil yeniləməsi', 'Əməkdaş üçün rol əsaslı billing və OLT keçidləri'],
        'roles' => ['Müştəri', 'Əməkdaş'],
        'customer_label' => 'Abonent kodu',
        'customer_placeholder' => 'Məsələn: SO-100245',
        'employee_label' => 'İstifadəçi adı',
        'employee_placeholder' => 'Məsələn: support.agent',
        'password' => 'Şifrə',
        'password_placeholder' => 'Şifrənizi daxil edin',
        'submit' => 'Daxil ol',
        'back' => 'Sayta qayıt',
        'meta' => 'Portal girişində rolunuza uyğun səhifə açılır.',
        'customer_hint' => 'Abonent kodu ilə şəxsi panelə daxil olub ödəniş və müraciətləri görə bilərsiniz.',
        'employee_hint' => 'Əməkdaş girişi support, satış və NOC hesabları üçündür; açılan keçidlər rolunuza görə dəyişir.',
    ],
    'en' => [
        'title' => 'Customer and employee access',
        'heading' => 'Sign in to the Super Optik portal',
        'text' => 'Use a customer account to review payments, package details and requests. Use an employee account to open billing and OLT access according to your role.',
        'benefits' => ['Payment and package overview', 'Support requests and profile updates', 'Role-based billing and OLT handoffs for employees'],
        'roles' => ['Customer', 'Employee'],
        'customer_label' => 'Subscriber code',
        'customer_placeholder' => 'Example: SO-100245',
        'employee_label' => 'Username',
        'employee_placeholder' => 'Example: support.agent',
        'password' => 'Password',
        'password_placeholder' => 'Enter your password',
        'submit' => 'Sign in',
        'back' => 'Back to website',
        'meta' => 'The portal opens the page that matches your role.',
        'customer_hint' => 'Use your subscriber code to access payment and request information.',
        'employee_hint' => 'Employee access is for support, sales and NOC accounts; available links change by role.',
    ],
    'ru' => [
        'title' => 'Вход для клиентов и сотрудников',
        'heading' => 'Войдите в портал Super Optik',
        'text' => 'Через клиентский аккаунт вы увидите оплаты, пакет и обращения. Через учетную запись сотрудника откроются billing и OLT переходы по вашей роли.',
        'benefits' => ['Оплаты и информация по пакету', 'Обращения в поддержку и обновление профиля', 'Ролевые billing и OLT переходы для сотрудников'],
        'roles' => ['Клиент', 'Сотрудник'],
        'customer_label' => 'Код абонента',
        'customer_placeholder' => 'Например: SO-100245',
        'employee_label' => 'Имя пользователя',
        'employee_placeholder' => 'Например: support.agent',
        'password' => 'Пароль',
        'password_placeholder' => 'Введите пароль',
        'submit' => 'Войти',
        'back' => 'Вернуться на сайт',
        'meta' => 'Портал открывает страницу в соответствии с вашей ролью.',
        'customer_hint' => 'По коду абонента вы получите доступ к оплатам и обращениям.',
        'employee_hint' => 'Вход для сотрудников предназначен для support, sales и NOC; доступные переходы зависят от роли.',
    ],
];

$texts['az'] = [
    'title' => 'Musteri portali',
    'heading' => 'Super Optik musteri portalina daxil olun',
    'text' => 'Abonent kodu ve sifre ile odenis, paket, muraciet ve profil melumatlarinizi gore bilersiniz.',
    'benefits' => ['Odenis ve paket gorunusu', 'Muraciet ve mesaj tarixcesi', 'Profil ve sifre yenilemesi'],
    'roles' => ['Musteri', 'Emekdas'],
    'customer_label' => 'Abonent kodu',
    'customer_placeholder' => 'Meselen: SO-100245',
    'employee_label' => 'Istifadeci adi',
    'employee_placeholder' => 'Meselen: support.agent',
    'password' => 'Sifre',
    'password_placeholder' => 'Sifrenizi daxil edin',
    'submit' => 'Daxil ol',
    'back' => 'Sayta qayit',
    'meta' => 'Girisden sonra sexsi kabinetiniz acilacaq.',
    'customer_hint' => 'Abonent kodu ile sexsi panele daxil olub odenis ve muracietleri gore bilersiniz.',
    'employee_hint' => 'Daxili emekdas girisi. Bu sehife yalniz icazeli sebekeden istifade edilmelidir.',
];

$copy = $texts[$lang] ?? $texts['az'];
if ($lang === 'ru') {
    $copy = $texts['az'];
}
if ($staffLoginEndpoint) {
    $copy['title'] = 'Emekdas girisi';
    $copy['heading'] = 'Super Optik emekdas paneline daxil olun';
    $copy['text'] = 'Bu sehife operator, texnik, NOC, anbar ve admin emekdaslarinin daxili sisteme girisi ucundur.';
    $copy['benefits'] = ['Billing ve musteri 360 idareetmesi', 'Ticket, anbar ve KPI emeliyyatlari', 'NOC, OLT/ONT ve RADIUS kecidleri'];
    $copy['meta'] = 'Girisden sonra rolunuza uygun daxili idareetme paneli acilacaq.';
}
$mygovConfig = app_sso_provider_config('mygov');
$mygovReady = app_sso_provider_ready('mygov');
$flashMap = [
    'az' => [
        'portal_login_failed' => ['type' => 'error', 'message' => 'Giriş məlumatları təsdiqlənmədi. Zəhmət olmasa yenidən yoxlayın.'],
        'portal_rate_limited' => ['type' => 'error', 'message' => 'Çox sayda uğursuz cəhd oldu. 5 dəqiqə sonra yenidən yoxlayın.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Sessiya yoxlaması uğursuz oldu. Səhifəni yeniləyib yenidən cəhd edin.'],
        'auth_required' => ['type' => 'error', 'message' => 'Davam etmək üçün portala daxil olun.'],
        'logged_out' => ['type' => 'success', 'message' => 'Portal sessiyası bağlandı.'],
        'mygov_not_configured' => ['type' => 'error', 'message' => 'MyGov ID girişi hazırlıq rejimindədir. API məlumatları qoşulmadan aktiv edilməyəcək.'],
        'mygov_state_invalid' => ['type' => 'error', 'message' => 'MyGov sessiyası etibarsızdır və ya vaxtı bitib. Yenidən cəhd edin.'],
        'mygov_identity_not_linked' => ['type' => 'error', 'message' => 'Bu MyGov ID ilə bağlı portal hesabı tapılmadı. Admin identity mapping yaratmalıdır.'],
        'mygov_callback_pending' => ['type' => 'error', 'message' => 'MyGov callback modulu hazırdır, amma real token/userinfo adapteri hələ qoşulmayıb.'],
        'mygov_failed' => ['type' => 'error', 'message' => 'MyGov giriş state-i yaradılmadı. DB və konfiqurasiyanı yoxlayın.'],
    ],
    'en' => [
        'portal_login_failed' => ['type' => 'error', 'message' => 'Login details could not be verified. Please check them and try again.'],
        'portal_rate_limited' => ['type' => 'error', 'message' => 'Too many failed attempts. Please wait 5 minutes and try again.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Session verification failed. Refresh the page and try again.'],
        'auth_required' => ['type' => 'error', 'message' => 'Please sign in to continue.'],
        'logged_out' => ['type' => 'success', 'message' => 'Portal session was closed.'],
        'mygov_not_configured' => ['type' => 'error', 'message' => 'MyGov ID login is in preparation mode. It will not be enabled before API credentials are configured.'],
        'mygov_state_invalid' => ['type' => 'error', 'message' => 'MyGov session is invalid or expired. Try again.'],
        'mygov_identity_not_linked' => ['type' => 'error', 'message' => 'No portal account is linked to this MyGov ID. Admin mapping is required.'],
        'mygov_callback_pending' => ['type' => 'error', 'message' => 'MyGov callback skeleton is ready, but token/userinfo adapter is not connected yet.'],
        'mygov_failed' => ['type' => 'error', 'message' => 'Could not create MyGov login state. Check DB and configuration.'],
    ],
    'ru' => [
        'portal_login_failed' => ['type' => 'error', 'message' => 'Не удалось подтвердить данные для входа. Проверьте их и попробуйте снова.'],
        'portal_rate_limited' => ['type' => 'error', 'message' => 'Слишком много неудачных попыток. Подождите 5 минут и попробуйте снова.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Проверка сессии не прошла. Обновите страницу и повторите попытку.'],
        'auth_required' => ['type' => 'error', 'message' => 'Чтобы продолжить, войдите в портал.'],
        'logged_out' => ['type' => 'success', 'message' => 'Сессия портала завершена.'],
    ],
];

$flashMap['az'] = [
    'portal_login_failed' => ['type' => 'error', 'message' => 'Giris melumatlari tesdiqlenmedi. Zehmet olmasa yeniden yoxlayin.'],
    'portal_rate_limited' => ['type' => 'error', 'message' => 'Cox sayda ugursuz cehd oldu. 5 deqiqe sonra yeniden yoxlayin.'],
    'csrf_failed' => ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu. Sehifeni yenileyib yeniden cehd edin.'],
    'captcha_failed' => ['type' => 'error', 'message' => 'Men robot deyilem yoxlamasi tamamlanmadi. Yeniden cehd edin.'],
    'customer_password_reset_success' => ['type' => 'success', 'message' => 'Sifreniz yenilendi. Yeni sifrenizle daxil ola bilersiniz.'],
    'auth_required' => ['type' => 'error', 'message' => 'Davam etmek ucun portala daxil olun.'],
    'logged_out' => ['type' => 'success', 'message' => 'Portal sessiyasi baglandi.'],
    'mygov_not_configured' => ['type' => 'error', 'message' => 'MyGov ID girisi hazirliq rejimindedir. API melumatlari qosulmadan aktiv edilmeyecek.'],
    'mygov_state_invalid' => ['type' => 'error', 'message' => 'MyGov sessiyasi etibarsizdir ve ya vaxti bitib. Yeniden cehd edin.'],
    'mygov_identity_not_linked' => ['type' => 'error', 'message' => 'Bu MyGov ID ile bagli portal hesabi tapilmadi. Admin identity mapping yaratmalidir.'],
    'mygov_callback_pending' => ['type' => 'error', 'message' => 'MyGov callback modulu hazirdir, amma real token/userinfo adapteri hele qosulmayib.'],
    'mygov_failed' => ['type' => 'error', 'message' => 'MyGov giris state-i yaradilmadi. DB ve konfiqurasiyani yoxlayin.'],
];

if (isset($flashMap[$lang][$status])) {
    $flash = $flashMap[$lang][$status];
}
if ($lang === 'ru' && isset($flashMap['az'][$status])) {
    $flash = $flashMap['az'][$status];
}
$humanChallenge = app_portal_human_challenge($humanVerificationScope);
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Optik Portal Login</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-auth-body">
    <main class="portal-auth-shell">
        <section class="portal-auth-copy portal-reveal">
            <a href="<?= e($publicSiteUrl); ?>" class="portal-brand">
                <img src="images/favicon.png" alt="Super Optik Tech">
                <span>Super Optik Portal</span>
            </a>

            <span class="portal-kicker"><?= e($copy['title']); ?></span>
            <h1><?= e($copy['heading']); ?></h1>
            <p><?= e($copy['text']); ?></p>

            <div class="portal-benefits">
                <div><i class="fa-solid fa-credit-card"></i><span><?= e($copy['benefits'][0]); ?></span></div>
                <div><i class="fa-solid fa-headset"></i><span><?= e($copy['benefits'][1]); ?></span></div>
                <div><i class="fa-solid fa-sitemap"></i><span><?= e($copy['benefits'][2]); ?></span></div>
            </div>
        </section>

        <section class="portal-auth-card portal-reveal">
            <?php if ($flash !== null): ?>
                <div class="portal-alert portal-alert-<?= e($flash['type']); ?>" data-autohide-alert>
                    <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <span><?= e($flash['message']); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($showRoleSwitch): ?>
                <div class="portal-role-switch">
                    <button type="button" class="portal-role-btn<?= $selectedRole === 'customer' ? ' is-active' : ''; ?>" data-role-switch="customer"><?= e($copy['roles'][0]); ?></button>
                    <button type="button" class="portal-role-btn<?= $selectedRole === 'employee' ? ' is-active' : ''; ?>" data-role-switch="employee"><?= e($copy['roles'][1]); ?></button>
                </div>
            <?php endif; ?>

            <div class="portal-demo-box">
                <strong><?= e($selectedRole === 'employee' ? $copy['roles'][1] : $copy['roles'][0]); ?></strong>
                <p><?= e($selectedRole === 'employee' ? $copy['employee_hint'] : $copy['customer_hint']); ?></p>
            </div>

            <form class="portal-auth-form" action="<?= e($loginAction); ?>" method="post">
                <input type="hidden" name="portal_role" value="<?= e($selectedRole); ?>" data-role-input>
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">

                <div class="portal-form-row">
                    <label for="portalLogin" data-role-label><?= e($selectedRole === 'employee' ? $copy['employee_label'] : $copy['customer_label']); ?></label>
                    <input
                        id="portalLogin"
                        type="text"
                        name="login"
                        placeholder="<?= e($selectedRole === 'employee' ? $copy['employee_placeholder'] : $copy['customer_placeholder']); ?>"
                        required
                        data-role-login-input
                        <?php if ($showRoleSwitch): ?>
                            data-customer-label="<?= e($copy['customer_label']); ?>"
                            data-customer-placeholder="<?= e($copy['customer_placeholder']); ?>"
                            data-employee-label="<?= e($copy['employee_label']); ?>"
                            data-employee-placeholder="<?= e($copy['employee_placeholder']); ?>"
                        <?php endif; ?>
                    >
                </div>

                <div class="portal-form-row">
                    <label for="portalPassword"><?= e($copy['password']); ?></label>
                    <input id="portalPassword" type="password" name="password" placeholder="<?= e($copy['password_placeholder']); ?>" required>
                </div>

                <?php app_portal_render_human_challenge($humanChallenge); ?>

                <button type="submit" class="portal-submit-btn"><?= e($copy['submit']); ?></button>
            </form>

            <?php if (!$staffLoginEndpoint): ?>
                <a class="portal-password-recovery-link" href="customer-password-recovery.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                    Şifrəni unutmusunuz? WhatsApp kodu ilə yeniləyin
                </a>
            <?php endif; ?>

            <?php if ($mygovReady): ?>
                <div class="portal-sso-divider"><span>ve ya</span></div>
                <a
                    class="portal-sso-btn is-ready"
                    href="mygov-auth.php?role=<?= e($selectedRole); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>"
                    data-mygov-login-link
                    data-base-href="mygov-auth.php"
                    data-lang="<?= e($lang); ?>"
                >
                    <i class="fa-solid fa-id-card"></i>
                    <span>MyGov ID ile daxil ol</span>
                    <small>Aktiv SSO konfiqurasiyasi</small>
                </a>
            <?php endif; ?>

            <div class="portal-auth-meta">
                <a href="<?= e($publicSiteUrl); ?>"><?= e($copy['back']); ?></a>
                <span><?= e($copy['meta']); ?></span>
            </div>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <?php if (($humanChallenge['mode'] ?? '') === 'google'): ?>
        <script src="https://www.google.com/recaptcha/api.js?hl=az" async defer></script>
    <?php endif; ?>
</body>
</html>
