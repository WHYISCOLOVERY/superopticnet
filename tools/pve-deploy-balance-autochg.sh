#!/usr/bin/env bash
set -euo pipefail
KEY=/root/.ssh/sot_vm_ed25519
TARGET=sardar@192.168.100.201
scp -o StrictHostKeyChecking=no -i "$KEY" /root/superoptic-balance-autochg-20261021.zip "$TARGET:/tmp/"
ssh -o StrictHostKeyChecking=no -i "$KEY" "$TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
sudo install -d -m 0755 /tmp/superoptic-balance-autochg
sudo unzip -oq /tmp/superoptic-balance-autochg-20261021.zip -d /tmp/superoptic-balance-autochg
sudo cp -a /tmp/superoptic-balance-autochg/. /var/www/superopticnet/
sudo php -l /var/www/superopticnet/db.php
sudo php -l /var/www/superopticnet/tools/billing-maintenance.php
sudo php /var/www/superopticnet/tools/billing-maintenance.php --apply --apply-balances --limit=10000
REMOTE
