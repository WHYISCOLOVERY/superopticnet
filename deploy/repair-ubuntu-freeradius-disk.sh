#!/usr/bin/env bash
set -Eeuo pipefail

[[ "${EUID}" -eq 0 ]] || { echo "Run with sudo"; exit 1; }

echo "[1/6] Disk before cleanup"
df -h /

echo "[2/6] Disable cdrom repo and clean apt cache"
sed -i.bak '/^[[:space:]]*deb cdrom:/ s/^/#/' /etc/apt/sources.list 2>/dev/null || true
apt-get clean || true
rm -rf /var/cache/apt/archives/*.deb /var/cache/apt/archives/partial/* 2>/dev/null || true
rm -rf /var/lib/apt/lists/partial/* 2>/dev/null || true

echo "[3/6] Trim system logs"
journalctl --vacuum-size=100M 2>/dev/null || true
find /var/log -type f \( -name '*.gz' -o -name '*.1' -o -name '*.old' \) -delete 2>/dev/null || true
find /var/log -type f -name '*.log' -size +50M -exec truncate -s 10M {} \; 2>/dev/null || true

echo "[4/6] Disk after cleanup"
df -h /

echo "[5/6] Repair dpkg/apt"
dpkg --configure -a || true
apt-get -f install -y || true

echo "[6/6] Status"
df -h /
dpkg --audit || true
systemctl status mysql --no-pager || true
systemctl status freeradius --no-pager || true

FREE_MB=$(df -Pm / | awk 'NR==2 {print $4}')
if [ "${FREE_MB:-0}" -lt 500 ]; then
  echo "ERROR: Still only ${FREE_MB} MB free. Increase VM disk or delete large files before continuing." >&2
  exit 20
fi

echo "Repair finished. Next run:"
echo "sudo SKIP_PACKAGES=1 bash setup-ubuntu-freeradius-lite.sh"
