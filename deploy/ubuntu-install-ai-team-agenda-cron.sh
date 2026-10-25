#!/usr/bin/env bash
set -euo pipefail

WEB_ROOT="${WEB_ROOT:-/var/www/superopticnet}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
CRON_FILE="${CRON_FILE:-/etc/cron.d/superoptic-ai-team-agenda}"
RUN_USER="${RUN_USER:-www-data}"
RUN_TIME_MINUTE="${RUN_TIME_MINUTE:-0}"
RUN_TIME_HOUR="${RUN_TIME_HOUR:-6}"

TOOL="${WEB_ROOT}/tools/ai-team-daily-agenda.php"
LOG_DIR="${WEB_ROOT}/tmp"
LOG_FILE="${LOG_DIR}/ai-team-daily-agenda-cron.log"

if [[ ! -x "${PHP_BIN}" ]]; then
  echo "PHP executable not found or not executable: ${PHP_BIN}" >&2
  exit 2
fi

if [[ ! -f "${TOOL}" ]]; then
  echo "AI Komanda daily-agenda worker not found: ${TOOL}" >&2
  exit 3
fi

mkdir -p "${LOG_DIR}"
chown "${RUN_USER}:${RUN_USER}" "${LOG_DIR}" || true

# --apply stages high/critical advisor facts as pending_admin_review task
# drafts (never assigned, never sent -- a human admin still has to approve).
# Every run also writes an ai_team_agenda_runs heartbeat row, surfaced on the
# AI Komanda page as "Son icra".
cat > "${CRON_FILE}" <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
${RUN_TIME_MINUTE} ${RUN_TIME_HOUR} * * * ${RUN_USER} cd ${WEB_ROOT} && ${PHP_BIN} ${TOOL} --apply >> ${LOG_FILE} 2>&1
EOF

chmod 0644 "${CRON_FILE}"

echo "SuperOptic AI Komanda daily-agenda cron installed."
echo "Cron: ${CRON_FILE}"
echo "Schedule: ${RUN_TIME_HOUR}:${RUN_TIME_MINUTE} daily"
echo "Log: ${LOG_FILE}"
