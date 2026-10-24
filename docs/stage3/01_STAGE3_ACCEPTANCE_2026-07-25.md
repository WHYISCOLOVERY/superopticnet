# Stage 3 - Role-Scoped Module Workspaces Acceptance

Date: 2026-07-25
Status: Pilot enabled

## Scope delivered

Stage 3 targets from `32_UNIFIED_UX_AUDIT.md` sections 3.1, 6.1 and 6.2 that
could be closed without waiting for the deeper page merges:

### 1. Section read-scope hardening (always on, not flag-gated)

The audit confirmed `operations-center.php` sections `noc`, `olt`, `bots` and
`search`/`overview` data blocks received no module read scope.

- `app_portal_request_module()` now maps `section=noc|olt|network` to the
  `network` module and `section=bots` to `ai`, so `billing_require_employee()`
  returns 403 for roles without that module (defense before the redirect to
  `noc-devices.php`).
- Universal search result blocks are scoped by module access:
  customers require `billing`, tickets require `messaging`, RADIUS and VLAN
  results require `network`.
- Overview data blocks are scoped the same way: recent payments (`billing`),
  recent tickets and messages (`messaging`), recent RADIUS sessions
  (`network`). Admin roles keep full visibility because
  `app_portal_employee_module_keys()` returns every module for them.

### 2. Feature-gated workspace visibility (`stage3_module_workspaces`)

- New migration `20260725_040_stage3_module_workspaces` seeds the disabled
  flag; `tools/stage3-feature-cutover.php --enable|--disable` controls it.
- New `workspace-lib.php`:
  `app_stage3_workspaces_enabled()` and
  `app_stage3_filter_workspace_cards()` (cards may declare a `module` key;
  cards without one stay visible; flag off returns the list unchanged).
- `operations-center.php` module cards and the legacy module map now declare
  their module and are filtered per role when the flag is on, so each role
  only sees its own workspaces on the Operations Center overview.
- WhatsApp Monitor: the unused `screen-wall` view leaves the menu when the
  flag is on (audit target "Menyudan cixar"), while the direct URL keeps
  working as an alias (`$allowedViews` still uses the full tab list).

### 3. Already-canonical surfaces confirmed

- The Billing two-level menu is already deduplicated
  (`billing_nav_groups()`; the legacy duplicate nav stays disabled in code).
- The main portal module grid was already role-filtered
  (`app_portal_employee_module_keys()` + `tools/portal-module-access-smoke.php`).
- Default 50 pagination and the automatic filter contract are covered by the
  existing passing `automatic-filter-contract-smoke`.

## Verification

- `tools/stage3-workspace-contract-smoke.php`:
  29 pass / 0 fail with the flag off, 32 pass / 0 fail with the flag on
  (section mapping, role isolation, card filtering, scope markers,
  screen-wall alias, migration/cutover contracts).
- `tools/portal-module-access-smoke.php`: ok.
- `tools/operations-module-smoke.php`: ok.
- `tools/whatsapp-monitor-module-smoke.php`: passed.
- PHP lint clean on all touched files.
- HTTP sanity: `operations-center.php` (incl. `section=bots|search`),
  `whatsapp-monitor.php`, `billing-customers.php` respond without PHP errors.
- Rollback cycle verified: disable flag -> migration rollback -> reapply ->
  re-enable.

## Out of scope / follow-up (Stage 4+)

- Merging duplicate billing pages (`balances`, `services`, `documents`) into
  customer-list filter presets requires those presets to exist first.
- Message Center / WhatsApp view consolidation (`cases`, `performance`,
  `audit` into card filters) is the Stage 4 Message Center redesign.
- Inventory tab consolidation into saved stock filters.
- Per-role Playwright E2E screenshots for the scoped overview.
