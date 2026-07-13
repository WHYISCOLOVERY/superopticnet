#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/superopticnet}"
CLICKHOUSE_DIR="${CLICKHOUSE_DIR:-${APP_DIR}/deploy/clickhouse}"
CLICKHOUSE_PASSWORD="${CLICKHOUSE_PASSWORD:-}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo APP_DIR=/opt/superopticnet CLICKHOUSE_PASSWORD='...' bash install-clickhouse-ubuntu.sh" >&2
  exit 1
fi

if grep -qi microsoft /proc/version 2>/dev/null && ! grep -qi wsl2 /proc/version 2>/dev/null; then
  echo "This environment looks like WSL1. Dockerized ClickHouse requires a real Ubuntu host, WSL2, or Proxmox VM." >&2
  echo "Current app path is likely /mnt/d/xampp/htdocs/superopticnet; copy it to the target Ubuntu server and run this script there." >&2
  exit 1
fi

if [[ -z "${CLICKHOUSE_PASSWORD}" ]]; then
  echo "CLICKHOUSE_PASSWORD is required." >&2
  exit 1
fi

if [[ ! -f "${CLICKHOUSE_DIR}/docker-compose.clickhouse.yml" ]]; then
  echo "ClickHouse compose file not found: ${CLICKHOUSE_DIR}/docker-compose.clickhouse.yml" >&2
  exit 1
fi

echo "[1/7] Installing Docker prerequisites"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl gnupg lsb-release jq

if ! command -v docker >/dev/null 2>&1; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  . /etc/os-release
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

echo "[2/7] Preparing persistent directories"
mkdir -p "${CLICKHOUSE_DIR}/archive" /var/log/superoptic/clickhouse /var/backups/superoptic/clickhouse
chmod 750 /var/log/superoptic /var/log/superoptic/clickhouse /var/backups/superoptic /var/backups/superoptic/clickhouse

echo "[3/7] Starting ClickHouse stack"
cd "${CLICKHOUSE_DIR}"
CLICKHOUSE_PASSWORD="${CLICKHOUSE_PASSWORD}" docker compose -f docker-compose.clickhouse.yml up -d

echo "[4/7] Waiting for ClickHouse HTTP"
for i in $(seq 1 60); do
  if curl -fsS -u "superoptic:${CLICKHOUSE_PASSWORD}" "http://127.0.0.1:8123/?query=SELECT%201" >/dev/null 2>&1; then
    break
  fi
  if [[ "$i" -eq 60 ]]; then
    docker compose -f docker-compose.clickhouse.yml logs --tail=200
    echo "ClickHouse did not become ready." >&2
    exit 1
  fi
  sleep 2
done

echo "[5/7] Applying schema"
curl -fsS -u "superoptic:${CLICKHOUSE_PASSWORD}" \
  --data-binary @"${CLICKHOUSE_DIR}/superoptic-cgnat.sql" \
  "http://127.0.0.1:8123/?multiquery=1"

echo "[6/7] Verifying tables"
curl -fsS -u "superoptic:${CLICKHOUSE_PASSWORD}" \
  "http://127.0.0.1:8123/?database=superoptic_analytics&query=SHOW%20TABLES" \
  | tee /var/log/superoptic/clickhouse/tables.txt

echo "[7/7] Writing environment template"
cat > "${CLICKHOUSE_DIR}/.env.runtime" <<EOF
CLICKHOUSE_HOST=127.0.0.1
CLICKHOUSE_PORT=9000
CLICKHOUSE_HTTP_PORT=8123
CLICKHOUSE_USER=superoptic
CLICKHOUSE_PASSWORD=${CLICKHOUSE_PASSWORD}
CLICKHOUSE_DATABASE=superoptic_analytics
CLICKHOUSE_SECURE=0
EOF
chmod 600 "${CLICKHOUSE_DIR}/.env.runtime"

echo "ClickHouse deployment complete."
echo "Use these env vars for PHP readiness probe:"
cat "${CLICKHOUSE_DIR}/.env.runtime"
