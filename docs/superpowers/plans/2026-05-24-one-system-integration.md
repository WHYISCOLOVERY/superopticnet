# One System Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `superopticnet` the single operator-facing system while `oltidareetme` remains the operational engine behind working SSO, module wrappers, and shared workflows.

**Architecture:** `superopticnet` remains the unified shell and customer-facing context layer. `oltidareetme` continues to run as the operational backend and SPA, but is opened only through signed SSO handoff and wrapped in stable module entry pages. Critical operational areas are then progressively surfaced natively inside `superopticnet` without breaking the already working legacy flows.

**Tech Stack:** PHP 8, MySQL, local Node MGS app, signed SSO handoff, CLI smoke tests

---

### Task 1: Prove and stabilize legacy SSO bridge

**Files:**
- Create: `D:\xampp\htdocs\superopticnet\tools\mgs-sso-bridge-smoke.php`
- Modify: `D:\xampp\htdocs\superopticnet\mgs-bridge.php`
- Modify: `D:\xampp\htdocs\superopticnet\config.php`
- Modify: `D:\xampp\htdocs\superopticnet\config.local.php`

- [ ] Write failing smoke that renders `mgs-bridge.php`, extracts iframe SSO URL, consumes token, refreshes auth, and verifies at least one protected MGS API returns `200`.
- [ ] Run smoke and capture whether failure is URL generation, cookie issuance, refresh, or protected API access.
- [ ] Implement minimal bridge/auth fix at the failing boundary only.
- [ ] Re-run smoke until SSO handoff and protected API access both pass.

### Task 2: Stabilize operations wrappers for legacy modules

**Files:**
- Modify: `D:\xampp\htdocs\superopticnet\operations-center.php`
- Modify: `D:\xampp\htdocs\superopticnet\billing-ui.php`
- Modify: `D:\xampp\htdocs\superopticnet\noc-devices.php`
- Modify: `D:\xampp\htdocs\superopticnet\tools\operations-module-smoke.php`

- [ ] Extend smoke to verify working wrapper routes for inventory, finance, technical, HR, KPI, reports, logs, scanner, and monitor.
- [ ] Fix wrapper routes, empty-state handling, and module deep links so operator navigation stays inside the single-system shell.
- [ ] Re-run module smoke and billing smoke until wrappers are stable.

### Task 3: Finish customer profile messaging and remaining network integration

**Files:**
- Modify: `D:\xampp\htdocs\superopticnet\billing-customer-profile.php`
- Modify: `D:\xampp\htdocs\superopticnet\noc-devices.php`
- Modify: `D:\xampp\htdocs\superopticnet\mgs-integration-lib.php`

- [ ] Keep message history/composer layout wide and usable inside customer profile.
- [ ] Add live ONT optical read action and wire modem-bot live OLT query through the learned HSGQ command path.
- [ ] Align FreeRADIUS accounting/CoA handling with the existing router-side config without changing router behavior first.
