#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 027

MODE="${1:---preflight}"
WEB_ROOT="${WEB_ROOT:-/var/www/superopticnet}"
CUTOVER_ENV="${CUTOVER_ENV:-/etc/superoptic-mgs/legacy-cutover.env}"
COMMUNICATION_ENV="${COMMUNICATION_ENV:-/etc/superoptic-mgs/services/communication.env}"
EXPECTED_CUSTOMERS="${EXPECTED_CUSTOMERS:-5500}"
EXPECTED_PAYMENTS="${EXPECTED_PAYMENTS:-0}"
PAYMENT_WINDOW="${PAYMENT_WINDOW:-10000}"
LEGACY_INTERNAL_IP="${LEGACY_INTERNAL_IP:-192.168.100.11}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/superopticnet}"

die() { printf 'BLOCKED: %s\n' "$1" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || die 'root execution is required'
[[ -d "${WEB_ROOT}" ]] || die 'web root missing'
[[ "${EXPECTED_CUSTOMERS}" =~ ^[0-9]+$ ]] || die 'invalid expected customer count'
[[ "${EXPECTED_PAYMENTS}" =~ ^[0-9]+$ ]] || die 'invalid expected payment count'
[[ "${PAYMENT_WINDOW}" =~ ^[0-9]+$ ]] || die 'invalid payment window'

readiness() {
    local phase="$1"
    [[ -r "${COMMUNICATION_ENV}" ]] || die 'communication service environment is missing'
    set -a
    # shellcheck disable=SC1090
    source "${COMMUNICATION_ENV}"
    set +a
    env COMMUNICATION_EXTERNAL_SEND_ENABLED=1 php "${WEB_ROOT}/tools/legacy-cutover-readiness.php" \
        --strict --phase="${phase}" \
        --expected-customers="${EXPECTED_CUSTOMERS}" \
        --expected-payments="${EXPECTED_PAYMENTS}"
}

load_cutover_env() {
    [[ -r "${CUTOVER_ENV}" ]] || die 'protected legacy cutover environment is missing'
    local owner mode
    owner="$(stat -c '%U:%G' "${CUTOVER_ENV}")"
    mode="$(stat -c '%a' "${CUTOVER_ENV}")"
    [[ "${owner}" == 'root:root' && "${mode}" == '600' ]] || die 'legacy cutover environment must be root:root mode 600'
    set -a
    # shellcheck disable=SC1090
    source "${CUTOVER_ENV}"
    set +a
    [[ -n "${OLD_BILLING_EMAIL:-}" && -n "${OLD_BILLING_PASSWORD:-}" ]] || die 'legacy billing credentials are missing'
}

old_payment_total_from_log() {
    python3 - "$1" <<'PY'
import json,re,sys
text=open(sys.argv[1],encoding='utf-8',errors='replace').read()
matches=re.findall(r'Payments done:\s*(\{[^\r\n]+\})',text)
if not matches:
    raise SystemExit(2)
print(int(json.loads(matches[-1]).get('total',0)))
PY
}

new_payment_total() {
    php "${WEB_ROOT}/tools/legacy-cutover-readiness.php" --payment-count-only
}

sync_payments() {
    local resolve_ip="${1:-}" log_file old_total new_total import_pid import_status
    load_cutover_env
    log_file="$(mktemp /tmp/superoptic-payment-delta.XXXXXX)"
    trap 'rm -f -- "${log_file:-}"' RETURN
    if [[ -n "${resolve_ip}" ]]; then
        export OLD_BILLING_RESOLVE_IP="${resolve_ip}"
    else
        unset OLD_BILLING_RESOLVE_IP || true
    fi
    php "${WEB_ROOT}/tools/import-old-billing.php" \
        --apply --skip-users --no-radius-sync --import-payments --skip-existing-payments \
        --payment-offset=0 --payment-limit="${PAYMENT_WINDOW}" --batch=500 >"${log_file}" 2>&1 &
    import_pid=$!
    while kill -0 "${import_pid}" 2>/dev/null; do
        printf 'PAYMENT_SYNC_RUNNING pid=%s\n' "${import_pid}"
        sleep 15
    done
    import_status=0
    wait "${import_pid}" || import_status=$?
    if [[ "${import_status}" -ne 0 ]]; then
        tail -n 20 "${log_file}" | sed -E 's/(password|secret|token)=[^ ]+/\1=[redacted]/Ig' >&2
        die 'payment delta import failed'
    fi
    old_total="$(old_payment_total_from_log "${log_file}")" || die 'could not read legacy payment total'
    new_total="$(new_payment_total)" || die 'could not read new payment total'
    [[ "${old_total}" -gt 0 ]] || die 'legacy payment total is zero'
    [[ "${new_total}" -eq "${old_total}" ]] || die "payment reconciliation mismatch old=${old_total} new=${new_total}; increase PAYMENT_WINDOW or inspect rejects"
    EXPECTED_PAYMENTS="${old_total}"
    export EXPECTED_PAYMENTS
    printf 'PAYMENT_RECONCILED old=%s new=%s\n' "${old_total}" "${new_total}"
}

case "${MODE}" in
    --preflight)
        readiness pre
        ;;
    --presync)
        mkdir -p "${BACKUP_DIR}"
        mysqldump --single-transaction superoptic_portal | gzip >"${BACKUP_DIR}/superoptic_portal-before-cutover-$(date +%Y%m%d-%H%M%S).sql.gz"
        sync_payments ''
        readiness pre
        ;;
    --final-sync)
        sync_payments "${LEGACY_INTERNAL_IP}"
        readiness pre
        ;;
    --activate)
        systemctl enable --now superoptic-billing-maintenance.timer
        systemctl enable --now superoptic-payment-outbox.timer
        systemctl enable --now superoptic-communication-queue.timer
        systemctl enable --now superoptic-radius-coa-worker.timer
        systemctl start superoptic-billing-maintenance.service
        systemctl start superoptic-payment-outbox.service
        systemctl start superoptic-communication-queue.service
        systemctl start superoptic-radius-coa-worker.service
        readiness post
        ;;
    --pause-ingress)
        install -o root -g www-data -m 0644 /dev/null /run/superoptic-payment-ingress-paused
        printf 'PAYMENT_INGRESS_PAUSED\n'
        ;;
    --resume-ingress)
        rm -f /run/superoptic-payment-ingress-paused
        printf 'PAYMENT_INGRESS_ACTIVE\n'
        ;;
    --enable-delivery)
        install -d -o root -g root -m 0755 /etc/systemd/system/superoptic-communication-queue.service.d
        cat >/etc/systemd/system/superoptic-communication-queue.service.d/20-cutover-delivery.conf <<'CONF'
[Service]
Environment=COMMUNICATION_EXTERNAL_SEND_ENABLED=1
CONF
        systemctl daemon-reload
        printf 'COMMUNICATION_EXTERNAL_ACTIVE\n'
        ;;
    --disable-delivery)
        rm -f /etc/systemd/system/superoptic-communication-queue.service.d/20-cutover-delivery.conf
        systemctl daemon-reload
        systemctl stop superoptic-communication-queue.service >/dev/null 2>&1 || true
        printf 'COMMUNICATION_EXTERNAL_PAUSED\n'
        ;;
    --postcheck)
        readiness post
        systemctl is-active freeradius apache2 mysql >/dev/null
        systemctl is-active superoptic-billing-maintenance.timer superoptic-payment-outbox.timer \
            superoptic-communication-queue.timer superoptic-radius-coa-worker.timer >/dev/null
        systemctl show superoptic-communication-queue.service --property=Environment --value \
            | grep -q 'COMMUNICATION_EXTERNAL_SEND_ENABLED=1'
        printf 'POSTCHECK_OK\n'
        ;;
    *)
        die 'unknown mode'
        ;;
esac
