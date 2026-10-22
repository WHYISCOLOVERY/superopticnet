#!/usr/bin/env bash
set -euo pipefail
KEY=/root/.ssh/sot_vm_ed25519
TARGET=sardar@192.168.100.201
ssh -o StrictHostKeyChecking=no -i "$KEY" "$TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
echo SERVICES
sudo systemctl list-units --type=service --all --no-pager | grep -Ei 'apache|nginx|php|superoptic' | head -40 || true
echo WEB_ROOTS
sudo find /var/www -maxdepth 3 -type f -name config.php -o -name payment.php 2>/dev/null | head -40 || true
echo HTTP_CONF
sudo grep -R -n -E 'mgt.superoptic.net|api/provider' /etc/apache2 /etc/nginx 2>/dev/null | head -80 || true
echo ENV_FILES
sudo find /etc /opt /var/www -maxdepth 4 -type f \( -name '*.env' -o -name 'superoptic*.conf' \) 2>/dev/null | head -60 || true
REMOTE
