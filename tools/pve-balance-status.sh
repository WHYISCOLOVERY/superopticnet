#!/usr/bin/env bash
set -euo pipefail
KEY=/root/.ssh/sot_vm_ed25519
TARGET=sardar@192.168.100.201
scp -o StrictHostKeyChecking=no -i "$KEY" /root/production-balance-auto-status.php "$TARGET:/tmp/"
ssh -o StrictHostKeyChecking=no -i "$KEY" "$TARGET" 'bash -s' <<'REMOTE'
sudo cp /tmp/production-balance-auto-status.php /var/www/superopticnet/tools/
sudo php /var/www/superopticnet/tools/production-balance-auto-status.php
REMOTE
