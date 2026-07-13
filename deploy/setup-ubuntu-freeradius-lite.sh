#!/usr/bin/env bash
set -Eeuo pipefail

RADIUS_SECRET="${RADIUS_SECRET:-menim_gizli_acarim123}"
DB_NAME="${DB_NAME:-superoptic_portal}"
DB_USER="${DB_USER:-radius}"
DB_PASS="${DB_PASS:-radius_lab_ChangeMe_123}"
SKIP_PACKAGES="${SKIP_PACKAGES:-0}"
MIN_FREE_MB="${MIN_FREE_MB:-1200}"

[[ "${EUID}" -eq 0 ]] || { echo "Run with sudo"; exit 1; }

echo "[1/7] Packages"
FREE_MB=$(df -Pm / | awk 'NR==2 {print $4}')
if [ "${SKIP_PACKAGES}" != "1" ] && [ "${FREE_MB:-0}" -lt "${MIN_FREE_MB}" ]; then
  echo "ERROR: Root filesystem has only ${FREE_MB} MB free. Need at least ${MIN_FREE_MB} MB." >&2
  echo "Run repair script first or increase Ubuntu VM disk." >&2
  exit 20
fi
if [ "${SKIP_PACKAGES}" = "1" ]; then
  echo "Skipping apt package install because SKIP_PACKAGES=1"
else
  export DEBIAN_FRONTEND=noninteractive
  sed -i.bak '/^[[:space:]]*deb cdrom:/ s/^/#/' /etc/apt/sources.list 2>/dev/null || true
  apt-get clean
  apt-get update
  apt-get install -y mysql-server mysql-client freeradius freeradius-mysql freeradius-utils
fi
systemctl enable --now mysql || true

echo "[2/7] MySQL database and grants"
mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "[3/7] Radius SQL tables and test users"
mysql -h127.0.0.1 -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" <<'SQL'
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
  KEY idx_radacct_acctuniqueid (acctuniqueid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radpostauth (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL DEFAULT '',
  pass VARCHAR(64) NOT NULL DEFAULT '',
  reply VARCHAR(32) NOT NULL DEFAULT '',
  authdate TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_radpostauth_username (username)
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

DELETE FROM radcheck WHERE username IN ('1100000','1200000');
DELETE FROM radreply WHERE username IN ('1100000','1200000');
DELETE FROM radusergroup WHERE username IN ('1100000','1200000');

INSERT INTO radcheck (username,attribute,op,value) VALUES
('1100000','Cleartext-Password',':=','UCuvsbLA2b'),
('1200000','Auth-Type',':=','Accept');

INSERT INTO radreply (username,attribute,op,value) VALUES
('1100000','Service-Type','=','Framed-User'),
('1100000','Framed-Protocol','=','PPP'),
('1100000','Framed-IP-Address','=','10.11.20.10'),
('1100000','Framed-IP-Netmask','=','255.255.255.0'),
('1200000','Service-Type','=','Framed-User'),
('1200000','Framed-IP-Address','=','10.110.0.10'),
('1200000','Framed-IP-Netmask','=','255.255.255.0');

INSERT INTO radusergroup (username,groupname,priority) VALUES
('1100000','tariff-200m',1),
('1200000','tariff-ipoe-100m',1)
ON DUPLICATE KEY UPDATE priority=VALUES(priority);
SQL

echo "[4/7] FreeRADIUS SQL module"
cp /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-available/sql.bak.$(date +%Y%m%d%H%M%S) 2>/dev/null || true
cat >/etc/freeradius/3.0/mods-available/sql <<CONF
sql {
  driver = "rlm_sql_mysql"
  dialect = "mysql"
  server = "127.0.0.1"
  port = 3306
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

echo "[5/7] Enable sql in sites"
for site in /etc/freeradius/3.0/sites-enabled/default /etc/freeradius/3.0/sites-enabled/inner-tunnel; do
  [ -f "$site" ] || continue
  cp "$site" "$site.bak.$(date +%Y%m%d%H%M%S)"
  sed -i 's/^[[:space:]]*#[[:space:]]*sql[[:space:]]*$/        sql/g' "$site"
done

echo "[6/7] clients.conf localhost secret"
cp /etc/freeradius/3.0/clients.conf /etc/freeradius/3.0/clients.conf.bak.$(date +%Y%m%d%H%M%S)
sed -i "/client localhost[[:space:]]*{/,/}/ s/^[[:space:]]*secret[[:space:]]*=.*/    secret = ${RADIUS_SECRET}/" /etc/freeradius/3.0/clients.conf
sed -i "/client localhost_ipv6[[:space:]]*{/,/}/ s/^[[:space:]]*secret[[:space:]]*=.*/    secret = ${RADIUS_SECRET}/" /etc/freeradius/3.0/clients.conf

echo "[7/7] Restart and test"
freeradius -C
systemctl enable freeradius
systemctl restart freeradius
sleep 2
ss -lunp | grep -E '1812|1813' || true
radtest 1100000 UCuvsbLA2b 127.0.0.1 0 "${RADIUS_SECRET}"
radtest 1200000 any-test-password 127.0.0.1 0 "${RADIUS_SECRET}"

echo "DONE"
