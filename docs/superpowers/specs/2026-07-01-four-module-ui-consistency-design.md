# Four Module UI Consistency Design

## Context

Finance, HR, Inventory, and Billing have canonical module navigation, but Billing Customers and Payments still render legacy standalone hero layouts. Payments also repeats analytics that belongs to Billing Statistics, and large monetary values overflow its six-column KPI grid. Finance Overview repeats the same chart datasets as Finance Statistics. HR and Inventory already keep Overview and Statistics visually distinct.

## Selected Approach

Preserve the existing customer creation, payment import, filtering, and write handlers. Add a shared Billing page intro/local-tab component to the two legacy controllers and simplify their visible content. This avoids rewriting production workflows while making every Billing route look and behave like one module.

CSS-only hiding is rejected because it leaves duplicate queries and ownership unclear. A full controller rewrite is also rejected because it creates unnecessary risk around customer and payment writes.

## Billing Contract

- Overview contains up to six KPIs, shortcuts, critical alerts, and recent payments. It does not render analytical chart panels.
- Statistics is the only Billing page that renders Billing chart datasets and date/metric selectors.
- Customers uses the same Billing breadcrumb, title, description, and ten local tabs as every other Billing route. Its create workflow remains an on-demand modal.
- Payments uses the same Billing module chrome. It contains at most four operational KPIs, provider import, filters, and one paginated payment table.
- Payments does not render daily revenue/provider charts and does not expose a second Analytics button.
- Network/RADIUS management shortcuts are removed from the Customers primary action area; customer service state remains visible as Billing context.

## Finance, HR, And Inventory Contract

- Finance Overview keeps six KPIs, shortcuts, recent payments, and open debt/receivable alerts. Finance charts render only in Finance Statistics or their owning workflow page.
- HR Overview and Inventory Overview keep their existing summary/shortcut structure because they do not duplicate statistics charts.
- All four modules use overflow-safe KPI values: cards have `min-width: 0`, values can wrap, and monetary typography scales within the card.

## Grouped Module Navigation

Long side-by-side local tab rows are replaced by grouped dropdown navigation matching the approved four-card Billing reference. Billing uses Overview, Customers, Billing, and Reports/Statistics groups. Finance, HR, and Inventory use the same component with domain-specific groups. Only the active group is highlighted; opening it reveals its child routes. A page renders one module navigation, not both grouped navigation and a duplicate flat tab row.

## Testing

A cross-module consistency smoke test renders the four Overview/Statistics pairs and Billing Customers/Payments. It verifies:

- Billing and Finance Overview contain no statistics chart markers;
- Statistics pages retain their chart markers;
- Customers and Payments contain shared Billing module chrome and the correct active section;
- Payments contains no duplicate Analytics link or analytical chart cards;
- Payments renders no more than four operational KPIs;
- all four module shells contain overflow-safe KPI CSS;
- every module renders one grouped navigation and no duplicate flat tab strip;
- existing customer, payment, Billing, Finance, HR, and Inventory regression suites remain green.

## Browser Verification

Desktop checks verify consistent title/tab placement and full monetary values. Narrow viewport checks verify no body-level horizontal overflow, wrapping KPI values, and scrollable local tabs.

## Rollback

Rollback restores the legacy Customers/Payments intro blocks and Overview charts. No database schema or write-handler changes are involved.
