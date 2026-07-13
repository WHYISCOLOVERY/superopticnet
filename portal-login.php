<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

app_enforce_https((bool) app_setting('portal_force_https', true));
app_session_boot();
$lang = app_lang();
$status = $_GET['status'] ?? '';

if (app_portal_current_user() !== null) {
    header('Location: portal.php' . ($lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''));
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
        header('Location: ' . app_portal_login_destination($user) . ($lang !== 'az' ? (str_contains(app_portal_login_destination($user), '?') ? '&' : '?') . 'lang=' . rawurlencode($lang) : ''));
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
        'title' => 'MÃ¼ÅŸtÉ™ri vÉ™ É™mÉ™kdaÅŸ giriÅŸi',
        'heading' => 'Super Optik portalÄ±na daxil olun',
        'text' => 'MÃ¼ÅŸtÉ™ri hesabÄ± ilÉ™ Ã¶dÉ™niÅŸ, paket vÉ™ mÃ¼raciÉ™t mÉ™lumatlarÄ±nÄ± gÃ¶rÃ¼n. ÆmÉ™kdaÅŸ hesabÄ± ilÉ™ billing vÉ™ OLT keÃ§idlÉ™rinÉ™ rolunuza uyÄŸun daxil olun.',
        'benefits' => ['Ã–dÉ™niÅŸ vÉ™ paket gÃ¶rÃ¼nÃ¼ÅŸÃ¼', 'DÉ™stÉ™k mÃ¼raciÉ™tlÉ™ri vÉ™ profil yenilÉ™mÉ™si', 'ÆmÉ™kdaÅŸ Ã¼Ã§Ã¼n rol É™saslÄ± billing vÉ™ OLT keÃ§idlÉ™ri'],
        'roles' => ['MÃ¼ÅŸtÉ™ri', 'ÆmÉ™kdaÅŸ'],
        'customer_label' => 'Abonent kodu',
        'customer_placeholder' => 'MÉ™sÉ™lÉ™n: SO-100245',
        'employee_label' => 'Ä°stifadÉ™Ã§i adÄ±',
        'employee_placeholder' => 'MÉ™sÉ™lÉ™n: support.agent',
        'password' => 'ÅifrÉ™',
        'password_placeholder' => 'ÅifrÉ™nizi daxil edin',
        'submit' => 'Daxil ol',
        'back' => 'Sayta qayÄ±t',
        'meta' => 'Portal giriÅŸindÉ™ rolunuza uyÄŸun sÉ™hifÉ™ aÃ§Ä±lÄ±r.',
        'customer_hint' => 'Abonent kodu ilÉ™ ÅŸÉ™xsi panelÉ™ daxil olub Ã¶dÉ™niÅŸ vÉ™ mÃ¼raciÉ™tlÉ™ri gÃ¶rÉ™ bilÉ™rsiniz.',
        'employee_hint' => 'ÆmÉ™kdaÅŸ giriÅŸi support, satÄ±ÅŸ vÉ™ NOC hesablarÄ± Ã¼Ã§Ã¼ndÃ¼r; aÃ§Ä±lan keÃ§idlÉ™r rolunuza gÃ¶rÉ™ dÉ™yiÅŸir.',
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
        'title' => 'Ğ’Ñ…Ğ¾Ğ´ Ğ´Ğ»Ñ ĞºĞ»Ğ¸ĞµĞ½Ñ‚Ğ¾Ğ² Ğ¸ ÑĞ¾Ñ‚Ñ€ÑƒĞ´Ğ½Ğ¸ĞºĞ¾Ğ²',
        'heading' => 'Ğ’Ğ¾Ğ¹Ğ´Ğ¸Ñ‚Ğµ Ğ² Ğ¿Ğ¾Ñ€Ñ‚Ğ°Ğ» Super Optik',
        'text' => 'Ğ§ĞµÑ€ĞµĞ· ĞºĞ»Ğ¸ĞµĞ½Ñ‚ÑĞºĞ¸Ğ¹ Ğ°ĞºĞºĞ°ÑƒĞ½Ñ‚ Ğ²Ñ‹ ÑƒĞ²Ğ¸Ğ´Ğ¸Ñ‚Ğµ Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ñ‹, Ğ¿Ğ°ĞºĞµÑ‚ Ğ¸ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ñ. Ğ§ĞµÑ€ĞµĞ· ÑƒÑ‡ĞµÑ‚Ğ½ÑƒÑ Ğ·Ğ°Ğ¿Ğ¸ÑÑŒ ÑĞ¾Ñ‚Ñ€ÑƒĞ´Ğ½Ğ¸ĞºĞ° Ğ¾Ñ‚ĞºÑ€Ğ¾ÑÑ‚ÑÑ billing Ğ¸ OLT Ğ¿ĞµÑ€ĞµÑ…Ğ¾Ğ´Ñ‹ Ğ¿Ğ¾ Ğ²Ğ°ÑˆĞµĞ¹ Ñ€Ğ¾Ğ»Ğ¸.',
        'benefits' => ['ĞĞ¿Ğ»Ğ°Ñ‚Ñ‹ Ğ¸ Ğ¸Ğ½Ñ„Ğ¾Ñ€Ğ¼Ğ°Ñ†Ğ¸Ñ Ğ¿Ğ¾ Ğ¿Ğ°ĞºĞµÑ‚Ñƒ', 'ĞĞ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ñ Ğ² Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºÑƒ Ğ¸ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½Ğ¸Ğµ Ğ¿Ñ€Ğ¾Ñ„Ğ¸Ğ»Ñ', 'Ğ Ğ¾Ğ»ĞµĞ²Ñ‹Ğµ billing Ğ¸ OLT Ğ¿ĞµÑ€ĞµÑ…Ğ¾Ğ´Ñ‹ Ğ´Ğ»Ñ ÑĞ¾Ñ‚Ñ€ÑƒĞ´Ğ½Ğ¸ĞºĞ¾Ğ²'],
        'roles' => ['ĞšĞ»Ğ¸ĞµĞ½Ñ‚', 'Ğ¡Ğ¾Ñ‚Ñ€ÑƒĞ´Ğ½Ğ¸Ğº'],
        'customer_label' => 'ĞšĞ¾Ğ´ Ğ°Ğ±Ğ¾Ğ½ĞµĞ½Ñ‚Ğ°',
        'customer_placeholder' => 'ĞĞ°Ğ¿Ñ€Ğ¸Ğ¼ĞµÑ€: SO-100245',
        'employee_label' => 'Ğ˜Ğ¼Ñ Ğ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ñ‚ĞµĞ»Ñ',
        'employee_placeholder' => 'ĞĞ°Ğ¿Ñ€Ğ¸Ğ¼ĞµÑ€: support.agent',
        'password' => 'ĞŸĞ°Ñ€Ğ¾Ğ»ÑŒ',
        'password_placeholder' => 'Ğ’Ğ²ĞµĞ´Ğ¸Ñ‚Ğµ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ',
        'submit' => 'Ğ’Ğ¾Ğ¹Ñ‚Ğ¸',
        'back' => 'Ğ’ĞµÑ€Ğ½ÑƒÑ‚ÑŒÑÑ Ğ½Ğ° ÑĞ°Ğ¹Ñ‚',
        'meta' => 'ĞŸĞ¾Ñ€Ñ‚Ğ°Ğ» Ğ¾Ñ‚ĞºÑ€Ñ‹Ğ²Ğ°ĞµÑ‚ ÑÑ‚Ñ€Ğ°Ğ½Ğ¸Ñ†Ñƒ Ğ² ÑĞ¾Ğ¾Ñ‚Ğ²ĞµÑ‚ÑÑ‚Ğ²Ğ¸Ğ¸ Ñ Ğ²Ğ°ÑˆĞµĞ¹ Ñ€Ğ¾Ğ»ÑŒÑ.',
        'customer_hint' => 'ĞŸĞ¾ ĞºĞ¾Ğ´Ñƒ Ğ°Ğ±Ğ¾Ğ½ĞµĞ½Ñ‚Ğ° Ğ²Ñ‹ Ğ¿Ğ¾Ğ»ÑƒÑ‡Ğ¸Ñ‚Ğµ Ğ´Ğ¾ÑÑ‚ÑƒĞ¿ Ğº Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ğ°Ğ¼ Ğ¸ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸ÑĞ¼.',
        'employee_hint' => 'Ğ’Ñ…Ğ¾Ğ´ Ğ´Ğ»Ñ ÑĞ¾Ñ‚Ñ€ÑƒĞ´Ğ½Ğ¸ĞºĞ¾Ğ² Ğ¿Ñ€ĞµĞ´Ğ½Ğ°Ğ·Ğ½Ğ°Ñ‡ĞµĞ½ Ğ´Ğ»Ñ support, sales Ğ¸ NOC; Ğ´Ğ¾ÑÑ‚ÑƒĞ¿Ğ½Ñ‹Ğµ Ğ¿ĞµÑ€ĞµÑ…Ğ¾Ğ´Ñ‹ Ğ·Ğ°Ğ²Ğ¸ÑÑÑ‚ Ğ¾Ñ‚ Ñ€Ğ¾Ğ»Ğ¸.',
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
        'portal_login_failed' => ['type' => 'error', 'message' => 'GiriÅŸ mÉ™lumatlarÄ± tÉ™sdiqlÉ™nmÉ™di. ZÉ™hmÉ™t olmasa yenidÉ™n yoxlayÄ±n.'],
        'portal_rate_limited' => ['type' => 'error', 'message' => 'Ã‡ox sayda uÄŸursuz cÉ™hd oldu. 5 dÉ™qiqÉ™ sonra yenidÉ™n yoxlayÄ±n.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Sessiya yoxlamasÄ± uÄŸursuz oldu. SÉ™hifÉ™ni yenilÉ™yib yenidÉ™n cÉ™hd edin.'],
        'auth_required' => ['type' => 'error', 'message' => 'Davam etmÉ™k Ã¼Ã§Ã¼n portala daxil olun.'],
        'logged_out' => ['type' => 'success', 'message' => 'Portal sessiyasÄ± baÄŸlandÄ±.'],
        'mygov_not_configured' => ['type' => 'error', 'message' => 'MyGov ID giriÅŸi hazÄ±rlÄ±q rejimindÉ™dir. API mÉ™lumatlarÄ± qoÅŸulmadan aktiv edilmÉ™yÉ™cÉ™k.'],
        'mygov_state_invalid' => ['type' => 'error', 'message' => 'MyGov sessiyasÄ± etibarsÄ±zdÄ±r vÉ™ ya vaxtÄ± bitib. YenidÉ™n cÉ™hd edin.'],
        'mygov_identity_not_linked' => ['type' => 'error', 'message' => 'Bu MyGov ID ilÉ™ baÄŸlÄ± portal hesabÄ± tapÄ±lmadÄ±. Admin identity mapping yaratmalÄ±dÄ±r.'],
        'mygov_callback_pending' => ['type' => 'error', 'message' => 'MyGov callback modulu hazÄ±rdÄ±r, amma real token/userinfo adapteri hÉ™lÉ™ qoÅŸulmayÄ±b.'],
        'mygov_failed' => ['type' => 'error', 'message' => 'MyGov giriÅŸ state-i yaradÄ±lmadÄ±. DB vÉ™ konfiqurasiyanÄ± yoxlayÄ±n.'],
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
        'portal_login_failed' => ['type' => 'error', 'message' => 'ĞĞµ ÑƒĞ´Ğ°Ğ»Ğ¾ÑÑŒ Ğ¿Ğ¾Ğ´Ñ‚Ğ²ĞµÑ€Ğ´Ğ¸Ñ‚ÑŒ Ğ´Ğ°Ğ½Ğ½Ñ‹Ğµ Ğ´Ğ»Ñ Ğ²Ñ…Ğ¾Ğ´Ğ°. ĞŸÑ€Ğ¾Ğ²ĞµÑ€ÑŒÑ‚Ğµ Ğ¸Ñ… Ğ¸ Ğ¿Ğ¾Ğ¿Ñ€Ğ¾Ğ±ÑƒĞ¹Ñ‚Ğµ ÑĞ½Ğ¾Ğ²Ğ°.'],
        'portal_rate_limited' => ['type' => 'error', 'message' => 'Ğ¡Ğ»Ğ¸ÑˆĞºĞ¾Ğ¼ Ğ¼Ğ½Ğ¾Ğ³Ğ¾ Ğ½ĞµÑƒĞ´Ğ°Ñ‡Ğ½Ñ‹Ñ… Ğ¿Ğ¾Ğ¿Ñ‹Ñ‚Ğ¾Ğº. ĞŸĞ¾Ğ´Ğ¾Ğ¶Ğ´Ğ¸Ñ‚Ğµ 5 Ğ¼Ğ¸Ğ½ÑƒÑ‚ Ğ¸ Ğ¿Ğ¾Ğ¿Ñ€Ğ¾Ğ±ÑƒĞ¹Ñ‚Ğµ ÑĞ½Ğ¾Ğ²Ğ°.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'ĞŸÑ€Ğ¾Ğ²ĞµÑ€ĞºĞ° ÑĞµÑÑĞ¸Ğ¸ Ğ½Ğµ Ğ¿Ñ€Ğ¾ÑˆĞ»Ğ°. ĞĞ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚Ğµ ÑÑ‚Ñ€Ğ°Ğ½Ğ¸Ñ†Ñƒ Ğ¸ Ğ¿Ğ¾Ğ²Ñ‚Ğ¾Ñ€Ğ¸Ñ‚Ğµ Ğ¿Ğ¾Ğ¿Ñ‹Ñ‚ĞºÑƒ.'],
        'auth_required' => ['type' => 'error', 'message' => 'Ğ§Ñ‚Ğ¾Ğ±Ñ‹ Ğ¿Ñ€Ğ¾Ğ´Ğ¾Ğ»Ğ¶Ğ¸Ñ‚ÑŒ, Ğ²Ğ¾Ğ¹Ğ´Ğ¸Ñ‚Ğµ Ğ² Ğ¿Ğ¾Ñ€Ñ‚Ğ°Ğ».'],
        'logged_out' => ['type' => 'success', 'message' => 'Ğ¡ĞµÑÑĞ¸Ñ Ğ¿Ğ¾Ñ€Ñ‚Ğ°Ğ»Ğ° Ğ·Ğ°Ğ²ĞµÑ€ÑˆĞµĞ½Ğ°.'],
    ],
];

$flashMap['az'] = [
    'portal_login_failed' => ['type' => 'error', 'message' => 'Giris melumatlari tesdiqlenmedi. Zehmet olmasa yeniden yoxlayin.'],
    'portal_rate_limited' => ['type' => 'error', 'message' => 'Cox sayda ugursuz cehd oldu. 5 deqiqe sonra yeniden yoxlayin.'],
    'csrf_failed' => ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu. Sehifeni yenileyib yeniden cehd edin.'],
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

                <button type="submit" class="portal-submit-btn"><?= e($copy['submit']); ?></button>
            </form>

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
</body>
</html>
