# Plugin Enabled Product System Plan

> **Goal:** Use the available design, analytics, experimentation, planning and sales/customer-context plugins to improve SuperOptik MGS without breaking the working PHP/MySQL system.

**Current evidence**
- Local database has usable first analytics sources: `billing_payments` 132295 rows, `portal_accounts` 5749 rows, `billing_radius_services` 5649 rows and legacy billing profile sections 54685 rows.
- Data Analytics artifact validation/render passed for the first kickoff report.
- Data Analytics Center phase 1 is embedded in `operations-center.php?section=reports` and backed by `analytics-center-lib.php`.
- Statsig connector is reachable, but the current Statsig project returned zero experiments, so `shorter_blog_titles` does not exist in the connected project yet.
- Statsig gates were created with 0% pass rules: `mgs_new_module_shell`, `mgs_bonus_dashboard_v2`, `mgs_noc_scan_selectable_results`, `mgs_statistics_ajax_charts`, `mgs_data_analytics_report_embed`.
- Mobbin connector is available but blocked by plan limits; it requires a paid Mobbin plan before design references can be pulled.
- Figma connector is available for inspection, but a file/node URL is required before design-to-code work can start.
- Linear project created: `SuperOptik Plugin Enabled Product System`.

## Plugin Usage Map

| Plugin | System use | First practical task | Status |
| --- | --- | --- | --- |
| Figma | Inspect approved UI frames and convert them to PHP/CSS module layouts | Redesign module shell, login and dashboard once a node link is supplied | Waiting for Figma URL |
| Mobbin | UI references for enterprise login, dashboards, filters and tables | Pull reference set for login, admin dashboard, NOC monitor and table filters | Blocked by paid plan |
| RewriteLy | Natural Azerbaijani microcopy and support text | Rewrite module labels, empty states and action messages | Available |
| Canva / Adobe Express | Training deck, operator guide, release notes visuals | Create staff onboarding guide after UI standard is stable | Optional |
| Statsig | Feature flags and experiments for gradual rollout | Add flags for new navigation, new charts, bonus dashboard and NOC scan UX | Gates created; local fallback implemented |
| Data Analytics | Analyst-facing reports and dashboard artifacts | First data task: database readiness and billing/customer/RADIUS dashboard | Phase 1 embedded |
| Linear | Product backlog and triage record | Keep this plan as an actionable triage document and split into issues when issue tools are available | Project and first issues created |
| Sales / Gmail | Customer/account follow-up workflows | Debt recovery, inactive customer outreach, corporate customer follow-up and service renewal messaging | Available for Gmail-backed workflows |

## Implementation Packages

### Package 1: UI Language And Shell Standard

- [ ] Create a shared module language dictionary for Azerbaijani labels, empty states, button text and status names.
- [ ] Replace mixed English/Azerbaijani strings in Finance bonus, HR, Inventory, Billing and NOC pages.
- [ ] Standardize action buttons: primary create, ghost filter/export, danger destructive, status badges.
- [ ] Keep forms in modals/drawers where the page would otherwise become long.
- [ ] Add smoke checks for duplicated headers, nested scrolls, overflowing KPI values and button overlap.

### Package 2: Statsig Rollout Layer

- [x] Create feature gate names:
  - `mgs_new_module_shell`
  - `mgs_bonus_dashboard_v2`
  - `mgs_noc_scan_selectable_results`
  - `mgs_statistics_ajax_charts`
  - `mgs_data_analytics_report_embed`
- [x] Add a PHP feature flag abstraction with local fallback so the app works when Statsig is unavailable.
- [ ] Gate risky UI changes behind flags and enable by role/employee.
- [ ] Add audit log entries when a gated feature is used.
- [ ] Create experiment templates only after real Statsig project objects exist.

### Package 3: Data Analytics First Dashboard

- [x] Convert the validated kickoff artifact into an internal Analytics page.
- [x] Add billing revenue by provider/payment method and month.
- [ ] Add active payer/customer coverage by customer code prefix.
- [x] Add RADIUS status and expiry-risk KPIs.
- [x] Add bonus payroll summary by period.
- [x] Keep charts backed by bounded rollups, not heavy request-time scans.

### Package 4: Linear Backlog Sync

- [x] Save this plan as a Linear project when the target team/project is known.
- [x] Split packages into Linear issues:
  - UI language and shell standard
  - Statsig feature flag abstraction
  - Data Analytics embedded report page
  - Sales/Gmail customer outreach flows
  - Google Drive document archive integration
- [ ] Keep each issue with acceptance criteria and smoke tests.

## Execution Links

- Linear project: https://linear.app/sardarabdul/project/superoptik-plugin-enabled-product-system-63031ae1df43
- Linear issues: `SAR-5`, `SAR-6`, `SAR-7`, `SAR-8`, `SAR-9`
- Statsig gates:
  - https://console.statsig.com/1sPi5GtUP2XcOm73DZux5R/gates/mgs_new_module_shell
  - https://console.statsig.com/1sPi5GtUP2XcOm73DZux5R/gates/mgs_bonus_dashboard_v2
  - https://console.statsig.com/1sPi5GtUP2XcOm73DZux5R/gates/mgs_noc_scan_selectable_results
  - https://console.statsig.com/1sPi5GtUP2XcOm73DZux5R/gates/mgs_statistics_ajax_charts
  - https://console.statsig.com/1sPi5GtUP2XcOm73DZux5R/gates/mgs_data_analytics_report_embed

### Package 5: Sales And Customer Operations

- [ ] Add customer segments for debt, inactive service, expiring service and high-value corporate accounts.
- [ ] Generate message drafts for SMS, WhatsApp, Telegram, app and email channels.
- [ ] Track outreach history in audit/message center.
- [ ] Use Gmail only for email workflows; do not send without explicit user confirmation.
- [ ] Add report: contacted customers, recovered payments, unresolved follow-ups.

## Acceptance Criteria

- Every new UX change is behind a safe rollout path or local fallback.
- Every analytics report shows source, query/metric definition and date range.
- Every action that can affect customer, stock, payroll or finance data writes audit logs.
- Every long table stays paginated at max 50 rows.
- Every filter/statistics refresh updates only its result area.
- Full regression remains green before release.
