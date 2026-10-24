<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$installerPath = $root . '/deploy/ubuntu-install-superoptic-mgs.sh';
$installer = is_file($installerPath) ? (string) file_get_contents($installerPath) : '';
$secretBootstrapPath = $root . '/deploy/set-superoptic-secrets.sh';
$secretBootstrap = is_file($secretBootstrapPath) ? (string) file_get_contents($secretBootstrapPath) : '';

$checks = [
    'installer_present' => $installer !== '',
    'timezone_configured' => str_contains($installer, 'timedatectl set-timezone "${APP_TIMEZONE}"')
        && str_contains($installer, '"app_timezone" "${APP_TIMEZONE}"'),
    'source_code_root_owned' => str_contains($installer, 'chown -R root:www-data "${WEB_ROOT}"')
        && !str_contains($installer, 'chown -R www-data:www-data "${WEB_ROOT}"'),
    'runtime_data_excluded' => str_contains($installer, '--exclude "data/security-events.jsonl"')
        && str_contains($installer, '--exclude "data/test-*"'),
    'runtime_write_scope_explicit' => str_contains($installer, 'approved writable runtime files')
        && str_contains($installer, 'chmod 0660 "${WEB_ROOT}/data/${runtime_file}"'),
    'apache_private_paths_denied' => substr_count($installer, '<Directory ${WEB_ROOT}/data>') >= 2
        && substr_count($installer, '<Directory ${WEB_ROOT}/tmp>') >= 2
        && str_contains($installer, 'Require all denied'),
    'apache_local_probe_vhost' => str_contains($installer, 'ServerAlias localhost 127.0.0.1')
        && str_contains($installer, 'a2dissite 000-default.conf'),
    'tls_modes_explicit' => str_contains($installer, 'letsencrypt|existing|deferred|off')
        && str_contains($installer, 'certbot --apache --non-interactive'),
    'deferred_tls_requires_override' => str_contains($installer, 'ALLOW_DEFERRED_TLS')
        && str_contains($installer, 'TLS is deferred.')
        && str_contains($installer, 'Production TLS_MODE must be letsencrypt or existing'),
    'firewall_preserves_ssh' => str_contains($installer, 'ufw allow "${SSH_PORT}/tcp"')
        && str_contains($installer, 'No SSH listener is active')
        && str_contains($installer, 'ufw default deny incoming')
        && str_contains($installer, 'ufw default allow outgoing'),
    'radius_firewall_scoped' => str_contains($installer, 'RADIUS_ALLOWED_NAS_SOURCE')
        && str_contains($installer, 'ufw allow from "${RADIUS_ALLOWED_NAS_SOURCE}"'),
    'preinstall_backup_hashed' => str_contains($installer, 'create_preinstall_backup')
        && str_contains($installer, 'sha256sum ./* >SHA256SUMS'),
    'systemd_services_hardened' => str_contains($installer, 'ProtectSystem=strict')
        && str_contains($installer, 'NoNewPrivileges=true')
        && str_contains($installer, 'ReadWritePaths='),
    'node_lockfile_install' => str_contains($installer, "npm_action='ci'")
        && str_contains($installer, 'prune --omit=dev --ignore-scripts'),
    'node_build_is_unprivileged' => str_contains($installer, 'runuser -u "${node_user}" -- env -i')
        && str_contains($installer, 'runuser -u superoptic-node -- env -i')
        && str_contains($installer, 'HOME=/home/superoptic-node')
        && str_contains($installer, 'useradd --system --create-home --home-dir "${node_home}"')
        && str_contains($installer, 'database-only environment visibility'),
    'node_install_has_no_curl_bash' => !str_contains($installer, 'deb.nodesource.com/setup_20.x | bash')
        && str_contains($installer, 'checksum-pinned official Node.js')
        && str_contains($installer, 'df770b2a6f130ed8627c9782c988fda9669fa23898329a61a871e32f965e007d')
        && str_contains($installer, "curl --fail --silent --show-error --location --proto '=https' --tlsv1.2"),
    'runtime_db_has_no_ddl' => str_contains($installer, 'REVOKE ALL PRIVILEGES ON \`${DB_NAME}\`.*')
        && str_contains($installer, 'GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE')
        && str_contains($installer, '"db_bootstrap_mode" "always"')
        && str_contains($installer, '"db_bootstrap_mode" "off"')
        && str_contains($installer, 'migrate_portal_schema')
        && str_contains($installer, 'tools/run-portal-migrations.php')
        && str_contains($installer, 'trap cleanup_installer_exit EXIT')
        && str_contains($installer, 'RUNTIME_DDL_OPEN=1'),
    'systemd_secrets_are_scoped' => str_contains($installer, '/services/node.env')
        && str_contains($installer, '/services/communication.env')
        && str_contains($installer, 'User=superoptic-node')
        && str_contains($installer, 'User=superoptic-communication')
        && str_contains($installer, 'ProtectProc=invisible'),
    'secret_files_are_root_only' => str_contains($secretBootstrap, 'chmod 0600 "${SUPEROPTIC_SECRET_TEMP_FILE}"')
        && str_contains($secretBootstrap, 'superoptic_secret_write_service_files')
        && str_contains($secretBootstrap, 'services'),
    'rotation_preserves_unrelated_values' => str_contains($secretBootstrap, '--rotate requires at least one explicitly supplied secret or metadata value')
        && !str_contains($secretBootstrap, 'else
                unset "${key}"'),
    'explicit_initial_admin' => str_contains($installer, 'create_initial_super_admin')
        && str_contains($installer, 'INITIAL_ADMIN_PASSWORD is required')
        && str_contains($installer, 'tools/create-initial-super-admin.php'),
    'production_preflight_gate' => str_contains($installer, 'Production preflight cannot be disabled')
        && str_contains($installer, 'run_production_preflight')
        && str_contains($installer, 'ubuntu-production-preflight.sh'),
    'deepseek_non_secret_metadata' => str_contains($installer, 'AI_MODEL_PROVIDER="${AI_MODEL_PROVIDER:-deepseek}"')
        && str_contains($installer, 'DEEPSEEK_BASE_URL="${DEEPSEEK_BASE_URL:-https://api.deepseek.com}"')
        && str_contains($installer, 'DEEPSEEK_MODEL="${DEEPSEEK_MODEL:-deepseek-v4-flash}"'),
    'local_llm_loopback_metadata' => str_contains($installer, 'LOCAL_LLM_ENDPOINT="${LOCAL_LLM_ENDPOINT:-http://127.0.0.1:11434}"')
        && str_contains($installer, 'LOCAL_LLM_MODEL="${LOCAL_LLM_MODEL:-qwen2.5:7b-instruct-q4_K_M}"')
        && str_contains($installer, 'LOCAL_LLM_API="${LOCAL_LLM_API:-ollama}"')
        && str_contains($installer, 'AI_MODEL_PROVIDER must be local, deepseek or disabled')
        && str_contains($installer, 'LOCAL_LLM_ENDPOINT must use the loopback Ollama endpoint')
        && str_contains($secretBootstrap, 'LOCAL_LLM_ENDPOINT must use the loopback Ollama endpoint'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
