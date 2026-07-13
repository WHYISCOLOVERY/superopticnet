#!/usr/bin/env bash
set -Eeuo pipefail

# Connect an existing Ubuntu FreeRADIUS 3.x server to SuperOptic MGS MySQL.
# This script does not install or replace the Windows system and does not change router config.

DB_HOST="${DB_HOST:-85.132.57.219}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-superoptic_portal}"
DB_USER="${DB_USER:-radius}"
DB_PASS="${DB_PASS:-}"
RADIUS_SECRET="${RADIUS_SECRET:-}"
CORE_NAS_IP="${CORE_NAS_IP:-85.132.57.218}"
CORE_CLIENT_NAME="${CORE_CLIENT_NAME:-sptech-asr1006-core}"
LAB_CLIENT_CIDR="${LAB_CLIENT_CIDR:-}"
INSTALL_PACKAGES="${INSTALL_PACKAGES:-1}"
CREATE_SCHEMA="${CREATE_SCHEMA:-1}"
CREATE_TEST_USERS="${CREATE_TEST_USERS:-0}"
FREERADIUS_DIR="${FREERADIUS_DIR:-/etc/freeradius/3.0}"

log() {
    printf '\n[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

require_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        fail "Run with sudo"
    fi
}

validate_identifier() {
    local value="$1"
    local label="$2"
    [[ "${value}" =~ ^[A-Za-z0-9_]+$ ]] || fail "${label} must contain only letters, numbers and underscore: ${value}"
}

sql_escape() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\'/\\\'}"
    printf "%s" "${value}"
}

backup_file() {
    local path="$1"
    if [[ -f "${path}" ]]; then
        cp -a "${path}" "${path}.bak.$(date +%Y%m%d%H%M%S)"
    fi
}

mysql_radius() {
    mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" "$@"
}

require_root
validate_identifier "${DB_NAME}" "DB_NAME"
validate_identifier "${DB_USER}" "DB_USER"
[[ -n "${DB_PASS}" ]] || fail "DB_PASS is required"
[[ -n "${RADIUS_SECRET}" ]] || fail "RADIUS_SECRET is required"
[[ -d "${FREERADIUS_DIR}" ]] || fail "FreeRADIUS directory not found: ${FREERADIUS_DIR}"

log "Installing missing packages if requested"
if [[ "${INSTALL_PACKAGES}" == "1" ]]; then
    export DEBIAN_FRONTEND=noninteractive
    sed -i.bak '/^[[:space:]]*deb cdrom:/ s/^/#/' /etc/apt/sources.list 2>/dev/null || true
    apt-get update
    apt-get install -y freeradius freeradius-mysql freeradius-utils mysql-client
else
    command -v mysql >/dev/null 2>&1 || fail "mysql-client is missing. Re-run with INSTALL_PACKAGES=1"
fi

log "Testing MySQL connectivity to ${DB_HOST}:${DB_PORT}/${DB_NAME}"
mysql_radius -e "SELECT 1 AS superoptic_radius_db_ok;" >/dev/null

if [[ "${CREATE_SCHEMA}" == "1" ]]; then
    log "Ensuring FreeRADIUS SQL tables exist"
    mysql_radius <<'SQL'
CREATE TABLE IF NOT EXISTS radcheck (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '==',
    value VARCHAR(253) NOT NULL DEFAULT '',
    KEY idx_radcheck_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radreply (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT '',
    KEY idx_radreply_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radusergroup (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    priority INT NOT NULL DEFAULT 1,
    UNIQUE KEY uq_radusergroup_user_group (username, groupname),
    KEY idx_radusergroup_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radgroupreply (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT '',
    KEY idx_radgroupreply_groupname (groupname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radgroupcheck (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '==',
    op CHAR(2) NOT NULL DEFAULT '==',
    value VARCHAR(253) NOT NULL DEFAULT '',
    KEY idx_radgroupcheck_groupname (groupname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radacct (
    radacctid BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    acctsessionid VARCHAR(64) NOT NULL DEFAULT '',
    acctuniqueid VARCHAR(64) NOT NULL DEFAULT '',
    username VARCHAR(64) NOT NULL DEFAULT '',
    realm VARCHAR(64) DEFAULT '',
    nasipaddress VARCHAR(45) NOT NULL DEFAULT '',
    nasportid VARCHAR(64) DEFAULT NULL,
    nasporttype VARCHAR(32) DEFAULT NULL,
    acctstarttime DATETIME DEFAULT NULL,
    acctupdatetime DATETIME DEFAULT NULL,
    acctstoptime DATETIME DEFAULT NULL,
    acctinterval INT UNSIGNED DEFAULT NULL,
    acctsessiontime INT UNSIGNED DEFAULT NULL,
    acctauthentic VARCHAR(32) DEFAULT NULL,
    connectinfo_start VARCHAR(50) DEFAULT NULL,
    connectinfo_stop VARCHAR(50) DEFAULT NULL,
    acctinputoctets BIGINT DEFAULT NULL,
    acctoutputoctets BIGINT DEFAULT NULL,
    calledstationid VARCHAR(50) NOT NULL DEFAULT '',
    callingstationid VARCHAR(50) NOT NULL DEFAULT '',
    acctterminatecause VARCHAR(32) NOT NULL DEFAULT '',
    servicetype VARCHAR(32) DEFAULT NULL,
    framedprotocol VARCHAR(32) DEFAULT NULL,
    framedipaddress VARCHAR(45) NOT NULL DEFAULT '',
    KEY idx_radacct_username (username),
    KEY idx_radacct_session (acctsessionid),
    KEY idx_radacct_unique (acctuniqueid),
    KEY idx_radacct_start_user (acctstarttime, username),
    KEY idx_radacct_nas_start (nasipaddress, acctstarttime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radpostauth (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    pass VARCHAR(64) NOT NULL DEFAULT '',
    reply VARCHAR(32) NOT NULL DEFAULT '',
    authdate TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_radpostauth_username (username),
    KEY idx_radpostauth_authdate (authdate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS nas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nasname VARCHAR(128) NOT NULL,
    shortname VARCHAR(32) DEFAULT NULL,
    type VARCHAR(30) DEFAULT 'other',
    ports INT DEFAULT NULL,
    secret VARCHAR(60) NOT NULL DEFAULT 'secret',
    server VARCHAR(64) DEFAULT NULL,
    community VARCHAR(50) DEFAULT NULL,
    description VARCHAR(200) DEFAULT NULL,
    UNIQUE KEY uq_nasname (nasname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
fi

if [[ "${CREATE_TEST_USERS}" == "1" ]]; then
    log "Creating optional test RADIUS users"
    mysql_radius <<'SQL'
DELETE FROM radcheck WHERE username IN ('1100000', '1200000');
DELETE FROM radreply WHERE username IN ('1100000', '1200000');
DELETE FROM radusergroup WHERE username IN ('1100000', '1200000');

INSERT INTO radcheck (username, attribute, op, value)
VALUES ('1100000', 'Cleartext-Password', ':=', 'UCuvsbLA2b');

INSERT INTO radreply (username, attribute, op, value)
VALUES
('1100000', 'Service-Type', '=', 'Framed-User'),
('1100000', 'Framed-Protocol', '=', 'PPP'),
('1100000', 'Framed-IP-Address', '=', '10.11.20.10'),
('1100000', 'Framed-IP-Netmask', '=', '255.255.255.0'),
('1200000', 'Service-Type', '=', 'Framed-User'),
('1200000', 'Framed-IP-Address', '=', '10.110.0.10'),
('1200000', 'Framed-IP-Netmask', '=', '255.255.255.0');

INSERT INTO radcheck (username, attribute, op, value)
VALUES ('1200000', 'Auth-Type', ':=', 'Accept');
SQL
fi

log "Writing FreeRADIUS SQL module"
SQL_FILE="${FREERADIUS_DIR}/mods-available/sql"
backup_file "${SQL_FILE}"
DB_PASS_ESCAPED="${DB_PASS//\\/\\\\}"
DB_PASS_ESCAPED="${DB_PASS_ESCAPED//\"/\\\"}"
cat >"${SQL_FILE}" <<CONF
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    server = "${DB_HOST}"
    port = ${DB_PORT}
    login = "${DB_USER}"
    password = "${DB_PASS_ESCAPED}"
    radius_db = "${DB_NAME}"

    acct_table1 = "radacct"
    acct_table2 = "radacct"
    postauth_table = "radpostauth"
    authcheck_table = "radcheck"
    authreply_table = "radreply"
    groupcheck_table = "radgroupcheck"
    groupreply_table = "radgroupreply"
    usergroup_table = "radusergroup"
    group_attribute = "SQL-Group"

    read_clients = no
    client_table = "nas"
    delete_stale_sessions = yes

    pool {
        start = 5
        min = 3
        max = 32
        spare = 10
        uses = 0
        retry_delay = 30
        lifetime = 0
        idle_timeout = 60
    }

    \$INCLUDE \${modconfdir}/\${.:name}/main/\${dialect}/queries.conf
}
CONF
ln -sfn "${SQL_FILE}" "${FREERADIUS_DIR}/mods-enabled/sql"

log "Enabling sql sections in default and inner-tunnel"
for site in "${FREERADIUS_DIR}/sites-enabled/default" "${FREERADIUS_DIR}/sites-enabled/inner-tunnel"; do
    [[ -f "${site}" ]] || continue
    backup_file "${site}"
    sed -i 's/^[[:space:]]*#[[:space:]]*sql[[:space:]]*$/        sql/g' "${site}"
done

log "Adding core NAS client without touching router"
CLIENTS_FILE="${FREERADIUS_DIR}/clients.conf"
backup_file "${CLIENTS_FILE}"
tmp_clients="$(mktemp)"
awk '/# BEGIN SUPEROPTIC CORE CLIENT/{skip=1} /# END SUPEROPTIC CORE CLIENT/{skip=0; next} !skip{print}' "${CLIENTS_FILE}" >"${tmp_clients}"
cat >>"${tmp_clients}" <<CONF

# BEGIN SUPEROPTIC CORE CLIENT
client ${CORE_CLIENT_NAME} {
    ipaddr = ${CORE_NAS_IP}
    secret = ${RADIUS_SECRET}
    nas_type = cisco
    require_message_authenticator = auto
}
CONF
if [[ -n "${LAB_CLIENT_CIDR}" ]]; then
cat >>"${tmp_clients}" <<CONF

client superoptic-lab {
    ipaddr = ${LAB_CLIENT_CIDR}
    secret = ${RADIUS_SECRET}
    nas_type = other
    require_message_authenticator = auto
}
CONF
fi
cat >>"${tmp_clients}" <<'CONF'
# END SUPEROPTIC CORE CLIENT
CONF
cat "${tmp_clients}" >"${CLIENTS_FILE}"
rm -f "${tmp_clients}"

log "Validating FreeRADIUS config"
freeradius -C

log "Restarting FreeRADIUS"
systemctl enable freeradius
systemctl restart freeradius
sleep 2

log "Listening ports"
ss -lunp | grep -E '1812|1813|3799' || true

if [[ "${CREATE_TEST_USERS}" == "1" ]]; then
    log "Running local auth tests"
    radtest 1100000 UCuvsbLA2b 127.0.0.1 0 "${RADIUS_SECRET}"
    radtest 1200000 any-test-password 127.0.0.1 0 "${RADIUS_SECRET}"
else
    log "Test users skipped. To test later: CREATE_TEST_USERS=1 sudo -E bash ubuntu-connect-existing-freeradius.sh"
fi

log "DONE: existing Ubuntu FreeRADIUS is connected to SuperOptic DB"
