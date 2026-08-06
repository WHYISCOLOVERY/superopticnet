#!/usr/bin/env bash
set -Eeuo pipefail

BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/superoptic}"
BACKUP_DIR=""
APPLY=0
RESTORE_DB=0
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_ADMIN_USER="${DB_ADMIN_USER:-root}"
DB_ADMIN_PASS="${DB_ADMIN_PASS:-}"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

usage() {
    cat <<'USAGE'
Usage: sudo bash deploy/ubuntu-rollback-superoptic.sh --backup-dir PATH [--apply] [--restore-db]

Without --apply the script validates the backup and prints a value-free plan.
Database restore is never implied; it additionally requires --restore-db.
DB_ADMIN_PASS may be supplied only through the process environment.
USAGE
}

while (( $# > 0 )); do
    case "$1" in
        --backup-dir) (( $# >= 2 )) || fail "--backup-dir requires a path"; BACKUP_DIR="$2"; shift 2 ;;
        --apply) APPLY=1; shift ;;
        --restore-db) RESTORE_DB=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) fail "Unknown option: $1" ;;
    esac
done

[[ "${EUID}" -eq 0 ]] || fail "Run with sudo"
[[ -n "${BACKUP_DIR}" && -d "${BACKUP_DIR}" ]] || fail "Backup directory not found"

resolved_root="$(realpath "${BACKUP_ROOT}")"
resolved_backup="$(realpath "${BACKUP_DIR}")"
case "${resolved_backup}" in
    "${resolved_root}"/preinstall-*) ;;
    *) fail "Backup must be a preinstall directory under ${resolved_root}" ;;
esac

[[ -f "${resolved_backup}/manifest.txt" ]] || fail "manifest.txt is missing"
[[ -f "${resolved_backup}/SHA256SUMS" ]] || fail "SHA256SUMS is missing"
(cd "${resolved_backup}" && sha256sum -c SHA256SUMS >/dev/null) || fail "Backup checksum verification failed"

manifest_value() {
    local key="$1"
    awk -F= -v wanted="${key}" '$1 == wanted { sub(/^[^=]*=/, ""); print; exit }' "${resolved_backup}/manifest.txt"
}

web_root="$(manifest_value web_root)"
mgs_root="$(manifest_value mgs_root)"
database="$(manifest_value database)"

validate_target() {
    local path="$1" prefix="$2"
    [[ "${path}" == "${prefix}"* && "${path}" != "${prefix}" && "${path}" != "/" ]] \
        || fail "Unsafe restore target in manifest: ${path}"
}

validate_target "${web_root}" "/var/www/"
validate_target "${mgs_root}" "/opt/"
[[ "${database}" =~ ^[A-Za-z0-9_]+$ ]] || fail "Unsafe database name in manifest"

printf 'Backup: %s\n' "${resolved_backup}"
printf 'Application archive: %s\n' "$([[ -f "${resolved_backup}/superopticnet.tar.gz" ]] && echo present || echo absent)"
printf 'MGS archive: %s\n' "$([[ -f "${resolved_backup}/superoptic-mgs.tar.gz" ]] && echo present || echo absent)"
printf 'Database archive: %s\n' "$([[ -f "${resolved_backup}/database.sql" ]] && echo present || echo absent)"
printf 'Database restore requested: %s\n' "${RESTORE_DB}"

if [[ "${APPLY}" != "1" ]]; then
    printf 'PLAN ONLY: no files, services or database rows were changed.\n'
    exit 0
fi

stage="$(mktemp -d /var/tmp/superoptic-rollback.XXXXXX)"
safety_dir="${BACKUP_ROOT}/rollback-safety-$(date '+%Y%m%d-%H%M%S')"
trap 'rm -rf -- "${stage}"' EXIT
install -d -m 0700 -o root -g root "${safety_dir}"

if [[ -d "${web_root}" ]]; then
    tar -C "${web_root}" -czf "${safety_dir}/superopticnet-current.tar.gz" .
fi
if [[ -d "${mgs_root}" ]]; then
    tar --exclude='./node_modules' --exclude='./logs' --exclude='./runtime' -C "${mgs_root}" \
        -czf "${safety_dir}/superoptic-mgs-current.tar.gz" .
fi

systemctl stop superoptic-mgs-node.service 2>/dev/null || true
systemctl stop 'superoptic-*.timer' 2>/dev/null || true
systemctl stop apache2.service 2>/dev/null || true

if [[ -f "${resolved_backup}/superopticnet.tar.gz" ]]; then
    install -d "${stage}/web" "${web_root}"
    tar -C "${stage}/web" -xzf "${resolved_backup}/superopticnet.tar.gz"
    rsync -a --delete "${stage}/web/" "${web_root}/"
fi
if [[ -f "${resolved_backup}/superoptic-mgs.tar.gz" ]]; then
    install -d "${stage}/mgs" "${mgs_root}"
    tar -C "${stage}/mgs" -xzf "${resolved_backup}/superoptic-mgs.tar.gz"
    rsync -a --delete "${stage}/mgs/" "${mgs_root}/"
fi

if [[ "${RESTORE_DB}" == "1" ]]; then
    [[ -f "${resolved_backup}/database.sql" ]] || fail "Database archive is missing"
    if [[ "${DB_HOST}" == "127.0.0.1" || "${DB_HOST}" == "localhost" ]] && [[ -z "${DB_ADMIN_PASS}" ]]; then
        mysqldump --protocol=socket -u "${DB_ADMIN_USER}" --single-transaction "${database}" \
            >"${safety_dir}/database-current.sql"
        mysql --protocol=socket -u "${DB_ADMIN_USER}" "${database}" <"${resolved_backup}/database.sql"
    else
        [[ -n "${DB_ADMIN_PASS}" ]] || fail "DB_ADMIN_PASS is required for remote database rollback"
        MYSQL_PWD="${DB_ADMIN_PASS}" mysqldump -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_ADMIN_USER}" \
            --single-transaction "${database}" >"${safety_dir}/database-current.sql"
        MYSQL_PWD="${DB_ADMIN_PASS}" mysql -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_ADMIN_USER}" \
            "${database}" <"${resolved_backup}/database.sql"
    fi
fi

(cd "${safety_dir}" && sha256sum ./* >SHA256SUMS)
chmod -R go-rwx "${safety_dir}"
systemctl start apache2.service
systemctl start superoptic-mgs-node.service 2>/dev/null || true
systemctl start 'superoptic-*.timer' 2>/dev/null || true

printf 'Rollback completed. Safety snapshot: %s\n' "${safety_dir}"
