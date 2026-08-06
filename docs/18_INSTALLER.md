# 18 Installer

## Purpose

Install and bootstrap SuperOptic MGS on Windows or Ubuntu while FreeRADIUS remains separately managed. The installers configure PHP, database access, required tools, scheduled jobs, web paths, and service-scoped secrets.

## Entry Points

- Ubuntu installer: `deploy/ubuntu-install-superoptic-mgs.sh`
- Ubuntu secret bootstrap/rotation: `deploy/set-superoptic-secrets.sh`
- Windows installer: `deploy/windows/install-superoptic-mgs.ps1`
- Windows secret bootstrap/rotation: `deploy/windows/Set-SuperOptic-Secrets.ps1`
- Deterministic environment check: `php tools/secret-readiness-smoke.php`

Run installer dry-runs before making changes:

```bash
DRY_RUN=1 bash deploy/ubuntu-install-superoptic-mgs.sh
```

```powershell
.\deploy\windows\install-superoptic-mgs.ps1 -DryRun
```

Dry-run mode never prompts, persists values, changes firewall rules, installs packages, or prints secret values. Missing dry-run inputs are reported only as counts.

## Secret Bootstrap

The bootstrap manages 36 canonical values. External credentials are collected with terminal echo disabled: application and RADIUS database passwords; the RADIUS router secret, SSH password and SNMP community; DeepSeek, PG365 SMS and WhatsApp credentials; the base Telegram token plus network, customer, finance, warehouse, backup, diagnostics and modem-search bot tokens; reCAPTCHA and MyGov client secrets. Internal credentials such as test/import tokens, SSO and user-sync secrets, WhatsApp bridge keys, ClickHouse password, portal/provider signing keys, and MGS service/JWT/master/webhook secrets are generated cryptographically when absent.

The authoritative canonical list is validated by `tools/secret-readiness-smoke.php --contract --json` against both platform bootstraps. The bootstrap also creates compatibility aliases for the PHP and Node runtimes: `RADIUS_SQL_PASSWORD`, `RADIUS_SHARED_SECRET`, `SMS_PROVIDER_USER`, `SMS_PROVIDER_PASSWORD`, `MGS_SERVICE_SHARED_SECRET`, `SERVICE_SHARED_SECRET`, `JWT_SECRET`, `SECRETS_MASTER_KEY`, `PORTAL_SHARED_SECRET`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_BOT_TOKEN`, `WHATSAPP_PROVIDER_API_KEY`, and `SMS_PROVIDER_API_KEY`.

`DATABASE_URL` and `RADIUS_DATABASE_URL` are derived without printing them from the canonical passwords plus non-secret host/port/database/user metadata. The installer supplies that metadata. Standalone rotation retains metadata from the existing Ubuntu service file or Windows machine environment.

Secret values must not be supplied as command-line arguments. For unattended installation, inject the canonical variables into the installer process from an approved secret manager, then use `NONINTERACTIVE=1` on Ubuntu or `-NonInteractive` on Windows. Missing, short, multiline, placeholder, or inconsistent values fail before any secret write.

Ubuntu stores the complete environment in `/etc/superoptic-mgs/superoptic.env`, owned by `root:root` with mode `0600`. Apache receives that file through systemd. Each background service receives a separate root-only file under `/etc/superoptic-mgs/services/` containing only its required keys. Service processes cannot read either the complete file or another service's scoped file.

Windows stores the complete environment in machine-scoped OS environment variables. Run the installer or bootstrap from an elevated PowerShell session. The helper also updates the current process so installer validation sees the new values immediately. Machine environment variables are not a general-purpose vault; use a dedicated server, minimize local logon rights, and do not grant the web/task account administrative access.

Generated `config.local.php` contains non-secret settings and blank secret fields. Environment values take precedence at runtime. If an existing `config.local.php` contains old secrets, rotate first and use `OVERWRITE_CONFIG=1` or `-OverwriteConfig` during a controlled maintenance window to scrub it. Do not create a populated `.env` in the repository or web root.

The installers do not invoke the legacy MGS `setup-db.cmd`, because that path writes and backs up populated `.env` files and passes credentials on command lines. MGS migrations run directly with the protected process/service environment. A pre-existing MGS `.env` is not deleted automatically; the installer warns, environment values take precedence, and service-account access to `.env*` is blocked while the file is removed after rotation or moved to approved encrypted storage.

## Validation

Validate the stored secret set without displaying values:

```bash
sudo bash deploy/set-superoptic-secrets.sh --validate-only
sudo systemd-run --wait --pipe --collect --uid=www-data \
  --property=EnvironmentFile=/etc/superoptic-mgs/superoptic.env \
  /usr/bin/php /var/www/superopticnet/tools/secret-readiness-smoke.php
```

```powershell
.\deploy\windows\Set-SuperOptic-Secrets.ps1 -ValidateOnly
php.exe .\tools\secret-readiness-smoke.php
```

The PHP smoke is deterministic and performs no database, provider, router, or network calls. It exits `0` when every canonical value and compatibility alias is ready, or `2` when validation fails. Output contains names and status only.

## Legacy Secret Cutover

Do not copy old file/database secrets into the new environment when they have been exposed or their history is uncertain. Rotate them at the provider/device first, store the replacement through the bootstrap, restart long-running services, and only then scrub legacy sources.

Inventory source names and environment readiness without displaying values:

```powershell
php.exe .\tools\secret-source-inventory.php
php.exe .\tools\scrub-legacy-secrets.php
```

The scrub command is a dry-run unless `--apply` is supplied. Apply is all-or-nothing: every detected legacy key must have a mapped, non-empty environment value and the database must be available. A blocked apply exits `2` without changing `config.local.php` or `mgs_runtime_settings`.

After rotation and service restart:

```powershell
.\deploy\windows\Test-SuperOptic-ServiceEnvironment.ps1
php.exe .\tools\scrub-legacy-secrets.php --apply
php.exe .\tools\production-readiness-check.php --production
```

The Windows visibility command must run elevated. It verifies both local Apache and an ephemeral `LOCAL SERVICE` scheduled task. The HTTP probe creates a token-protected temporary PHP file, requests it only through the local Apache URL, removes it immediately, and stores value-free readiness evidence for 15 minutes. The scrub tool writes no plaintext backup and never prints secret values. It deletes matching runtime rows first and atomically replaces `config.local.php` with the same non-secret configuration plus blank legacy secret fields.

## Rotation

Ubuntu `--rotate` changes only the canonical secrets explicitly supplied in the current process and retains every unrelated value. It refuses an empty rotation. Scoped files and aliases are regenerated from the resulting set. Arrange provider-side and device-side changes first, run the bootstrap in a maintenance window, validate, then revoke old credentials.

Ubuntu interactive rotation and controlled service restart:

```bash
sudo env SMS_PROVIDER_PRIVATE_KEY='value-from-approved-secret-manager' \
  bash deploy/set-superoptic-secrets.sh --rotate --restart-services
sudo bash deploy/set-superoptic-secrets.sh --validate-only
```

Windows interactive rotation:

```powershell
.\deploy\windows\Set-SuperOptic-Secrets.ps1 -Rotate
.\deploy\windows\Set-SuperOptic-Secrets.ps1 -ValidateOnly
```

After Windows rotation, use the elevated installer flow to stop and start the complete Apache runtime and recreate scheduled jobs under `NT AUTHORITY\LOCAL SERVICE`. A worker reload is not sufficient because long-running processes retain their old environment. The installer then runs `Test-SuperOptic-ServiceEnvironment.ps1`; production readiness remains blocked unless both local Apache and an ephemeral `LOCAL SERVICE` task can see the complete canonical and alias set. Never log old/new values or include them in tickets.

Recommended rotation order is database application/RADIUS SQL credentials, RADIUS router shared secret, router SSH and SNMP credentials, provider/API tokens, then MGS service secret. Use overlapping provider credentials where supported to avoid downtime.

`DB_ADMIN_PASS` is accepted by the Ubuntu installer only as a transient process environment variable for remote database bootstrap. It is never persisted to the service environment because the application does not need administrative database access.

## Least Privilege

- Ubuntu secret files are readable and writable only by `root`; systemd injects the relevant values into each service.
- Ubuntu workers run under separate `superoptic-*` users with `www-data` only as the file-data group.
- Node dependencies are installed as an unprivileged build user. Node.js 20 comes from the signed Ubuntu Snap Store package, with no `curl | bash` execution.
- Database DDL grants exist only during schema migration. An `EXIT` cleanup revokes them after interrupted or failed deployment, and runtime configuration is switched to `db_bootstrap_mode=off`.
- The Windows MGS startup task runs as `LOCAL SERVICE`, not `SYSTEM`; installer ACLs grant read/execute on the MGS tree, deny that account access to legacy `.env*` files, and grant modify access only under `data`, `logs`, and `runtime`.
- The Ubuntu database app user is created only for `DB_APP_ALLOWED_HOSTS` (default `localhost,127.0.0.1`); no `%` account is created.
- Database grants are limited to the application database and exclude global administration, user/grant management, file access, process control, replication, and shutdown privileges.
- Keep the RADIUS SQL user separate from the portal DB user and grant only the tables/actions required by the external FreeRADIUS SQL module.
- Use a read-only SNMP identity/community wherever device support permits it. Use a dedicated router automation account restricted by source address and command policy.
- Provider tokens should be scoped to the required sender/account and should not carry account administration permissions.

## RADIUS Ports And Firewall

RADIUS authentication and accounting default to `1812/UDP` and `1813/UDP`. Installers validate that both ports are in range and distinct, warn when non-standard ports are selected, and can inspect local listeners when `RADIUS_SERVER_HOST`/`-RadiusServerHost` identifies localhost.

UDP socket creation or DNS resolution does not prove end-to-end RADIUS reachability. Use an authenticated test request from an approved NAS during cutover. The Ubuntu installer configures UFW by default: deny incoming, allow outgoing, preserve the detected SSH port, and allow HTTP/HTTPS. RADIUS ports are opened only when `RADIUS_ALLOWED_NAS_SOURCE` is explicitly provided and FreeRADIUS is local. Set `CONFIGURE_UFW=0` only when an external host firewall is managed separately. The Windows installer does not create Windows Firewall rules.

Firewall policy must allow `1812/UDP` and `1813/UDP` only from explicit NAS source addresses to the external RADIUS server. Do not expose either port to the public internet or a broad LAN CIDR. CoA/Disconnect on `3799/UDP`, when enabled separately, must be restricted in the reverse direction to the approved RADIUS server and NAS addresses.

## Installer Safety

- Installer reruns retain an existing managed secret environment unless explicit rotation is requested.
- All secret values are collected and validated before a write begins.
- Ubuntu writes the environment through a same-directory temporary file and atomic rename.
- Windows attempts to restore prior target values if an OS environment update fails.
- Production data/config overwrite requires an explicit flag.
- Update Manager must use versioned packages and application/database restore points.
- Logs may show step names, environment key names, endpoints, and failures, but never secret values.

## Future Requirements

- Docker deployment.
- FastAPI services.
- React frontend build.
- PostgreSQL, ClickHouse, Redis, and RabbitMQ.
- Nginx gateway.
- Prometheus, Grafana, and Loki.
