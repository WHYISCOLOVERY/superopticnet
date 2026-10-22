#!/usr/bin/env bash
set -euo pipefail
KEY=/root/.ssh/sot_vm_ed25519
TARGET=sardar@192.168.100.201
ssh -o StrictHostKeyChecking=no -i "$KEY" "$TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
echo ENV_FLAGS
sudo systemctl show superoptic-portal.service --property=Environment --no-pager 2>/dev/null | sed -E 's/(TOKEN|SECRET|PASSWORD)=[^ ]+/\1=SET/g' || true
echo NGINX_PROVIDER
sudo nginx -T 2>/dev/null | grep -n -E 'server_name|api/provider|provider_api_allowed' | head -40 || true
echo PAYMENT_LOG
sudo grep -h -E '/api/(provider/payment|provider/up/insert-payment)' /var/log/nginx/access.log* 2>/dev/null | tail -20 || true
echo APP_LOG
sudo journalctl --since '2026-10-21 11:30:00' --no-pager 2>/dev/null | grep -Ei 'provider|payment' | tail -30 || true
REMOTE
