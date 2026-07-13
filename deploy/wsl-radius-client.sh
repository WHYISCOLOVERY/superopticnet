#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${1:-status}"
USERNAME="${2:-}"
PASSWORD="${3:-}"
FRAMED_IP="${4:-10.250.255.250}"
SESSION_ID="${5:-SUPEROPTIC-WLS-$(date +%s)}"
RADIUS_DIR="${FREERADIUS_DIR:-/etc/freeradius/3.0}"
CLIENTS_FILE="${RADIUS_DIR}/clients.conf"

if [[ "${EUID}" -ne 0 ]]; then
    echo "wsl_radius_requires_root" >&2
    exit 2
fi

client_secret="$({
    awk '
        /client localhost[[:space:]]*\{/ { in_client=1; next }
        in_client && /^[[:space:]]*secret[[:space:]]*=/ {
            value=$0
            sub(/^[^=]*=[[:space:]]*/, "", value)
            gsub(/[[:space:]\"]/, "", value)
            print value
            exit
        }
        in_client && /}/ { in_client=0 }
    ' "${CLIENTS_FILE}"
} 2>/dev/null)"

if [[ -z "${client_secret}" ]]; then
    echo "localhost_radius_secret_not_found" >&2
    exit 3
fi

ensure_radius() {
    if ! service freeradius status >/dev/null 2>&1; then
        freeradius -C >/tmp/superoptic-freeradius-config-check.log 2>&1
        service freeradius start >/dev/null
        sleep 1
    fi
}

ensure_radius

case "${MODE}" in
    status)
        service freeradius status
        ;;
    auth)
        [[ -n "${USERNAME}" && -n "${PASSWORD}" ]] || {
            echo "usage: $0 auth USERNAME PASSWORD" >&2
            exit 4
        }
        exec radtest "${USERNAME}" "${PASSWORD}" 127.0.0.1 0 "${client_secret}"
        ;;
    accounting-start|accounting-stop)
        [[ -n "${USERNAME}" ]] || {
            echo "usage: $0 accounting-start USERNAME UNUSED FRAMED_IP SESSION_ID" >&2
            exit 4
        }
        status_type="Start"
        if [[ "${MODE}" == "accounting-stop" ]]; then
            status_type="Stop"
        fi
        {
            printf 'User-Name = "%s"\n' "${USERNAME}"
            printf 'Acct-Status-Type = %s\n' "${status_type}"
            printf 'Acct-Session-Id = "%s"\n' "${SESSION_ID}"
            printf 'Acct-Unique-Session-Id = "%s"\n' "$(printf '%s' "${SESSION_ID}:${USERNAME}" | sha1sum | cut -d' ' -f1)"
            printf 'NAS-IP-Address = 127.0.0.1\n'
            printf 'NAS-Port = 0\n'
            printf 'NAS-Port-Type = Virtual\n'
            printf 'Service-Type = Framed-User\n'
            printf 'Framed-Protocol = PPP\n'
            printf 'Framed-IP-Address = %s\n' "${FRAMED_IP}"
            if [[ "${status_type}" == "Stop" ]]; then
                printf 'Acct-Session-Time = 60\n'
                printf 'Acct-Terminate-Cause = User-Request\n'
            fi
        } | radclient -x 127.0.0.1 acct "${client_secret}"
        ;;
    *)
        echo "unsupported_mode:${MODE}" >&2
        exit 4
        ;;
esac
