#!/usr/bin/env bash
set -euo pipefail
KEY=/root/.ssh/sot_vm_ed25519
TARGET=sardar@192.168.100.201
ssh -o ConnectTimeout=5 -o StrictHostKeyChecking=no -i "$KEY" "$TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
echo VHOSTS
sudo cat /etc/apache2/sites-enabled/billing-compat-ssl.conf /etc/apache2/sites-enabled/superoptic-mgs-ssl.conf 2>/dev/null | sed -E 's/(Token|Secret|Password|Authorization)[[:space:]]+[^[:space:]]+/\1 [REDACTED]/Ig' | head -240
echo DNS
getent ahostsv4 billing.superoptic.net mgt.superoptic.net || true
echo FIREWALL
sudo ufw status numbered || true
REMOTE
