#!/usr/bin/env bash
set -Eeuo pipefail

# SuperOptic MGS full system installer for Ubuntu.
# FreeRADIUS is intentionally excluded. Connect it later with the existing Radius DB/CoA settings.

APP_DOMAIN="${APP_DOMAIN:-billing.superoptic.net}"
APP_URL="${APP_URL:-https://${APP_DOMAIN}}"
PUBLIC_SITE_URL="${PUBLIC_SITE_URL:-https://superoptic.net/}"
APP_TIMEZONE="${APP_TIMEZONE:-Asia/Baku}"
INSTALL_MODE="${INSTALL_MODE:-staging}"
if [[ "${INSTALL_MODE}" == "production" ]]; then
    DEFAULT_ALLOW_DEFERRED_TLS=0
    DEFAULT_RUN_PRODUCTION_PREFLIGHT=1
else
    DEFAULT_ALLOW_DEFERRED_TLS=1
    DEFAULT_RUN_PRODUCTION_PREFLIGHT=0
fi
WEB_ROOT="${WEB_ROOT:-/var/www/superopticnet}"
MGS_ROOT="${MGS_ROOT:-/opt/superoptic-mgs}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/superoptic}"
UPDATES_ROOT="${UPDATES_ROOT:-/var/lib/superoptic/updates}"
SOURCE_WEB_ROOT="${SOURCE_WEB_ROOT:-}"
SOURCE_MGS_ROOT="${SOURCE_MGS_ROOT:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-superoptic_portal}"
DB_USER="${DB_USER:-superoptic_app}"
DB_PASS="${DB_PASS:-}"
DB_ADMIN_USER="${DB_ADMIN_USER:-root}"
DB_ADMIN_PASS="${DB_ADMIN_PASS:-}"
DB_APP_ALLOWED_HOSTS="${DB_APP_ALLOWED_HOSTS:-localhost,127.0.0.1}"
RADIUS_SQL_HOST="${RADIUS_SQL_HOST:-127.0.0.1}"
RADIUS_SQL_PORT="${RADIUS_SQL_PORT:-3306}"
RADIUS_SQL_DB="${RADIUS_SQL_DB:-superoptic_portal}"
RADIUS_SQL_USER="${RADIUS_SQL_USER:-radius}"
RADIUS_DB_PASS="${RADIUS_DB_PASS:-${RADIUS_SQL_PASSWORD:-}}"
RADIUS_ROUTER_SECRET="${RADIUS_ROUTER_SECRET:-${RADIUS_SHARED_SECRET:-}}"
RADIUS_CORE_NAS_IP="${RADIUS_CORE_NAS_IP:-}"
RADIUS_SERVER_HOST="${RADIUS_SERVER_HOST:-}"
RADIUS_AUTH_PORT="${RADIUS_AUTH_PORT:-1812}"
RADIUS_ACCT_PORT="${RADIUS_ACCT_PORT:-1813}"
MGS_INTERNAL_URL="${MGS_INTERNAL_URL:-http://127.0.0.1:3000}"
MGS_SERVICE_SECRET="${MGS_SERVICE_SECRET:-}"
DEEPSEEK_API_KEY="${DEEPSEEK_API_KEY:-}"
AI_MODEL_PROVIDER="${AI_MODEL_PROVIDER:-deepseek}"
LOCAL_LLM_ENDPOINT="${LOCAL_LLM_ENDPOINT:-http://127.0.0.1:11434}"
LOCAL_LLM_MODEL="${LOCAL_LLM_MODEL:-qwen2.5:7b-instruct-q4_K_M}"
LOCAL_LLM_API="${LOCAL_LLM_API:-ollama}"
DEEPSEEK_BASE_URL="${DEEPSEEK_BASE_URL:-https://api.deepseek.com}"
DEEPSEEK_MODEL="${DEEPSEEK_MODEL:-deepseek-v4-flash}"
SMS_PROVIDER_PUBLIC_KEY="${SMS_PROVIDER_PUBLIC_KEY:-${SMS_PROVIDER_USER:-}}"
SMS_PROVIDER_PRIVATE_KEY="${SMS_PROVIDER_PRIVATE_KEY:-${SMS_PROVIDER_PASSWORD:-}}"
TELEGRAM_TOKEN="${TELEGRAM_TOKEN:-}"
WHATSAPP_PROVIDER_TOKEN="${WHATSAPP_PROVIDER_TOKEN:-}"
RADIUS_SSH_PASSWORD="${RADIUS_SSH_PASSWORD:-}"
SNMP_COMMUNITY="${SNMP_COMMUNITY:-}"
INITIAL_ADMIN_LOGIN="${INITIAL_ADMIN_LOGIN:-s.abdullayev@superoptic.net}"
INITIAL_ADMIN_FULL_NAME="${INITIAL_ADMIN_FULL_NAME:-Samir Abdullayev}"
INITIAL_ADMIN_PASSWORD="${INITIAL_ADMIN_PASSWORD:-}"
SECRET_ENV_FILE="${SECRET_ENV_FILE:-/etc/superoptic-mgs/superoptic.env}"
SECRET_ENV_GROUP="${SECRET_ENV_GROUP:-www-data}"
INSTALL_PACKAGES="${INSTALL_PACKAGES:-1}"
CONFIGURE_APACHE="${CONFIGURE_APACHE:-1}"
CONFIGURE_UFW="${CONFIGURE_UFW:-1}"
SSH_PORT="${SSH_PORT:-22}"
RADIUS_ALLOWED_NAS_SOURCE="${RADIUS_ALLOWED_NAS_SOURCE:-}"
TLS_MODE="${TLS_MODE:-deferred}"
LETSENCRYPT_EMAIL="${LETSENCRYPT_EMAIL:-}"
TLS_CERT_FILE="${TLS_CERT_FILE:-}"
TLS_KEY_FILE="${TLS_KEY_FILE:-}"
ALLOW_DEFERRED_TLS="${ALLOW_DEFERRED_TLS:-${DEFAULT_ALLOW_DEFERRED_TLS}}"
CREATE_PREINSTALL_BACKUP="${CREATE_PREINSTALL_BACKUP:-1}"
RUN_PRODUCTION_PREFLIGHT="${RUN_PRODUCTION_PREFLIGHT:-${DEFAULT_RUN_PRODUCTION_PREFLIGHT}}"
OVERWRITE_CONFIG="${OVERWRITE_CONFIG:-0}"
# The PHP portal is the canonical application. The legacy Node MGS is opt-in
# only for isolated compatibility work and must not own duplicate business data.
SKIP_MGS_NPM_INSTALL="${SKIP_MGS_NPM_INSTALL:-1}"
SKIP_SYSTEMD="${SKIP_SYSTEMD:-0}"
NONINTERACTIVE="${NONINTERACTIVE:-0}"
ROTATE_SECRETS="${ROTATE_SECRETS:-0}"
DRY_RUN="${DRY_RUN:-0}"
NODE_BIN="${NODE_BIN:-}"
NPM_BIN="${NPM_BIN:-}"
NODE_RELEASE_VERSION="20.20.2"
RUNTIME_DDL_OPEN=0

log() {
    printf '\n[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

warn() {
    printf 'WARN: %s\n' "$*" >&2
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

run() {
    if [[ "${DRY_RUN}" == "1" ]]; then
        printf '[DRY-RUN]'
        printf ' %q' "$@"
        printf '\n'
        return 0
    fi
    "$@"
}

require_root() {
    if [[ "${DRY_RUN}" == "1" ]]; then
        log "Dry-run does not require root"
        return
    fi
    [[ "${EUID}" -eq 0 ]] || fail "Run with sudo"
}

validate_flag() {
    local name="$1"
    local value="$2"
    [[ "${value}" == "0" || "${value}" == "1" ]] || fail "${name} must be 0 or 1"
}

validate_port() {
    local name="$1"
    local value="$2"
    [[ "${value}" =~ ^[0-9]+$ ]] || fail "${name} must be numeric"
    (( value >= 1 && value <= 65535 )) || fail "${name} must be between 1 and 65535"
}

validate_absolute_path() {
    local name="$1" value="$2"
    [[ "${value}" == /* && "${value}" != "/" && "${value}" != *$'\n'* && "${value}" != *$'\r'* && "${value}" != *'"'* ]] \
        || fail "${name} must be a safe absolute path"
}

validate_runtime_options() {
    local name
    for name in INSTALL_PACKAGES CONFIGURE_APACHE CONFIGURE_UFW ALLOW_DEFERRED_TLS CREATE_PREINSTALL_BACKUP RUN_PRODUCTION_PREFLIGHT OVERWRITE_CONFIG SKIP_MGS_NPM_INSTALL SKIP_SYSTEMD NONINTERACTIVE ROTATE_SECRETS DRY_RUN; do
        validate_flag "${name}" "${!name}"
    done
    case "${TLS_MODE}" in
        letsencrypt|existing|deferred|off) ;;
        *) fail "TLS_MODE must be letsencrypt, existing, deferred or off" ;;
    esac
    case "${INSTALL_MODE}" in
        staging|production) ;;
        *) fail "INSTALL_MODE must be staging or production" ;;
    esac
    [[ "${APP_DOMAIN}" =~ ^[A-Za-z0-9.-]+$ ]] || fail "APP_DOMAIN contains invalid characters"
    [[ "${APP_TIMEZONE}" =~ ^[A-Za-z0-9_+/-]+$ ]] || fail "APP_TIMEZONE contains invalid characters"
    [[ "${AI_MODEL_PROVIDER}" == "local" || "${AI_MODEL_PROVIDER}" == "deepseek" || "${AI_MODEL_PROVIDER}" == "disabled" ]] \
        || fail "AI_MODEL_PROVIDER must be local, deepseek or disabled"
    [[ "${LOCAL_LLM_ENDPOINT}" == "http://127.0.0.1:11434" ]] \
        || fail "LOCAL_LLM_ENDPOINT must use the loopback Ollama endpoint"
    [[ "${LOCAL_LLM_MODEL}" =~ ^[A-Za-z0-9._:/-]{1,128}$ ]] \
        || fail "LOCAL_LLM_MODEL contains invalid characters"
    [[ "${LOCAL_LLM_API}" == "ollama" || "${LOCAL_LLM_API}" == "openai" ]] \
        || fail "LOCAL_LLM_API must be ollama or openai"
    [[ "${DEEPSEEK_BASE_URL}" == "https://api.deepseek.com" ]] \
        || fail "DEEPSEEK_BASE_URL must use the official allowlisted endpoint"
    [[ "${DEEPSEEK_MODEL}" == "deepseek-v4-flash" || "${DEEPSEEK_MODEL}" == "deepseek-v4-pro" ]] \
        || fail "DEEPSEEK_MODEL must be deepseek-v4-flash or deepseek-v4-pro"
    if [[ "${DRY_RUN}" != "1" && ! -f "/usr/share/zoneinfo/${APP_TIMEZONE}" ]]; then
        fail "APP_TIMEZONE is not installed: ${APP_TIMEZONE}"
    fi
    validate_port "DB_PORT" "${DB_PORT}"
    validate_port "RADIUS_SQL_PORT" "${RADIUS_SQL_PORT}"
    validate_port "RADIUS_AUTH_PORT" "${RADIUS_AUTH_PORT}"
    validate_port "RADIUS_ACCT_PORT" "${RADIUS_ACCT_PORT}"
    validate_port "SSH_PORT" "${SSH_PORT}"
    validate_absolute_path "WEB_ROOT" "${WEB_ROOT}"
    validate_absolute_path "MGS_ROOT" "${MGS_ROOT}"
    validate_absolute_path "BACKUP_ROOT" "${BACKUP_ROOT}"
    validate_absolute_path "UPDATES_ROOT" "${UPDATES_ROOT}"
    validate_absolute_path "SECRET_ENV_FILE" "${SECRET_ENV_FILE}"
    [[ "${RADIUS_AUTH_PORT}" != "${RADIUS_ACCT_PORT}" ]] || fail "RADIUS auth/accounting ports must be different"
    if [[ "${TLS_MODE}" == "letsencrypt" && -z "${LETSENCRYPT_EMAIL}" ]]; then
        fail "LETSENCRYPT_EMAIL is required when TLS_MODE=letsencrypt"
    fi
    if [[ "${TLS_MODE}" == "existing" && ( -z "${TLS_CERT_FILE}" || -z "${TLS_KEY_FILE}" ) ]]; then
        fail "TLS_CERT_FILE and TLS_KEY_FILE are required when TLS_MODE=existing"
    fi
    if [[ "${INSTALL_MODE}" == "production" ]]; then
        [[ "${APP_URL}" == https://* ]] || fail "Production APP_URL must use HTTPS"
        [[ "${CONFIGURE_APACHE}" == "1" ]] || fail "Production installation requires managed Apache/TLS"
        [[ "${TLS_MODE}" == "letsencrypt" || "${TLS_MODE}" == "existing" ]] \
            || fail "Production TLS_MODE must be letsencrypt or existing"
        [[ "${RUN_PRODUCTION_PREFLIGHT}" == "1" ]] || fail "Production preflight cannot be disabled"
    fi
}

detect_sources() {
    if [[ -z "${SOURCE_WEB_ROOT}" ]]; then
        if [[ -f "./config.php" && -f "./db.php" ]]; then
            SOURCE_WEB_ROOT="$(pwd)"
        elif [[ -d "./payload/superopticnet" ]]; then
            SOURCE_WEB_ROOT="$(pwd)/payload/superopticnet"
        elif [[ -d "./superopticnet" ]]; then
            SOURCE_WEB_ROOT="$(pwd)/superopticnet"
        else
            fail "SOURCE_WEB_ROOT not set and superopticnet source not found"
        fi
    fi

    if [[ -z "${SOURCE_MGS_ROOT}" ]]; then
        if [[ -d "./payload/oltidareetme" ]]; then
            SOURCE_MGS_ROOT="$(pwd)/payload/oltidareetme"
        elif [[ -d "./oltidareetme" ]]; then
            SOURCE_MGS_ROOT="$(pwd)/oltidareetme"
        elif [[ -d "$(dirname "${SOURCE_WEB_ROOT}")/oltidareetme" ]]; then
            SOURCE_MGS_ROOT="$(dirname "${SOURCE_WEB_ROOT}")/oltidareetme"
        else
            warn "SOURCE_MGS_ROOT not found. MGS Node install will be skipped unless provided."
            SOURCE_MGS_ROOT=""
        fi
    fi
}

validate_identifier() {
    local value="$1"
    local label="$2"
    [[ "${value}" =~ ^[A-Za-z0-9_]+$ ]] || fail "${label} must contain only letters, numbers and underscore: ${value}"
}

mysql_admin_exec() {
    local args=()
    if [[ "${DRY_RUN}" == "1" ]]; then
        log "[DRY-RUN] would execute scoped MySQL bootstrap without exposing credentials or SQL"
        return 0
    fi
    if [[ "${DB_HOST}" == "127.0.0.1" || "${DB_HOST}" == "localhost" ]] && [[ -z "${DB_ADMIN_PASS}" ]]; then
        args=(--batch --skip-column-names --protocol=socket -u "${DB_ADMIN_USER}")
        mysql "${args[@]}"
        return
    fi
    [[ -n "${DB_ADMIN_PASS}" ]] || return 2
    args=(--batch --skip-column-names -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_ADMIN_USER}")
    MYSQL_PWD="${DB_ADMIN_PASS}" mysql "${args[@]}"
}

install_packages() {
    if [[ "${INSTALL_PACKAGES}" != "1" ]]; then
        log "Package install skipped"
        NODE_BIN="${NODE_BIN:-$(command -v node 2>/dev/null || true)}"
        NPM_BIN="${NPM_BIN:-$(command -v npm 2>/dev/null || true)}"
        [[ -x "${NODE_BIN}" && -x "${NPM_BIN}" ]] || fail "INSTALL_PACKAGES=0 requires existing Node.js and npm executables"
        local existing_node_major
        existing_node_major="$(${NODE_BIN} -v | sed -E 's/^v([0-9]+).*/\1/')"
        [[ "${existing_node_major}" -ge 20 ]] || fail "INSTALL_PACKAGES=0 requires Node.js 20 or newer"
        return
    fi

    log "Installing Ubuntu packages"
    export DEBIAN_FRONTEND=noninteractive
    run apt-get update
    run apt-get install -y \
        apache2 mysql-server mysql-client \
        php php-cli php-mysql php-curl php-mbstring php-xml php-zip php-gd php-intl \
        nodejs npm default-jre snmp \
        rsync unzip curl git openssl ca-certificates xz-utils ufw certbot python3-certbot-apache

    if [[ "${DRY_RUN}" == "1" ]]; then
        log "Optional snmp-mibs-downloader check skipped in dry-run"
    else
        apt-get install -y snmp-mibs-downloader >/dev/null 2>&1 || warn "snmp-mibs-downloader not available; snmpwalk is still installed via snmp package."
    fi

    local node_major node_candidate
    node_candidate="$(command -v node 2>/dev/null || true)"
    node_major="$(${node_candidate:-/bin/false} -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/' || true)"
    if [[ -z "${node_major}" || "${node_major}" -lt 20 ]]; then
        local node_arch node_archive node_expected_sha node_install_root node_url node_tmp
        case "$(uname -m)" in
            x86_64)
                node_arch="x64"
                node_expected_sha="df770b2a6f130ed8627c9782c988fda9669fa23898329a61a871e32f965e007d"
                ;;
            aarch64|arm64)
                node_arch="arm64"
                node_expected_sha="73093db209e4e9e09dd7d15a47aeaab1b74833830df03efa5f942a1122c5fa71"
                ;;
            *)
                fail "Unsupported Node.js runtime architecture: $(uname -m)"
                ;;
        esac
        node_archive="node-v${NODE_RELEASE_VERSION}-linux-${node_arch}.tar.xz"
        node_url="https://nodejs.org/dist/v${NODE_RELEASE_VERSION}/${node_archive}"
        node_install_root="/opt/node-v${NODE_RELEASE_VERSION}-linux-${node_arch}"
        log "Installing checksum-pinned official Node.js ${NODE_RELEASE_VERSION} runtime"
        if [[ "${DRY_RUN}" == "1" ]]; then
            printf '[DRY-RUN] curl --fail --location --proto =https --tlsv1.2 %s\n' "${node_url}"
            printf '[DRY-RUN] verify SHA-256 %s and install under %s\n' "${node_expected_sha}" "${node_install_root}"
        else
            node_tmp="$(mktemp -d)"
            curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
                "${node_url}" -o "${node_tmp}/${node_archive}"
            printf '%s  %s\n' "${node_expected_sha}" "${node_tmp}/${node_archive}" | sha256sum --check --status \
                || fail "Official Node.js archive checksum verification failed"
            rm -rf "${node_install_root}"
            tar -xJf "${node_tmp}/${node_archive}" -C /opt
            rm -rf "${node_tmp}"
            ln -sfn "${node_install_root}/bin/node" /usr/local/bin/node
            ln -sfn "${node_install_root}/bin/npm" /usr/local/bin/npm
            ln -sfn "${node_install_root}/bin/npx" /usr/local/bin/npx
            ln -sfn "${node_install_root}/bin/corepack" /usr/local/bin/corepack
        fi
        NODE_BIN=/usr/local/bin/node
        NPM_BIN=/usr/local/bin/npm
    else
        NODE_BIN="${node_candidate}"
        NPM_BIN="$(command -v npm)"
    fi
    if [[ "${DRY_RUN}" != "1" ]]; then
        [[ -x "${NODE_BIN}" && -x "${NPM_BIN}" ]] || fail "Node.js/npm runtime is unavailable after package installation"
        node_major="$(${NODE_BIN} -v | sed -E 's/^v([0-9]+).*/\1/')"
        [[ "${node_major}" -ge 20 ]] || fail "Node.js 20 or newer is required"
    fi
}

configure_timezone() {
    log "Configuring server timezone: ${APP_TIMEZONE}"
    run timedatectl set-timezone "${APP_TIMEZONE}"
}

create_preinstall_backup() {
    if [[ "${CREATE_PREINSTALL_BACKUP}" != "1" ]]; then
        log "Pre-install backup skipped"
        return
    fi

    local timestamp backup_dir schema_exists=""
    timestamp="$(date '+%Y%m%d-%H%M%S')"
    backup_dir="${BACKUP_ROOT}/preinstall-${timestamp}"
    if [[ "${DRY_RUN}" == "1" ]]; then
        log "[DRY-RUN] would create root-only application and database backup at ${backup_dir}"
        return
    fi

    install -d -m 0700 -o root -g root "${backup_dir}"
    if [[ -d "${WEB_ROOT}" ]]; then
        tar --exclude='./tmp' --exclude='./logs' -C "${WEB_ROOT}" -czf "${backup_dir}/superopticnet.tar.gz" .
    fi
    if [[ -d "${MGS_ROOT}" ]]; then
        tar --exclude='./node_modules' --exclude='./logs' --exclude='./runtime' --exclude='./.env*' \
            -C "${MGS_ROOT}" -czf "${backup_dir}/superoptic-mgs.tar.gz" .
    fi

    schema_exists="$(mysql_admin_exec <<SQL 2>/dev/null || true
SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}';
SQL
)"
    if [[ "${schema_exists}" == "${DB_NAME}" ]]; then
        if [[ "${DB_HOST}" == "127.0.0.1" || "${DB_HOST}" == "localhost" ]] && [[ -z "${DB_ADMIN_PASS}" ]]; then
            mysqldump --protocol=socket -u "${DB_ADMIN_USER}" --single-transaction --routines --triggers --events \
                "${DB_NAME}" >"${backup_dir}/database.sql"
        else
            [[ -n "${DB_ADMIN_PASS}" ]] || fail "DB_ADMIN_PASS is required to back up the existing remote database"
            MYSQL_PWD="${DB_ADMIN_PASS}" mysqldump -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_ADMIN_USER}" \
                --single-transaction --routines --triggers --events "${DB_NAME}" >"${backup_dir}/database.sql"
        fi
    fi

    {
        printf 'created_at=%s\n' "$(date --iso-8601=seconds)"
        printf 'web_root=%s\n' "${WEB_ROOT}"
        printf 'mgs_root=%s\n' "${MGS_ROOT}"
        printf 'database=%s\n' "${DB_NAME}"
        printf 'restore_requires_explicit_maintenance_window=true\n'
    } >"${backup_dir}/manifest.txt"
    (cd "${backup_dir}" && sha256sum ./* >SHA256SUMS)
    chmod -R go-rwx "${backup_dir}"
    log "Pre-install backup created: ${backup_dir}"
}

copy_sources() {
    local -a legacy_env_files=()
    log "Copying PHP portal to ${WEB_ROOT}"
    run mkdir -p "${WEB_ROOT}"
    run rsync -a --delete \
        --exclude ".git" \
        --exclude ".env" \
        --exclude ".env.*" \
        --exclude "tmp" \
        --exclude "logs" \
        --exclude "node_modules" \
        --exclude "config.local.php" \
        --exclude "data/area-interest-log.jsonl" \
        --exclude "data/db-bootstrap-ok.json" \
        --exclude "data/portal-password-overrides.json" \
        --exclude "data/portal-tickets.json" \
        --exclude "data/portal-users.json" \
        --exclude "data/rate-limits.json" \
        --exclude "data/security-events.jsonl" \
        --exclude "data/system-settings.json" \
        --exclude "data/ticket-sequence.txt" \
        --exclude "data/used-token-jti.json" \
        --exclude "data/test-*" \
        --exclude "data/legacy-*" \
        --exclude "*.log" \
        "${SOURCE_WEB_ROOT}/" "${WEB_ROOT}/"
    run chown -R root:www-data "${WEB_ROOT}"
    run find "${WEB_ROOT}" -type d -exec chmod 0750 {} +
    run find "${WEB_ROOT}" -type f -exec chmod 0640 {} +
    run install -d -m 0770 -o www-data -g www-data "${WEB_ROOT}/tmp"
    run install -d -m 0750 -o root -g www-data "${WEB_ROOT}/data"

    if [[ "${DRY_RUN}" == "1" ]]; then
        log "[DRY-RUN] would create only the approved writable runtime files under ${WEB_ROOT}/data"
    else
        declare -A runtime_defaults=(
            [area-interest-log.jsonl]=''
            [db-bootstrap-ok.json]='{}'
            [portal-password-overrides.json]='{}'
            [portal-tickets.json]='[]'
            [portal-users.json]='[]'
            [rate-limits.json]='{}'
            [security-events.jsonl]=''
            [system-settings.json]='{}'
            [ticket-sequence.txt]='0'
            [used-token-jti.json]='{}'
        )
        local runtime_file
        for runtime_file in "${!runtime_defaults[@]}"; do
            if [[ ! -e "${WEB_ROOT}/data/${runtime_file}" ]]; then
                printf '%s\n' "${runtime_defaults[${runtime_file}]}" >"${WEB_ROOT}/data/${runtime_file}"
            fi
            chown www-data:www-data "${WEB_ROOT}/data/${runtime_file}"
            chmod 0660 "${WEB_ROOT}/data/${runtime_file}"
        done
    fi

    if [[ -n "${SOURCE_MGS_ROOT}" ]]; then
        log "Copying MGS Node app to ${MGS_ROOT}"
        run mkdir -p "${MGS_ROOT}"
        run rsync -a --delete \
            --exclude ".git" \
            --exclude "node_modules" \
            --exclude "logs" \
            --exclude "test-results" \
            --exclude ".env" \
            --exclude ".env.*" \
            --exclude "*.log" \
            "${SOURCE_MGS_ROOT}/" "${MGS_ROOT}/"
        run chown -R root:www-data "${MGS_ROOT}"
        run chmod -R g+rX "${MGS_ROOT}"
        shopt -s nullglob
        legacy_env_files=("${MGS_ROOT}"/.env*)
        shopt -u nullglob
        if (( ${#legacy_env_files[@]} > 0 )); then
            # Protect legacy dotenv files from the non-root Node service during migration.
            run chown root:root "${legacy_env_files[@]}"
            run chmod 0600 "${legacy_env_files[@]}"
        fi
        run install -d -m 0770 -o www-data -g www-data \
            "${MGS_ROOT}/data" "${MGS_ROOT}/logs" "${MGS_ROOT}/runtime"
        if [[ -f "${MGS_ROOT}/.env" ]]; then
            warn "Legacy MGS .env exists. Service environment values take precedence; remove the file after a controlled secret rotation/scrub."
        fi
    fi
}

bootstrap_secrets() {
    local bootstrap_script="${SOURCE_WEB_ROOT}/deploy/set-superoptic-secrets.sh"
    local args=(--env-file "${SECRET_ENV_FILE}" --group "${SECRET_ENV_GROUP}")
    [[ -f "${bootstrap_script}" ]] || fail "Secret bootstrap script not found: ${bootstrap_script}"

    export DB_PASS RADIUS_DB_PASS RADIUS_ROUTER_SECRET DEEPSEEK_API_KEY
    export AI_MODEL_PROVIDER LOCAL_LLM_ENDPOINT LOCAL_LLM_MODEL LOCAL_LLM_API
    export DEEPSEEK_BASE_URL DEEPSEEK_MODEL
    export SMS_PROVIDER_PUBLIC_KEY SMS_PROVIDER_PRIVATE_KEY TELEGRAM_TOKEN
    export WHATSAPP_PROVIDER_TOKEN RADIUS_SSH_PASSWORD SNMP_COMMUNITY MGS_SERVICE_SECRET
    export SUPEROPTIC_DB_HOST="${DB_HOST}"
    export SUPEROPTIC_DB_PORT="${DB_PORT}"
    export SUPEROPTIC_DB_NAME="${DB_NAME}"
    export SUPEROPTIC_DB_USER="${DB_USER}"
    export SUPEROPTIC_RADIUS_DB_HOST="${RADIUS_SQL_HOST}"
    export SUPEROPTIC_RADIUS_DB_PORT="${RADIUS_SQL_PORT}"
    export SUPEROPTIC_RADIUS_DB_NAME="${RADIUS_SQL_DB}"
    export SUPEROPTIC_RADIUS_DB_USER="${RADIUS_SQL_USER}"
    export SUPEROPTIC_MGS_ROOT="${MGS_ROOT}"
    export SUPEROPTIC_BILLING_ROOT="${WEB_ROOT}"
    export SUPEROPTIC_UPDATES_ROOT="${UPDATES_ROOT}"
    export BACKUP_DIR="${BACKUP_ROOT}/mgs"

    if [[ "${DRY_RUN}" == "1" ]]; then
        args+=(--dry-run)
    elif [[ "${NONINTERACTIVE}" == "1" ]]; then
        args+=(--non-interactive)
    fi
    [[ "${INSTALL_MODE}" == "staging" ]] && args+=(--allow-missing-external)
    [[ "${ROTATE_SECRETS}" == "1" ]] && args+=(--rotate)

    log "Bootstrapping service secrets outside the repository"
    # shellcheck disable=SC1090
    source "${bootstrap_script}"
    superoptic_secrets_main "${args[@]}"
}

validate_radius_ports() {
    log "Validating RADIUS UDP port configuration"
    [[ "${RADIUS_AUTH_PORT}" == "1812" ]] || warn "Non-standard RADIUS authentication port configured: ${RADIUS_AUTH_PORT} (standard is 1812/UDP)"
    [[ "${RADIUS_ACCT_PORT}" == "1813" ]] || warn "Non-standard RADIUS accounting port configured: ${RADIUS_ACCT_PORT} (standard is 1813/UDP)"
    if [[ "${DRY_RUN}" == "1" ]]; then
        log "[DRY-RUN] would resolve/check RADIUS endpoint ${RADIUS_SERVER_HOST:-<unset>} on UDP ${RADIUS_AUTH_PORT}/${RADIUS_ACCT_PORT}"
        warn "No firewall rule would be added. Permit RADIUS UDP only between explicit NAS and RADIUS server addresses."
        return
    fi

    if [[ -z "${RADIUS_SERVER_HOST}" ]]; then
        warn "RADIUS_SERVER_HOST is empty; endpoint checks for UDP ${RADIUS_AUTH_PORT}/${RADIUS_ACCT_PORT} were skipped."
    elif ! getent ahosts "${RADIUS_SERVER_HOST}" >/dev/null 2>&1; then
        warn "RADIUS server host does not resolve: ${RADIUS_SERVER_HOST}"
    elif [[ "${RADIUS_SERVER_HOST}" == "127.0.0.1" || "${RADIUS_SERVER_HOST}" == "localhost" || "${RADIUS_SERVER_HOST}" == "::1" ]]; then
        if command -v ss >/dev/null 2>&1; then
            ss -H -lun | awk '{print $5}' | grep -Eq "(^|:)${RADIUS_AUTH_PORT}$" || warn "No local UDP listener detected on RADIUS authentication port ${RADIUS_AUTH_PORT}."
            ss -H -lun | awk '{print $5}' | grep -Eq "(^|:)${RADIUS_ACCT_PORT}$" || warn "No local UDP listener detected on RADIUS accounting port ${RADIUS_ACCT_PORT}."
        else
            warn "ss is unavailable; local RADIUS UDP listener checks were skipped."
        fi
    else
        warn "RADIUS uses UDP; DNS resolution passed, but authenticated traffic is required to prove remote reachability on ${RADIUS_AUTH_PORT}/${RADIUS_ACCT_PORT}."
    fi
    warn "No firewall rule was added. Permit RADIUS UDP only between explicit NAS and RADIUS server addresses."
}

create_database() {
    local escaped_pass sql host
    local -a app_hosts=()
    validate_identifier "${DB_NAME}" "DB_NAME"
    validate_identifier "${DB_USER}" "DB_USER"
    [[ "${DRY_RUN}" == "1" || -n "${DB_PASS}" ]] || fail "DB_PASS was not loaded by the secret bootstrap"

    log "Creating MySQL database and app user"
    escaped_pass="${DB_PASS//\\/\\\\}"
    escaped_pass="${escaped_pass//\'/\\\'}"
    sql="CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    IFS=',' read -r -a app_hosts <<<"${DB_APP_ALLOWED_HOSTS}"
    for host in "${app_hosts[@]}"; do
        host="${host//[[:space:]]/}"
        [[ -n "${host}" && "${host}" =~ ^[A-Za-z0-9_.:%-]+$ ]] || fail "Invalid DB_APP_ALLOWED_HOSTS entry"
        sql+=$'\n'"CREATE USER IF NOT EXISTS '${DB_USER}'@'${host}' IDENTIFIED BY '${escaped_pass}';"
        sql+=$'\n'"ALTER USER '${DB_USER}'@'${host}' IDENTIFIED BY '${escaped_pass}';"
        sql+=$'\n'"GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${host}';"
    done
    sql+=$'\nFLUSH PRIVILEGES;'

    if ! mysql_admin_exec <<<"${sql}"; then
        if [[ "${DB_HOST}" != "127.0.0.1" && "${DB_HOST}" != "localhost" && -z "${DB_ADMIN_PASS}" ]]; then
            warn "Remote DB admin password was not supplied; database/user bootstrap was skipped. DB_ADMIN_PASS is transient and is never persisted."
            return
        fi
        fail "MySQL database/user bootstrap failed"
    fi
    [[ "${DRY_RUN}" == "1" ]] || RUNTIME_DDL_OPEN=1
    unset sql escaped_pass
}

set_php_config_value() {
    local file="$1"
    local key="$2"
    local value="$3"
    php /dev/stdin "${file}" "${key}" "${value}" <<'PHP'
<?php
[$file, $key, $value] = array_slice($argv, 1);
$content = file_get_contents($file);
$pattern = "/('\Q" . $key . "\E'\s*=>\s*)'[^']*'/";
if (preg_match($pattern, $content)) {
    $content = preg_replace($pattern, "\${1}'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'", $content, 1);
} else {
    $content = preg_replace("/return\s+\[\s*\n/", "return [\n    '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $key) . "' => '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "',\n", $content, 1);
}
file_put_contents($file, $content);
PHP
}

warn_existing_secret_config() {
    local config="$1"
    if grep -Eq "'(db_pass|radius_sql_password|radius_shared_secret|mgs_service_shared_secret)'[[:space:]]*=>[[:space:]]*'[^']+'" "${config}"; then
        warn "Existing config.local.php may contain file-based secrets. Environment values take precedence; rotate them and use OVERWRITE_CONFIG=1 during a controlled scrub."
    fi
}

write_config() {
    local config="${WEB_ROOT}/config.local.php"
    local template="${WEB_ROOT}/config.local.production.example.php"
    if [[ "${DRY_RUN}" == "1" && ! -f "${template}" ]]; then
        template="${SOURCE_WEB_ROOT}/config.local.production.example.php"
    fi
    [[ -f "${template}" ]] || fail "Production config template not found: ${template}"

    if [[ -f "${config}" && "${OVERWRITE_CONFIG}" != "1" ]]; then
        log "config.local.php exists; keeping it"
        warn_existing_secret_config "${config}"
    else
        log "Writing non-secret config.local.php"
        run cp "${template}" "${config}"
    fi
    if [[ "${DRY_RUN}" == "1" ]]; then
        return
    fi

    set_php_config_value "${config}" "app_url" "${APP_URL}"
    set_php_config_value "${config}" "app_environment" "${INSTALL_MODE}"
    set_php_config_value "${config}" "public_site_url" "${PUBLIC_SITE_URL}"
    set_php_config_value "${config}" "app_timezone" "${APP_TIMEZONE}"
    set_php_config_value "${config}" "ticket_close_base_url" "${APP_URL}"
    set_php_config_value "${config}" "billing_mode" "mysql"
    set_php_config_value "${config}" "portal_storage_driver" "mysql"
    # Deploy-time migrations run with temporary DDL grants; runtime is switched to off afterwards.
    set_php_config_value "${config}" "db_bootstrap_mode" "always"
    set_php_config_value "${config}" "db_host" "${DB_HOST}"
    set_php_config_value "${config}" "db_port" "${DB_PORT}"
    set_php_config_value "${config}" "db_name" "${DB_NAME}"
    set_php_config_value "${config}" "db_user" "${DB_USER}"
    set_php_config_value "${config}" "radius_sql_host" "${RADIUS_SQL_HOST}"
    set_php_config_value "${config}" "radius_sql_port" "${RADIUS_SQL_PORT}"
    set_php_config_value "${config}" "radius_sql_db" "${RADIUS_SQL_DB}"
    set_php_config_value "${config}" "radius_sql_user" "${RADIUS_SQL_USER}"
    set_php_config_value "${config}" "radius_core_nas_ip" "${RADIUS_CORE_NAS_IP}"
    set_php_config_value "${config}" "radius_auth_port" "${RADIUS_AUTH_PORT}"
    set_php_config_value "${config}" "radius_acct_port" "${RADIUS_ACCT_PORT}"
    if [[ -n "${RADIUS_SERVER_HOST}" ]]; then
        set_php_config_value "${config}" "radius_server_ip" "${RADIUS_SERVER_HOST}"
    fi
    set_php_config_value "${config}" "mgs_internal_url" "${MGS_INTERNAL_URL}"
    set_php_config_value "${config}" "billing_customer_url" "${APP_URL}/portal-login.php"
    set_php_config_value "${config}" "billing_employee_url" "${APP_URL}/staff-login.php"
    if [[ "${TLS_MODE}" == "letsencrypt" || "${TLS_MODE}" == "existing" ]]; then
        set_php_config_value "${config}" "portal_force_https" "1"
    else
        set_php_config_value "${config}" "portal_force_https" "0"
    fi
    chown root:www-data "${config}"
    chmod 640 "${config}"
}

bootstrap_php_db() {
    log "Bootstrapping the canonical PHP/MySQL schema"
    run php -l "${WEB_ROOT}/config.php"
    if [[ "${DRY_RUN}" == "1" ]]; then
        return
    fi
    php -r "require '${WEB_ROOT}/config.php'; require '${WEB_ROOT}/mgs-integration-lib.php'; \$db = app_db(); if (!(\$db instanceof PDO) || !app_mgs_ensure_schema(\$db)) { fwrite(STDERR, 'db_bootstrap_unified_schema_failed'); exit(2); } echo 'db_bootstrap_ok'.PHP_EOL;"
}

migrate_portal_schema() {
    local tool="${WEB_ROOT}/tools/run-portal-migrations.php"
    [[ -f "${tool}" || "${DRY_RUN}" == "1" ]] || fail "Portal migration runner is missing"
    log "Running versioned PHP portal migrations"
    if [[ "${DRY_RUN}" == "1" ]]; then
        log "[DRY-RUN] would apply pending portal migrations before runtime DDL is revoked"
        return
    fi
    php "${tool}" || fail "Portal migrations failed"
}

create_initial_super_admin() {
    local tool="${WEB_ROOT}/tools/create-initial-super-admin.php"
    local password="${INITIAL_ADMIN_PASSWORD}" confirmation=""
    [[ -f "${tool}" || "${DRY_RUN}" == "1" ]] || fail "Initial super-admin bootstrap tool is missing"
    if [[ "${DRY_RUN}" == "1" ]]; then
        log "[DRY-RUN] would retain an existing active super-admin or collect one strong initial password without echo"
        return
    fi
    if php "${tool}" --status >/dev/null 2>&1; then
        log "An active super-admin already exists; bootstrap skipped"
        return
    fi
    if [[ -z "${password}" && "${NONINTERACTIVE}" == "1" ]]; then
        fail "INITIAL_ADMIN_PASSWORD is required for a new non-interactive installation"
    fi
    if [[ -z "${password}" ]]; then
        [[ -r /dev/tty && -w /dev/tty ]] || fail "No interactive terminal is available for the initial admin password"
        printf 'Initial super-admin password: ' >/dev/tty
        IFS= read -r -s password </dev/tty
        printf '\nConfirm initial super-admin password: ' >/dev/tty
        IFS= read -r -s confirmation </dev/tty
        printf '\n' >/dev/tty
        [[ "${password}" == "${confirmation}" ]] || fail "Initial super-admin passwords do not match"
    fi
    INITIAL_ADMIN_LOGIN="${INITIAL_ADMIN_LOGIN}" \
    INITIAL_ADMIN_FULL_NAME="${INITIAL_ADMIN_FULL_NAME}" \
    INITIAL_ADMIN_PASSWORD="${password}" \
        php "${tool}"
    unset password confirmation INITIAL_ADMIN_PASSWORD
}

ensure_node_runtime_user() {
    local node_user="superoptic-node"
    local node_home="/home/${node_user}"

    if [[ "${DRY_RUN}" == "1" ]]; then
        log "[DRY-RUN] would ensure ${node_user} with home ${node_home}"
        return
    fi

    if ! id "${node_user}" >/dev/null 2>&1; then
        useradd --system --create-home --home-dir "${node_home}" \
            --shell /usr/sbin/nologin --gid www-data "${node_user}"
    elif [[ "$(getent passwd "${node_user}" | cut -d: -f6)" != "${node_home}" ]]; then
        usermod --home "${node_home}" "${node_user}"
    fi

    install -d -m 0750 -o "${node_user}" -g www-data "${node_home}"
}

install_mgs_node_dependencies() {
    if [[ -z "${SOURCE_MGS_ROOT}" || ( "${DRY_RUN}" != "1" && ! -d "${MGS_ROOT}" ) ]]; then
        warn "MGS root not available; skipping Node MGS install"
        return
    fi
    if [[ "${SKIP_MGS_NPM_INSTALL}" == "1" ]]; then
        log "MGS npm install skipped"
        return
    fi

    if [[ ! -f "${MGS_ROOT}/package-lock.json" && "${INSTALL_MODE}" == "production" ]]; then
        fail "Production MGS install requires package-lock.json"
    fi

    log "Installing MGS Node dependencies as an unprivileged build user without service secrets"
    if [[ "${DRY_RUN}" == "1" ]]; then
        log "[DRY-RUN] would build Node dependencies in a temporary nobody-owned directory"
        return
    fi

    local build_root npm_action node_user="superoptic-node" node_home="/home/superoptic-node"
    ensure_node_runtime_user
    build_root="$(mktemp -d "${node_home}/build-XXXXXXXX")"
    trap '[[ -z "${build_root:-}" ]] || rm -rf -- "${build_root}"' RETURN
    rsync -a --delete \
        --exclude node_modules --exclude .env --exclude '.env.*' \
        --exclude data --exclude logs --exclude runtime \
        "${MGS_ROOT}/" "${build_root}/"
    chown -R "${node_user}:www-data" "${build_root}"
    npm_action='ci'
    if [[ ! -f "${build_root}/package-lock.json" ]]; then
        warn "package-lock.json is missing; staging dependency resolution is not reproducible"
        npm_action='install'
    fi
    runuser -u "${node_user}" -- env -i HOME="${node_home}" PATH=/snap/bin:/usr/local/bin:/usr/bin:/bin \
        bash -lc "cd '${build_root}' && '${NPM_BIN}' ${npm_action}"
    rm -rf -- "${MGS_ROOT}/node_modules"
    install -d -m 0750 -o "${node_user}" -g www-data "${MGS_ROOT}/node_modules"
    rsync -a --delete "${build_root}/node_modules/" "${MGS_ROOT}/node_modules/"
    chown -R "${node_user}:www-data" "${MGS_ROOT}/node_modules"
    rm -rf -- "${build_root}"
    build_root=''
    trap - RETURN
}

migrate_mgs_node() {
    if [[ -z "${SOURCE_MGS_ROOT}" || "${SKIP_MGS_NPM_INSTALL}" == "1" ]]; then
        return
    fi
    log "Running MGS migrations with database-only environment visibility"
    if [[ "${DRY_RUN}" == "1" ]]; then
        log "[DRY-RUN] would run migrations as www-data with only database URLs"
        return
    fi
    ensure_node_runtime_user
    if ! runuser -u superoptic-node -- env -i \
        HOME=/home/superoptic-node PATH=/snap/bin:/usr/local/bin:/usr/bin:/bin NODE_ENV=production \
        DATABASE_URL="${DATABASE_URL}" RADIUS_DATABASE_URL="${RADIUS_DATABASE_URL}" \
        bash -lc "cd '${MGS_ROOT}' && '${NPM_BIN}' run migrate"; then
        return 1
    fi
    if ! runuser -u superoptic-node -- env -i HOME=/home/superoptic-node PATH=/snap/bin:/usr/local/bin:/usr/bin:/bin NODE_ENV=production \
        bash -lc "cd '${MGS_ROOT}' && '${NPM_BIN}' prune --omit=dev --ignore-scripts --package-lock=false"; then
        return 1
    fi
    chown -R root:www-data "${MGS_ROOT}/node_modules"
    chmod -R g+rX,o-rwx "${MGS_ROOT}/node_modules"
}

lock_runtime_database_privileges() {
    local sql host config="${WEB_ROOT}/config.local.php"
    local -a app_hosts=()
    log "Restricting the runtime database user to DML after migrations"
    IFS=',' read -r -a app_hosts <<<"${DB_APP_ALLOWED_HOSTS}"
    sql=''
    for host in "${app_hosts[@]}"; do
        host="${host//[[:space:]]/}"
        sql+=$'\n'"REVOKE ALL PRIVILEGES ON \`${DB_NAME}\`.* FROM '${DB_USER}'@'${host}';"
        sql+=$'\n'"GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${host}';"
    done
    sql+=$'\nFLUSH PRIVILEGES;'
    mysql_admin_exec <<<"${sql}" || return 1
    if [[ "${DRY_RUN}" != "1" ]]; then
        set_php_config_value "${config}" "db_bootstrap_mode" "off"
        chown root:www-data "${config}"
        chmod 0640 "${config}"
    fi
    RUNTIME_DDL_OPEN=0
    unset sql
}

cleanup_installer_exit() {
    local status=$?
    if [[ "${RUNTIME_DDL_OPEN:-0}" == "1" ]]; then
        warn "Installation stopped while temporary DDL privileges were open; restricting the runtime database account now."
        if ! lock_runtime_database_privileges; then
            warn "Emergency runtime database privilege restriction failed; revoke DDL grants manually before starting services."
        fi
    fi
    trap - EXIT
    exit "${status}"
}

configure_apache() {
    if [[ "${CONFIGURE_APACHE}" != "1" ]]; then
        log "Apache config skipped"
        return
    fi
    log "Configuring Apache virtual host"
    local conf="/etc/apache2/sites-available/000-superoptic-mgs.conf"
    run tee "${conf}" >/dev/null <<CONF
<VirtualHost *:80>
    ServerName ${APP_DOMAIN}
    ServerAlias localhost 127.0.0.1
    DocumentRoot ${WEB_ROOT}
    IncludeOptional /etc/apache2/superoptic-force-https.conf

    <Directory ${WEB_ROOT}>
        AllowOverride All
        Options -Indexes
        Require all granted
    </Directory>

    <Directory ${WEB_ROOT}/data>
        Require all denied
    </Directory>

    <Directory ${WEB_ROOT}/tmp>
        Require all denied
    </Directory>

    <FilesMatch "(^\\.|config\\.local\\.php$|\\.env|\\.(log|sql|bak)$)">
        Require all denied
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/superoptic-error.log
    CustomLog \${APACHE_LOG_DIR}/superoptic-access.log combined
</VirtualHost>
CONF
    run a2enmod rewrite headers proxy proxy_http ssl
    run a2dissite 000-default.conf
    run a2ensite 000-superoptic-mgs.conf
    run apache2ctl configtest
    run systemctl enable apache2
}

configure_service_environment() {
    if [[ "${CONFIGURE_APACHE}" != "1" ]]; then
        warn "Apache environment was not configured because CONFIGURE_APACHE=0. Attach ${SECRET_ENV_FILE} to the managed PHP service before production use."
        return
    fi
    log "Attaching protected environment to Apache"
    run install -d -m 0770 -o www-data -g www-data "${BACKUP_ROOT}/mgs" "${UPDATES_ROOT}"
    run mkdir -p /etc/systemd/system/apache2.service.d
    run tee /etc/systemd/system/apache2.service.d/superoptic-secrets.conf >/dev/null <<CONF
[Service]
EnvironmentFile=${SECRET_ENV_FILE}
Environment="SUPEROPTIC_MGS_ROOT=${MGS_ROOT}"
Environment="SUPEROPTIC_BILLING_ROOT=${WEB_ROOT}"
Environment="SUPEROPTIC_UPDATES_ROOT=${UPDATES_ROOT}"
Environment="BACKUP_DIR=${BACKUP_ROOT}/mgs"
Environment="AI_MODEL_PROVIDER=${AI_MODEL_PROVIDER}"
Environment="LOCAL_LLM_ENDPOINT=${LOCAL_LLM_ENDPOINT}"
Environment="LOCAL_LLM_MODEL=${LOCAL_LLM_MODEL}"
Environment="LOCAL_LLM_API=${LOCAL_LLM_API}"
Environment="DEEPSEEK_BASE_URL=${DEEPSEEK_BASE_URL}"
Environment="DEEPSEEK_MODEL=${DEEPSEEK_MODEL}"
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
CONF
    run systemctl daemon-reload
    run systemctl restart apache2
}

configure_tls() {
    if [[ "${CONFIGURE_APACHE}" != "1" ]]; then
        [[ "${TLS_MODE}" == "off" || "${ALLOW_DEFERRED_TLS}" == "1" ]] || fail "TLS requires CONFIGURE_APACHE=1 or an explicitly managed external gateway"
        return
    fi

    case "${TLS_MODE}" in
        letsencrypt)
            log "Requesting Let's Encrypt certificate for ${APP_DOMAIN}"
            run rm -f /etc/apache2/superoptic-force-https.conf
            run certbot --apache --non-interactive --agree-tos \
                --email "${LETSENCRYPT_EMAIL}" -d "${APP_DOMAIN}"
            run tee /etc/apache2/superoptic-force-https.conf >/dev/null <<CONF
    RewriteEngine On
    RewriteCond %{HTTPS} !=on
    RewriteCond %{HTTP_HOST} !^(localhost|127\\.0\\.0\\.1)(?::[0-9]+)?$ [NC]
    RewriteRule ^ https://${APP_DOMAIN}%{REQUEST_URI} [R=301,L,NE]
CONF
            run apache2ctl configtest
            run systemctl reload apache2
            ;;
        existing)
            if [[ "${DRY_RUN}" != "1" ]]; then
                [[ -r "${TLS_CERT_FILE}" && -r "${TLS_KEY_FILE}" ]] || fail "Configured TLS certificate or key is not readable"
            fi
            log "Configuring existing TLS certificate"
            run tee /etc/apache2/sites-available/superoptic-mgs-ssl.conf >/dev/null <<CONF
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName ${APP_DOMAIN}
    DocumentRoot ${WEB_ROOT}
    SSLEngine on
    SSLCertificateFile ${TLS_CERT_FILE}
    SSLCertificateKeyFile ${TLS_KEY_FILE}
    <Directory ${WEB_ROOT}>
        AllowOverride All
        Options -Indexes
        Require all granted
    </Directory>
    <Directory ${WEB_ROOT}/data>
        Require all denied
    </Directory>
    <Directory ${WEB_ROOT}/tmp>
        Require all denied
    </Directory>
    <FilesMatch "(^\\.|config\\.local\\.php$|\\.env|\\.(log|sql|bak)$)">
        Require all denied
    </FilesMatch>
    ErrorLog \${APACHE_LOG_DIR}/superoptic-ssl-error.log
    CustomLog \${APACHE_LOG_DIR}/superoptic-ssl-access.log combined
</VirtualHost>
</IfModule>
CONF
            run a2ensite superoptic-mgs-ssl.conf
            run tee /etc/apache2/superoptic-force-https.conf >/dev/null <<CONF
    RewriteEngine On
    RewriteCond %{HTTPS} !=on
    RewriteCond %{HTTP_HOST} !^(localhost|127\\.0\\.0\\.1)(?::[0-9]+)?$ [NC]
    RewriteRule ^ https://${APP_DOMAIN}%{REQUEST_URI} [R=301,L,NE]
CONF
            run apache2ctl configtest
            run systemctl reload apache2
            ;;
        deferred)
            run rm -f /etc/apache2/superoptic-force-https.conf
            if [[ "${ALLOW_DEFERRED_TLS}" != "1" && "${DRY_RUN}" != "1" ]]; then
                fail "TLS is deferred. Set TLS_MODE=letsencrypt/existing, or ALLOW_DEFERRED_TLS=1 only for a non-public staging install"
            fi
            warn "TLS is deferred; do not expose this install publicly until HTTPS is configured."
            ;;
        off)
            run rm -f /etc/apache2/superoptic-force-https.conf
            [[ "${APP_URL}" == http://* || "${ALLOW_DEFERRED_TLS}" == "1" ]] || fail "TLS_MODE=off conflicts with HTTPS APP_URL"
            warn "TLS is disabled; this mode is for an external TLS gateway or isolated staging only."
            ;;
    esac
}

configure_firewall() {
    if [[ "${CONFIGURE_UFW}" != "1" ]]; then
        log "UFW configuration skipped"
        return
    fi
    log "Configuring host firewall"
    if [[ "${DRY_RUN}" != "1" ]]; then
        ss -H -ltn | awk -v suffix=":${SSH_PORT}" '$4 ~ (suffix "$") { found=1 } END { exit(found ? 0 : 1) }' \
            || fail "No SSH listener is active on configured SSH_PORT=${SSH_PORT}; refusing to enable UFW"
    fi
    run ufw default deny incoming
    run ufw default allow outgoing
    run ufw allow "${SSH_PORT}/tcp" comment 'SuperOptic SSH'
    run ufw allow 80/tcp comment 'SuperOptic HTTP ACME/redirect'
    if [[ "${TLS_MODE}" != "off" ]]; then
        run ufw allow 443/tcp comment 'SuperOptic HTTPS'
    fi
    if [[ -n "${RADIUS_ALLOWED_NAS_SOURCE}" ]]; then
        [[ "${RADIUS_SERVER_HOST}" == "127.0.0.1" || "${RADIUS_SERVER_HOST}" == "localhost" || "${RADIUS_SERVER_HOST}" == "::1" ]] \
            || fail "RADIUS_ALLOWED_NAS_SOURCE may only open local FreeRADIUS listeners"
        [[ "${RADIUS_ALLOWED_NAS_SOURCE}" =~ ^[A-Za-z0-9:./_-]+$ ]] || fail "RADIUS_ALLOWED_NAS_SOURCE is invalid"
        run ufw allow from "${RADIUS_ALLOWED_NAS_SOURCE}" to any port "${RADIUS_AUTH_PORT}" proto udp comment 'SuperOptic RADIUS auth'
        run ufw allow from "${RADIUS_ALLOWED_NAS_SOURCE}" to any port "${RADIUS_ACCT_PORT}" proto udp comment 'SuperOptic RADIUS accounting'
    fi
    run ufw --force enable
    run ufw status verbose
}

write_systemd_units() {
    if [[ "${SKIP_SYSTEMD}" == "1" ]]; then
        log "systemd units skipped"
        return
    fi

    log "Writing systemd service/timer units"
    run install -d -m 0770 -o www-data -g www-data "${BACKUP_ROOT}/mgs" "${UPDATES_ROOT}"
    local service_user
    for service_user in node billing communication pg365 radius modem optimizer security network finance whatsapp; do
        if [[ "${service_user}" == "node" ]]; then
            ensure_node_runtime_user
        elif [[ "${DRY_RUN}" == "1" ]]; then
            log "[DRY-RUN] would ensure system user superoptic-${service_user} with primary group www-data"
        elif ! id "superoptic-${service_user}" >/dev/null 2>&1; then
            useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin \
                --gid www-data "superoptic-${service_user}"
        fi
    done
    if [[ "${SKIP_MGS_NPM_INSTALL}" != "1" && -n "${SOURCE_MGS_ROOT}" ]]; then
        run tee /etc/systemd/system/superoptic-mgs-node.service >/dev/null <<CONF
[Unit]
Description=SuperOptic MGS Node service
After=network-online.target mysql.service mariadb.service
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=${MGS_ROOT}
Environment=NODE_ENV=production
Environment=HOME=/home/superoptic-node
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/node.env
ExecStart=${NODE_BIN:-/usr/local/bin/node} server.js
Restart=always
RestartSec=5
User=superoptic-node
Group=www-data

[Install]
WantedBy=multi-user.target
CONF
    else
        run systemctl disable --now superoptic-mgs-node.service 2>/dev/null || true
        run rm -f /etc/systemd/system/superoptic-mgs-node.service
    fi

    run tee /etc/systemd/system/superoptic-billing-maintenance.service >/dev/null <<CONF
[Unit]
Description=SuperOptic billing maintenance

[Service]
Type=oneshot
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/billing.env
User=superoptic-billing
Group=www-data
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/billing-maintenance.php --apply --sync-radius --queue-notices --channel=auto
CONF

    run tee /etc/systemd/system/superoptic-billing-maintenance.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic billing maintenance every 5 minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
Unit=superoptic-billing-maintenance.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-communication-queue.service >/dev/null <<CONF
[Unit]
Description=SuperOptic communication queue worker

[Service]
Type=oneshot
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/communication.env
User=superoptic-communication
Group=www-data
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/process-communication-queue.php --apply --limit=50 --channel=all
CONF

    run tee /etc/systemd/system/superoptic-communication-queue.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic communication queue every 2 minutes

[Timer]
OnBootSec=1min
OnUnitActiveSec=2min
Unit=superoptic-communication-queue.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-pg365-status.service >/dev/null <<CONF
[Unit]
Description=SuperOptic PG365 delivery status worker

[Service]
Type=oneshot
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/pg365.env
User=superoptic-pg365
Group=www-data
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/pg365-status-worker.php --apply --limit=100
CONF

    run tee /etc/systemd/system/superoptic-pg365-status.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic PG365 delivery status worker every 2 minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=2min
Unit=superoptic-pg365-status.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-radius-coa-worker.service >/dev/null <<CONF
[Unit]
Description=SuperOptic Radius CoA queue worker

[Service]
Type=oneshot
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/radius.env
User=superoptic-radius
Group=www-data
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/process-radius-session-actions.php --apply --limit=50
CONF

    run tee /etc/systemd/system/superoptic-radius-coa-worker.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic Radius CoA worker every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
Unit=superoptic-radius-coa-worker.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-modem-bot.service >/dev/null <<CONF
[Unit]
Description=SuperOptic Superpilot/modem Telegram bot poller

[Service]
Type=oneshot
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/modem.env
User=superoptic-modem
Group=www-data
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/process-telegram-modem-search-bot.php --once
CONF

    run tee /etc/systemd/system/superoptic-modem-bot.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic modem bot poller every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
Unit=superoptic-modem-bot.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-system-optimizer.service >/dev/null <<CONF
[Unit]
Description=SuperOptic retention and index optimizer

[Service]
Type=oneshot
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/optimizer.env
User=superoptic-optimizer
Group=www-data
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/system-maintenance-optimizer.php --apply --json
CONF

    run tee /etc/systemd/system/superoptic-system-optimizer.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic retention and index optimizer daily

[Timer]
OnCalendar=*-*-* 03:20:00
Persistent=true
Unit=superoptic-system-optimizer.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-security-anomaly.service >/dev/null <<CONF
[Unit]
Description=SuperOptic security anomaly scanner

[Service]
Type=oneshot
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/security.env
User=superoptic-security
Group=www-data
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/security-anomaly-scan.php --record --live
CONF

    run tee /etc/systemd/system/superoptic-security-anomaly.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic security anomaly scanner every 5 minutes

[Timer]
OnBootSec=3min
OnUnitActiveSec=5min
Unit=superoptic-security-anomaly.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-network-olt-rules.service >/dev/null <<CONF
[Unit]
Description=SuperOptic all-OLT optical and outage ticket rule scanner

[Service]
Type=oneshot
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/network.env
User=superoptic-network
Group=www-data
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/process-network-olt-ticket-rules.php --apply
CONF

    run tee /etc/systemd/system/superoptic-network-olt-rules.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic all-OLT ticket rule scanner every 5 minutes

[Timer]
OnBootSec=4min
OnUnitActiveSec=5min
Unit=superoptic-network-olt-rules.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-ont-telemetry-sync.service >/dev/null <<CONF
[Unit]
Description=SuperOptic live ONT temperature/TX/RX telemetry sync into customer profiles

[Service]
Type=oneshot
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/network.env
User=superoptic-network
Group=www-data
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/sync-ont-live-telemetry.php --apply --limit=20 --stale-minutes=15
CONF

    run tee /etc/systemd/system/superoptic-ont-telemetry-sync.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic ONT live telemetry sync every 10 minutes

[Timer]
OnBootSec=6min
OnUnitActiveSec=10min
Unit=superoptic-ont-telemetry-sync.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-finance-daily-report.service >/dev/null <<CONF
[Unit]
Description=SuperOptic finance daily income/expense report to the finance Telegram bot

[Service]
Type=oneshot
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/finance.env
User=superoptic-finance
Group=www-data
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/process-finance-daily-report.php --apply --period=daily
CONF

    run tee /etc/systemd/system/superoptic-whatsapp-heartbeat-watch.service >/dev/null <<CONF
[Unit]
Description=SuperOptic WhatsApp bridge heartbeat watchdog (alerts on a dead session, does not restart the bridge)

[Service]
Type=oneshot
EnvironmentFile=$(dirname "${SECRET_ENV_FILE}")/services/whatsapp.env
User=superoptic-whatsapp
Group=www-data
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/watch-whatsapp-heartbeat.php --apply
CONF

    run tee /etc/systemd/system/superoptic-whatsapp-heartbeat-watch.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic WhatsApp heartbeat watchdog every 5 minutes

[Timer]
OnBootSec=3min
OnUnitActiveSec=5min
Unit=superoptic-whatsapp-heartbeat-watch.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-finance-daily-report.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic finance daily report once a day at day-end

[Timer]
OnCalendar=*-*-* 23:55:00
Persistent=true
Unit=superoptic-finance-daily-report.service

[Install]
WantedBy=timers.target
CONF

    local service_name
    local -a hardened_services=(
        superoptic-billing-maintenance.service
        superoptic-communication-queue.service
        superoptic-pg365-status.service
        superoptic-radius-coa-worker.service
        superoptic-modem-bot.service
        superoptic-system-optimizer.service
        superoptic-security-anomaly.service
        superoptic-network-olt-rules.service
        superoptic-ont-telemetry-sync.service
        superoptic-finance-daily-report.service
        superoptic-whatsapp-heartbeat-watch.service
    )
    if [[ "${SKIP_MGS_NPM_INSTALL}" != "1" && -n "${SOURCE_MGS_ROOT}" ]]; then
        hardened_services=(superoptic-mgs-node.service "${hardened_services[@]}")
    fi
    for service_name in "${hardened_services[@]}"; do
        run mkdir -p "/etc/systemd/system/${service_name}.d"
        run tee "/etc/systemd/system/${service_name}.d/superoptic-hardening.conf" >/dev/null <<CONF
[Service]
Environment="SUPEROPTIC_MGS_ROOT=${MGS_ROOT}"
Environment="SUPEROPTIC_BILLING_ROOT=${WEB_ROOT}"
Environment="SUPEROPTIC_UPDATES_ROOT=${UPDATES_ROOT}"
Environment="BACKUP_DIR=${BACKUP_ROOT}/mgs"
Environment="AI_MODEL_PROVIDER=${AI_MODEL_PROVIDER}"
Environment="LOCAL_LLM_ENDPOINT=${LOCAL_LLM_ENDPOINT}"
Environment="LOCAL_LLM_MODEL=${LOCAL_LLM_MODEL}"
Environment="LOCAL_LLM_API=${LOCAL_LLM_API}"
Environment="DEEPSEEK_BASE_URL=${DEEPSEEK_BASE_URL}"
Environment="DEEPSEEK_MODEL=${DEEPSEEK_MODEL}"
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ProtectKernelTunables=true
ProtectControlGroups=true
ProtectProc=invisible
ProcSubset=pid
RestrictSUIDSGID=true
LockPersonality=true
ReadWritePaths=${WEB_ROOT}/data ${WEB_ROOT}/tmp -${MGS_ROOT}/data -${MGS_ROOT}/logs -${MGS_ROOT}/runtime ${BACKUP_ROOT}/mgs ${UPDATES_ROOT}
CONF
    done

    run systemctl daemon-reload
    if [[ "${SKIP_MGS_NPM_INSTALL}" != "1" && ( -d "${MGS_ROOT}" || ( "${DRY_RUN}" == "1" && -n "${SOURCE_MGS_ROOT}" ) ) ]]; then
        run systemctl enable --now superoptic-mgs-node.service
    fi
    run systemctl enable --now superoptic-billing-maintenance.timer
    run systemctl enable --now superoptic-communication-queue.timer
    run systemctl enable --now superoptic-pg365-status.timer
    run systemctl enable --now superoptic-radius-coa-worker.timer
    run systemctl enable --now superoptic-modem-bot.timer
    run systemctl enable --now superoptic-system-optimizer.timer
    run systemctl enable --now superoptic-security-anomaly.timer
    run systemctl enable --now superoptic-network-olt-rules.timer
    run systemctl enable --now superoptic-ont-telemetry-sync.timer
    run systemctl enable --now superoptic-finance-daily-report.timer
    run systemctl enable --now superoptic-whatsapp-heartbeat-watch.timer
}

verify_install() {
    log "Verification"
    run php -v
    if [[ "${SKIP_MGS_NPM_INSTALL}" != "1" ]]; then
        run "${NODE_BIN:-node}" -v
    fi
    run java -version
    if command -v snmpwalk >/dev/null 2>&1; then
        log "snmpwalk available: $(command -v snmpwalk)"
    else
        warn "snmpwalk not found"
    fi
    if [[ "${DRY_RUN}" != "1" ]]; then
        php "${WEB_ROOT}/tools/secret-readiness-smoke.php" || fail "secret readiness smoke failed"
        php "${WEB_ROOT}/tools/device-backup-worker.php" --dry-run --limit=3 --type=profile_snapshot || warn "device backup worker dry-run failed"
        php "${WEB_ROOT}/tools/update-package-smoke.php" || warn "update-package smoke failed"
        php "${WEB_ROOT}/tools/provider-api-smoke.php" || warn "provider-api smoke failed"
        php "${WEB_ROOT}/tools/provider-up-api-smoke.php" || warn "provider-up-api smoke failed"
        php "${WEB_ROOT}/tools/system-maintenance-optimizer.php" --json || warn "system optimizer dry-run failed"
        php "${WEB_ROOT}/tools/security-anomaly-scan.php" || warn "security anomaly dry-run failed"
        php "${WEB_ROOT}/tools/process-network-olt-ticket-rules.php" || warn "network OLT ticket rule dry-run failed"
    fi
}

run_production_preflight() {
    if [[ "${RUN_PRODUCTION_PREFLIGHT}" != "1" ]]; then
        log "Production preflight skipped for this staging install"
        return
    fi
    local preflight="${WEB_ROOT}/deploy/ubuntu-production-preflight.sh"
    [[ -f "${preflight}" || "${DRY_RUN}" == "1" ]] || fail "Production preflight script is missing"
    log "Running the mandatory production preflight gate"
    run bash "${preflight}" \
        --web-root="${WEB_ROOT}" \
        --env-file="${SECRET_ENV_FILE}" \
        --app-domain="${APP_DOMAIN}" \
        || fail "Production preflight gate failed"
}

require_root
trap cleanup_installer_exit EXIT
validate_runtime_options
detect_sources
validate_radius_ports
install_packages
configure_timezone
create_preinstall_backup
copy_sources
install_mgs_node_dependencies
bootstrap_secrets
# The sourced bootstrap owns an EXIT cleanup for its temporary files. Restore the
# installer cleanup before temporary database DDL privileges are granted.
trap cleanup_installer_exit EXIT
create_database
write_config
bootstrap_php_db
migrate_portal_schema
create_initial_super_admin
if ! migrate_mgs_node; then
    fail "MGS migration failed; runtime DDL privileges will be revoked before exit"
fi
lock_runtime_database_privileges || fail "Runtime database privilege restriction failed"
configure_apache
configure_service_environment
configure_tls
configure_firewall
write_systemd_units
verify_install
run_production_preflight

log "DONE: SuperOptic MGS installed on Ubuntu. FreeRADIUS remains external."
printf 'Web root: %s\nMGS root: %s\nApp URL: %s\nDB: %s:%s/%s\nRADIUS UDP: %s/%s\n' "${WEB_ROOT}" "${MGS_ROOT}" "${APP_URL}" "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${RADIUS_AUTH_PORT}" "${RADIUS_ACCT_PORT}"
