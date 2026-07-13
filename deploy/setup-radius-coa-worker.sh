#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${1:-/opt/superopticnet}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
SERVICE_FILE="/etc/systemd/system/superoptic-radius-coa-worker.service"
TIMER_FILE="/etc/systemd/system/superoptic-radius-coa-worker.timer"

if [[ ! -f "${APP_ROOT}/tools/process-radius-session-actions.php" ]]; then
  echo "Missing worker script at ${APP_ROOT}/tools/process-radius-session-actions.php" >&2
  exit 1
fi

cat > "${SERVICE_FILE}" <<EOF
[Unit]
Description=SuperOptic RADIUS CoA/disconnect worker
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=oneshot
WorkingDirectory=${APP_ROOT}
ExecStart=${PHP_BIN} ${APP_ROOT}/tools/process-radius-session-actions.php --limit=20 --json
User=www-data
Group=www-data
Nice=5
EOF

cat > "${TIMER_FILE}" <<EOF
[Unit]
Description=Run SuperOptic RADIUS CoA/disconnect worker every 20s

[Timer]
OnBootSec=20s
OnUnitActiveSec=20s
Unit=superoptic-radius-coa-worker.service
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now superoptic-radius-coa-worker.timer
systemctl status superoptic-radius-coa-worker.timer --no-pager || true
echo "Installed superoptic-radius-coa-worker timer for ${APP_ROOT}"
