#!/usr/bin/env bash
set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NGINX_DIR="${NGINX_DIR:-/etc/nginx}"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run as root: sudo bash install-superoptic-nginx.sh" >&2
    exit 1
fi

install -d "${NGINX_DIR}/conf.d"
install -d "${NGINX_DIR}/snippets"
install -d "${NGINX_DIR}/sites-available"
install -d "${NGINX_DIR}/sites-enabled"
install -d "/var/www/letsencrypt"

install -m 0644 "${SRC_DIR}/conf.d/superoptic-core.conf" "${NGINX_DIR}/conf.d/superoptic-core.conf"
install -m 0644 "${SRC_DIR}/conf.d/superoptic-access-control.conf" "${NGINX_DIR}/conf.d/superoptic-access-control.conf"
install -m 0644 "${SRC_DIR}/conf.d/superoptic-rate-limits.conf" "${NGINX_DIR}/conf.d/superoptic-rate-limits.conf"
install -m 0644 "${SRC_DIR}/conf.d/superoptic-upstreams.conf" "${NGINX_DIR}/conf.d/superoptic-upstreams.conf"
install -m 0644 "${SRC_DIR}/snippets/proxy-headers.conf" "${NGINX_DIR}/snippets/proxy-headers.conf"
install -m 0644 "${SRC_DIR}/snippets/security-headers.conf" "${NGINX_DIR}/snippets/security-headers.conf"
install -m 0644 "${SRC_DIR}/snippets/ssl-params.conf" "${NGINX_DIR}/snippets/ssl-params.conf"
install -m 0644 "${SRC_DIR}/sites-available/superoptic-gateway.conf" "${NGINX_DIR}/sites-available/superoptic-gateway.conf"

ln -sfn "${NGINX_DIR}/sites-available/superoptic-gateway.conf" "${NGINX_DIR}/sites-enabled/superoptic-gateway.conf"

echo "Installed SuperOptic Nginx gateway files."
echo "Before reload: install SSL certificates or adjust ssl_certificate paths."
echo "Validation command: nginx -t"
