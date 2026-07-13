# HR Module Separation Design

## Scope

This phase applies the approved global module-separation rules to the existing HR implementation. It reorganizes current HR workflows without inventing a second HR system or changing payroll, leave, travel, score, document, attendance, or Finance-bridge business rules.

## Current Problems

- `operations-center.php?section=hr` loads attendance, KPI, payroll, compensation, score, leave, travel, document, cost, and Finance-bridge data together.
- Create/update forms remain permanently visible inside one long page.
- Existing row helpers use fixed small limits and cannot provide total counts or page 2.
- The default HR page is not a lightweight overview.
- Statistics and report context are not isolated as an HR domain.

## Canonical Navigation

The canonical controller remains `operations-center.php` to preserve permission and POST workflows:

```text
?section=hr&hr_tab=overview
?section=hr&hr_tab=employees
?section=hr&hr_tab=attendance
?section=hr&hr_tab=leave-travel
?section=hr&hr_tab=performance
?section=hr&hr_tab=payroll
?section=hr&hr_tab=documents
?section=hr&hr_tab=statistics
```

`section=hr` resolves to `overview`. Existing `focus=kpi` resolves to `performance`; attendance focus resolves to `attendance`.

## Tab Ownership

- **Overview:** at most six KPIs, four compact charts/insights, alerts, recent critical records, and shortcuts. No full form or full table.
- **Employees:** active operational employees and gross/net compensation profiles. Compensation editing opens in a modal.
- **Attendance:** attendance profiles, card/RFID, branch, work start/end, and grace minutes. Editing opens in a modal.
- **Leave and travel:** leave requests, travel requests, legal average preview, and their create/status workflows. Only one selected list is rendered at a time.
- **Performance:** annual/selected-period 100-point score, penalties, rewards, and KPI summary. Score event creation opens in a modal.
- **Payroll:** legal tax profile, net salary, employee deductions, employer cost, leave/travel amounts, payroll records, and Finance bridge actions.
- **Documents:** required-document completeness, document upload, and status updates.
- **Statistics:** HR-only employee cost, payroll, score, leave/travel, attendance, and document-completeness aggregates. No Billing, RADIUS, Inventory, or Network datasets.

## Loading and Pagination

`hr-module-lib.php` validates tabs and builds only the selected tab context. Every large table uses `COUNT(*)`, `LIMIT 50`, and `OFFSET`; URL state carries `page`, filters, date ranges, and selected subview. Test/demo employees remain excluded from operational selectors.

`hr-module-partial.php` returns the same shared module view used by the full page. Fetch navigation replaces only `[data-hr-module]`, uses `AbortController`, updates history, preserves scroll, and supports browser Back/Forward. Statistics filters replace only the statistics results panel.

## Forms and Redirects

Existing POST actions remain in `operations-center.php`. Every HR form includes `hr_tab`, and the flash redirect returns to the owning tab. Forms are rendered only in the selected tab and remain closed inside a modal until requested.

## Error and Permission Behavior

The existing module permission boundary remains authoritative. Authenticated users without HR access receive HTTP 403. Partial endpoint failures show a local error state without replacing the portal shell.

## Verification

- Default overview excludes all heavy HR workflow forms.
- Each tab renders only its own markers and no more than 50 rows.
- Page 2 is DB-backed, not client-side slicing.
- Finance payroll/tax calculations and existing workflow smoke tests remain green.
- HR statistics contain no Billing/RADIUS/Inventory/Network queries.
- AJAX tab, filter, Back/Forward, modal overflow, desktop, and compact/mobile layouts are browser-verified.

## Follow-on Phase

After HR regression is green, Inventory receives the same architecture using catalog, assets, cable drums, technician/customer holdings, handover, returns/RMA, movements, and statistics tabs.
