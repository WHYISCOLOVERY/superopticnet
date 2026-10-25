#!/usr/bin/env bash
set -euo pipefail
KEY=/root/.ssh/sot_vm_ed25519
TARGET=sardar@192.168.100.201
scp -o StrictHostKeyChecking=no -i "$KEY" /root/production-customer-billing-inspect.php "$TARGET:/tmp/"
ssh -o StrictHostKeyChecking=no -i "$KEY" "$TARGET" 'bash -s' <<'REMOTE'
sudo cp /tmp/production-customer-billing-inspect.php /var/www/superopticnet/tools/
sudo php /var/www/superopticnet/tools/production-customer-billing-inspect.php 3001455
REMOTE
