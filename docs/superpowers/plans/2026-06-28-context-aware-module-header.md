# Context Aware Module Header Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the correct module identity and home destination in every shared portal header.

**Architecture:** Add a small pure helper in `billing-ui.php` that derives the brand destination from the existing Billing navigation boundary. Add one active-section title map in `operations-center.php` and reuse it in both `<title>` and `billing_render_header()`.

**Tech Stack:** PHP 8, server-rendered HTML, existing PHP smoke tests, in-app browser verification.

---

### Task 1: Header destination helper

**Files:**
- Modify: `billing-ui.php`
- Test: `tools/portal-module-access-smoke.php`

- [ ] Add a failing assertion that `dashboard` resolves to `billing-dashboard.php` while `statistics`, `messages`, and `noc-devices` resolve to `portal.php`.
- [ ] Run `D:\xampp\php\php.exe tools\portal-module-access-smoke.php` and confirm `module_header_brand_boundary_missing` fails.
- [ ] Implement `billing_header_brand_href()` using `billing_nav_contains_active()`.
- [ ] Use the helper in `billing_render_header()` and rerun the smoke test.

### Task 2: Operations section titles

**Files:**
- Modify: `operations-center.php`
- Test: `tools/portal-module-access-smoke.php`

- [ ] Add source assertions for the Finance, HR, Inventory, Network, Statistics, and AI title map.
- [ ] Run the smoke test and confirm the title-map assertion fails.
- [ ] Define `$operationsPageTitles`, select `$operationsPageTitle`, and reuse it in the document title and shared header call.
- [ ] Run PHP lint and the focused smoke test.

### Task 3: Regression and browser verification

**Files:**
- Verify: `billing-ui.php`
- Verify: `operations-center.php`

- [ ] Run `portal-module-access-smoke.php`, `operations-module-smoke.php`, and `billing-ui-smoke.php`.
- [ ] Open Finance, HR, Statistics, and Billing pages and verify title text, brand destination, and Billing navigation visibility.
- [ ] Confirm no console errors are introduced.
