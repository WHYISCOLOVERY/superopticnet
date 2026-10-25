# Stage 5 - Unified Agent Workspace Acceptance

Date: 2026-07-25
Status: Pilot enabled

## Scope delivered

Stage 5 implements the agent contract from `32_UNIFIED_UX_AUDIT.md` sections
10, 10.1, 10.2 and the Merhele 5 acceptance. The agent creates no new business
backend: it orchestrates the existing idempotent, audited, permissioned service
layer, gated by a single canonical contract.

### 1. Service allowlist registry (audit 10.2)

New `agent-workspace-lib.php` defines `app_agent_action_registry()`. Every
agent-writable action declares the module(s) whose service permission the actor
must hold, its risk tier, whether confirmation is mandatory, how it is reversed,
and whether it is a go-live blocker:

- Allowed (orchestrating existing services): `create_ticket`,
  `send_customer_message`, `inventory_movement`, `radius_refresh_disconnect`,
  `ont_restart_request`, `ont_control_request`.
- Registered but **blocked** as go-live blockers, never agent-writable:
  `billing_payment`, `finance_payout`, `noc_command`, `role_change`.

Permission uses the canonical `app_portal_can_access_module()` (service
permission, not UI role). An action may accept more than one module (e.g.
`create_ticket` accepts `messaging` OR `network`, because both customer-service
and field/NOC roles raise tickets), while `send_customer_message` stays
`messaging`-only.

### 2. Agent contract (audit 10.1)

- `app_agent_action_gate()` — allowlist -> go-live blocker refusal -> module
  permission. Go-live blockers are refused for everyone, including admin.
- `app_agent_action_preview()` — structured who / object / business impact /
  external-provider impact / confirmation-required / reversible (step 4).
- `app_agent_prepare_action()` — bundles injection detection, the gate, and the
  duplicate-retry guard, returning the preview when clean.
- `app_agent_detect_injection()` / `app_agent_sanitize_for_prompt()` — block
  prompt-injection directives and redact secrets/PII (passwords, API keys, FIN
  codes, long card/id runs) before any text reaches the model or audit (step 10).
- `app_agent_idempotency_existing_id()` — duplicate-retry guard by
  `idempotency_key` (step 7 / Merhele 5).
- `app_agent_action_undo()` — reversible actions move to a new `reversed`
  status with a compensating record; **no hard delete** (step 9).

This is additive: the working `copilot-agent.php` pending -> confirm -> execute
-> audit flow keeps its behavior and now also passes every staged action through
`app_agent_prepare_action()` for defense-in-depth. The canonical gate runs on
the resolved employee account alongside the existing role check.

### 3. Schema

Migration `20260725_060_stage5_agent_workspace`:
- adds `reversed` to the `mgs_operation_actions.status` enum (additive),
- seeds the disabled `stage5_agent_workspace` flag.
The canonical audit schema definitions (`audit-log-lib.php`,
`mgs-integration-lib.php`) were updated to include `reversed` so the audit
drift detector stays consistent.

## Verification

- `tools/stage5-agent-eval-suite.php`: **46 pass / 0 fail**, flag off and on.
  It blocks the four Merhele 5 attack classes and more:
  - unauthorized action (warehouse -> network, noc -> inventory) denied;
  - go-live blockers (`billing_payment`, `finance_payout`, `noc_command`,
    `role_change`) refused even for admin;
  - unknown kind rejected;
  - prompt injection blocked (gate and `prepare`);
  - secret/PII redaction (password, API key, long card);
  - duplicate retry detected by idempotency key and blocked;
  - undo sets `reversed` (no hard delete), second undo is a no-op, fixture
    cleaned up;
  - technician allowed `create_ticket` via network, blocked from
    `send_customer_message`.
- `tools/copilot-agent-smoke.php`: `status: ok` (existing agent flow intact —
  ticket confirmed, radius confirmed, inventory/ont cancelled).
- `tools/operations-action-permission-smoke.php`: passed.
- `tools/audit-permission-smoke.php` and `tools/audit-ledger-smoke.php`: ok
  (enum drift resolved).
- PHP lint clean on all touched files.
- Rollback cycle verified: disable -> migration rollback -> reapply -> re-enable.

## Out of scope / follow-up

- Opening go-live blocker kinds to agent write stays blocked until their
  underlying blockers (payment/RADIUS idempotency, NOC action permission,
  role-change governance) are resolved in Merhele 0/6.
- A dedicated agent chat UI surface consuming the preview/confirmation contract
  can be built on top of `app_agent_action_preview()`; the current wiring keeps
  the Telegram/superpilot confirmation flow.
- Model-side intent extraction remains in `copilot-engine.php`; Stage 5 governs
  the action execution boundary, not the NL parser.
