# Role Modules and AJAX Statistics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build role-filtered employee modules, remove unrelated modules from Billing navigation, and refresh statistics charts without a full page reload.

**Architecture:** Central module definitions and role access rules live in `config.php`. `billing_require_employee()` enforces inferred module access for landing and deep-link pages, while `portal.php` renders the same authorized catalog. Statistics results move to a focused PHP fragment that serves both full and partial responses.

**Tech Stack:** PHP 8, MySQL/PDO, server-rendered HTML, vanilla JavaScript, existing smoke-test scripts.

---

### Task 1: Role module authorization

**Files:**
- Modify: `config.php`
- Modify: `billing-ui.php`
- Test: `tools/portal-module-access-smoke.php`

- [ ] Write assertions for all eight modules, the approved role matrix, and request-to-module resolution.
- [ ] Run `php tools/portal-module-access-smoke.php` and confirm the missing-function failure.
- [ ] Add module catalog, role access, request resolution, and forbidden redirect handling.
- [ ] Re-run the smoke test and confirm it passes.

### Task 2: Portal grouping and Billing navigation

**Files:**
- Modify: `portal.php`
- Modify: `billing-ui.php`
- Test: `tools/portal-module-access-smoke.php`

- [ ] Add source assertions for the role-filtered module grid and Billing-only navigation.
- [ ] Run the test and confirm the missing markers and extra navigation groups fail.
- [ ] Replace repeated employee module links with the authorized module catalog.
- [ ] Limit Billing navigation to dashboard, customer, and Billing groups.
- [ ] Re-run the focused smoke test.

### Task 3: Statistics partial rendering

**Files:**
- Create: `billing-statistics-results.php`
- Modify: `billing-statistics.php`
- Modify: `tools/billing-statistics-selector-smoke.php`

- [ ] Add assertions for the AJAX form, replaceable result region, partial request flag, fetch call, history update, and normalized dates.
- [ ] Run `php tools/billing-statistics-selector-smoke.php` and confirm the new assertions fail.
- [ ] Extract chart results into the shared fragment and add the partial-response branch.
- [ ] Add submit interception, loading/error feedback, result replacement, form date synchronization, and URL history update.
- [ ] Re-run the selector smoke test.

### Task 4: Regression and browser verification

**Files:**
- Verify: `portal.php`
- Verify: `billing-statistics.php`
- Verify: `admin-users.php`

- [ ] Run PHP lint on every changed PHP file.
- [ ] Run focused module, statistics, operations, HR layout, HR salary/score, and Billing UI smoke tests.
- [ ] Verify the eight-module portal and role filtering in the browser.
- [ ] Submit statistics filters in the browser and verify only the results region changes.
- [ ] Confirm the employee modal remains aligned with no overflowing area labels.
