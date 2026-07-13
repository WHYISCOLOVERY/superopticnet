# Four Module UI Consistency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove duplicated analytics and inconsistent Billing layouts while making KPI values overflow-safe across Finance, HR, Inventory, and Billing.

**Architecture:** Keep existing customer/payment controllers and write handlers, but render a shared Billing module intro/local-tab component inside them. Overview pages own summary/alerts; Statistics pages exclusively own cross-metric charts. Shared module CSS enforces bounded, wrapping KPI values.

**Tech Stack:** PHP 8, PDO/MySQL, existing portal CSS, existing PHP smoke renderer, in-app browser.

---

### Task 1: Lock The Cross-Module UI Contract

**Files:**
- Create: `tools/four-module-ui-consistency-smoke.php`

- [ ] Render Billing Overview, Customers, Payments, Statistics, Finance Overview/Statistics, HR Overview/Statistics, and Inventory Overview/Statistics through `tools/billing-ui-smoke.php`.
- [ ] Assert shared Billing chrome on Customers/Payments, no chart markers on Billing/Finance Overview, chart markers on Statistics, no Payments Analytics link/chart cards, at most four Payments KPI markers, and overflow-safe CSS markers in all four module shells.
- [ ] Run `D:\xampp\php\php.exe tools\four-module-ui-consistency-smoke.php` and verify it fails on the current legacy layouts and duplicate charts.

### Task 2: Unify Billing Customers And Payments Chrome

**Files:**
- Create: `views/billing/_legacy-module-intro.php`
- Modify: `billing-customers.php`
- Modify: `billing-payments.php`
- Modify: `views/billing/module.php`

- [ ] Add a shared intro component that renders the Billing breadcrumb, selected section title, description, portal link, and ten local tabs.
- [ ] Replace Customers' standalone data-explorer hero with the shared intro and customer-only primary actions.
- [ ] Replace Payments' finance-control hero with the shared intro and payment-only primary actions.
- [ ] Preserve customer modal, payment import, filters, POST handlers, and route URLs unchanged.

### Task 3: Restore Analytics Ownership

**Files:**
- Modify: `views/billing/overview.php`
- Modify: `billing-payments.php`
- Modify: `views/finance/overview.php`
- Modify: `finance-module-lib.php`

- [ ] Remove Billing Overview chart rendering while retaining six KPIs, alerts, recent payments, and shortcuts.
- [ ] Remove Payments daily/provider chart panels and the duplicate Analytics action.
- [ ] Remove Finance Overview chart rendering while retaining KPIs, shortcuts, recent payments, and open debt/receivable alerts.
- [ ] Stop Finance Overview from building full statistics datasets; build only the six Overview KPI values required by the view.

### Task 4: Prevent KPI Overflow Across Four Modules

**Files:**
- Modify: `views/billing/module.php`
- Modify: `views/finance/module.php`
- Modify: `views/hr/module.php`
- Modify: `views/inventory/module.php`
- Modify: `billing-payments.php`

- [ ] Add `min-width:0`, wrapping, and bounded responsive font sizing to each module KPI card/value.
- [ ] Use a four-column responsive Payments KPI grid so long AZN totals remain inside their cards.
- [ ] Verify narrow layouts collapse predictably without body-level horizontal overflow.

### Task 5: Group Module Navigation

**Files:**
- Create: `views/_module-group-nav.php`
- Modify: `billing-ui.php`
- Modify: `views/billing/module.php`
- Modify: `views/billing/_legacy-module-intro.php`
- Modify: `views/finance/module.php`
- Modify: `views/hr/module.php`
- Modify: `views/inventory/module.php`

- [ ] Mark the existing four-group Billing navigation as the canonical grouped module navigation.
- [ ] Remove duplicate flat Billing tab rows from shared and legacy Billing content.
- [ ] Render Finance, HR, and Inventory routes through the shared grouped navigation component using domain-specific groups.
- [ ] Keep active group/child state visible and preserve native keyboard/details behavior.

### Task 6: Verify Regression And Browser Behavior

**Files:**
- Test: `tools/four-module-ui-consistency-smoke.php`
- Test: `tools/billing-module-separation-smoke.php`
- Test: `tools/billing-flow-smoke.php`
- Test: `tools/finance-module-separation-smoke.php`
- Test: `tools/hr-module-separation-smoke.php`
- Test: `tools/inventory-module-separation-smoke.php`
- Test: `tools/billing-ui-smoke.php`

- [ ] Run the new smoke and all existing module regression suites.
- [ ] Run PHP lint for every modified and new PHP file.
- [ ] Verify Billing Overview, Customers, Payments, Statistics, and Finance Overview/Statistics in the browser at desktop and narrow widths.
- [ ] Confirm the payment total is fully visible, local tabs remain usable, and Statistics remains the only cross-metric chart page.
