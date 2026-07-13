# Admin Audit Journal and Protected Ledger Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete guarded Inventory edit/adjustment operations and add an admin-visible audit journal with super-admin management and an immutable hash-chained security ledger.

**Architecture:** Add a focused `audit-log-lib.php` service that extends `mgs_operation_actions`, sanitizes snapshots, captures request context, and appends a protected hash-chain row inside the caller's transaction. Inventory and other sensitive domain services call this shared API. `operation-logs.php` provides admin-only filtering/export, while only `super_admin` can soft-delete or edit visible journal metadata; every intervention is appended to the protected ledger.

**Tech Stack:** PHP 8 strict types, PDO MySQL/InnoDB transactions and row locks, existing portal CSS/JavaScript, CLI smoke tests, CSV streaming.

**Repository note:** `D:\xampp\htdocs\superopticnet` is not a Git repository. Each task ends with a reproducible test checkpoint instead of a commit.

---

## File Structure

- Create `audit-log-lib.php`: schema migration, event recording, sanitization, protected hash-chain, queries, integrity verification and visible-journal interventions.
- Create `operation-logs.php`: admin-only journal screen, filters, detail modal, protected-ledger view and AJAX shell.
- Create `operation-logs-export.php`: admin-only filtered CSV export.
- Create `tools/audit-ledger-smoke.php`: shared audit and hash-chain behavior.
- Create `tools/audit-permission-smoke.php`: admin/super-admin access and intervention behavior.
- Create `tools/inventory-catalog-adjustment-smoke.php`: catalog edit/deactivation and manual adjustment behavior.
- Create `tools/sensitive-reason-smoke.php`: mandatory reasons for customer-day, Finance, HR and Inventory changes.
- Modify `config.php`: super-admin helpers, request context helper and protected audit page routing.
- Modify `db.php`: seed/migrate the designated super-admin account.
- Modify `portal-actions.php`: super-admin assignment protection and privilege audit integration.
- Modify `inventory-operations-lib.php`: catalog edit/deactivation and manual stock adjustment services.
- Modify `inventory-module-lib.php`: adjustment/can-manage context and paginated catalog data.
- Modify `views/inventory/catalog.php`: edit/deactivate controls and modals.
- Modify `views/inventory/movements.php`: manual adjustment modal and reason field.
- Modify `operations-center.php`: Inventory endpoints and shared audit integration.
- Modify `billing-customer-edit.php`: mandatory reason and before/after audit for service-day changes.
- Modify `finance-operations-lib.php`: mandatory reason and shared audit for void/update.
- Modify `mgs-integration-lib.php`: route HR sensitive writes through the shared audit service.
- Modify `admin-users.php` and `portal.php`: admin journal entry points and super-admin badge.
- Modify `tools/portal-module-access-smoke.php`, existing module smokes and `tools/billing-ui-smoke.php`: regression expectations.

---

### Task 1: Shared Operational Audit and Protected Ledger

**Files:**
- Create: `audit-log-lib.php`
- Create: `tools/audit-ledger-smoke.php`
- Modify: `mgs-integration-lib.php`

- [ ] **Step 1: Write the failing shared-audit test**

Create `tools/audit-ledger-smoke.php` that starts a transaction, calls the wished-for API, verifies the operational row and protected row, verifies sanitization, then rolls back:

```php
$event = app_audit_record($db, [
    'module' => 'inventory',
    'action_type' => 'inventory_item_updated',
    'operation_class' => 'update',
    'entity_type' => 'inventory_item',
    'entity_id' => '42',
    'actor' => $actor,
    'summary' => 'Catalog item updated',
    'reason' => 'Unit correction',
    'before' => ['unit' => 'eded', 'password' => 'must-not-leak'],
    'after' => ['unit' => 'paket'],
    'request' => ['ip' => '127.0.0.1', 'user_agent' => 'audit-smoke', 'request_id' => 'audit-' . $run],
    'sensitivity' => 'high',
]);
assert($event['ok'] === true);
assert(app_audit_verify_chain($db)['ok'] === true);
```

The test must assert that `password` is replaced by `[REDACTED]`, request context is stored, `previous_hash` points to the preceding ledger row, and `event_hash` is a 64-character hexadecimal value.

- [ ] **Step 2: Run the audit test and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\audit-ledger-smoke.php
```

Expected: FAIL because `audit-log-lib.php`, `app_audit_record()` and `security_audit_ledger` do not exist.

- [ ] **Step 3: Implement idempotent audit schema migration**

In `audit-log-lib.php`, implement `app_audit_ensure_schema(PDO $db): void`. Add missing columns to `mgs_operation_actions` one at a time using `INFORMATION_SCHEMA.COLUMNS` checks:

```sql
module_key VARCHAR(64) DEFAULT NULL,
operation_class VARCHAR(32) DEFAULT NULL,
reason VARCHAR(255) DEFAULT NULL,
before_json LONGTEXT DEFAULT NULL,
after_json LONGTEXT DEFAULT NULL,
request_ip VARCHAR(45) DEFAULT NULL,
user_agent VARCHAR(255) DEFAULT NULL,
request_id VARCHAR(64) DEFAULT NULL,
sensitivity ENUM('normal','high','critical') NOT NULL DEFAULT 'normal',
is_visible TINYINT(1) NOT NULL DEFAULT 1,
edited_by_account_id BIGINT UNSIGNED DEFAULT NULL,
edited_at DATETIME DEFAULT NULL,
deleted_by_account_id BIGINT UNSIGNED DEFAULT NULL,
deleted_at DATETIME DEFAULT NULL
```

Create the append-only table:

```sql
CREATE TABLE IF NOT EXISTS security_audit_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  operational_action_id BIGINT UNSIGNED DEFAULT NULL,
  event_type VARCHAR(64) NOT NULL,
  module_key VARCHAR(64) NOT NULL DEFAULT '',
  entity_type VARCHAR(64) NOT NULL DEFAULT '',
  entity_id VARCHAR(191) NOT NULL DEFAULT '',
  actor_account_id BIGINT UNSIGNED DEFAULT NULL,
  actor_login VARCHAR(191) DEFAULT NULL,
  actor_name VARCHAR(191) DEFAULT NULL,
  actor_role VARCHAR(64) DEFAULT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  event_json LONGTEXT NOT NULL,
  request_ip VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  request_id VARCHAR(64) DEFAULT NULL,
  previous_hash CHAR(64) NOT NULL,
  event_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_security_audit_hash (event_hash),
  KEY idx_security_audit_action (operational_action_id),
  KEY idx_security_audit_actor (actor_account_id, created_at),
  KEY idx_security_audit_entity (entity_type, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Also create and seed a singleton chain head so concurrent first writes serialize correctly:

```sql
CREATE TABLE IF NOT EXISTS security_audit_chain_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  last_ledger_id BIGINT UNSIGNED DEFAULT NULL,
  last_hash CHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO security_audit_chain_state(id,last_ledger_id,last_hash)
VALUES(1,NULL,REPEAT('0',64));
```

Create `BEFORE UPDATE` and `BEFORE DELETE` triggers on `security_audit_ledger` that raise `SIGNAL SQLSTATE '45000'`. This enforces append-only behavior even for accidental application code paths.

Schema migration must run before business transactions because `ALTER TABLE` causes implicit commits.

- [ ] **Step 4: Implement sanitization and canonical hashing**

Implement:

```php
function app_audit_sanitize(mixed $value): mixed;
function app_audit_canonical_json(array $value): string;
function app_audit_request_context(?array $override = null): array;
```

`app_audit_sanitize()` recursively redacts keys matching `password`, `password_hash`, `token`, `secret`, `session`, `csrf`, `api_key`, `authorization` or `cookie`. `app_audit_canonical_json()` recursively sorts associative keys before JSON encoding with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.

- [ ] **Step 5: Implement transactional event recording**

Implement `app_audit_record(PDO $db, array $event): array`. It must require an existing transaction for `executed` sensitive business events, insert `mgs_operation_actions`, lock `security_audit_chain_state WHERE id=1 FOR UPDATE`, calculate:

```php
$eventHash = hash('sha256', $previousHash . '|' . app_audit_canonical_json($ledgerPayload));
```

insert `security_audit_ledger`, then update the locked chain head with the new ID/hash. Return `['ok' => true, 'action_id' => ..., 'ledger_id' => ..., 'event_hash' => ...]`. Throw on audit persistence failure so the caller's business transaction rolls back.

- [ ] **Step 6: Implement chain verification and filtered page query**

Implement:

```php
app_audit_verify_chain(PDO $db): array;
app_audit_operation_page(PDO $db, array $query): array;
app_audit_ledger_page(PDO $db, array $query): array;
```

Both page functions cap `limit` at 50, clamp `page` to the calculated last page and support date, actor, module, operation, status, entity and free-text filters.

- [ ] **Step 7: Run RED-to-GREEN**

Run:

```powershell
D:\xampp\php\php.exe -l audit-log-lib.php
D:\xampp\php\php.exe tools\audit-ledger-smoke.php
```

Expected: both exit 0 and the smoke output reports a valid chain and redacted secret.

---

### Task 2: Super Admin Role and Protected Permissions

**Files:**
- Create: `tools/audit-permission-smoke.php`
- Modify: `config.php`
- Modify: `db.php`
- Modify: `portal-actions.php`
- Modify: `admin-users.php`

- [ ] **Step 1: Write the failing permission test**

The test loads the designated account and asserts:

```php
$account = app_db_account_by_login('s.abdullayev@superoptic.net');
assert($account['employee_role'] === 'super_admin');
assert(app_portal_is_super_admin($account) === true);
assert(app_portal_is_admin($account) === true);
assert(app_audit_actor_can_view($admin) === true);
assert(app_audit_actor_can_manage($admin) === false);
assert(app_audit_actor_can_manage($account) === true);
```

It must also verify that a normal admin cannot edit/soft-delete an operational row and that the super admin can.

- [ ] **Step 2: Run and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\audit-permission-smoke.php
```

Expected: FAIL because `super_admin` and audit intervention APIs are absent.

- [ ] **Step 3: Add role helpers and migrate the account**

In `config.php`, add:

```php
function app_portal_is_super_admin(array $user): bool
{
    return strtolower((string) ($user['employee_role'] ?? '')) === 'super_admin';
}
```

Change `app_portal_is_admin()` to return true for `admin` or `super_admin`. In the existing bootstrap account creation/migration in `db.php`, set `s.abdullayev@superoptic.net` to `role=employee`, `account_type=admin`, `employee_role=super_admin`, `is_active=1`. Run the update idempotently by login.

- [ ] **Step 4: Protect role assignment**

In `portal-actions.php`, reject any submitted `employee_role=super_admin` unless the current actor is already super admin. Prevent deletion or demotion of `s.abdullayev@superoptic.net` through normal admin forms. Record privilege changes through `app_audit_record()` with before/after snapshots and a required reason for demotion, deactivation or role changes.

- [ ] **Step 5: Implement visible-journal intervention services**

In `audit-log-lib.php`, implement:

```php
app_audit_actor_can_view(array $actor): bool;
app_audit_actor_can_manage(array $actor): bool;
app_audit_edit_visible(PDO $db, int $actionId, string $summary, string $reason, array $actor): array;
app_audit_delete_visible(PDO $db, int $actionId, string $reason, array $actor): array;
```

Both intervention services require a non-empty reason, lock the operational row, update only visible metadata or `is_visible`, and append `journal_edit` or `journal_delete` to `security_audit_ledger`. They must never update or delete an existing protected-ledger row.

- [ ] **Step 6: Show the role in Admin UI**

Add `Super admin` badge rendering in `admin-users.php`. Do not expose `super_admin` as a selectable role to normal admins. Show the journal link to both admins and super admin.

- [ ] **Step 7: Run permission tests**

Run:

```powershell
D:\xampp\php\php.exe -l config.php
D:\xampp\php\php.exe -l db.php
D:\xampp\php\php.exe -l portal-actions.php
D:\xampp\php\php.exe tools\audit-permission-smoke.php
D:\xampp\php\php.exe tools\portal-module-access-smoke.php
```

Expected: all exit 0.

---

### Task 3: Inventory Catalog Edit, Deactivation and Manual Adjustment

**Files:**
- Create: `tools/inventory-catalog-adjustment-smoke.php`
- Modify: `inventory-operations-lib.php`
- Modify: `inventory-module-lib.php`
- Modify: `views/inventory/catalog.php`
- Modify: `views/inventory/movements.php`
- Modify: `operations-center.php`

- [ ] **Step 1: Write the failing Inventory test**

Seed an item and location balance, then require these wished-for APIs:

```php
$updated = app_inventory_update_item($db, [
    'item_id' => $itemId,
    'item_name' => 'Updated ONU',
    'category' => 'active_equipment',
    'unit' => 'eded',
    'minimum_quantity' => 2,
    'reason' => 'Catalog correction',
], $warehouseActor);

$adjusted = app_inventory_adjust_stock($db, [
    'item_id' => $itemId,
    'location_type' => 'warehouse',
    'location_id' => 'MAIN',
    'quantity_delta' => -1,
    'reason' => 'Damaged during count',
], $warehouseManager);
```

Assert item history snapshots, company/location quantities, mandatory reason rejection, insufficient-stock rejection, and no physical item deletion when documents reference it.

- [ ] **Step 2: Run and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\inventory-catalog-adjustment-smoke.php
```

Expected: FAIL because update/deactivate/adjust APIs are missing.

- [ ] **Step 3: Implement catalog update and guarded deactivation**

Add `app_inventory_update_item()` and `app_inventory_deactivate_item()` to `inventory-operations-lib.php`. Both require Warehouse role or admin, a non-empty reason, a transaction and `SELECT ... FOR UPDATE`. Capture before/after snapshots and call `app_audit_record()` before commit. Deactivation sets `status=inactive`; no endpoint physically deletes referenced catalog items.

- [ ] **Step 4: Implement manual stock adjustment**

Add `app_inventory_adjust_stock()`. Require admin or `warehouse_manager`, non-zero delta and reason. Lock both `inventory_stock_items` and `inventory_location_balances`; reject any resulting negative quantity. Update company-wide and selected location balance by the same delta, append a lifecycle row and write critical audit snapshots in one transaction.

- [ ] **Step 5: Add endpoints and modal forms**

Add `update_inventory_item`, `deactivate_inventory_item` and `adjust_inventory_stock` POST actions to `operations-center.php`. Verify CSRF and service permissions before use. In Catalog, add superimposed-safe edit/deactivate controls and dialogs. In Movements, add `Stok duzelisi` modal with item, location, signed delta and required reason.

- [ ] **Step 6: Enforce visibility and pagination**

Use `can_write` and `can_approve` context flags to hide mutation controls from read-only roles. Clamp catalog and movement pages to 50 rows and the calculated last page.

- [ ] **Step 7: Run Inventory RED-to-GREEN and regressions**

Run:

```powershell
D:\xampp\php\php.exe tools\inventory-catalog-adjustment-smoke.php
D:\xampp\php\php.exe tools\inventory-operational-integrity-smoke.php
D:\xampp\php\php.exe tools\inventory-location-transfer-smoke.php
D:\xampp\php\php.exe tools\inventory-return-approval-smoke.php
D:\xampp\php\php.exe tools\inventory-module-separation-smoke.php
D:\xampp\php\php.exe tools\inventory-lifecycle-smoke.php
```

Expected: all exit 0.

---

### Task 4: Mandatory Reasons and Shared Audit in Sensitive Modules

**Files:**
- Create: `tools/sensitive-reason-smoke.php`
- Modify: `billing-customer-identity-lib.php`
- Modify: `billing-customer-edit.php`
- Modify: `finance-operations-lib.php`
- Modify: `hr-employee-service.php`
- Modify: `mgs-integration-lib.php`
- Modify: `inventory-operations-lib.php`

- [ ] **Step 1: Write the failing sensitive-reason test**

The test must verify rejection without a reason and success with a reason for:

```php
app_billing_validate_sensitive_change($beforeBilling, $afterBilling, '');
app_finance_void_entry($db, $entryId, $actor, '');
app_mgs_save_hr_employee_compensation(['employee_account_id' => $employeeId, 'gross_salary' => 1200, 'reason' => ''], $hr, $db);
app_inventory_adjust_stock($db, $adjustment, $manager);
```

It must assert `reason_required` and confirm the successful events include actor, reason, before/after, IP, user agent and request ID.

- [ ] **Step 2: Run and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\sensitive-reason-smoke.php
```

Expected: FAIL because the existing sensitive services do not consistently require reasons or shared snapshots.

- [ ] **Step 3: Add Billing service-day reason validation**

Add `app_billing_validate_sensitive_change(array $before, array $after, string $reason): array` in the Billing identity/domain library. Treat billing days, expiration, tariff, active state and stable due day as sensitive. `billing-customer-edit.php` adds a `change_reason` textarea and preserves it on validation failure. When sensitive fields differ, require the reason and call `app_audit_record()` with customer before/after snapshots inside the existing account/RADIUS transaction.

- [ ] **Step 4: Add Finance reasons and snapshots**

Change Finance entry update/void signatures to accept `reason`. Creation remains optional. Updates and voids require reason, lock the row, capture before/after and write the shared event. Update the Finance forms and controller mappings.

- [ ] **Step 5: Add HR reasons and snapshots**

Require `reason` for compensation replacement, payroll status changes after draft, leave/travel status changes, employee score reset and manual score events. Capture the locked record before and after the change and use `app_audit_record()` in the same transaction.

- [ ] **Step 6: Route existing Inventory writes through shared audit**

Keep existing domain document/lifecycle records. Replace or supplement `app_inventory_record_audit()` so intake, branch transfer, handover, external request, approval, rejection and adjustment produce standardized shared audit fields without duplicate visible events.

- [ ] **Step 7: Run sensitive and module regressions**

Run:

```powershell
D:\xampp\php\php.exe tools\sensitive-reason-smoke.php
D:\xampp\php\php.exe tools\finance-operational-crud-smoke.php
D:\xampp\php\php.exe tools\hr-payroll-legal-smoke.php
D:\xampp\php\php.exe tools\hr-leave-travel-docs-smoke.php
D:\xampp\php\php.exe tools\hr-salary-score-smoke.php
D:\xampp\php\php.exe tools\billing-customer-identity-smoke.php
D:\xampp\php\php.exe tools\billing-customer-edit-smoke.php
```

Expected: all exit 0.

---

### Task 5: Admin Journal Page, Detail View and CSV Export

**Files:**
- Create: `operation-logs.php`
- Create: `operation-logs-export.php`
- Modify: `admin-users.php`
- Modify: `portal.php`
- Modify: `config.php`
- Modify: `tools/audit-permission-smoke.php`
- Modify: `tools/billing-ui-smoke.php`

- [ ] **Step 1: Extend the failing UI/permission test**

Add assertions that:

- unauthenticated and non-admin requests are denied;
- admins see both Operational Journal and Protected Ledger tabs;
- only super admin markup contains edit/delete controls;
- filters and pagination use AJAX markers;
- CSV export uses the same query parameters;
- detail markup contains before/after, reason, IP, user-agent and request ID.

- [ ] **Step 2: Run and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\audit-permission-smoke.php
```

Expected: FAIL because the journal pages do not exist.

- [ ] **Step 3: Build admin-only journal shell**

`operation-logs.php` calls `billing_require_employee(false)` and rejects users for whom `app_audit_actor_can_view()` is false with HTTP 403. Render restrained grouped controls, KPI strip, filter form, 50-row table and detail dialogs. The page uses `data-audit-log-root`, `data-audit-filter`, `data-audit-pagination` and `data-audit-detail` markers.

- [ ] **Step 4: Add AJAX filtering and pagination**

Submit filter forms and pagination links with `fetch()`, parse the returned document and replace only `[data-audit-log-content]`. Update browser history without full-page reload. Preserve loading, error and empty states.

- [ ] **Step 5: Add protected-ledger integrity panel**

The Protected Ledger tab shows the latest rows and `app_audit_verify_chain()` result. A mismatch renders a critical warning with the first broken row ID. No edit/delete controls render in this tab for any role.

- [ ] **Step 6: Add super-admin interventions**

POST actions `edit_visible_audit` and `delete_visible_audit` verify CSRF, super-admin role and mandatory reason, then delegate to the service. Edit allows only visible summary and reason changes. Delete performs soft deletion. Both preserve entered values and return to the same filtered page.

- [ ] **Step 7: Add filtered CSV export**

`operation-logs-export.php` repeats admin authorization and streams UTF-8 CSV with event ID, date, actor, role, module, action, entity, status, reason, IP, request ID, before JSON and after JSON. It must never export secret values.

- [ ] **Step 8: Add navigation entry points**

Add `Emeliyyat jurnali` links in Admin Users and the admin-only portal controls. Add `operation-logs.php` and `operation-logs-export.php` to the protected request map without adding the journal to normal employee module cards.

- [ ] **Step 9: Run UI RED-to-GREEN**

Run:

```powershell
D:\xampp\php\php.exe tools\audit-permission-smoke.php
D:\xampp\php\php.exe tools\billing-ui-smoke.php
D:\xampp\php\php.exe tools\mobile-layout-smoke.php
```

Expected: all exit 0; Billing UI smoke includes the journal page for admin context.

---

### Task 6: Full Verification and Integrity Evidence

**Files:**
- Modify only if verification exposes a defect.

- [ ] **Step 1: Lint every PHP file**

Run:

```powershell
$files = rg --files -g '*.php'
$failed = @()
foreach ($file in $files) {
    & 'D:\xampp\php\php.exe' -l $file | Out-Null
    if ($LASTEXITCODE -ne 0) { $failed += $file }
}
if ($failed.Count -gt 0) { throw ($failed -join "`n") }
```

Expected: exit 0 and no failed files.

- [ ] **Step 2: Run the focused audit/Inventory suite sequentially**

Run all new tests plus:

```powershell
D:\xampp\php\php.exe tools\inventory-operational-integrity-smoke.php
D:\xampp\php\php.exe tools\inventory-location-transfer-smoke.php
D:\xampp\php\php.exe tools\inventory-return-approval-smoke.php
D:\xampp\php\php.exe tools\inventory-module-separation-smoke.php
D:\xampp\php\php.exe tools\finance-operational-crud-smoke.php
D:\xampp\php\php.exe tools\hr-module-separation-smoke.php
D:\xampp\php\php.exe tools\billing-module-separation-smoke.php
D:\xampp\php\php.exe tools\portal-module-access-smoke.php
```

Expected: every process exits 0.

- [ ] **Step 3: Run the complete established 30-test regression suite**

Use the same sequential test list recorded in the prior verification checkpoint, then add the four new smoke tests. Report total, passed and failed counts from actual process exit codes.

- [ ] **Step 4: Render page and responsive smoke tests**

Run:

```powershell
D:\xampp\php\php.exe tools\billing-ui-smoke.php
D:\xampp\php\php.exe tools\ui-layout-regression.php
D:\xampp\php\php.exe tools\mobile-layout-smoke.php
D:\xampp\php\php.exe tools\admin-hr-layout-smoke.php
```

Expected: all exit 0 with no missing markers or matched PHP/runtime errors.

- [ ] **Step 5: Verify protected chain after all tests**

Run a CLI probe that calls `app_audit_verify_chain(app_db())` and prints JSON. Expected: `ok=true`, `broken_id=null`, and a non-negative checked row count.

- [ ] **Step 6: Report exact limitations**

State whether live browser interaction was available. If only CLI rendering was possible, explicitly distinguish rendered-page smoke evidence from manual browser/pixel verification.
