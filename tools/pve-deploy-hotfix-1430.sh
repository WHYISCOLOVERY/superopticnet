#!/usr/bin/env bash
set -euo pipefail
KEY=/root/.ssh/sot_vm_ed25519
TARGET=sardar@192.168.100.201
scp -o StrictHostKeyChecking=no -i "$KEY" /root/superoptic-hotfix-20261021-1430.zip "$TARGET:/tmp/"
ssh -o StrictHostKeyChecking=no -i "$KEY" "$TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
sudo install -d -m 0755 /tmp/superoptic-hotfix-1430
sudo unzip -oq /tmp/superoptic-hotfix-20261021-1430.zip -d /tmp/superoptic-hotfix-1430
sudo cp -a /tmp/superoptic-hotfix-1430/. /var/www/superopticnet/
sudo php -l /var/www/superopticnet/customer-operator-policy.php
sudo php -l /var/www/superopticnet/billing-customers.php
sudo php /var/www/superopticnet/tools/seed-hr-position-catalog.php
sudo php /var/www/superopticnet/tools/production-payment-health.php
echo DEPLOY_OK
REMOTE
