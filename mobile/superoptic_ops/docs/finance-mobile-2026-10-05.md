# Mobile finance integration — 2026-10-05

Implementation uses the existing `app_finance_save_entry`, `app_finance_actor_can_write`, `app_finance_overview_kpis` and reporting-period contract. No second ledger or mobile-only financial permission has been introduced.

## Available

- Finance role opens the finance screen; manager navigation includes a finance shortcut. API authorization remains the canonical finance permission (admin or finance employee).
- Today, trailing 7/30 days, trailing 12 months and custom date ranges.
- Existing overview KPIs including billing revenue, manually recorded income, expenses, net cash flow, receivables, payables and invoice count.
- Date-based income/expense bars for posted AZN ledger entries. The UI explicitly distinguishes this series from billing-inclusive overview revenue.
- Latest 200 entries in the selected period, with author, source/destination, reason and status.
- Create income/expense with required source/destination and reason; large expenses retain the existing pending-approval policy.
- Mobile replay record and ledger mutation share a transaction. Existing standalone finance callers still own their transactions.

## Verification

- PHP syntax checks pass.
- Read-only QA API checks: admin GET 200, reversed dates 422, technician GET 403.
- Three transaction ownership unit checks pass without a database connection.
- Flutter analyze passes; two Flutter tests pass including finance role routing.

## Remaining verification and scope

- Real financial creation, approval and concurrent replay must be integration-tested on an isolated database. No test financial entries were written to the operational database.
- Finance form submission requires a connection; persistent offline finance drafts are not implemented here.
- Finance approval/editing remains in the existing web workflow.
- Technician stock consumption, customer asset lifecycle, warehouse handover/return confirmation and the combined technical/financial dashboard are separate unfinished integrations; this change does not claim to complete them.
