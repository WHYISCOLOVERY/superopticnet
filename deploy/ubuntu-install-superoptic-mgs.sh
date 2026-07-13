#!/usr/bin/env bash
set -Eeuo pipefail

# SuperOptic MGS full system installer for Ubuntu.
# FreeRADIUS is intentionally excluded. Connect it later with the existing Radius DB/CoA settings.

APP_DOMAIN="${APP_DOMAIN:-billing.superoptic.net}"
APP_URL="${APP_URL:-https://${APP_DOMAIN}}"
PUBLIC_SITE_URL="${PUBLIC_SITE_URL:-https://superoptic.net/}"
WEB_ROOT="${WEB_ROOT:-/var/www/superopticnet}"
MGS_ROOT="${MGS_ROOT:-/opt/superoptic-mgs}"
SOURCE_WEB_ROOT="${SOURCE_WEB_ROOT:-}"
SOURCE_MGS_ROOT="${SOURCE_MGS_ROOT:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-superoptic_portal}"
DB_USER="${DB_USER:-superoptic_app}"
DB_PASS="${DB_PASS:-}"
DB_ADMIN_USER="${DB_ADMIN_USER:-root}"
DB_ADMIN_PASS="${DB_ADMIN_PASS:-}"
RADIUS_SQL_HOST="${RADIUS_SQL_HOST:-127.0.0.1}"
RADIUS_SQL_PORT="${RADIUS_SQL_PORT:-3306}"
RADIUS_SQL_DB="${RADIUS_SQL_DB:-superoptic_portal}"
RADIUS_SQL_USER="${RADIUS_SQL_USER:-radius}"
RADIUS_SQL_PASSWORD="${RADIUS_SQL_PASSWORD:-}"
RADIUS_SHARED_SECRET="${RADIUS_SHARED_SECRET:-}"
RADIUS_CORE_NAS_IP="${RADIUS_CORE_NAS_IP:-85.132.57.218}"
MGS_INTERNAL_URL="${MGS_INTERNAL_URL:-http://127.0.0.1:3000}"
MGS_SERVICE_SECRET="${MGS_SERVICE_SECRET:-}"
INSTALL_PACKAGES="${INSTALL_PACKAGES:-1}"
CONFIGURE_APACHE="${CONFIGURE_APACHE:-1}"
OVERWRITE_CONFIG="${OVERWRITE_CONFIG:-0}"
SKIP_MGS_NPM_INSTALL="${SKIP_MGS_NPM_INSTALL:-0}"
SKIP_SYSTEMD="${SKIP_SYSTEMD:-0}"
DRY_RUN="${DRY_RUN:-0}"

log() {
    printf '\n[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

warn() {
    printf 'WARN: %s\n' "$*" >&2
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

run() {
    if [[ "${DRY_RUN}" == "1" ]]; then
        printf '[DRY-RUN] %q ' "$@"
        printf '\n'
        return 0
    fi
    "$@"
}

require_root() {
    [[ "${EUID}" -eq 0 ]] || fail "Run with sudo"
}

random_secret() {
    openssl rand -base64 32 | tr '+/' 'xy' | tr -d '='
}

detect_sources() {
    if [[ -z "${SOURCE_WEB_ROOT}" ]]; then
        if [[ -f "./config.php" && -f "./db.php" ]]; then
            SOURCE_WEB_ROOT="$(pwd)"
        elif [[ -d "./payload/superopticnet" ]]; then
            SOURCE_WEB_ROOT="$(pwd)/payload/superopticnet"
        elif [[ -d "./superopticnet" ]]; then
            SOURCE_WEB_ROOT="$(pwd)/superopticnet"
        else
            fail "SOURCE_WEB_ROOT not set and superopticnet source not found"
        fi
    fi

    if [[ -z "${SOURCE_MGS_ROOT}" ]]; then
        if [[ -d "./payload/oltidareetme" ]]; then
            SOURCE_MGS_ROOT="$(pwd)/payload/oltidareetme"
        elif [[ -d "./oltidareetme" ]]; then
            SOURCE_MGS_ROOT="$(pwd)/oltidareetme"
        elif [[ -d "$(dirname "${SOURCE_WEB_ROOT}")/oltidareetme" ]]; then
            SOURCE_MGS_ROOT="$(dirname "${SOURCE_WEB_ROOT}")/oltidareetme"
        else
            warn "SOURCE_MGS_ROOT not found. MGS Node install will be skipped unless provided."
            SOURCE_MGS_ROOT=""
        fi
    fi
}

validate_identifier() {
    local value="$1"
    local label="$2"
    [[ "${value}" =~ ^[A-Za-z0-9_]+$ ]] || fail "${label} must contain only letters, numbers and underscore: ${value}"
}

mysql_admin_args() {
    if [[ "${DB_HOST}" == "127.0.0.1" || "${DB_HOST}" == "localhost" ]]; then
        if [[ -n "${DB_ADMIN_PASS}" ]]; then
            printf -- "-h%s -P%s -u%s -p%s" "${DB_HOST}" "${DB_PORT}" "${DB_ADMIN_USER}" "${DB_ADMIN_PASS}"
        else
            printf -- "--protocol=socket -u%s" "${DB_ADMIN_USER}"
        fi
    else
        [[ -n "${DB_ADMIN_PASS}" ]] || return 1
        printf -- "-h%s -P%s -u%s -p%s" "${DB_HOST}" "${DB_PORT}" "${DB_ADMIN_USER}" "${DB_ADMIN_PASS}"
    fi
}

install_packages() {
    if [[ "${INSTALL_PACKAGES}" != "1" ]]; then
        log "Package install skipped"
        return
    fi

    log "Installing Ubuntu packages"
    export DEBIAN_FRONTEND=noninteractive
    run apt-get update
    run apt-get install -y \
        apache2 mysql-server mysql-client \
        php php-cli php-mysql php-curl php-mbstring php-xml php-zip php-gd php-intl \
        nodejs npm default-jre snmp \
        rsync unzip curl git openssl ca-certificates

    if [[ "${DRY_RUN}" == "1" ]]; then
        log "Optional snmp-mibs-downloader check skipped in dry-run"
    else
        apt-get install -y snmp-mibs-downloader >/dev/null 2>&1 || warn "snmp-mibs-downloader not available; snmpwalk is still installed via snmp package."
    fi

    local node_major
    node_major="$(node -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/' || true)"
    if [[ -z "${node_major}" || "${node_major}" -lt 20 ]]; then
        log "Installing Node.js 20 LTS from NodeSource"
        if [[ "${DRY_RUN}" == "1" ]]; then
            echo "[DRY-RUN] curl -fsSL https://deb.nodesource.com/setup_20.x | bash -"
        else
            curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
        fi
        run apt-get install -y nodejs
    fi
}

copy_sources() {
    log "Copying PHP portal to ${WEB_ROOT}"
    run mkdir -p "${WEB_ROOT}"
    run rsync -a --delete \
        --exclude ".git" \
        --exclude "tmp" \
        --exclude "logs" \
        --exclude "node_modules" \
        --exclude "config.local.php" \
        --exclude "*.log" \
        "${SOURCE_WEB_ROOT}/" "${WEB_ROOT}/"
    run chown -R www-data:www-data "${WEB_ROOT}"

    if [[ -n "${SOURCE_MGS_ROOT}" ]]; then
        log "Copying MGS Node app to ${MGS_ROOT}"
        run mkdir -p "${MGS_ROOT}"
        run rsync -a --delete \
            --exclude ".git" \
            --exclude "node_modules" \
            --exclude "logs" \
            --exclude "test-results" \
            --exclude ".env" \
            --exclude ".env.*" \
            --exclude "*.log" \
            "${SOURCE_MGS_ROOT}/" "${MGS_ROOT}/"
    fi
}

create_database() {
    validate_identifier "${DB_NAME}" "DB_NAME"
    validate_identifier "${DB_USER}" "DB_USER"
    if [[ -z "${DB_PASS}" ]]; then
        DB_PASS="$(random_secret)"
        log "Generated DB_PASS for ${DB_USER}"
    fi

    log "Creating MySQL database and app user"
    if admin_args="$(mysql_admin_args)"; then
        local escaped_pass
        escaped_pass="${DB_PASS//\\/\\\\}"
        escaped_pass="${escaped_pass//\'/\\\'}"
        # shellcheck disable=SC2086
        run mysql ${admin_args} <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${escaped_pass}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${escaped_pass}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${escaped_pass}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
    else
        warn "DB admin access not available. Assuming database/user already exists."
    fi
}

set_php_config_value() {
    local file="$1"
    local key="$2"
    local value="$3"
    local escaped
    escaped="${value//\\/\\\\}"
    escaped="${escaped//\'/\\\'}"
    php -r '
        [$file, $key, $value] = array_slice($argv, 1);
        $content = file_get_contents($file);
        $pattern = "/('\Q" . $key . "\E'\s*=>\s*)'[^']*'/";
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, "\${1}'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'", $content, 1);
        } else {
            $content = preg_replace("/return\s+\[\s*\n/", "return [\n    '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $key) . "' => '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "',\n", $content, 1);
        }
        file_put_contents($file, $content);
    ' "${file}" "${key}" "${value}"
}

write_config() {
    local config="${WEB_ROOT}/config.local.php"
    local template="${WEB_ROOT}/config.local.production.example.php"
    [[ -f "${template}" ]] || fail "Production config template not found: ${template}"

    if [[ -f "${config}" && "${OVERWRITE_CONFIG}" != "1" ]]; then
        log "config.local.php exists; keeping it"
        return
    fi

    [[ -n "${MGS_SERVICE_SECRET}" ]] || MGS_SERVICE_SECRET="$(random_secret)"
    [[ -n "${RADIUS_SQL_PASSWORD}" ]] || RADIUS_SQL_PASSWORD="${DB_PASS}"
    [[ -n "${RADIUS_SHARED_SECRET}" ]] || RADIUS_SHARED_SECRET="CHANGE_ME_RADIUS_SHARED_SECRET"

    log "Writing config.local.php"
    run cp "${template}" "${config}"
    if [[ "${DRY_RUN}" == "1" ]]; then
        return
    fi

    set_php_config_value "${config}" "app_url" "${APP_URL}"
    set_php_config_value "${config}" "public_site_url" "${PUBLIC_SITE_URL}"
    set_php_config_value "${config}" "ticket_close_base_url" "${APP_URL}"
    set_php_config_value "${config}" "billing_mode" "mysql"
    set_php_config_value "${config}" "portal_storage_driver" "mysql"
    set_php_config_value "${config}" "db_host" "${DB_HOST}"
    set_php_config_value "${config}" "db_port" "${DB_PORT}"
    set_php_config_value "${config}" "db_name" "${DB_NAME}"
    set_php_config_value "${config}" "db_user" "${DB_USER}"
    set_php_config_value "${config}" "db_pass" "${DB_PASS}"
    set_php_config_value "${config}" "radius_sql_host" "${RADIUS_SQL_HOST}"
    set_php_config_value "${config}" "radius_sql_port" "${RADIUS_SQL_PORT}"
    set_php_config_value "${config}" "radius_sql_db" "${RADIUS_SQL_DB}"
    set_php_config_value "${config}" "radius_sql_user" "${RADIUS_SQL_USER}"
    set_php_config_value "${config}" "radius_sql_password" "${RADIUS_SQL_PASSWORD}"
    set_php_config_value "${config}" "radius_shared_secret" "${RADIUS_SHARED_SECRET}"
    set_php_config_value "${config}" "radius_core_nas_ip" "${RADIUS_CORE_NAS_IP}"
    set_php_config_value "${config}" "mgs_internal_url" "${MGS_INTERNAL_URL}"
    set_php_config_value "${config}" "mgs_service_shared_secret" "${MGS_SERVICE_SECRET}"
    set_php_config_value "${config}" "billing_customer_url" "${APP_URL}/portal-login.php"
    set_php_config_value "${config}" "billing_employee_url" "${APP_URL}/staff-login.php"
    chown www-data:www-data "${config}"
    chmod 640 "${config}"
}

bootstrap_php_db() {
    log "Bootstrapping PHP/MySQL schema"
    run php -l "${WEB_ROOT}/config.php"
    if [[ "${DRY_RUN}" == "1" ]]; then
        return
    fi
    php -r "require '${WEB_ROOT}/config.php'; \$db = app_db(); if (!(\$db instanceof PDO)) { fwrite(STDERR, 'db_unavailable'); exit(2); } echo 'db_bootstrap_ok'.PHP_EOL;"
}

install_mgs_node() {
    if [[ -z "${SOURCE_MGS_ROOT}" || ! -d "${MGS_ROOT}" ]]; then
        warn "MGS root not available; skipping Node MGS install"
        return
    fi
    if [[ "${SKIP_MGS_NPM_INSTALL}" == "1" ]]; then
        log "MGS npm install skipped"
        return
    fi

    log "Installing MGS Node dependencies"
    run bash -lc "cd '${MGS_ROOT}' && npm install"
}

configure_apache() {
    if [[ "${CONFIGURE_APACHE}" != "1" ]]; then
        log "Apache config skipped"
        return
    fi
    log "Configuring Apache virtual host"
    local conf="/etc/apache2/sites-available/superoptic-mgs.conf"
    run tee "${conf}" >/dev/null <<CONF
<VirtualHost *:80>
    ServerName ${APP_DOMAIN}
    DocumentRoot ${WEB_ROOT}

    <Directory ${WEB_ROOT}>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/superoptic-error.log
    CustomLog \${APACHE_LOG_DIR}/superoptic-access.log combined
</VirtualHost>
CONF
    run a2enmod rewrite headers proxy proxy_http
    run a2ensite superoptic-mgs.conf
    run systemctl enable apache2
    run systemctl reload apache2
}

write_systemd_units() {
    if [[ "${SKIP_SYSTEMD}" == "1" ]]; then
        log "systemd units skipped"
        return
    fi

    log "Writing systemd service/timer units"
    run tee /etc/systemd/system/superoptic-mgs-node.service >/dev/null <<CONF
[Unit]
Description=SuperOptic MGS Node service
After=network-online.target mysql.service mariadb.service
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=${MGS_ROOT}
Environment=NODE_ENV=production
ExecStart=/usr/bin/node server.js
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
CONF

    run tee /etc/systemd/system/superoptic-billing-maintenance.service >/dev/null <<CONF
[Unit]
Description=SuperOptic billing maintenance

[Service]
Type=oneshot
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/billing-maintenance.php --apply --sync-radius --queue-notices --channel=auto
CONF

    run tee /etc/systemd/system/superoptic-billing-maintenance.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic billing maintenance every 5 minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
Unit=superoptic-billing-maintenance.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-communication-queue.service >/dev/null <<CONF
[Unit]
Description=SuperOptic communication queue worker

[Service]
Type=oneshot
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/process-communication-queue.php --apply --limit=50 --channel=all
CONF

    run tee /etc/systemd/system/superoptic-communication-queue.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic communication queue every 2 minutes

[Timer]
OnBootSec=1min
OnUnitActiveSec=2min
Unit=superoptic-communication-queue.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-radius-coa-worker.service >/dev/null <<CONF
[Unit]
Description=SuperOptic Radius CoA queue worker

[Service]
Type=oneshot
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/process-radius-session-actions.php --apply --limit=50
CONF

    run tee /etc/systemd/system/superoptic-radius-coa-worker.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic Radius CoA worker every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
Unit=superoptic-radius-coa-worker.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-modem-bot.service >/dev/null <<CONF
[Unit]
Description=SuperOptic Superpilot/modem Telegram bot poller

[Service]
Type=oneshot
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/process-telegram-modem-search-bot.php --once
CONF

    run tee /etc/systemd/system/superoptic-modem-bot.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic modem bot poller every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
Unit=superoptic-modem-bot.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-system-optimizer.service >/dev/null <<CONF
[Unit]
Description=SuperOptic retention and index optimizer

[Service]
Type=oneshot
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/system-maintenance-optimizer.php --apply --json
CONF

    run tee /etc/systemd/system/superoptic-system-optimizer.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic retention and index optimizer daily

[Timer]
OnCalendar=*-*-* 03:20:00
Persistent=true
Unit=superoptic-system-optimizer.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-security-anomaly.service >/dev/null <<CONF
[Unit]
Description=SuperOptic security anomaly scanner

[Service]
Type=oneshot
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/security-anomaly-scan.php --record --live
CONF

    run tee /etc/systemd/system/superoptic-security-anomaly.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic security anomaly scanner every 5 minutes

[Timer]
OnBootSec=3min
OnUnitActiveSec=5min
Unit=superoptic-security-anomaly.service

[Install]
WantedBy=timers.target
CONF

    run tee /etc/systemd/system/superoptic-network-olt-rules.service >/dev/null <<CONF
[Unit]
Description=SuperOptic all-OLT optical and outage ticket rule scanner

[Service]
Type=oneshot
ExecStart=/usr/bin/php ${WEB_ROOT}/tools/process-network-olt-ticket-rules.php --apply
CONF

    run tee /etc/systemd/system/superoptic-network-olt-rules.timer >/dev/null <<'CONF'
[Unit]
Description=Run SuperOptic all-OLT ticket rule scanner every 5 minutes

[Timer]
OnBootSec=4min
OnUnitActiveSec=5min
Unit=superoptic-network-olt-rules.service

[Install]
WantedBy=timers.target
CONF

    run systemctl daemon-reload
    if [[ -d "${MGS_ROOT}" ]]; then
        run systemctl enable --now superoptic-mgs-node.service
    fi
    run systemctl enable --now superoptic-billing-maintenance.timer
    run systemctl enable --now superoptic-communication-queue.timer
    run systemctl enable --now superoptic-radius-coa-worker.timer
    run systemctl enable --now superoptic-modem-bot.timer
    run systemctl enable --now superoptic-system-optimizer.timer
    run systemctl enable --now superoptic-security-anomaly.timer
    run systemctl enable --now superoptic-network-olt-rules.timer
}

verify_install() {
    log "Verification"
    run php -v
    run node -v
    run java -version
    if command -v snmpwalk >/dev/null 2>&1; then
        log "snmpwalk available: $(command -v snmpwalk)"
    else
        warn "snmpwalk not found"
    fi
    if [[ "${DRY_RUN}" != "1" ]]; then
        php "${WEB_ROOT}/tools/device-backup-worker.php" --dry-run --limit=3 --type=profile_snapshot || warn "device backup worker dry-run failed"
        php "${WEB_ROOT}/tools/update-package-smoke.php" || warn "update-package smoke failed"
        php "${WEB_ROOT}/tools/provider-api-smoke.php" || warn "provider-api smoke failed"
        php "${WEB_ROOT}/tools/provider-up-api-smoke.php" || warn "provider-up-api smoke failed"
        php "${WEB_ROOT}/tools/system-maintenance-optimizer.php" --json || warn "system optimizer dry-run failed"
        php "${WEB_ROOT}/tools/security-anomaly-scan.php" || warn "security anomaly dry-run failed"
        php "${WEB_ROOT}/tools/process-network-olt-ticket-rules.php" || warn "network OLT ticket rule dry-run failed"
    fi
}

require_root
detect_sources
install_packages
copy_sources
create_database
write_config
bootstrap_php_db
install_mgs_node
configure_apache
write_systemd_units
verify_install

log "DONE: SuperOptic MGS installed on Ubuntu. FreeRADIUS remains external."
printf 'Web root: %s\nMGS root: %s\nApp URL: %s\nDB: %s:%s/%s\n' "${WEB_ROOT}" "${MGS_ROOT}" "${APP_URL}" "${DB_HOST}" "${DB_PORT}" "${DB_NAME}"
