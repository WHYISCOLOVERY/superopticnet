#!/usr/bin/env bash
set -Eeuo pipefail

[[ "${EUID}" -eq 0 ]] || { echo "Run with sudo"; exit 1; }

echo "[1/9] Disk and package status"
df -h /
df -i /
dpkg --audit || true

echo "[2/9] Disable cdrom repo and cleanup"
sed -i.bak '/^[[:space:]]*deb cdrom:/ s/^/#/' /etc/apt/sources.list 2>/dev/null || true
apt-get clean || true
rm -rf /var/cache/apt/archives/*.deb /var/cache/apt/archives/partial/* 2>/dev/null || true
rm -rf /var/lib/apt/lists/partial/* 2>/dev/null || true
journalctl --vacuum-size=80M 2>/dev/null || true
find /tmp -mindepth 1 -maxdepth 1 -mtime +1 -exec rm -rf {} + 2>/dev/null || true
find /var/tmp -mindepth 1 -maxdepth 1 -mtime +2 -exec rm -rf {} + 2>/dev/null || true

FREE_MB=$(df -Pm / | awk 'NR==2 {print $4}')
echo "Free space after cleanup: ${FREE_MB} MB"
if [ "${FREE_MB:-0}" -lt 700 ]; then
  echo "ERROR: Not enough free disk (${FREE_MB} MB). Increase VM disk or delete files before continuing." >&2
  exit 20
fi

echo "[3/9] Repair dpkg"
dpkg --configure -a || true
apt-get -f install -y || true

echo "[4/9] Ensure MySQL packages are configured"
export DEBIAN_FRONTEND=noninteractive
apt-get update || true
apt-get install -y mysql-server mysql-client || true
dpkg --configure -a || true
apt-get -f install -y || true

echo "[5/9] Prepare MySQL runtime directories"
id mysql >/dev/null 2>&1 || useradd -r -s /bin/false mysql
mkdir -p /var/run/mysqld /var/lib/mysql
chown -R mysql:mysql /var/run/mysqld /var/lib/mysql
chmod 755 /var/run/mysqld

echo "[6/9] Try starting MySQL"
systemctl reset-failed mysql || true
systemctl enable mysql || true
systemctl restart mysql || true
sleep 5

if ! systemctl is-active --quiet mysql; then
  echo "MySQL still not active. Last journal lines:"
  journalctl -xeu mysql.service --no-pager -n 120 || true
  echo "Trying mysqld config validation:"
  mysqld --validate-config || true
  echo "Trying safe runtime directory repair once more:"
  mkdir -p /var/run/mysqld
  chown mysql:mysql /var/run/mysqld
  systemctl restart mysql || true
  sleep 5
fi

if ! systemctl is-active --quiet mysql; then
  echo "ERROR: MySQL did not start. Paste the journal output above." >&2
  exit 30
fi

echo "[7/9] MySQL status"
systemctl status mysql --no-pager || true
mysql --protocol=socket -uroot -e "SELECT VERSION() AS mysql_version;"

echo "[8/9] FreeRADIUS package/config sanity"
dpkg -s freeradius freeradius-mysql freeradius-utils >/dev/null
freeradius -C || true

echo "[9/9] Ready"
echo "Now run:"
echo "sudo SKIP_PACKAGES=1 bash setup-ubuntu-freeradius-lite.sh"
