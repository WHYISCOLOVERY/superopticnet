<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require dirname(__DIR__) . '/integration-api-client-lib.php';

$options = getopt('', [
    'provider:',
    'name:',
    'scopes::',
    'allowed-ips::',
    'expires-at::',
    'output:',
]);
$provider = trim((string) ($options['provider'] ?? ''));
$name = trim((string) ($options['name'] ?? ''));
$output = trim((string) ($options['output'] ?? ''));
if ($provider === '' || $name === '' || $output === '') {
    fwrite(STDERR, "Usage: --provider=emanat --name=\"Emanat production\" --output=/secure/path/emanat.json [--allowed-ips=IP,CIDR]\n");
    exit(2);
}
if (file_exists($output)) {
    fwrite(STDERR, "output_file_already_exists\n");
    exit(3);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database_unavailable\n");
    exit(4);
}
$adminLogin = strtolower(trim(app_config('super_admin_login', 's.abdullayev@superoptic.net')));
$actor = app_db_account_by_login($adminLogin);
if (!is_array($actor) || !app_portal_is_super_admin($actor)) {
    fwrite(STDERR, "designated_super_admin_unavailable\n");
    exit(5);
}

$scopesText = trim((string) ($options['scopes'] ?? 'customer:read,payment:write,payment:status'));
$scopes = preg_split('/[\s,;]+/', $scopesText) ?: [];
$created = app_integration_api_create($db, $actor, [
    'provider_slug' => $provider,
    'display_name' => $name,
    'scopes' => $scopes,
    'allowed_ips' => (string) ($options['allowed-ips'] ?? ''),
    'expires_at' => (string) ($options['expires-at'] ?? ''),
]);
$handoff = [
    'provider' => $created['provider_slug'],
    'display_name' => $created['display_name'],
    'public_key_id' => $created['key_id'],
    'private_secret' => $created['secret'],
    'authorization' => 'Bearer ' . $created['key_id'] . '.' . $created['secret'],
    'scopes' => $created['scopes'],
    'allowed_ips' => $created['allowed_ips'],
    'expires_at' => $created['expires_at'],
    'signature' => 'lowercase hex HMAC-SHA256(timestamp + "\\n" + raw_body, private_secret)',
    'created_at' => date(DATE_ATOM),
];

$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('output_directory_create_failed');
}
$encoded = json_encode($handoff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded) || file_put_contents($output, $encoded . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('credential_handoff_write_failed');
}
@chmod($output, 0600);

echo json_encode([
    'ok' => true,
    'provider' => $created['provider_slug'],
    'public_key_id' => $created['key_id'],
    'private_secret_exposed_to_stdout' => false,
    'handoff_path' => $output,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
