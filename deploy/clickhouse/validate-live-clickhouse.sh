#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/superopticnet}"
RUNTIME_ENV="${RUNTIME_ENV:-${APP_DIR}/deploy/clickhouse/.env.runtime}"
PHP_BIN="${PHP_BIN:-php}"

if [[ ! -f "${RUNTIME_ENV}" ]]; then
  echo "Runtime env not found: ${RUNTIME_ENV}" >&2
  exit 1
fi

set -a
# shellcheck disable=SC1090
. "${RUNTIME_ENV}"
set +a

cd "${APP_DIR}"

echo "[1/8] HTTP health"
curl -fsS -u "${CLICKHOUSE_USER}:${CLICKHOUSE_PASSWORD}" \
  "http://${CLICKHOUSE_HOST}:${CLICKHOUSE_HTTP_PORT}/?query=SELECT%201"
echo

echo "[2/8] Readiness probe"
"${PHP_BIN}" tools/cgnat-storage-readiness.php

echo "[3/8] Schema inventory"
curl -fsS -u "${CLICKHOUSE_USER}:${CLICKHOUSE_PASSWORD}" \
  "http://${CLICKHOUSE_HOST}:${CLICKHOUSE_HTTP_PORT}/?database=${CLICKHOUSE_DATABASE}&query=SELECT%20name%2C%20engine%2C%20partition_key%2C%20sorting_key%20FROM%20system.tables%20WHERE%20database%3D%27${CLICKHOUSE_DATABASE}%27%20ORDER%20BY%20name%20FORMAT%20PrettyCompact"

echo "[4/8] TTL inventory"
curl -fsS -u "${CLICKHOUSE_USER}:${CLICKHOUSE_PASSWORD}" \
  "http://${CLICKHOUSE_HOST}:${CLICKHOUSE_HTTP_PORT}/?query=SELECT%20database%2C%20table%2C%20name%2C%20expression%20FROM%20system.parts_columns%20WHERE%20database%3D%27${CLICKHOUSE_DATABASE}%27%20LIMIT%201%20FORMAT%20PrettyCompact" >/dev/null
curl -fsS -u "${CLICKHOUSE_USER}:${CLICKHOUSE_PASSWORD}" \
  "http://${CLICKHOUSE_HOST}:${CLICKHOUSE_HTTP_PORT}/?query=SELECT%20database%2C%20name%2C%20engine%2C%20total_rows%2C%20total_bytes%20FROM%20system.tables%20WHERE%20database%3D%27${CLICKHOUSE_DATABASE}%27%20FORMAT%20PrettyCompact"

echo "[5/8] Live CGNAT ingest small batch"
"${PHP_BIN}" tools/cgnat-clickhouse-ingest.php --source=mysql --limit=1000 --apply

echo "[6/8] Live RADIUS accounting offload small batch"
"${PHP_BIN}" tools/radius-accounting-offload.php --limit=1000 --apply

echo "[7/8] Law export dry-run gate"
"${PHP_BIN}" tools/law-enforcement-export.php \
  --dry-run \
  --requester="validation" \
  --reason="live-clickhouse-validation" \
  --format=csv \
  --from="$(date -u -d '7 days ago' '+%F 00:00:00')" \
  --to="$(date -u '+%F 23:59:59')" \
  --limit=10

echo "[8/8] 100k digital twin"
"${PHP_BIN}" tools/phase3-digital-twin-simulation.php \
  --customers=100000 \
  --olts=250 \
  --onts=100000 \
  --days=30 \
  --seed=550 \
  --sample-ai=1000
