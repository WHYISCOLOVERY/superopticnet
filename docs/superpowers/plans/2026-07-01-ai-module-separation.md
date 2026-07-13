# AI Module Separation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Separate existing Superpilot capabilities into a canonical, permission-protected AI module with lightweight routes and partial updates.

**Architecture:** Shared `ai-module-lib.php` owns section validation, bounded queries, and view context. Thin canonical routes use one page controller, while one partial endpoint updates only the selected AI result/list panel. Existing Copilot APIs and `mgs_operation_actions` remain authoritative.

**Tech Stack:** PHP 8, PDO/MySQL, existing portal CSS, Fetch API, History API, existing Copilot engine and agent endpoints.

---

### Task 1: Lock The AI Module Contract

**Files:**
- Create: `tools/ai-module-separation-smoke.php`
- Modify: `config.php`

- [ ] Write a smoke test asserting ten canonical sections/routes, Overview as the portal landing route, `ai` backend permission mapping, the 50-row maximum, old HTML compatibility redirect, and preserved JSON context.
- [ ] Run `php tools/ai-module-separation-smoke.php` and verify it fails because the AI module contract does not exist.
- [ ] Add the canonical AI routes to the central module catalog and request-module map.
- [ ] Re-run the smoke and keep remaining assertions red for the missing shell.

### Task 2: Build Shared AI Context And Thin Routes

**Files:**
- Create: `ai-module-lib.php`
- Create: `ai-section-page.php`
- Create: `ai-module-partial.php`
- Create: `ai-dashboard.php`
- Create: `ai-copilot.php`
- Create: `ai-customer-analytics.php`
- Create: `ai-network-analytics.php`
- Create: `ai-finance-analytics.php`
- Create: `ai-risks.php`
- Create: `ai-recommendations.php`
- Create: `ai-automations.php`
- Create: `ai-audit.php`
- Create: `ai-model-status.php`

- [ ] Implement `app_ai_sections()`, strict section selection, and pagination clamped to 50.
- [ ] Implement section-specific context builders so hidden sections are not queried.
- [ ] Query automation/audit rows with `COUNT`, `LIMIT`, and `OFFSET`; filter only Copilot/AI action families.
- [ ] Require the `ai` module permission in full and partial controllers.
- [ ] Re-run the smoke and verify route/context assertions pass.

### Task 3: Render The AI Views

**Files:**
- Create: `views/ai/module.php`
- Create: `views/ai/_pagination.php`
- Create: `views/ai/overview.php`
- Create: `views/ai/copilot.php`
- Create: `views/ai/customer-analytics.php`
- Create: `views/ai/network-analytics.php`
- Create: `views/ai/finance-analytics.php`
- Create: `views/ai/risks.php`
- Create: `views/ai/recommendations.php`
- Create: `views/ai/automations.php`
- Create: `views/ai/audit.php`
- Create: `views/ai/model-status.php`

- [ ] Render one shared page header, ten local tabs, and only the selected section.
- [ ] Keep Overview at six KPIs and three status charts maximum.
- [ ] Render analysis output as findings, risks, recommendations, confidence, explanation, and canonical links without foreign-domain raw tables.
- [ ] Render action lists as one responsive table/card list with bounded pagination.

### Task 4: Add Partial Updates And Compatibility

**Files:**
- Modify: `ai-section-page.php`
- Modify: `mgs-copilot-context.php`
- Modify: `tools/ai-module-separation-smoke.php`

- [ ] Add Fetch/AbortController handling for Copilot, customer analysis, filters, and pagination.
- [ ] Update URL state with History API and restore it on `popstate` without a full-page reload.
- [ ] Redirect old HTML context requests to Network analytics while preserving the existing JSON response.
- [ ] Run the AI smoke and verify all assertions pass.

### Task 5: Regression And Browser Verification

**Files:**
- Test: `tools/ai-module-separation-smoke.php`
- Test: `tools/copilot-customer-analysis-smoke.php`
- Test: `tools/copilot-agent-smoke.php`
- Test: `tools/portal-module-access-smoke.php`

- [ ] Run PHP lint for all new and modified PHP files.
- [ ] Run the AI, Copilot, portal access, Billing, Finance, HR, and Inventory separation smoke suites.
- [ ] Verify desktop and mobile AI Overview, tab navigation, partial customer analysis, pagination, browser history, and denied-role behavior in the local browser.
- [ ] Record any unavailable live provider/model checks as residual risk rather than reporting them as validated.
