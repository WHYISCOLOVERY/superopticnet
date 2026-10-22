#!/usr/bin/env bash
set -euo pipefail
KEY=/root/.ssh/sot_vm_ed25519
TARGET=sardar@192.168.100.201
scp -o StrictHostKeyChecking=no -i "$KEY" /root/superoptic-hotfix-20261021-1402.zip "$TARGET:/tmp/"
ssh -o StrictHostKeyChecking=no -i "$KEY" "$TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
sudo install -d -m 0755 /tmp/superoptic-hotfix-20261021
sudo unzip -oq /tmp/superoptic-hotfix-20261021-1402.zip -d /tmp/superoptic-hotfix-20261021
sudo cp -a /tmp/superoptic-hotfix-20261021/. /var/www/superopticnet/
sudo php -l /var/www/superopticnet/db.php
sudo php -l /var/www/superopticnet/hr-employee-service.php
sudo php -l /var/www/superopticnet/operations-center.php
sudo systemctl disable --now superoptic-modem-bot.service || true
echo DEPLOY_OK
REMOTE
