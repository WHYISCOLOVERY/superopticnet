# Billing Module Separation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Separate Billing into ten canonical, role-protected pages with a lightweight overview, Billing-only statistics, 50-row server pagination, on-demand forms, and partial filter/chart refresh while preserving existing customer and payment write flows.

**Architecture:** Keep mature customer, profile, payment, and cash routes as canonical workflows. Add a shared Billing read-model library and a thin section-page renderer for the missing areas; replace the mixed dashboard and statistics templates with focused Billing views. The shared header provides one ten-item local navigation across old and new routes.

**Tech Stack:** PHP 8, PDO/MySQL, server-rendered HTML, existing portal CSS, Fetch API, History API, AbortController, CLI smoke tests.

**Workspace note:** `D:\xampp\htdocs\superopticnet` is not a git repository, so commit steps are replaced by explicit verification checkpoints.

---

### Task 1: Billing Separation Contract

**Files:**
- Create: `tools/billing-module-separation-smoke.php`
- Modify: `tools/billing-ui-smoke.php`
- Test: `tools/billing-module-separation-smoke.php`

- [ ] **Step 1: Write the failing module contract test**

Define the exact section contract and required files before production code:

```php
$expectedSections = [
    'overview', 'customers', 'packages', 'balances', 'payments',
    'services', 'providers', 'documents', 'reports', 'statistics',
];

$expect(array_keys(app_billing_sections()) === $expectedSections, 'billing_section_contract_mismatch');
$expect(app_billing_pagination(['page' => 3, 'limit' => 500])['limit'] === 50, 'billing_limit_not_capped');
```

Assert that overview output does not contain `radacct`, `NOC`, `CRM`, `AI Revenue Optimization`, or `Billing maintenance`, and that each list contains at most 50 `data-billing-row` markers.

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
D:\xampp\php\php.exe tools\billing-module-separation-smoke.php
```

Expected: exit `1` with missing library, section routes, views, partial endpoint, and section functions.

- [ ] **Step 3: Add the new Billing pages to the UI smoke registry**

Register `billing-packages.php`, `billing-balances.php`, `billing-services.php`, `billing-providers.php`, `billing-documents.php`, and `billing-reports.php` with route-specific markers.

- [ ] **Step 4: Verification checkpoint**

Record the RED output in the task log. Do not add production files until the expected failure is confirmed.

### Task 2: Shared Billing Read Model

**Files:**
- Create: `billing-module-lib.php`
- Test: `tools/billing-module-separation-smoke.php`

- [ ] **Step 1: Add the section and pagination helpers**

Implement these exact public functions:

```php
function app_billing_sections(): array;
function app_billing_section(array $query, string $fallback = 'overview'): string;
function app_billing_pagination(array $query): array;
function app_billing_query_page(PDO $db, string $countSql, string $rowsSql, array $params, array $query): array;
function app_billing_url(string $section, array $params = [], string $lang = 'az'): string;
```

`app_billing_pagination()` must clamp `limit` to `50`, calculate `offset`, and normalize invalid pages to `1`.

- [ ] **Step 2: Add operational-data guards**

Add customer and payment SQL predicates excluding known smoke prefixes and names:

```php
function app_billing_operational_customer_sql(string $alias = 'a'): string;
function app_billing_operational_payment_sql(string $alias = 'p'): string;
```

Use the same predicate in row lists, KPI totals, and chart datasets so displayed totals match tables.

- [ ] **Step 3: Add focused query functions**

Implement:

```php
function app_billing_package_page(PDO $db, array $query): array;
function app_billing_balance_page(PDO $db, array $query): array;
function app_billing_service_page(PDO $db, array $query): array;
function app_billing_provider_page(PDO $db, array $query): array;
function app_billing_document_page(PDO $db, array $query): array;
function app_billing_report_context(PDO $db, array $query): array;
function app_billing_statistics_context(PDO $db, array $query): array;
function app_billing_overview_context(PDO $db, array $query): array;
function app_billing_build_context(PDO $db, array $user, string $section, array $query): array;
```

Allowed tables for Billing statistics are `portal_accounts`, `billing_tariffs`, `billing_radius_services`, and `billing_payments`. Raw RADIUS, Finance, HR, Inventory, Network, and Messaging tables are forbidden.

- [ ] **Step 4: Run query tests**

Run:

```powershell
D:\xampp\php\php.exe tools\billing-module-separation-smoke.php --queries
```

Expected: all query functions return no `error`, each page has at most 50 rows, and each statistics dataset has at most 12 buckets.

### Task 3: Shared Section Renderer And Canonical Routes

**Files:**
- Create: `billing-section-page.php`
- Create: `billing-module-partial.php`
- Create: `billing-packages.php`
- Create: `billing-balances.php`
- Create: `billing-services.php`
- Create: `billing-providers.php`
- Create: `billing-documents.php`
- Create: `billing-reports.php`
- Modify: `billing-dashboard.php`
- Modify: `billing-statistics.php`
- Create: `views/billing/module.php`
- Create: `views/billing/overview.php`
- Create: `views/billing/packages.php`
- Create: `views/billing/balances.php`
- Create: `views/billing/services.php`
- Create: `views/billing/providers.php`
- Create: `views/billing/documents.php`
- Create: `views/billing/reports.php`
- Create: `views/billing/statistics.php`
- Create: `views/billing/_pagination.php`
- Create: `views/billing/_statistics-results.php`

- [ ] **Step 1: Add thin route wrappers**

Each route defines one section and delegates to the shared renderer:

```php
<?php
declare(strict_types=1);
$billingRouteSection = 'packages';
require __DIR__ . '/billing-section-page.php';
```

Use the corresponding section value for every wrapper. Replace dashboard and statistics bodies with the same renderer while retaining their URLs.

- [ ] **Step 2: Enforce permission in full and partial routes**

Both renderers must call:

```php
$user = billing_require_employee(false, 'billing');
```

The partial endpoint accepts only the section allowlist and returns either the module or `statistics-results` fragment.

- [ ] **Step 3: Render only the selected section**

`views/billing/module.php` must require exactly one view:

```php
require __DIR__ . '/' . $billingSection . '.php';
```

It must not loop over all section views or preload hidden datasets.

- [ ] **Step 4: Add responsive tables and modal contract**

Use a maximum radius of `8px`, convert tables to cards below `620px`, and use a single modal body scroll. Any new modal opens through the viewport portal pattern:

```js
document.body.appendChild(dialog);
dialog.hidden = false;
```

On close, hide it and append it back to `[data-billing-module]`.

- [ ] **Step 5: Run render tests**

Run:

```powershell
D:\xampp\php\php.exe tools\billing-module-separation-smoke.php --render
```

Expected: ten routes render their own marker, overview renders no workflow form, and every table has at most 50 rows.

### Task 4: Canonical Billing Navigation

**Files:**
- Modify: `billing-ui.php:31-70`
- Modify: `config.php:1012-1143`
- Test: `tools/billing-nav-layout-smoke.php`
- Test: `tools/portal-module-access-smoke.php`

- [ ] **Step 1: Replace grouped legacy links with ten Billing sections**

Return one local Billing navigation array using these keys and routes:

```php
[
    'dashboard' => 'billing-dashboard.php',
    'customers' => 'billing-customers.php',
    'packages' => 'billing-packages.php',
    'balances' => 'billing-balances.php',
    'payments' => 'billing-payments.php',
    'services' => 'billing-services.php',
    'providers' => 'billing-providers.php',
    'documents' => 'billing-documents.php',
    'reports' => 'billing-reports.php',
    'statistics' => 'billing-statistics.php',
]
```

Remove customer requests, raw RADIUS, IP pools, maintenance, and customer-intelligence shortcuts from Billing navigation.

- [ ] **Step 2: Register every Billing route as module `billing`**

Add the six new files and change `billing-statistics.php` ownership from `statistics` to `billing`. Keep Network and Message Center routes mapped to their own modules.

- [ ] **Step 3: Keep old workflow URLs compatible**

Do not remove customer profile/edit, cash payment, or payment history routes. Remove `billing-requests.php` and `billing-operations-check.php` from the visible Billing nav while preserving direct compatibility access.

- [ ] **Step 4: Run navigation and role tests**

Run:

```powershell
D:\xampp\php\php.exe tools\billing-nav-layout-smoke.php
D:\xampp\php\php.exe tools\portal-module-access-smoke.php
```

Expected: ten links, one active section, and unauthorized roles receive `403` from both full and partial Billing routes.

### Task 5: Lightweight Billing Overview

**Files:**
- Modify: `views/billing/overview.php`
- Test: `tools/billing-module-separation-smoke.php`

- [ ] **Step 1: Render six Billing KPIs only**

Use customer count, active customer count, active service count, expiring/suspended count, payments today, and debt total. Do not query or render raw RADIUS traffic, CRM, NOC, Finance, AI, or maintenance data.

- [ ] **Step 2: Render at most three charts**

Render daily payments, customer billing status, and service status. Each chart uses no more than 12 rows.

- [ ] **Step 3: Add alerts, recent events, and shortcuts**

Show at most five expiring/debt alerts and five recent payments. Shortcuts link to the ten canonical Billing pages.

- [ ] **Step 4: Measure before and after**

Use the render harness to record HTML bytes, section count, table count, and headings. Expected overview constraints: at most six KPIs, three charts, one short recent list, and no foreign-domain heading.

### Task 6: Billing-Only Statistics And Reports

**Files:**
- Modify: `billing-statistics-results.php`
- Modify: `views/billing/statistics.php`
- Modify: `views/billing/_statistics-results.php`
- Modify: `views/billing/reports.php`
- Modify: `tools/billing-statistics-selector-smoke.php`

- [ ] **Step 1: Replace mixed metrics with Billing datasets**

Use these selector keys:

```php
['all', 'payments', 'customers', 'services', 'packages', 'providers']
```

Remove expense, Finance debt, raw RADIUS traffic/auth, optical, AI revenue, and network quality datasets.

- [ ] **Step 2: Keep selected metric and date range in the URL**

The statistics form sends `metric`, `range`, `start_date`, and `end_date`. `all` renders at most four charts; a selected metric renders one chart.

- [ ] **Step 3: Add report-context mapping**

`billing-reports.php?report=payments` must render payment report statistics, while `report=services` renders service statistics. It must never fall back to an unrelated global statistics page.

- [ ] **Step 4: Run statistics tests**

Run:

```powershell
D:\xampp\php\php.exe tools\billing-statistics-selector-smoke.php
D:\xampp\php\php.exe tools\billing-module-separation-smoke.php --statistics
```

Expected: Billing dataset keys only, maximum four all-mode charts, one selected chart, and no foreign table names in the Billing library.

### Task 7: Partial Filters, Pagination, And History

**Files:**
- Modify: `billing-section-page.php`
- Modify: `billing-module-partial.php`
- Modify: `views/billing/module.php`
- Modify: `billing-customers.php`
- Modify: `billing-payments.php`
- Create: `views/billing/_customers-results.php`
- Create: `views/billing/_payments-results.php`
- Test: `tools/billing-module-separation-smoke.php`

- [ ] **Step 1: Add delegated partial-refresh controller**

Intercept only forms and links marked `data-billing-filter`, `data-billing-pagination`, or `data-billing-statistics-form`. Use `AbortController`, replace the marked result panel, preserve scroll, and push canonical URL state.

- [ ] **Step 2: Add back/forward restoration**

On `popstate`, reload the current Billing panel with `history: 'none'`. Never call `window.location.reload()` for filters, pagination, or chart selection.

- [ ] **Step 3: Extract customer and payment result panels**

Keep their existing queries and write handlers. Move only the filter summary, table, and pagination markup into focused fragments so partial requests do not render the full document.

- [ ] **Step 4: Verify partial behavior**

Run:

```powershell
D:\xampp\php\php.exe tools\billing-module-separation-smoke.php --ajax
```

Expected markers: partial endpoint, `fetch`, `AbortController`, `history.pushState`, `popstate`, scroll preservation, and exactly one replacement panel.

### Task 8: Regression And Browser Verification

**Files:**
- Test: all modified and created PHP files
- Test: Billing, role, provider, payment, portal, Finance, HR, and Inventory smoke suites

- [ ] **Step 1: Lint all changed PHP files**

Run `D:\xampp\php\php.exe -l` for the Billing library, renderers, routes, views, tests, `billing-ui.php`, `config.php`, and the HR modal fix in `operations-center.php`.

- [ ] **Step 2: Run the focused regression suite**

Run:

```powershell
D:\xampp\php\php.exe tools\billing-module-separation-smoke.php
D:\xampp\php\php.exe tools\billing-ui-smoke.php
D:\xampp\php\php.exe tools\billing-flow-smoke.php
D:\xampp\php\php.exe tools\billing-statistics-selector-smoke.php
D:\xampp\php\php.exe tools\provider-api-smoke.php
D:\xampp\php\php.exe tools\portal-module-access-smoke.php
D:\xampp\php\php.exe tools\finance-module-separation-smoke.php
D:\xampp\php\php.exe tools\hr-module-separation-smoke.php
D:\xampp\php\php.exe tools\inventory-module-separation-smoke.php
```

Expected: zero failures.

- [ ] **Step 3: Verify in the in-app browser**

Check desktop and mobile behavior:

1. Overview contains only Billing KPIs/charts.
2. All ten canonical pages are reachable and one is active.
3. A 50-row list paginates without full-page reload.
4. Statistics selection updates only its panel and URL.
5. Back/forward restores the prior chart/filter.
6. Any modal remains inside the viewport and closes by button, backdrop, and Escape.
7. Console has no errors.

- [ ] **Step 4: Final verification checkpoint**

Record test counts, before/after render measurements, browser evidence, changed files, remaining risks, and rollback steps. No merge or PR action is available because this workspace has no git metadata.
