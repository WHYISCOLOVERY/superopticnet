# Operational CRUD and Inventory Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add missing Finance, HR, Inventory and Billing operations with transactional inventory approval, printable documents, strict authorization and consistent module UX.

**Architecture:** Keep the PHP/PDO/MySQL application and introduce focused domain service files for new write operations. Normalized tables are the source of truth; `mgs_operation_actions` receives audit copies. Module libraries build read contexts, while `operations-center.php` and Billing pages validate CSRF/permissions and delegate business transactions to the services.

**Tech Stack:** PHP 8 strict types, PDO MySQL transactions, existing portal CSS/JS, CLI smoke tests, Font Awesome Lite.

**Repository note:** `D:\xampp\htdocs\superopticnet` is not a Git repository. Every task ends with a test checkpoint instead of a commit command.

---

## File Structure

- Create `finance-operations-lib.php`: finance schema, manual income and counterparty write services.
- Create `hr-employee-service.php`: HR employee creation without authorization escalation.
- Create `billing-customer-identity-lib.php`: FIN/document schema, normalization, uniqueness and persistence.
- Create `inventory-operations-lib.php`: location balances, intake, transfers, approval requests and document snapshots.
- Create `inventory-document.php`: authorized printable inventory document endpoint.
- Create `views/finance/income.php`: income list and create modal.
- Modify existing module libraries/views only to route, render and query the new domain records.
- Add one focused CLI smoke test per domain and one UI consistency smoke test.

## Phase 1: Core CRUD

### Task 1: Finance Income and Counterparty Registry

**Files:**
- Create: `finance-operations-lib.php`
- Create: `views/finance/income.php`
- Create: `tools/finance-operational-crud-smoke.php`
- Modify: `operations-center.php:4-9,414-537`
- Modify: `finance-module-lib.php:6-20,204-245,385-557,570-627`
- Modify: `views/finance/module.php:24-36`
- Modify: `views/finance/companies.php`
- Modify: `views/finance/overview.php`

- [ ] **Step 1: Write the failing finance CRUD smoke test**

Create a test that requires `finance-operations-lib.php`, generates a run id and asserts these wished-for APIs:

```php
$counterparty = app_finance_save_counterparty($db, [
    'display_name' => 'Smoke Supplier ' . $runId,
    'counterparty_type' => 'company',
    'tax_id' => 'VOEN' . $runId,
    'phone' => '+994501112233',
    'status' => 'active',
], $admin);
$income = app_finance_save_entry($db, [
    'entry_type' => 'income',
    'title' => 'Smoke manual income',
    'category' => 'other',
    'amount' => 123.45,
    'currency' => 'AZN',
    'transaction_date' => date('Y-m-d'),
    'counterparty_id' => $counterparty['id'],
    'reference_no' => 'INC-' . $runId,
], $financeUser);
assert($counterparty['ok'] === true);
assert($income['ok'] === true);
assert(app_finance_manual_income_total($db, date('Y-m-d'), date('Y-m-d')) >= 123.45);
```

Also render Finance Income and Companies and assert `data-finance-income`, `Yeni gelir`, `data-finance-counterparty-create`, and `limit=50` pagination markers.

- [ ] **Step 2: Run the finance test and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\finance-operational-crud-smoke.php
```

Expected: FAIL because `finance-operations-lib.php` and the write APIs do not exist.

- [ ] **Step 3: Add normalized finance tables and services**

In `finance-operations-lib.php`, add idempotent schema creation for:

```sql
CREATE TABLE IF NOT EXISTS finance_counterparties (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  display_name VARCHAR(191) NOT NULL,
  normalized_name VARCHAR(191) NOT NULL,
  counterparty_type ENUM('company','institution','person','other') NOT NULL DEFAULT 'company',
  tax_id VARCHAR(64) DEFAULT NULL,
  phone VARCHAR(32) DEFAULT NULL,
  email VARCHAR(191) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  note TEXT DEFAULT NULL,
  created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_finance_counterparty_name_type (normalized_name, counterparty_type),
  KEY idx_finance_counterparty_tax (tax_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS finance_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_type ENUM('income','expense') NOT NULL,
  title VARCHAR(191) NOT NULL,
  category VARCHAR(96) NOT NULL DEFAULT 'manual',
  amount DECIMAL(14,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'AZN',
  transaction_date DATE NOT NULL,
  branch_id BIGINT UNSIGNED DEFAULT NULL,
  counterparty_id BIGINT UNSIGNED DEFAULT NULL,
  payment_source VARCHAR(96) DEFAULT NULL,
  reference_no VARCHAR(128) DEFAULT NULL,
  note TEXT DEFAULT NULL,
  status ENUM('posted','void') NOT NULL DEFAULT 'posted',
  created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
  created_by_name VARCHAR(191) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_finance_entry_type_date (entry_type, transaction_date),
  KEY idx_finance_entry_counterparty (counterparty_id),
  UNIQUE KEY uq_finance_entry_reference (reference_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Implement `app_finance_save_counterparty()`, `app_finance_save_entry()`, `app_finance_manual_income_total()` and `app_finance_income_page()`. Both write services verify the actor has Finance module access or admin status, validate required values, use transactions and write one `mgs_operation_actions` audit row after a successful commit.

- [ ] **Step 4: Add Finance routes and forms**

Add `'income' => 'Gelirler'` to `app_finance_tabs()`, include it in the Finance Operations navigation group and build its 50-row page context. `views/finance/income.php` contains the list and modal fields from the spec. `views/finance/companies.php` gains create/edit controls and displays registry rows plus unmatched legacy aggregate rows.

Add `create_finance_income` and `save_finance_counterparty` POST actions to `operations-center.php`. Each action verifies CSRF, delegates to the new service, and redirects to the exact Finance tab with a success/error flash.

- [ ] **Step 5: Include manual income in KPIs and statistics without duplication**

Change `app_finance_statistics_context()` and `app_finance_overview_kpis()` so income equals:

```sql
SELECT COALESCE(SUM(amount),0) FROM billing_payments WHERE created_at >= :start_at AND created_at < :end_at
```

plus:

```sql
SELECT COALESCE(SUM(amount),0) FROM finance_entries
WHERE entry_type = 'income' AND status = 'posted'
  AND transaction_date BETWEEN :start_date AND :end_date
```

Keep Billing rows and manual rows in separately labelled dataset buckets before combining totals.

- [ ] **Step 6: Run finance RED-to-GREEN and regressions**

Run:

```powershell
D:\xampp\php\php.exe tools\finance-operational-crud-smoke.php
D:\xampp\php\php.exe tools\finance-module-separation-smoke.php
D:\xampp\php\php.exe tools\four-module-ui-consistency-smoke.php
```

Expected: all three exit 0; the smoke test cleans up its own counterparty, entry and audit rows.

### Task 2: HR Employee Creation with Admin-Only Role Assignment

**Files:**
- Create: `hr-employee-service.php`
- Create: `tools/hr-employee-create-role-smoke.php`
- Modify: `db.php:192-225,2596-2690`
- Modify: `operations-center.php:4-9,414-780`
- Modify: `hr-module-lib.php:129-155,362-414`
- Modify: `views/hr/employees.php`
- Modify: `admin-users.php:394-468`
- Modify: `portal-actions.php:163-246`
- Modify: `config.php:1074-1102`

- [ ] **Step 1: Write the failing HR authorization smoke test**

The test creates an HR actor and submits a payload containing malicious authorization fields:

```php
$result = app_hr_create_employee($db, [
    'full_name' => 'Smoke Employee ' . $runId,
    'login' => 'hr-smoke-' . $runId,
    'phone' => '+994501234567',
    'job_title' => 'Field Specialist',
    'branch_id' => '1',
    'hire_date' => date('Y-m-d'),
    'salary_mode' => 'gross',
    'gross_salary' => 1000,
    'employee_role' => 'admin',
    'account_type' => 'admin',
    'is_active' => 1,
], $hrUser);
assert($result['ok'] === true);
$created = app_db_account_by_id((int) $result['account_id']);
assert($created['job_title'] === 'Field Specialist');
assert($created['employee_role'] === '');
assert($created['account_type'] === 'employee');
assert((int) $created['is_active'] === 0);
```

The same test calls the service as a non-HR/non-admin actor and expects `forbidden`. It renders the HR modal and asserts no `employee_role`, `account_type` or activation controls exist.

- [ ] **Step 2: Run the HR test and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\hr-employee-create-role-smoke.php
```

Expected: FAIL because `app_hr_create_employee()` and `portal_accounts.job_title` do not exist.

- [ ] **Step 3: Add the HR job-title schema and account support**

Add `job_title VARCHAR(128) DEFAULT NULL AFTER employee_role` to the `portal_accounts` create schema and an idempotent `SHOW COLUMNS` migration in `hr-employee-service.php`. Extend `app_db_upsert_account()` select/update/insert record mapping to persist `job_title` without deriving it from `employee_role`.

- [ ] **Step 4: Implement the HR-only creation transaction**

Implement `app_hr_create_employee(PDO $db, array $input, array $actor): array` with these fixed account values:

```php
'role' => 'employee',
'account_type' => 'employee',
'employee_role' => '',
'job_title' => clean_text((string) $input['job_title']),
'is_active' => 0,
```

Generate a random initial password hash that is never displayed and cannot be used while the account is inactive. Ignore submitted authorization fields. Save branch/hire date under `payload.employee`, then create the initial compensation inside the same transaction when salary is supplied. Roll back both records on failure.

- [ ] **Step 5: Add the HR modal and preserve admin ownership of roles**

Add `Yeni emekdas` beside `Maas profili elave et`. The modal contains name, login, phone, email, address, branch, job title, hire date, salary mode and gross/net salary. It contains no access role or activation control.

Add `create_hr_employee` to `operations-center.php`. Keep `portal-actions.php?action=save_account` admin-only. Extend the admin form with `warehouse_manager`, and show `job_title` as read-only HR information while `employee_role` remains the admin-controlled authorization selector.

Add `warehouse_manager => ['inventory']` to the module permission map.

- [ ] **Step 6: Run HR RED-to-GREEN and regressions**

Run:

```powershell
D:\xampp\php\php.exe tools\hr-employee-create-role-smoke.php
D:\xampp\php\php.exe tools\hr-module-separation-smoke.php
D:\xampp\php\php.exe tools\hr-salary-score-smoke.php
```

Expected: all exit 0 and the created employee/compensation/audit records are cleaned up.

### Task 3: Required Billing Customer Identity

**Files:**
- Create: `billing-customer-identity-lib.php`
- Create: `tools/billing-customer-identity-smoke.php`
- Modify: `db.php:192-225,2596-2690`
- Modify: `billing-customers.php:276-405,510-516,936-965`
- Modify: `billing-customer-edit.php:28-205,270-318`
- Modify: `billing-module-lib.php:109-135`

- [ ] **Step 1: Write the failing Billing identity test**

Test normalization and validation before rendering:

```php
$missing = app_billing_validate_customer_identity($db, [
    'fin_code' => '', 'document_no' => '', 'phone' => '', 'address' => ''
]);
assert($missing['ok'] === false);
assert(count($missing['fields']) === 4);

$valid = app_billing_validate_customer_identity($db, [
    'fin_code' => 'abc123d',
    'document_no' => 'AA1234567',
    'phone' => '+994 50 111 22 33',
    'address' => 'Baki',
]);
assert($valid['ok'] === true);
assert($valid['data']['fin_code'] === 'ABC123D');
```

Create one customer with a FIN and assert a second active customer receives `duplicate_fin`. Render create/edit pages and assert all four inputs are present and `required`.

- [ ] **Step 2: Run the Billing test and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\billing-customer-identity-smoke.php
```

Expected: FAIL because the identity service and account columns do not exist.

- [ ] **Step 3: Add identity columns and persistence**

Add nullable `fin_code VARCHAR(32)` and `document_no VARCHAR(64)` columns to the account create schema plus an idempotent migration. Add an index on `fin_code`. Extend `app_db_upsert_account()` to persist both fields.

Implement `app_billing_validate_customer_identity()` and `app_billing_save_customer_identity()`. Normalize FIN with uppercase alphanumeric characters, normalize phone with `normalize_phone()`, reject duplicate active customer FIN values excluding the edited account id, and mirror FIN/document into `payload.profile`.

- [ ] **Step 4: Enforce create and next-save update behavior**

In `billing-customers.php`, run identity validation before tariff/pool writes. Add required `fin_code`, `document_no`, `phone` and `address` controls. Include FIN/document in list search.

In `billing-customer-edit.php`, load account/profile identity values, show required controls and reject the save when any are missing. Existing customers remain readable until they submit an edit.

- [ ] **Step 5: Run Billing RED-to-GREEN and regressions**

Run:

```powershell
D:\xampp\php\php.exe tools\billing-customer-identity-smoke.php
D:\xampp\php\php.exe tools\billing-customer-edit-smoke.php
D:\xampp\php\php.exe tools\billing-ui-smoke.php
```

Expected: all exit 0 and Billing UI reports zero failed pages.

## Phase 2: Inventory Control and Documents

### Task 4: Location Balances, Intake and Branch Transfers

**Files:**
- Create: `inventory-operations-lib.php`
- Create: `tools/inventory-location-transfer-smoke.php`
- Modify: `operations-center.php:4-9,414-526`
- Modify: `inventory-module-lib.php:6-25,141-169,433-480`
- Modify: `views/inventory/movements.php`
- Modify: `views/inventory/handover.php`
- Modify: `views/inventory/module.php:30-42`
- Modify: `views/inventory/overview.php`

- [ ] **Step 1: Write the failing location-balance smoke test**

Create a stock item and execute:

```php
$intake = app_inventory_post_intake($db, [
    'item_id' => $itemId,
    'quantity' => 10,
    'unit_price' => 12.50,
    'to_location_type' => 'branch',
    'to_location_id' => $sourceBranch,
], $warehouseUser);
$transfer = app_inventory_transfer_branch($db, [
    'item_id' => $itemId,
    'quantity' => 4,
    'from_branch_id' => $sourceBranch,
    'to_branch_id' => $targetBranch,
    'note' => 'Smoke transfer',
], $warehouseUser);
assert(app_inventory_location_quantity($db, $itemId, 'branch', $sourceBranch) === 6.0);
assert(app_inventory_location_quantity($db, $itemId, 'branch', $targetBranch) === 4.0);
assert(app_inventory_company_quantity($db, $itemId) === 10.0);
```

Also assert insufficient source quantity and same-branch transfer are rejected without changing any balance.

- [ ] **Step 2: Run the inventory location test and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\inventory-location-transfer-smoke.php
```

Expected: FAIL because location balance APIs and table do not exist.

- [ ] **Step 3: Add location balance schema and locking helpers**

Create:

```sql
CREATE TABLE IF NOT EXISTS inventory_location_balances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id BIGINT UNSIGNED NOT NULL,
  location_type ENUM('warehouse','branch','employee','external') NOT NULL,
  location_id VARCHAR(128) NOT NULL,
  available_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
  reserved_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inventory_location_balance (item_id, location_type, location_id),
  KEY idx_inventory_location_lookup (location_type, location_id),
  CONSTRAINT fk_inventory_location_item FOREIGN KEY (item_id) REFERENCES inventory_stock_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Implement `app_inventory_lock_location_balance()`, `app_inventory_location_quantity()`, `app_inventory_company_quantity()`, `app_inventory_post_intake()` and `app_inventory_transfer_branch()`. Use `SELECT ... FOR UPDATE`, reject negative balances and write both stock and lifecycle movement records.

- [ ] **Step 4: Separate intake and transfer forms**

Replace the ambiguous one-form movement modal with two actions: `Mal medaxil et` and `Filiala transfer et`. Intake requires destination location. Transfer requires different source/destination branches. Add explicit `create_inventory_intake` and `create_inventory_branch_transfer` POST handlers.

Update handover to require `source_branch_id`; decrement that branch location and increment employee location in one transaction. Company-wide quantity remains unchanged for handover.

- [ ] **Step 5: Run inventory location RED-to-GREEN and regressions**

Run:

```powershell
D:\xampp\php\php.exe tools\inventory-location-transfer-smoke.php
D:\xampp\php\php.exe tools\inventory-module-separation-smoke.php
D:\xampp\php\php.exe tools\inventory-lifecycle-smoke.php
```

Expected: all exit 0 with unchanged global quantity after branch transfer.

### Task 5: Approved External Returns and Printable Documents

**Files:**
- Modify: `inventory-operations-lib.php`
- Create: `inventory-document.php`
- Create: `views/inventory/approvals.php`
- Create: `tools/inventory-return-approval-smoke.php`
- Modify: `operations-center.php:414-526`
- Modify: `inventory-module-lib.php:6-25,433-480`
- Modify: `views/inventory/module.php:30-42`
- Modify: `views/inventory/returns-inspection.php`
- Modify: `config.php:60-120,1083-1097`

- [ ] **Step 1: Write the failing approval/idempotency smoke test**

After seeding 8 units at a branch, create a pending return for 3 units and assert stock stays 8. Approve once as `warehouse_manager` and assert stock becomes 5. Approve again and assert stock remains 5 with `already_finalized`. Reject a second request and assert stock remains 5.

Assert generated numbers match:

```php
assert(preg_match('/^QAY-' . date('Y') . '-\d{6}$/', $request['document_no']) === 1);
```

Render `inventory-document.php?id=<request>` and assert company name, document number, item snapshot, `Tehvil veren` and `Tehvil alan` exist.

- [ ] **Step 2: Run the approval test and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\inventory-return-approval-smoke.php
```

Expected: FAIL because request/document tables and approval APIs do not exist.

- [ ] **Step 3: Add document sequence, request and snapshot tables**

Add `inventory_document_sequences(document_type, fiscal_year, next_value)` with a unique type/year key, `inventory_documents` with document type/status/source/destination/reason/disposition/actor/approver fields, and `inventory_document_items` containing immutable item name, quantity, unit, unit price, MAC, serial and note snapshots. Intake, handover and branch-transfer documents are stored with `approved` status immediately after their stock transaction commits; external removal documents use the approval status flow.

Implement `app_inventory_next_document_no()` by locking/incrementing the sequence row in the same transaction. Prefixes are `MDX`, `TRF`, `THV` and `QAY`.

- [ ] **Step 4: Implement request, approval and rejection transactions**

Implement:

```php
app_inventory_create_external_request(PDO $db, array $input, array $actor): array
app_inventory_approve_request(PDO $db, int $requestId, array $actor): array
app_inventory_reject_request(PDO $db, int $requestId, string $reason, array $actor): array
app_inventory_document(PDO $db, int $requestId): ?array
```

Creation accepts warehouse/admin, status `draft` or `pending`, and never changes stock. Approval accepts only `warehouse_manager` or admin, locks the request and source balances, applies each line once, decrements company-wide stock for external removal, sets approval metadata and commits one audit action. Rejection writes reason/status only.

Extend `app_inventory_post_intake()`, `app_inventory_transfer_branch()` and the employee handover transaction to call the same document snapshot writer with `MDX`, `TRF` and `THV` prefixes. A successful operational transaction and its document are committed together; neither is left without the other.

- [ ] **Step 5: Add approval queue and printable endpoint**

Add `approvals` to Inventory tabs/navigation. The page lists pending/finalized requests with View, Approve, Reject and Print controls based on permissions. `inventory-document.php` verifies Inventory access, loads the stored snapshot and emits print CSS with no portal navigation. Its table columns are sequence, item, quantity, unit, price, MAC/SN and note; footer has blank signature lines.

Use `app_config('portal_company_name') ?: 'Super Optik'` for the document heading.

- [ ] **Step 6: Run approval RED-to-GREEN and regressions**

Run:

```powershell
D:\xampp\php\php.exe tools\inventory-return-approval-smoke.php
D:\xampp\php\php.exe tools\inventory-location-transfer-smoke.php
D:\xampp\php\php.exe tools\inventory-module-separation-smoke.php
```

Expected: all exit 0; repeated approval changes no balance.

## Phase 3: Reports and Module UX

### Task 6: Reports Context and Overflow Repair

**Files:**
- Create: `tools/operations-reports-ui-smoke.php`
- Modify: `operations-center.php:1137-1139,1406-1469,2920-2990`
- Modify: `config.php:1166-1190`
- Modify: `css/portal.css:2354-2368,2828-2860`

- [ ] **Step 1: Write the failing Reports UI smoke test**

Render `operations-center.php?section=reports` and assert:

```php
assert(!str_contains($html, 'Statistics context not configured'));
foreach (['finance_tab=statistics', 'inventory_tab=statistics', 'hr_tab=statistics', 'billing-statistics.php'] as $href) {
    assert(str_contains($html, $href));
}
assert(str_contains($html, 'data-report-statistics-links'));
assert(str_contains($html, 'overflow-wrap:anywhere'));
```

- [ ] **Step 2: Run the Reports test and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\operations-reports-ui-smoke.php
```

Expected: FAIL on the English missing-context pill and missing fallback links.

- [ ] **Step 3: Replace the missing context with explicit module links**

Keep `app_portal_statistics_context_url()` for valid Finance context. For the context-free Reports page render four labelled links: Finance Statistics, Inventory Statistics, HR Statistics and Billing Statistics. Remove the missing-context pill entirely.

- [ ] **Step 4: Add narrow-layout overflow protection**

Add scoped CSS so `.portal-operations-report .portal-card-head` wraps, `.portal-badge-list > *` has `min-width:0`, button text wraps, and `.spog-backup-metric strong`, restore names and backup paths use `overflow-wrap:anywhere`. Stack the report header/actions and backup summary before 900px.

- [ ] **Step 5: Run Reports RED-to-GREEN**

Run:

```powershell
D:\xampp\php\php.exe tools\operations-reports-ui-smoke.php
D:\xampp\php\php.exe tools\four-module-ui-consistency-smoke.php
```

Expected: both exit 0.

### Task 7: Grouped AI Navigation, Pagination and AJAX Contract

**Files:**
- Create: `tools/ai-grouped-navigation-smoke.php`
- Modify: `views/ai/module.php`
- Modify: `ai-module-lib.php:6-48,56-102,205-260`
- Modify: `ai-module-partial.php`
- Modify: `ai-section-page.php`
- Modify: `views/_module-group-nav.php`

- [ ] **Step 1: Write the failing AI navigation test**

Render every AI section and assert exactly one `data-module-group-nav`, exactly four `data-module-nav-group` markers, no `class="ai-tabs"`, an active child and `overflow-wrap:anywhere`. For paginated AI pages, assert the requested limit is clamped to 50. Render the partial endpoint and assert it returns module content without a second full document shell.

- [ ] **Step 2: Run the AI test and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\ai-grouped-navigation-smoke.php
```

Expected: FAIL because AI still uses a flat ten-item tab strip.

- [ ] **Step 3: Replace flat AI tabs with grouped navigation**

Use `views/_module-group-nav.php` with these groups:

```php
[
  ['label' => 'Umumi baxis ve Copilot', 'links' => ['overview', 'copilot']],
  ['label' => 'Musteri ve sebeke analitikasi', 'links' => ['customer-analytics', 'network-analytics']],
  ['label' => 'Maliyye ve risk', 'links' => ['finance-analytics', 'risks', 'recommendations']],
  ['label' => 'Avtomatlashdirma ve audit', 'links' => ['automations', 'audit', 'model-status']],
]
```

Map AI routes into the generic link shape and add an allowed `data-ai-nav` attribute to the shared partial. Add AJAX navigation/filter handling that replaces only `[data-ai-content]`, updates history and exposes loading/error status.

- [ ] **Step 4: Apply AI overflow and pagination rules**

Add `min-width:0`, clamped KPI font sizes and `overflow-wrap:anywhere` to AI KPIs/cards. Keep `app_ai_pagination()` at 50 maximum and ensure every list page renders the existing pagination component/contract.

- [ ] **Step 5: Run AI RED-to-GREEN and regression**

Run:

```powershell
D:\xampp\php\php.exe tools\ai-grouped-navigation-smoke.php
D:\xampp\php\php.exe tools\ai-module-separation-smoke.php
D:\xampp\php\php.exe tools\billing-ui-smoke.php
```

Expected: all exit 0 with zero Billing UI failures.

### Task 8: Messaging and Network Grouped Shell Baseline

**Files:**
- Create: `tools/subsequent-module-shell-smoke.php`
- Modify: `message-center.php:437-445,790-841`
- Modify: `noc-devices.php:311-360,1179-1201`
- Modify: `views/_module-group-nav.php`

- [ ] **Step 1: Write the failing subsequent-module shell test**

Render every `message-center.php?view=` value and each `noc-devices.php?view=` value. Assert one `data-module-group-nav`, three to five `data-module-nav-group` elements, the active child, no old flat `mgs-command-actions` navigation, no mojibake page title and no more than 50 rendered history/audit rows.

- [ ] **Step 2: Run the shell test and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\subsequent-module-shell-smoke.php
```

Expected: FAIL because Messaging and Network still use flat action rows.

- [ ] **Step 3: Group Message Center navigation**

Replace the seven flat links with the shared group component:

```php
[
  ['label' => 'Umumi baxis', 'links' => ['overview']],
  ['label' => 'Mesaj gonder', 'links' => ['single', 'group']],
  ['label' => 'Mezmun', 'links' => ['templates']],
  ['label' => 'Tarixce ve audit', 'links' => ['history', 'audit']],
  ['label' => 'Provider test', 'links' => ['test']],
]
```

Filter `group` and `test` links before rendering for non-admin users. Change the topbar title to ASCII source text `Mesaj merkezi`. Keep the existing 20-row pagination, which is below the 50-row maximum.

- [ ] **Step 4: Group Network navigation**

Replace the NOC hero action row with:

```php
[
  ['label' => 'Monitor', 'links' => ['monitor']],
  ['label' => 'Cihazlar', 'links' => ['devices', 'ont-search']],
  ['label' => 'Backup', 'links' => ['backups']],
  ['label' => 'Sebeke tenzimlemeleri', 'links' => ['network-definitions']],
]
```

Keep the full-screen monitor as the first usable content in Monitor view. Apply `min-width:0` and wrapping to NOC KPI/action text without changing saved monitor layout behavior.

- [ ] **Step 5: Run shell RED-to-GREEN and regressions**

Run:

```powershell
D:\xampp\php\php.exe tools\subsequent-module-shell-smoke.php
D:\xampp\php\php.exe tools\billing-ui-smoke.php
```

Expected: both exit 0 and Billing UI reports zero failed pages.

### Task 9: Full Verification and Cleanup

**Files:**
- Modify only files that fail verification.

- [ ] **Step 1: Lint every changed PHP file**

Run a PowerShell loop over all files listed in Tasks 1-8 using:

```powershell
& 'D:\xampp\php\php.exe' -l $file
```

Expected: `No syntax errors detected` for every file.

- [ ] **Step 2: Run all new tests together**

```powershell
$tests = @(
  'tools\finance-operational-crud-smoke.php',
  'tools\hr-employee-create-role-smoke.php',
  'tools\billing-customer-identity-smoke.php',
  'tools\inventory-location-transfer-smoke.php',
  'tools\inventory-return-approval-smoke.php',
  'tools\operations-reports-ui-smoke.php',
  'tools\ai-grouped-navigation-smoke.php',
  'tools\subsequent-module-shell-smoke.php'
)
foreach ($test in $tests) { & 'D:\xampp\php\php.exe' $test; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE } }
```

Expected: eight passed test files and exit 0.

- [ ] **Step 3: Run the existing regression suites**

```powershell
$tests = @(
  'tools\finance-module-separation-smoke.php',
  'tools\finance-debt-einvoice-smoke.php',
  'tools\hr-module-separation-smoke.php',
  'tools\hr-payroll-legal-smoke.php',
  'tools\inventory-module-separation-smoke.php',
  'tools\inventory-lifecycle-smoke.php',
  'tools\billing-module-separation-smoke.php',
  'tools\billing-ui-smoke.php',
  'tools\ai-module-separation-smoke.php',
  'tools\four-module-ui-consistency-smoke.php'
)
foreach ($test in $tests) { & 'D:\xampp\php\php.exe' $test; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE } }
```

Expected: all ten suites exit 0; Billing UI reports zero failed pages.

- [ ] **Step 4: Browser verification**

Verify desktop and 390px-wide layouts for Finance Income/Companies, HR Employees, Inventory Movements/Approvals/document print, Billing create/edit, Reports, all four AI navigation groups, Message Center and Network views. Confirm no body-level horizontal overflow, no overlapping actions, modals remain inside the viewport and AJAX navigation does not reload the full page.

- [ ] **Step 5: Record the final verification evidence**

Report exact lint file count, test file count, Billing checked-page count and any browser limitation. Do not claim visual verification if the in-app browser connection is unavailable.
