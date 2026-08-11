<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(64);
}

$root = dirname(__DIR__);
$preflightPath = $root . '/deploy/ubuntu-production-preflight.sh';
$bootstrapPath = $root . '/deploy/set-superoptic-secrets.sh';
$secretSmokePath = $root . '/tools/secret-readiness-smoke.php';

$preflight = is_file($preflightPath) ? (string) file_get_contents($preflightPath) : '';
$bootstrap = is_file($bootstrapPath) ? (string) file_get_contents($bootstrapPath) : '';
$secretSmoke = is_file($secretSmokePath) ? (string) file_get_contents($secretSmokePath) : '';

/** @return list<string> */
function preflight_shell_array(string $source, string $name): array
{
    $pattern = '/declare\s+-[a-zA-Z]*a[a-zA-Z]*\s+' . preg_quote($name, '/') . '=\(\s*(.*?)\s*\)/s';
    if (preg_match($pattern, $source, $matches) !== 1) {
        return [];
    }

    $tokens = preg_split('/\s+/', trim($matches[1])) ?: [];
    return array_values(array_filter(array_map(
        static fn (string $token): string => trim($token, " \t\n\r\0\x0B'\""),
        $tokens
    ), static fn (string $token): bool => $token !== ''));
}

/** @return array<string,string> */
function preflight_php_aliases(string $source): array
{
    if (preg_match('/\$aliases\s*=\s*\[(.*?)\];/s', $source, $section) !== 1) {
        return [];
    }
    preg_match_all("/'([A-Z0-9_]+)'\\s*=>\\s*'([A-Z0-9_]+)'/", $section[1], $matches, PREG_SET_ORDER);
    $aliases = [];
    foreach ($matches as $match) {
        $aliases[$match[1]] = $match[2];
    }
    return $aliases;
}

function preflight_has_all(string $source, array $markers): bool
{
    foreach ($markers as $marker) {
        if (!str_contains($source, (string) $marker)) {
            return false;
        }
    }
    return true;
}

$expectedCanonical = [];
if (preg_match('/SUPEROPTIC_SECRET_KEYS=\(\s*(.*?)\s*\)/s', $bootstrap, $canonicalSection) === 1) {
    $expectedCanonical = preg_split('/\s+/', trim($canonicalSection[1])) ?: [];
}
$expectedAliases = preflight_php_aliases($secretSmoke);
$actualCanonical = preflight_shell_array($preflight, 'CANONICAL_SECRET_KEYS');
$actualAliases = preflight_shell_array($preflight, 'ALIAS_SECRET_KEYS');
$planPayload = [];
if (preg_match("/if \\[\\[ \"\\\$\\{PLAN\\}\" == \"1\" \\]\\]; then\\s+cat <<'JSON'\\s+(\\{.*?\\})\\s+JSON/s", $preflight, $planSection) === 1) {
    $decodedPlan = json_decode($planSection[1], true);
    $planPayload = is_array($decodedPlan) ? $decodedPlan : [];
}

$expectedServices = [
    'superoptic-billing-maintenance.service',
    'superoptic-communication-queue.service',
    'superoptic-pg365-status.service',
    'superoptic-radius-coa-worker.service',
    'superoptic-modem-bot.service',
    'superoptic-system-optimizer.service',
    'superoptic-security-anomaly.service',
    'superoptic-network-olt-rules.service',
];
$expectedTimers = [
    'superoptic-billing-maintenance.timer',
    'superoptic-communication-queue.timer',
    'superoptic-pg365-status.timer',
    'superoptic-radius-coa-worker.timer',
    'superoptic-modem-bot.timer',
    'superoptic-system-optimizer.timer',
    'superoptic-security-anomaly.timer',
    'superoptic-network-olt-rules.timer',
];

$checks = [
    'files_present' => $preflight !== '' && $bootstrap !== '' && $secretSmoke !== '',
    'bash_entrypoint' => str_starts_with($preflight, "#!/usr/bin/env bash\nset -Eeuo pipefail\nset +x\n"),
    'canonical_contract_40' => count($actualCanonical) === 40
        && $actualCanonical === $expectedCanonical
        && str_contains($preflight, '${#CANONICAL_SECRET_KEYS[@]} == 40')
        && str_contains($preflight, 'environment file contains 40 canonical and 13 alias values'),
    'alias_contract_13' => count($actualAliases) === 13 && $actualAliases === array_keys($expectedAliases),
    'alias_targets_present' => array_reduce(
        array_keys($expectedAliases),
        static fn (bool $ok, string $alias): bool => $ok
            && str_contains($preflight, '[' . $alias . ']=' . $expectedAliases[$alias]),
        true
    ),
    'no_secret_output_contract' => preflight_has_all($preflight, [
        'set +x',
        'secrets_exposed=false',
        '"secrets_exposed": false',
        'missing_or_invalid_names=',
    ])
        && preg_match('/(^|\s)(printenv|env)(\s|$)/m', $preflight) !== 1
        && !str_contains($preflight, 'cat "${SECRET_ENV_FILE}"')
        && !str_contains($preflight, 'systemctl show-environment'),
    'plan_is_early_and_side_effect_free' => preflight_has_all($preflight, [
        'if [[ "${PLAN}" == "1" ]]',
        '"network_requests": 0',
        '"files_written": 0',
        '"provider_calls": 0',
        '"router_calls": 0',
        '"olt_calls": 0',
        '"canonical_secret_count": 40',
        '"alias_secret_count": 13',
    ])
        && ($planPayload['ok'] ?? false) === true
        && ($planPayload['mode'] ?? '') === 'plan'
        && ($planPayload['canonical_secret_count'] ?? 0) === 40
        && ($planPayload['alias_secret_count'] ?? 0) === 13
        && ($planPayload['network_requests'] ?? -1) === 0
        && ($planPayload['files_written'] ?? -1) === 0
        && ($planPayload['provider_calls'] ?? -1) === 0
        && ($planPayload['router_calls'] ?? -1) === 0
        && ($planPayload['olt_calls'] ?? -1) === 0
        && ($planPayload['secrets_exposed'] ?? true) === false
        && strpos($preflight, 'if [[ "${PLAN}" == "1" ]]') < strpos($preflight, 'if [[ "${EUID}" -ne 0 ]]'),
    'root_systemd_runtime_checks' => preflight_has_all($preflight, [
        '${EUID}',
        '/run/systemd/system',
        'systemctl is-system-running',
        'systemctl show --property=Version',
    ]),
    'apache_php_optional_node_mysql_checks' => preflight_has_all($preflight, [
        'systemctl is-active --quiet apache2.service',
        'apache2ctl configtest',
        'PHP 8.1',
        'pdo_mysql',
        'Node.js 20',
        'mysql.service',
        'mariadb.service',
        'SET SESSION TRANSACTION READ ONLY',
    ]),
    'protected_environment_contract' => preflight_has_all($preflight, [
        '/etc/superoptic-mgs/superoptic.env',
        "stat -c '%U:%G'",
        "stat -c '%a'",
        'root:root',
        "'600'",
        'EnvironmentFiles',
    ]),
    'apache_local_probe_cleanup' => preflight_has_all($preflight, [
        'superoptic-production-preflight-XXXXXXXX',
        "https://\${APP_DOMAIN}/\${probe_name}?token=\${probe_token}",
        '--resolve "${APP_DOMAIN}:443:127.0.0.1"',
        'hash_equals($expectedToken, $providedToken)',
        'cleanup_probe_file',
        'Apache HTTPS probe file was removed immediately',
        'restore_visibility_evidence',
    ]),
    'ephemeral_www_data_visibility' => preflight_has_all($preflight, [
        'systemd-run',
        '--wait',
        '--pipe',
        '--collect',
        '--property=User=www-data',
        '--property=Group=www-data',
        '--property="EnvironmentFile=${SECRET_ENV_FILE}"',
        '--property=ProtectSystem=strict',
        '--property=IPAddressDeny=any',
        '--property=IPAddressAllow=localhost',
        'secret-visibility-lib.php',
        'count($required) === 53',
        'all 40 canonical and 13 alias values',
    ]),
    'services_contract' => preflight_shell_array($preflight, 'APPLICATION_SERVICES') === $expectedServices
        && str_contains($preflight, 'runtime_application_services=("${APPLICATION_SERVICES[@]}")')
        && !preg_match('/^\s*APPLICATION_SERVICES=\(/m', $preflight),
    'scoped_service_principals' => preflight_has_all($preflight, [
        'APPLICATION_SERVICE_USERS',
        'APPLICATION_SERVICE_SCOPES',
        'superoptic-node',
        'superoptic-communication',
        '/services/${expected_scope}.env',
        "file_owner",
        "file_mode",
    ]),
    'timers_contract' => preflight_shell_array($preflight, 'APPLICATION_TIMERS') === $expectedTimers
        && str_contains($preflight, 'systemctl is-active --quiet "${timer_unit}"')
        && str_contains($preflight, 'systemctl is-enabled --quiet "${timer_unit}"'),
    'timezone_disk_ports_tls' => preflight_has_all($preflight, [
        'Asia/Baku',
        'timedatectl show --property=Timezone',
        'df -P -- "${WEB_ROOT}"',
        'df -Pi -- "${WEB_ROOT}"',
        'for public_port in 80 443',
        'private_ports=(3306)',
        'private_ports+=(3000)',
        'openssl s_client',
        '-connect 127.0.0.1:443',
        '-verify_hostname "${APP_DOMAIN}"',
    ]),
    'production_readiness_contract' => preflight_has_all($preflight, [
        '${WEB_ROOT}/tools/production-readiness-check.php',
        '--production',
        'production readiness result is blocked or invalid',
    ]) && str_contains((string) file_get_contents($root . '/tools/production-readiness-check.php'), "['apache-http', 'apache-https']"),
    'no_persistent_mutation_commands' => preg_match(
        '/\b(apt|apt-get|ufw|nft|iptables)\b|systemctl\s+(enable|disable|start|restart|reload|daemon-reload)\b/i',
        $preflight
    ) !== 1,
    'localhost_only_network_contract' => !str_contains($preflight, 'http://')
        && substr_count($preflight, 'https://${APP_DOMAIN}/') === 1
        && substr_count($preflight, '--resolve "${APP_DOMAIN}:443:127.0.0.1"') === 1
        && substr_count($preflight, '-connect 127.0.0.1:443') === 1
        && substr_count($preflight, 'mysql:host=127.0.0.1;') === 1,
    'no_provider_router_olt_invocation' => !str_contains($preflight, 'provider-api-smoke.php')
        && !str_contains($preflight, 'provider-up-api-smoke.php')
        && !str_contains($preflight, 'process-network-olt-ticket-rules.php')
        && !str_contains($preflight, 'device-backup-worker.php')
        && preg_match('/\b(radclient|snmpwalk|ssh|telnet|wget)\b/', $preflight) !== 1,
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));

echo json_encode([
    'ok' => $failed === [],
    'canonical_count' => count($actualCanonical),
    'alias_count' => count($actualAliases),
    'checks' => $checks,
    'failed' => $failed,
    'network_requests' => 0,
    'files_written' => 0,
    'secrets_exposed' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
