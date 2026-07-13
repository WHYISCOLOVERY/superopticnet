# Inventory Module Separation Design

## Scope

This phase applies the approved global module-separation rules to the canonical Inventory section in `operations-center.php`. It reorganizes existing inventory data and actions; it does not introduce a second inventory engine or new lifecycle business rules.

## Canonical Tabs

1. `overview` - up to six inventory KPIs, critical stock, recent alerts, and shortcuts only.
2. `catalog` - product catalog and category maintenance.
3. `devices` - serialized assets with model, serial, MAC, condition, lifecycle, and location.
4. `materials` - consumable stock such as connector, cable, tool, and other non-serialized products.
5. `cable-drums` - drum length, reserved length, remaining length, condition, and location.
6. `technicians` - assets currently assigned to technicians.
7. `customers` - devices currently assigned to customers or represented by active customer assignments.
8. `handover` - existing technician handover workflow and handover movement history.
9. `returns-inspection` - return and inspection records selected through a local sub-view.
10. `repair-rma` - existing assets whose condition or lifecycle indicates defective, repair, or RMA handling.
11. `movements` - stock and lifecycle movement history selected through a local sub-view.
12. `statistics` - Inventory-only aggregates and date filters.

## Data Ownership

Inventory owns stock items, categories, serialized assets, cable drums, technician/customer assignments, returns, inspections, and inventory movement history. Finance keeps depreciation and write-off accounting. Billing and Customer360 may show customer-specific equipment summaries, but global inventory management remains here.

## Loading Model

`inventory-module-lib.php` normalizes the selected tab and builds data only for that tab. Every list query uses server-side pagination capped at 50 rows. `inventory-module-partial.php` performs the Inventory permission check and renders either the full module fragment or the statistics results fragment.

The main page delegates tab, filter, pagination, and statistics requests through `fetch`. Successful changes update the canonical query string with `history.pushState`; `popstate` restores the previous tab/filter without reloading the portal shell. Scroll position is preserved.

## UI Model

The module follows the established Finance and HR structure: breadcrumb, header, horizontally scrollable local tabs, selected-tab content, responsive tables, modal forms, loading status, and empty/error states. Create forms are rendered only on the owning tab and remain hidden in a modal until requested. Modal headers stay inside the panel while a single body region scrolls.

## Statistics

Inventory statistics expose only inventory datasets: stock by category, asset lifecycle, asset condition, cable remaining by location, movement type, and current asset location. Date range applies to movement datasets; snapshot datasets remain current-state aggregates. The visible chart selector updates only the statistics panel and renders at most four charts for `all`.

## Compatibility

Legacy `inventory_tab=stock`, `handover`, and `lifecycle` values map to `catalog`, `handover`, and `overview` respectively. Existing POST actions keep their field names and backend handlers. Redirects preserve the canonical `inventory_tab` selected by the submitted form.

## Verification

The Inventory separation smoke test verifies the tab contract, legacy mapping, 50-row cap, selected-tab rendering, Inventory-only statistics, AJAX markers, and permission guard. Existing lifecycle, finance-asset, Operations, portal-access, and Billing UI suites remain regression gates. Browser verification covers AJAX tabs, modal containment, chart-only refresh, URL/history, and console errors.
