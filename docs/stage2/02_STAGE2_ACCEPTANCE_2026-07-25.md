# Stage 2 - Shared Design System Acceptance

Date: 2026-07-25
Status: Pilot enabled

## Delivered

- `css/design-system.css` and `script/design-system.js` are loaded on the
  pilot surfaces: `portal.php`, `admin-users.php`, `billing-customers.php`,
  `operations-center.php` (plus `mgs-integration.php` and
  `network-definitions.php` for confirmation coverage).
- `design-system-lib.php` now resolves the database handle itself
  (`app_design_system_enabled()` / `app_design_system_body_attribute()` accept
  a nullable PDO), so pilot pages emit `data-design-system="v2"` with one call.
- Migration `20260723_030_stage2_design_system` is applied and the
  `stage2_design_system` flag is enabled at 100% via
  `tools/stage2-feature-cutover.php --enable`.
- All native `confirm()` handlers were replaced by the shared accessible
  dialog contract (`data-ui-confirm` + `data-ui-confirm-title`):
  - `admin-users.php` account delete (2 forms);
  - `mgs-integration.php` custom bot delete (2 forms);
  - `network-definitions.php` real device apply (1 form).
- Runtime bug fixed in `script/design-system.js`: dynamically added forms that
  themselves match the dirty-form selector are now initialized (the
  MutationObserver previously only scanned descendants of added nodes).

## Verification

- `tools/stage2-design-system-contract-smoke.php`: 41 pass / 0 fail
  (tokens, component markers, pilot wiring, zero native confirms,
  migration/cutover contracts, DB flag state).
- Browser fixture (`tools/render-stage2-design-system-fixture.php` +
  `tmp/stage2-design-system-fixture.html`):
  toast render, confirm dialog open/cancel, modal open + Escape close,
  dirty-form tracking and dirty-navigation guard all verified in a live
  browser session.
- `tools/mobile-layout-smoke.php`: ok.
- `tools/automatic-filter-contract-smoke.php`: passed (2 consecutive runs).
- `tools/billing-ui-smoke.php`: all checks ok.
- PHP lint clean on every touched file; `node --check` clean on
  `script/design-system.js`.

## Rollback verified

- `tools/stage2-feature-cutover.php --disable` turns the runtime off
  (`runtime_enabled=false`, body attribute no longer emitted).
- `tools/run-portal-migrations.php --rollback=20260723_030_stage2_design_system`
  removes the disabled flag row; reapply + `--enable` restores the pilot.
- The migration refuses to remove an enabled flag by design.

## Out of scope / follow-up

- Inline-style debt (existing pages) remains; Stage 2 added none.
- Module information architecture, duplicate route removal, Message Center
  redesign and Agent Workspace remain later stages (Stage 3+).
- Focus-trap behavior verified via keyboard contract in fixture; a full
  Playwright accessibility pass on authenticated pages is still pending.
