# Inventory Module Separation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the existing Inventory mega-page into lazy-loaded, paginated canonical tabs without changing established inventory business rules.

**Architecture:** Add an Inventory query/context library, selected-tab views, and an authenticated partial endpoint. The Operations shell renders only the active Inventory context and delegates local navigation and filter changes through AJAX with URL/history synchronization.

**Tech Stack:** PHP 8, PDO/MySQL, existing portal CSS/JavaScript, CLI smoke tests, in-app browser verification.

---

### Task 1: Lock the Inventory contract with a failing smoke test

**Files:**
- Create: `tools/inventory-module-separation-smoke.php`

- [ ] Assert the twelve canonical tab keys and legacy `stock`, `handover`, and `lifecycle` mappings.
- [ ] Assert each table page returns at most 50 rows and Inventory statistics contain no billing, finance, HR, RADIUS, or network datasets.
- [ ] Render Overview and assert it contains no create movement/category/handover form.
- [ ] Render every owning tab and assert its marker and expected existing action.
- [ ] Assert AJAX/history source markers and `billing_require_employee(false, 'inventory')` in the partial endpoint.
- [ ] Run `D:\xampp\php\php.exe tools\inventory-module-separation-smoke.php` and confirm it fails because the new module files do not exist.

### Task 2: Implement paginated Inventory data access

**Files:**
- Create: `inventory-module-lib.php`
- Test: `tools/inventory-module-separation-smoke.php`

- [ ] Implement tab normalization, legacy mapping, date normalization, pagination capped at 50, and safe search filters.
- [ ] Implement paginated queries for catalog, devices, materials, drums, technician/customer assets, handovers, returns, inspections, RMA, stock movements, and lifecycle movements.
- [ ] Implement Inventory-only KPIs and aggregate datasets with at most twelve yearly buckets.
- [ ] Implement `app_inventory_build_context()` so only the selected tab queries its primary table.
- [ ] Run the query-only smoke mode and confirm all query assertions pass.

### Task 3: Build selected-tab views and modal workflows

**Files:**
- Create: `views/inventory/module.php`
- Create: `views/inventory/_pagination.php`
- Create: `views/inventory/_statistics-results.php`
- Create: `views/inventory/overview.php`
- Create: `views/inventory/catalog.php`
- Create: `views/inventory/devices.php`
- Create: `views/inventory/materials.php`
- Create: `views/inventory/cable-drums.php`
- Create: `views/inventory/technicians.php`
- Create: `views/inventory/customers.php`
- Create: `views/inventory/handover.php`
- Create: `views/inventory/returns-inspection.php`
- Create: `views/inventory/repair-rma.php`
- Create: `views/inventory/movements.php`
- Create: `views/inventory/statistics.php`

- [ ] Render only the selected view from `module.php`.
- [ ] Keep category, stock movement, and handover forms in bounded modals on their owning tabs.
- [ ] Use one large table per tab, server-side pagination controls, responsive mobile card rendering, and scoped empty states.
- [ ] Keep Overview limited to KPIs, critical stock, recent movement summaries, and shortcuts.

### Task 4: Wire partial rendering and Operations integration

**Files:**
- Create: `inventory-module-partial.php`
- Modify: `operations-center.php`

- [ ] Require the Inventory library and build `$inventoryContext` only for `section=inventory`.
- [ ] Replace the runtime Inventory mega-page with `views/inventory/module.php`.
- [ ] Preserve `inventory_tab` through POST flash redirects.
- [ ] Add delegated Inventory tab/filter/pagination/statistics AJAX, abort handling, modal controls, `pushState`, scroll preservation, and `popstate` restoration.
- [ ] Render only `_statistics-results.php` when `inventory_fragment=statistics-results`.

### Task 5: Regression and browser verification

**Files:**
- Modify: `tools/operations-module-smoke.php`
- Modify: `tools/billing-ui-smoke.php`
- Modify only stale Inventory source-marker tests that reference the retired mega-page layout.

- [ ] Run PHP lint for every changed PHP file.
- [ ] Run Inventory separation, lifecycle, Operations, portal access, finance asset, and Billing UI suites.
- [ ] Verify live tab AJAX, one-chart selection, back navigation, modal layout, 50-row cap, and no console errors in the browser.
- [ ] Record exact verification counts and any remaining limitation in the completion report.
