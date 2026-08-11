#!/usr/bin/env bash
set -Eeuo pipefail
set +x

# Production validation must never echo secret values, including on failures.
umask 027

PLAN=0
WEB_ROOT="${WEB_ROOT:-/var/www/superopticnet}"
SECRET_ENV_FILE="${SECRET_ENV_FILE:-/etc/superoptic-mgs/superoptic.env}"
APP_DOMAIN="${APP_DOMAIN:-billing.superoptic.net}"
MIN_DISK_FREE_PERCENT="${MIN_DISK_FREE_PERCENT:-10}"
MIN_INODE_FREE_PERCENT="${MIN_INODE_FREE_PERCENT:-5}"
TLS_MIN_VALID_SECONDS="${TLS_MIN_VALID_SECONDS:-1209600}"

declare -ar CANONICAL_SECRET_KEYS=(
    DB_PASS
    RADIUS_DB_PASS
    RADIUS_ROUTER_SECRET
    DEEPSEEK_API_KEY
    SMS_PROVIDER_PUBLIC_KEY
    SMS_PROVIDER_PRIVATE_KEY
    TELEGRAM_TOKEN
    TELEGRAM_NETWORK_BOT_TOKEN
    TELEGRAM_CUSTOMER_BOT_TOKEN
    TELEGRAM_FINANCE_BOT_TOKEN
    TELEGRAM_WAREHOUSE_BOT_TOKEN
    TELEGRAM_BACKUP_BOT_TOKEN
    TELEGRAM_DIAGNOSTICS_BOT_TOKEN
    TELEGRAM_MODEM_SEARCH_BOT_TOKEN
    WHATSAPP_PROVIDER_TOKEN
    RADIUS_SSH_PASSWORD
    RADIUS_TEST_PASSWORD
    SNMP_COMMUNITY
    IMPORT_API_TOKEN
    COPILOT_API_TOKEN
    RECAPTCHA_SECRET
    BILLING_SSO_SHARED_SECRET
    OLT_SSO_SHARED_SECRET
    OLT_USER_SYNC_SECRET
    MYGOV_CLIENT_SECRET
    API_SECRET_KEY
    PAIRING_WORKER_TOKEN
    CLICKHOUSE_PASSWORD
    PORTAL_TICKET_CLOSE_SECRET
    PROVIDER_API_TOKEN
    PROVIDER_API_HMAC_SECRET
    EMANAT_PROVIDER_API_TOKEN
    EMANAT_PROVIDER_HMAC_SECRET
    UNITEDPAYMENT_PROVIDER_API_TOKEN
    UNITEDPAYMENT_PROVIDER_HMAC_SECRET
    MGS_SERVICE_SECRET
    MGS_JWT_SECRET
    MGS_SECRETS_MASTER_KEY
    MGS_PORTAL_SHARED_SECRET
    MGS_TELEGRAM_WEBHOOK_SECRET
)

declare -ar ALIAS_SECRET_KEYS=(
    RADIUS_SQL_PASSWORD
    RADIUS_SHARED_SECRET
    SMS_PROVIDER_USER
    SMS_PROVIDER_PASSWORD
    MGS_SERVICE_SHARED_SECRET
    SERVICE_SHARED_SECRET
    JWT_SECRET
    SECRETS_MASTER_KEY
    PORTAL_SHARED_SECRET
    TELEGRAM_WEBHOOK_SECRET
    TELEGRAM_BOT_TOKEN
    WHATSAPP_PROVIDER_API_KEY
    SMS_PROVIDER_API_KEY
)

declare -Ar SECRET_ALIAS_TARGETS=(
    [RADIUS_SQL_PASSWORD]=RADIUS_DB_PASS
    [RADIUS_SHARED_SECRET]=RADIUS_ROUTER_SECRET
    [SMS_PROVIDER_USER]=SMS_PROVIDER_PUBLIC_KEY
    [SMS_PROVIDER_PASSWORD]=SMS_PROVIDER_PRIVATE_KEY
    [MGS_SERVICE_SHARED_SECRET]=MGS_SERVICE_SECRET
    [SERVICE_SHARED_SECRET]=MGS_SERVICE_SECRET
    [JWT_SECRET]=MGS_JWT_SECRET
    [SECRETS_MASTER_KEY]=MGS_SECRETS_MASTER_KEY
    [PORTAL_SHARED_SECRET]=MGS_PORTAL_SHARED_SECRET
    [TELEGRAM_WEBHOOK_SECRET]=MGS_TELEGRAM_WEBHOOK_SECRET
    [TELEGRAM_BOT_TOKEN]=TELEGRAM_TOKEN
    [WHATSAPP_PROVIDER_API_KEY]=WHATSAPP_PROVIDER_TOKEN
    [SMS_PROVIDER_API_KEY]=SMS_PROVIDER_PRIVATE_KEY
)

declare -ar APPLICATION_SERVICES=(
    superoptic-billing-maintenance.service
    superoptic-communication-queue.service
    superoptic-pg365-status.service
    superoptic-radius-coa-worker.service
    superoptic-modem-bot.service
    superoptic-system-optimizer.service
    superoptic-security-anomaly.service
    superoptic-network-olt-rules.service
)

declare -Ar APPLICATION_SERVICE_USERS=(
    [superoptic-mgs-node.service]=superoptic-node
    [superoptic-billing-maintenance.service]=superoptic-billing
    [superoptic-communication-queue.service]=superoptic-communication
    [superoptic-pg365-status.service]=superoptic-pg365
    [superoptic-radius-coa-worker.service]=superoptic-radius
    [superoptic-modem-bot.service]=superoptic-modem
    [superoptic-system-optimizer.service]=superoptic-optimizer
    [superoptic-security-anomaly.service]=superoptic-security
    [superoptic-network-olt-rules.service]=superoptic-network
)

declare -Ar APPLICATION_SERVICE_SCOPES=(
    [superoptic-mgs-node.service]=node
    [superoptic-billing-maintenance.service]=billing
    [superoptic-communication-queue.service]=communication
    [superoptic-pg365-status.service]=pg365
    [superoptic-radius-coa-worker.service]=radius
    [superoptic-modem-bot.service]=modem
    [superoptic-system-optimizer.service]=optimizer
    [superoptic-security-anomaly.service]=security
    [superoptic-network-olt-rules.service]=network
)

declare -ar APPLICATION_TIMERS=(
    superoptic-billing-maintenance.timer
    superoptic-communication-queue.timer
    superoptic-pg365-status.timer
    superoptic-radius-coa-worker.timer
    superoptic-modem-bot.timer
    superoptic-system-optimizer.timer
    superoptic-security-anomaly.timer
    superoptic-network-olt-rules.timer
)

PASSED=0
WARNINGS=0
FAILED=0
PROBE_FILE=""
EVIDENCE_PATH="${WEB_ROOT}/tmp/service-secret-visibility.json"
EVIDENCE_BACKUP=""
EVIDENCE_ACTIVE=0
EVIDENCE_HAD_ORIGINAL=0
CURRENT_TRANSIENT_UNIT=""

usage() {
    cat <<'EOF'
Usage: ubuntu-production-preflight.sh [options]

Options:
  --plan                       Print the side-effect-free validation plan.
  --web-root=PATH              Portal document root.
  --env-file=PATH              Protected systemd EnvironmentFile.
  --app-domain=HOST            TLS SNI and Apache Host header.
  --min-disk-free=PERCENT      Minimum free filesystem percentage.
  --min-inode-free=PERCENT     Minimum free inode percentage.
  --help                       Show this help.

Real mode requires root. It performs only read-only checks, localhost HTTP/TLS
probes, and collected transient systemd tasks. Secret values are never printed.
EOF
}

for argument in "$@"; do
    case "${argument}" in
        --plan) PLAN=1 ;;
        --web-root=*) WEB_ROOT="${argument#*=}" ;;
        --env-file=*) SECRET_ENV_FILE="${argument#*=}" ;;
        --app-domain=*) APP_DOMAIN="${argument#*=}" ;;
        --min-disk-free=*) MIN_DISK_FREE_PERCENT="${argument#*=}" ;;
        --min-inode-free=*) MIN_INODE_FREE_PERCENT="${argument#*=}" ;;
        --help|-h) usage; exit 0 ;;
        *) printf 'ERROR: unknown option: %s\n' "${argument}" >&2; usage >&2; exit 64 ;;
    esac
done

EVIDENCE_PATH="${WEB_ROOT}/tmp/service-secret-visibility.json"

if [[ "${PLAN}" == "1" ]]; then
    cat <<'JSON'
{
  "ok": true,
  "mode": "plan",
  "checks": [
    "root_systemd_runtime",
    "apache_php_node_mysql",
    "protected_environment_owner_mode",
    "36_canonical_and_13_alias_visibility",
    "apache_local_http_probe_and_cleanup",
    "www_data_transient_systemd_visibility",
    "services_and_timers",
    "asia_baku_timezone",
    "disk_and_inodes",
    "local_ports_and_tls",
    "production_readiness_result"
  ],
  "canonical_secret_count": 40,
  "alias_secret_count": 13,
  "network_requests": 0,
  "files_written": 0,
  "provider_calls": 0,
  "router_calls": 0,
  "olt_calls": 0,
  "secrets_exposed": false
}
JSON
    exit 0
fi

pass_check() {
    printf '[OK] %s\n' "$1"
    ((PASSED += 1))
}

warn_check() {
    printf '[WARN] %s\n' "$1" >&2
    ((WARNINGS += 1))
}

fail_check() {
    printf '[FAIL] %s\n' "$1" >&2
    ((FAILED += 1))
}

cleanup_probe_file() {
    if [[ -n "${PROBE_FILE}" && -e "${PROBE_FILE}" ]]; then
        rm -f -- "${PROBE_FILE}" >/dev/null 2>&1 || return 1
    fi
    PROBE_FILE=""
    return 0
}

restore_visibility_evidence() {
    local temporary_restore=""
    if [[ "${EVIDENCE_ACTIVE}" != "1" ]]; then
        [[ -z "${EVIDENCE_BACKUP}" ]] || rm -f -- "${EVIDENCE_BACKUP}" >/dev/null 2>&1 || true
        EVIDENCE_BACKUP=""
        EVIDENCE_HAD_ORIGINAL=0
        return 0
    fi

    if [[ "${EVIDENCE_HAD_ORIGINAL}" == "1" && -f "${EVIDENCE_BACKUP}" ]]; then
        temporary_restore="$(mktemp --tmpdir="$(dirname "${EVIDENCE_PATH}")" '.superoptic-preflight-restore-XXXXXXXX')" || return 1
        if ! cp --preserve=all -- "${EVIDENCE_BACKUP}" "${temporary_restore}" \
            || ! mv -f -- "${temporary_restore}" "${EVIDENCE_PATH}"; then
            rm -f -- "${temporary_restore}" >/dev/null 2>&1 || true
            return 1
        fi
    else
        rm -f -- "${EVIDENCE_PATH}" || return 1
    fi

    [[ -z "${EVIDENCE_BACKUP}" ]] || rm -f -- "${EVIDENCE_BACKUP}" >/dev/null 2>&1 || true
    EVIDENCE_BACKUP=""
    EVIDENCE_ACTIVE=0
    EVIDENCE_HAD_ORIGINAL=0
    return 0
}

cleanup_runtime_artifacts() {
    cleanup_probe_file || true
    restore_visibility_evidence || true
    if [[ -n "${CURRENT_TRANSIENT_UNIT}" ]] && command -v systemctl >/dev/null 2>&1; then
        systemctl stop "${CURRENT_TRANSIENT_UNIT}" >/dev/null 2>&1 || true
    fi
}
trap cleanup_runtime_artifacts EXIT HUP INT TERM

if [[ "${EUID}" -ne 0 ]]; then
    fail_check 'root execution is required'
    printf '[SUMMARY] passed=%d warnings=%d failed=%d secrets_exposed=false\n' "${PASSED}" "${WARNINGS}" "${FAILED}"
    exit 1
fi
pass_check 'running as root'

if [[ ! "${WEB_ROOT}" =~ ^/[^[:cntrl:]]+$ || ! "${SECRET_ENV_FILE}" =~ ^/[^[:cntrl:]]+$ ]]; then
    fail_check 'web root and environment file must be absolute paths'
fi
if [[ ! "${APP_DOMAIN}" =~ ^[A-Za-z0-9.-]+$ || "${APP_DOMAIN}" == .* || "${APP_DOMAIN}" == *. ]]; then
    fail_check 'application domain is invalid'
fi
if [[ ! "${MIN_DISK_FREE_PERCENT}" =~ ^[0-9]+$ || "${MIN_DISK_FREE_PERCENT}" -gt 100 ]]; then
    fail_check 'minimum disk percentage is invalid'
fi
if [[ ! "${MIN_INODE_FREE_PERCENT}" =~ ^[0-9]+$ || "${MIN_INODE_FREE_PERCENT}" -gt 100 ]]; then
    fail_check 'minimum inode percentage is invalid'
fi
if (( FAILED > 0 )); then
    printf '[SUMMARY] passed=%d warnings=%d failed=%d secrets_exposed=false\n' "${PASSED}" "${WARNINGS}" "${FAILED}"
    exit 1
fi

required_commands=(
    systemctl systemd-run apache2ctl php curl openssl timeout ss stat awk grep
    df timedatectl mktemp cp mv rm chown chmod basename dirname
)
LEGACY_MGS_NODE_INSTALLED=0
if [[ "$(systemctl show superoptic-mgs-node.service --property=LoadState --value 2>/dev/null || true)" == 'loaded' ]]; then
    LEGACY_MGS_NODE_INSTALLED=1
    required_commands+=(node)
fi
missing_commands=()
for command_name in "${required_commands[@]}"; do
    command -v "${command_name}" >/dev/null 2>&1 || missing_commands+=("${command_name}")
done
if (( ${#missing_commands[@]} > 0 )); then
    fail_check "required commands are missing: ${missing_commands[*]}"
    printf '[SUMMARY] passed=%d warnings=%d failed=%d secrets_exposed=false\n' "${PASSED}" "${WARNINGS}" "${FAILED}"
    exit 1
fi
pass_check 'required Ubuntu runtime commands are installed'

if [[ -d /run/systemd/system ]] && systemctl show --property=Version --value >/dev/null 2>&1; then
    pass_check 'systemd is the active service manager'
else
    fail_check 'systemd runtime is unavailable'
fi
systemd_state="$(systemctl is-system-running 2>/dev/null || true)"
case "${systemd_state}" in
    running) pass_check 'systemd reports running state' ;;
    degraded) warn_check 'systemd reports degraded state; inspect unrelated failed units' ;;
    *) fail_check 'systemd is not in running or degraded state' ;;
esac

if [[ -d "${WEB_ROOT}" && -r "${WEB_ROOT}/config.php" && -r "${WEB_ROOT}/tools/production-readiness-check.php" ]]; then
    pass_check 'portal web root and readiness tool are readable'
else
    fail_check 'portal web root or readiness tool is unavailable'
fi

if systemctl is-active --quiet apache2.service; then
    pass_check 'Apache service is active'
else
    fail_check 'Apache service is not active'
fi
if apache2ctl configtest >/dev/null 2>&1; then
    pass_check 'Apache configuration test passed'
else
    fail_check 'Apache configuration test failed'
fi
apache_environment_files="$(systemctl show apache2.service --property=EnvironmentFiles --value 2>/dev/null || true)"
if grep -Fq -- "${SECRET_ENV_FILE}" <<<"${apache_environment_files}"; then
    pass_check 'Apache references the protected environment file'
else
    fail_check 'Apache does not reference the protected environment file'
fi

if php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' >/dev/null 2>&1; then
    pass_check 'PHP CLI version is supported'
else
    fail_check 'PHP 8.1 or newer is required'
fi
php_modules="$(php -m 2>/dev/null || true)"
missing_php_modules=()
for php_module in PDO pdo_mysql curl json openssl; do
    grep -Eiq "^${php_module}$" <<<"${php_modules}" || missing_php_modules+=("${php_module}")
done
if (( ${#missing_php_modules[@]} == 0 )); then
    pass_check 'required PHP modules are loaded'
else
    fail_check "required PHP modules are missing: ${missing_php_modules[*]}"
fi

if [[ "${LEGACY_MGS_NODE_INSTALLED}" == "0" ]]; then
    pass_check 'Node.js runtime is not required for the canonical PHP portal'
else
    node_runtime="$(command -v node 2>/dev/null || true)"
    if [[ -x /snap/bin/node ]]; then
        node_runtime=/snap/bin/node
    fi
    if [[ -n "${node_runtime}" ]] && "${node_runtime}" -e 'const major = Number(process.versions.node.split(".")[0]); process.exit(major >= 20 ? 0 : 1);' >/dev/null 2>&1; then
        pass_check 'Node.js 20 or newer is installed for optional legacy MGS'
    else
        fail_check 'Optional legacy MGS requires Node.js 20 or newer'
    fi
fi

mysql_unit=""
for candidate in mysql.service mariadb.service; do
    if [[ "$(systemctl show "${candidate}" --property=LoadState --value 2>/dev/null || true)" == "loaded" ]]; then
        mysql_unit="${candidate}"
        break
    fi
done
if [[ -n "${mysql_unit}" ]] && systemctl is-active --quiet "${mysql_unit}"; then
    pass_check "local MySQL service is active (${mysql_unit})"
else
    fail_check 'mysql.service or mariadb.service is not active'
fi

if [[ -f "${SECRET_ENV_FILE}" && ! -L "${SECRET_ENV_FILE}" ]]; then
    pass_check 'protected environment file is a regular non-symlink file'
else
    fail_check 'protected environment file is missing or is a symlink'
fi
env_owner="$(stat -c '%U:%G' -- "${SECRET_ENV_FILE}" 2>/dev/null || true)"
env_mode="$(stat -c '%a' -- "${SECRET_ENV_FILE}" 2>/dev/null || true)"
if [[ "${env_owner}" == 'root:root' && "${env_mode}" == '600' ]]; then
    pass_check 'protected environment owner and mode are root:root 0600'
else
    fail_check 'protected environment owner or mode differs from root:root 0600'
fi

environment_file_has_nonempty_key() {
    local wanted="$1"
    awk -v wanted="${wanted}" '
        /^[[:space:]]*(#|$)/ { next }
        {
            line = $0
            sub(/^[[:space:]]*/, "", line)
            equals = index(line, "=")
            if (equals == 0) { next }
            key = substr(line, 1, equals - 1)
            sub(/[[:space:]]*$/, "", key)
            if (key != wanted) { next }
            count++
            value = substr(line, equals + 1)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
            if (value != "" && value != "\"\"" && value != "\047\047") { nonempty = 1 }
        }
        END { exit(count == 1 && nonempty == 1 ? 0 : 1) }
    ' "${SECRET_ENV_FILE}" >/dev/null 2>&1
}

missing_environment_keys=()
for secret_key in "${CANONICAL_SECRET_KEYS[@]}" "${ALIAS_SECRET_KEYS[@]}"; do
    environment_file_has_nonempty_key "${secret_key}" || missing_environment_keys+=("${secret_key}")
done
if (( ${#CANONICAL_SECRET_KEYS[@]} == 40 && ${#ALIAS_SECRET_KEYS[@]} == 13 && ${#missing_environment_keys[@]} == 0 )); then
    pass_check 'environment file contains 40 canonical and 13 alias values'
else
    fail_check "environment visibility contract failed; missing_or_invalid_names=${missing_environment_keys[*]:-none}"
fi

unit_uses_protected_environment() {
    local unit="$1" environment_files user group expected_user expected_scope expected_file file_owner file_mode
    environment_files="$(systemctl show "${unit}" --property=EnvironmentFiles --value 2>/dev/null || true)"
    user="$(systemctl show "${unit}" --property=User --value 2>/dev/null || true)"
    group="$(systemctl show "${unit}" --property=Group --value 2>/dev/null || true)"
    expected_user="${APPLICATION_SERVICE_USERS[${unit}]-}"
    expected_scope="${APPLICATION_SERVICE_SCOPES[${unit}]-}"
    expected_file="$(dirname "${SECRET_ENV_FILE}")/services/${expected_scope}.env"
    [[ -n "${expected_user}" && -n "${expected_scope}" ]] || return 1
    [[ "${user}" == "${expected_user}" && "${group}" == 'www-data' ]] || return 1
    grep -Fq -- "${expected_file}" <<<"${environment_files}" || return 1
    grep -Fq -- "${SECRET_ENV_FILE}" <<<"${environment_files}" && return 1
    [[ -f "${expected_file}" && ! -L "${expected_file}" ]] || return 1
    file_owner="$(stat -c '%U:%G' -- "${expected_file}" 2>/dev/null || true)"
    file_mode="$(stat -c '%a' -- "${expected_file}" 2>/dev/null || true)"
    [[ "${file_owner}" == 'root:root' && "${file_mode}" == '600' ]]
}

runtime_application_services=("${APPLICATION_SERVICES[@]}")
if [[ "${LEGACY_MGS_NODE_INSTALLED}" == "1" ]]; then
    runtime_application_services=(superoptic-mgs-node.service "${runtime_application_services[@]}")
fi

for service_unit in "${runtime_application_services[@]}"; do
    if [[ "$(systemctl show "${service_unit}" --property=LoadState --value 2>/dev/null || true)" != 'loaded' ]]; then
        fail_check "systemd service is missing: ${service_unit}"
        continue
    fi
    if unit_uses_protected_environment "${service_unit}"; then
        pass_check "service uses a dedicated principal and root-only scoped environment: ${service_unit}"
    else
        fail_check "service principal/environment contract failed: ${service_unit}"
    fi
    service_result="$(systemctl show "${service_unit}" --property=Result --value 2>/dev/null || true)"
    if [[ "${service_result}" == 'failed' ]]; then
        fail_check "service last result is failed: ${service_unit}"
    fi
done

if [[ "${LEGACY_MGS_NODE_INSTALLED}" == "0" ]]; then
    pass_check 'Legacy MGS Node service is intentionally not installed'
elif systemctl is-active --quiet superoptic-mgs-node.service && systemctl is-enabled --quiet superoptic-mgs-node.service; then
    pass_check 'Optional MGS Node service is active and enabled'
else
    fail_check 'Optional MGS Node service is installed but not active and enabled'
fi

for timer_unit in "${APPLICATION_TIMERS[@]}"; do
    timer_service="${timer_unit%.timer}.service"
    configured_timer_service="$(systemctl show "${timer_unit}" --property=Unit --value 2>/dev/null || true)"
    if systemctl is-active --quiet "${timer_unit}" \
        && systemctl is-enabled --quiet "${timer_unit}" \
        && [[ "${configured_timer_service}" == "${timer_service}" ]] \
        && [[ "$(systemctl show "${timer_service}" --property=LoadState --value 2>/dev/null || true)" == 'loaded' ]]; then
        pass_check "timer is active, enabled, and linked to a service: ${timer_unit}"
    else
        fail_check "timer contract failed: ${timer_unit}"
    fi
done

system_timezone="$(timedatectl show --property=Timezone --value 2>/dev/null || true)"
if [[ "${system_timezone}" == 'Asia/Baku' ]]; then
    pass_check 'host timezone is Asia/Baku'
else
    fail_check 'host timezone is not Asia/Baku'
fi

if df -P -- "${WEB_ROOT}" 2>/dev/null | awk -v minimum="${MIN_DISK_FREE_PERCENT}" '
    NR == 2 { gsub(/%/, "", $5); exit((100 - $5) >= minimum ? 0 : 1) }
    END { if (NR < 2) exit 1 }
'; then
    pass_check "filesystem free space is at least ${MIN_DISK_FREE_PERCENT}%"
else
    fail_check "filesystem free space is below ${MIN_DISK_FREE_PERCENT}%"
fi
if df -Pi -- "${WEB_ROOT}" 2>/dev/null | awk -v minimum="${MIN_INODE_FREE_PERCENT}" '
    NR == 2 { gsub(/%/, "", $5); exit((100 - $5) >= minimum ? 0 : 1) }
    END { if (NR < 2) exit 1 }
'; then
    pass_check "filesystem free inodes are at least ${MIN_INODE_FREE_PERCENT}%"
else
    fail_check "filesystem free inodes are below ${MIN_INODE_FREE_PERCENT}%"
fi

port_is_listening() {
    local port="$1"
    ss -H -ltn 2>/dev/null | awk -v suffix=":${port}" '
        $4 ~ (suffix "$") { found = 1 }
        END { exit(found ? 0 : 1) }
    '
}

port_is_loopback_only() {
    local port="$1"
    ss -H -ltn 2>/dev/null | awk -v suffix=":${port}" '
        $4 ~ (suffix "$") {
            found = 1
            endpoint = $4
            sub(suffix "$", "", endpoint)
            gsub(/^\[/, "", endpoint)
            gsub(/\]$/, "", endpoint)
            if (endpoint != "127.0.0.1" && endpoint != "::1" && endpoint != "localhost") { unsafe = 1 }
        }
        END { exit(found && !unsafe ? 0 : 1) }
    '
}

for public_port in 80 443; do
    if port_is_listening "${public_port}"; then
        pass_check "TCP port ${public_port} is listening"
    else
        fail_check "TCP port ${public_port} is not listening"
    fi
done
private_ports=(3306)
if [[ "${LEGACY_MGS_NODE_INSTALLED}" == "1" ]]; then
    private_ports+=(3000)
fi
for private_port in "${private_ports[@]}"; do
    if port_is_loopback_only "${private_port}"; then
        pass_check "TCP port ${private_port} is bound only to loopback"
    else
        fail_check "TCP port ${private_port} is missing or exposed beyond loopback"
    fi
done

tls_output=""
tls_status=0
set +e
tls_output="$(timeout 12 openssl s_client \
    -connect 127.0.0.1:443 \
    -servername "${APP_DOMAIN}" \
    -verify_hostname "${APP_DOMAIN}" \
    -verify_return_error \
    -showcerts </dev/null 2>&1)"
tls_status=$?
set -e
leaf_certificate="$(awk '
    /-----BEGIN CERTIFICATE-----/ { capture = 1 }
    capture { print }
    /-----END CERTIFICATE-----/ { exit }
' <<<"${tls_output}")"
if [[ "${tls_status}" -eq 0 && -n "${leaf_certificate}" ]] \
    && openssl x509 -checkend "${TLS_MIN_VALID_SECONDS}" -noout <<<"${leaf_certificate}" >/dev/null 2>&1; then
    pass_check 'localhost TLS chain, hostname, and minimum validity passed'
else
    fail_check 'localhost TLS chain, hostname, or minimum validity failed'
fi
unset tls_output leaf_certificate

random_suffix() {
    openssl rand -hex 8
}

run_transient_capture() {
    local output_variable="$1" purpose="$2" output status unit_name
    shift 2
    unit_name="superoptic-preflight-${purpose}-$(random_suffix).service"
    CURRENT_TRANSIENT_UNIT="${unit_name}"
    set +e
    output="$(systemd-run \
        --quiet \
        --wait \
        --pipe \
        --collect \
        --unit="${unit_name}" \
        --property=Type=oneshot \
        --property=User=www-data \
        --property=Group=www-data \
        --property="WorkingDirectory=${WEB_ROOT}" \
        --property="EnvironmentFile=${SECRET_ENV_FILE}" \
        --property=NoNewPrivileges=yes \
        --property=PrivateTmp=yes \
        --property=ProtectSystem=strict \
        --property=ProtectHome=yes \
        --property='RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6' \
        --property=IPAddressDeny=any \
        --property=IPAddressAllow=localhost \
        "$@" 2>&1)"
    status=$?
    set -e
    CURRENT_TRANSIENT_UNIT=""
    printf -v "${output_variable}" '%s' "${output}"
    return "${status}"
}

task_visibility_code='require (string) ($argv[1] ?? "") . "/tools/secret-visibility-lib.php";
$required = app_service_secret_visibility_required_keys();
$missing = app_service_secret_visibility_missing_keys($required);
echo json_encode([
    "ok" => $missing === [] && count($required) === 53,
    "context" => "systemd-www-data-ephemeral",
    "required_count" => count($required),
    "missing_keys" => $missing,
    "secrets_exposed" => false,
], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($missing === [] && count($required) === 53 ? 0 : 2);'
task_visibility_output=""
if run_transient_capture task_visibility_output secret-visibility \
    /usr/bin/php -r "${task_visibility_code}" -- "${WEB_ROOT}" \
    && php -r '
        $result = json_decode(stream_get_contents(STDIN), true);
        exit(is_array($result)
            && !empty($result["ok"])
            && ($result["context"] ?? "") === "systemd-www-data-ephemeral"
            && (int) ($result["required_count"] ?? 0) === 53
            && ($result["missing_keys"] ?? null) === []
            && empty($result["secrets_exposed"]) ? 0 : 1);
    ' <<<"${task_visibility_output}" >/dev/null 2>&1; then
    pass_check 'ephemeral systemd www-data task sees all 40 canonical and 13 alias values'
else
    fail_check 'ephemeral systemd www-data secret visibility failed'
fi
unset task_visibility_output task_visibility_code

mysql_probe_code='require (string) ($argv[1] ?? "") . "/config.php";
$port = (string) (getenv("SUPEROPTIC_DB_PORT") ?: "3306");
$database = (string) (getenv("SUPEROPTIC_DB_NAME") ?: "");
$user = (string) (getenv("SUPEROPTIC_DB_USER") ?: "");
$password = (string) (getenv("DB_PASS") ?: "");
$safe = ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535
    && preg_match("/^[A-Za-z0-9_]+$/", $database) === 1 && $user !== "" && $password !== "";
if (!$safe) {
    echo json_encode(["ok" => false, "reason" => "db_metadata_invalid", "secrets_exposed" => false]), PHP_EOL;
    exit(2);
}
try {
    $pdo = new PDO(
        "mysql:host=127.0.0.1;port=" . $port . ";dbname=" . $database . ";charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    $pdo->exec("SET SESSION TRANSACTION READ ONLY");
    $row = $pdo->query("SELECT 1 AS probe, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS offset_minutes")
        ->fetch(PDO::FETCH_ASSOC);
    $timezone = new DateTimeZone("Asia/Baku");
    $expectedOffset = intdiv($timezone->getOffset(new DateTimeImmutable("now", $timezone)), 60);
    $appTimezone = function_exists("app_timezone") ? app_timezone() : date_default_timezone_get();
    $ok = (int) ($row["probe"] ?? 0) === 1
        && (int) ($row["offset_minutes"] ?? PHP_INT_MIN) === $expectedOffset
        && $appTimezone === "Asia/Baku"
        && date_default_timezone_get() === "Asia/Baku";
    echo json_encode([
        "ok" => $ok,
        "context" => "localhost-read-only-mysql",
        "timezone" => "Asia/Baku",
        "secrets_exposed" => false,
    ], JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($ok ? 0 : 2);
} catch (Throwable) {
    echo json_encode(["ok" => false, "reason" => "db_read_only_probe_failed", "secrets_exposed" => false]), PHP_EOL;
    exit(2);
}'
mysql_probe_output=""
if run_transient_capture mysql_probe_output mysql-readonly \
    /usr/bin/php -r "${mysql_probe_code}" -- "${WEB_ROOT}" \
    && php -r '
        $result = json_decode(stream_get_contents(STDIN), true);
        exit(is_array($result)
            && !empty($result["ok"])
            && ($result["context"] ?? "") === "localhost-read-only-mysql"
            && ($result["timezone"] ?? "") === "Asia/Baku"
            && empty($result["secrets_exposed"]) ? 0 : 1);
    ' <<<"${mysql_probe_output}" >/dev/null 2>&1; then
    pass_check 'localhost MySQL read-only query and PHP/MySQL timezone alignment passed'
else
    fail_check 'localhost MySQL read-only query or PHP/MySQL timezone alignment failed'
fi
unset mysql_probe_output mysql_probe_code

write_php_list() {
    local variable_name="$1"
    shift
    printf '$%s = [' "${variable_name}"
    printf "'%s'," "$@"
    printf '];\n'
}

write_php_alias_map() {
    local alias_name
    printf '$aliases = ['
    for alias_name in "${ALIAS_SECRET_KEYS[@]}"; do
        printf "'%s'=>'%s'," "${alias_name}" "${SECRET_ALIAS_TARGETS[${alias_name}]}"
    done
    printf '];\n'
}

create_apache_probe() {
    local probe_token="$1"
    PROBE_FILE="$(mktemp --tmpdir="${WEB_ROOT}" --suffix=.php 'superoptic-production-preflight-XXXXXXXX')" || return 1
    {
        cat <<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
PHP
        printf "\$expectedToken = '%s';\n" "${probe_token}"
        write_php_list canonical "${CANONICAL_SECRET_KEYS[@]}"
        write_php_alias_map
        cat <<'PHP'
$providedToken = (string) ($_GET['token'] ?? '');
if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'secrets_exposed' => false]);
    exit;
}
$missing = [];
$values = [];
foreach ($canonical as $key) {
    $value = getenv($key, true);
    if ($value === false) {
        $value = getenv($key);
    }
    if (!is_string($value) || trim($value) === '') {
        $missing[] = $key;
        continue;
    }
    $values[$key] = $value;
}
$inconsistentAliases = [];
foreach ($aliases as $alias => $canonicalKey) {
    $value = getenv($alias, true);
    if ($value === false) {
        $value = getenv($alias);
    }
    if (!is_string($value) || trim($value) === '') {
        $missing[] = $alias;
        continue;
    }
    if (!isset($values[$canonicalKey]) || !hash_equals($values[$canonicalKey], $value)) {
        $inconsistentAliases[] = $alias;
    }
}
echo json_encode([
    'ok' => $missing === [] && $inconsistentAliases === [],
    'context' => 'apache-https',
    'sapi' => PHP_SAPI,
    'canonical_count' => count($canonical),
    'alias_count' => count($aliases),
    'required_count' => count($canonical) + count($aliases),
    'missing_keys' => $missing,
    'inconsistent_aliases' => $inconsistentAliases,
    'checked_at' => date(DATE_ATOM),
    'secrets_exposed' => false,
], JSON_UNESCAPED_SLASHES);
PHP
    } >"${PROBE_FILE}" || return 1
    chown root:www-data -- "${PROBE_FILE}" || return 1
    chmod 0640 -- "${PROBE_FILE}" || return 1
}

activate_visibility_evidence() {
    local response="$1" evidence_directory evidence_temporary
    evidence_directory="$(dirname "${EVIDENCE_PATH}")"
    [[ -d "${evidence_directory}" ]] || return 1

    if [[ -e "${EVIDENCE_PATH}" ]]; then
        EVIDENCE_BACKUP="$(mktemp --tmpdir=/run 'superoptic-preflight-evidence-XXXXXXXX')" || return 1
        if ! cp --preserve=all -- "${EVIDENCE_PATH}" "${EVIDENCE_BACKUP}"; then
            rm -f -- "${EVIDENCE_BACKUP}" >/dev/null 2>&1 || true
            EVIDENCE_BACKUP=""
            return 1
        fi
        EVIDENCE_HAD_ORIGINAL=1
    fi

    if ! evidence_temporary="$(mktemp --tmpdir="${evidence_directory}" '.superoptic-preflight-evidence-XXXXXXXX')"; then
        restore_visibility_evidence
        return 1
    fi
    if ! printf '%s\n' "${response}" >"${evidence_temporary}" \
        || ! chown root:www-data -- "${evidence_temporary}" \
        || ! chmod 0640 -- "${evidence_temporary}" \
        || ! mv -f -- "${evidence_temporary}" "${EVIDENCE_PATH}"; then
        rm -f -- "${evidence_temporary}" >/dev/null 2>&1 || true
        restore_visibility_evidence
        return 1
    fi
    EVIDENCE_ACTIVE=1
}

probe_token="$(openssl rand -hex 24)"
apache_probe_response=""
apache_probe_valid=0
if create_apache_probe "${probe_token}"; then
    probe_name="$(basename "${PROBE_FILE}")"
    set +e
    apache_probe_response="$(curl \
        --silent \
        --show-error \
        --fail \
        --noproxy '*' \
        --connect-timeout 5 \
        --max-time 10 \
        --resolve "${APP_DOMAIN}:443:127.0.0.1" \
        "https://${APP_DOMAIN}/${probe_name}?token=${probe_token}" 2>/dev/null)"
    apache_probe_status=$?
    set -e
    if [[ "${apache_probe_status}" -eq 0 ]] && php -r '
        $result = json_decode(stream_get_contents(STDIN), true);
        exit(is_array($result)
            && !empty($result["ok"])
            && ($result["context"] ?? "") === "apache-https"
            && ($result["sapi"] ?? "cli") !== "cli"
            && (int) ($result["canonical_count"] ?? 0) === 40
            && (int) ($result["alias_count"] ?? 0) === 13
            && (int) ($result["required_count"] ?? 0) === 53
            && ($result["missing_keys"] ?? null) === []
            && ($result["inconsistent_aliases"] ?? null) === []
            && empty($result["secrets_exposed"]) ? 0 : 1);
    ' <<<"${apache_probe_response}" >/dev/null 2>&1; then
        apache_probe_valid=1
        pass_check 'local Apache HTTPS context sees 40 canonical and 13 consistent aliases'
    else
        fail_check 'local Apache HTTPS secret visibility probe failed'
    fi
else
    fail_check 'Apache HTTP probe file could not be created'
fi

if cleanup_probe_file; then
    pass_check 'Apache HTTPS probe file was removed immediately'
else
    fail_check 'Apache HTTP probe cleanup failed'
fi
unset probe_token probe_name

if [[ "${apache_probe_valid}" == "1" ]]; then
    if activate_visibility_evidence "${apache_probe_response}"; then
        pass_check 'value-free Apache visibility evidence is active for readiness validation'
    else
        fail_check 'temporary Apache visibility evidence could not be activated'
    fi
fi
unset apache_probe_response

readiness_output=""
readiness_command_ok=0
if run_transient_capture readiness_output production-readiness \
    /usr/bin/php "${WEB_ROOT}/tools/production-readiness-check.php" --production; then
    readiness_command_ok=1
fi
readiness_summary="$(php -r '
    $result = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($result) || !isset($result["status"], $result["summary"])) {
        exit(2);
    }
    printf(
        "%s:%d:%d",
        (string) $result["status"],
        (int) ($result["summary"]["critical_failed"] ?? -1),
        (int) ($result["summary"]["warnings_failed"] ?? -1)
    );
' <<<"${readiness_output}" 2>/dev/null || true)"
case "${readiness_summary}" in
    ok:0:*)
        if [[ "${readiness_command_ok}" == "1" ]]; then
            pass_check 'production readiness result is ok'
        else
            fail_check 'production readiness returned an inconsistent exit status'
        fi
        ;;
    warning:0:*)
        if [[ "${readiness_command_ok}" == "1" ]]; then
            warn_check "production readiness result is warning (${readiness_summary})"
        else
            fail_check 'production readiness warning returned an inconsistent exit status'
        fi
        ;;
    *) fail_check 'production readiness result is blocked or invalid' ;;
esac
unset readiness_output readiness_summary

if [[ "${EVIDENCE_ACTIVE}" == "1" ]]; then
    if restore_visibility_evidence; then
        pass_check 'temporary readiness evidence was removed or original evidence restored'
    else
        fail_check 'temporary readiness evidence cleanup failed'
    fi
fi

printf '[SUMMARY] passed=%d warnings=%d failed=%d secrets_exposed=false\n' "${PASSED}" "${WARNINGS}" "${FAILED}"
exit "$(( FAILED == 0 ? 0 : 1 ))"
