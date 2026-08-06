#!/usr/bin/env bash
set -Eeuo pipefail

SUPEROPTIC_SECRET_ENV_FILE="${SUPEROPTIC_SECRET_ENV_FILE:-/etc/superoptic-mgs/superoptic.env}"
SUPEROPTIC_SECRET_GROUP="${SUPEROPTIC_SECRET_GROUP:-www-data}"
SUPEROPTIC_SECRET_DRY_RUN=0
SUPEROPTIC_SECRET_NONINTERACTIVE=0
SUPEROPTIC_SECRET_ROTATE=0
SUPEROPTIC_SECRET_VALIDATE_ONLY=0
SUPEROPTIC_SECRET_RESTART_SERVICES=0
SUPEROPTIC_SECRET_ALLOW_MISSING_EXTERNAL=0
SUPEROPTIC_SECRET_TEMP_FILE=""
SUPEROPTIC_SECRET_SCOPED_TEMP_FILE=""
SUPEROPTIC_SECRET_SERVICE_ENV_DIR=""
SUPEROPTIC_DB_HOST_SUPPLIED="${SUPEROPTIC_DB_HOST+x}"
SUPEROPTIC_DB_PORT_SUPPLIED="${SUPEROPTIC_DB_PORT+x}"
SUPEROPTIC_DB_NAME_SUPPLIED="${SUPEROPTIC_DB_NAME+x}"
SUPEROPTIC_DB_USER_SUPPLIED="${SUPEROPTIC_DB_USER+x}"
SUPEROPTIC_RADIUS_DB_HOST_SUPPLIED="${SUPEROPTIC_RADIUS_DB_HOST+x}"
SUPEROPTIC_RADIUS_DB_PORT_SUPPLIED="${SUPEROPTIC_RADIUS_DB_PORT+x}"
SUPEROPTIC_RADIUS_DB_NAME_SUPPLIED="${SUPEROPTIC_RADIUS_DB_NAME+x}"
SUPEROPTIC_RADIUS_DB_USER_SUPPLIED="${SUPEROPTIC_RADIUS_DB_USER+x}"
AI_MODEL_PROVIDER_SUPPLIED="${AI_MODEL_PROVIDER+x}"
LOCAL_LLM_ENDPOINT_SUPPLIED="${LOCAL_LLM_ENDPOINT+x}"
LOCAL_LLM_MODEL_SUPPLIED="${LOCAL_LLM_MODEL+x}"
LOCAL_LLM_API_SUPPLIED="${LOCAL_LLM_API+x}"
DEEPSEEK_BASE_URL_SUPPLIED="${DEEPSEEK_BASE_URL+x}"
DEEPSEEK_MODEL_SUPPLIED="${DEEPSEEK_MODEL+x}"
SUPEROPTIC_DB_HOST="${SUPEROPTIC_DB_HOST:-127.0.0.1}"
SUPEROPTIC_DB_PORT="${SUPEROPTIC_DB_PORT:-3306}"
SUPEROPTIC_DB_NAME="${SUPEROPTIC_DB_NAME:-superoptic_portal}"
SUPEROPTIC_DB_USER="${SUPEROPTIC_DB_USER:-superoptic_app}"
SUPEROPTIC_RADIUS_DB_HOST="${SUPEROPTIC_RADIUS_DB_HOST:-127.0.0.1}"
SUPEROPTIC_RADIUS_DB_PORT="${SUPEROPTIC_RADIUS_DB_PORT:-3306}"
SUPEROPTIC_RADIUS_DB_NAME="${SUPEROPTIC_RADIUS_DB_NAME:-superoptic_portal}"
SUPEROPTIC_RADIUS_DB_USER="${SUPEROPTIC_RADIUS_DB_USER:-radius}"
AI_MODEL_PROVIDER="${AI_MODEL_PROVIDER:-deepseek}"
LOCAL_LLM_ENDPOINT="${LOCAL_LLM_ENDPOINT:-http://127.0.0.1:11434}"
LOCAL_LLM_MODEL="${LOCAL_LLM_MODEL:-qwen2.5:7b-instruct-q4_K_M}"
LOCAL_LLM_API="${LOCAL_LLM_API:-ollama}"
DEEPSEEK_BASE_URL="${DEEPSEEK_BASE_URL:-https://api.deepseek.com}"
DEEPSEEK_MODEL="${DEEPSEEK_MODEL:-deepseek-v4-flash}"

SUPEROPTIC_SECRET_KEYS=(
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

SUPEROPTIC_SECRET_METADATA_KEYS=(
    SUPEROPTIC_DB_HOST
    SUPEROPTIC_DB_PORT
    SUPEROPTIC_DB_NAME
    SUPEROPTIC_DB_USER
    SUPEROPTIC_RADIUS_DB_HOST
    SUPEROPTIC_RADIUS_DB_PORT
    SUPEROPTIC_RADIUS_DB_NAME
    SUPEROPTIC_RADIUS_DB_USER
    AI_MODEL_PROVIDER
    LOCAL_LLM_ENDPOINT
    LOCAL_LLM_MODEL
    LOCAL_LLM_API
    DEEPSEEK_BASE_URL
    DEEPSEEK_MODEL
)

superoptic_secret_log() {
    printf '[SuperOptic secrets] %s\n' "$*"
}

superoptic_secret_warn() {
    printf 'WARN: %s\n' "$*" >&2
}

superoptic_secret_fail() {
    printf 'ERROR: %s\n' "$*" >&2
    return 1
}

superoptic_secret_usage() {
    cat <<'USAGE'
Usage: set-superoptic-secrets.sh [options]

Options:
  --env-file PATH       Service environment file (default: /etc/superoptic-mgs/superoptic.env)
  --group NAME          Read-only service group (default: www-data)
  --dry-run             Validate supplied values and describe actions without prompting or writing
  --non-interactive     Require secrets from the process environment; never prompt
  --rotate              Replace only explicitly supplied secrets; retain all others
  --validate-only       Validate the existing environment file without changing it
  --restart-services    Restart Apache and the active MGS Node service after rotation
  --allow-missing-external
                        Staging only: keep unavailable external credentials empty
  --help                Show this help

Secret values are accepted only through the process environment or hidden terminal prompts.
They are never accepted as command-line arguments and are never printed.
USAGE
}

superoptic_secret_min_length() {
    case "$1" in
        TELEGRAM_*_BOT_TOKEN|TELEGRAM_TOKEN|RADIUS_TEST_PASSWORD|IMPORT_API_TOKEN|COPILOT_API_TOKEN|RECAPTCHA_SECRET|MYGOV_CLIENT_SECRET|CLICKHOUSE_PASSWORD|PROVIDER_API_TOKEN|EMANAT_PROVIDER_API_TOKEN|UNITEDPAYMENT_PROVIDER_API_TOKEN|MGS_SERVICE_SECRET|MGS_PORTAL_SHARED_SECRET|MGS_TELEGRAM_WEBHOOK_SECRET) printf '24' ;;
        BILLING_SSO_SHARED_SECRET|OLT_SSO_SHARED_SECRET|OLT_USER_SYNC_SECRET|API_SECRET_KEY|PAIRING_WORKER_TOKEN|PORTAL_TICKET_CLOSE_SECRET|PROVIDER_API_HMAC_SECRET|EMANAT_PROVIDER_HMAC_SECRET|UNITEDPAYMENT_PROVIDER_HMAC_SECRET|MGS_JWT_SECRET|MGS_SECRETS_MASTER_KEY) printf '32' ;;
        *) printf '8' ;;
    esac
}

superoptic_secret_is_generated() {
    case "$1" in
        RADIUS_TEST_PASSWORD|IMPORT_API_TOKEN|COPILOT_API_TOKEN|BILLING_SSO_SHARED_SECRET|OLT_SSO_SHARED_SECRET|OLT_USER_SYNC_SECRET|API_SECRET_KEY|PAIRING_WORKER_TOKEN|CLICKHOUSE_PASSWORD|PORTAL_TICKET_CLOSE_SECRET|PROVIDER_API_TOKEN|PROVIDER_API_HMAC_SECRET|EMANAT_PROVIDER_API_TOKEN|EMANAT_PROVIDER_HMAC_SECRET|UNITEDPAYMENT_PROVIDER_API_TOKEN|UNITEDPAYMENT_PROVIDER_HMAC_SECRET|MGS_*) return 0 ;;
        *) return 1 ;;
    esac
}

superoptic_secret_is_staging_optional_external() {
    case "$1" in
        RADIUS_DB_PASS|RADIUS_ROUTER_SECRET|DEEPSEEK_API_KEY|SMS_PROVIDER_PUBLIC_KEY|SMS_PROVIDER_PRIVATE_KEY|TELEGRAM_TOKEN|TELEGRAM_*_BOT_TOKEN|WHATSAPP_PROVIDER_TOKEN|RADIUS_SSH_PASSWORD|SNMP_COMMUNITY|RECAPTCHA_SECRET|MYGOV_CLIENT_SECRET) return 0 ;;
        *) return 1 ;;
    esac
}

superoptic_secret_is_placeholder() {
    local value="${1^^}"
    case "${value}" in
        *CHANGE_ME*|*CHANGEME*|*EXAMPLE*|*TEST_SECRET*|*DRY_RUN_SECRET*|SECRET|TESTING123|PUBLIC|PRIVATE)
            return 0
            ;;
    esac
    return 1
}

superoptic_secret_validate_value() {
    local key="$1"
    local value="$2"
    local min_length
    min_length="$(superoptic_secret_min_length "${key}")"

    [[ -n "${value}" ]] || superoptic_secret_fail "${key} is missing"
    [[ "${value}" != *$'\n'* && "${value}" != *$'\r'* ]] || superoptic_secret_fail "${key} contains a line break"
    (( ${#value} >= min_length )) || superoptic_secret_fail "${key} does not meet the minimum length policy"
    ! superoptic_secret_is_placeholder "${value}" || superoptic_secret_fail "${key} contains a placeholder/default value"
}

superoptic_secret_validate_all() {
    local key value expected_database_url expected_radius_database_url
    for key in "${SUPEROPTIC_SECRET_KEYS[@]}"; do
        value="${!key-}"
        if [[ "${SUPEROPTIC_SECRET_ALLOW_MISSING_EXTERNAL}" == "1" ]] \
            && superoptic_secret_is_staging_optional_external "${key}" \
            && [[ -z "${value}" ]]; then
            continue
        fi
        if [[ "${key}" == "DEEPSEEK_API_KEY" && "${AI_MODEL_PROVIDER}" != "deepseek" && -z "${value}" ]]; then
            continue
        fi
        superoptic_secret_validate_value "${key}" "${value}"
    done

    [[ "${AI_MODEL_PROVIDER}" == "local" || "${AI_MODEL_PROVIDER}" == "deepseek" || "${AI_MODEL_PROVIDER}" == "disabled" ]] || superoptic_secret_fail "Invalid AI_MODEL_PROVIDER metadata"
    [[ "${LOCAL_LLM_ENDPOINT}" == "http://127.0.0.1:11434" ]] || superoptic_secret_fail "LOCAL_LLM_ENDPOINT must use the loopback Ollama endpoint"
    [[ "${LOCAL_LLM_MODEL}" =~ ^[A-Za-z0-9._:/-]{1,128}$ ]] || superoptic_secret_fail "Invalid LOCAL_LLM_MODEL metadata"
    [[ "${LOCAL_LLM_API}" == "ollama" || "${LOCAL_LLM_API}" == "openai" ]] || superoptic_secret_fail "Invalid LOCAL_LLM_API metadata"
    [[ "${DEEPSEEK_BASE_URL}" == "https://api.deepseek.com" ]] || superoptic_secret_fail "Invalid DEEPSEEK_BASE_URL metadata"
    [[ "${DEEPSEEK_MODEL}" == "deepseek-v4-flash" || "${DEEPSEEK_MODEL}" == "deepseek-v4-pro" ]] || superoptic_secret_fail "Invalid DEEPSEEK_MODEL metadata"

    [[ "${RADIUS_SQL_PASSWORD-}" == "${RADIUS_DB_PASS}" ]] || superoptic_secret_fail "RADIUS_SQL_PASSWORD alias is missing or inconsistent"
    [[ "${RADIUS_SHARED_SECRET-}" == "${RADIUS_ROUTER_SECRET}" ]] || superoptic_secret_fail "RADIUS_SHARED_SECRET alias is missing or inconsistent"
    [[ "${SMS_PROVIDER_USER-}" == "${SMS_PROVIDER_PUBLIC_KEY}" ]] || superoptic_secret_fail "SMS_PROVIDER_USER alias is missing or inconsistent"
    [[ "${SMS_PROVIDER_PASSWORD-}" == "${SMS_PROVIDER_PRIVATE_KEY}" ]] || superoptic_secret_fail "SMS_PROVIDER_PASSWORD alias is missing or inconsistent"
    [[ "${MGS_SERVICE_SHARED_SECRET-}" == "${MGS_SERVICE_SECRET}" ]] || superoptic_secret_fail "MGS_SERVICE_SHARED_SECRET alias is missing or inconsistent"
    [[ "${SERVICE_SHARED_SECRET-}" == "${MGS_SERVICE_SECRET}" ]] || superoptic_secret_fail "SERVICE_SHARED_SECRET alias is missing or inconsistent"
    [[ "${JWT_SECRET-}" == "${MGS_JWT_SECRET}" ]] || superoptic_secret_fail "JWT_SECRET alias is missing or inconsistent"
    [[ "${SECRETS_MASTER_KEY-}" == "${MGS_SECRETS_MASTER_KEY}" ]] || superoptic_secret_fail "SECRETS_MASTER_KEY alias is missing or inconsistent"
    [[ "${PORTAL_SHARED_SECRET-}" == "${MGS_PORTAL_SHARED_SECRET}" ]] || superoptic_secret_fail "PORTAL_SHARED_SECRET alias is missing or inconsistent"
    [[ "${TELEGRAM_WEBHOOK_SECRET-}" == "${MGS_TELEGRAM_WEBHOOK_SECRET}" ]] || superoptic_secret_fail "TELEGRAM_WEBHOOK_SECRET alias is missing or inconsistent"
    [[ "${TELEGRAM_BOT_TOKEN-}" == "${TELEGRAM_TOKEN}" ]] || superoptic_secret_fail "TELEGRAM_BOT_TOKEN alias is missing or inconsistent"
    [[ "${WHATSAPP_PROVIDER_API_KEY-}" == "${WHATSAPP_PROVIDER_TOKEN}" ]] || superoptic_secret_fail "WHATSAPP_PROVIDER_API_KEY alias is missing or inconsistent"
    [[ "${SMS_PROVIDER_API_KEY-}" == "${SMS_PROVIDER_PRIVATE_KEY}" ]] || superoptic_secret_fail "SMS_PROVIDER_API_KEY alias is missing or inconsistent"
    expected_database_url="$(superoptic_secret_build_mysql_url "${SUPEROPTIC_DB_HOST}" "${SUPEROPTIC_DB_PORT}" "${SUPEROPTIC_DB_NAME}" "${SUPEROPTIC_DB_USER}" "${DB_PASS}")"
    expected_radius_database_url="$(superoptic_secret_build_mysql_url "${SUPEROPTIC_RADIUS_DB_HOST}" "${SUPEROPTIC_RADIUS_DB_PORT}" "${SUPEROPTIC_RADIUS_DB_NAME}" "${SUPEROPTIC_RADIUS_DB_USER}" "${RADIUS_DB_PASS}")"
    [[ "${DATABASE_URL-}" == "${expected_database_url}" ]] || superoptic_secret_fail "DATABASE_URL is missing or inconsistent"
    [[ "${RADIUS_DATABASE_URL-}" == "${expected_radius_database_url}" ]] || superoptic_secret_fail "RADIUS_DATABASE_URL is missing or inconsistent"
}

superoptic_secret_export_aliases() {
    export RADIUS_SQL_PASSWORD="${RADIUS_DB_PASS}"
    export RADIUS_SHARED_SECRET="${RADIUS_ROUTER_SECRET}"
    export SMS_PROVIDER_USER="${SMS_PROVIDER_PUBLIC_KEY}"
    export SMS_PROVIDER_PASSWORD="${SMS_PROVIDER_PRIVATE_KEY}"
    export MGS_SERVICE_SHARED_SECRET="${MGS_SERVICE_SECRET}"
    export SERVICE_SHARED_SECRET="${MGS_SERVICE_SECRET}"
    export JWT_SECRET="${MGS_JWT_SECRET}"
    export SECRETS_MASTER_KEY="${MGS_SECRETS_MASTER_KEY}"
    export PORTAL_SHARED_SECRET="${MGS_PORTAL_SHARED_SECRET}"
    export TELEGRAM_WEBHOOK_SECRET="${MGS_TELEGRAM_WEBHOOK_SECRET}"
    export TELEGRAM_BOT_TOKEN="${TELEGRAM_TOKEN}"
    export WHATSAPP_PROVIDER_API_KEY="${WHATSAPP_PROVIDER_TOKEN}"
    export SMS_PROVIDER_API_KEY="${SMS_PROVIDER_PRIVATE_KEY}"
    export DATABASE_URL="$(superoptic_secret_build_mysql_url "${SUPEROPTIC_DB_HOST}" "${SUPEROPTIC_DB_PORT}" "${SUPEROPTIC_DB_NAME}" "${SUPEROPTIC_DB_USER}" "${DB_PASS}")"
    export RADIUS_DATABASE_URL="$(superoptic_secret_build_mysql_url "${SUPEROPTIC_RADIUS_DB_HOST}" "${SUPEROPTIC_RADIUS_DB_PORT}" "${SUPEROPTIC_RADIUS_DB_NAME}" "${SUPEROPTIC_RADIUS_DB_USER}" "${RADIUS_DB_PASS}")"
}

superoptic_secret_urlencode() {
    local value="$1" char encoded="" hex i
    local LC_ALL=C
    for ((i = 0; i < ${#value}; i++)); do
        char="${value:i:1}"
        case "${char}" in
            [A-Za-z0-9._~-]) encoded+="${char}" ;;
            *)
                printf -v hex '%%%02X' "'${char}"
                encoded+="${hex}"
                ;;
        esac
    done
    printf '%s' "${encoded}"
}

superoptic_secret_build_mysql_url() {
    local host="$1" port="$2" database="$3" user="$4" password="$5"
    local encoded_user encoded_password encoded_database formatted_host
    [[ -n "${host}" && "${host}" != *[[:space:]/@?#]* ]] || superoptic_secret_fail "Invalid database host metadata"
    [[ "${port}" =~ ^[0-9]+$ ]] && (( port >= 1 && port <= 65535 )) || superoptic_secret_fail "Invalid database port metadata"
    [[ "${database}" =~ ^[A-Za-z0-9_]+$ ]] || superoptic_secret_fail "Invalid database name metadata"
    [[ -n "${user}" ]] || superoptic_secret_fail "Invalid database user metadata"
    encoded_user="$(superoptic_secret_urlencode "${user}")"
    encoded_password="$(superoptic_secret_urlencode "${password}")"
    encoded_database="$(superoptic_secret_urlencode "${database}")"
    formatted_host="${host}"
    [[ "${host}" == *:* && "${host}" != \[*\] ]] && formatted_host="[${host}]"
    printf 'mysql://%s:%s@%s:%s/%s' "${encoded_user}" "${encoded_password}" "${formatted_host}" "${port}" "${encoded_database}"
}

superoptic_secret_require_root() {
    if [[ "${SUPEROPTIC_SECRET_DRY_RUN}" == "1" ]]; then
        return 0
    fi
    [[ "${EUID}" -eq 0 ]] || superoptic_secret_fail "Run as root so the service environment can be protected"
}

superoptic_secret_load_file() {
    local file="$1"
    local owner mode mode_value
    [[ -f "${file}" ]] || superoptic_secret_fail "Secret environment file not found: ${file}"

    owner="$(stat -c '%U' "${file}")"
    mode="$(stat -c '%a' "${file}")"
    [[ "${owner}" == "root" ]] || superoptic_secret_fail "Secret environment file must be owned by root"
    mode_value=$((8#${mode}))
    (( (mode_value & 0022) == 0 )) || superoptic_secret_fail "Secret environment file must not be group/world writable"

    set -a
    # The file is root-owned, non-writable by services, and generated by this script.
    # shellcheck disable=SC1090
    source "${file}"
    set +a
}

superoptic_secret_prompt() {
    local key="$1"
    local label="$2"
    local value=""
    [[ -r /dev/tty && -w /dev/tty ]] || superoptic_secret_fail "No interactive terminal is available for ${key}; use --non-interactive with environment variables"
    printf '%s: ' "${label}" >/dev/tty
    IFS= read -r -s value </dev/tty || superoptic_secret_fail "Unable to read ${key}"
    printf '\n' >/dev/tty
    printf -v "${key}" '%s' "${value}"
    export "${key}"
}

superoptic_secret_label() {
    case "$1" in
        DB_PASS) printf 'MySQL application password' ;;
        RADIUS_DB_PASS) printf 'RADIUS SQL password' ;;
        RADIUS_ROUTER_SECRET) printf 'RADIUS router shared secret' ;;
        DEEPSEEK_API_KEY) printf 'DeepSeek API key' ;;
        SMS_PROVIDER_PUBLIC_KEY) printf 'SMS provider public key' ;;
        SMS_PROVIDER_PRIVATE_KEY) printf 'SMS provider private key' ;;
        TELEGRAM_TOKEN) printf 'Telegram bot token' ;;
        TELEGRAM_NETWORK_BOT_TOKEN) printf 'Telegram network bot token' ;;
        TELEGRAM_CUSTOMER_BOT_TOKEN) printf 'Telegram customer bot token' ;;
        TELEGRAM_FINANCE_BOT_TOKEN) printf 'Telegram finance bot token' ;;
        TELEGRAM_WAREHOUSE_BOT_TOKEN) printf 'Telegram warehouse bot token' ;;
        TELEGRAM_BACKUP_BOT_TOKEN) printf 'Telegram backup bot token' ;;
        TELEGRAM_DIAGNOSTICS_BOT_TOKEN) printf 'Telegram diagnostics bot token' ;;
        TELEGRAM_MODEM_SEARCH_BOT_TOKEN) printf 'Telegram modem-search bot token' ;;
        WHATSAPP_PROVIDER_TOKEN) printf 'WhatsApp provider token' ;;
        RADIUS_SSH_PASSWORD) printf 'Router SSH password' ;;
        RADIUS_TEST_PASSWORD) printf 'RADIUS test-user password' ;;
        SNMP_COMMUNITY) printf 'SNMP read-only community' ;;
        IMPORT_API_TOKEN) printf 'Import API token' ;;
        COPILOT_API_TOKEN) printf 'Copilot API token' ;;
        RECAPTCHA_SECRET) printf 'reCAPTCHA provider secret' ;;
        BILLING_SSO_SHARED_SECRET) printf 'Billing SSO shared secret' ;;
        OLT_SSO_SHARED_SECRET) printf 'OLT SSO shared secret' ;;
        OLT_USER_SYNC_SECRET) printf 'OLT user-sync shared secret' ;;
        MYGOV_CLIENT_SECRET) printf 'MyGov client secret' ;;
        API_SECRET_KEY) printf 'WhatsApp bridge API secret key' ;;
        PAIRING_WORKER_TOKEN) printf 'WhatsApp pairing-worker token' ;;
        CLICKHOUSE_PASSWORD) printf 'ClickHouse service password' ;;
        PORTAL_TICKET_CLOSE_SECRET) printf 'Portal ticket-close signing secret' ;;
        PROVIDER_API_TOKEN) printf 'Provider API token' ;;
        PROVIDER_API_HMAC_SECRET) printf 'Provider API HMAC secret' ;;
        EMANAT_PROVIDER_API_TOKEN) printf 'Emanat provider API token' ;;
        EMANAT_PROVIDER_HMAC_SECRET) printf 'Emanat provider HMAC secret' ;;
        UNITEDPAYMENT_PROVIDER_API_TOKEN) printf 'United Payment provider API token' ;;
        UNITEDPAYMENT_PROVIDER_HMAC_SECRET) printf 'United Payment provider HMAC secret' ;;
        MGS_SERVICE_SECRET) printf 'MGS service shared secret' ;;
        MGS_JWT_SECRET) printf 'MGS JWT signing secret' ;;
        MGS_SECRETS_MASTER_KEY) printf 'MGS encrypted-device-secret master key' ;;
        MGS_PORTAL_SHARED_SECRET) printf 'MGS portal shared secret' ;;
        MGS_TELEGRAM_WEBHOOK_SECRET) printf 'MGS Telegram webhook secret' ;;
        *) printf '%s' "$1" ;;
    esac
}

superoptic_secret_generate() {
    command -v openssl >/dev/null 2>&1 || superoptic_secret_fail "openssl is required to generate MGS_SERVICE_SECRET"
    openssl rand -base64 32 | tr -d '\n'
}

superoptic_secret_collect() {
    local key value label
    for key in "${SUPEROPTIC_SECRET_KEYS[@]}"; do
        value="${!key-}"
        if superoptic_secret_is_generated "${key}" && [[ -z "${value}" ]]; then
            value="$(superoptic_secret_generate)"
            printf -v "${key}" '%s' "${value}"
            export "${key}"
            continue
        fi
        if [[ -n "${value}" ]]; then
            continue
        fi
        if [[ "${SUPEROPTIC_SECRET_ALLOW_MISSING_EXTERNAL}" == "1" ]] \
            && superoptic_secret_is_staging_optional_external "${key}"; then
            printf -v "${key}" '%s' ''
            export "${key}"
            continue
        fi
        if [[ "${SUPEROPTIC_SECRET_NONINTERACTIVE}" == "1" ]]; then
            superoptic_secret_fail "${key} is required in non-interactive mode"
        fi
        label="$(superoptic_secret_label "${key}")"
        superoptic_secret_prompt "${key}" "${label}"
    done
    superoptic_secret_export_aliases
    superoptic_secret_validate_all
}

superoptic_secret_escape_environment_value() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//\$/\\$}"
    value="${value//\`/\\\`}"
    printf '"%s"' "${value}"
}

superoptic_secret_write_file() {
    local directory key value alias metadata
    directory="$(dirname "${SUPEROPTIC_SECRET_ENV_FILE}")"
    getent group "${SUPEROPTIC_SECRET_GROUP}" >/dev/null 2>&1 || superoptic_secret_fail "Service group not found: ${SUPEROPTIC_SECRET_GROUP}"

    install -d -m 0700 -o root -g root "${directory}"
    umask 077
    SUPEROPTIC_SECRET_TEMP_FILE="$(mktemp "${directory}/.superoptic.env.XXXXXX")"
    {
        printf '# Managed by deploy/set-superoptic-secrets.sh. Do not commit or print values.\n'
        for metadata in "${SUPEROPTIC_SECRET_METADATA_KEYS[@]}"; do
            value="${!metadata}"
            printf '%s=' "${metadata}"
            superoptic_secret_escape_environment_value "${value}"
            printf '\n'
        done
        for key in "${SUPEROPTIC_SECRET_KEYS[@]}"; do
            value="${!key}"
            printf '%s=' "${key}"
            superoptic_secret_escape_environment_value "${value}"
            printf '\n'
        done
        for alias in RADIUS_SQL_PASSWORD RADIUS_SHARED_SECRET SMS_PROVIDER_USER SMS_PROVIDER_PASSWORD MGS_SERVICE_SHARED_SECRET SERVICE_SHARED_SECRET JWT_SECRET SECRETS_MASTER_KEY PORTAL_SHARED_SECRET TELEGRAM_WEBHOOK_SECRET TELEGRAM_BOT_TOKEN WHATSAPP_PROVIDER_API_KEY SMS_PROVIDER_API_KEY DATABASE_URL RADIUS_DATABASE_URL; do
            value="${!alias}"
            printf '%s=' "${alias}"
            superoptic_secret_escape_environment_value "${value}"
            printf '\n'
        done
    } >"${SUPEROPTIC_SECRET_TEMP_FILE}"
    chown root:root "${SUPEROPTIC_SECRET_TEMP_FILE}"
    chmod 0600 "${SUPEROPTIC_SECRET_TEMP_FILE}"
    mv -f -- "${SUPEROPTIC_SECRET_TEMP_FILE}" "${SUPEROPTIC_SECRET_ENV_FILE}"
    SUPEROPTIC_SECRET_TEMP_FILE=""
    superoptic_secret_log "Root-only service environment updated at ${SUPEROPTIC_SECRET_ENV_FILE}; values were not displayed"
}

superoptic_secret_write_scoped_file() {
    local name="$1" key line temporary
    shift
    temporary="$(mktemp "${SUPEROPTIC_SECRET_SERVICE_ENV_DIR}/.${name}.env.XXXXXX")"
    SUPEROPTIC_SECRET_SCOPED_TEMP_FILE="${temporary}"
    {
        printf '# Managed service scope. Do not commit or print values.\n'
        for key in "$@"; do
            line="$(grep -m1 -E "^${key}=" "${SUPEROPTIC_SECRET_ENV_FILE}" || true)"
            [[ -n "${line}" ]] || superoptic_secret_fail "Managed secret key is missing while writing ${name}.env: ${key}"
            printf '%s\n' "${line}"
        done
    } >"${temporary}"
    chown root:root "${temporary}"
    chmod 0600 "${temporary}"
    mv -f -- "${temporary}" "${SUPEROPTIC_SECRET_SERVICE_ENV_DIR}/${name}.env"
    SUPEROPTIC_SECRET_SCOPED_TEMP_FILE=""
}

superoptic_secret_write_service_files() {
    SUPEROPTIC_SECRET_SERVICE_ENV_DIR="${SUPEROPTIC_SECRET_SERVICE_ENV_DIR:-$(dirname "${SUPEROPTIC_SECRET_ENV_FILE}")/services}"
    install -d -m 0700 -o root -g root "${SUPEROPTIC_SECRET_SERVICE_ENV_DIR}"

    superoptic_secret_write_scoped_file node \
        DATABASE_URL RADIUS_DATABASE_URL DEEPSEEK_API_KEY API_SECRET_KEY PAIRING_WORKER_TOKEN \
        CLICKHOUSE_PASSWORD PROVIDER_API_TOKEN PROVIDER_API_HMAC_SECRET MGS_SERVICE_SECRET \
        MGS_JWT_SECRET MGS_SECRETS_MASTER_KEY MGS_PORTAL_SHARED_SECRET MGS_TELEGRAM_WEBHOOK_SECRET \
        MGS_SERVICE_SHARED_SECRET SERVICE_SHARED_SECRET JWT_SECRET SECRETS_MASTER_KEY \
        PORTAL_SHARED_SECRET TELEGRAM_WEBHOOK_SECRET
    superoptic_secret_write_scoped_file billing \
        DB_PASS RADIUS_DB_PASS RADIUS_ROUTER_SECRET RADIUS_SSH_PASSWORD RADIUS_TEST_PASSWORD \
        SMS_PROVIDER_PUBLIC_KEY SMS_PROVIDER_PRIVATE_KEY TELEGRAM_TOKEN TELEGRAM_CUSTOMER_BOT_TOKEN \
        WHATSAPP_PROVIDER_TOKEN PROVIDER_API_TOKEN PROVIDER_API_HMAC_SECRET \
        EMANAT_PROVIDER_API_TOKEN EMANAT_PROVIDER_HMAC_SECRET \
        UNITEDPAYMENT_PROVIDER_API_TOKEN UNITEDPAYMENT_PROVIDER_HMAC_SECRET PORTAL_TICKET_CLOSE_SECRET \
        RADIUS_SQL_PASSWORD RADIUS_SHARED_SECRET SMS_PROVIDER_USER SMS_PROVIDER_PASSWORD \
        TELEGRAM_BOT_TOKEN WHATSAPP_PROVIDER_API_KEY SMS_PROVIDER_API_KEY
    superoptic_secret_write_scoped_file communication \
        DB_PASS SMS_PROVIDER_PUBLIC_KEY SMS_PROVIDER_PRIVATE_KEY WHATSAPP_PROVIDER_TOKEN \
        TELEGRAM_TOKEN TELEGRAM_NETWORK_BOT_TOKEN TELEGRAM_CUSTOMER_BOT_TOKEN \
        TELEGRAM_FINANCE_BOT_TOKEN TELEGRAM_WAREHOUSE_BOT_TOKEN TELEGRAM_BACKUP_BOT_TOKEN \
        TELEGRAM_DIAGNOSTICS_BOT_TOKEN TELEGRAM_MODEM_SEARCH_BOT_TOKEN \
        SMS_PROVIDER_USER SMS_PROVIDER_PASSWORD TELEGRAM_BOT_TOKEN WHATSAPP_PROVIDER_API_KEY SMS_PROVIDER_API_KEY
    superoptic_secret_write_scoped_file pg365 \
        DB_PASS SMS_PROVIDER_PUBLIC_KEY SMS_PROVIDER_PRIVATE_KEY SMS_PROVIDER_USER SMS_PROVIDER_PASSWORD SMS_PROVIDER_API_KEY
    superoptic_secret_write_scoped_file radius \
        DB_PASS RADIUS_DB_PASS RADIUS_ROUTER_SECRET RADIUS_SSH_PASSWORD RADIUS_TEST_PASSWORD \
        RADIUS_SQL_PASSWORD RADIUS_SHARED_SECRET
    superoptic_secret_write_scoped_file modem \
        DB_PASS TELEGRAM_MODEM_SEARCH_BOT_TOKEN TELEGRAM_TOKEN TELEGRAM_BOT_TOKEN SNMP_COMMUNITY
    superoptic_secret_write_scoped_file optimizer DB_PASS RADIUS_DB_PASS CLICKHOUSE_PASSWORD
    superoptic_secret_write_scoped_file security DB_PASS CLICKHOUSE_PASSWORD TELEGRAM_DIAGNOSTICS_BOT_TOKEN
    superoptic_secret_write_scoped_file network DB_PASS SNMP_COMMUNITY TELEGRAM_NETWORK_BOT_TOKEN
    superoptic_secret_write_scoped_file finance DB_PASS TELEGRAM_FINANCE_BOT_TOKEN
    superoptic_secret_log "Root-only scoped service environments updated; values were not displayed"
}

superoptic_secret_restart_services() {
    [[ "${SUPEROPTIC_SECRET_RESTART_SERVICES}" == "1" ]] || return 0
    command -v systemctl >/dev/null 2>&1 || superoptic_secret_fail "systemctl is required by --restart-services"
    systemctl daemon-reload
    systemctl try-restart apache2.service
    if systemctl is-enabled superoptic-mgs-node.service >/dev/null 2>&1; then
        systemctl restart superoptic-mgs-node.service
    fi
    superoptic_secret_log "Affected long-running services restarted"
}

superoptic_secret_dry_run() {
    local key value present=0 generated=0 missing=0
    for key in "${SUPEROPTIC_SECRET_KEYS[@]}"; do
        value="${!key-}"
        if [[ -n "${value}" ]]; then
            superoptic_secret_validate_value "${key}" "${value}"
            ((present += 1))
        elif superoptic_secret_is_generated "${key}"; then
            ((generated += 1))
        else
            ((missing += 1))
        fi
    done
    superoptic_secret_log "[DRY-RUN] ${present} supplied secret(s) passed validation; ${generated} would be generated and ${missing} collected securely"
    superoptic_secret_log "[DRY-RUN] would atomically update ${SUPEROPTIC_SECRET_ENV_FILE} and scoped service files with root:root ownership and mode 0600"
}

superoptic_secrets_main() {
    SUPEROPTIC_SECRET_ENV_FILE="${SUPEROPTIC_SECRET_ENV_FILE:-/etc/superoptic-mgs/superoptic.env}"
    SUPEROPTIC_SECRET_GROUP="${SUPEROPTIC_SECRET_GROUP:-www-data}"
    SUPEROPTIC_SECRET_DRY_RUN=0
    SUPEROPTIC_SECRET_NONINTERACTIVE=0
    SUPEROPTIC_SECRET_ROTATE=0
    SUPEROPTIC_SECRET_VALIDATE_ONLY=0
    SUPEROPTIC_SECRET_RESTART_SERVICES=0
    SUPEROPTIC_SECRET_ALLOW_MISSING_EXTERNAL=0

    while (( $# > 0 )); do
        case "$1" in
            --env-file)
                (( $# >= 2 )) || superoptic_secret_fail "--env-file requires a path"
                SUPEROPTIC_SECRET_ENV_FILE="$2"
                shift 2
                ;;
            --group)
                (( $# >= 2 )) || superoptic_secret_fail "--group requires a name"
                SUPEROPTIC_SECRET_GROUP="$2"
                shift 2
                ;;
            --dry-run) SUPEROPTIC_SECRET_DRY_RUN=1; SUPEROPTIC_SECRET_NONINTERACTIVE=1; shift ;;
            --non-interactive) SUPEROPTIC_SECRET_NONINTERACTIVE=1; shift ;;
            --rotate) SUPEROPTIC_SECRET_ROTATE=1; shift ;;
            --validate-only) SUPEROPTIC_SECRET_VALIDATE_ONLY=1; SUPEROPTIC_SECRET_NONINTERACTIVE=1; shift ;;
            --restart-services) SUPEROPTIC_SECRET_RESTART_SERVICES=1; shift ;;
            --allow-missing-external) SUPEROPTIC_SECRET_ALLOW_MISSING_EXTERNAL=1; shift ;;
            --help|-h) superoptic_secret_usage; return 0 ;;
            *) superoptic_secret_fail "Unknown option: $1"; return 1 ;;
        esac
    done

    [[ "${SUPEROPTIC_SECRET_VALIDATE_ONLY}" != "1" || "${SUPEROPTIC_SECRET_ROTATE}" != "1" ]] || superoptic_secret_fail "--validate-only and --rotate cannot be combined"
    [[ "${SUPEROPTIC_SECRET_DRY_RUN}" != "1" || "${SUPEROPTIC_SECRET_VALIDATE_ONLY}" != "1" ]] || superoptic_secret_fail "--dry-run and --validate-only cannot be combined"
    superoptic_secret_require_root

    if [[ "${SUPEROPTIC_SECRET_DRY_RUN}" == "1" ]]; then
        superoptic_secret_dry_run
        return 0
    fi

    if [[ "${SUPEROPTIC_SECRET_VALIDATE_ONLY}" == "1" ]]; then
        superoptic_secret_load_file "${SUPEROPTIC_SECRET_ENV_FILE}"
        superoptic_secret_validate_all
        superoptic_secret_export_aliases
        superoptic_secret_log "Secret environment validation passed; values were not displayed"
        return 0
    fi

    if [[ -f "${SUPEROPTIC_SECRET_ENV_FILE}" && "${SUPEROPTIC_SECRET_ROTATE}" != "1" ]]; then
        superoptic_secret_load_file "${SUPEROPTIC_SECRET_ENV_FILE}"
        superoptic_secret_validate_all
        superoptic_secret_export_aliases
        superoptic_secret_write_service_files
        superoptic_secret_log "Existing secret environment retained; use --rotate to replace it"
        return 0
    fi

    if [[ -f "${SUPEROPTIC_SECRET_ENV_FILE}" && "${SUPEROPTIC_SECRET_ROTATE}" == "1" ]]; then
        local key metadata supplied_flag existing_master_key supplied_count=0
        local -A supplied_secrets=()
        local -A supplied_metadata=()
        for key in "${SUPEROPTIC_SECRET_KEYS[@]}"; do
            if [[ -n "${!key-}" ]]; then
                supplied_secrets["${key}"]="${!key}"
                ((supplied_count += 1))
            fi
        done
        for metadata in "${SUPEROPTIC_SECRET_METADATA_KEYS[@]}"; do
            supplied_flag="${metadata}_SUPPLIED"
            if [[ "${!supplied_flag-}" == "x" ]]; then
                supplied_metadata["${metadata}"]="${!metadata}"
                ((supplied_count += 1))
            fi
        done
        superoptic_secret_load_file "${SUPEROPTIC_SECRET_ENV_FILE}"
        (( supplied_count > 0 )) || superoptic_secret_fail "--rotate requires at least one explicitly supplied secret or metadata value; no values were changed"
        existing_master_key="${MGS_SECRETS_MASTER_KEY}"
        if [[ -n "${supplied_secrets[MGS_SECRETS_MASTER_KEY]+set}" ]] \
            && [[ "${supplied_secrets[MGS_SECRETS_MASTER_KEY]}" != "${existing_master_key}" ]]; then
            superoptic_secret_fail "MGS_SECRETS_MASTER_KEY cannot be changed by general rotation; re-encrypt stored device credentials with a versioned key migration first"
            return 1
        fi
        for key in "${SUPEROPTIC_SECRET_KEYS[@]}"; do
            if [[ "${key}" == "MGS_SECRETS_MASTER_KEY" ]]; then
                printf -v "${key}" '%s' "${existing_master_key}"
                export "${key}"
            elif [[ -n "${supplied_secrets[${key}]+set}" ]]; then
                printf -v "${key}" '%s' "${supplied_secrets[${key}]}"
                export "${key}"
            fi
        done
        for metadata in "${SUPEROPTIC_SECRET_METADATA_KEYS[@]}"; do
            if [[ -n "${supplied_metadata[${metadata}]+set}" ]]; then
                printf -v "${metadata}" '%s' "${supplied_metadata[${metadata}]}"
                export "${metadata}"
            fi
        done
    fi

    superoptic_secret_collect
    superoptic_secret_write_file
    superoptic_secret_write_service_files
    superoptic_secret_restart_services
}

trap 'if [[ -n "${SUPEROPTIC_SECRET_TEMP_FILE:-}" ]]; then rm -f -- "${SUPEROPTIC_SECRET_TEMP_FILE}"; fi; if [[ -n "${SUPEROPTIC_SECRET_SCOPED_TEMP_FILE:-}" ]]; then rm -f -- "${SUPEROPTIC_SECRET_SCOPED_TEMP_FILE}"; fi' EXIT

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    superoptic_secrets_main "$@"
fi
