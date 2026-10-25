# Security Audit — 2026-09-24 (full red-team pass)

Scope: full 12-vector red-team security analysis of the whole SuperOptic ISP portal
(carrier-grade: customer portal, staff/employee panel, billing, RADIUS, CGNAT, provider
payment APIs, MyGov/MGS SSO bridges, WhatsApp/Telegram integration, lawful-intercept export).
No git repo present at the audit root — the one hardening change was applied directly and
verified with live HTTP smoke tests (no commits).

## Verdict

**Mature, security-hardened codebase. No exploitable vulnerabilities found.** The application
shows deliberate, consistent security engineering throughout (parameterized data layer, a single
hardened config core, HMAC-signed provider APIs, per-action authorization, enumeration-safe
auth). One low-severity defense-in-depth gap was closed; two forward-looking hardening items are
recommended below.

## Hardening applied

### LOW / defense-in-depth — fixed
**`data/` generic `.json` files were only shielded by a mod_rewrite rule.** The project-root
`.htaccess` blocks the whole `data/` directory with `RewriteRule ^data(/|$) - [F,L]`, and its
`FilesMatch` denies `.jsonl`/`.sql`/`.env`/`config.local.php` — but **not** generic `.json`. So
`data/rate-limits.json`, `data/system-settings.json` and especially `data/cache/billing_*.json`
(short-TTL caches that can contain customer names, debts and recent payments) were protected only
while `mod_rewrite` is enabled. If a host ever served the doc-root with `mod_rewrite` off, those
files would be web-readable. **Fix:** added `data/.htaccess` with `Require all denied` (plus the
legacy `Deny from all` fallback), matching the double-guard pattern the root `.htaccess` already
uses. This is pure defense-in-depth — `data/` is read from disk by PHP, never fetched over HTTP,
and was already `[F,L]`-blocked when `mod_rewrite` is on. Verified live: homepage `200`,
`api/health.php` `200`, `data/news.json` / `data/system-settings.json` / `data/cache/` now `403`.

## Reviewed — no issues found

- **SQL injection — none.** The data layer (`db.php`, ~295 KB) is 100% PDO prepared statements
  with named placeholders. A whole-tree grep for a superglobal (`$_GET/$_POST/$_REQUEST/$_COOKIE`)
  interpolated into a SQL string returned **zero** matches. Every dynamic fragment that *is*
  concatenated is server-controlled: `LIMIT`/`OFFSET` are always `max(1, min(N, (int)…))`; table
  and column identifiers come from hardcoded config specs (guarded by `app_analytics_table_exists`)
  or ternaries between two literals; the billing "operational guard" fragments
  (`app_billing_operational_payment_sql`/`_customer_sql`/`_debt_sql`) return static strings built
  from a hardcoded alias, never user input.
- **Authentication — comprehensive.** Every `api/` endpoint is gated: portal session
  (`app_portal_current_user` + role check), employee + per-module capability
  (`billing_require_employee`), provider APIs (`app_provider_authenticate` /
  `app_up_provider_authenticate`), or attendance-device auth. The public endpoints
  (`api/public/website-lead.php`, `api/health.php`) are intentionally public and expose no
  sensitive data.
- **Provider payment APIs — strongly authenticated.** `app_provider_authenticate` verifies a
  registry bearer token with scope + source-IP allowlist + provider match, then an **HMAC
  signature** (`X-Superoptic-Timestamp` with a ±300 s replay window + `X-Superoptic-Signature`
  checked via `hash_equals` over timestamp+body). Payment ingestion adds an idempotency key and a
  duplicate-external-id check, so a replay cannot double-post a payment.
- **Authorization / IDOR — clean.** `portal-actions.php` derives the actor from the session
  (`app_portal_current_user`), never from a request field. Every privileged branch carries its own
  role gate in the condition (`role === 'employee' && app_portal_is_admin($user)`); account
  deletion additionally requires `app_portal_is_super_admin`, refuses to delete the protected
  super-admin account or the actor's own account, and requires an audit reason. Customer
  self-service actions act only on `$user['id']`.
- **Login & password recovery — hardened.** Credentials are bcrypt (`password_verify` in
  `db.php:2364`; password change re-verifies the current password). Login is rate-limited to 3
  failures / 15 min per IP **and** per account. Password recovery is enumeration-safe (identical
  generic response + `usleep` timing jitter whether or not the account exists), issues a CSPRNG
  6-digit code delivered only to the account's stored phone, stores **only hashes** of the token /
  code / destination / IP, and caps attempts (5) with a 10-min expiry and single-use consumption.
- **CSRF — enforced.** Token is 32 random bytes, verified with `hash_equals`. 40 state-changing
  pages call `app_verify_csrf` and reject on failure before mutating.
- **XSS — output encoded.** `e()` wraps `htmlspecialchars(…, ENT_QUOTES)` (plus a mojibake-repair
  output buffer). No raw `echo`/`<?=` of a superglobal into HTML was found; request values are read
  into filter/form arrays and re-rendered through `e()`.
- **Secrets — externalized.** Loaded from `.env` / `config.local.php` via `app_setting`; no
  hardcoded credentials in tracked PHP. The root `.htaccess` denies `config.local.php`, `.env*`,
  `.jsonl`, `.sql`, backups and dotfiles, and `[F,L]`-blocks `data`, `config`, `db`, `tools`,
  `tmp`, `deploy`, `output`, `whatsapp-bridge`, `.git`, `.claude`.
- **Dangerous functions — no reachable injection.** `shell_exec`/`proc_open` hits are (a) CLI-only
  smoke tests under `tools/`, (b) `system-health.php` — Windows-local only (skipped on the Linux
  production server), with PowerShell single-quote escaping and hardcoded task names, or (c)
  `app_mgs_run_process` — `proc_open($arrayCommand, …, ['bypass_shell' => true])`, i.e. array-form
  with the shell disabled, so no argument can be interpreted as a shell command.
- **Lawful-intercept export** (`tools/law-enforcement-export.php`) is `PHP_SAPI === 'cli'`-gated
  (403 over HTTP) — not web-reachable; arguments come from `argv` with requester/reason/approver
  audit fields.
- **Session & transport.** Sessions are `httponly` + `SameSite=Lax` + `use_strict_mode` +
  `cookie_secure` on HTTPS, regenerated on sign-in/out. Security headers include
  `X-Frame-Options: SAMEORIGIN`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, CSP and HSTS.
  `X-Forwarded-*` headers are trusted only from configured proxy CIDRs, preventing client-IP
  spoofing of rate limits and host-header redirects.
- **SSRF surface limited.** The SSO bridges (`portal-bridge.php`, `mygov-auth.php`) are
  redirect-based and make no server-side fetch of a user-controlled URL; outbound HTTP
  (`http_request*`) targets come from admin config (billing/OLT/Telegram), the same trust model as
  any admin-configurable integration URL.

## Recommendations (hardening, not vulnerabilities)

1. **CSP `script-src 'unsafe-inline'`** (`config.php` `app_send_security_headers`) weakens the CSP
   as an XSS backstop. Output encoding via `e()` is the primary defense and is solid, but moving to
   a nonce-based `script-src` would make CSP a real second layer. Medium effort (inline handlers
   must move to nonced blocks).
2. **Replicate the static-file denials on the nginx tier.** The nginx gateway
   (`deploy/nginx/…`) is a proxy/upstream design; the `data/` / `config/` / `.env` / dotfile
   denials currently live in the Apache `.htaccess`. Whichever server serves the PHP doc-root
   under nginx should carry an equivalent `location ~ ^/(data|config|db|tools|tmp|deploy)/ { return 404; }`
   and `location ~ /\.(?!well-known) { return 404; }` so the protection is not Apache-only.
