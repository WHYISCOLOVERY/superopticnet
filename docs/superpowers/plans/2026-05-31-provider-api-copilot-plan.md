# Provider API And Billing Copilot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add production-ready terminal payment API endpoints and a read-only customer diagnostics Copilot layer for billing/support operators and Telegram bot integration.

**Architecture:** Provider endpoints live under `/api/provider/*` and use token/HMAC authentication, idempotent transaction IDs, and existing billing payment application functions. Copilot analysis is read-only and builds structured evidence from customer profile, payments, tickets, messages, RADIUS/service data and ONT/modem snapshots before any future LLM/Telegram action layer is allowed.

**Tech Stack:** PHP 8, MySQL/PDO, existing `portal_accounts`, `billing_payments`, `billing_radius_services`, `communication_messages`, and Nginx provider API route/rate-limit layer.

---

### Task 1: Provider API Security And Customer Lookup

**Files:**
- Create: `provider-api-lib.php`
- Create: `api/provider/customer-lookup.php`
- Modify: `config.local.example.php`
- Modify: `config.local.production.example.php`
- Test: `tools/provider-api-smoke.php`

- [x] Implement shared provider auth with bearer token and optional timestamped HMAC signature.
- [x] Implement customer lookup by subscriber code, contract number or login.
- [x] Return only provider-safe fields: code, full name, balance, status, package, remaining days.

### Task 2: Provider Payment Apply Endpoint

**Files:**
- Modify: `provider-api-lib.php`
- Create: `api/provider/payment.php`
- Test: `tools/provider-api-smoke.php`

- [x] Accept provider, terminal, transaction ID, customer code, amount and paid time.
- [x] Make transaction idempotent with `provider:transaction_id` external IDs.
- [x] Apply payment through `app_billing_apply_customer_payment()` so RADIUS sync and day extension stay centralized.

### Task 3: Read-Only Billing Copilot Evidence Engine

**Files:**
- Create: `copilot-engine.php`
- Create: `api/copilot/customer-analyze.php`
- Test: `tools/copilot-customer-analysis-smoke.php`

- [x] Resolve customer from a question by code/login/name.
- [x] Gather evidence from billing profile, payments, statements, services, ONT/modem data, tickets and messages.
- [x] Return likely causes, recommended next steps, confidence and evidence without changing data.

### Task 4: Validation

**Commands:**
- `php -l provider-api-lib.php`
- `php -l api/provider/customer-lookup.php`
- `php -l api/provider/payment.php`
- `php -l copilot-engine.php`
- `php -l api/copilot/customer-analyze.php`
- `php tools/provider-api-smoke.php`
- `php tools/copilot-customer-analysis-smoke.php`

**Notes:** Production cutover still requires DNS/nginx routing, provider IP whitelist, real token/HMAC secrets, and a backup before moving `billing.superoptic.net` away from the old billing app.
