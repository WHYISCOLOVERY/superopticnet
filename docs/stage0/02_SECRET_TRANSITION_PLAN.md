# Stage 0 secret inventory and transition plan

Captured: 2026-07-19  
Mode: inventory and readiness only  
Credential rotation/revocation: not executed

## Inventory summary

The inventory stores only source name, configuration key, target environment key and readiness booleans. It never emits secret values or hashes.

| Item | Count |
|---|---:|
| Legacy secret sources found | 18 |
| Environment reference ready | 1 |
| Not ready | 17 |
| Unmapped keys | 0 |
| Secret values exposed by inventory | 0 |

Sources found:

- `config.local.php`: 17 entries;
- `mgs_runtime_settings`: 1 entry;
- environment-ready now: database credential only.

Secret categories still requiring a working reference include RADIUS, provider/HMAC, Copilot, reCAPTCHA, Telegram channels, SMS private credential, WhatsApp provider, MGS service and OLT SSO.

## Required reference contract

1. Application code reads secret values only through `app_environment_secret()` or a service-specific secret resolver.
2. Public runtime settings, HTML, JSON responses, logs and audit payloads may expose only `configured`, `source`, `reference`, `last_rotated_at` and health status.
3. The installer writes service environment files with OS-level ACLs and restarts the affected service.
4. A preflight verifies that every required reference resolves inside the actual PHP/worker/Node service account.
5. A cutover smoke validates DB/RADIUS/provider health without printing the value.
6. Only after successful read-back and service health may the legacy source be scrubbed and the old credential revoked.

## Rotation sequence per credential

1. Create a new provider/device credential without revoking the old one.
2. Store the new value in the target environment/secret store.
3. Run service-account visibility and provider health checks.
4. Switch the service reference and restart/drain workers where required.
5. Verify normal traffic and audit correlation.
6. Remove the legacy plaintext copy.
7. Revoke the old provider credential.
8. Record rotation metadata without the value.

Failure at steps 2-5 means rollback to the old reference. The old credential is not revoked until step 5 passes.

## Feature flag

`APP_SECRET_SOURCE_STRICT` has two rollout modes:

- `report`: legacy sources are inventoried and warned about, but runtime is not interrupted;
- `enforce`: production startup fails if a required secret is missing from the approved environment/reference source.

The initial Stage 0 rollout remains `report` because 17 references are not ready. `enforce` is an acceptance gate for the real server after the installer/service environment validation passes.

## Existing tools

- `tools/secret-source-inventory.php`
- `tools/secret-readiness-smoke.php`
- `tools/service-secret-visibility.php`
- `tools/task-secret-visibility.php`
- `tools/secret-cutover-smoke.php`
- `tools/scrub-legacy-secrets.php`
- `deploy/set-superoptic-secrets.sh`
- `deploy/windows/Set-SuperOptic-Secrets.ps1`

## Current verification result

- Inventory execution: passed.
- Secret readiness: failed as expected because only the DB reference is configured in the current CLI environment.
- No credential was rotated, scrubbed or revoked.
- No secret value was written to this document.

