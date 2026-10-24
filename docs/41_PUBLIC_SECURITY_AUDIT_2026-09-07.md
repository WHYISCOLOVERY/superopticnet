# SuperOpticNet public go-live audit — 2026-09-07

## Scope and confidence

This review covers the PHP application in `D:\xampp\htdocs\superopticnet`, its API routes, NOC/MGS integration, billing/provider surface, WhatsApp monitor, deployment templates and the agent-operation bridge. Graphify code-only analysis indexed 3,845 nodes and 8,684 edges; SQL files were not parsed because the local Graphify runtime does not have `tree_sitter_sql`, so SQL relationships were verified from source instead of treated as graph facts. Some graph edges are inferred and must not replace a manual source review.

This is an engineering risk assessment, not a claim that an internet-facing system can be made “100% secure”. Production cutover remains blocked until the P0/P1 items below are closed and the real production environment is tested.

## Changes applied in this review

- Forwarded client/host/protocol headers are now trusted only when `REMOTE_ADDR` is in `TRUSTED_PROXY_IPS` (default `127.0.0.1,::1`). This closes spoofed-IP rate-limit bypass and internal health-detail disclosure.
- HTTPS redirects now select a configured application host (`APP_ALLOWED_HOSTS`/configured URLs), not an arbitrary direct `Host` header; the cPanel package also has an explicit canonical-host allowlist.
- Removed the device-specific SNMP community fallback from source code. Device secrets must come from the encrypted credential vault or environment defaults.
- Removed `public`/`private` SNMP defaults from NOC forms and scan helpers. An empty value now means “configure the vault/environment”; no weak community is silently written.
- Restricted update-package upload to real uploaded files, bounded size, ZIP MIME types, randomised filenames and private file permissions.
- Expanded Apache/cPanel deny rules to cover `.git`, internal collaboration/config/output/Graphify/runtime directories and backup/editor artefacts. The intended production document root is still the generated `output/cpanel-superoptic-site/public_html`, not the repository root.
- Public health/status details are disabled by default (`HEALTH_DETAIL_ENABLED=0`); authenticated `system-health.php` is the diagnostic surface.

## Findings by severity

### P0 — must close before public exposure

1. **Credential exposure and rotation.** The working tree contains local secret sources (`.env`, `config.local.php`) and the user has previously pasted device/provider credentials into chat. Even with deny rules, assume those values are compromised: rotate DB, RADIUS, Telegram, provider HMAC/API, MGS, SSH and SNMP credentials; issue new service tokens; invalidate old tokens; verify no secrets exist in git history or backup archives. Never upload the repository root as a public document root.
2. **Production environment is not yet proven.** Run `tools/production-readiness-check.php --production` on the real service host (not a local override), then perform an external HTTPS scan and authenticated route smoke test. A local XAMPP pass is not production evidence.
3. **Live mutation gates.** Keep device command/config, RADIUS/CoA, payment apply, Telegram/SMS/WhatsApp delivery and Crew live-submit behind explicit approval/idempotency/audit gates. The current contracts require this; do not enable a broad “agent can do everything” token on a public endpoint.

### P1 — high risk / required for go-live

1. **Secrets in legacy storage.** `tools/secret-source-inventory.php` currently reports legacy secret sources (including local config and runtime settings). Production must report zero local-config secret fields and zero secret-like runtime DB rows. Complete vault/environment migration and blank legacy columns after verification.
2. **Provider API restrictions.** Payment routes need per-provider credentials, HMAC, replay/idempotency protection and production IP allowlists. Set `provider_api_require_hmac=1`, configure provider-specific keys, and keep legacy shared credentials disabled.
3. **ZIP extension and update verification.** The local smoke test skipped because `ZipArchive` is unavailable. Install/enable the production ZIP extension, require signed/checksummed update manifests, test zip-slip rejection and never apply an unverified package.
4. **Internal surface separation.** Deploy only the generated cPanel `public_html` package or the Nginx gateway. Keep DB dumps, migrations, tools, Graphify output, `.git`, agent collaboration files, backups and device integration code outside the public document root.
5. **Agent service-token blast radius.** `CREW_INGEST_TOKEN` and department service tokens are powerful internal credentials. Store them only in service environment/secret manager, scope modules/actions, rotate them, rate-limit ingress, and keep proposal-ingest non-executing.
6. **External delivery interlock.** Leave `COMMUNICATION_EXTERNAL_SEND_ENABLED=0` until provider health, consent/opt-out, recipient scope and audit verification are complete. Do not enable real SMS/Telegram/WhatsApp from smoke tests.
7. **Network integration safety.** SNMP/SSH probes now require NOC authorization and scoped device objects, but production must use least-privilege read-only accounts, SSH host-key verification, management VLAN ACLs and a separate approval worker for writes.

### P2 — hardening and operational quality

1. The CSP still uses `'unsafe-inline'` and external CDN sources. Migrate inline scripts/styles to nonces or hashes and pin/self-host third-party assets.
2. Add an explicit session idle timeout and absolute lifetime for employee accounts, plus MFA for administrators and NOC roles.
3. Add central audit alerts for repeated auth failures, service-token failures, provider HMAC failures, vault decrypt errors and denied NOC scope checks.
4. Keep health/status endpoints minimal. The forwarded-header fix prevents private-network spoofing, but public status should expose availability only, not business counts or implementation paths.
5. Ensure PHP production settings use `display_errors=0`, `log_errors=1`, strict transport security, secure cookies and a read-only DB user for public health/status.

## Module-by-module result

| Module | Current result | Remaining gate |
|---|---|---|
| Public site / cPanel package | Build script allowlists public files and excludes internal code; deny rules strengthened | Build and deploy only `public_html`; rotate ingress HMAC keys; external cache/header test |
| Auth / portal | CSRF, session regeneration, password hashing, login throttling and role checks are present; security smoke passed | MFA, idle timeout, production cookie/HTTPS test |
| Agent/Copilot | Employee/session and CSRF checks exist; service endpoints are proposal/read scoped | Keep execution approval-only; bound provider latency and token scopes |
| NOC/MGS/SNMP/SSH | Service authorization and object scope smoke passed; credential vault exists | Vault migration, read-only device accounts, host-key verification, ZIP extension, live device test |
| Billing/RADIUS/payments | Idempotency/audit primitives and provider auth exist | Production HMAC/IP allowlist, DB transaction/duplicate tests, no GET side effects |
| Messaging/WhatsApp | Worker tokens, consent/queue gates and delivery guards exist | Keep external-send off until real provider test and opt-out audit |
| Inventory/CRM/HR/Finance | Source and smoke coverage exists; role modules are separated | Run full regression and verify maker-checker/audit for every write |
| Deployment | Apache/Nginx templates include TLS/rate limits/security headers | Apply config on real host, `nginx -t`/Apache config test, external scan, backups/rollback |

## Verification performed

- PHP syntax: changed security/config/NOC/upload files passed `php -l`.
- `cross-module-defect-correction-smoke.php`: passed.
- `portal-login-security-smoke.php`: passed.
- `portal-token-security-smoke.php`: passed.
- `device-credential-vault-smoke.php`: passed.
- `noc-service-authorization-smoke.php`: passed (including service guards before device I/O).
- `public-security-audit-smoke.php`: passed (public boundary, trusted proxy/host gates, upload hardening and health detail defaults).
- `update-package-zip-slip-smoke.php`: skipped because local PHP lacks `ZipArchive`; this is a production blocker, not a pass.
- Nginx gateway validator: passed its 26 static checks.
- Secret source inventory: ran without printing values; it reported legacy sources that must be migrated before production.

## Go-live sequence

1. Rotate all exposed credentials and remove local secret files from any deploy artifact/history.
2. Run production readiness against the real environment; fix every critical/warning result.
3. Enable PHP ZIP extension and run the update-package/zip-slip tests.
4. Deploy the generated public package or Nginx gateway with a private backend root.
5. Run external HTTPS/header/path probes (`.env`, `.git`, `config.local.php`, `/tools`, `/docs`, `/data`, `/output` must return 404/403).
6. Run authenticated smoke tests for each role/module and payment/provider replay tests.
7. Perform a controlled read-only NOC probe, then a separately approved write in a maintenance window; verify audit and rollback.
8. Only after evidence is archived, enable required external delivery or live agent actions one capability at a time.
