#!/usr/bin/env bash
set -Eeuo pipefail

if [ "${CONFIRM_RESET:-}" != "YES" ]; then
  cat >&2 <<'TEXT'
This script is destructive for MySQL on this Ubuntu VM.
It removes local MySQL packages/config/data and reinstalls MySQL + FreeRADIUS.

Use only on the lab VM if there is no important MySQL data.

Run:
  sudo CONFIRM_RESET=YES bash reset-ubuntu-mysql-radius-lab.sh
TEXT
  exit 10
fi

[[ "${EUID}" -eq 0 ]] || { echo "Run with sudo"; exit 1; }

echo "[1/8] Stop services"
systemctl stop freeradius mysql 2>/dev/null || true

echo "[2/8] Free disk and fix apt sources"
sed -i.bak '/^[[:space:]]*deb cdrom:/ s/^/#/' /etc/apt/sources.list 2>/dev/null || true
apt-get clean || true
rm -rf /var/cache/apt/archives/*.deb /var/cache/apt/archives/partial/* 2>/dev/null || true
journalctl --vacuum-size=80M 2>/dev/null || true

echo "[3/8] Purge broken MySQL packages"
export DEBIAN_FRONTEND=noninteractive
apt-get remove --purge -y mysql-server mysql-server-8.0 mysql-server-core-8.0 mysql-client mysql-client-8.0 mysql-client-core-8.0 || true
apt-get autoremove --purge -y || true

echo "[4/8] Remove lab MySQL data/config"
rm -rf /var/lib/mysql /var/log/mysql /var/run/mysqld /etc/mysql
mkdir -p /var/run/mysqld

echo "[5/8] Repair package database"
dpkg --configure -a || true
apt-get -f install -y || true

echo "[6/8] Reinstall packages"
apt-get update
apt-get install -y mysql-server mysql-client freeradius freeradius-mysql freeradius-utils

echo "[7/8] Start MySQL"
systemctl enable --now mysql
sleep 3
systemctl status mysql --no-pager -l
mysql --protocol=socket -uroot -e "SELECT VERSION() AS mysql_version;"

echo "[8/8] Continue Radius setup"
if [ -f ./setup-ubuntu-freeradius-lite.sh ]; then
  SKIP_PACKAGES=1 bash ./setup-ubuntu-freeradius-lite.sh
else
  echo "setup-ubuntu-freeradius-lite.sh not found in current directory."
  echo "Download it, then run: sudo SKIP_PACKAGES=1 bash setup-ubuntu-freeradius-lite.sh"
fi
