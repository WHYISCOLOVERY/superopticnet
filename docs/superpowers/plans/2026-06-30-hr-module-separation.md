# HR Module Separation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the HR mega-page with selected-tab lazy loading, modal workflows, 50-row server pagination, and HR-only contextual statistics while preserving current business logic.

**Architecture:** Keep `operations-center.php?section=hr` as the canonical controller and extract selected-tab queries into `hr-module-lib.php` and rendering into `views/hr`. The full page and `hr-module-partial.php` share one context builder and one renderer, preventing duplicate implementations.

**Tech Stack:** PHP 8, PDO/MySQL, existing portal CSS, Fetch API, existing CLI render/smoke harness.

---

### Task 1: Define the HR separation contract

**Files:**
- Create: `tools/hr-module-separation-smoke.php`

- [ ] Write a failing test requiring the eight canonical tabs, overview isolation, selected-tab workflow markers, maximum 50 rows, operational employee filtering, AJAX/history markers, and HR-only statistics.
- [ ] Run `.\php.cmd tools\hr-module-separation-smoke.php` and confirm failure occurs because the new HR module files and markers do not exist.

### Task 2: Add selected-tab query and pagination APIs

**Files:**
- Create: `hr-module-lib.php`
- Test: `tools/hr-module-separation-smoke.php`

- [ ] Implement `app_hr_tabs`, `app_hr_tab`, `app_hr_pagination`, shared page metadata, operational employee filtering, and DB-backed pages for employees, compensation, attendance, leave, travel, score, payroll, documents, and cost reports.
- [ ] Implement HR-only aggregate statistics with index-friendly timestamp ranges and at most twelve year buckets.
- [ ] Run `.\php.cmd tools\hr-module-separation-smoke.php --queries` and confirm page limits, offsets, totals, and domain boundaries pass.

### Task 3: Extract the HR views

**Files:**
- Create: `views/hr/module.php`
- Create: `views/hr/overview.php`
- Create: `views/hr/employees.php`
- Create: `views/hr/attendance.php`
- Create: `views/hr/leave-travel.php`
- Create: `views/hr/performance.php`
- Create: `views/hr/payroll.php`
- Create: `views/hr/documents.php`
- Create: `views/hr/statistics.php`
- Create: `views/hr/_pagination.php`
- Create: `views/hr/_statistics-results.php`
- Modify: `operations-center.php`

- [ ] Render only the selected tab and remove the old HR mega-page block.
- [ ] Keep overview to six KPIs/four insights and no full forms.
- [ ] Move compensation, attendance, leave, travel, score, payroll, document, status, and Finance-bridge forms into owning-tab modals.
- [ ] Render one paginated list per selected view and preserve subview/filter state.
- [ ] Run the HR separation smoke test and confirm tab isolation and row limits pass.

### Task 4: Add HR partial navigation and redirects

**Files:**
- Create: `hr-module-partial.php`
- Modify: `operations-center.php`

- [ ] Protect the partial endpoint with the HR module guard and shared context builder.
- [ ] Add delegated Fetch navigation, `AbortController`, loading/error state, `history.pushState`, `popstate`, and scroll preservation.
- [ ] Replace only HR statistics results for statistics filter submissions.
- [ ] Preserve `hr_tab` in every HR POST flash redirect.
- [ ] Run `.\php.cmd tools\hr-module-separation-smoke.php --ajax` and confirm partial and history contracts pass.

### Task 5: Regression and browser verification

**Files:**
- Update existing HR smoke marker expectations only where the old mega-page contract was intentionally removed.

- [ ] Run PHP lint on every changed PHP file.
- [ ] Run `tools/hr-payroll-legal-smoke.php`, `tools/hr-salary-score-smoke.php`, `tools/hr-leave-travel-docs-smoke.php`, `tools/hr-finance-bridge-smoke.php`, `tools/operations-module-smoke.php`, and `tools/billing-ui-smoke.php`.
- [ ] Browser-check overview, every tab, modal bounds, AJAX transitions, page 2, Back/Forward, HR statistics domain isolation, console logs, and compact/mobile overflow.
- [ ] Record changed files, test totals, render timing, residual data limitations, and the next Inventory phase.
