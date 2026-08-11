# Unified Agent Operations Work Queue / Approval Center - foundation

Date: 2026-08-03

## Problem

The advisor ecosystem (`docs/stage5/04_ADVISOR_AGENT_ECOSYSTEM_2026-08-02.md`)
gave Serdar/super-admin five governed advisor briefings, but there was no
single place to see actionable work across Billing, Finance, HR, Inventory,
NOC/network, Message Center and CRM at once. Each module already has its own
list views; this increment consolidates them into one role/scope-filtered,
paginated, read-only queue - an "Approval Center" - without creating a second
backend or duplicating any module's business rules.

## What changed

### `agent-ops-queue-lib.php` (new)

The read-only projection layer. Every item is built by one of 14 registered
source functions (`app_agent_ops_queue_sources_registry()`):

- **Billing**: `billing_overdue` - individual customers with an open debt
  balance (`app_billing_balance_page()`).
- **Finance**: `finance_receivables` (`app_finance_debt_page()`, open
  receivables) and `finance_payroll` (`app_hr_payroll_page()`, status
  `pending_approval` - payroll ownership already lives under Finance per
  `finance-module-lib.php`'s own `require_once hr-module-lib.php`).
- **HR**: `hr_attendance` (today's absent/late rows,
  `app_hr_attendance_page()`), `hr_documents` (employees with missing
  required documents, `app_hr_document_page()`), `hr_performance` (score
  <=60, `app_hr_score_page()`).
- **Inventory**: `inventory_critical_stock`
  (`app_inventory_catalog_page(['critical'=>1])`) and
  `inventory_pending_inspection` (returns awaiting inspection,
  `app_inventory_return_page(['status'=>'received'])`).
- **NOC/network**: `noc_unresolved_tickets` and `noc_verification_required`
  - both read `app_db_ticket_records()` (db.php) and are narrowed with the
    existing `app_noc_filter_objects()` object-scope filter
    (`noc-authorization-lib.php`); verification-required rows filter on the
    existing `resolution_verification_status` enum from
    `ticket-resolution-lib.php`.
- **Message Center**: `messaging_campaign_approvals`
  (`app_communication_campaign_recent()`, `pending_approval` rows) and
  `messaging_failed_deliveries` - a small named read-only adapter mirroring
  `message_center_filtered_message_where()`'s `status = 'failed'` predicate,
  because `message-center.php` cannot be `require`d as a library (it runs
  route-level side effects - auth redirect, etc. - at top level).
- **CRM/marketing**: `crm_inactive_customers`
  (`app_communication_campaign_long_inactive_candidates()`) - never
  auto-sends; the next action is a deep link, never a queued message.
- **Advisor council**: `advisor_proposals` wraps the existing
  `app_advisor_all_briefings()` output (finance/noc/techops/marketing,
  `ceo` excluded to avoid re-listing the same proposals twice) as queue
  items. Each item carries the *exact* signed envelope
  `app_advisor_build_proposal()` produced, so - and only for these items -
  `confirmable=true` legitimately means the item can be handed to the
  existing `app_advisor_prepare_proposal_action()` preview bridge. No other
  source in this file fabricates or reconstructs a proposal envelope.

Every item is normalized through `app_agent_ops_queue_build_item()` into the
task-mandated shape: stable id, source module/entity type/id, title, reason,
severity, status, owner/role, scope, created/updated/source timestamp,
`data_status` (`verified`/`unavailable`), deep link, required permission,
approval owner, `next_action_kind`, `confirmable`, `rollback_available`.

`app_agent_ops_queue_page()` is the single aggregator: it gates every source
by `app_portal_can_access_module()` (admin/super_admin see everything; a
non-admin actor only sees a source whose module they already hold - the same
boundary every other page in this app already enforces), merges, applies
`search`/`severity`/`modules` filters, sorts deterministically (severity rank,
then source timestamp, then the item's own stable id as a final tie-break),
and paginates in PHP with a hard 50-row cap. Every source query is itself
already bounded (<=50-500 rows via existing pagination/limit contracts), so
the merge is bounded by `source_count x max_rows_per_source`, never an
unbounded scan.

**Object-scope rule:** module permission is necessary but is not treated as
object scope. Admin/super-admin and the canonical owner roles for Finance,
HR, Inventory and NOC retain their existing module-wide view. Other actors
are narrowed with the existing `portal_account_areas` / `responsible_areas`
scope exposed by `app_noc_scope_values()`. Sources whose rows expose an area
are filtered row by row. A non-owner source that has no safely evaluable
area/branch/assignment field fails closed with `scope_unavailable`; it never
falls back to company-wide rows. NOC continues to use
`app_noc_filter_objects()`, and advisor rows continue to inherit
`app_advisor_access_allowed()`.

### `api/agent-ops/work-queue.php` (new)

Read-only business-data `GET` JSON endpoint. Requires an authenticated
employee session, `ai` module access, and the `agent_ops_work_queue` feature
flag. It never accepts `$_POST` and never calls a business write/action
function. Every successful or failed queue read is routed through
`app_agent_ops_queue_access()`, which writes one bounded append-only audit
event. If that audit write fails, the endpoint returns `audit_unavailable`
without queue data.

### `ai-module-lib.php`, `ai-work-queue.php`, `views/ai/work-queue.php`, `views/ai/module.php`, `css/ai-center.css`

Integrated as a new `work-queue` section of the existing Superpilot/AI module
shell (`ai-module.php` -> `views/ai/module.php`), reusing the existing
AJAX filter/pagination pattern (`script/ai-center.js`'s `[data-ai-filter]`
auto-submit and `[data-ai-page]` history-aware navigation - no new JS was
needed). The view renders loading (`.ai-module.is-loading`, existing shell
behavior), empty and per-source error states via
`app_design_system_state()` (`design-system-lib.php`), and a mobile-responsive
table using the existing `data-label`/`.portal-table` stacking contract
(`css/portal.css`). Every row's only action is a "Modula keç" (go to module)
deep link opened in a new tab; the view renders **no POST form and no
`name="action"` write-trigger input** - the pattern used everywhere else in
this app to submit a write. Items eligible for the advisor preview bridge are
only flagged with an informational badge in this foundation; actually wiring
a live preview-call button is out of scope here (see below).

### `config.php`

Added `'ai-work-queue.php' => 'ai'` to the route-to-module map
(`app_portal_request_module()`) so the new route is gated by the same
canonical module-permission check as every other AI page.

### `feature-flags-lib.php`

Seeded a new flag, `agent_ops_work_queue` (default: **disabled**, matching
this codebase's existing rollout convention for every prior stage). Enable
locally via the existing feature-flag admin path or:

```php
app_feature_flag_save($db, 'agent_ops_work_queue', true, 100.0);
```

## Governance recap (task-mandated, enforced in code)

- No second business backend: every source reads an existing canonical
  service/read-model function, or (message-center failed deliveries, NOC
  ticket rows) a minimal named adapter mirroring an already-established
  predicate, never a re-derived business rule.
- Missing/failing sources report `{available:false, error:...}`, never a
  silently-zero result - verified structurally for all 14 sources in the
  smoke test.
- Permission/object-scope checks happen in `agent-ops-queue-lib.php` through
  existing canonical helpers (`app_portal_can_access_module()`,
  `app_noc_filter_objects()`, `app_advisor_access_allowed()`); the view does
  not hide sections as its only protection - the API endpoint enforces the
  same gate independently.
- Deterministic ordering, filters and a hard 50-row/page cap; no N+1 (each
  source issues at most one bounded query).
- The queue view **never executes a write** - no POST form, no write-trigger
  input, no call to any prepare/approve/queue/send function from the view or
  the API endpoint (comment-aware static scan in the smoke test).
- Action buttons deep-link to an allowlisted canonical module route only
  (`app_agent_ops_queue_link()` refuses anything not in
  `app_agent_ops_queue_deep_link_allowlist()`, including path traversal and
  absolute URLs). Advisor-sourced items additionally carry a genuinely signed
  proposal envelope eligible for the existing
  `app_advisor_prepare_proposal_action()` preview contract, but this
  increment does not wire a live POST button for it (see Out of scope).
- No confirmable item ever points at a go-live-blocked action kind
  (`billing_payment`, `finance_payout`, `noc_command`, `role_change`) -
  verified against every live item returned in the smoke test, not just the
  registry in isolation.
- Queue access is append-only audited through the canonical
  `app_audit_record()` service. The payload stores only allowlisted filters,
  clamped pagination, source availability/counts and a SHA-256 search hash;
  it stores no plaintext search or source rows. The GET path does not create
  audit schema. Missing audit schema/service fails closed.
- Public output is an explicit field allowlist. `_advisor_proposal`, signed
  envelopes, evidence, signatures and unknown nested fields remain
  server-side and are removed before JSON or HTML rendering.

## Verification

- `tools/agent-ops-work-queue-smoke.php` (new, registered in
  `tools/full-system-regression.php` as `agent_ops_work_queue`, plus 5 new
  lint entries): **107 pass / 0 fail** after the Codex acceptance correction
  (the original foundation run was 84/0), covering the original checks plus
  stable error-code redaction, explicit public projection, nested internal
  field removal, allowlisted audit metadata, plaintext-search exclusion,
  transactional audit persistence/rollback, fail-closed audit failure and
  role/area/module-owner scope behavior. The original suite was run 5x with
  0 flakes
  after the ordering fix below. Covers: deep-link allowlist (including path
  traversal/absolute-URL refusal), stable item ids, source registry
  completeness across all 7 task-mandated modules, the
  `{available,items,error}` unavailable contract (unit + structurally against
  every real builder), static no-write scan (comment-aware) of the lib and
  API endpoint, view UI contract (mobile `data-label`, both empty/error
  design-system states, no POST form, no write-trigger input, no
  approve/send/pay label), feature-flag fail-closed default, role/module
  scope isolation (admin cross-module vs. HR-only vs. billing-scoped actor),
  call-to-call determinism, the 50-row pagination cap, severity-rank
  ordering, full item-schema completeness, absence of
  secret/password/token/FIN-shaped keys in any item, go-live-blocker leakage
  check against live data, advisor-envelope authenticity check
  (`app_advisor_proposal_envelope_valid()`), and severity/module filters.
- **Real bug found and fixed during verification**: the initial
  advisor-proposal timestamp used `app_advisor_build_proposal()`'s
  `generated_at`, which is stamped with a fresh `app_db_now()` on every call.
  Two proposals built milliseconds apart could tie-break inconsistently
  between two otherwise-identical `app_agent_ops_queue_page()` calls,
  intermittently breaking the deterministic-ordering contract (observed
  directly: repeated calls produced two different stable orderings, each
  reproducible on its own). Fixed by truncating to the same date-only
  granularity the proposal's own `id` already uses
  (`app_advisor_proposal_identity()`) for exactly the same reason. Re-verified
  stable across 15 consecutive calls and 5 consecutive smoke-test runs after
  the fix.
- `tools/ai-module-separation-smoke.php`: updated the hardcoded expected
  section/route list to include `work-queue` (the section this increment
  legitimately adds) - **passed** after the update.
- `tools/ai-grouped-navigation-smoke.php`: **passed**, unaffected.
- `tools/advisor-agent-foundation-smoke.php`: **102 pass / 0 fail**,
  unaffected - confirms the advisor layer this file reuses is untouched.
- PHP lint clean on every touched/created file.
- `tools/full-system-regression.php`: **203 pass / 0 fail / 0 timeout / 4
  intentional skip**. Report:
  `tmp/full-system-regression-20260803-132500.json`. A second back-to-back
  run on the same machine hit Windows paging-file/out-of-memory exhaustion on
  13 unrelated tests (`VirtualAlloc`/"Out of memory" errors spawning
  `proc_open` child processes under load) - `agent_ops_work_queue` itself
  passed in both runs; three of the affected tests
  (`four-module-ui-consistency-smoke.php`, `finance-bonus-module-smoke.php`,
  `portal-workflow-guide-smoke.php`) were re-run individually afterward and
  passed cleanly, confirming the failures were environmental resource
  exhaustion from running the full heavy suite twice in a row, not a
  regression from this change.
- Manual functional verification (PHP CLI, in-process, both with the flag
  enabled and restored to disabled afterward): the full page shell renders
  for an admin actor and for a scoped HR actor (who sees only `hr`-module
  items), the AJAX partial fragment renders correctly for a filtered request,
  and the JSON API endpoint returns a correct authenticated response.

## Out of scope / follow-up

- No live POST "prepare preview" button is wired in the view yet for
  advisor-linked items (`confirmable=true`) - the view only shows an
  informational badge plus the deep link. Wiring an actual call to
  `app_advisor_prepare_proposal_action()` from this queue would need a new
  CSRF-protected POST endpoint and a payload-collection UI (e.g. inventory
  movement quantity/direction) that no queue row currently gathers; building
  that is the natural next increment, not this foundation.
- Per-row branch/assignment readers still do not exist for every source.
  Those sources now fail closed for non-owner roles. A later upstream service
  refactor may add richer branch/assignment fields and replace the closed
  state with a narrower useful result without weakening this queue gate.
- `messaging_failed_deliveries` and `noc_unresolved_tickets` use small named
  read-only SQL adapters instead of a page-context service function, because
  the natural home for that logic (`message-center.php`) is a route file with
  top-level side effects, not a requirable library; a future refactor could
  extract those two functions into a lib file others can share.
- No production deploy, no real SMS/Telegram/WhatsApp send, no RADIUS/OLT/
  router action, no credential read/rotation, no commit/push, no destructive
  DB operation, no schema migration (none was needed - this is a pure read
  projection) happened in this round.

## Acceptance correction - 2026-08-03

Claude implemented the scope/error/audit/public-projection skeleton before
its local CLI session limit was reached. Codex completed the focused review,
removed GET-time audit schema creation, fixed failed-access audit semantics,
completed the UI error state and Azerbaijani `Təsdiq Mərkəzi` naming, added
the missing contract tests, and independently reran lint and focused
regressions. The single final full-system run passed **203/0**, with 0
timeouts and the same 4 intentional live-integration skips. Report:
`tmp/full-system-regression-20260803-150336.json`. No production or live
integration action was performed.
