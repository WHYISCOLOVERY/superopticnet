#!/usr/bin/env bash
set -Eeuo pipefail

# SuperOptic FreeRADIUS + MySQL bootstrap for Ubuntu.
# Default mode installs local MySQL on the Ubuntu VM and creates two test users.
# For portal DB mode, pass DB_HOST/DB_PASS and set INSTALL_MYSQL_SERVER=0.

RADIUS_SECRET="${RADIUS_SECRET:-menim_gizli_acarim123}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-superoptic_portal}"
DB_USER="${DB_USER:-radius}"
DB_PASS="${DB_PASS:-radius_lab_ChangeMe_123}"
DB_ADMIN_USER="${DB_ADMIN_USER:-root}"
DB_ADMIN_PASS="${DB_ADMIN_PASS:-}"
INSTALL_MYSQL_SERVER="${INSTALL_MYSQL_SERVER:-1}"
CORE_NAS_IP="${CORE_NAS_IP:-}"
LAB_CLIENT_CIDR="${LAB_CLIENT_CIDR:-192.168.80.0/24}"
CREATE_TEST_USERS="${CREATE_TEST_USERS:-1}"

log() {
    printf '\n[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

fail() {
    printf '\nERROR: %s\n' "$*" >&2
    exit 1
}

require_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        fail "Run with sudo: sudo bash deploy/setup-ubuntu-freeradius.sh"
    fi
}

validate_name() {
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

mysql_admin_args() {
    if [[ "${DB_HOST}" == "127.0.0.1" || "${DB_HOST}" == "localhost" ]]; then
        if [[ -n "${DB_ADMIN_PASS}" ]]; then
            printf -- "-h%s -P%s -u%s -p%s" "${DB_HOST}" "${DB_PORT}" "${DB_ADMIN_USER}" "${DB_ADMIN_PASS}"
        else
            printf -- "--protocol=socket -u%s" "${DB_ADMIN_USER}"
        fi
    else
        [[ -n "${DB_ADMIN_PASS}" ]] || return 1
        printf -- "-h%s -P%s -u%s -p%s" "${DB_HOST}" "${DB_PORT}" "${DB_ADMIN_USER}" "${DB_ADMIN_PASS}"
    fi
}

mysql_radius_args() {
    printf -- "-h%s -P%s -u%s -p%s %s" "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASS}" "${DB_NAME}"
}

install_packages() {
    log "Installing FreeRADIUS and MySQL packages"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    if [[ "${INSTALL_MYSQL_SERVER}" == "1" ]]; then
        apt-get install -y mysql-server mysql-client freeradius freeradius-mysql freeradius-utils
        systemctl enable --now mysql || systemctl enable --now mariadb || true
    else
        apt-get install -y mysql-client freeradius freeradius-mysql freeradius-utils
    fi
}

create_database_and_grants() {
    validate_name "${DB_NAME}" "DB_NAME"
    validate_name "${DB_USER}" "DB_USER"

    local db_pass_escaped
    db_pass_escaped="$(sql_escape "${DB_PASS}")"

    log "Creating database, Radius user and grants if admin access is available"
    if admin_args="$(mysql_admin_args)"; then
        # shellcheck disable=SC2086
        mysql ${admin_args} <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${db_pass_escaped}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${db_pass_escaped}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${db_pass_escaped}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
    else
        log "DB admin password not provided for remote DB. Skipping CREATE USER/GRANT and using existing ${DB_USER}."
    fi
}

create_schema_and_test_users() {
    log "Creating FreeRADIUS SQL tables and test users"

    # shellcheck disable=SC2086
    mysql $(mysql_radius_args) <<'SQL'
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
    attribute VARCHAR(64) NOT NULL DEFAULT '',
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
    nasipaddress VARCHAR(15) NOT NULL DEFAULT '',
    nasportid VARCHAR(32) DEFAULT NULL,
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
    framedipaddress VARCHAR(15) NOT NULL DEFAULT '',
    KEY idx_radacct_username (username),
    KEY idx_radacct_acctsessionid (acctsessionid),
    KEY idx_radacct_acctuniqueid (acctuniqueid),
    KEY idx_radacct_start_user (acctstarttime, username)
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

    if [[ "${CREATE_TEST_USERS}" == "1" ]]; then
        # shellcheck disable=SC2086
        mysql $(mysql_radius_args) <<'SQL'
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
('1100000', 'Framed-IP-Netmask', '=', '255.255.255.0');
INSERT INTO radusergroup (username, groupname, priority)
VALUES ('1100000', 'tariff-200m', 1)
ON DUPLICATE KEY UPDATE priority = VALUES(priority);

INSERT INTO radcheck (username, attribute, op, value)
VALUES ('1200000', 'Auth-Type', ':=', 'Accept');
INSERT INTO radreply (username, attribute, op, value)
VALUES
('1200000', 'Service-Type', '=', 'Framed-User'),
('1200000', 'Framed-IP-Address', '=', '10.110.0.10'),
('1200000', 'Framed-IP-Netmask', '=', '255.255.255.0');
INSERT INTO radusergroup (username, groupname, priority)
VALUES ('1200000', 'tariff-ipoe-100m', 1)
ON DUPLICATE KEY UPDATE priority = VALUES(priority);
SQL
    fi
}

configure_sql_module() {
    log "Configuring FreeRADIUS SQL module"
    local sql_file="/etc/freeradius/3.0/mods-available/sql"
    local backup="${sql_file}.bak.$(date +%Y%m%d%H%M%S)"
    [[ -f "${sql_file}" ]] && cp "${sql_file}" "${backup}"

    cat > "${sql_file}" <<CONF
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"

    server = "${DB_HOST}"
    port = ${DB_PORT}
    login = "${DB_USER}"
    password = "${DB_PASS}"
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

    ln -sfn /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/sql

    for site in /etc/freeradius/3.0/sites-enabled/default /etc/freeradius/3.0/sites-enabled/inner-tunnel; do
        [[ -f "${site}" ]] || continue
        cp "${site}" "${site}.bak.$(date +%Y%m%d%H%M%S)"
        sed -i 's/^[[:space:]]*#[[:space:]]*sql[[:space:]]*$/        sql/g' "${site}"
    done
}

configure_clients() {
    log "Configuring FreeRADIUS clients"
    local clients_file="/etc/freeradius/3.0/clients.conf"
    cp "${clients_file}" "${clients_file}.bak.$(date +%Y%m%d%H%M%S)"

    # Make the default localhost client match radtest secret.
    sed -i "/client localhost[[:space:]]*{/,/}/ s/^[[:space:]]*secret[[:space:]]*=.*/    secret = ${RADIUS_SECRET}/" "${clients_file}"
    sed -i "/client localhost_ipv6[[:space:]]*{/,/}/ s/^[[:space:]]*secret[[:space:]]*=.*/    secret = ${RADIUS_SECRET}/" "${clients_file}"

    # Remove previous managed block and append a fresh one.
    sed -i '/# BEGIN SUPEROPTIC MANAGED CLIENTS/,/# END SUPEROPTIC MANAGED CLIENTS/d' "${clients_file}"

    {
        echo ""
        echo "# BEGIN SUPEROPTIC MANAGED CLIENTS"
        if [[ -n "${CORE_NAS_IP}" ]]; then
            cat <<CONF
client sptech-core {
    ipaddr = ${CORE_NAS_IP}
    secret = ${RADIUS_SECRET}
    nas_type = cisco
}
CONF
        fi
        if [[ -n "${LAB_CLIENT_CIDR}" ]]; then
            cat <<CONF
client superoptic-lab-range {
    ipaddr = ${LAB_CLIENT_CIDR}
    secret = ${RADIUS_SECRET}
    nas_type = other
}
CONF
        fi
        echo "# END SUPEROPTIC MANAGED CLIENTS"
    } >> "${clients_file}"
}

test_mysql() {
    log "Testing MySQL Radius rows"
    # shellcheck disable=SC2086
    mysql $(mysql_radius_args) -e "SELECT username, attribute, op, value FROM radcheck WHERE username IN ('1100000','1200000') ORDER BY username, id;"
}

restart_and_test_radius() {
    log "Validating FreeRADIUS configuration"
    freeradius -C
    systemctl enable freeradius
    systemctl restart freeradius
    sleep 2
    systemctl --no-pager --full status freeradius || true
    ss -lunp | grep -E '1812|1813' || true

    log "Testing radtest PPPoE user 1100000"
    if ! radtest 1100000 UCuvsbLA2b 127.0.0.1 0 "${RADIUS_SECRET}" | tee /tmp/superoptic-radtest-1100000.log | grep -q "Access-Accept"; then
        journalctl -u freeradius --no-pager -n 80 || true
        fail "PPPoE radtest did not return Access-Accept. Run: sudo systemctl stop freeradius && sudo freeradius -X"
    fi

    log "Testing radtest IPoE user 1200000"
    if ! radtest 1200000 any-test-password 127.0.0.1 0 "${RADIUS_SECRET}" | tee /tmp/superoptic-radtest-1200000.log | grep -q "Access-Accept"; then
        journalctl -u freeradius --no-pager -n 80 || true
        fail "IPoE radtest did not return Access-Accept. Run: sudo systemctl stop freeradius && sudo freeradius -X"
    fi
}

main() {
    require_root
    validate_name "${DB_NAME}" "DB_NAME"
    validate_name "${DB_USER}" "DB_USER"

    log "SuperOptic FreeRADIUS setup started"
    log "DB_HOST=${DB_HOST} DB_NAME=${DB_NAME} DB_USER=${DB_USER} INSTALL_MYSQL_SERVER=${INSTALL_MYSQL_SERVER}"

    install_packages
    create_database_and_grants
    create_schema_and_test_users
    configure_sql_module
    configure_clients
    test_mysql
    restart_and_test_radius

    log "Setup completed successfully"
    cat <<TEXT

Ready tests:
  radtest 1100000 UCuvsbLA2b 127.0.0.1 0 ${RADIUS_SECRET}
  radtest 1200000 any-test-password 127.0.0.1 0 ${RADIUS_SECRET}

Router NAS secret:
  ${RADIUS_SECRET}

If you use the production portal DB later:
  sudo INSTALL_MYSQL_SERVER=0 DB_HOST=192.168.80.1 DB_PASS='REAL_RADIUS_DB_PASS' bash setup-ubuntu-freeradius.sh
TEXT
}

main "$@"
