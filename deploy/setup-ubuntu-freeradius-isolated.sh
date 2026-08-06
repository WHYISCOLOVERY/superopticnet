#!/usr/bin/env bash
set -Eeuo pipefail
set +x

# Staging-only FreeRADIUS bootstrap for the unified SuperOptic portal.
# It uses the canonical portal DB, listens on loopback/non-standard ports,
# creates no NAS client, and removes its local smoke user after validation.

RADIUS_ENV_FILE="${RADIUS_ENV_FILE:-/etc/superoptic-mgs/services/radius.env}"
ROOT_ENV_FILE="${ROOT_ENV_FILE:-/etc/superoptic-mgs/superoptic.env}"
PORTAL_CONFIG_PATH="${PORTAL_CONFIG_PATH:-/var/www/superopticnet/config.local.php}"
DB_NAME="${DB_NAME:-superoptic_portal}"
RADIUS_DB_USER="${RADIUS_DB_USER:-radius}"
RADIUS_DB_PASS="${RADIUS_DB_PASS:-}"
RADIUS_SHARED_SECRET="${RADIUS_SHARED_SECRET:-${RADIUS_ROUTER_SECRET:-}}"
RADIUS_LISTEN_IP="${RADIUS_LISTEN_IP:-127.0.0.1}"
RADIUS_AUTH_PORT="${RADIUS_AUTH_PORT:-11812}"
RADIUS_ACCT_PORT="${RADIUS_ACCT_PORT:-11813}"
ROTATE_RADIUS_SECRETS="${ROTATE_RADIUS_SECRETS:-0}"

log() {
    printf '[freeradius-isolated] %s\n' "$*"
}

fail() {
    printf '[freeradius-isolated] ERROR: %s\n' "$*" >&2
    exit 1
}

require_root() {
    [[ "${EUID}" -eq 0 ]] || fail "Run as root."
}

validate_identifier() {
    [[ "$1" =~ ^[A-Za-z0-9_]+$ ]] || fail "Invalid SQL identifier."
}

validate_port() {
    [[ "$1" =~ ^[0-9]+$ ]] && (( "$1" >= 1024 && "$1" <= 65535 )) \
        || fail "Invalid isolated RADIUS port: $1"
}

secret_is_usable() {
    local value="${1:-}"
    [[ "${#value}" -ge 32 ]] || return 1
    [[ "${value^^}" != *"CHANGE_ME"* ]] || return 1
    [[ "${value^^}" != *"EXAMPLE"* ]] || return 1
    [[ "${value^^}" != *"YOUR_"* ]] || return 1
}

sql_escape() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\'/\\\'}"
    printf '%s' "${value}"
}

upsert_env_key() {
    local path="$1"
    local key="$2"
    local value="$3"
    local temporary
    temporary="$(mktemp)"
    if [[ -f "${path}" ]]; then
        awk -F= -v key="${key}" '$1 != key { print }' "${path}" > "${temporary}"
    fi
    printf '%s=%s\n' "${key}" "${value}" >> "${temporary}"
    install -o root -g root -m 0600 "${temporary}" "${path}"
    rm -f "${temporary}"
}

load_service_environment() {
    if [[ -f "${RADIUS_ENV_FILE}" ]]; then
        set -a
        # shellcheck disable=SC1090
        source "${RADIUS_ENV_FILE}"
        set +a
    fi
    RADIUS_DB_PASS="${RADIUS_DB_PASS:-${RADIUS_SQL_PASSWORD:-}}"
    RADIUS_SHARED_SECRET="${RADIUS_SHARED_SECRET:-${RADIUS_ROUTER_SECRET:-}}"
}

prepare_secrets() {
    if [[ "${ROTATE_RADIUS_SECRETS}" == "1" ]] || ! secret_is_usable "${RADIUS_DB_PASS}"; then
        RADIUS_DB_PASS="$(openssl rand -base64 48 | tr -d '\n')"
    fi
    if [[ "${ROTATE_RADIUS_SECRETS}" == "1" ]] || ! secret_is_usable "${RADIUS_SHARED_SECRET}"; then
        RADIUS_SHARED_SECRET="$(openssl rand -base64 48 | tr -d '\n')"
    fi

    local path
    for path in "${RADIUS_ENV_FILE}" "${ROOT_ENV_FILE}"; do
        mkdir -p "$(dirname "${path}")"
        upsert_env_key "${path}" "RADIUS_DB_PASS" "${RADIUS_DB_PASS}"
        upsert_env_key "${path}" "RADIUS_SQL_PASSWORD" "${RADIUS_DB_PASS}"
        upsert_env_key "${path}" "RADIUS_SHARED_SECRET" "${RADIUS_SHARED_SECRET}"
        upsert_env_key "${path}" "RADIUS_ROUTER_SECRET" "${RADIUS_SHARED_SECRET}"
    done
}

install_packages() {
    export DEBIAN_FRONTEND=noninteractive
    systemctl mask freeradius.service >/dev/null 2>&1 || true
    apt-get install -y freeradius freeradius-mysql freeradius-utils >/dev/null
}

configure_database_access() {
    local escaped_password
    escaped_password="$(sql_escape "${RADIUS_DB_PASS}")"
    mysql --protocol=socket <<SQL
CREATE USER IF NOT EXISTS '${RADIUS_DB_USER}'@'localhost' IDENTIFIED BY '${escaped_password}';
CREATE USER IF NOT EXISTS '${RADIUS_DB_USER}'@'127.0.0.1' IDENTIFIED BY '${escaped_password}';
ALTER USER '${RADIUS_DB_USER}'@'localhost' IDENTIFIED BY '${escaped_password}';
ALTER USER '${RADIUS_DB_USER}'@'127.0.0.1' IDENTIFIED BY '${escaped_password}';
DROP USER IF EXISTS '${RADIUS_DB_USER}'@'%';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${RADIUS_DB_USER}'@'localhost';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${RADIUS_DB_USER}'@'127.0.0.1';
GRANT SELECT ON \`${DB_NAME}\`.radcheck TO '${RADIUS_DB_USER}'@'localhost';
GRANT SELECT ON \`${DB_NAME}\`.radcheck TO '${RADIUS_DB_USER}'@'127.0.0.1';
GRANT SELECT ON \`${DB_NAME}\`.radreply TO '${RADIUS_DB_USER}'@'localhost';
GRANT SELECT ON \`${DB_NAME}\`.radreply TO '${RADIUS_DB_USER}'@'127.0.0.1';
GRANT SELECT ON \`${DB_NAME}\`.radusergroup TO '${RADIUS_DB_USER}'@'localhost';
GRANT SELECT ON \`${DB_NAME}\`.radusergroup TO '${RADIUS_DB_USER}'@'127.0.0.1';
GRANT SELECT ON \`${DB_NAME}\`.radgroupcheck TO '${RADIUS_DB_USER}'@'localhost';
GRANT SELECT ON \`${DB_NAME}\`.radgroupcheck TO '${RADIUS_DB_USER}'@'127.0.0.1';
GRANT SELECT ON \`${DB_NAME}\`.radgroupreply TO '${RADIUS_DB_USER}'@'localhost';
GRANT SELECT ON \`${DB_NAME}\`.radgroupreply TO '${RADIUS_DB_USER}'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.radacct TO '${RADIUS_DB_USER}'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.radacct TO '${RADIUS_DB_USER}'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.radpostauth TO '${RADIUS_DB_USER}'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.radpostauth TO '${RADIUS_DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
}

configure_sql_module() {
    local sql_file="/etc/freeradius/3.0/mods-available/sql"
    [[ -f "${sql_file}.superoptic-original" ]] || cp "${sql_file}" "${sql_file}.superoptic-original"
    cat > "${sql_file}" <<CONF
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    server = "127.0.0.1"
    port = 3306
    login = "${RADIUS_DB_USER}"
    password = "${RADIUS_DB_PASS}"
    radius_db = "${DB_NAME}"
    acct_table1 = "radacct"
    acct_table2 = "radacct"
    postauth_table = "radpostauth"
    authcheck_table = "radcheck"
    groupcheck_table = "radgroupcheck"
    authreply_table = "radreply"
    groupreply_table = "radgroupreply"
    usergroup_table = "radusergroup"
    group_attribute = "SQL-Group"
    read_clients = no
    client_table = "nas"
    delete_stale_sessions = yes
    pool {
        start = 2
        min = 1
        max = 8
        spare = 2
        uses = 0
        retry_delay = 30
        lifetime = 0
        idle_timeout = 60
    }
    \$INCLUDE \${modconfdir}/\${.:name}/main/\${dialect}/queries.conf
}
CONF
    chown root:freerad "${sql_file}"
    chmod 0640 "${sql_file}"
    ln -sfn ../mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
}

configure_local_client() {
    local clients="/etc/freeradius/3.0/clients.conf"
    [[ -f "${clients}.superoptic-original" ]] || cp "${clients}" "${clients}.superoptic-original"
    cat > "${clients}" <<CONF
client superoptic-local-smoke {
    ipaddr = 127.0.0.1
    secret = ${RADIUS_SHARED_SECRET}
    nas_type = other
}

client superoptic-local-smoke-v6 {
    ipv6addr = ::1
    secret = ${RADIUS_SHARED_SECRET}
    nas_type = other
}
CONF
    chown root:freerad "${clients}"
    chmod 0640 "${clients}"
}

configure_loopback_site() {
    local radiusd="/etc/freeradius/3.0/radiusd.conf"
    local default_site="/etc/freeradius/3.0/sites-available/default"
    local isolated_site="/etc/freeradius/3.0/sites-available/superoptic-isolated"
    [[ -f "${radiusd}.superoptic-original" ]] || cp "${radiusd}" "${radiusd}.superoptic-original"
    cp "${radiusd}.superoptic-original" "${radiusd}"
    sed -i 's/^[[:space:]]*proxy_requests[[:space:]]*=.*/proxy_requests = no/' "${radiusd}"
    [[ -f "${default_site}.superoptic-original" ]] || cp "${default_site}" "${default_site}.superoptic-original"
    cp "${default_site}.superoptic-original" "${isolated_site}"

    python3 - "${isolated_site}" "${RADIUS_AUTH_PORT}" "${RADIUS_ACCT_PORT}" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
auth_port = sys.argv[2]
acct_port = sys.argv[3]
lines = path.read_text(encoding="utf-8").splitlines()
output = []
i = 0
while i < len(lines):
    if re.match(r"^\s*listen\s*\{\s*$", lines[i]):
        block = [lines[i]]
        depth = lines[i].count("{") - lines[i].count("}")
        i += 1
        while i < len(lines) and depth > 0:
            block.append(lines[i])
            depth += lines[i].count("{") - lines[i].count("}")
            i += 1
        block_text = "\n".join(block)
        listener_type = "auth" if re.search(r"^\s*type\s*=\s*auth\b", block_text, re.M) else (
            "acct" if re.search(r"^\s*type\s*=\s*acct\b", block_text, re.M) else ""
        )
        port = auth_port if listener_type == "auth" else acct_port
        rewritten = []
        for line in block:
            line = re.sub(r"^(\s*)ipaddr\s*=\s*\*\s*(#.*)?$", r"\1ipaddr = 127.0.0.1", line)
            line = re.sub(r"^(\s*)ipv6addr\s*=\s*::\s*(#.*)?$", r"\1ipv6addr = ::1", line)
            if listener_type:
                line = re.sub(r"^(\s*)port\s*=\s*0\s*(#.*)?$", rf"\1port = {port}", line)
            rewritten.append(line)
        output.extend(rewritten)
        continue
    output.append(lines[i])
    i += 1

text = "\n".join(output) + "\n"
text = re.sub(r"^(\s*)#\s*sql\s*$", r"\1sql", text, flags=re.M)
path.write_text(text, encoding="utf-8")
PY

    local inner="/etc/freeradius/3.0/sites-available/inner-tunnel"
    [[ -f "${inner}.superoptic-original" ]] || cp "${inner}" "${inner}.superoptic-original"
    cp "${inner}.superoptic-original" "${inner}"
    sed -i 's/^\([[:space:]]*\)#[[:space:]]*sql[[:space:]]*$/\1sql/' "${inner}"

    rm -f /etc/freeradius/3.0/sites-enabled/default
    ln -sfn ../sites-available/superoptic-isolated /etc/freeradius/3.0/sites-enabled/superoptic-isolated
    chown root:freerad "${radiusd}" "${isolated_site}" "${inner}"
    chmod 0640 "${radiusd}" "${isolated_site}" "${inner}"
}

update_portal_radius_endpoint() {
    [[ -f "${PORTAL_CONFIG_PATH}" ]] || return
    python3 - "${PORTAL_CONFIG_PATH}" "${RADIUS_LISTEN_IP}" "${RADIUS_AUTH_PORT}" "${RADIUS_ACCT_PORT}" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
values = {
    "radius_server_ip": sys.argv[2],
    "radius_auth_port": sys.argv[3],
    "radius_acct_port": sys.argv[4],
}
text = path.read_text(encoding="utf-8")
for key, value in values.items():
    pattern = rf"('{re.escape(key)}'\s*=>\s*)'[^']*'"
    if re.search(pattern, text):
        text = re.sub(pattern, rf"\g<1>'{value}'", text)
    else:
        text = re.sub(r"\n\];\s*$", f"\n    '{key}' => '{value}',\n];\n", text)
path.write_text(text, encoding="utf-8")
PY
    chown root:www-data "${PORTAL_CONFIG_PATH}"
    chmod 0640 "${PORTAL_CONFIG_PATH}"
}

validate_and_start() {
    freeradius -C >/tmp/superoptic-freeradius-config-check.log 2>&1 \
        || fail "FreeRADIUS configuration validation failed."
    systemctl unmask freeradius.service >/dev/null
    systemctl enable freeradius.service >/dev/null
    systemctl restart freeradius.service
    systemctl is-active --quiet freeradius.service || fail "FreeRADIUS service is not active."

    ss -H -lun | awk '{print $4}' | grep -Eq "127\.0\.0\.1:${RADIUS_AUTH_PORT}$" \
        || fail "Loopback authentication listener is missing."
    ss -H -lun | awk '{print $4}' | grep -Eq "127\.0\.0\.1:${RADIUS_ACCT_PORT}$" \
        || fail "Loopback accounting listener is missing."
    if ss -H -lun | awk '{print $4}' | grep -Eq '(^|:)1812$|(^|:)1813$'; then
        fail "Standard RADIUS ports must remain closed during isolated staging."
    fi
    if ss -H -lunp | awk '$0 ~ /freeradius/ {print $4}' \
        | grep -Ev '^(127\.0\.0\.1:|\[::1\]:)' | grep -q .; then
        fail "FreeRADIUS has a non-loopback UDP listener."
    fi
}

run_local_smoke() {
    local username="SOT-RADIUS-SMOKE-$(date +%s)"
    local password
    local escaped_password
    local output="/tmp/superoptic-radius-smoke.log"
    password="$(openssl rand -base64 24 | tr -d '\n')"
    escaped_password="$(sql_escape "${password}")"

    cleanup_smoke() {
        mysql --protocol=socket "${DB_NAME}" <<SQL >/dev/null 2>&1 || true
DELETE FROM radcheck WHERE username = '${username}';
DELETE FROM radreply WHERE username = '${username}';
DELETE FROM radusergroup WHERE username = '${username}';
DELETE FROM radpostauth WHERE username = '${username}';
SQL
        rm -f "${output}"
    }
    trap cleanup_smoke EXIT

    mysql --protocol=socket "${DB_NAME}" <<SQL
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('${username}', 'Cleartext-Password', ':=', '${escaped_password}');
INSERT INTO radreply (username, attribute, op, value)
VALUES ('${username}', 'Service-Type', '=', 'Framed-User');
SQL

    radtest "${username}" "${password}" "127.0.0.1:${RADIUS_AUTH_PORT}" 0 "${RADIUS_SHARED_SECRET}" \
        >"${output}" 2>&1 || true
    grep -q 'Access-Accept' "${output}" || fail "Local RADIUS authentication smoke failed."
    cleanup_smoke
    trap - EXIT
}

write_marker() {
    cat > /etc/superoptic-mgs/freeradius-isolated.conf <<CONF
mode=isolated
listen_ip=${RADIUS_LISTEN_IP}
auth_port=${RADIUS_AUTH_PORT}
acct_port=${RADIUS_ACCT_PORT}
database=${DB_NAME}
database_user=${RADIUS_DB_USER}
router_clients=0
smoke_data_retained=0
CONF
    chown root:root /etc/superoptic-mgs/freeradius-isolated.conf
    chmod 0644 /etc/superoptic-mgs/freeradius-isolated.conf
}

main() {
    require_root
    validate_identifier "${DB_NAME}"
    validate_identifier "${RADIUS_DB_USER}"
    [[ "${RADIUS_LISTEN_IP}" == "127.0.0.1" ]] || fail "Isolated mode only permits 127.0.0.1."
    validate_port "${RADIUS_AUTH_PORT}"
    validate_port "${RADIUS_ACCT_PORT}"
    [[ "${RADIUS_AUTH_PORT}" != "${RADIUS_ACCT_PORT}" ]] || fail "Auth and accounting ports must differ."

    load_service_environment
    prepare_secrets
    install_packages
    configure_database_access
    configure_sql_module
    configure_local_client
    configure_loopback_site
    update_portal_radius_endpoint
    validate_and_start
    run_local_smoke
    write_marker
    log "Ready: loopback ${RADIUS_AUTH_PORT}/${RADIUS_ACCT_PORT}; no router/NAS client configured."
}

main "$@"
