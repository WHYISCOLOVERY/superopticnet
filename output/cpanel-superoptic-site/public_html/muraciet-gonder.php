<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$lang = app_lang();
$redirect = static function (string $status) use (&$lang): never {
    $query = '?status=' . rawurlencode($status);
    if ($lang !== 'az') {
        $query .= '&lang=' . rawurlencode($lang);
    }
    header('Location: index.php' . $query);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $redirect('request_failed');
}

$csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
if (!app_verify_csrf($csrfToken)) {
    $redirect('csrf_failed');
}
if (clean_text($_POST['website'] ?? '') !== '') {
    $redirect('request_failed');
}

$rateScope = 'website-lead:' . app_client_ip();
if (app_public_rate_limit($rateScope, 6, 600)) {
    $redirect('rate_limited');
}
app_public_rate_limit($rateScope, 6, 600, true);

$payload = [
    'full_name' => clean_text($_POST['full_name'] ?? ''),
    'phone' => normalize_phone($_POST['phone'] ?? ''),
    'email' => clean_text($_POST['email'] ?? ''),
    'service_type' => clean_text($_POST['service_type'] ?? ''),
    'area' => clean_text($_POST['area'] ?? ''),
    'message' => clean_text($_POST['message'] ?? ''),
    'lang' => $lang,
];
if ($payload['message'] === '') {
    $payload['message'] = 'Yeni qoşulma və ya xidmət sorğusu';
}
if ($payload['full_name'] === '' || $payload['phone'] === '' || $payload['service_type'] === '' || $payload['area'] === '') {
    $redirect('request_failed');
}
if (!preg_match('/^\+994(10|50|51|55|60|70|77|99)\d{7}$/', $payload['phone'])) {
    $redirect('request_failed');
}
if ($payload['email'] !== '' && filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false) {
    $redirect('request_failed');
}

// Google reCAPTCHA (secret təyin olunubsa məcburidir)
$recaptchaSecret = app_environment_secret('RECAPTCHA_SECRET');
if ($recaptchaSecret !== '') {
    if (!function_exists('curl_init')) {
        $redirect('gateway_unavailable');
    }
    $captcha = trim((string) ($_POST['g-recaptcha-response'] ?? ''));
    if ($captcha === '') {
        $redirect('captcha_failed');
    }
    $captchaRequest = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($captchaRequest, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => $recaptchaSecret,
            'response' => $captcha,
            'remoteip' => app_client_ip(),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $captchaBody = curl_exec($captchaRequest);
    $captchaStatus = (int) curl_getinfo($captchaRequest, CURLINFO_RESPONSE_CODE);
    curl_close($captchaRequest);
    $captchaResult = is_string($captchaBody) ? json_decode($captchaBody, true) : [];
    if ($captchaStatus !== 200 || empty($captchaResult['success'])) {
        $redirect('captcha_failed');
    }
}

/**
 * Ehtiyat bildiriş: mgt əlçatmaz olanda müraciət Telegram-a düşsün ki, itməsin.
 * Bu, mgt-ni əvəz etmir — komanda həmin müraciəti mgt-ə manual əlavə etməlidir.
 */
$telegramFallback = static function (array $payload, string $reason): bool {
    $token = app_config('telegram_token');
    $chatId = app_config('telegram_chat_id');
    if ($token === '' || $chatId === '' || !function_exists('curl_init')) {
        return false;
    }
    $text = implode("\n", [
        '⚠️ Yeni sayt müraciəti (mgt-ə yazıla bilmədi — MANUAL əlavə et!)',
        'Səbəb: ' . $reason,
        'Ad və soyad: ' . $payload['full_name'],
        'Telefon: ' . $payload['phone'],
        'E-mail: ' . ($payload['email'] !== '' ? $payload['email'] : 'Qeyd edilməyib'),
        'Xidmət: ' . $payload['service_type'],
        'Ərazi: ' . $payload['area'],
        'Qeyd: ' . $payload['message'],
        'Tarix: ' . date('d.m.Y H:i:s'),
    ]);
    $req = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($req, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => 'true',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($req);
    $status = (int) curl_getinfo($req, CURLINFO_RESPONSE_CODE);
    curl_close($req);
    $decoded = is_string($body) ? json_decode($body, true) : [];
    return $status === 200 && is_array($decoded) && !empty($decoded['ok']);
};

// Əsas yol: mgt lead gateway → ticket (analiz/iş) + Telegram bildirişi.
$gatewayUrl = app_config('mgs_lead_gateway_url', 'https://mgt.superoptic.net/api/public/website-lead.php');
$keyId = app_environment_secret('PUBLIC_SITE_INGRESS_KEY_ID');
$secret = app_environment_secret('PUBLIC_SITE_INGRESS_SECRET');
if ($gatewayUrl === '' || $keyId === '' || strlen($secret) < 32 || !function_exists('curl_init')) {
    error_log('Public website lead gateway is not configured.');
    if ($telegramFallback($payload, 'gateway_not_configured')) {
        $redirect('request_sent');
    }
    $redirect('gateway_unavailable');
}

$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($body)) {
    $redirect('request_failed');
}
$timestamp = (string) time();
$nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$signature = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body), $secret);

$request = curl_init($gatewayUrl);
curl_setopt_array($request, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Superoptic-Key-Id: ' . $keyId,
        'X-Superoptic-Timestamp: ' . $timestamp,
        'X-Superoptic-Nonce: ' . $nonce,
        'X-Superoptic-Signature: ' . $signature,
    ],
]);
$responseBody = curl_exec($request);
$responseStatus = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
$transportError = curl_error($request);
curl_close($request);

$response = is_string($responseBody) ? json_decode($responseBody, true) : [];
if ($responseStatus === 202 && is_array($response) && !empty($response['ok'])) {
    // mgt ticket yaratdı və Telegram bildirişini göndərdi.
    $redirect('request_sent');
}

// mgt uğursuz oldu — müraciəti itirməmək üçün ehtiyat Telegram bildirişi.
error_log('Public website lead gateway failed. status=' . $responseStatus . ' transport=' . $transportError);
if ($telegramFallback($payload, 'gateway_http_' . $responseStatus)) {
    $redirect('request_sent');
}
$redirect('gateway_unavailable');
