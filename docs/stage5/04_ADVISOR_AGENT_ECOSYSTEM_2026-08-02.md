# Unified internal agent ecosystem - foundation (CEO/Finance/NOC/Techops/Marketing)

Date: 2026-08-02

## Problem

Serdar (super-admin) asked for the foundation of five internal advisor
agents - CEO, Finance, NOC/network engineer, Technical operations manager,
Marketing - that help govern operators, technicians, warehouse work, and
finance/HR/customer/network data, and propose (never silently execute)
improvements. Two existing contracts had to keep working unchanged:
verified ticket closure (ONT signal/temperature + RADIUS state before
close) and long-inactive-customer campaigns staying proposal-gated before
any queue/send.

## What changed

### `advisor-agent-lib.php` (new)

- `app_advisor_agent_roster()` - five advisor **policy profiles**: label,
  mission, the module(s) each one reads, and whether it is cross-module.
  These are not employee accounts and carry no login/password - the
  foundation smoke test asserts that directly.
- `app_advisor_access_allowed()` - reuses the existing canonical
  `app_portal_can_access_module()`; no new permission system. Admin/
  super_admin always pass. A non-admin actor must hold every module an
  advisor reads, so cross-module advisors (CEO, Techops) are admin-only in
  practice under the current role map, while single-module advisors
  (Finance, NOC, Marketing) are reachable by the matching operational role.
- `app_advisor_build_proposal()` - the one place every proposal envelope is
  built: evidence (source + observed timestamp + detail), risk, required
  permission, approval owner, next action, status. `approval_owner` is
  always `super_admin` in this foundation - Serdar is the sole approval
  authority for any advisor-originated proposal, cross-module or not, until
  a narrower delegated hierarchy is defined.
- Any proposal whose `next_action` maps to a Stage 5 registry kind
  (`agent-workspace-lib.php`) is resolved through that **existing**
  registry - required_permission and go-live-blocker status come from
  `app_agent_action_registry()`, never from a second copy. A proposal that
  names a go-live-blocked kind (e.g. `billing_payment`) is labelled
  `blocked_go_live_blocker:...` and stays blocked, even for a CEO-advisor
  proposal.
- Five read-only briefing functions (`app_advisor_finance_briefing`,
  `_noc_briefing`, `_techops_briefing`, `_marketing_briefing`,
  `_ceo_briefing`) reuse existing service functions
  (`app_finance_overview_kpis`) or minimal read-only aggregate SQL
  (COUNT/SUM against `portal_tickets`, `inventory_stock_items`,
  `portal_accounts`/`billing_payments`, `device_learning_profiles`) - no
  business rule is duplicated. `app_advisor_ceo_briefing()` composes the
  other four rather than re-querying, so the cross-module view cannot drift
  from what each specialist advisor already reported.
- `app_advisor_all_briefings()` - single entry point; advisors the actor
  cannot access are omitted from the result, never returned as an explicit
  denial that would confirm private cross-module data exists.
- `app_advisor_council_run()` - biznes məqsədinin agent ekosisteminə daxil
  olduğu vahid qapıdır. Prompt-injection yoxlanılır, giriş sanitizasiya olunur,
  yalnız aktyorun görə bildiyi məsləhətçilər işləyir və nəticə append-only audit
  jurnalına yazılmasa cavab fail-closed olur. Auditdə məqsəd mətni saxlanmır;
  yalnız hash, təhlükəsiz intent kateqoriyası, advisor/proposal ID-ləri və data
  status sayları qalır.
- Proposal ID-si tam envelope üçün deterministikdir. Summary, action route,
  risk, permission və approval contract dəyişərsə imza da dəyişir; buna görə
  sonradan action dəyişdirilmiş proposal bridge tərəfindən rədd edilir.
- `app_advisor_prepare_proposal_action()` yalnız faktiki designated
  super-admin üçün mövcud Stage 5 action registry/gate preview-sunu hazırlayır.
  O, pending action yaratmır, təsdiqləmir və icra etmir. Uğurlu və rədd edilmiş
  hazırlama cəhdləri sanitizasiya olunmuş append-only audit qeydi yaradır.

### `communication-campaign-lib.php`

- `app_communication_campaign_long_inactive_candidates()` and
  `app_communication_campaign_prepare_long_inactive()` - the marketing
  advisor's next action for its `long_inactive_segment` proposal. This
  fills in PHP logic for the `communication_campaign_recipients` table and
  the `idempotency_key`/`eligibility_json` columns that migration
  `20260802_220_operational_completeness` had already provisioned but no
  code used yet. It follows the **exact same contract** as the existing
  `app_communication_campaign_prepare_holiday()`: it only ever inserts a
  `pending_approval` campaign row (plus one audit row per candidate
  recipient in `communication_campaign_recipients`). Nothing is queued or
  sent until a *different* actor calls the existing
  `app_communication_campaign_approve_and_queue()`, which already refuses
  `creator_cannot_approve`. Long-inactive kampaniyası üçün həmin ikinci aktyor
  faktiki designated super-admin olmalıdır; sadə admin kifayət etmir.
- Long-inactive proposal yaratmaq service səviyyəsində etibarlı actor id və
  canonical `messaging` modul icazəsi tələb edir. UI gizlətməsi icazə yoxlaması
  hesab edilmir.
- `app_communication_campaign_queue_account()` now returns the inserted
  `communication_messages` id (0 on failure) instead of a bare bool, so
  `app_communication_campaign_approve_and_queue()` can also write the
  per-recipient `queue_status`/`message_id` back to
  `communication_campaign_recipients` for audit - a no-op for the existing
  holiday flow, which has no rows in that table.
- Candidate ordering: `(last_payment_at IS NULL) DESC, last_payment_at ASC,
  id DESC` - customers who have never paid at all surface first, then the
  longest-overdue-by-date customers.

### `copilot-agent.php`

- Requires `advisor-agent-lib.php`.
- `app_copilot_agent_match_advisor_intent()` /
  `app_copilot_agent_advisor_briefing_answer()` - a new deterministic,
  read-only branch in `app_copilot_agent_handle_message()` (checked before
  the LLM fallback, alongside the existing permissions/security-anomaly/
  traffic-monetization branches). Asking Superpilot e.g. "CEO məsləhətçisi
  hesabatı" or "marketinq məsləhətçisi" returns the matching advisor
  briefing(s) through `app_advisor_council_run()`, which enforces access,
  injection detection and audit fail-closed behavior. This function never calls
  `app_copilot_agent_pending_action()` - it cannot stage or execute
  anything, only report.

## Governance recap (task-mandated, all enforced in code)

- No second business backend: every briefing reads through existing lib
  functions or read-only aggregate SQL; every write-capable next action
  routes through the existing Stage 5 registry/gate.
- Read-only analysis and proposals are the default; nothing here executes.
- Every proposal carries evidence + source timestamps, risk, required
  permission, approval owner, and next action.
- Serdar/super-admin is the sole approval owner in this foundation.
- Go-live blockers (`billing_payment`, `finance_payout`, `noc_command`,
  `role_change`) stay blocked - an advisor cannot reference one and get a
  usable next action.
- Verified ticket closure (ONT signal/temperature + RADIUS state) was not
  touched; the foundation smoke test asserts the existing contract strings
  are still present in `ticket-resolution-lib.php` rather than
  re-implementing them.
- Long-inactive campaigns stay proposal-gated: distinct-actor approval is
  required before anything queues, verified by a full fixture round trip
  (create candidate -> prepare -> self-approve rejected -> distinct-actor
  approve -> queued, then cleaned up).

## Verification

- `tools/advisor-agent-foundation-smoke.php`: **102 pass / 0 fail** -
  roster shape, access gate (admin vs. single-module vs. cross-module
  actors), signed/deterministic proposal envelope, tamper refusal and denied
  attempt audit, registry reuse for linked actions, go-live-blocker refusal,
  council injection/audit/PII contract, DB-backed briefings for admin vs. a scoped
  actor, envelope-field completeness across every live proposal, a full
  long-inactive campaign fixture (permission denial -> stage -> reject
  non-super-admin/self-approval -> designated super-admin
  approval -> queue -> cleanup), copilot wiring, and the ticket-resolution
  contract check. Registered in `tools/full-system-regression.php`
  (`advisor_agent_foundation`) alongside two new lint entries.
- `tools/stage5-agent-eval-suite.php`: 46/0 (unchanged - this round is
  additive on top of it).
- `tools/communication-campaign-approval-smoke.php`: unaffected, still
  passes (`creator_cannot_approve` holiday-flow contract intact).
- `tools/copilot-agent-smoke.php`: `status: ok` (existing ticket/radius/
  inventory/ont flows intact).
- Manual end-to-end check against the local dataset: `app_copilot_agent_handle_message()`
  correctly returned a composed CEO briefing (open network tickets,
  unassigned tickets, ~3.3k long-inactive customers, critical stock,
  positive cash flow) for an admin actor, a finance-only briefing for a
  finance-role actor, and `advisor_access_denied` when that same finance
  actor asked for the NOC advisor.
- PHP lint clean on every touched/created file.
- `tools/full-system-regression.php`: **197 pass / 0 fail / 0 timeout / 4
  intentional skip**. Report:
  `tmp/full-system-regression-20260802-114926.json`.

## Out of scope / follow-up

- No dedicated UI page yet - the read surface is the existing Superpilot
  chat (`copilot-agent.php` / `api/copilot/agent-chat.php`), which is
  already audited and permissioned. A dashboard view under the `ai` module
  (`ai-module-lib.php` already has a `recommendations` section this could
  extend) is a natural next increment.
- The marketing advisor's `long_inactive_segment` proposal only reports the
  candidate count and stages the campaign when explicitly invoked; it does
  not auto-invoke `app_communication_campaign_prepare_long_inactive()` from
  the briefing itself - staging still requires a deliberate admin action.
- A narrower delegated-approval hierarchy (module owner approves low-risk,
  single-module proposals; only cross-module/high-risk escalates to
  super-admin) is deferred - every proposal currently routes to
  `super_admin`, which is safe but coarse.
- No real SMS/Telegram/WhatsApp send, RADIUS/OLT/router action, credential
  read/rotation, production deploy, or commit/push happened in this round.
