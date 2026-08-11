<?php
declare(strict_types=1);

function app_load_environment_file(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $candidates = [];
    $override = getenv('SUPEROPTIC_SECRET_ENV_FILE');
    if (is_string($override) && trim($override) !== '') {
        $candidates[] = trim($override);
    }
    $candidates[] = __DIR__ . '/.env';

    foreach ($candidates as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }
            $key = strtoupper(trim(substr($line, 0, $separator)));
            if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/', $key) !== 1) {
                continue;
            }
            if (getenv($key) !== false) {
                continue;
            }
            $value = trim(substr($line, $separator + 1));
            $length = strlen($value);
            if ($length >= 2
                && (($value[0] === '"' && $value[$length - 1] === '"')
                    || ($value[0] === "'" && $value[$length - 1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
        break;
    }
}

app_load_environment_file();

function app_local_config(): array
{
    static $config;

    if ($config !== null) {
        return $config;
    }

    $file = __DIR__ . '/config.local.php';
    $config = file_exists($file) ? (array) require $file : [];

    return $config;
}

function app_public_runtime_settings_path(): string
{
    return __DIR__ . '/data/system-settings.json';
}

function app_public_runtime_settings(?string $path = null): array
{
    static $cache = [];
    $path = $path ?? app_public_runtime_settings_path();
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }
    if (!is_file($path)) {
        return $cache[$path] = [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $cache[$path] = [];
    }
    $allowed = [];
    if (isset($decoded['app_country_code']) && preg_match('/^[A-Z]{2}$/', (string) $decoded['app_country_code']) === 1) {
        $allowed['app_country_code'] = (string) $decoded['app_country_code'];
    }
    if (isset($decoded['app_timezone'])) {
        try {
            new DateTimeZone((string) $decoded['app_timezone']);
            $allowed['app_timezone'] = (string) $decoded['app_timezone'];
        } catch (Throwable) {
        }
    }
    return $cache[$path] = $allowed;
}

function app_write_public_runtime_settings(array $settings, ?string $path = null): bool
{
    $path = $path ?? app_public_runtime_settings_path();
    $country = strtoupper(trim((string) ($settings['app_country_code'] ?? '')));
    $timezone = trim((string) ($settings['app_timezone'] ?? ''));
    if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
        return false;
    }
    try {
        new DateTimeZone($timezone);
    } catch (Throwable) {
        return false;
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }
    $payload = json_encode([
        'app_country_code' => $country,
        'app_timezone' => $timezone,
        'updated_at' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($payload) && file_put_contents($path, $payload . PHP_EOL, LOCK_EX) !== false;
}

function app_setting(string $key, mixed $default = null): mixed
{
    $envKey = strtoupper($key);
    $envValue = getenv($envKey);
    if ($envValue !== false && $envValue !== '') {
        return $envValue;
    }

    $runtime = app_public_runtime_settings();
    if (array_key_exists($key, $runtime)) {
        return $runtime[$key];
    }

    $local = app_local_config();
    if (array_key_exists($key, $local)) {
        return $local[$key];
    }

    return $default;
}

function app_config(string $key, string $default = ''): string
{
    $value = app_setting($key, $default);
    return is_string($value) ? $value : $default;
}

function app_environment_name(): string
{
    return strtolower(trim(app_config('app_environment', 'production')));
}

function app_demo_fixtures_enabled(): bool
{
    if (!in_array(app_environment_name(), ['development', 'dev', 'local', 'test'], true)) {
        return false;
    }

    $value = strtolower(trim((string) app_setting('app_demo_fixtures_enabled', '0')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function app_request_correlation_id(): string
{
    static $correlationId = null;
    if (is_string($correlationId)) {
        return $correlationId;
    }

    $candidate = trim((string) ($_SERVER['HTTP_X_CORRELATION_ID'] ?? $_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    $candidate = (string) preg_replace('/[^A-Za-z0-9._:-]+/', '', $candidate);
    if ($candidate === '') {
        $candidate = 'req-' . date('YmdHis') . '-' . bin2hex(random_bytes(6));
    }

    return $correlationId = substr($candidate, 0, 96);
}

function app_runtime_error_reference(string $scope, ?Throwable $exception = null, array $context = []): string
{
    $reference = app_request_correlation_id();
    $safeContext = [];
    foreach ($context as $key => $value) {
        if (!is_scalar($value) && $value !== null) {
            continue;
        }
        $safeKey = (string) preg_replace('/[^a-z0-9_.-]+/i', '_', (string) $key);
        $safeContext[$safeKey] = is_string($value) ? mb_substr($value, 0, 191) : $value;
    }

    error_log((string) json_encode([
        'event' => 'runtime_error',
        'scope' => mb_substr($scope, 0, 128),
        'correlation_id' => $reference,
        'exception' => $exception !== null ? get_class($exception) : null,
        'exception_code' => $exception !== null ? (string) $exception->getCode() : null,
        'context' => $safeContext,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return $reference;
}

function app_environment_secret(string $envKey): string
{
    $envKey = strtoupper(trim($envKey));
    if (preg_match('/^[A-Z][A-Z0-9_]{2,127}$/', $envKey) !== 1) {
        return '';
    }
    $value = getenv($envKey, true);
    if ($value === false) {
        $value = getenv($envKey);
    }
    return is_string($value) ? trim($value) : '';
}

function app_timezone(): string
{
    static $timezone = null;
    if (is_string($timezone)) {
        return $timezone;
    }

    $candidate = trim(app_config('app_timezone', 'Asia/Baku'));
    if ($candidate === '') {
        $candidate = 'Asia/Baku';
    }
    try {
        new DateTimeZone($candidate);
        $timezone = $candidate;
    } catch (Throwable) {
        $timezone = 'Asia/Baku';
    }

    date_default_timezone_set($timezone);
    return $timezone;
}

function app_timezone_offset(): string
{
    $zone = new DateTimeZone(app_timezone());
    $seconds = $zone->getOffset(new DateTimeImmutable('now', $zone));
    $sign = $seconds < 0 ? '-' : '+';
    $minutes = (int) floor(abs($seconds) / 60);
    return sprintf('%s%02d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
}

app_timezone();

function app_asset(string $path): string
{
    $absolute = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!file_exists($absolute)) {
        return $path;
    }

    return $path . '?v=' . filemtime($absolute);
}

function app_supported_langs(): array
{
    return ['az', 'en', 'ru'];
}

function app_lang(): string
{
    static $lang = null;

    if ($lang !== null) {
        return $lang;
    }

    $requested = $_GET['lang'] ?? ($_COOKIE['site_lang'] ?? 'az');
    $requested = is_string($requested) ? strtolower(trim($requested)) : 'az';
    $lang = in_array($requested, app_supported_langs(), true) ? $requested : 'az';

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

function app_translations(): array
{
    static $translations = [];
    $lang = app_lang();

    if (isset($translations[$lang])) {
        return $translations[$lang];
    }

    $file = __DIR__ . '/lang/' . $lang . '.json';
    if (!file_exists($file)) {
        $translations[$lang] = [];
        return $translations[$lang];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    $translations[$lang] = is_array($decoded) ? $decoded : [];
    return $translations[$lang];
}

function app_t(string $key, string $fallback = ''): string
{
    $translations = app_translations();
    $value = $translations[$key] ?? null;
    return is_string($value) && $value !== '' ? $value : $fallback;
}

function app_repair_mojibake(string $value): string
{
    if ($value === '') {
        return $value;
    }

    return strtr($value, [
        'â†“' => '↓',
        'â†‘' => '↑',
        'â‚¼' => '₼',
        'â„–' => '№',
        'â€œ' => '"',
        'â€' => '"',
        'â€˜' => "'",
        'â€™' => "'",
        'â€“' => '-',
        'â€”' => '-',
        'ÃƒÂ¼' => 'ü',
        'ÃƒÅ“' => 'Ü',
        'ÃƒÂ¶' => 'ö',
        'Ãƒâ€“' => 'Ö',
        'ÃƒÂ§' => 'ç',
        'Ãƒâ€¡' => 'Ç',
        'Ã…Å¸' => 'ş',
        'Ã…Â' => 'Ş',
        'Ã‰â„¢' => 'ə',
        'Ã†Â' => 'Ə',
        'Ã„Â±' => 'ı',
        'Ã„Â°' => 'İ',
        'Ã„Å¸' => 'ğ',
        'Ã„Å¾' => 'Ğ',
        'Ã¼' => 'ü',
        'Ãœ' => 'Ü',
        'Ã¶' => 'ö',
        'Ã–' => 'Ö',
        'Ã§' => 'ç',
        'Ã‡' => 'Ç',
        'ÅŸ' => 'ş',
        'Å' => 'Ş',
        'É™' => 'ə',
        'Æ' => 'Ə',
        'Ä±' => 'ı',
        'Ä°' => 'İ',
        'ÄŸ' => 'ğ',
        'Äž' => 'Ğ',
        'Â°' => '°',
        'Â±' => '±',
        'Â' => '',
        'Aktiv istifadəçilÉ™r vÉ™ sessiyalar' => 'Aktiv istifadəçilər və sessiyalar',
        'Referans sistemdÉ™ki aktiv list modeli: username, IP, MAC, VLAN/status, 24 saat trafik, sessiya vÉ™ disconnect É™mÉ™liyyatına hazır görünüş.' => 'Referans sistemdəki aktiv list modeli: username, IP, MAC, VLAN/status, 24 saat trafik, sessiya və disconnect əməliyyatına hazır görünüş.',
        'MüştÉ™ri siyahısı' => 'Müştəri siyahısı',
        'NAT log axtarışı' => 'NAT log axtarışı',
        'radacct açıq sessiyalar' => 'radacct açıq sessiyalar',
        'CÉ™mi istifadəçi' => 'Cəmi istifadəçi',
        'Hazırda online' => 'Hazırda online',
        'Top trafik istifadəçilÉ™ri' => 'Top trafik istifadəçiləri',
        'Son 24 saat üçün download + upload xülasÉ™si.' => 'Son 24 saat üçün download + upload xülasəsi.',
        'Son 24 saat üçün RADIUS accounting trafiki tapılmadı.' => 'Son 24 saat üçün RADIUS accounting trafiki tapılmadı.',
        'NAS vÉ™ status paylanması' => 'NAS və status paylanması',
        'Aktiv list nÉ™ticÉ™sinÉ™ görÉ™ operativ bölünmÉ™.' => 'Aktiv list nəticəsinə görə operativ bölünmə.',
        'Axtarış vÉ™ filtr' => 'Axtarış və filtr',
        'İstifadəçi, IP, MAC, telefon vÉ™ müştÉ™ri adı üzrÉ™ axtar.' => 'İstifadəçi, IP, MAC, telefon və müştəri adı üzrə axtar.',
        'Axtarış' => 'Axtarış',
        'Hamısı' => 'Hamısı',
        'Dayandırılıb' => 'Dayandırılıb',
        'Sıfırla' => 'Sıfırla',
        'Disconnect sorÄŸuları' => 'Disconnect sorğuları',
        'Bu siyahı audit üçündür. Sistem buradan canlı CoA vÉ™ ya router disconnect É™mri göndÉ™rmir.' => 'Bu siyahı audit üçündür. Sistem buradan canlı CoA və ya router disconnect əmri göndərmir.',
        'Admin/NOC icazÉ™si var' => 'Admin/NOC icazəsi var',
        'Admin/NOC icazÉ™si lazımdır' => 'Admin/NOC icazəsi lazımdır',
        'Disconnect sorÄŸusu yoxdur' => 'Disconnect sorğusu yoxdur',
        'Online sessiyadan sorÄŸu yaradıldıqda burada görünÉ™cÉ™k.' => 'Online sessiyadan sorğu yaradıldıqda burada görünəcək.',
        'MüştÉ™ri' => 'Müştəri',
        'SÉ™bÉ™b' => 'Səbəb',
        'İstifadəçi siyahısı' => 'İstifadəçi siyahısı',
        'nÉ™ticÉ™ göstÉ™rilir.' => 'nəticə göstərilir.',
        'Radius istifadəçisi tapılmadı' => 'Radius istifadəçisi tapılmadı',
        'Axtarış vÉ™ filtr ÅŸÉ™rtlÉ™rinÉ™ uyÄŸun sessiya vÉ™ ya servis yoxdur.' => 'Axtarış və filtr şərtlərinə uyğun sessiya və ya servis yoxdur.',
        'ƏmÉ™liyyat' => 'Əməliyyat',
        'Ətraflı' => 'Ətraflı',
        'Disconnect sorÄŸusu' => 'Disconnect sorğusu',
    ]);
}

function app_repair_mojibake_dynamic(string $value): string
{
    if ($value === '' || !preg_match('/[\x{00C3}\x{00C5}\x{00C9}]/u', $value)) {
        return $value;
    }

    if (!function_exists('mb_convert_encoding') || !function_exists('mb_check_encoding')) {
        return $value;
    }

    $noise = static function (string $text): int {
        return (preg_match_all('/[\x{00C2}\x{00C3}\x{00C5}\x{00C9}]/u', $text) ?: 0)
            + substr_count($text, '�')
            + substr_count($text, '?');
    };

    $result = $value;
    $currentNoise = $noise($result);
    for ($index = 0; $index < 3; $index++) {
        $candidate = @mb_convert_encoding($result, 'Windows-1252', 'UTF-8');
        if (!is_string($candidate) || $candidate === '' || !mb_check_encoding($candidate, 'UTF-8')) {
            break;
        }
        if ($candidate === $result) {
            break;
        }

        $candidateNoise = $noise($candidate);
        if ($candidateNoise >= $currentNoise) {
            break;
        }

        $result = $candidate;
        $currentNoise = $candidateNoise;
        if ($currentNoise === 0) {
            break;
        }
    }

    return $result;
}

function e(string $value): string
{
    $value = app_repair_mojibake_dynamic(app_repair_mojibake($value));
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function app_start_html_text_repair(): void
{
    static $started = false;

    if ($started || PHP_SAPI === 'cli') {
        return;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($script, '/api/')) {
        return;
    }

    $started = true;
    ob_start(static function (string $buffer): string {
        return app_repair_mojibake_dynamic(app_repair_mojibake($buffer));
    });
}

function clean_text(string $value): string
{
    return trim(strip_tags($value));
}

function clean_login_identifier(string $value): string
{
    return trim(strip_tags($value));
}

function clean_password_input(string $value): string
{
    return trim($value);
}

function normalize_phone(string $value): string
{
    return preg_replace('/[^\d\+]/', '', $value) ?? '';
}

require_once __DIR__ . '/db.php';

function app_session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_start([
        'cookie_secure' => app_request_is_https(),
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'cookie_lifetime' => 0,
    ]);
}

function app_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $proto === 'https';
}

function app_request_host(): string
{
    $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = trim(explode(',', $host)[0] ?? 'localhost');
    if (str_contains($host, ':')) {
        [$host] = explode(':', $host, 2);
    }

    return strtolower(trim($host)) ?: 'localhost';
}

function app_request_is_localhost(): bool
{
    return in_array(app_request_host(), ['localhost', '127.0.0.1', '::1'], true);
}

function app_enforce_https(bool $enabled = true): void
{
    if (!$enabled || app_request_is_https() || app_request_is_localhost() || headers_sent()) {
        return;
    }

    $host = app_request_host();
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

function app_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }

        $parts = array_map('trim', explode(',', $candidate));
        foreach ($parts as $part) {
            if (filter_var($part, FILTER_VALIDATE_IP)) {
                return $part;
            }
        }
    }

    return '0.0.0.0';
}

function app_send_security_headers(): void
{
    static $sent = false;

    if ($sent || headers_sent()) {
        return;
    }

    $sent = true;

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!str_contains($script, '/api/')) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()');
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "base-uri 'self'; " .
        "form-action 'self'; " .
        "frame-ancestors 'self'; " .
        "object-src 'none'; " .
        "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://www.google.com https://www.gstatic.com; " .
        "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
        "img-src 'self' data: https:; " .
        "font-src 'self' data: https://cdnjs.cloudflare.com; " .
        "connect-src 'self' https://www.google.com https://www.gstatic.com https://api.telegram.org; " .
        "frame-src 'self' http://127.0.0.1:3000 http://localhost:3000 https://www.google.com https://www.gstatic.com"
    );

    if (app_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

function app_csrf_token(): string
{
    app_session_boot();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function app_verify_csrf(?string $token): bool
{
    app_session_boot();
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    return is_string($token) && $token !== '' && is_string($sessionToken) && hash_equals($sessionToken, $token);
}

function app_request_csrf_token(): ?string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($headerToken) && trim($headerToken) !== '') {
        return trim($headerToken);
    }

    $postToken = $_POST['csrf_token'] ?? '';
    if (is_string($postToken) && trim($postToken) !== '') {
        return trim($postToken);
    }

    return null;
}

function app_rate_limit_file(): string
{
    return __DIR__ . '/data/rate-limits.json';
}

function app_rate_limit_read(): array
{
    $file = app_rate_limit_file();
    if (!file_exists($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function app_rate_limit_write(array $data): void
{
    $file = app_rate_limit_file();
    $directory = dirname($file);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function app_rate_limit_is_blocked(string $scope, int $limit, int $windowSeconds): bool
{
    $state = app_rate_limit_read();
    $now = time();
    $hits = array_values(array_filter(
        $state[$scope] ?? [],
        static fn ($timestamp): bool => is_int($timestamp) && $timestamp > ($now - $windowSeconds)
    ));

    if ($hits !== ($state[$scope] ?? [])) {
        $state[$scope] = $hits;
        app_rate_limit_write($state);
    }

    return count($hits) >= $limit;
}

function app_rate_limit_hit(string $scope, int $windowSeconds): void
{
    $state = app_rate_limit_read();
    $now = time();
    $hits = array_values(array_filter(
        $state[$scope] ?? [],
        static fn ($timestamp): bool => is_int($timestamp) && $timestamp > ($now - $windowSeconds)
    ));
    $hits[] = $now;
    $state[$scope] = $hits;
    app_rate_limit_write($state);
}

function app_rate_limit_clear(string $scope): void
{
    $state = app_rate_limit_read();
    if (!array_key_exists($scope, $state)) {
        return;
    }

    unset($state[$scope]);
    app_rate_limit_write($state);
}

function app_rate_limit_scope_hits(string $scope, int $windowSeconds): int
{
    $state = app_rate_limit_read();
    $now = time();
    $hits = array_values(array_filter(
        $state[$scope] ?? [],
        static fn ($timestamp): bool => is_int($timestamp) && $timestamp > ($now - $windowSeconds)
    ));

    return count($hits);
}

function app_rate_limit_retry_after(string $scope, int $windowSeconds): int
{
    $state = app_rate_limit_read();
    $now = time();
    $hits = array_values(array_filter(
        $state[$scope] ?? [],
        static fn ($timestamp): bool => is_int($timestamp) && $timestamp > ($now - $windowSeconds)
    ));
    if ($hits === []) {
        return 0;
    }

    sort($hits);
    $oldest = (int) $hits[0];
    return max(0, ($oldest + $windowSeconds) - $now);
}

function app_security_event_log_path(): string
{
    return __DIR__ . '/data/security-events.jsonl';
}

function app_record_security_event(array $event): bool
{
    $path = app_security_event_log_path();
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $payload = [
        'event' => (string) ($event['event'] ?? 'unknown'),
        'status' => (string) ($event['status'] ?? 'info'),
        'role' => (string) ($event['role'] ?? ''),
        'login' => (string) ($event['login'] ?? ''),
        'ip' => app_client_ip(),
        'host' => app_request_host(),
        'created_at' => date('c'),
        'meta' => is_array($event['meta'] ?? null) ? $event['meta'] : [],
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        return false;
    }

    return file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}

function http_post(string $url, array $data, array $headers = []): bool
{
    $result = http_request(
        $url,
        $data,
        array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
        false
    );

    return $result['ok'];
}

function http_post_json(string $url, array $data, array $headers = []): ?array
{
    $contentType = 'Content-Type: application/json';
    $normalizedHeaders = $headers !== [] ? $headers : [$contentType];
    $isJsonRequest = in_array($contentType, $normalizedHeaders, true);

    $result = http_request($url, $data, $normalizedHeaders, $isJsonRequest);
    if (!$result['ok']) {
        return null;
    }

    $decoded = json_decode($result['body'], true);
    return is_array($decoded) ? $decoded : null;
}

function http_request(string $url, array $data, array $headers, bool $asJson): array
{
    $payload = $asJson
        ? (json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}')
        : http_build_query($data);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        $body = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'ok' => $body !== false && $statusCode >= 200 && $statusCode < 300,
            'body' => is_string($body) ? $body : '',
            'status' => $statusCode,
        ];
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) && ini_get('allow_url_fopen') !== '1') {
        return ['ok' => false, 'body' => '', 'status' => 0];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?: [])
        : (((isset($GLOBALS['http_response_header']) && is_array($GLOBALS['http_response_header'] ?? null))
            ? $GLOBALS['http_response_header']
            : []));
    $statusCode = http_response_code_from_headers($responseHeaders);

    return [
        'ok' => $body !== false && $statusCode >= 200 && $statusCode < 300,
        'body' => is_string($body) ? $body : '',
        'status' => $statusCode,
    ];
}

function http_request_raw(string $url, string $method = 'GET', string $body = '', array $headers = [], int $timeout = 20): array
{
    $normalizedMethod = strtoupper(trim($method));
    if ($normalizedMethod === '') {
        $normalizedMethod = 'GET';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $normalizedMethod,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(1, $timeout),
        ];
        if ($normalizedMethod !== 'GET' && $body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'ok' => $responseBody !== false && $statusCode >= 200 && $statusCode < 300,
            'body' => is_string($responseBody) ? $responseBody : '',
            'status' => $statusCode,
        ];
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) && ini_get('allow_url_fopen') !== '1') {
        return ['ok' => false, 'body' => '', 'status' => 0];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $normalizedMethod,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => max(1, $timeout),
            'ignore_errors' => true,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?: [])
        : (((isset($GLOBALS['http_response_header']) && is_array($GLOBALS['http_response_header'] ?? null))
            ? $GLOBALS['http_response_header']
            : []));
    $statusCode = http_response_code_from_headers($responseHeaders);

    return [
        'ok' => $responseBody !== false && $statusCode >= 200 && $statusCode < 300,
        'body' => is_string($responseBody) ? $responseBody : '',
        'status' => $statusCode,
    ];
}

function http_response_code_from_headers(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function app_flash_message(): ?array
{
    $status = $_GET['status'] ?? '';

    $messages = [
        'request_sent' => ['type' => 'success', 'message' => 'Muracietiniz qebul edildi. Komandamiz sizinle qisa zamanda elaqe saxlayacaq.'],
        'request_failed' => ['type' => 'error', 'message' => 'Muraciet gonderilerken problem yarandi. Zehmet olmasa melumatlari yeniden yoxlayin.'],
        'captcha_failed' => ['type' => 'error', 'message' => 'Robot yoxlamasi tamamlanmadi ve ya ugursuz oldu.'],
        'missing_config' => ['type' => 'error', 'message' => 'Server konfiqurasiyasi tamamlanmayib. Config faylini doldurmaq lazimdir.'],
        'cabinet_pending' => ['type' => 'error', 'message' => 'Billing inteqrasiyasi tam aktivlesmeyib. Portal skeletonu hazirdir, endpointler elave olunmalidir.'],
        'portal_login_failed' => ['type' => 'error', 'message' => 'Login melumatlari tesdiqlenmedi. Zehmet olmasa yeniden yoxlayin.'],
        'portal_phone_updated' => ['type' => 'success', 'message' => 'Nomre ugurla yenilendi. Billing inteqrasiyasi aktivdirse deyisiklik billingde de eks olunacaq.'],
        'portal_phone_failed' => ['type' => 'error', 'message' => 'Nomreni yenilemek olmadi. Format ve billing endpointini yoxlayin.'],
        'portal_ticket_created' => ['type' => 'success', 'message' => 'Yeni muraciet yaradildi.'],
        'portal_ticket_failed' => ['type' => 'error', 'message' => 'Muraciet yaradilmadi. Zehmet olmasa yeniden cehd edin.'],
        'portal_consent_updated' => ['type' => 'success', 'message' => 'Bildiris tercihiniz yenilendi.'],
        'portal_consent_failed' => ['type' => 'error', 'message' => 'Tercihiniz saxlanmadi. Zehmet olmasa yeniden cehd edin.'],
        'auth_required' => ['type' => 'error', 'message' => 'Davam etmek ucun portala daxil olun.'],
        'logged_out' => ['type' => 'success', 'message' => 'Portal sessiyasi baglandi.'],
        'portal_bridge_unavailable' => ['type' => 'error', 'message' => 'Kechid konfiqurasiyasi hazir deyil. OLT token ayarlari ve ya giris URL-i doldurulmalidir.'],
        'portal_bridge_forbidden' => ['type' => 'error', 'message' => 'Bu emeliyyat ucun size uygun OLT kecidi tapilmadi.'],
        'portal_billing_unavailable' => ['type' => 'error', 'message' => 'Billing kecidi konfiqurasiyasi tamamlənmayib. Billing URL ve ya billing SSO ayarlarini daxil edin.'],
    ];

    return $messages[$status] ?? null;
}

function app_news(): array
{
    $file = __DIR__ . '/data/news.json';
    if (!file_exists($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function app_coverage_areas(): array
{
    return [
        'Astara',
        'Masallı',
        'Lənkəran',
        'Neftçala',
        'İsmayıllı',
        'Nizami',
        'Sabunçu',
        'Suraxanı',
        'Abşeron',
        'Xətai',
        'Binəqədi',
        'Kürdəxanı',
        'Pirşağı',
        'Mehdiabad',
        'Məmmədli',
        'Zabrat',
        'Qaraçuxur',
        'Bakıxanov',
        'Əhmədli',
        'Biləcəri',
        'Günəşli',
    ];
}

function app_service_areas(): array
{
    $areas = array_values(array_unique(array_filter(
        app_coverage_areas(),
        static fn ($area): bool => is_string($area) && trim($area) !== ''
    )));
    sort($areas);

    return $areas;
}

function app_coverage_points(): array
{
    return [
        ['name' => 'Astara', 'top' => '88%', 'left' => '34%'],
        ['name' => 'Masallı', 'top' => '81%', 'left' => '35%'],
        ['name' => 'Lənkəran', 'top' => '84%', 'left' => '38%'],
        ['name' => 'Neftçala', 'top' => '71%', 'left' => '39%'],
        ['name' => 'İsmayıllı', 'top' => '42%', 'left' => '45%'],
        ['name' => 'Abşeron', 'top' => '52%', 'left' => '56%'],
        ['name' => 'Bakı', 'top' => '53%', 'left' => '58%'],
        ['name' => 'Suraxanı', 'top' => '54%', 'left' => '60%'],
        ['name' => 'Sabunçu', 'top' => '50%', 'left' => '61%'],
        ['name' => 'Xətai', 'top' => '56%', 'left' => '60%'],
        ['name' => 'Binəqədi', 'top' => '50%', 'left' => '57%'],
        ['name' => 'Günəşli', 'top' => '57%', 'left' => '61%'],
    ];
}

function app_highlights(): array
{
    return [
        [
            'icon' => 'fa-solid fa-gauge-high',
            'title' => '100-1000 Mb/s',
            'text' => 'Ev ve biznes ucun ferqli suret planlari',
        ],
        [
            'icon' => 'fa-solid fa-headset',
            'title' => '7/24 destek',
            'text' => 'Texniki komandadan operativ geri donus',
        ],
        [
            'icon' => 'fa-solid fa-network-wired',
            'title' => 'Sistem helleri',
            'text' => 'LAN/WAN, server, IP telefoniya ve lisenziya',
        ],
    ];
}

function app_services(): array
{
    return [
        [
            'icon' => 'fa-solid fa-house-signal',
            'title' => 'Ev interneti',
            'summary' => 'Aile ve ferdi istifade ucun suretli fiber paketler, TV ve telefon xetti secimleri.',
            'details' => 'Qosulma, modem konfiqurasiyasi, erazi uygunlugu ve odenis istiqametlendirilmesi bir axinda teqdim olunur.',
        ],
        [
            'icon' => 'fa-solid fa-building',
            'title' => 'Biznes interneti',
            'summary' => 'Ofisler ve obyektler ucun dayaniqli elaqe, prioritet destek ve ferdi teklifler.',
            'details' => 'Biznes musterileri ucun yuksek sabitlik, genislenen infrastruktur ve ehtiyaclara uygun sistem layihelendirmesi planlana biler.',
        ],
        [
            'icon' => 'fa-solid fa-diagram-project',
            'title' => 'LAN/WAN ve sebeke qurulmasi',
            'summary' => 'Daxili ve uzaq sebeke infrastrukturu ucun planlama, qurasdirma ve optimallasdirma.',
            'details' => 'Ofis daxili topologiya, filiallararasi elaqe, router ve switch konfiqurasiyasi, nasazliq analizi ve performans olcumu daxildir.',
        ],
        [
            'icon' => 'fa-solid fa-server',
            'title' => 'Server ve sistem helleri',
            'summary' => 'Fayl serveri, backup, monitorinq ve xidmetlerin merkezlesdirilmis idaresi.',
            'details' => 'Movcud sistemin auditi, yeni server yerlesdirilmesi ve emeliyyat proseslerine uygun sabit arxitektura qurula biler.',
        ],
        [
            'icon' => 'fa-solid fa-phone-volume',
            'title' => 'IP telefoniya',
            'summary' => 'Cagri yonlendirme, IVR, daxili nomreleme ve inteqrasiya olunmus telefoniya helleri.',
            'details' => 'Asterisk, FreePBX ve diger uygun hellerle elastik ve nezaret edilen telefon infrastrukturu qurmaq mumkundur.',
        ],
        [
            'icon' => 'fa-solid fa-shield-halved',
            'title' => 'Lisenziya ve tehlukesizlik',
            'summary' => 'Resmi lisenziyalar, tehlukesizlik yanasmasi ve sistem intizami ile daha etibarli is muhiti.',
            'details' => 'Microsoft, MikroTik, Ubiquiti ve diger mehsullar ucun duzgun lisenziyalasdirma ve tehlukesizlik teleblerine uygun konfiqurasiya desteyi verile biler.',
        ],
    ];
}

function app_modem_guides(): array
{
    return [
        [
            'title' => 'HSGQ modemləri',
            'summary' => 'Brauzerdə `192.168.1.1` və ya cihazın arxa hissəsində yazılan ünvanla idarəetmə panelinə daxil olun.',
            'steps' => [
                'Wireless və ya Wi-Fi bölməsini açın.',
                '2.4 GHz və 5 GHz üçün SSID adlarını ayrıca yoxlayın.',
                'Yeni şifrəni `WPA2/WPA3` formatında təyin edin və yadda saxlayın.',
                'Dəyişiklikdən sonra telefon və noutbukları yeni şifrə ilə yenidən qoşun.',
            ],
        ],
        [
            'title' => 'Huawei modemləri',
            'summary' => 'Ən çox istifadə olunan giriş ünvanı `192.168.100.1` və ya `192.168.1.1` olur.',
            'steps' => [
                'WLAN bölməsinə keçin.',
                '2.4G və 5G şəbəkələrini ayrı-ayrılıqda yoxlayın.',
                'SSID Name və WPA PreSharedKey sahələrini yeniləyin.',
                'Yadda saxladıqdan sonra modem bəzən qısa yenilənmə edə bilər.',
            ],
        ],
        [
            'title' => 'TP-Link modem və routerlər',
            'summary' => '`tplinkwifi.net` və ya `192.168.0.1` / `192.168.1.1` üzərindən idarəetməyə daxil olun.',
            'steps' => [
                'Wireless Settings və ya Basic Wireless bölməsini açın.',
                'Smart Connect aktivdirsə 2.4 və 5 GHz eyni adda görünə bilər.',
                'Wi-Fi adı və şifrəsini yeniləyin.',
                'Dəyişiklikdən sonra zəif siqnal olan otaqlarda 2.4 GHz-dən istifadə edin.',
            ],
        ],
        [
            'title' => 'BDCOM modemləri',
            'summary' => 'İdarəetmə panelində adətən WLAN və ya Network hissəsində Wi-Fi ayarları yerləşir.',
            'steps' => [
                'Mövcud SSID profilini seçin.',
                'Təhlükəsizlik növünü `WPA2-PSK` və ya `WPA2/WPA3` olaraq saxlayın.',
                'Yeni şifrəni daxil edib apply edin.',
                'Əgər TV Box ayrıca qoşulursa, TV VLAN ayarına toxunmadan yalnız Wi-Fi hissəsini dəyişin.',
            ],
        ],
    ];
}

function app_faq_items(): array
{
    return [
        [
            'question' => 'İnternet sürətim aşağıdırsa nə etməliyəm?',
            'answer' => 'Əvvəlcə modemi enerji xəttindən 10-15 saniyə ayırıb yenidən başladın. Sonra cihazı mümkün olduqda kabel ilə test edin. Wi-Fi üzərindən sürət aşağı görünürsə buna 2.4 GHz sıxlığı, qalın divarlar, köhnə telefon və ya eyni anda çox cihazın işləməsi səbəb ola bilər. Kabeldə sürət normaldırsa problem çox vaxt modem yerləşimi və ya Wi-Fi kanal sıxlığı ilə bağlı olur.',
        ],
        [
            'question' => '2.4 GHz ilə 5 GHz arasındakı fərq nədir?',
            'answer' => '2.4 GHz daha uzağa çatır və divarlardan daha rahat keçir, amma sürət baxımından daha zəif və sıx istifadə olunan diapazondur. 5 GHz isə daha yüksək sürət verir, ping daha yaxşı olur, amma məsafə artdıqca siqnal tez zəifləyir. Modemə yaxın otaqlarda 5 GHz, uzaq otaqlarda isə 2.4 GHz daha uyğun olur.',
        ],
        [
            'question' => 'Ödənişi necə edə bilərəm və gecikmə olanda nə baş verir?',
            'answer' => 'Ödənişləri onlayn ödəniş səhifəsi, bank və partnyor kanallar üzərindən etmək mümkündür. Gecikmə olduqda xətt məhdudlaşdırıla və ya dayandırıla bilər. Ödəniş etdikdən sonra bərpa bir neçə dəqiqədən qısa müddətə qədər çəkə bilər. Gecikmə davam edirsə, qəbz və ya ödəniş tarixini dəstəklə paylaşmaq lazımdır.',
        ],
        [
            'question' => 'Wi-Fi şifrəsini unutmuşamsa və ya qonşuda daha yaxşı siqnal gedirsə nə etməliyəm?',
            'answer' => 'Əvvəlcə modem stikerindəki ilkin məlumatları yoxlayın. Əgər şifrə dəyişdirilibsə, modem panelindən yeni şifrə təyin etmək daha doğrudur. Siqnal zəifdirsə modem mərkəzi nöqtəyə yerləşdirilməli, metal səthlərdən və TV arxasından uzaq tutulmalıdır. Böyük evlərdə əlavə access point və ya mesh həlli daha uyğun olur.',
        ],
    ];
}

function app_tariffs(): array
{
    return [
        ['audience' => 'home', 'filter' => 'internet', 'type' => 'Fərdi internet', 'speed' => 100, 'price' => 25, 'features' => ['Fiber optik xətt', 'Wi-Fi router dəstəyi', 'Standart quraşdırma'], 'badge' => 'Yeni qoşulma'],
        ['audience' => 'home', 'filter' => 'internet-tv', 'type' => 'Fərdi internet + TV', 'speed' => 100, 'price' => 35, 'features' => ['Fiber optik xətt', 'TV xidməti', 'TV Box dəstəyi']],
        ['audience' => 'home', 'filter' => 'internet-phone', 'type' => 'Fərdi internet + Telefon', 'speed' => 100, 'price' => 35, 'features' => ['Fiber optik xətt', 'Sabit telefon xətti', 'Daxili danışıq rahatlığı']],
        ['audience' => 'home', 'filter' => 'bundle', 'type' => 'Fərdi tam paket', 'speed' => 100, 'price' => 45, 'features' => ['İnternet', 'TV xidməti', 'Telefon xətti']],
        ['audience' => 'home', 'filter' => 'internet', 'type' => 'Fərdi internet', 'speed' => 150, 'price' => 30, 'features' => ['Fiber optik xətt', 'Wi-Fi router dəstəyi', 'Ailə istifadəsi üçün rahat sürət']],
        ['audience' => 'home', 'filter' => 'bundle', 'type' => 'Fərdi tam paket', 'speed' => 150, 'price' => 50, 'features' => ['İnternet', 'TV xidməti', 'Telefon xətti'], 'badge' => 'Ən çox seçilən'],
        ['audience' => 'home', 'filter' => 'internet', 'type' => 'Fərdi internet', 'speed' => 250, 'price' => 36, 'features' => ['4K streaming üçün uyğun', 'Wi-Fi router dəstəyi', 'Sabit fiber xətt']],
        ['audience' => 'home', 'filter' => 'bundle', 'type' => 'Fərdi tam paket', 'speed' => 250, 'price' => 56, 'features' => ['İnternet', 'TV xidməti', 'Telefon xətti']],
        ['audience' => 'business', 'filter' => 'internet', 'type' => 'Biznes internet', 'speed' => 100, 'price' => 30, 'features' => ['Ev paketindən 5 ₼ yuxarı baza', 'Prioritet dəstək', 'Ofis istifadəsi üçün sabit xətt']],
        ['audience' => 'business', 'filter' => 'internet-tv', 'type' => 'Biznes internet + TV', 'speed' => 100, 'price' => 40, 'features' => ['Baza qiymət + TV 10 ₼', 'Gözləmə zonası üçün TV', 'Fiber optik xətt']],
        ['audience' => 'business', 'filter' => 'internet-phone', 'type' => 'Biznes internet + Telefon', 'speed' => 100, 'price' => 40, 'features' => ['Baza qiymət + Telefon 10 ₼', 'Sabit əlaqə xətti', 'Ofis üçün uyğun']],
        ['audience' => 'business', 'filter' => 'bundle', 'type' => 'Biznes tam paket', 'speed' => 100, 'price' => 50, 'features' => ['İnternet 30 ₼', 'TV +10 ₼', 'Telefon +10 ₼']],
        ['audience' => 'business', 'filter' => 'internet', 'type' => 'Biznes internet', 'speed' => 150, 'price' => 35, 'features' => ['Prioritet dəstək', 'Şəbəkə üçün daha geniş resurs', 'Fiber optik xətt']],
        ['audience' => 'business', 'filter' => 'bundle', 'type' => 'Biznes tam paket', 'speed' => 150, 'price' => 55, 'features' => ['İnternet', 'TV xidməti', 'Telefon xətti']],
        ['audience' => 'business', 'filter' => 'internet', 'type' => 'Biznes internet', 'speed' => 250, 'price' => 41, 'features' => ['Ofis və kamera sistemi üçün uyğun', 'Şəbəkə dayanıqlığı', 'Prioritet xidmət']],
        ['audience' => 'business', 'filter' => 'bundle', 'type' => 'Biznes tam paket', 'speed' => 250, 'price' => 61, 'features' => ['İnternet', 'TV xidməti', 'Telefon xətti']],
    ];
}

function app_portal_mode(): string
{
    return (string) app_setting('billing_mode', 'mysql');
}

function app_portal_mock_mode_enabled(): bool
{
    return app_portal_mode() === 'mock' && app_demo_fixtures_enabled();
}

function app_portal_uses_local_db(): bool
{
    return in_array(app_portal_mode(), ['mock', 'mysql'], true);
}

function app_portal_current_user(): ?array
{
    app_session_boot();
    $user = $_SESSION['portal_user'] ?? null;
    if (!is_array($user)) {
        return null;
    }

    if (app_db_available() && (string) ($user['token'] ?? '') !== 'db-session') {
        $migrated = app_db_session_user_from_legacy($user);
        if (is_array($migrated)) {
            $_SESSION['portal_user'] = $migrated;
            $user = $migrated;
        }
    }

    app_portal_enforce_password_change($user);
    return $user;
}

function app_portal_enforce_password_change(array $user): void
{
    if (PHP_SAPI === 'cli' || empty($user['must_change_password'])) {
        return;
    }
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $script = basename($scriptPath);
    if (in_array($script, ['change-password.php', 'portal-actions.php', 'portal-login.php'], true)) {
        return;
    }
    if (str_contains($scriptPath, '/api/')) {
        http_response_code(428);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => 'password_change_required']);
        exit;
    }
    header('Location: change-password.php?status=password_change_required');
    exit;
}

function app_portal_sign_in(array $user): void
{
    app_session_boot();
    session_regenerate_id(true);
    $_SESSION['portal_user'] = $user;
}

function app_portal_sign_out(): void
{
    app_session_boot();
    unset($_SESSION['portal_user']);
    session_regenerate_id(true);
}

function app_portal_is_admin(array $user): bool
{
    $accountType = strtolower(trim((string) ($user['account_type'] ?? '')));
    $employeeRole = strtolower(trim((string) ($user['employee_role'] ?? '')));
    $normalizedRole = str_replace([' ', '-'], '_', $employeeRole);

    return $accountType === 'admin'
        || in_array($normalizedRole, ['admin', 'super_admin', 'superadmin'], true)
        || app_portal_is_super_admin($user);
}

function app_portal_is_super_admin(array $user): bool
{
    $designatedLogin = strtolower(trim(app_config('super_admin_login', 's.abdullayev@superoptic.net')));
    if ($designatedLogin === '') {
        $designatedLogin = 's.abdullayev@superoptic.net';
    }

    if (strtolower(trim((string) ($user['login'] ?? ''))) !== $designatedLogin) {
        return false;
    }

    $employeeRole = strtolower(trim((string) ($user['employee_role'] ?? '')));
    $normalizedRole = str_replace([' ', '-'], '_', $employeeRole);
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $accountType = strtolower(trim((string) ($user['account_type'] ?? '')));
    $isActive = !array_key_exists('is_active', $user) || (int) $user['is_active'] === 1;

    return $isActive
        && $role === 'employee'
        && $accountType === 'admin'
        && in_array($normalizedRole, ['super_admin', 'superadmin'], true);
}

function app_portal_module_catalog(string $lang = 'az'): array
{
    $appendLang = static function (string $url) use ($lang): string {
        if ($lang === 'az') {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'lang=' . rawurlencode($lang);
    };

    return [
        'finance' => [
            'label' => 'Maliyye',
            'description' => 'Gelir, xerc, borc, e-qaime, amortizasiya ve maliyye hesabatlari',
            'href' => $appendLang('operations-center.php?section=finance'),
            'icon' => 'fa-wallet',
        ],
        'hr' => [
            'label' => 'HR',
            'description' => 'Emekdas, maas, vergi, mezuniyyet, ezamiyyet ve sicil bali',
            'href' => $appendLang('operations-center.php?section=hr'),
            'icon' => 'fa-id-card-clip',
        ],
        'inventory' => [
            'label' => 'Anbar',
            'description' => 'Medaxil, mexaric, tehvil, inventar, stok ve avadanliq siyahisi',
            'href' => $appendLang('operations-center.php?section=inventory'),
            'icon' => 'fa-boxes-stacked',
        ],
        'billing' => [
            'label' => 'Billing',
            'description' => 'Musteri, paket, balans, odenis ve billing xidmetleri',
            'href' => $appendLang('billing-dashboard.php'),
            'icon' => 'fa-receipt',
        ],
        'crm' => [
            'label' => 'CRM ve destek',
            'description' => 'Yeni ve kohne muracietler, ticket novbesi, texnik teyinat ve problem analitikasi',
            'href' => $appendLang('crm.php'),
            'icon' => 'fa-ticket',
        ],
        'ai' => [
            'label' => 'AI',
            'description' => 'Superpilot analizi, kontekst ve emeliyyat komekcisi',
            'href' => $appendLang('ai-dashboard.php'),
            'icon' => 'fa-brain',
        ],
        'messaging' => [
            'label' => 'Gonderisler',
            'description' => 'SMS, Telegram, portal bildirisleri ve tesdiqli kampaniyalar',
            'href' => $appendLang('message-center.php'),
            'icon' => 'fa-message',
        ],
        'whatsapp_monitor' => [
            'label' => 'WhatsApp Monitorinq',
            'description' => 'Sirket WhatsApp nomreleri, cihaz ekranlari, cavabsiz case ve CRM elaqesi',
            'href' => $appendLang('whatsapp-monitor.php'),
            'icon' => 'fa-brands fa-whatsapp',
        ],
        'statistics' => [
            'label' => 'Sirket statistikasi',
            'description' => 'Maliyye, musteri, mesaj, HR ve sebeke gostericilerinin umumi analitikasi',
            'href' => $appendLang('statistics-center.php'),
            'icon' => 'fa-chart-line',
        ],
        'network' => [
            'label' => 'Sebeke',
            'description' => 'NOC monitoru, OLT/ONT, RADIUS, alarm ve discovery',
            'href' => $appendLang('noc-devices.php?view=monitor'),
            'icon' => 'fa-network-wired',
        ],
    ];
}

function app_portal_employee_module_keys(array $user): array
{
    $employeeRole = strtolower(trim((string) ($user['employee_role'] ?? '')));
    $accountType = strtolower(trim((string) ($user['account_type'] ?? '')));

    if (in_array($employeeRole, ['admin', 'super_admin'], true) || ($employeeRole === '' && $accountType === 'admin')) {
        return array_keys(app_portal_module_catalog());
    }

    if (array_key_exists('module_keys', $user) && is_array($user['module_keys'])) {
        $requestedModules = array_values(array_unique(array_map(
            static fn (mixed $key): string => strtolower(trim((string) $key)),
            $user['module_keys']
        )));
        if (in_array('messaging', $requestedModules, true) && !in_array('crm', $requestedModules, true)) {
            $requestedModules[] = 'crm';
        }

        return array_values(array_intersect(
            array_keys(app_portal_module_catalog()),
            $requestedModules
        ));
    }

    $roleModules = [
        'finance' => ['finance', 'billing', 'statistics'],
        'warehouse' => ['inventory'],
        'warehouse_manager' => ['inventory'],
        'hr' => ['hr', 'statistics'],
        'operator' => ['billing', 'crm', 'messaging', 'whatsapp_monitor', 'statistics'],
        'night_operator' => ['billing', 'crm', 'messaging', 'whatsapp_monitor', 'statistics'],
        'support' => ['billing', 'crm', 'messaging', 'whatsapp_monitor', 'statistics', 'network', 'ai'],
        'technician' => ['inventory', 'network', 'whatsapp_monitor'],
        'engineer' => ['inventory', 'network', 'whatsapp_monitor'],
        'noc' => ['crm', 'messaging', 'whatsapp_monitor', 'statistics', 'network', 'ai'],
        'sales' => ['finance', 'billing', 'crm', 'messaging', 'whatsapp_monitor', 'statistics'],
    ];

    return $roleModules[$employeeRole] ?? [];
}

function app_portal_can_access_module(array $user, string $module): bool
{
    return in_array($module, app_portal_employee_module_keys($user), true);
}

function app_portal_request_module(string $scriptName, array $query = []): ?string
{
    $script = strtolower(basename(str_replace('\\', '/', $scriptName)));
    if ($script === 'operations-center.php') {
        return match (strtolower(clean_text((string) ($query['section'] ?? '')))) {
            'finance' => 'finance',
            'hr' => 'hr',
            'inventory' => 'inventory',
            'technical' => 'network',
            'noc' => 'network',
            'olt' => 'network',
            'network' => 'network',
            'bots' => 'ai',
            'reports' => 'statistics',
            default => null,
        };
    }

    $scriptModules = [
        'billing-dashboard.php' => 'billing',
        'billing-section-page.php' => 'billing',
        'billing-module-partial.php' => 'billing',
        'billing-customers.php' => 'billing',
        'billing-customer-edit.php' => 'billing',
        'billing-customer-profile.php' => 'billing',
        'billing-payments.php' => 'billing',
        'billing-packages.php' => 'billing',
        'billing-balances.php' => 'billing',
        'billing-services.php' => 'billing',
        'billing-providers.php' => 'billing',
        'billing-documents.php' => 'billing',
        'billing-reports.php' => 'billing',
        'billing-statistics.php' => 'billing',
        'billing-cash-payment.php' => 'billing',
        'billing-requests.php' => 'crm',
        'billing-operations-check.php' => 'billing',
        'radius-billing.php' => 'billing',
        'crm.php' => 'crm',
        'problem-statistics.php' => 'crm',
        'message-center.php' => 'messaging',
        'whatsapp-monitor.php' => 'whatsapp_monitor',
        'campaigns.php' => 'messaging',
        'admin-positions.php' => 'hr',
        'noc-devices.php' => 'network',
        'noc-security.php' => 'network',
        'network-definitions.php' => 'network',
        'ip-pools.php' => 'network',
        'radius-active.php' => 'network',
        'radius-logs.php' => 'network',
        'radius-nas.php' => 'network',
        'radius-natlog.php' => 'network',
        'mgs-copilot-context.php' => 'ai',
        'ai-dashboard.php' => 'ai',
        'ai-section-page.php' => 'ai',
        'ai-module-partial.php' => 'ai',
        'ai-copilot.php' => 'ai',
        'ai-customer-analytics.php' => 'ai',
        'ai-network-analytics.php' => 'ai',
        'ai-finance-analytics.php' => 'ai',
        'ai-risks.php' => 'ai',
        'ai-recommendations.php' => 'ai',
        'ai-team.php' => 'ai',
        'ai-work-queue.php' => 'ai',
        'ai-automations.php' => 'ai',
        'ai-audit.php' => 'ai',
        'ai-model-status.php' => 'ai',
        'statistics-center.php' => 'statistics',
        'statistics-center-partial.php' => 'statistics',
    ];

    return $scriptModules[$script] ?? null;
}

function app_portal_statistics_context_url(string $module, string $reportType, array $filters = [], string $lang = 'az'): ?string
{
    $module = strtolower(clean_text($module));
    $reportType = strtolower(clean_text($reportType));
    $financeReports = ['overview', 'invoices', 'payments', 'companies', 'expenses', 'cash-bank', 'reconciliation', 'assets'];
    if ($module !== 'finance' || !in_array($reportType, $financeReports, true)) {
        return null;
    }

    $params = [
        'section' => 'finance',
        'finance_tab' => 'statistics',
        'report_type' => $reportType,
    ];
    foreach (['range', 'start_date', 'end_date', 'finance_metric'] as $key) {
        if (isset($filters[$key]) && is_scalar($filters[$key]) && (string) $filters[$key] !== '') {
            $params[$key] = clean_text((string) $filters[$key]);
        }
    }
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }

    return 'operations-center.php?' . http_build_query($params);
}

function app_portal_ticket_subjects(): array
{
    return [
        'Suret problemi',
        'Texniki destek',
        'Sifre deyisdirme',
        'Qirmizi isiq yanir modemde',
        'Tesekkur etmek',
    ];
}

function app_portal_ticket_close_reasons(): array
{
    return [
        'Core routerda problem var idi',
        'Xetdi qirilmisdi',
        'Fastconnectoru qirilmisdi',
        'Modemin yeri yanlis idi',
        'Modem uzun muddet tokdan cixarilmirdi',
        'Adapteri yanmisdi',
        'Modem siradan cixmisdi',
        'Umumi problem (erazide isiq sonmusdu)',
        'Erazinin esas xetdi qirilmisdi',
    ];
}

function app_portal_password_store_path(): string
{
    return __DIR__ . '/data/portal-password-overrides.json';
}

function app_portal_ticket_store_path(): string
{
    return __DIR__ . '/data/portal-tickets.json';
}

function app_json_read_file(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function app_json_write_file(string $path, array $data): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function app_ticket_sequence_store_path(): string
{
    return __DIR__ . '/data/ticket-sequence.txt';
}

function app_next_ticket_id(): string
{
    if (app_db_available()) {
        return app_db_next_ticket_no();
    }

    $path = app_ticket_sequence_store_path();
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        return 'REQ-' . random_int(100000, 999999);
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return 'REQ-' . random_int(100000, 999999);
        }

        rewind($handle);
        $current = stream_get_contents($handle);
        $numeric = (int) trim(is_string($current) ? $current : '0');
        if ($numeric <= 0) {
            $maxExisting = 100000;
            foreach (app_portal_ticket_records() as $ticket) {
                if (!is_array($ticket)) {
                    continue;
                }

                if (preg_match('/REQ-(\d+)/', (string) ($ticket['id'] ?? ''), $matches)) {
                    $maxExisting = max($maxExisting, (int) $matches[1]);
                }
            }
            $numeric = $maxExisting;
        }
        $numeric = max(100000, $numeric);
        $next = $numeric + 1;

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) $next);
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    return 'REQ-' . $next;
}

function app_portal_password_overrides(): array
{
    return app_json_read_file(app_portal_password_store_path());
}

function app_portal_password_override_set(string $userId, string $password): void
{
    $data = app_portal_password_overrides();
    $data[$userId] = password_hash($password, PASSWORD_DEFAULT);
    app_json_write_file(app_portal_password_store_path(), $data);
}

function app_portal_password_override_matches(string $userId, string $password): bool
{
    $data = app_portal_password_overrides();
    $hash = $data[$userId] ?? null;

    return is_string($hash) && $hash !== '' && password_verify($password, $hash);
}

function app_absolute_url(string $path, array $params = []): string
{
    $base = rtrim(app_config('app_url'), '/');
    if ($base === '') {
        $scheme = app_request_is_https() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = $scheme . '://' . $host;
    }

    $query = $params !== [] ? '?' . http_build_query($params) : '';
    return $base . '/' . ltrim($path, '/') . $query;
}

function app_portal_login(string $role, string $login, string $password): ?array
{
    $role = $role === 'employee' ? 'employee' : 'customer';
    $login = clean_login_identifier($login);
    $password = clean_password_input($password);

    if ($login === '' || $password === '') {
        return null;
    }

    if (app_portal_uses_local_db()) {
        if (app_db_available()) {
            $dbUser = app_db_login($role, $login, $password);
            if ($dbUser !== null) {
                return $dbUser;
            }
        }

        return app_portal_mock_mode_enabled() ? app_mock_portal_login($role, $login, $password) : null;
    }

    return app_api_portal_login($role, $login, $password);
}

function app_portal_customer_snapshot(array $user): array
{
    if (app_portal_uses_local_db()) {
        if (app_db_available()) {
            return app_db_customer_snapshot($user);
        }

        return app_portal_mock_mode_enabled() ? app_mock_customer_snapshot($user) : [];
    }

    return app_api_customer_snapshot($user);
}

function app_portal_employee_snapshot(array $user): array
{
    if (app_portal_uses_local_db()) {
        if (app_db_available()) {
            return app_db_employee_snapshot($user);
        }

        return app_portal_mock_mode_enabled() ? app_mock_employee_snapshot($user) : [];
    }

    return app_api_employee_snapshot($user);
}

function app_portal_update_customer_phone(array $user, string $phone): bool
{
    $normalizedPhone = normalize_phone($phone);
    if (!preg_match('/^\+994(10|50|51|55|60|70|77)\d{7}$/', $normalizedPhone)) {
        return false;
    }

    if (app_portal_uses_local_db()) {
        if (app_db_available()) {
            return app_db_update_customer_phone($user, $normalizedPhone);
        }

        if (!app_portal_mock_mode_enabled()) {
            return false;
        }

        app_session_boot();
        $_SESSION['mock_customer_phone'][$user['id']] = $normalizedPhone;
        return true;
    }

    $endpoint = app_config('billing_customer_phone_update_endpoint');
    if ($endpoint === '') {
        return false;
    }

    $response = http_post_json($endpoint, [
        'customer_id' => $user['id'],
        'subscriber_code' => $user['subscriber_code'] ?? '',
        'phone' => $normalizedPhone,
        'token' => $user['token'] ?? '',
    ]);

    return !empty($response['success']);
}

function app_portal_update_customer_password(array $user, string $currentPassword, string $newPassword, string $confirmPassword): bool
{
    $currentPassword = clean_password_input($currentPassword);
    $newPassword = clean_password_input($newPassword);
    $confirmPassword = clean_password_input($confirmPassword);

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        return false;
    }

    $requiresStrongPassword = !empty($user['must_change_password'])
        || (string) ($user['role'] ?? 'customer') === 'employee';
    $validNewPassword = $requiresStrongPassword
        ? strlen($newPassword) >= 14
            && preg_match('/[a-z]/', $newPassword) === 1
            && preg_match('/[A-Z]/', $newPassword) === 1
            && preg_match('/\d/', $newPassword) === 1
            && preg_match('/[^A-Za-z0-9]/', $newPassword) === 1
        : strlen($newPassword) >= 8;
    if ($newPassword !== $confirmPassword || !$validNewPassword) {
        return false;
    }

    if (app_portal_uses_local_db()) {
        if (app_db_available()) {
            return app_db_update_password($user, $currentPassword, $newPassword);
        }

        if (!app_portal_mock_mode_enabled()) {
            return false;
        }

        $login = (string) ($user['login'] ?? '');
        $role = (string) ($user['role'] ?? 'customer');
        $data = app_mock_portal_data();
        $record = $role === 'employee'
            ? ($data['employees'][$login] ?? null)
            : ($data['customers'][$login] ?? null);

        if (!is_array($record)) {
            return false;
        }

        $validCurrent = $record['password'] === $currentPassword || app_portal_password_override_matches((string) $record['id'], $currentPassword);
        if (!$validCurrent) {
            return false;
        }

        app_portal_password_override_set((string) $record['id'], $newPassword);
        return true;
    }

    $endpoint = app_config('billing_customer_password_update_endpoint');
    if ($endpoint === '') {
        return false;
    }

    $response = http_post_json($endpoint, [
        'customer_id' => $user['id'],
        'subscriber_code' => $user['subscriber_code'] ?? '',
        'current_password' => $currentPassword,
        'new_password' => $newPassword,
        'token' => $user['token'] ?? '',
    ]);

    return !empty($response['success']);
}

function app_portal_mock_technicians(): array
{
    if (app_db_available()) {
        return app_db_technicians();
    }

    if (!app_demo_fixtures_enabled()) {
        return [];
    }

    $data = app_mock_portal_data();
    return is_array($data['technicians'] ?? null) ? $data['technicians'] : [];
}

function app_portal_resolve_area_from_text(string $text): string
{
    $textNormalized = strtolower($text);
    $knownAreas = app_service_areas();

    foreach ($knownAreas as $area) {
        if (!is_string($area) || $area === '') {
            continue;
        }

        if (str_contains($textNormalized, strtolower($area))) {
            return $area;
        }
    }

    return '';
}

function app_portal_assign_technician(string $area): ?array
{
    $normalizedArea = strtolower(trim($area));
    $fallback = null;

    foreach (app_portal_mock_technicians() as $technician) {
        if (!is_array($technician)) {
            continue;
        }

        $fallback ??= $technician;
        foreach (($technician['areas'] ?? []) as $coveredArea) {
            if (is_string($coveredArea) && strtolower($coveredArea) === $normalizedArea) {
                return $technician;
            }
        }
    }

    return $fallback;
}

function app_portal_seed_ticket_records(): array
{
    $data = app_mock_portal_data();
    $records = [];

    foreach (($data['customers'] ?? []) as $login => $customer) {
        if (!is_array($customer)) {
            continue;
        }

        $profile = $customer['dashboard']['profile'] ?? [];
        $area = (string) ($profile['area'] ?? app_portal_resolve_area_from_text((string) ($profile['address'] ?? '')));
        $technician = app_portal_assign_technician($area);

        foreach (($customer['dashboard']['tickets'] ?? []) as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }

            $status = (string) ($ticket['status'] ?? 'Aciqdir');
            $resolvedAt = in_array($status, ['Baglandi', 'Bağlandı', 'Closed'], true) ? (string) ($ticket['updated_at'] ?? '') : '';
            $records[] = [
                'id' => (string) ($ticket['id'] ?? ('REQ-' . random_int(1000, 9999))),
                'customer_id' => (string) ($customer['id'] ?? ''),
                'subscriber_code' => (string) ($customer['subscriber_code'] ?? ''),
                'contract_no' => (string) ($profile['contract_no'] ?? ''),
                'customer_name' => (string) ($profile['full_name'] ?? $customer['name'] ?? $login),
                'phone' => (string) ($profile['phone'] ?? ''),
                'address' => (string) ($profile['address'] ?? ''),
                'area' => $area,
                'subject' => (string) ($ticket['subject'] ?? 'Texniki destek'),
                'message' => (string) ($ticket['message'] ?? ''),
                'status' => $status,
                'opened_at' => (string) ($ticket['updated_at'] ?? date('d.m.Y H:i')),
                'updated_at' => (string) ($ticket['updated_at'] ?? date('d.m.Y H:i')),
                'resolved_at' => $resolvedAt,
                'assigned_technician_id' => (string) ($technician['id'] ?? ''),
                'assigned_technician_name' => (string) ($technician['name'] ?? ''),
                'resolution_reason' => $resolvedAt !== '' ? 'Qeydli tiket baglanib' : '',
                'resolution_note' => $resolvedAt !== '' ? (string) ($ticket['message'] ?? '') : '',
                'handled_by' => $resolvedAt !== '' ? (string) ($technician['name'] ?? '') : '',
                'created_by_role' => 'seed',
                'created_by_name' => 'System',
                'telegram_message_ids' => [],
            ];
        }
    }

    return $records;
}

function app_portal_ticket_records(): array
{
    if (app_db_available()) {
        return app_db_ticket_records();
    }

    if (!app_demo_fixtures_enabled()) {
        return [];
    }

    $path = app_portal_ticket_store_path();
    if (!file_exists($path)) {
        $seed = app_portal_seed_ticket_records();
        app_json_write_file($path, $seed);
        return $seed;
    }

    return app_json_read_file($path);
}

function app_portal_ticket_write_records(array $records): void
{
    if (app_db_available()) {
        return;
    }

    if (!app_demo_fixtures_enabled()) {
        return;
    }

    app_json_write_file(app_portal_ticket_store_path(), array_values($records));
}

function app_portal_customer_tickets(array $user): array
{
    $customerId = (string) ($user['id'] ?? '');
    $tickets = array_filter(
        app_portal_ticket_records(),
        static fn ($ticket): bool => is_array($ticket) && (string) ($ticket['customer_id'] ?? '') === $customerId
    );

    usort($tickets, static function (array $a, array $b): int {
        return strtotime((string) ($b['updated_at'] ?? '')) <=> strtotime((string) ($a['updated_at'] ?? ''));
    });

    return array_values($tickets);
}

function app_portal_crm_tickets(array $filters = []): array
{
    if (app_db_available()) {
        return app_db_ticket_records($filters);
    }

    $tickets = app_portal_ticket_records();

    $tickets = array_values(array_filter($tickets, static function ($ticket) use ($filters): bool {
        if (!is_array($ticket)) {
            return false;
        }

        $q = strtolower(trim((string) ($filters['q'] ?? '')));
        if ($q !== '') {
            $haystack = strtolower(implode(' ', [
                (string) ($ticket['id'] ?? ''),
                (string) ($ticket['customer_name'] ?? ''),
                (string) ($ticket['subscriber_code'] ?? ''),
                (string) ($ticket['subject'] ?? ''),
                (string) ($ticket['area'] ?? ''),
                (string) ($ticket['assigned_technician_name'] ?? ''),
            ]));
            if (!str_contains($haystack, $q)) {
                return false;
            }
        }

        foreach (['area', 'status', 'subject', 'assigned_technician_name'] as $field) {
            $expected = trim((string) ($filters[$field] ?? ''));
            if ($expected !== '' && (string) ($ticket[$field] ?? '') !== $expected) {
                return false;
            }
        }

        return true;
    }));

    usort($tickets, static function (array $a, array $b): int {
        return strtotime((string) ($b['opened_at'] ?? $b['updated_at'] ?? '')) <=> strtotime((string) ($a['opened_at'] ?? $a['updated_at'] ?? ''));
    });

    return $tickets;
}

function app_portal_crm_ticket_count(array $filters = []): int
{
    if (app_db_available()) {
        return app_db_ticket_count($filters);
    }

    $countFilters = $filters;
    unset($countFilters['limit'], $countFilters['offset']);
    return count(app_portal_crm_tickets($countFilters));
}

function app_portal_ticket_stats(): array
{
    if (app_db_available()) {
        return app_db_ticket_stats();
    }

    $tickets = app_portal_ticket_records();
    $stats = [
        'total' => count($tickets),
        'open' => 0,
        'closed' => 0,
    ];

    foreach ($tickets as $ticket) {
        $status = (string) ($ticket['status'] ?? '');
        if (in_array($status, ['Baglandi', 'Bağlandı', 'Closed'], true)) {
            $stats['closed']++;
        } else {
            $stats['open']++;
        }
    }

    return $stats;
}

function app_portal_ticket_find(string $ticketId): ?array
{
    if (app_db_available()) {
        return app_db_ticket_find($ticketId);
    }

    foreach (app_portal_ticket_records() as $ticket) {
        if (is_array($ticket) && (string) ($ticket['id'] ?? '') === $ticketId) {
            return $ticket;
        }
    }

    return null;
}

function app_portal_customer_core_snapshot(array $user): array
{
    if (app_portal_uses_local_db() && app_db_available()) {
        return app_db_customer_snapshot($user);
    }

    return app_portal_mock_mode_enabled() ? app_mock_customer_snapshot($user) : app_api_customer_snapshot($user);
}

function app_portal_ticket_summary(array $ticket): string
{
    $parts = array_filter([
        (string) ($ticket['id'] ?? ''),
        'ABN:' . (string) ($ticket['subscriber_code'] ?? ''),
        (string) ($ticket['area'] ?? ''),
        (string) ($ticket['customer_name'] ?? ''),
        (string) ($ticket['phone'] ?? ''),
        (string) ($ticket['subject'] ?? ''),
        'Texnik: ' . (string) ($ticket['assigned_technician_name'] ?? ''),
    ]);

    $summary = implode(' | ', $parts);
    if (mb_strlen($summary) > 160) {
        $summary = mb_substr($summary, 0, 157) . '...';
    }

    return $summary;
}

function app_telegram_token_is_valid(string $token): bool
{
    return preg_match('/^[0-9]{6,16}:[A-Za-z0-9_-]{30,80}$/D', trim($token)) === 1;
}

function app_telegram_runtime_chat_id(string $settingKey, string $fallback = ''): string
{
    $fallback = trim($fallback);
    if ($fallback !== '') {
        return $fallback;
    }

    try {
        $db = app_db();
        if (!$db instanceof PDO) {
            return '';
        }
        $statement = $db->prepare(
            'SELECT setting_value FROM mgs_runtime_settings WHERE setting_key = :setting_key LIMIT 1'
        );
        $statement->execute(['setting_key' => $settingKey]);
        return trim((string) ($statement->fetchColumn() ?: ''));
    } catch (Throwable) {
        return '';
    }
}

function app_telegram_provider_profile(string $profile = 'generic'): array
{
    $profile = strtolower(trim($profile));
    if ($profile === 'customer') {
        $specificToken = app_environment_secret('TELEGRAM_CUSTOMER_BOT_TOKEN');
        $genericToken = app_environment_secret('TELEGRAM_TOKEN');
        return [
            'token' => app_telegram_token_is_valid($specificToken)
                ? $specificToken
                : (app_telegram_token_is_valid($genericToken) ? $genericToken : ''),
            'chat_id' => app_telegram_runtime_chat_id(
                'telegram_customer_chat_id',
                app_config('telegram_customer_chat_id')
            ),
        ];
    }

    $genericToken = app_environment_secret('TELEGRAM_TOKEN');
    return [
        'token' => app_telegram_token_is_valid($genericToken) ? $genericToken : '',
        'chat_id' => app_config('telegram_chat_id'),
    ];
}

function app_telegram_send_message(string $text, string $profile = 'generic'): ?array
{
    $provider = app_telegram_provider_profile($profile);
    $token = (string) ($provider['token'] ?? '');
    $chatId = (string) ($provider['chat_id'] ?? '');
    if ($token === '' || $chatId === '') {
        return null;
    }

    return http_post_json('https://api.telegram.org/bot' . $token . '/sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ]);
}

function app_telegram_delete_message(int $messageId, string $profile = 'generic'): void
{
    $provider = app_telegram_provider_profile($profile);
    $token = (string) ($provider['token'] ?? '');
    $chatId = (string) ($provider['chat_id'] ?? '');
    if ($token === '' || $chatId === '' || $messageId <= 0) {
        return;
    }

    http_post_json('https://api.telegram.org/bot' . $token . '/deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
    ]);
}

function app_portal_ticket_close_link(array $ticket): string
{
    $secret = app_environment_secret('PORTAL_TICKET_CLOSE_SECRET');
    if ($secret === '') {
        return '';
    }
    $now = time();
    $token = app_portal_sign_token([
        'iss' => app_config('portal_token_issuer', 'portal.superoptic.net'),
        'aud' => app_config('portal_ticket_close_audience', 'portal.ticket.close'),
        'type' => 'ticket-close',
        'ticket_id' => (string) ($ticket['id'] ?? ''),
        'tech_id' => (string) ($ticket['assigned_technician_id'] ?? ''),
        'iat' => $now,
        'nbf' => $now - 5,
        'exp' => $now + 86400,
        'jti' => bin2hex(random_bytes(8)),
    ], $secret);

    $base = rtrim(app_config('ticket_close_base_url'), '/');
    if ($base !== '') {
        return $base . '/technician-close.php?' . http_build_query(['token' => $token]);
    }

    return app_absolute_url('technician-close.php', ['token' => $token]);
}

function app_portal_send_ticket_to_telegram(array $ticket): array
{
    $messageIds = [];
    $summaryResponse = app_telegram_send_message(app_portal_ticket_summary($ticket), 'customer');
    $summaryMessageId = (int) ($summaryResponse['result']['message_id'] ?? 0);
    if ($summaryMessageId > 0) {
        $messageIds[] = $summaryMessageId;
    }

    $closeLink = app_portal_ticket_close_link($ticket);
    if ($closeLink !== '') {
        $linkResponse = app_telegram_send_message('Bagla: ' . $closeLink, 'customer');
        $linkMessageId = (int) ($linkResponse['result']['message_id'] ?? 0);
        if ($linkMessageId > 0) {
            $messageIds[] = $linkMessageId;
        }
    }

    return $messageIds;
}

function app_portal_build_customer_ticket(array $user, string $subject, string $message): ?array
{
    $subject = clean_text($subject);
    $message = clean_text($message);
    if (!in_array($subject, app_portal_ticket_subjects(), true) || $message === '') {
        return null;
    }

    if (mb_strlen($message) > 160) {
        $message = mb_substr($message, 0, 160);
    }

    $snapshot = app_portal_customer_core_snapshot($user);
    $profile = is_array($snapshot['profile'] ?? null) ? $snapshot['profile'] : [];
    $area = (string) ($profile['area'] ?? app_portal_resolve_area_from_text((string) ($profile['address'] ?? '')));
    $technician = app_portal_assign_technician($area);
    $now = date('d.m.Y H:i');

    return [
        'id' => app_next_ticket_id(),
        'customer_id' => (string) ($user['id'] ?? ''),
        'subscriber_code' => (string) ($profile['subscriber_code'] ?? ($user['subscriber_code'] ?? '')),
        'contract_no' => (string) ($profile['contract_no'] ?? ''),
        'customer_name' => (string) ($profile['full_name'] ?? ($user['name'] ?? '')),
        'phone' => (string) ($profile['phone'] ?? ''),
        'address' => (string) ($profile['address'] ?? ''),
        'area' => $area,
        'subject' => $subject,
        'message' => $message,
        'status' => 'Aciqdir',
        'opened_at' => $now,
        'updated_at' => $now,
        'resolved_at' => '',
        'assigned_technician_id' => (string) ($technician['id'] ?? ''),
        'assigned_technician_name' => (string) ($technician['name'] ?? 'Teyin edilmeyib'),
        'resolution_reason' => '',
        'resolution_note' => '',
        'handled_by' => '',
        'created_by_role' => 'customer',
        'created_by_name' => (string) ($profile['full_name'] ?? ($user['name'] ?? '')),
        'telegram_message_ids' => [],
    ];
}

function app_portal_create_customer_ticket(array $user, string $subject, string $message): bool
{
    $ticket = app_portal_build_customer_ticket($user, $subject, $message);
    if ($ticket === null) {
        return false;
    }

    if (app_portal_uses_local_db()) {
        if (app_db_available()) {
            $ticketNo = (string) ($ticket['id'] ?? app_next_ticket_id());
            $ticket['ticket_no'] = $ticketNo;
            $ticket['customer_account_id'] = (int) ($user['id'] ?? 0);
            $ticket['opened_at'] = app_db_parse_datetime((string) ($ticket['opened_at'] ?? '')) ?? app_db_now();
            $ticket['updated_at'] = app_db_parse_datetime((string) ($ticket['updated_at'] ?? '')) ?? app_db_now();
            $ticket['resolved_at'] = null;
            $ticket['created_by_account_id'] = (int) ($user['id'] ?? 0);
            $ticket['telegram_message_ids_json'] = '[]';
            $ticket['metadata_json'] = null;

            if (!app_db_insert_ticket($ticket)) {
                return false;
            }

            $created = app_db_ticket_find($ticketNo);
            if ($created !== null) {
                $messageIds = app_portal_send_ticket_to_telegram($created);
                if ($messageIds !== []) {
                    $db = app_db();
                    if ($db instanceof PDO) {
                        $statement = $db->prepare('UPDATE portal_tickets SET telegram_message_ids_json = :message_ids WHERE ticket_no = :ticket_no');
                        $statement->execute([
                            'message_ids' => app_db_json_encode($messageIds),
                            'ticket_no' => $ticketNo,
                        ]);
                    }
                }
            }

            return true;
        }

        if (!app_portal_mock_mode_enabled()) {
            return false;
        }

        $records = app_portal_ticket_records();
        $ticket['telegram_message_ids'] = app_portal_send_ticket_to_telegram($ticket);
        $records[] = $ticket;
        app_portal_ticket_write_records($records);
        return true;
    }

    $endpoint = app_config('billing_customer_ticket_create_endpoint');
    if ($endpoint === '') {
        return false;
    }

    $response = http_post_json($endpoint, [
        'customer_id' => $user['id'],
        'subscriber_code' => $user['subscriber_code'] ?? '',
        'subject' => $subject,
        'message' => mb_substr(clean_text($message), 0, 160),
        'token' => $user['token'] ?? '',
    ]);

    return !empty($response['success']);
}

function app_portal_create_operator_ticket(array $employee, array $payload): bool
{
    $subject = clean_text((string) ($payload['subject'] ?? ''));
    $message = clean_text((string) ($payload['message'] ?? ''));
    $customerName = clean_text((string) ($payload['customer_name'] ?? ''));
    $phone = normalize_phone((string) ($payload['phone'] ?? ''));
    $area = clean_text((string) ($payload['area'] ?? ''));
    $subscriberCode = clean_text((string) ($payload['subscriber_code'] ?? ''));
    $address = clean_text((string) ($payload['address'] ?? ''));
    $customerAccountId = (int) ($payload['customer_account_id'] ?? 0);

    $resolvedCustomer = app_db_available()
        ? app_db_customer_lookup_for_ticket($customerAccountId, $subscriberCode, $phone, $customerName)
        : null;
    if (is_array($resolvedCustomer)) {
        $customerAccountId = (int) ($resolvedCustomer['id'] ?? 0);
        $subscriberCode = $subscriberCode !== ''
            ? $subscriberCode
            : trim((string) ($resolvedCustomer['subscriber_code'] ?? $resolvedCustomer['contract_no'] ?? $resolvedCustomer['login'] ?? ''));
        $customerName = $customerName !== ''
            ? $customerName
            : trim((string) ($resolvedCustomer['full_name'] ?? ''));
        $phone = $phone !== ''
            ? $phone
            : normalize_phone((string) ($resolvedCustomer['phone'] ?? ''));
        $area = $area !== ''
            ? $area
            : clean_text((string) ($resolvedCustomer['area'] ?? ''));
        $address = $address !== ''
            ? $address
            : clean_text((string) ($resolvedCustomer['address'] ?? ''));
    }

    if ($customerName === '' || $phone === '' || $area === '' || $subject === '' || $message === '') {
        return false;
    }

    if (!in_array($subject, app_portal_ticket_subjects(), true)) {
        return false;
    }

    $message = mb_substr($message, 0, 160);
    $technician = app_portal_assign_technician($area);
    $now = date('d.m.Y H:i');
    $ticketNo = app_next_ticket_id();
    $ticket = [
        'id' => $ticketNo,
        'customer_id' => $subscriberCode !== '' ? $subscriberCode : ($customerAccountId > 0 ? (string) $customerAccountId : 'manual-' . random_int(1000, 9999)),
        'subscriber_code' => $subscriberCode,
        'contract_no' => '',
        'customer_name' => $customerName,
        'phone' => $phone,
        'address' => $address,
        'area' => $area,
        'subject' => $subject,
        'message' => $message,
        'status' => 'Aciqdir',
        'opened_at' => $now,
        'updated_at' => $now,
        'resolved_at' => '',
        'assigned_technician_id' => (string) ($technician['id'] ?? ''),
        'assigned_technician_name' => (string) ($technician['name'] ?? 'Teyin edilmeyib'),
        'resolution_reason' => '',
        'resolution_note' => '',
        'handled_by' => '',
        'created_by_role' => 'employee',
        'created_by_name' => (string) ($employee['name'] ?? 'Operator'),
        'telegram_message_ids' => [],
    ];

    if (app_db_available()) {
        $account = $resolvedCustomer;
        if (!is_array($account) && $subscriberCode !== '') {
            $account = app_db_account_by_subscriber_code($subscriberCode);
        }
        $ticket['ticket_no'] = $ticketNo;
        $ticket['customer_account_id'] = (int) ($account['id'] ?? 0);
        $ticket['opened_at'] = app_db_parse_datetime($now) ?? app_db_now();
        $ticket['updated_at'] = app_db_parse_datetime($now) ?? app_db_now();
        $ticket['resolved_at'] = null;
        $ticket['assigned_technician_account_id'] = (int) ($technician['id'] ?? 0);
        $ticket['created_by_account_id'] = (int) ($employee['id'] ?? 0);
        $ticket['telegram_message_ids_json'] = '[]';
        $ticket['metadata_json'] = null;

        if (!app_db_insert_ticket($ticket)) {
            return false;
        }

        $created = app_db_ticket_find($ticketNo);
        if ($created !== null) {
            $messageIds = app_portal_send_ticket_to_telegram($created);
            if ($messageIds !== []) {
                $db = app_db();
                if ($db instanceof PDO) {
                    $statement = $db->prepare('UPDATE portal_tickets SET telegram_message_ids_json = :message_ids WHERE ticket_no = :ticket_no');
                    $statement->execute([
                        'message_ids' => app_db_json_encode($messageIds),
                        'ticket_no' => $ticketNo,
                    ]);
                }
            }
        }

        return true;
    }

    if (!app_demo_fixtures_enabled()) {
        return false;
    }

    $ticket['telegram_message_ids'] = app_portal_send_ticket_to_telegram($ticket);
    $records = app_portal_ticket_records();
    $records[] = $ticket;
    app_portal_ticket_write_records($records);
    return true;
}

function app_api_portal_login(string $role, string $login, string $password): ?array
{
    $endpoint = $role === 'employee'
        ? app_config('billing_employee_auth_endpoint')
        : app_config('billing_customer_auth_endpoint');

    if ($endpoint === '') {
        return null;
    }

    $response = http_post_json($endpoint, [
        'role' => $role,
        'login' => $login,
        'password' => $password,
    ]);

    if (empty($response['success']) || empty($response['user']) || !is_array($response['user'])) {
        return null;
    }

    $user = $response['user'];

    return [
        'id' => (string) ($user['id'] ?? $login),
        'name' => (string) ($user['name'] ?? $login),
        'role' => $role,
        'subscriber_code' => (string) ($user['subscriber_code'] ?? ''),
        'category' => (string) ($user['category'] ?? ''),
        'employee_role' => (string) ($user['employee_role'] ?? ''),
        'token' => (string) ($response['token'] ?? ''),
        'login' => $login,
    ];
}

function app_api_customer_snapshot(array $user): array
{
    $endpoint = app_config('billing_customer_overview_endpoint');
    if ($endpoint === '') {
        return app_demo_fixtures_enabled() ? app_mock_customer_snapshot($user) : [];
    }

    $response = http_post_json($endpoint, [
        'customer_id' => $user['id'],
        'subscriber_code' => $user['subscriber_code'] ?? '',
        'token' => $user['token'] ?? '',
    ]);

    return is_array($response['data'] ?? null)
        ? $response['data']
        : (app_demo_fixtures_enabled() ? app_mock_customer_snapshot($user) : []);
}

function app_api_employee_snapshot(array $user): array
{
    $endpoint = app_config('billing_employee_overview_endpoint');
    if ($endpoint === '') {
        return app_demo_fixtures_enabled() ? app_mock_employee_snapshot($user) : [];
    }

    $response = http_post_json($endpoint, [
        'employee_id' => $user['id'],
        'role' => $user['employee_role'] ?? '',
        'token' => $user['token'] ?? '',
    ]);

    $snapshot = is_array($response['data'] ?? null)
        ? $response['data']
        : (app_demo_fixtures_enabled() ? app_mock_employee_snapshot($user) : []);
    $snapshot['olt_links'] = app_portal_employee_links($user);

    return $snapshot;
}

function app_mock_portal_login(string $role, string $login, string $password): ?array
{
    $data = app_mock_portal_data();
    $bucket = $role === 'employee' ? $data['employees'] : $data['customers'];
    $record = $bucket[$login] ?? null;

    if (!is_array($record)) {
        return null;
    }

    $passwordMatches = $record['password'] === $password || app_portal_password_override_matches((string) $record['id'], $password);
    if (!$passwordMatches) {
        return null;
    }

    return [
        'id' => $record['id'],
        'name' => $record['name'],
        'role' => $role,
        'subscriber_code' => $record['subscriber_code'] ?? '',
        'category' => $record['category'] ?? '',
        'employee_role' => $record['employee_role'] ?? '',
        'token' => 'mock-token',
        'login' => $login,
    ];
}

function app_mock_customer_snapshot_data(array $user): array
{
    if (!app_demo_fixtures_enabled()) {
        return [];
    }

    app_session_boot();
    $data = app_mock_portal_data();
    $record = $data['customers'][$user['login']] ?? reset($data['customers']);
    if (!is_array($record)) {
        return [];
    }
    $phoneOverride = $_SESSION['mock_customer_phone'][$record['id']] ?? null;

    $snapshot = $record['dashboard'];
    if (is_string($phoneOverride) && $phoneOverride !== '') {
        $snapshot['profile']['phone'] = $phoneOverride;
    }

    $profile = is_array($snapshot['profile'] ?? null) ? $snapshot['profile'] : [];
    $subscriberCode = (string) ($profile['subscriber_code'] ?? $record['subscriber_code'] ?? $user['login'] ?? '');
    $plan = (string) ($profile['plan'] ?? 'Demo tarif');
    $status = (string) ($profile['status'] ?? 'Aktiv');
    $payments = is_array($snapshot['payments'] ?? null) ? array_values(array_filter($snapshot['payments'], 'is_array')) : [];
    $statements = is_array($snapshot['statements'] ?? null) ? array_values(array_filter($snapshot['statements'], 'is_array')) : [];
    $lastStatement = $statements[0] ?? [];

    if (!is_array($snapshot['billing'] ?? null)) {
        $snapshot['billing'] = [
            'service_type' => 'pppoe',
            'pppoe_username' => $subscriberCode,
            'pppoe_password' => 'mock-pass',
            'customer_code' => $subscriberCode,
            'tariff' => $plan,
            'monthly_fee' => (string) ($lastStatement['charge'] ?? '0 AZN'),
            'balance' => (string) ($lastStatement['balance'] ?? '0 AZN'),
            'status' => $status,
            'radius_status' => 'Aktiv',
            'pool' => 'Demo Local PPPoE Pool',
            'zone' => (string) ($profile['area'] ?? ''),
            'ip_address' => '10.11.20.10',
            'static_ip' => '',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+27 days')),
            'days_left' => 27,
            'remaining_days_raw' => '27 gun',
            'debt_raw' => (string) ($lastStatement['balance'] ?? '0 AZN'),
            'last_payment_at' => (string) ($payments[0]['date'] ?? ''),
            'payment_count' => count($payments),
        ];
    }

    if (!is_array($snapshot['internet_services'] ?? null)) {
        $snapshot['internet_services'] = [[
            'service_type' => 'pppoe',
            'username' => $subscriberCode,
            'password' => 'mock-pass',
            'tariff' => $plan,
            'pool' => 'Demo Local PPPoE Pool',
            'ip_address' => (string) ($snapshot['billing']['ip_address'] ?? '10.11.20.10'),
            'static_ip' => '',
            'vlan' => '1001',
            'mac' => 'MOCK-MAC',
            'status' => 'active',
        ]];
    }

    if (!is_array($snapshot['ont_profiles'] ?? null)) {
        $snapshot['ont_profiles'] = [[
            'olt' => 'Demo OLT',
            'pon' => 'PON01',
            'ont_id' => '001',
            'serial' => 'MOCKSN' . preg_replace('/\D+/', '', $subscriberCode),
            'mac' => 'MOCK-MAC',
            'temperature' => '44',
            'rx_power' => '-20.1',
            'tx_power' => '2.3',
            'olt_rx_power' => '-21.0',
            'olt_tx_power' => '4.5',
            'status' => 'online',
        ]];
    }

    if (!is_array($snapshot['messages'] ?? null)) {
        $snapshot['messages'] = [[
            'date' => date('d.m.Y H:i'),
            'channel' => 'Mock',
            'status' => 'Hazir',
            'text' => 'Xidmetiniz aktivdir. Odenis ve ticket tarixcesi profilinizde gorunur.',
        ]];
    }

    if (!is_array($snapshot['old_billing'] ?? null)) {
        $snapshot['old_billing'] = [
            'profile_tables' => [
                'payment_history' => [[
                    'headers' => ['Tarix', 'Mebleg', 'Terminal', 'Status'],
                    'rows' => array_map(static fn (array $payment): array => [
                        (string) ($payment['date'] ?? '-'),
                        (string) ($payment['amount'] ?? '-'),
                        (string) ($payment['method'] ?? '-'),
                        (string) ($payment['status'] ?? '-'),
                    ], $payments),
                ]],
                'payment_statement' => [[
                    'headers' => ['Period', 'Borc', 'Odenis', 'Balans'],
                    'rows' => array_map(static fn (array $statement): array => [
                        (string) ($statement['period'] ?? '-'),
                        (string) ($statement['charge'] ?? '-'),
                        (string) ($statement['paid'] ?? '-'),
                        (string) ($statement['balance'] ?? '-'),
                    ], $statements),
                ]],
                'customer_logs' => [[
                    'headers' => ['Tarix', 'Sahe', 'Qeyd'],
                    'rows' => [
                        [date('d.m.Y H:i'), 'Profil', 'Mock/import profil melumatlari yuklendi'],
                    ],
                ]],
                'radius_logs' => [[
                    'headers' => ['Tarix', 'Username', 'Netice'],
                    'rows' => [
                        [date('d.m.Y H:i'), $subscriberCode, 'Access-Accept'],
                    ],
                ]],
            ],
        ];
    }

    $snapshot['tickets'] = app_portal_customer_tickets($user);
    return $snapshot;
}

function app_mock_customer_snapshot(array $user): array
{
    if (app_db_available()) {
        return app_db_customer_snapshot($user);
    }

    return app_demo_fixtures_enabled() ? app_mock_customer_snapshot_data($user) : [];
}

function app_mock_employee_snapshot_data(array $user): array
{
    if (!app_demo_fixtures_enabled()) {
        return [];
    }

    $data = app_mock_portal_data();
    $record = $data['employees'][$user['login']] ?? reset($data['employees']);
    if (!is_array($record)) {
        return [];
    }
    $snapshot = $record['dashboard'];
    $stats = app_portal_ticket_stats();
    $assignedTickets = array_filter(
        app_portal_ticket_records(),
        static fn ($ticket): bool => is_array($ticket) && (string) ($ticket['assigned_technician_name'] ?? '') !== ''
    );

    $snapshot['summary']['open_queue'] = $stats['open'] . ' aciq muraciet';
    $snapshot['crm_stats'] = $stats;
    $snapshot['tickets'] = array_values($assignedTickets);
    $snapshot['technicians'] = app_portal_mock_technicians();
    $snapshot['olt_links'] = app_portal_employee_links($user);
    return $snapshot;
}

function app_mock_employee_snapshot(array $user): array
{
    if (app_db_available()) {
        return app_db_employee_snapshot($user);
    }

    return app_demo_fixtures_enabled() ? app_mock_employee_snapshot_data($user) : [];
}

function app_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function app_base64url_decode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return is_string($decoded) ? $decoded : '';
}

function app_portal_token_usage_store_path(): string
{
    return __DIR__ . '/data/used-token-jti.json';
}

function app_portal_consume_token_jti(string $audience, string $jti, int $exp): bool
{
    if ($jti === '') {
        return true;
    }

    $path = app_portal_token_usage_store_path();
    $data = app_json_read_file($path);
    $now = time();

    $data = array_filter(
        $data,
        static fn ($storedExp): bool => is_int($storedExp) ? $storedExp >= $now : ((int) $storedExp) >= $now
    );

    $key = $audience . ':' . $jti;
    if (array_key_exists($key, $data)) {
        app_json_write_file($path, $data);
        return false;
    }

    $data[$key] = max($exp, $now + 3600);
    app_json_write_file($path, $data);

    return true;
}

function app_portal_sign_token(array $payload, string $secret): string
{
    if ($secret === '') {
        return '';
    }

    $header = app_base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_UNICODE) ?: '{}');
    $claims = app_base64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');
    $signature = hash_hmac('sha256', $header . '.' . $claims, $secret, true);

    return $header . '.' . $claims . '.' . app_base64url_encode($signature);
}

function app_portal_verify_token(string $token, string $secret, array $requirements = []): ?array
{
    if ($secret === '') {
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$header, $claims, $signature] = $parts;
    $decodedHeader = json_decode(app_base64url_decode($header), true);
    if (!is_array($decodedHeader)
        || (string) ($decodedHeader['alg'] ?? '') !== 'HS256'
        || (string) ($decodedHeader['typ'] ?? '') !== 'JWT') {
        return null;
    }

    $expected = app_base64url_encode(hash_hmac('sha256', $header . '.' . $claims, $secret, true));
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode(app_base64url_decode($claims), true);
    if (!is_array($payload)) {
        return null;
    }

    $exp = (int) ($payload['exp'] ?? 0);
    if ($exp > 0 && $exp < time()) {
        return null;
    }

    $nbf = (int) ($payload['nbf'] ?? 0);
    if ($nbf > 0 && $nbf > time()) {
        return null;
    }

    if (isset($requirements['iss']) && (string) ($payload['iss'] ?? '') !== (string) $requirements['iss']) {
        return null;
    }

    if (isset($requirements['aud']) && (string) ($payload['aud'] ?? '') !== (string) $requirements['aud']) {
        return null;
    }

    if (isset($requirements['type']) && (string) ($payload['type'] ?? '') !== (string) $requirements['type']) {
        return null;
    }

    return $payload;
}

function app_portal_close_ticket_by_token(string $token, string $reason, string $note): bool
{
    // Legacy token-only closure is intentionally disabled. Ticket closure must
    // pass through ticket-resolution-lib.php with an authenticated actor.
    return false;
}

function app_portal_build_handoff_url(string $entrypoint, string $token, string $returnPath): string
{
    $separator = str_contains($entrypoint, '?') ? '&' : '?';
    return $entrypoint . $separator . 'token=' . rawurlencode($token) . '&return=' . rawurlencode($returnPath);
}

function app_mgs_bridge_shell_path(string $moduleKey): string
{
    $module = clean_text($moduleKey);
    if ($module === '') {
        $module = 'monitor';
    }

    $returnMap = [
        'monitor' => '/superopticnet/operations-center.php',
        'ont' => '/superopticnet/noc-devices.php?view=devices',
        'mpls' => '/superopticnet/network-definitions.php',
        'inventory' => '/superopticnet/operations-center.php?section=inventory',
        'finance' => '/superopticnet/operations-center.php?section=finance',
        'technical' => '/superopticnet/operations-center.php?section=technical',
        'kpi' => '/superopticnet/operations-center.php?section=hr&focus=kpi',
        'reports' => '/superopticnet/statistics-center.php',
        'stats' => '/superopticnet/billing-statistics.php',
        'logs' => '/superopticnet/noc-devices.php?view=devices',
        'backup' => '/superopticnet/noc-devices.php?view=backups',
        'hr' => '/superopticnet/operations-center.php?section=hr',
        'users' => '/superopticnet/admin-users.php',
    ];

    return $returnMap[$module] ?? '/superopticnet/operations-center.php';
}

function app_mgs_internal_base_url(): string
{
    $url = trim((string) app_config('mgs_internal_url', 'http://localhost:3000'));
    if ($url === '') {
        return 'http://localhost:3000';
    }

    return rtrim($url, '/');
}

function app_mgs_internal_service_online(?string $baseUrl = null): bool
{
    static $cache = [];

    $url = rtrim((string) ($baseUrl ?: app_mgs_internal_base_url()), '/');
    if (isset($cache[$url])) {
        return $cache[$url];
    }

    $parts = parse_url($url);
    $host = (string) ($parts['host'] ?? '');
    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($host === '' || $port <= 0) {
        return $cache[$url] = false;
    }

    $timeoutMs = (int) app_config('mgs_service_connect_timeout_ms', '180');
    $timeout = max(0.05, min(2.0, $timeoutMs / 1000));
    $targetHost = strtolower($host) === 'localhost' ? '127.0.0.1' : $host;
    $socket = @stream_socket_client(
        'tcp://' . $targetHost . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );
    if (is_resource($socket)) {
        fclose($socket);
        return $cache[$url] = true;
    }

    return $cache[$url] = false;
}

function app_mgs_service_shared_secret(): string
{
    return app_environment_secret('MGS_SERVICE_SHARED_SECRET');
}

function app_mgs_service_headers(string $pathWithQuery, string $method = 'GET', string $body = ''): array
{
    $serviceName = 'superopticnet';
    $timestamp = (string) time();
    $bodyHash = hash('sha256', $body);
    $payload = implode("\n", [
        strtoupper(trim($method) !== '' ? $method : 'GET'),
        parse_url($pathWithQuery, PHP_URL_PATH) ?: '/',
        $timestamp,
        strtolower($bodyHash),
        strtolower($serviceName),
    ]);
    $signature = hash_hmac('sha256', $payload, app_mgs_service_shared_secret());

    return [
        'x-mgs-service-name: ' . $serviceName,
        'x-mgs-service-timestamp: ' . $timestamp,
        'x-mgs-service-signature: ' . $signature,
        'x-mgs-service-body-sha256: ' . $bodyHash,
    ];
}

function app_mgs_service_native_count(?PDO $db, string $sql): int
{
    if (!$db instanceof PDO) {
        return 0;
    }
    try {
        return (int) $db->query($sql)->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function app_mgs_service_native_rows(?PDO $db, string $sql, array $params = [], int $limit = 10): array
{
    if (!$db instanceof PDO) {
        return [];
    }
    try {
        $statement = $db->prepare($sql . ' LIMIT ' . max(1, min(50, $limit)));
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_service_native_fallback(string $path): ?array
{
    $db = app_db();
    $cleanPath = '/' . trim($path, '/');
    $summary = [
        'customers' => app_mgs_service_native_count($db, "SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer'"),
        'tickets' => app_mgs_service_native_count($db, 'SELECT COUNT(*) FROM portal_tickets'),
        'openTickets' => app_mgs_service_native_count($db, 'SELECT COUNT(*) FROM portal_tickets WHERE status NOT IN ("closed","resolved")'),
        'inventoryItems' => app_mgs_service_native_count($db, 'SELECT COUNT(*) FROM inventory_stock_items'),
        'inventoryMovements' => app_mgs_service_native_count($db, 'SELECT COUNT(*) FROM inventory_stock_movements'),
        'employees' => app_mgs_service_native_count($db, "SELECT COUNT(*) FROM portal_accounts WHERE role = 'employee'"),
        'radiusOnline' => app_mgs_service_native_count($db, 'SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL'),
    ];
    $summaryRows = [
        ['title' => 'Musteriler', 'value' => $summary['customers'], 'note' => 'Portal accounts'],
        ['title' => 'Aciq ticket', 'value' => $summary['openTickets'], 'note' => 'CRM / NOC'],
        ['title' => 'Online sessiya', 'value' => $summary['radiusOnline'], 'note' => 'RADIUS accounting'],
        ['title' => 'Anbar mehsulu', 'value' => $summary['inventoryItems'], 'note' => 'Inventory items'],
        ['title' => 'Anbar hereketi', 'value' => $summary['inventoryMovements'], 'note' => 'Posted movements'],
        ['title' => 'Emekdas', 'value' => $summary['employees'], 'note' => 'Active staff accounts'],
    ];

    $devices = app_mgs_service_native_rows(
        $db,
        'SELECT device_key, device_name AS name, device_type AS type, vendor, model, ip_address AS ip,
                management_network AS location, reachability_state, status
         FROM device_learning_profiles
         ORDER BY updated_at DESC, id DESC',
        [],
        20
    );
    $alarms = app_mgs_service_native_rows(
        $db,
        'SELECT title, severity, status, last_seen_at
         FROM mgs_alarm_events
         ORDER BY FIELD(severity, "critical", "high", "warning", "info"), last_seen_at DESC',
        [],
        12
    );
    $tickets = app_mgs_service_native_rows(
        $db,
        'SELECT ticket_no, customer_name, subject, status, priority, opened_at
         FROM portal_tickets
         ORDER BY opened_at DESC, id DESC',
        [],
        12
    );

    $inventoryProducts = app_mgs_service_native_rows(
        $db,
        'SELECT id,
                sku,
                item_name,
                item_name AS name,
                category,
                unit,
                unit AS unitName,
                quantity_on_hand,
                quantity_on_hand AS quantityOnHand,
                minimum_quantity,
                minimum_quantity AS minStockLevel
         FROM inventory_stock_items
         ORDER BY item_name, sku',
        [],
        30
    );
    $inventoryCategories = app_mgs_service_native_rows(
        $db,
        'SELECT category_code AS code,
                category_name AS name,
                description,
                status
           FROM inventory_categories
          WHERE status = "active"
          ORDER BY sort_order, category_name',
        [],
        50
    );
    if ($inventoryCategories === []) {
        $categoryRows = app_mgs_service_native_rows(
            $db,
            'SELECT DISTINCT category AS code,
                    category AS name,
                    "" AS description,
                    "active" AS status
               FROM inventory_stock_items
              WHERE category IS NOT NULL AND category <> ""
              ORDER BY category',
            [],
            50
        );
        $inventoryCategories = $categoryRows !== [] ? $categoryRows : [
            ['code' => 'ont_modem', 'name' => 'ONT / Modem', 'description' => 'Musteriye qurasdirilan ONT ve modem avadanliqlari', 'status' => 'active'],
            ['code' => 'router', 'name' => 'Router', 'description' => 'Router ve CPE cihazlari', 'status' => 'active'],
            ['code' => 'switch', 'name' => 'Switch', 'description' => 'Switch ve access avadanliqlari', 'status' => 'active'],
            ['code' => 'sfp', 'name' => 'SFP / Optik modul', 'description' => 'Optik modul ve transceiver', 'status' => 'active'],
            ['code' => 'fiber_cable', 'name' => 'Fiber kabel', 'description' => 'Kabel barabani ve metrajli fiber', 'status' => 'active'],
            ['code' => 'connector', 'name' => 'Connector', 'description' => 'Fast connector ve patchcord', 'status' => 'active'],
            ['code' => 'tool', 'name' => 'Alet', 'description' => 'Texnik aletleri', 'status' => 'active'],
            ['code' => 'other', 'name' => 'Diger', 'description' => 'Diger inventar', 'status' => 'active'],
        ];
    }
    $inventoryMovements = app_mgs_service_native_rows(
        $db,
        'SELECT m.movement_type, m.quantity, m.status, m.created_at, m.note, i.sku, i.item_name,
                tech.full_name AS technician_name, cust.full_name AS customer_name
         FROM inventory_stock_movements m
         JOIN inventory_stock_items i ON i.id = m.item_id
         LEFT JOIN portal_accounts tech ON tech.id = m.technician_account_id
         LEFT JOIN portal_accounts cust ON cust.id = m.customer_account_id
         ORDER BY m.created_at DESC, m.id DESC',
        [],
        20
    );
    $employees = app_mgs_service_native_rows(
        $db,
        'SELECT id, full_name, login, account_type, employee_role, status
         FROM portal_accounts
         WHERE role = "employee"
         ORDER BY full_name, id',
        [],
        50
    );
    $branches = function_exists('app_db_branches') ? app_db_branches($db) : [];
    $financeEntries = app_mgs_service_native_rows(
        $db,
        'SELECT subscriber_code, amount, payment_method, transaction_id, created_at
         FROM billing_payments
         ORDER BY created_at DESC, id DESC',
        [],
        20
    );
    $bots = app_mgs_service_native_rows(
        $db,
        'SELECT bot_key, display_name AS bot_name, status, purpose, destination_masked
         FROM telegram_bot_registry
         ORDER BY bot_key',
        [],
        20
    );

    return match ($cleanPath) {
        '/api/service/platform-summary' => [
            'summary' => $summaryRows,
            'metrics' => $summary,
            'platform' => [
                'devices' => $devices,
                'alarms' => $alarms,
                'tickets' => $tickets,
            ],
        ],
        '/api/service/network-dashboard', '/api/service/devices' => [
            'summary' => $summaryRows,
            'metrics' => $summary,
            'platform' => [
                'devices' => $devices,
                'alarms' => $alarms,
                'tickets' => $tickets,
            ],
            'devices' => $devices,
        ],
        '/api/service/inventory' => [
            'summary' => [
                ['title' => 'Mehsul tipi', 'value' => $summary['inventoryItems'], 'note' => 'Aktiv nomenklatura'],
                ['title' => 'Hereket', 'value' => $summary['inventoryMovements'], 'note' => 'Medaxil/mexaric/tehvil'],
                ['title' => 'Isci uzerinde', 'value' => $summary['employees'], 'note' => 'Emekdas hesab sayi'],
            ],
            'metrics' => $summary,
            'products' => $inventoryProducts,
            'categories' => $inventoryCategories,
            'balances' => $inventoryProducts,
            'movements' => $inventoryMovements,
            'assets' => $inventoryMovements,
            'handovers' => $inventoryMovements,
            'branches' => $branches,
            'sources' => ['native_mysql_fallback'],
        ],
        '/api/service/finance' => [
            'summary' => [
                ['title' => 'Odenisler', 'value' => app_mgs_service_native_count($db, 'SELECT COUNT(*) FROM billing_payments'), 'note' => 'Payment rows'],
                ['title' => 'Bugun', 'value' => number_format(app_mgs_service_native_count($db, 'SELECT COUNT(*) FROM billing_payments WHERE DATE(created_at) = CURDATE()'), 0), 'note' => 'Bugunku say'],
                ['title' => 'Borclu ticket', 'value' => $summary['openTickets'], 'note' => 'Context KPI'],
            ],
            'metrics' => $summary,
            'entries' => $financeEntries,
            'branches' => $branches,
        ],
        '/api/service/technical' => [
            'summary' => $summaryRows,
            'metrics' => $summary,
            'tasks' => $tickets,
            'planning' => [],
            'branches' => $branches,
            'users' => $employees,
        ],
        '/api/service/attendance' => [
            'summary' => $summaryRows,
            'metrics' => $summary,
            'presenceBoard' => [],
            'profiles' => $employees,
            'users' => $employees,
            'branches' => $branches,
        ],
        '/api/service/kpi' => [
            'summary' => $summaryRows,
            'metrics' => $summary,
            'leaderboard' => $employees,
            'awards' => [],
        ],
        '/api/service/reports' => [
            'summary' => $summaryRows,
            'metrics' => $summary,
            'branchSummaries' => $branches,
            'opticalTrendSummary' => [],
        ],
        '/api/service/telegram-bots' => [
            'summary' => $summaryRows,
            'metrics' => $summary,
            'bots' => $bots,
        ],
        default => null,
    };
}

function app_mgs_service_get_json(string $path, array $query = [], int $timeout = 20): array
{
    $cleanPath = '/' . ltrim($path, '/');
    $queryString = $query !== [] ? ('?' . http_build_query($query)) : '';
    $pathWithQuery = $cleanPath . $queryString;
    $baseUrl = app_mgs_internal_base_url();
    $url = $baseUrl . $pathWithQuery;
    $native = app_mgs_service_native_fallback($cleanPath);

    if (is_array($native)) {
        return [
            'ok' => true,
            'status' => 200,
            'data' => $native,
            'error' => '',
            'url' => $url,
        ];
    }
    if (!app_mgs_internal_service_online($baseUrl)) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'service_unavailable', 'url' => $url];
    }

    $headers = app_mgs_service_headers($pathWithQuery, 'GET', '');
    $result = http_request_raw($url, 'GET', '', $headers, $timeout);

    $decoded = [];
    if ($result['body'] !== '') {
        $json = json_decode((string) $result['body'], true);
        if (is_array($json)) {
            $decoded = $json;
        }
    }

    $ok = (bool) ($result['ok'] ?? false);
    return [
        'ok' => $ok,
        'status' => (int) ($result['status'] ?? 0),
        'data' => $decoded,
        'error' => (string) ($decoded['error'] ?? ''),
        'url' => $url,
    ];
}

function app_mgs_service_native_request_fallback(string $path, string $method, array $payload): ?array
{
    $cleanPath = '/' . trim($path, '/');
    $normalizedMethod = strtoupper(trim($method));
    if (!in_array($normalizedMethod, ['POST', 'PUT', 'PATCH'], true)) {
        return null;
    }

    $db = app_db();
    $user = app_portal_current_user();

    try {
        if ($cleanPath === '/api/service/attendance/profiles') {
            if (!function_exists('app_mgs_save_hr_attendance_profile')) {
                return null;
            }
            $native = app_mgs_save_hr_attendance_profile($payload, is_array($user) ? $user : [], $db);
            return [
                'ok' => !empty($native['ok']),
                'status' => !empty($native['ok']) ? 200 : 422,
                'data' => [
                    'profile' => is_array($native['profile'] ?? null) ? $native['profile'] : [],
                    'source' => 'unified_portal',
                ],
                'error' => (string) ($native['error'] ?? ''),
            ];
        }

        if ($cleanPath === '/api/service/telegram-bots/test-message') {
            if (!function_exists('app_mgs_send_telegram_bot_test')) {
                return null;
            }
            $scope = clean_text((string) ($payload['scope'] ?? 'general'));
            $message = clean_text((string) ($payload['text'] ?? ''));
            $native = app_mgs_send_telegram_bot_test(
                $scope !== '' ? $scope : 'general',
                $message !== '' ? $message : 'SuperOptic test mesaji',
                '',
                true
            );
            return [
                'ok' => !empty($native['ok']),
                'status' => !empty($native['ok']) ? 200 : 422,
                'data' => [
                    'sent' => !empty($native['sent']),
                    'source' => 'unified_portal',
                ],
                'error' => (string) ($native['error'] ?? $native['reason'] ?? ''),
            ];
        }

        if ($cleanPath === '/api/service/technical-tasks') {
            if (!function_exists('app_mgs_create_technical_task')) {
                return null;
            }
            $native = app_mgs_create_technical_task($payload, is_array($user) ? $user : [], $db);
            return [
                'ok' => !empty($native['ok']),
                'status' => !empty($native['ok']) ? 200 : 422,
                'data' => [
                    'ticket_no' => (string) ($native['ticket_no'] ?? ''),
                    'source' => 'unified_portal',
                ],
                'error' => (string) ($native['error'] ?? ''),
            ];
        }
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'status' => 500,
            'data' => [],
            'error' => 'unified_portal_action_failed: ' . $exception->getMessage(),
        ];
    }

    return null;
}

function app_mgs_service_request_json(string $path, string $method = 'POST', array $payload = [], int $timeout = 20): array
{
    $cleanPath = '/' . ltrim($path, '/');
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        $body = '{}';
    }

    $baseUrl = app_mgs_internal_base_url();
    $url = $baseUrl . $cleanPath;
    $normalizedMethod = strtoupper(trim($method));
    if ($normalizedMethod === '') {
        $normalizedMethod = 'POST';
    }

    $native = app_mgs_service_native_request_fallback($cleanPath, $normalizedMethod, $payload);
    if (is_array($native)) {
        return [
            'ok' => (bool) ($native['ok'] ?? false),
            'status' => (int) ($native['status'] ?? 0),
            'data' => is_array($native['data'] ?? null) ? $native['data'] : [],
            'error' => (string) ($native['error'] ?? ''),
            'url' => $url,
        ];
    }

    if (!app_mgs_internal_service_online($baseUrl)) {
        return [
            'ok' => false,
            'status' => 0,
            'data' => [],
            'error' => 'service_unavailable',
            'url' => $url,
        ];
    }

    $headers = array_merge(
        ['Content-Type: application/json; charset=UTF-8'],
        app_mgs_service_headers($cleanPath, $normalizedMethod, $body)
    );
    $result = http_request_raw($url, $normalizedMethod, $body, $headers, $timeout);

    $decoded = [];
    if ($result['body'] !== '') {
        $json = json_decode((string) $result['body'], true);
        if (is_array($json)) {
            $decoded = $json;
        }
    }

    $ok = (bool) ($result['ok'] ?? false);
    return [
        'ok' => $ok,
        'status' => (int) ($result['status'] ?? 0),
        'data' => $decoded,
        'error' => (string) ($decoded['error'] ?? ''),
        'url' => $url,
    ];
}

function app_mgs_native_module_url(string $moduleKey): string
{
    $module = clean_text($moduleKey);
    if ($module === '') {
        $module = 'monitor';
    }

    $map = [
        'monitor' => 'operations-center.php?section=noc',
        'ont' => 'noc-devices.php?view=devices',
        'mpls' => 'network-definitions.php',
        'inventory' => 'operations-center.php?section=inventory',
        'finance' => 'operations-center.php?section=finance',
        'technical' => 'operations-center.php?section=technical',
        'kpi' => 'operations-center.php?section=hr&focus=kpi',
        'reports' => 'statistics-center.php',
        'stats' => 'billing-statistics.php',
        'logs' => 'noc-devices.php?view=devices',
        'backup' => 'operations-center.php?section=reports&focus=backup',
        'hr' => 'operations-center.php?section=hr',
        'users' => 'admin-users.php',
    ];

    return $map[$module] ?? 'operations-center.php';
}

function app_mgs_bridge_url(string $moduleKey, bool $shellView = true): string
{
    return app_mgs_native_module_url($moduleKey);
}

function app_portal_olt_role(string $employeeRole): string
{
    $configured = app_setting('employee_olt_role_map', []);
    if (is_array($configured) && isset($configured[$employeeRole]) && is_string($configured[$employeeRole])) {
        return $configured[$employeeRole];
    }

    $defaults = [
        'support' => 'operator',
        'noc' => 'admin',
        'sales' => 'operator',
        'operator' => 'operator',
        'technician' => 'operator',
        'admin' => 'admin',
        'super_admin' => 'admin',
    ];

    return $defaults[$employeeRole] ?? 'operator';
}

function app_portal_employee_links(array $user): array
{
    $role = (string) ($user['employee_role'] ?? '');
    $configured = app_setting('employee_olt_links', []);
    if (is_array($configured) && isset($configured[$role]) && is_array($configured[$role])) {
        $links = $configured[$role];
    } else {
        $defaults = [
            'support' => [
                [
                    'slug' => 'olt-support-overview',
                    'kind' => 'olt_sso',
                    'label' => 'ONU baxis paneli',
                    'scope' => 'Abonent seviyyesinde baxis ve ilkin diaqnostika',
                    'panel' => 'support',
                    'required_olt_role' => 'operator',
                ],
                [
                    'slug' => 'olt-support-tickets',
                    'kind' => 'olt_sso',
                    'label' => 'Ariza yonlendirme paneli',
                    'scope' => 'Yeni problem qeydleri ve status izleme',
                    'panel' => 'support',
                    'required_olt_role' => 'operator',
                ],
            ],
            'noc' => [
                [
                    'slug' => 'olt-noc-control',
                    'kind' => 'olt_sso',
                    'label' => 'OLT idareetme paneli',
                    'scope' => 'Port, profil, signal ve toplu emeliyyatlar',
                    'panel' => 'noc',
                    'required_olt_role' => 'admin',
                ],
                [
                    'slug' => 'olt-noc-onu',
                    'kind' => 'olt_sso',
                    'label' => 'ONU provisioning paneli',
                    'scope' => 'ONU qeydiyyati, silinmesi ve profil deyisikliyi',
                    'panel' => 'noc',
                    'required_olt_role' => 'admin',
                ],
            ],
            'sales' => [
                [
                    'slug' => 'olt-sales-plan',
                    'kind' => 'olt_sso',
                    'label' => 'Yeni qosulma planlama',
                    'scope' => 'Yeni abonent axini ve erazi yoxlamasi',
                    'panel' => 'sales',
                    'required_olt_role' => 'operator',
                ],
            ],
            'technician' => [
                [
                    'slug' => 'olt-technician-view',
                    'kind' => 'olt_sso',
                    'label' => 'Texnik OLT baxisi',
                    'scope' => 'ONU ve siqnal seviyyelerine baxis',
                    'panel' => 'field',
                    'required_olt_role' => 'operator',
                ],
            ],
            'admin' => [
                [
                    'slug' => 'olt-admin-control',
                    'kind' => 'olt_sso',
                    'label' => 'OLT admin paneli',
                    'scope' => 'Rol, istifadeci ve tam idareetme',
                    'panel' => 'admin',
                    'required_olt_role' => 'admin',
                ],
            ],
        ];

        $links = $defaults[$role] ?? [];
    }

    $resolved = [];
    foreach ($links as $index => $link) {
        if (!is_array($link)) {
            continue;
        }

        $slug = clean_text((string) ($link['slug'] ?? $role . '-olt-' . $index));
        $kind = (string) ($link['kind'] ?? 'olt_sso');
        $resolved[] = [
            'slug' => $slug,
            'kind' => $kind,
            'label' => (string) ($link['label'] ?? 'OLT paneli'),
            'scope' => (string) ($link['scope'] ?? ''),
            'panel' => (string) ($link['panel'] ?? 'main'),
            'required_olt_role' => (string) ($link['required_olt_role'] ?? app_portal_olt_role($role)),
            'fallback_url' => (string) ($link['fallback_url'] ?? $link['url'] ?? '#'),
            'url' => $kind === 'olt_sso'
                ? 'portal-bridge.php?target=olt&slug=' . rawurlencode($slug)
                : (string) ($link['url'] ?? '#'),
        ];
    }

    return $resolved;
}

function app_portal_find_employee_link(array $user, string $slug): ?array
{
    foreach (app_portal_employee_links($user) as $link) {
        if (($link['slug'] ?? '') === $slug) {
            return $link;
        }
    }

    return null;
}

function app_portal_olt_handoff_url(array $user, array $link): ?string
{
    $entrypoint = app_config('olt_sso_entrypoint');
    $secret = app_environment_secret('OLT_SSO_SHARED_SECRET');
    $fallback = (string) ($link['fallback_url'] ?? '');
    $safeFallback = $fallback !== '' && $fallback !== '#'
        ? $fallback
        : 'noc-devices.php?view=devices';

    if ($entrypoint === '' || $secret === '') {
        return $safeFallback;
    }

    $issuer = app_config('olt_sso_issuer', 'portal.superoptic.net');
    $audience = app_config('olt_sso_audience', 'superoptic-mgs');
    $returnPath = (string) ($link['return_path'] ?? app_config('olt_sso_default_return', '/'));
    $now = time();
    $token = app_portal_sign_token([
        'iss' => $issuer,
        'aud' => $audience,
        'sub' => (string) ($user['id'] ?? ''),
        'username' => (string) ($user['login'] ?? ''),
        'name' => (string) ($user['name'] ?? ''),
        'role' => (string) ($link['required_olt_role'] ?? app_portal_olt_role((string) ($user['employee_role'] ?? ''))),
        'employee_role' => (string) ($user['employee_role'] ?? ''),
        'panel' => (string) ($link['panel'] ?? 'main'),
        'scope' => (string) ($link['slug'] ?? 'olt'),
        'iat' => $now,
        'nbf' => $now - 5,
        'exp' => $now + 60,
        'jti' => bin2hex(random_bytes(12)),
    ], $secret);

    return app_portal_build_handoff_url($entrypoint, $token, $returnPath);
}

function app_portal_billing_handoff_url(array $user): ?string
{
    $role = (string) ($user['role'] ?? 'customer');
    $fallbackUrl = $role === 'employee'
        ? app_config('billing_employee_url', app_config('cabinet_url', ''))
        : app_config('billing_customer_url', app_config('cabinet_url', ''));

    $entrypoint = app_config('billing_sso_entrypoint');
    $secret = app_environment_secret('BILLING_SSO_SHARED_SECRET');
    if ($entrypoint === '' || $secret === '') {
        return $fallbackUrl !== '' ? $fallbackUrl : null;
    }

    $issuer = app_config('billing_sso_issuer', 'portal.superoptic.net');
    $audience = app_config('billing_sso_audience', 'billing.superoptic.net');
    $returnPath = app_config('billing_sso_default_return', '/');
    $now = time();
    $token = app_portal_sign_token([
        'iss' => $issuer,
        'aud' => $audience,
        'sub' => (string) ($user['id'] ?? ''),
        'username' => (string) ($user['login'] ?? ''),
        'name' => (string) ($user['name'] ?? ''),
        'role' => $role,
        'employee_role' => (string) ($user['employee_role'] ?? ''),
        'category' => (string) ($user['category'] ?? ''),
        'iat' => $now,
        'nbf' => $now - 5,
        'exp' => $now + 60,
        'jti' => bin2hex(random_bytes(12)),
    ], $secret);

    return app_portal_build_handoff_url($entrypoint, $token, $returnPath);
}

function app_portal_login_destination(array $user): string
{
    if (!empty($user['must_change_password'])) {
        return 'change-password.php';
    }
    $role = (string) ($user['role'] ?? 'customer');
    $setting = $role === 'employee'
        ? app_config('portal_employee_landing', 'portal')
        : app_config('portal_customer_landing', 'portal');

    return match ($setting) {
        'billing' => 'portal-bridge.php?target=billing',
        'olt' => 'portal-bridge.php?target=olt',
        default => 'portal.php',
    };
}

function app_mock_portal_data(): array
{
    if (!app_demo_fixtures_enabled()) {
        return ['customers' => [], 'employees' => [], 'technicians' => []];
    }

    $data = require __DIR__ . '/data/mock-portal.php';
    return is_array($data) ? $data : ['customers' => [], 'employees' => [], 'technicians' => []];
}

function app_azerbaijan_districts(): array
{
    return [
        'Abşeron', 'Ağcabədi', 'Ağdam', 'Ağdaş', 'Ağstafa', 'Ağsu', 'Astara', 'Babək', 'Balakən', 'Bakı',
        'Bərdə', 'Beyləqan', 'Biləsuvar', 'Cəbrayıl', 'Cəlilabad', 'Daşkəsən', 'Füzuli', 'Gədəbəy', 'Gəncə',
        'Goranboy', 'Göyçay', 'Göygöl', 'Göytəpə', 'Hacıqabul', 'Horadiz', 'İmişli', 'İsmayıllı', 'Kəlbəcər',
        'Kürdəmir', 'Qax', 'Qazax', 'Qəbələ', 'Qobustan', 'Quba', 'Qubadlı', 'Qusar', 'Laçın', 'Lerik',
        'Lənkəran', 'Masallı', 'Mingəçevir', 'Naftalan', 'Naxçıvan', 'Neftçala', 'Oğuz', 'Ordubad', 'Saatlı',
        'Sabirabad', 'Salyan', 'Samux', 'Şabran', 'Şahbuz', 'Şamaxı', 'Şəki', 'Şəmkir', 'Şərur', 'Şirvan',
        'Siyəzən', 'Sumqayıt', 'Tərtər', 'Tovuz', 'Ucar', 'Xaçmaz', 'Xankəndi', 'Xırdalan', 'Xızı', 'Xocalı',
        'Xocavənd', 'Yardımlı', 'Yevlax', 'Zaqatala', 'Zəngilan', 'Zərdab',
    ];
}

function app_area_interest_log_path(): string
{
    return __DIR__ . '/data/area-interest-log.jsonl';
}

function app_record_area_interest(string $district): bool
{
    $district = clean_text($district);
    if ($district === '' || !in_array($district, app_azerbaijan_districts(), true)) {
        return false;
    }

    $line = json_encode([
        'district' => $district,
        'created_at' => date('c'),
        'month' => date('Y-m'),
    ], JSON_UNESCAPED_UNICODE);

    if (!is_string($line)) {
        return false;
    }

    return file_put_contents(app_area_interest_log_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}

function app_area_interest_monthly_summary(int $limit = 4): array
{
    $path = app_area_interest_log_path();
    if (!file_exists($path)) {
        return ['total' => 0, 'items' => []];
    }

    $rows = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($rows)) {
        return ['total' => 0, 'items' => []];
    }

    $targetMonth = date('Y-m');
    $counts = [];
    $total = 0;

    foreach ($rows as $row) {
        $decoded = json_decode($row, true);
        if (!is_array($decoded) || ($decoded['month'] ?? '') !== $targetMonth) {
            continue;
        }

        $district = (string) ($decoded['district'] ?? '');
        if ($district === '') {
            continue;
        }

        $counts[$district] = ($counts[$district] ?? 0) + 1;
        $total++;
    }

    arsort($counts);
    $items = [];
    foreach (array_slice($counts, 0, $limit, true) as $district => $count) {
        $items[] = [
            'district' => $district,
            'count' => $count,
        ];
    }

    return [
        'total' => $total,
        'items' => $items,
    ];
}

function app_tv_channels(): array
{
    $file = __DIR__ . '/data/tv-channels.json';
    if (!file_exists($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

if (PHP_SAPI !== 'cli') {
    app_enforce_https((bool) app_setting('portal_force_https', true));
}
app_send_security_headers();
app_start_html_text_repair();
