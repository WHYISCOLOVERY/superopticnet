<?php
declare(strict_types=1);

require __DIR__ . '/mgs-integration-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_status('request_failed');
}

$csrfToken = $_POST['csrf_token'] ?? '';
$honeypot = clean_text($_POST['website'] ?? '');
$lang = $_POST['lang'] ?? app_lang();
$lang = is_string($lang) && in_array($lang, app_supported_langs(), true) ? $lang : 'az';
$fullName = clean_text($_POST['full_name'] ?? '');
$phone = normalize_phone($_POST['phone'] ?? '');
$email = clean_text($_POST['email'] ?? '');
$serviceType = clean_text($_POST['service_type'] ?? '');
$area = clean_text($_POST['area'] ?? '');
$message = clean_text($_POST['message'] ?? '');
$recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
    redirect_with_status('csrf_failed');
}

if ($honeypot !== '') {
    redirect_with_status('request_failed');
}

if (app_rate_limit_is_blocked('lead:' . app_client_ip(), 6, 600)) {
    redirect_with_status('rate_limited');
}

if ($fullName === '' || $phone === '' || $serviceType === '' || $area === '') {
    redirect_with_status('request_failed');
}

if (!preg_match('/^\+994(10|50|51|55|60|70|77)\d{7}$/', $phone)) {
    redirect_with_status('request_failed');
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_status('request_failed');
}

if ($message === '') {
    $message = 'Yeni qoşulma və ya xidmət sorğusu';
}

$recaptchaSecret = app_environment_secret('RECAPTCHA_SECRET');
if ($recaptchaSecret !== '') {
    $recaptchaVerified = verify_recaptcha($recaptchaSecret, $recaptchaResponse);
    if (!$recaptchaVerified) {
        redirect_with_status('captcha_failed');
    }
}

$telegramSent = false;
$billingSent = false;
app_rate_limit_hit('lead:' . app_client_ip(), 600);

$billingUrl = app_config('billing_ticket_url');
$canUseBilling = app_portal_mode() !== 'mock' && $billingUrl !== '';

if (function_exists('app_notification_route_event')) {
    $telegramMessage = implode("\n", [
        'Yeni sayt muracieti',
        'Ad ve soyad: ' . $fullName,
        'Telefon: ' . $phone,
        'E-mail: ' . ($email !== '' ? $email : 'Qeyd edilmeyib'),
        'Xidmet: ' . $serviceType,
        'Erazi: ' . $area,
        'Qeyd: ' . $message,
        'Tarix: ' . date('d.m.Y H:i:s'),
    ]);

    $telegramRoute = app_notification_route_event([
        'category' => 'CUSTOMER_REQUEST',
        'event_type' => 'website_lead',
        'source' => 'superoptic_site',
        'title' => 'Yeni sayt muracieti',
        'message' => $telegramMessage,
        'dedup_key' => hash('sha256', implode('|', ['website_lead', $phone, $serviceType, $area, date('YmdHi')])),
        'template_code' => 'website_customer_request',
        'metadata' => [
            'customer_name' => $fullName,
            'phone' => $phone,
            'service_type' => $serviceType,
            'area' => $area,
        ],
    ], false, true);
    $telegramSent = !empty($telegramRoute['queued']);
}

if ($canUseBilling) {
    $billingSent = http_post(
        $billingUrl,
        [
            'ticket_zone' => $area,
            'ticket_employee' => app_config('billing_employee_id', '54'),
            'open_employee' => $fullName,
            'ticket_type' => $serviceType . ' / Sayt',
            'ticket_number' => $phone,
            'ticket_company' => 'Super Optik Tech',
            'ticket_end_date' => date('d.m.Y H:i:s'),
            'ticket_comment' => $message . ($email !== '' ? ' | Email: ' . $email : ''),
        ]
    );
}

if ($telegramSent || $billingSent) {
    redirect_with_status('request_sent');
}

if (!$telegramSent) {
    redirect_with_status(!$canUseBilling ? 'missing_config' : 'request_failed');
}

redirect_with_status('request_failed');

function verify_recaptcha(string $secret, string $response): bool
{
    if ($response === '') {
        return false;
    }

    $result = http_post_json(
        'https://www.google.com/recaptcha/api/siteverify',
        [
            'secret' => $secret,
            'response' => $response,
        ],
        ['Content-Type: application/x-www-form-urlencoded']
    );

    return is_array($result) && !empty($result['success']);
}

function redirect_with_status(string $status): never
{
    global $lang;

    $query = '?status=' . rawurlencode($status);
    if ($lang !== 'az') {
        $query .= '&lang=' . rawurlencode($lang);
    }

    header('Location: index.php' . $query);
    exit;
}
