# Admin Audit Journal and Protected Ledger Design

**Date:** 2026-07-05

## Objective

Finish the remaining Inventory operational controls, then add an admin-visible audit journal that records who changed what, why it changed, the previous and new values, and request context. The system must make harmful or self-benefiting changes attributable without preventing the designated super admin from managing visible journal records.

## Roles

- `admin`: can view, search, filter and export the operational journal and protected security ledger. Cannot edit or delete journal records.
- `super_admin`: has all admin permissions and can edit or delete visible operational journal records.
- `s.abdullayev@superoptic.net`: is migrated to `employee_role = super_admin` and remains an active employee/admin account.
- Other roles: cannot access either journal page or journal APIs.

`app_portal_is_super_admin()` is the single authorization helper for super-admin-only actions. `app_portal_is_admin()` treats both `admin` and `super_admin` as administrators so existing admin screens continue to work.

## Two-Layer Audit Architecture

### Operational Journal

The existing `mgs_operation_actions` stream remains the visible cross-module journal. Its schema is extended with:

- module key
- operation class: create, update, delete, approve, reject, login, export, journal_edit, journal_delete
- reason
- before snapshot JSON
- after snapshot JSON
- request IP
- user agent
- request ID
- sensitivity level
- visible/deleted state and super-admin edit metadata

The operational journal is paginated at 50 rows. Admins can view it. Only the super admin can change its visible summary/reason or mark a row deleted.

### Protected Security Ledger

`security_audit_ledger` is an append-only table. It records every sensitive business operation and every edit/delete applied to the visible journal. Each row stores the previous row hash and its own SHA-256 hash over the canonical event payload. This creates a verifiable chain.

No portal endpoint exposes update or delete operations for this table. Admin and super admin access is read-only. A verification service recalculates the chain and reports the first broken row if database-level tampering occurs.

## Required Event Data

Every sensitive event stores:

- actor account ID, login and display name
- actor authorization role
- module and action type
- entity type and stable entity ID
- human-readable summary
- mandatory business reason when applicable
- before and after snapshots with secrets removed
- request IP and user agent
- request ID for correlating multiple writes in one request
- creation time and execution status

Passwords, tokens, session IDs, card secrets and full authentication payloads are never included in snapshots.

## Sensitive Operations and Mandatory Reason

A non-empty reason is required for:

- changing customer service days, expiration date, balance-affecting service state or package
- deleting or manually adjusting Inventory stock
- changing or voiding Finance income, debt, payments or write-off state
- changing employee salary, payroll, leave, travel or disciplinary score after creation
- approving write-off, external Inventory transfer or supplier return
- editing or deleting a visible journal record

Create-only master data and normal stock intake may use an optional note unless they also perform a sensitive adjustment.

## Inventory Completion

Inventory keeps normalized source-of-truth tables and transactional services. Remaining controls include:

- catalog item edit with before/after audit
- guarded item deactivation instead of physical deletion when history exists
- explicit manual stock adjustment with positive or negative delta, mandatory reason and warehouse-manager/admin approval
- all list pages capped at 50 rows with clamped pagination
- write controls hidden from read-only roles
- intake, branch transfer, employee handover, return, write-off and adjustment events routed through the common audit service

Historical document snapshots remain unchanged when catalog data is edited.

## Data Flow

1. Endpoint verifies CSRF and module permission.
2. Sensitive input validation requires a reason.
3. Service starts a database transaction and locks the target row.
4. Service captures a sanitized before snapshot.
5. Business data is changed.
6. Service captures the after snapshot.
7. Operational journal and protected ledger rows are inserted in the same transaction.
8. Transaction commits only if all required writes succeed.

Failed attempts create a sanitized failure event after rollback when possible, without exposing SQL or secrets.

## Journal UI

Add `operation-logs.php` as an admin-only page and expose it from the main portal/admin controls.

The page provides:

- KPI counts for today, failed events, sensitive changes and super-admin journal interventions
- filters for date range, employee, module, operation, status, entity and free-text search
- 50-row table with actor, operation, target, reason, timestamp and IP
- detail modal showing sanitized before/after differences
- CSV export using the active filters
- protected-ledger tab with chain verification status
- super-admin-only visible-journal edit/delete controls with mandatory reason

AJAX filter and pagination updates replace only the journal content, not the whole page.

## Error Handling

- Missing reason returns a field-oriented validation error and preserves submitted values.
- Unauthorized journal actions return HTTP 403 and write no success event.
- Concurrent edits use row locks and reject stale updates.
- A protected-ledger hash mismatch is displayed as a critical integrity warning and is never automatically repaired.
- Audit failure rolls back the associated sensitive business transaction.

## Testing

Tests are written before production code and verify RED then GREEN for:

- super admin migration for `s.abdullayev@superoptic.net`
- admin read access and edit/delete denial
- super-admin visible-journal edit/delete permission
- protected ledger remaining unchanged after visible-journal modification
- mandatory reason validation for customer-day, stock-adjustment and journal interventions
- before/after snapshots, actor, IP, user agent and request ID
- hash-chain creation and verification
- Inventory catalog edit/deactivation and manual adjustment balance integrity
- 50-row pagination and AJAX journal markers
- CSV export permission and filter preservation
- existing Finance, HR, Inventory, Billing and portal access regressions

## Delivery Order

1. Complete Inventory catalog and adjustment controls with audit integration.
2. Add the shared audit service and protected ledger.
3. Migrate existing sensitive audit writers to the shared service.
4. Add super-admin role and migrate the designated account.
5. Build the admin journal page, detail view, export and super-admin interventions.
6. Run full PHP lint, focused tests, module regression suites and rendered-page smoke tests.

## Out of Scope

- External SIEM integration
- Remote write-once storage
- Digital signatures or qualified electronic signatures
- Automatic legal retention deletion
- Recording secret values in audit snapshots

## Repository Note

`D:\xampp\htdocs\superopticnet` is not a Git repository. The design document cannot be committed here; implementation checkpoints use reproducible test output instead.
