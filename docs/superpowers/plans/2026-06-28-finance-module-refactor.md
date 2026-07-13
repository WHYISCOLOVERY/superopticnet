# Finance Module Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the Finance mega-page into lazy-loaded local tabs, add real 50-row server pagination, and route report statistics to finance-only contextual analytics.

**Architecture:** Keep `operations-center.php?section=finance` as the canonical Finance controller so existing permissions and POST workflows remain intact. Move Finance querying and rendering into focused library/view files; the full page and AJAX partial endpoint use the same context builder and view, so no duplicate implementation is created.

**Tech Stack:** PHP 8, PDO/MySQL, existing portal CSS, Fetch API, existing CLI smoke-test harness.

---

### Task 1: Finance contract and regression test

**Files:**
- Create: `tools/finance-module-separation-smoke.php`
- Test: `tools/billing-ui-smoke.php`

- [ ] **Step 1: Write a failing smoke test**

The test must require these tabs: `overview`, `invoices`, `payments`, `companies`, `expenses`, `cash-bank`, `reconciliation`, `assets`, and `statistics`. It must render the overview and assert that invoice, payment, debt, depreciation, and import forms are absent; render each selected tab and assert only its own workflow marker is present; assert pagination never exceeds 50 rows; and assert Finance statistics contains no RADIUS/customer analytics markers.

- [ ] **Step 2: Verify RED**

Run: `.\php.cmd tools\finance-module-separation-smoke.php`

Expected: failure because Finance tabs, partial endpoint, and contextual statistics do not exist.

### Task 2: Shared Finance query and pagination layer

**Files:**
- Create: `finance-module-lib.php`
- Modify: `mgs-integration-lib.php`
- Test: `tools/finance-module-separation-smoke.php`

- [ ] **Step 1: Define the tested API**

Implement tab validation, date validation, `page`/`limit` normalization with a hard maximum of 50, total-count metadata, and `LIMIT/OFFSET` queries for invoices, billing receipts, counterparties, expenses, debts, debt payments, depreciation, and asset views.

- [ ] **Step 2: Keep data ownership explicit**

Billing customer receipts are read-only Finance income rows. Finance invoice/debt/payment/depreciation tables remain Finance-owned. Inventory assets are read-only valuation/print views and are not editable from Finance.

- [ ] **Step 3: Verify GREEN for query contracts**

Run: `.\php.cmd tools\finance-module-separation-smoke.php --queries`

Expected: pagination metadata is correct, page size is at most 50, and page 1/page 2 use different rows when enough data exists.

### Task 3: Split Finance rendering into selected-tab views

**Files:**
- Create: `views/finance/module.php`
- Create: `views/finance/overview.php`
- Create: `views/finance/invoices.php`
- Create: `views/finance/payments.php`
- Create: `views/finance/companies.php`
- Create: `views/finance/expenses.php`
- Create: `views/finance/cash-bank.php`
- Create: `views/finance/reconciliation.php`
- Create: `views/finance/assets.php`
- Create: `views/finance/statistics.php`
- Modify: `operations-center.php`
- Test: `tools/finance-module-separation-smoke.php`

- [ ] **Step 1: Render only the selected tab**

Default Finance opens `overview`. Overview contains at most six KPIs, four compact charts/insights, critical items, and shortcut links. It must not render full forms or full tables.

- [ ] **Step 2: Move workflows into modal/drawer panels**

Invoice import, manual expense, debt creation, debt payment, and depreciation forms are rendered only on their owning tab and remain closed until their action button is selected.

- [ ] **Step 3: Add one paginated table per list view**

Every list view shows `start-end / total`, Previous/Next/page links, keeps filters in the URL, and renders no more than 50 rows.

- [ ] **Step 4: Verify GREEN for rendering**

Run: `.\php.cmd tools\finance-module-separation-smoke.php`

Expected: all tab isolation and row-count assertions pass.

### Task 4: AJAX tabs and finance-only statistics

**Files:**
- Create: `finance-module-partial.php`
- Modify: `views/finance/module.php`
- Modify: `views/finance/statistics.php`
- Modify: `operations-center.php`
- Test: `tools/finance-module-separation-smoke.php`

- [ ] **Step 1: Add a permission-protected partial endpoint**

The endpoint accepts the same Finance tab/filter/pagination query, builds the same context, and returns only `[data-finance-module]` HTML.

- [ ] **Step 2: Add delegated Fetch API navigation**

Intercept Finance tab, filter, and pagination links/forms; use `AbortController`; replace only the Finance module; preserve scroll; update canonical URL with `history.pushState`; support `popstate`; and expose loading/error states.

- [ ] **Step 3: Add contextual Finance statistics**

Finance statistics aggregates only Finance income, expenses, invoices, receivables/payables, payment methods, and counterparties for the selected date range/report type. It must not query or render RADIUS, traffic, package, or customer lifecycle statistics.

- [ ] **Step 4: Verify partial and statistics contracts**

Run: `.\php.cmd tools\finance-module-separation-smoke.php --ajax`

Expected: partial markers, AbortController, history handling, finance-only datasets, and no Billing fallback all pass.

### Task 5: Context-aware report statistics and permissions

**Files:**
- Modify: `operations-center.php`
- Modify: `config.php`
- Modify: `billing-ui.php`
- Test: `tools/finance-module-separation-smoke.php`
- Test: `tools/portal-module-access-smoke.php`

- [ ] **Step 1: Replace the hardcoded report Statistics link**

Finance report links carry `module=finance` and `report_type`. Their Statistics action opens `section=finance&finance_tab=statistics&report_type=...`. Unknown report context displays `Statistics context not configured` and never falls back to `billing-statistics.php`.

- [ ] **Step 2: Return HTTP 403 for authenticated forbidden modules**

Keep unauthenticated login redirects, but return a 403 response for an authenticated employee without the requested module permission.

- [ ] **Step 3: Run focused regression tests**

Run: `.\php.cmd tools\finance-module-separation-smoke.php`

Run: `.\php.cmd tools\portal-module-access-smoke.php`

Run: `.\php.cmd tools\finance-debt-einvoice-smoke.php`

Run: `.\php.cmd tools\finance-inventory-assets-smoke.php`

Expected: all pass without warnings.

### Task 6: Browser and full regression verification

**Files:**
- Modify only files required by defects reproduced during verification.

- [ ] **Step 1: Run syntax and UI suites**

Run PHP lint on every changed PHP file, then run `tools/billing-ui-smoke.php`, `tools/operations-module-smoke.php`, and `tools/billing-statistics-selector-smoke.php`.

- [ ] **Step 2: Verify desktop and mobile in the browser**

Check Finance overview, all tabs, modal overflow, 50-row pagination, AJAX tab/filter behavior, Back/refresh state, Finance report Statistics context, console errors, and mobile table/card behavior.

- [ ] **Step 3: Record completion evidence**

Report changed files, route/context map, pagination coverage, smoke results, browser results, remaining module phases, and any residual data-model limitations.
