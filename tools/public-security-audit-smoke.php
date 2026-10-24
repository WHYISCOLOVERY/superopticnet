<?php
declare(strict_types=1);

/** Static go-live guard: checks that the repository's public boundary and
 * proxy/secret hardening cannot silently regress. It never connects to a
 * device, sends a message or changes the database. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
$checks = [];
$failures = [];
$check = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) {
        $failures[] = $name;
    }
};
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    return is_file($path) ? (string) file_get_contents($path) : '';
};

$htaccess = $read('.htaccess');
$apache = $read('deploy/apache/000-superoptic-mgs.conf');
$cpanel = $read('deploy/cpanel/.htaccess');
$config = $read('config.php');
$mgs = $read('mgs-integration-lib.php');
$ops = $read('operations-center.php');
$health = $read('api/health.php');
$status = $read('status.php');

$check('root_htaccess_exists', $htaccess !== '');
$check('root_denies_internal_dirs', preg_match('/output\|config\|graphify-out|\.git|\.ai-collaboration|\.claude/', $htaccess) === 1);
$check('root_denies_local_secrets', preg_match('/config\\\.local\\\.php|\\\.env\(\?:\\\\\.\.*\)/', $htaccess) === 1);
$check('apache_denies_internal_dirs', preg_match('/DirectoryMatch.*(?:graphify-out|\.git|output|config)/s', $apache) === 1);
$check('apache_denies_local_secrets', str_contains($apache, 'config\\.local\\.php'));
$check('cpanel_denies_local_secrets', str_contains($cpanel, 'config\\.local\\.php'));
$check('cpanel_https_redirect_host_allowlist', str_contains($cpanel, 'RewriteCond %{HTTP_HOST} ^(?:mgt\\.superoptic\\.net|superoptic\\.net|www\\.superoptic\\.net)'));
$check('trusted_proxy_gate_present', str_contains($config, 'function app_trusted_proxy_request') && str_contains($config, 'trusted_proxy_ips'));
$check('client_ip_uses_trusted_proxy_gate', preg_match('/function app_client_ip\(\).*?app_trusted_proxy_request/s', $config) === 1);
$check('https_redirect_host_allowlist_present', str_contains($config, 'function app_allowed_request_hosts') && str_contains($config, 'function app_safe_redirect_host') && str_contains($config, '$host = app_safe_redirect_host()'));
$check('hardcoded_snmp_communities_removed', !str_contains($mgs, 'SuperOptikRO') && !str_contains($mgs, 'SuperOptikRW'));
$check('update_upload_requires_real_upload', str_contains($ops, 'is_uploaded_file($tmpName)'));
$check('update_upload_has_size_bound', str_contains($ops, 'mgs_update_max_upload_bytes'));
$check('update_upload_randomized_name', str_contains($ops, 'bin2hex(random_bytes(6))'));
$check('update_upload_private_permissions', str_contains($ops, '@chmod($targetPath, 0600)'));
$check('public_health_details_disabled_by_default', str_contains($health, "app_setting('health_detail_enabled', '0')") && str_contains($status, "app_setting('health_detail_enabled', '0')"));
$check('zip_extension_guard_documented', str_contains($read('docs/41_PUBLIC_SECURITY_AUDIT_2026-09-07.md'), 'ZipArchive'));

$secretFiles = [];
foreach (['.env', 'config.local.php'] as $file) {
    if (is_file($root . DIRECTORY_SEPARATOR . $file)) {
        $secretFiles[] = $file;
    }
}
// Local development files may exist, but the smoke makes the boundary visible
// so CI/deploy jobs can require a generated public package instead.
$check('local_secret_files_are_not_public_package', !is_dir($root . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'cpanel-superoptic-site' . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . '.env'));

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failed' => $failures,
    'local_secret_files_detected' => $secretFiles,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
