# Agent intent-understanding layer (Superpilot)

Date: 2026-07-25

## Problem

The Superpilot agent used a rigid keyword parser and, when nothing matched,
returned a generic "Superpilot sorgunu anlasilmadi". The operator screenshot
showed `musteri yarat astarada` failing entirely — customer-create was not a
recognized intent and the generic error violates the agent spec (which forbids
"başa düşmədim / yenidən yazın / xəta baş verdi").

## What changed (`copilot-agent.php`)

1. **Typo-tolerant normalizer** — `app_copilot_agent_norm()` now lowercases and
   strips combining diacritical marks, so `MÜŞTƏRİ YARAT`, `müştəri`, `musteri`
   all normalize to `musteri`.
2. **Intent classifier** — `app_copilot_agent_classify_intent()` maps free text
   to a capability family (`customer_create`, `customer_show`, `customer_edit`,
   `inventory`, `ticket`, `report`, `performance`, `analytics`) using
   longest-matched-stem-wins scoring, with the spec's misspelling variants
   (`muster/musder/mustri/mstr yarat`, `yeni abuneci`, `yeni client elave`,
   `yeni internet qos`).
3. **Guided customer-create flow** — `app_copilot_agent_customer_create_flow()`:
   extracts area (`astarada -> Astara`), phone, FIN, document, address, name;
   if identity fields are missing it asks **specifically** which ones with an
   example; when all are present it stages a confirmation-gated pending
   `create_customer` action.
4. **Real execution** — `app_copilot_agent_execute_create_customer()` calls the
   existing `app_billing_upsert_customer_with_identity()` service and returns the
   service's specific field errors (`required_identity_fields`, `duplicate_fin`),
   never a generic failure.
5. **Guided fallback** — `app_copilot_agent_guided_fallback()` replaces the
   generic error: it detects the closest family and asks that family's specific
   next-step question; only truly-unrecognized input gets a capability guide
   (still actionable, not an error).

## Stage 5 governance

`create_customer` is registered in `agent-workspace-lib.php`: module `billing`,
confirmation required, reversible (`deactivate_customer`), **not** a go-live
blocker. So it flows through the Stage 5 gate (allowlist, module permission,
prompt-injection, duplicate-retry, audit, undo) like every other agent action.

## Verification

- `tools/agent-intent-understanding-smoke.php`: **43 pass / 0 fail** —
  11 create variants classify correctly, other families classify to themselves,
  area/field extraction, the guided partial-create response asks for FIN+phone
  and never says "anlasilmadi", unknown input returns a capability guide, and
  the registry entry is correct.
- `tools/copilot-agent-smoke.php`: `status: ok` (existing ticket/radius/
  inventory/ont flows intact).
- `tools/stage5-agent-eval-suite.php`: 46/0 (gate unchanged).

## What still needs the LLM (DeepSeek) for full free-form NL

The classifier covers the operator's core verbs deterministically (works
offline). For arbitrary free-form phrasing, multi-step compound commands
("yeni müştəri yarat, HG8145X6 əlavə et, texnikə tapşırıq ver"), and rich
analytics narration, set `DEEPSEEK_API_KEY` — `app_ai_deepseek_chat()` is
already wired as the intent fallback before the guided response. The
deterministic layer guarantees the agent never fails silently even when the
LLM is unavailable.

## Recommended next additions (agent maturity)

- Multi-turn slot filling: remember partial create fields across messages so the
  user can answer "FIN 1ABC2DE" as a follow-up.
- Compound-command splitting into sequential gated actions.
- Customer-edit / package-change and inventory issue-by-model execution behind
  the same Stage 5 gate.
- Per-actor conversation context store for "ikinci cümlə əvvəlkinə aiddir".
