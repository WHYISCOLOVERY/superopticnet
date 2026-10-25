<?php
declare(strict_types=1);

function app_public_private_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $configuredPath = trim((string) (getenv('SUPEROPTIC_PUBLIC_CONFIG') ?: ''));
    $candidates = array_filter([
        $configuredPath,
        dirname(__DIR__) . '/superoptic-private/public-site.local.php',
    ]);
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $loaded = require $path;
        if (is_array($loaded)) {
            return $config = $loaded;
        }
    }

    return $config = [];
}

function app_config(string $key, string $default = ''): string
{
    $config = app_public_private_config();
    $value = $config[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
}

function app_environment_secret(string $key): string
{
    if (preg_match('/^[A-Z][A-Z0-9_]{2,127}$/', $key) !== 1) {
        return '';
    }
    $value = getenv($key);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }
    // Fallback: shared-hosting mühitində env dəyişəni təyin etmək çətindir, ona görə
    // sirri web-root xaricindəki private config faylından da oxumağa icazə veririk.
    $config = app_public_private_config();
    $fileValue = $config[$key] ?? '';
    return is_scalar($fileValue) ? trim((string) $fileValue) : '';
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean_text(mixed $value): string
{
    $value = strip_tags(trim((string) $value));
    return trim((string) preg_replace('/\s+/u', ' ', $value));
}

function normalize_phone(mixed $value): string
{
    $digits = preg_replace('/\D+/', '', (string) $value) ?: '';
    if (strlen($digits) === 10 && $digits[0] === '0') {
        $digits = '994' . substr($digits, 1);
    } elseif (strlen($digits) === 9) {
        $digits = '994' . $digits;
    }
    return str_starts_with($digits, '994') ? '+' . $digits : '';
}

function app_request_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return in_array($https, ['1', 'on'], true) || $forwarded === 'https';
}

function app_client_ip(): string
{
    $candidate = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : '0.0.0.0';
}

function app_session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('superoptic_public');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => app_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function app_asset(string $path): string
{
    $absolute = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    return is_file($absolute) ? $path . '?v=' . filemtime($absolute) : $path;
}

function app_supported_langs(): array
{
    return ['az', 'en', 'ru'];
}

function app_lang(): string
{
    static $lang = null;
    if (is_string($lang)) {
        return $lang;
    }

    $candidate = strtolower(clean_text($_GET['lang'] ?? ($_COOKIE['site_lang'] ?? 'az')));
    $lang = in_array($candidate, app_supported_langs(), true) ? $candidate : 'az';
    if (!headers_sent()) {
        setcookie('site_lang', $lang, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => app_request_is_https(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
    return $lang;
}

function app_csrf_token(): string
{
    app_session_boot();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function app_verify_csrf(?string $token): bool
{
    app_session_boot();
    $stored = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && is_string($stored) && hash_equals($stored, $token);
}

function app_flash_message(): ?array
{
    $messages = [
        'request_sent' => ['type' => 'success', 'message' => 'Müraciətiniz qəbul edildi. Komandamız sizinlə qısa zamanda əlaqə saxlayacaq.'],
        'request_failed' => ['type' => 'error', 'message' => 'Müraciət göndərilmədi. Məlumatları yoxlayıb yenidən cəhd edin.'],
        'captcha_failed' => ['type' => 'error', 'message' => 'Robot yoxlaması tamamlanmadı.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Səhifənin təhlükəsizlik müddəti bitib. Səhifəni yeniləyin.'],
        'rate_limited' => ['type' => 'error', 'message' => 'Çox sayda cəhd edildi. Bir neçə dəqiqə sonra yenidən yoxlayın.'],
        'gateway_unavailable' => ['type' => 'error', 'message' => 'Müraciət xidməti müvəqqəti əlçatan deyil. Telefonla əlaqə saxlaya bilərsiniz.'],
    ];
    $status = clean_text($_GET['status'] ?? '');
    return $messages[$status] ?? null;
}

function app_public_storage_dir(): string
{
    $configured = app_config('storage_path');
    $path = $configured !== '' ? $configured : dirname(__DIR__) . '/superoptic-private/data';
    if (!is_dir($path)) {
        @mkdir($path, 0700, true);
    }
    if (is_dir($path)) {
        @chmod($path, 0700);
    }
    // Konfiqurasiya edilmiş qovluq yazıla bilmirsə (məs. yanlış icazə), sistem temp-ə keç.
    if (!is_writable($path)) {
        $fallback = sys_get_temp_dir() . '/superoptic-public';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0700, true);
        }
        if (is_dir($fallback)) {
            @chmod($fallback, 0700);
        }
        if (is_writable($fallback)) {
            return $fallback;
        }
    }
    return $path;
}

function app_public_rate_limit(string $scope, int $limit, int $windowSeconds, bool $hit = false): bool
{
    $path = app_public_storage_dir() . '/rate-limits.json';
    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) {
        // Storage əlçatmazdırsa formanı bloklama (fail-open) — CSRF+reCAPTCHA onsuz da qoruyur.
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }
        $raw = stream_get_contents($handle);
        $rows = is_string($raw) ? json_decode($raw, true) : [];
        $rows = is_array($rows) ? $rows : [];
        $now = time();
        foreach ($rows as $key => $row) {
            if (!is_array($row) || (int) ($row['expires_at'] ?? 0) <= $now) {
                unset($rows[$key]);
            }
        }
        $key = hash('sha256', $scope);
        $row = is_array($rows[$key] ?? null) ? $rows[$key] : ['count' => 0, 'expires_at' => $now + $windowSeconds];
        $blocked = (int) ($row['count'] ?? 0) >= $limit;
        if ($hit && !$blocked) {
            $row['count'] = (int) ($row['count'] ?? 0) + 1;
            $rows[$key] = $row;
        }
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '{}');
        fflush($handle);
        flock($handle, LOCK_UN);
        return $blocked;
    } finally {
        fclose($handle);
    }
}

function app_azerbaijan_districts(): array
{
    return [
        'Abşeron', 'Ağcabədi', 'Ağdam', 'Ağdaş', 'Ağstafa', 'Ağsu', 'Astara', 'Babək', 'Balakən', 'Bakı',
        'Bərdə', 'Beyləqan', 'Biləsuvar', 'Cəbrayıl', 'Cəlilabad', 'Daşkəsən', 'Füzuli', 'Gədəbəy', 'Gəncə',
        'Goranboy', 'Göyçay', 'Göygöl', 'Hacıqabul', 'İmişli', 'İsmayıllı', 'Kəlbəcər', 'Kürdəmir', 'Qax',
        'Qazax', 'Qəbələ', 'Qobustan', 'Quba', 'Qubadlı', 'Qusar', 'Laçın', 'Lerik', 'Lənkəran', 'Masallı',
        'Mingəçevir', 'Naftalan', 'Naxçıvan', 'Neftçala', 'Oğuz', 'Ordubad', 'Saatlı', 'Sabirabad', 'Salyan',
        'Samux', 'Şabran', 'Şahbuz', 'Şamaxı', 'Şəki', 'Şəmkir', 'Şərur', 'Şirvan', 'Siyəzən', 'Sumqayıt',
        'Tərtər', 'Tovuz', 'Ucar', 'Xaçmaz', 'Xankəndi', 'Xırdalan', 'Xızı', 'Xocalı', 'Xocavənd', 'Yardımlı',
        'Yevlax', 'Zaqatala', 'Zəngilan', 'Zərdab',
    ];
}

function app_record_area_interest(string $district): bool
{
    $district = clean_text($district);
    if (!in_array($district, app_azerbaijan_districts(), true)) {
        return false;
    }
    $line = json_encode([
        'district' => $district,
        'created_at' => date(DATE_ATOM),
        'month' => date('Y-m'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($line)
        && file_put_contents(app_public_storage_dir() . '/area-interest-log.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}

function app_area_interest_monthly_summary(int $limit = 4): array
{
    $path = app_public_storage_dir() . '/area-interest-log.jsonl';
    if (!is_file($path)) {
        return ['total' => 0, 'items' => []];
    }
    $rows = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $counts = [];
    foreach ($rows as $row) {
        $decoded = json_decode($row, true);
        if (!is_array($decoded) || ($decoded['month'] ?? '') !== date('Y-m')) {
            continue;
        }
        $district = clean_text($decoded['district'] ?? '');
        if ($district !== '') {
            $counts[$district] = ($counts[$district] ?? 0) + 1;
        }
    }
    arsort($counts);
    $items = [];
    foreach (array_slice($counts, 0, max(1, $limit), true) as $district => $count) {
        $items[] = ['district' => $district, 'count' => $count];
    }
    return ['total' => array_sum($counts), 'items' => $items];
}

function app_tv_channels(): array
{
    $path = __DIR__ . '/data/tv-channels.json';
    $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    return is_array($decoded) ? $decoded : [];
}

function app_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header(
        "Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; " .
        "object-src 'none'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://www.google.com https://www.gstatic.com; " .
        "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; " .
        "font-src 'self' data: https://cdnjs.cloudflare.com; connect-src 'self' https://www.google.com https://www.gstatic.com; " .
        "frame-src 'self' https://www.google.com https://www.gstatic.com"
    );
    if (app_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

date_default_timezone_set(app_config('timezone', 'Asia/Baku'));
app_send_security_headers();
