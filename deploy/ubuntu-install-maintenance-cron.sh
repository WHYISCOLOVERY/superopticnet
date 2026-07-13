#!/usr/bin/env bash
set -euo pipefail

WEB_ROOT="${WEB_ROOT:-/var/www/superopticnet}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
CRON_FILE="${CRON_FILE:-/etc/cron.d/superoptic-mgs-maintenance}"
RUN_USER="${RUN_USER:-www-data}"
RUN_TIME_MINUTE="${RUN_TIME_MINUTE:-20}"
RUN_TIME_HOUR="${RUN_TIME_HOUR:-3}"

TOOL="${WEB_ROOT}/tools/system-maintenance-optimizer.php"
LOG_DIR="${WEB_ROOT}/tmp"
LOG_FILE="${LOG_DIR}/system-maintenance-cron.log"

if [[ ! -x "${PHP_BIN}" ]]; then
  echo "PHP executable not found or not executable: ${PHP_BIN}" >&2
  exit 2
fi

if [[ ! -f "${TOOL}" ]]; then
  echo "Maintenance optimizer not found: ${TOOL}" >&2
  exit 3
fi

mkdir -p "${LOG_DIR}"
chown "${RUN_USER}:${RUN_USER}" "${LOG_DIR}" || true

cat > "${CRON_FILE}" <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
${RUN_TIME_MINUTE} ${RUN_TIME_HOUR} * * * ${RUN_USER} cd ${WEB_ROOT} && ${PHP_BIN} ${TOOL} --apply --json >> ${LOG_FILE} 2>&1
EOF

chmod 0644 "${CRON_FILE}"

echo "SuperOptic maintenance cron installed."
echo "Cron: ${CRON_FILE}"
echo "Schedule: ${RUN_TIME_HOUR}:${RUN_TIME_MINUTE} daily"
echo "Log: ${LOG_FILE}"
