<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

app_session_boot();
$lang = app_lang();
$provider = 'mygov';
$role = ($_GET['role'] ?? $_POST['role'] ?? 'customer') === 'employee' ? 'employee' : 'customer';
$returnUrl = clean_text((string) ($_GET['return'] ?? $_POST['return'] ?? ''));
$config = app_sso_provider_config($provider);

function mygov_login_redirect(string $status, string $role, string $lang): never
{
    $params = ['status' => $status, 'role' => $role];
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }
    header('Location: portal-login.php?' . http_build_query($params));
    exit;
}

if (isset($_GET['callback'])) {
    $state = clean_text((string) ($_GET['state'] ?? ''));
    $stateRow = app_sso_consume_state($provider, $state);
    if ($stateRow === null) {
        mygov_login_redirect('mygov_state_invalid', $role, $lang);
    }

    $role = (string) ($stateRow['requested_role'] ?? $role);

    if (!empty($config['mock_enabled'])) {
        $mockSubject = clean_text((string) ($_GET['mock_subject'] ?? ''));
        if ($mockSubject === '') {
            mygov_login_redirect('mygov_identity_not_linked', $role, $lang);
        }

        $account = app_sso_find_account_by_identity($provider, $mockSubject, $role);
        if ($account === null) {
            mygov_login_redirect('mygov_identity_not_linked', $role, $lang);
        }

        app_portal_sign_in(app_db_user_session_payload($account));
        $destination = app_portal_login_destination(app_db_user_session_payload($account));
        header('Location: ' . $destination . ($lang !== 'az' ? (str_contains($destination, '?') ? '&' : '?') . 'lang=' . rawurlencode($lang) : ''));
        exit;
    }

    if (!app_sso_provider_ready($provider)) {
        mygov_login_redirect('mygov_not_configured', $role, $lang);
    }

    /*
     * Future integration point:
     * 1. Exchange `code` with myGov token endpoint using client_secret and redirect_uri.
     * 2. Validate ID token signature, issuer, audience, expiry and nonce.
     * 3. Fetch userinfo and map provider subject / FIN to portal_account_identities.
     * 4. Sign in the matched customer or employee account.
     */
    mygov_login_redirect('mygov_callback_pending', $role, $lang);
}

$stateRow = app_sso_create_state($provider, $role, $returnUrl);
if ($stateRow === null) {
    mygov_login_redirect('mygov_failed', $role, $lang);
}

if (!empty($config['mock_enabled'])) {
    $mockSubject = clean_text((string) ($_GET['mock_subject'] ?? ''));
    $params = [
        'callback' => '1',
        'state' => (string) $stateRow['state'],
        'role' => $role,
    ];
    if ($mockSubject !== '') {
        $params['mock_subject'] = $mockSubject;
    }
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }
    header('Location: mygov-auth.php?' . http_build_query($params));
    exit;
}

$authorizeUrl = app_sso_authorize_url($provider, $stateRow);
if ($authorizeUrl === null) {
    mygov_login_redirect('mygov_not_configured', $role, $lang);
}

header('Location: ' . $authorizeUrl);
exit;
