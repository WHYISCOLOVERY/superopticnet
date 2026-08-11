<?php
declare(strict_types=1);

$script = (string) file_get_contents(dirname(__DIR__) . '/deploy/setup-ubuntu-freeradius-isolated.sh');

$checks = [
    'canonical_portal_database' => str_contains($script, 'DB_NAME="${DB_NAME:-superoptic_portal}"')
        && !str_contains($script, 'CREATE DATABASE'),
    'loopback_only' => str_contains($script, 'RADIUS_LISTEN_IP="${RADIUS_LISTEN_IP:-127.0.0.1}"')
        && str_contains($script, 'Isolated mode only permits 127.0.0.1')
        && str_contains($script, 'proxy_requests = no')
        && str_contains($script, 'FreeRADIUS has a non-loopback UDP listener'),
    'nonstandard_staging_ports' => str_contains($script, 'RADIUS_AUTH_PORT="${RADIUS_AUTH_PORT:-11812}"')
        && str_contains($script, 'RADIUS_ACCT_PORT="${RADIUS_ACCT_PORT:-11813}"'),
    'standard_ports_rejected' => str_contains($script, 'Standard RADIUS ports must remain closed during isolated staging'),
    'no_network_nas_client' => !str_contains($script, 'RADIUS_ALLOWED_NAS_SOURCE')
        && !str_contains($script, 'client superoptic-lab-range')
        && !str_contains($script, 'ufw allow'),
    'local_mysql_accounts_only' => str_contains($script, "DROP USER IF EXISTS '\${RADIUS_DB_USER}'@'%'")
        && !str_contains($script, "CREATE USER IF NOT EXISTS '\${RADIUS_DB_USER}'@'%'"),
    'table_scoped_runtime_grants' => str_contains($script, 'GRANT SELECT ON \`${DB_NAME}\`.radcheck')
        && str_contains($script, 'GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.radacct')
        && !str_contains($script, 'GRANT ALL'),
    'secrets_externalized' => str_contains($script, 'upsert_env_key')
        && str_contains($script, 'chmod 0640 "${sql_file}"')
        && !preg_match('/RADIUS_(?:DB_PASS|SHARED_SECRET)="[^"$]/', $script),
    'smoke_data_is_removed' => str_contains($script, 'SOT-RADIUS-SMOKE-')
        && str_contains($script, 'DELETE FROM radcheck WHERE username')
        && str_contains($script, 'trap cleanup_smoke EXIT'),
    'portal_endpoint_matches_staging' => str_contains($script, 'update_portal_radius_endpoint')
        && str_contains($script, '"radius_auth_port"')
        && str_contains($script, '"radius_acct_port"'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
