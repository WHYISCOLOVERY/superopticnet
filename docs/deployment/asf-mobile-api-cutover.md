# MGT mobile API cutover

Status checked: 2026-10-14 (Asia/Baku)

## Public endpoint

- Production management/API host: `mgt.superoptic.net`
- Public APK/download host: `asf.superoptic.net`
- Current document root: `/home/superop2/public_html/app`
- DNS resolves to the shared-hosting address.
- HTTPS is active.
- The document root uses CloudLinux PHP 8.2 through `.htaccess`.
- Directory listing is disabled.
- No application secrets, internal API source, database dump, or local `.env`
  file has been uploaded.

## Verified mobile build

- Local API build installed on the connected Samsung device.
- Online dashboard loaded successfully.
- With ADB reverse removed, the cached dashboard loaded in offline mode.
- Restoring connectivity returned the dashboard to online mode.
- Stale background GPS telemetry was purged.
- Background GPS now starts only for an active duty session and stops when the
  server reports that duty is closed.

## Production topology required

The mobile endpoints directly depend on the main SuperOptic database and the
internal PHP libraries. The public-site hosting package explicitly excludes
internal API code, MGS configuration, database dumps, and secrets. Therefore,
the production API must run beside the main management system/database, or the
`mgt` hostname terminates on that server. The `asf` hostname remains isolated
for APK/download publication.

Planned production URL:

`https://mgt.superoptic.net/api/mobile/v1`

Required cutover steps:

1. VM 201, Apache, PHP, MySQL and TLS were verified.
2. The release was staged separately and all PHP files passed syntax checks.
3. Production mobile secrets were generated in the protected service environment.
4. Database migrations were applied after a snapshot and compressed SQL backup.
5. The mobile API, inventory lifecycle, ticket resolution and single-owner bonus
   smoke suites passed on the production database with temporary records cleaned up.
6. The verified release was activated at `/var/www/superopticnet`; the previous
   release remains available for rollback.
7. Build the APK with the MGT HTTPS base URL, install it on the test device, and
   repeat online/offline/synchronization tests without ADB reverse.

## Live status

`https://mgt.superoptic.net/api/mobile/v1/app/version-check.php` is reachable
over the Internet. Sensitive paths such as `.env`, `config.local.php`, `tools`,
`db` and `data/private` return HTTP 403.
