#!/usr/bin/env bash
set -euo pipefail
KEY=/root/.ssh/sot_vm_ed25519
TARGET=sardar@192.168.100.201
ssh -o ConnectTimeout=5 -o StrictHostKeyChecking=no -i "$KEY" "$TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
echo APACHE
sudo apachectl -S 2>&1 || true
echo CALLBACKS
sudo grep -h -E '/api/(provider/payment|provider/up/insert-payment)' /var/log/apache2/*access* 2>/dev/null | tail -30 || true
echo ERRORS
sudo grep -h -Ei 'provider|payment' /var/log/apache2/*error* 2>/dev/null | tail -30 || true
REMOTE
