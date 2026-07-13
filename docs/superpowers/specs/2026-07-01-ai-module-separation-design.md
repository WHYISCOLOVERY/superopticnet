# AI Module Separation Design

## Context

The approved portal architecture requires AI to be a canonical top-level module. The current AI entry point, `mgs-copilot-context.php`, is a small context snapshot without local navigation. Existing AI capabilities already include the Superpilot agent endpoint, customer evidence analysis, network/device context, approval-gated operation actions, and action audit records.

This change reorganizes those capabilities. It does not add a new model provider, invent AI results, or duplicate Finance, Network, Billing, or Inventory tables.

## Selected Approach

Create a lightweight AI shell with dedicated routes and shared views. Keep the existing Copilot engine and action store as the source of truth. Each route builds only its own context; filters and pagination refresh only the active panel.

Embedding legacy pages or rendering every AI area in one page is rejected because both approaches preserve the current ownership and page-length problems.

## Canonical AI Map

| Area | Canonical route | Existing source |
| --- | --- | --- |
| Overview | `ai-dashboard.php` | MGS summary and Copilot action counts |
| Copilot | `ai-copilot.php` | `api/copilot/agent-chat.php` |
| Customer analytics | `ai-customer-analytics.php` | `app_copilot_customer_analysis()` |
| Network analytics | `ai-network-analytics.php` | Current MGS context and device learning summary |
| Finance analytics | `ai-finance-analytics.php` | Availability/ownership state and Finance canonical link |
| Risks | `ai-risks.php` | Existing Copilot findings and failed/pending AI actions |
| Recommendations | `ai-recommendations.php` | Existing evidence-engine recommendations only |
| Automations | `ai-automations.php` | Pending/planned Copilot actions |
| AI audit | `ai-audit.php` | Executed, failed, and skipped Copilot actions |
| Model status | `ai-model-status.php` | Local engine, API, bridge, and configuration readiness |

The old `mgs-copilot-context.php` route remains compatible and redirects HTML requests to Network analytics. Its JSON contract remains unchanged.

## Page Contract

All AI routes share one module header and ten local tabs. Overview renders at most six KPIs, three compact status charts, critical AI alerts, recent important actions, and shortcuts. It does not render raw customer, payment, inventory, HR, or network management tables.

Only the selected route is queried. Large action lists use SQL `COUNT`, `LIMIT`, and `OFFSET`, with a hard limit of 50 rows. Search, status, and page state remain in the URL.

## Interaction

Copilot chat posts to the existing CSRF-protected agent endpoint and updates only the conversation panel. Customer analytics submits a question or customer identifier to a dedicated partial endpoint and renders only findings, risk, recommendation, confidence, and canonical links. Stale requests are cancelled with `AbortController`; failed requests keep the current page shell intact.

List filters and pagination use the same partial endpoint and History API. Back/forward restores URL state.

## Domain Boundaries

AI may display a result, risk, recommendation, confidence, explanation, and link to the owning module. It must not expose full Finance ledgers, Network management controls, Inventory workflows, HR records, or Billing administration tables.

Finance analytics reports whether an existing AI finance result source is available. Until such a source exists, it shows an explicit empty state and links to Finance rather than fabricating data.

## Permissions And Safety

Every AI page and partial endpoint calls `billing_require_employee(false, 'ai')`. The existing action confirmation gate remains authoritative. No action is executed by the new UI without the existing pending-action and confirmation workflow.

## Testing

Smoke tests prove the ten canonical routes, default Overview, role enforcement, selected-section rendering, 50-row cap, partial refresh, URL history, old HTML redirect, preserved JSON context, and domain boundaries. Existing Copilot engine, agent, portal access, and module regression tests remain green.

## Rollback

Rollback restores the portal AI link to `mgs-copilot-context.php`, removes the additive AI routes/views/partial endpoint, and removes the HTML redirect. Copilot APIs, action rows, and context JSON remain untouched.
