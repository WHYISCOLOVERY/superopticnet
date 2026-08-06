<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

// Sadə qoruma — yalnız bu açarla açılır. Testdən sonra bu faylı SİL.
if (($_GET['key'] ?? '') !== 'sotdiag2026') {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: text/plain; charset=UTF-8');

$mask = static function ($s): string {
    $s = (string) $s;
    if ($s === '') {
        return '(BOŞ!)';
    }
    return substr($s, 0, 6) . '...' . substr($s, -4) . ' (uzunluq ' . strlen($s) . ')';
};

$token   = app_config('telegram_token');
$chatId  = app_config('telegram_chat_id');
$gateway = app_config('mgs_lead_gateway_url');
$keyId   = app_environment_secret('PUBLIC_SITE_INGRESS_KEY_ID');
$secret  = app_environment_secret('PUBLIC_SITE_INGRESS_SECRET');

echo "==================== KONFIQ ====================\n";
echo "telegram_token:       " . $mask($token) . "\n";
echo "telegram_chat_id:     " . ($chatId !== '' ? $chatId : '(BOŞ!)') . "\n";
echo "mgs_lead_gateway_url: " . ($gateway !== '' ? $gateway : '(BOŞ!)') . "\n";
echo "ingress_key_id:       " . ($keyId !== '' ? $keyId : '(BOŞ!)') . "\n";
echo "ingress_secret:       " . $mask($secret) . "\n";
echo "curl mövcud:          " . (function_exists('curl_init') ? 'BƏLİ' : 'XEYR!') . "\n\n";

if (!function_exists('curl_init')) {
    exit("curl yoxdur — hostinqdə curl aktiv deyil!\n");
}

$call = static function (string $url, ?array $post = null): array {
    $c = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true];
    if ($post !== null) {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = is_string($post['_raw'] ?? null) ? $post['_raw'] : http_build_query($post);
        if (isset($post['_headers'])) {
            $opt[CURLOPT_HTTPHEADER] = $post['_headers'];
        }
    }
    curl_setopt_array($c, $opt);
    $body = curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $err = curl_error($c);
    curl_close($c);
    return ['status' => $status, 'error' => $err, 'body' => is_string($body) ? $body : ''];
};

echo "==== TEST 1: api.telegram.org/getMe (outbound çıxış yoxlaması) ====\n";
$r = $call("https://api.telegram.org/bot{$token}/getMe");
echo "HTTP: {$r['status']} | curl xəta: " . ($r['error'] ?: '(yox)') . "\n";
echo "cavab: " . substr($r['body'], 0, 300) . "\n\n";

echo "==== TEST 2: sendMessage (chat id-ə TEST mesajı) ====\n";
$r = $call("https://api.telegram.org/bot{$token}/sendMessage", [
    'chat_id' => $chatId,
    'text' => "✅ Super Optik diaqnostika testi — " . date('d.m.Y H:i:s'),
]);
echo "HTTP: {$r['status']} | curl xəta: " . ($r['error'] ?: '(yox)') . "\n";
echo "cavab: " . substr($r['body'], 0, 500) . "\n\n";

echo "==== TEST 3: mgt gateway ({$gateway}) ====\n";
if ($gateway !== '') {
    $r = $call($gateway, ['_raw' => '{}', '_headers' => ['Content-Type: application/json', 'Accept: application/json']]);
    echo "HTTP: {$r['status']} | curl xəta: " . ($r['error'] ?: '(yox)') . "\n";
    echo "cavab: " . substr($r['body'], 0, 300) . "\n";
    echo "(qeyd: imzasız {} göndərdik — 401/authentication_failed NORMALDIR, əsas odur ki, çatır)\n";
} else {
    echo "(gateway URL boşdur)\n";
}

echo "\n==================== BİTDİ ====================\n";
echo "BU FAYLI İNDİ SİL: public_html/test-connectivity.php\n";
