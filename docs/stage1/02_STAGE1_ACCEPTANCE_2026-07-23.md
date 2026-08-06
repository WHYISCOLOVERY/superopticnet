# Stage 1 Acceptance - 2026-07-23

## Decision

Stage 1 service and data correctness scope is implemented and accepted locally.
All four Stage 1 feature flags are enabled at 100 percent.

This acceptance does not include the later large UX refactor, Message Center
redesign, unified double-entry ledger, or Agent Workspace.

## Database Baseline And Backup

- Pre-migration backup:
  `tmp/pre-stage1-migration-20260723-081019/superoptic_portal.sql`
- Backup size: `276983364` bytes
- Backup SHA-256:
  `98D5E1989FD36B5BFD5D5E4B92315AB39F98630D7B151493352F9CF3A1511A44`
- Migration:
  `20260723_020_stage1_service_data_contracts`
- Migration SHA-256:
  `1359846EE2F51AC3AC76B3E6A6B635D507CA69507713C196FEF38BAF79C2671C`
- Registry status after final verification: `applied`

## Applied Migration

The migration adds:

- canonical source identity, idempotency, correlation, employee and payload
  fields to `finance_entries`;
- source document and Finance entry links to `finance_debt_ledger`;
- persistent e-invoice batch, request, row and document identities;
- serialized asset return and inspection idempotency/correlation fields;
- repair cases and immutable repair transition events;
- four disabled-by-default Stage 1 feature flags.

The migration `down` action fails closed if Stage 1 business data exists.

## Enabled Feature Flags

All flags are enabled with `rollout_percent = 100` and
`source = stage1_cutover`:

- `stage1_finance_ledger`
- `stage1_e_invoice_atomic_import`
- `stage1_inventory_repair_contract`
- `stage1_statistics_date_contract`

Cutover command:

```powershell
D:\xampp\php\php.exe tools\stage1-feature-cutover.php --enable
```

## Implemented Contracts

### Permissions

- Unknown dispatcher actions are denied by default, including for admins.
- NOC scan/create legacy routes now use the authorized service boundary.
- Finance, HR and Inventory action maps include the Stage 1 write operations.
- NOC service authorization checks role, action and object scope before I/O.

### Finance, HR And E-Invoice

- Manual and source Finance records use `finance_entries` as the canonical
  income/expense source.
- Source writes require role authorization, source identity, idempotency key
  and correlation ID.
- HR payroll, leave and travel costs post to Finance through the service layer.
- Payroll postings preserve gross, net, employee tax/contribution and employer
  contribution breakdown in `payload_json`.
- E-invoice import uses a persistent request/batch registry.
- A repeated request returns the existing result.
- A failed multi-row import rolls all business rows back together and keeps a
  failed batch state for diagnosis.
- E-invoice Finance references use a deterministic collision-safe document hash.

### Inventory

- Serialized returned assets enter quarantine before inspection.
- Inspection produces explicit working, repair, RMA or scrap disposition.
- Repair transitions follow a state machine and produce immutable events.
- Completed repair closes the return and restores the asset to warehouse state.
- Return, inspection, repair creation and transitions are idempotent.

### Statistics

- Custom dates require strict `Y-m-d`.
- Reversed ranges return `reversed_custom_date_range`; they are not silently
  swapped.
- Date filters use inclusive local dates and half-open datetime boundaries.
- The application timezone and UTC boundaries are returned explicitly.
- Future-dated Billing payments are excluded when the Stage 1 contract is
  enabled and are reported as a data-quality anomaly.
- KPI totals are calculated before visual row truncation.

## Changed Files

Core implementation:

- `db/migrations/20260723_020_stage1_service_data_contracts.php`
- `stage1-feature-lib.php`
- `reporting-period-lib.php`
- `operations-permission-lib.php`
- `operations-center.php`
- `finance-operations-lib.php`
- `finance-module-lib.php`
- `billing-module-lib.php`
- `mgs-integration-lib.php`
- `inventory-operations-lib.php`
- `inventory-module-lib.php`
- `statistics-center-lib.php`

Verification and operations:

- `tools/stage1-permission-matrix-smoke.php`
- `tools/stage1-service-data-contract-smoke.php`
- `tools/stage1-statistics-date-contract-smoke.php`
- `tools/stage1-rollback-readiness.php`
- `tools/stage1-feature-cutover.php`
- `tools/finance-debt-einvoice-smoke.php`
- `tools/statistics-center-smoke.php`
- `tools/full-system-regression.php`

Planning and acceptance:

- `docs/stage1/01_STAGE1_PLAN_2026-07-23.md`
- `docs/stage1/02_STAGE1_ACCEPTANCE_2026-07-23.md`

## Test Results

Targeted checks passed:

- Stage 1 permission matrix
- NOC service authorization and object scope
- Finance source idempotency and role denial
- HR-to-Finance idempotency and payroll breakdown
- E-invoice success, replay and atomic failure rollback
- Serialized return quarantine, inspection and repair state machine
- Repair event chain
- Strict date, leap day, reversed range, timezone and future-row contracts
- Legacy Finance debt/e-invoice roundtrip under the new contract

Migration rollback verification passed:

1. Readiness showed zero Stage 1 business rows and zero enabled flags.
2. Migration `20260723_020_stage1_service_data_contracts` rolled back.
3. Registry showed the migration as `pending`.
4. The migration was reapplied successfully.
5. Registry showed the migration as `applied`.

First full regression:

- `182 pass`
- `1 fail`
- `0 timeout`
- `4 skip`
- Report: `tmp/full-system-regression-20260723-082931.json`

The one failure was an obsolete smoke expectation that silently swapped a
reversed custom date range. The smoke was corrected to require the explicit
error contract.

Final full regression:

- `183 pass`
- `0 fail`
- `0 timeout`
- `4 skip`
- Report: `tmp/full-system-regression-20260723-083421.json`

Post-regression fixture check found zero Stage 1 business rows from smoke tests.
`git diff --check` reported no whitespace errors.

## Rollback

Operational rollback preserves data and only disables the new behavior:

```powershell
D:\xampp\php\php.exe tools\stage1-feature-cutover.php --disable
```

Schema rollback is allowed only before real Stage 1 business data exists:

```powershell
D:\xampp\php\php.exe tools\stage1-rollback-readiness.php
D:\xampp\php\php.exe tools\run-portal-migrations.php --rollback=20260723_020_stage1_service_data_contracts
```

If `stage1-rollback-readiness.php` reports business rows, do not drop the
schema. Disable the flags and preserve the data for reconciliation.

The pre-migration SQL backup is the final recovery path if a database restore
is explicitly required.

## Remaining External Tests

These four tests remain intentionally skipped in deterministic regression:

- real router CoA/disconnect against a selected PPPoE/IPoE test session;
- real OLT/ONT restart after a reachable VLAN and test ONT are selected;
- real paid SMS delivery to an approved destination;
- live Telegram polling because it consumes provider updates.

Dry-run, service, queue, role and recovery contracts for these areas passed.
