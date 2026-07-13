# Copilot Action Agent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the existing Telegram modem bot into a confirmation-gated operations Copilot that can answer customer, inventory, employee, ticket and RADIUS questions, then execute safe actions only after the requesting employee confirms.

**Architecture:** Add a focused `copilot-agent.php` router beside the existing `copilot-engine.php`. The router classifies Telegram/free-text questions, uses existing read models for customer/modem/inventory/employee data, creates `mgs_operation_actions` rows in `pending` status for mutating actions, and executes them only when the employee sends `TESDIQ <id>`. Existing MGS audit, ticket, RADIUS disconnect queue, and inventory movement functions remain the source of truth.

**Tech Stack:** PHP 8/XAMPP, MySQL/PDO, Telegram getUpdates worker, existing `mgs_operation_actions`, `portal_tickets`, `radius_session_actions`, `inventory_stock_*`.

---

### Task 1: Copilot Router And Pending Action Store

**Files:**
- Create: `D:\xampp\htdocs\superopticnet\copilot-agent.php`
- Modify: `D:\xampp\htdocs\superopticnet\tools\process-telegram-modem-search-bot.php`
- Test: `D:\xampp\htdocs\superopticnet\tools\copilot-agent-smoke.php`

- [ ] Add read-only answer routing for customer/modem, inventory ownership, employee inventory, and employee KPI questions.
- [ ] Add pending action creation for ticket creation, RADIUS refresh/disconnect, inventory issue, and inventory return.
- [ ] Store pending actions in `mgs_operation_actions` with `status='pending'`, `action_type='copilot_action'`, `entity_type` matching the action family, and a full `payload_json`.
- [ ] Add `TESDIQ <id>` and `LEGV <id>` handling.
- [ ] Execute confirmed actions through existing functions: `app_db_insert_ticket`, `app_radius_request_disconnect`, `app_billing_sync_radius_account`, and `app_mgs_record_inventory_movement`.

### Task 2: Telegram Worker Integration

**Files:**
- Modify: `D:\xampp\htdocs\superopticnet\tools\process-telegram-modem-search-bot.php`
- Test: `D:\xampp\htdocs\superopticnet\tools\copilot-agent-smoke.php`

- [ ] Replace direct modem-only reply building with `app_copilot_agent_handle_message`.
- [ ] Keep `/start` and `/help`, but describe Copilot commands and confirmation format.
- [ ] Record bot queries and action IDs in `mgs_operation_actions` for audit.

### Task 3: Verification

**Files:**
- Create: `D:\xampp\htdocs\superopticnet\tools\copilot-agent-smoke.php`

- [ ] Build smoke data for a customer, employee, ticket, and inventory item.
- [ ] Verify read-only customer analysis returns `read_only=true`.
- [ ] Verify inventory ownership question returns the assigned employee/customer context.
- [ ] Verify ticket/RADIUS/inventory actions first return pending confirmation IDs.
- [ ] Verify `TESDIQ <id>` executes and `LEGV <id>` skips.
- [ ] Run syntax checks and XAMPP PHP smoke tests.
