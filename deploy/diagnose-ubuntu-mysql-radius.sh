#!/usr/bin/env bash
set -Eeuo pipefail

echo "### Disk"
df -h /
df -i /

echo
echo "### Largest dirs under /var"
du -xhd1 /var 2>/dev/null | sort -h | tail -n 20 || true

echo
echo "### dpkg audit"
dpkg --audit || true

echo
echo "### mysql service"
systemctl status mysql.service --no-pager -l || true

echo
echo "### mysql journal"
journalctl -xeu mysql.service --no-pager -n 160 || true

echo
echo "### mysql error logs"
for file in /var/log/mysql/error.log /var/log/mysql/mysql.log /var/log/mysql/mysql.err; do
  if [ -f "$file" ]; then
    echo "--- $file"
    tail -n 160 "$file" || true
  fi
done

echo
echo "### mysql dirs"
ls -ld /var/lib/mysql /var/run/mysqld /etc/mysql 2>/dev/null || true
ls -la /var/lib/mysql 2>/dev/null | tail -n 60 || true

echo
echo "### listening ports"
ss -lntup | grep -E '3306|1812|1813' || true

echo
echo "### freeradius service"
systemctl status freeradius.service --no-pager -l || true

echo
echo "### freeradius config check"
freeradius -C || true
