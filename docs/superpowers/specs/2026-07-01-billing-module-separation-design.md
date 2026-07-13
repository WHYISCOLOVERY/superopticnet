# Billing Module Separation Design

## Context

Billing currently has mature customer and payment workflows, but its dashboard and navigation mix Billing with RADIUS, network traffic, CRM requests, system maintenance, finance analytics, and AI recommendations. The approved portal rule requires each domain to have one canonical owner, a lightweight overview, at most 50 rows per table, server pagination, on-demand forms, and partial refresh for filters and charts.

This change preserves the existing customer, payment, and service business logic. It reorganizes ownership and presentation without creating a parallel billing engine.

## Considered Approaches

### 1. Preserve canonical pages and refactor them in place

Keep the established customer, profile, payment, and cash routes. Add the missing Billing routes, replace the mixed navigation, simplify the dashboard, and add shared partial-refresh behavior.

This is the selected approach because it has the lowest migration risk and avoids duplicating write logic.

### 2. Move every workflow into a new single Billing shell

This would provide one uniform tab controller, but it would duplicate or rewrite large customer and payment handlers. The risk to production payment and subscriber flows is too high.

### 3. Embed old pages inside a new shell

This would preserve behavior quickly, but nested documents and scroll areas would keep the current architecture and UX problems. This approach is rejected.

## Canonical Billing Map

| Billing area | Canonical route | Existing source |
| --- | --- | --- |
| Overview | `billing-dashboard.php` | Simplified existing dashboard |
| Customers | `billing-customers.php` | Existing customer list and create workflow |
| Packages | `billing-packages.php` | Tariff portion of `radius-billing.php` |
| Balances and debt | `billing-balances.php` | Customer billing payload and service totals |
| Payments | `billing-payments.php` | Existing payment history; cash acceptance remains a primary action |
| Service activity | `billing-services.php` | `billing_radius_services`, tariffs, customer account |
| Provider integrations | `billing-providers.php` | Payment method/provider metadata and import workflow |
| Billing documents | `billing-documents.php` | Payment receipts and subscriber billing references already stored in Billing |
| Billing reports | `billing-reports.php` | Billing-only customer, payment, service, and package reports |
| Billing statistics | `billing-statistics.php` | Refactored to Billing-only datasets |

Customer detail and edit routes remain `billing-customer-profile.php` and `billing-customer-edit.php`. Cash payment remains `billing-cash-payment.php` and is reached from the Payments primary action.

## Functions Removed From Billing Navigation

| Current function | Canonical owner |
| --- | --- |
| Customer requests and CRM queue | Message Center / CRM |
| RADIUS sessions, logs, NAS, pools, and auth statistics | Network |
| OLT/ONT, optical signal, VLAN/MPLS, traffic, and NOC | Network |
| Finance expenses, debts, companies, and e-invoices | Finance |
| Inventory and equipment workflows | Inventory |
| HR, attendance, payroll, and KPI | HR |
| AI revenue optimization and recommendations | AI |
| Billing maintenance and CLI verification | Admin/system services |

Old routes remain callable where required for compatibility, but they are not exposed as Billing module sections. Cross-domain links point to the canonical module and do not render the foreign workflow inside Billing.

## Navigation And Page Contract

Every Billing page renders the same local navigation with these ten items:

1. Overview
2. Customers
3. Packages
4. Balances and debt
5. Payments
6. Service activity
7. Provider integrations
8. Billing documents
9. Billing reports
10. Billing statistics

The current route is the only active page. Hidden sections are not queried or rendered. Mobile navigation is horizontally scrollable without nested page scroll containers.

## Overview

The overview contains at most six Billing KPIs:

- total customers;
- active customers;
- active billing services;
- expiring or suspended services;
- payments today;
- customer debt total.

It contains at most three Billing charts:

- daily payment trend;
- customer billing status;
- service status.

It may also show critical expiring/debt alerts, five recent payments, and shortcuts. It must not show raw RADIUS traffic, NOC data, CRM queues, AI recommendations, maintenance commands, or full tables.

## Data And Pagination

Shared Billing query helpers enforce:

- default and maximum page size of 50;
- SQL `COUNT`, `LIMIT`, and `OFFSET` pagination;
- page, search, status, date, provider, and package state in the URL;
- test/smoke records excluded from operational lists and KPI totals;
- selected page only querying its owned tables.

Billing statistics may query only `portal_accounts` customer billing fields, `billing_tariffs`, `billing_radius_services`, and `billing_payments`. It must not query raw RADIUS accounting, Finance, HR, Inventory, Network, or Messaging tables.

## Forms And Details

Create and edit forms are not permanently displayed on list pages. Existing dedicated detail routes remain valid. New short forms use a modal with:

- the header inside the modal panel;
- one scrollable modal body;
- a viewport-level overlay appended to `body` while open;
- Escape, backdrop, and close-button support;
- restoration to its owning module after close.

The large customer wizard remains on demand through its explicit create state until it can be safely converted without changing its write contract.

## AJAX And Browser History

Canonical Billing section links may navigate between dedicated routes. Within a route:

- filters, pagination, and chart selectors refresh only their result panel;
- requests use a dedicated Billing partial endpoint;
- stale requests are cancelled with `AbortController`;
- URL state is updated with History API;
- back and forward restore the selected filters and page;
- scroll position is preserved;
- failed partial requests keep the previous content and show an inline status.

## Permissions

Every Billing route and partial endpoint calls `billing_require_employee(false, 'billing')`. Customer detail and write handlers keep their existing authorization and CSRF rules. UI visibility never replaces backend permission enforcement.

## Compatibility

- Existing customer profile, customer edit, payment, and cash URLs remain valid.
- `radius-billing.php#tariffs` gets a safe path to the new Packages route; IP pool and RADIUS operations remain owned by Network.
- Billing statistics becomes Billing-domain statistics. The separate global Statistics module will link to it as a domain view instead of duplicating it.
- No database table is removed in this phase.

## Testing

The Billing separation smoke test must prove:

- ten canonical Billing sections exist;
- the overview is the default and contains no foreign-domain blocks;
- page size cannot exceed 50;
- only the selected page is queried/rendered;
- Billing statistics contains only Billing datasets;
- filters and charts use partial refresh and URL history;
- all new endpoints enforce Billing permission;
- existing customer, payment, provider, and portal access tests still pass;
- modal overlays use the viewport-level portal pattern;
- mobile tables convert to cards without nested scroll.

## Rollback

The refactor is additive around existing business logic. Rollback restores the previous Billing navigation and dashboard templates, removes the new read-only section routes and partial endpoint, and leaves all customer, payment, tariff, and service tables untouched.
