# Operational CRUD, Inventory Approval and Module UX Design

**Date:** 2026-07-02

## Objective

Finance, HR, Inventory and Billing modules must expose the operational actions that belong to them. Inventory movements must preserve branch-level accountability and external returns must never change stock before approval. Statistics, AI and later modules must follow the grouped navigation and responsive layout rules already applied to the first four modules.

## Scope and Delivery Order

The work is split into three independently testable phases:

1. Core CRUD: manual finance income, finance counterparties, HR employee creation, inventory stock intake, and required Billing customer identity fields.
2. Inventory control: branch balances, branch transfers, external return/transfer requests, approval, audit, and printable documents.
3. UX consistency: Reports context repair, overflow fixes, grouped AI navigation, pagination, and the same shell rules for subsequent modules.

Each phase must leave the application runnable and must not depend on the external MGS service being online for local records.

## Architecture

The application remains a PHP/PDO/MySQL system. New operational records use normalized MySQL tables and explicit service functions in the existing module libraries. `mgs_operation_actions` remains the immutable cross-module audit stream, but it is not the source of truth for companies, branch balances, approvals, or return document lines.

All stock-changing operations run in database transactions. The approval service locks the request and affected location balances before applying deltas. Repeated approval of the same request must be idempotent and must not apply stock changes twice.

## Finance

### Manual Income

Finance receives an `income` route and page. Authorized Finance staff can create a manual income record with:

- title
- category
- amount and currency
- transaction date
- branch
- counterparty when applicable
- payment source or account
- reference number
- note

Manual income is stored in `finance_entries`. Billing payments remain read-only Billing-originated income. Finance overview and statistics calculate total income from both Billing payments and posted manual income without counting either source twice.

### Counterparty Registry

`finance_counterparties` is the master registry for companies, institutions and persons. A record contains legal/display name, type, tax identifier when available, phone, email, address, status and note.

The Companies page receives a visible `Yeni qarsi teref` action and create/edit modal. Existing debt and invoice names remain visible. When a normalized name matches a registry entry, aggregated document totals are attached to that entry. Unmatched historical names appear as legacy/unlinked rows and can later be linked without rewriting history.

## HR

### Employee Creation

HR receives a `Yeni emekdas` action in the Employees page. HR can enter personal, contact, employment, branch, position and salary-base information. HR cannot select `account_type`, `employee_role` used for authorization, module permissions, admin status or portal access role.

`portal_accounts.job_title` stores the HR position. `portal_accounts.employee_role` remains the authorization role used by `app_portal_employee_module_keys()`. HR writes `job_title` and the admin flow writes `employee_role`; the two values are never copied into each other automatically.

The employee is created as:

- `role = employee`
- portal access disabled
- authorization role unassigned
- HR position stored separately from the authorization role

Only an admin can activate portal access, set the authorization role and therefore grant module visibility. The existing admin user-management flow remains the only role-assignment interface. Server-side validation ignores or rejects role fields submitted to the HR endpoint.

The create transaction writes the employee record first and then the initial compensation profile when salary data is present. A failure in either required part rolls back the whole create operation.

## Inventory

### Location Balances

`inventory_location_balances` stores quantity by stock item and location:

- location type: warehouse, branch, employee, external
- location id
- available quantity
- reserved quantity
- update timestamp

`inventory_stock_items.quantity_on_hand` remains the company-wide usable quantity. Intake and approved external removal change the company-wide quantity. Branch-to-branch transfers do not.

### Stock Intake

Inventory receives a prominent `Mal medaxil et` action. Intake requires a product or new SKU, quantity, unit, unit price, receiving branch/warehouse, source counterparty when applicable and note. Posting intake increments both company-wide stock and the selected location balance and writes stock plus lifecycle audit records.

### Branch Transfer

A branch transfer requires source branch, destination branch, item, quantity and note. Source and destination must differ. The transaction rejects insufficient source stock, decrements the source location, increments the destination location and leaves company-wide stock unchanged.

The destination branch inventory becomes available to that branch's employees. Employee handover must identify the source branch and consume the branch location balance rather than an unspecified global balance.

### External Return or Transfer

Returning or transferring goods to a company, institution or person creates an approval request. Supported reasons include unneeded, defective, incorrect delivery and other. Supported dispositions include return to supplier, external transfer and write-off.

Status flow:

`draft -> pending -> approved | rejected`

Draft and pending records never change stock. Approval requires the admin-assigned `warehouse_manager` authorization role or admin. Approval locks the request and balances, validates stock again, applies the removal once, stores approver and timestamp, and writes immutable movement/audit records. Rejection stores the reason and does not change stock.

### Automatic Document and Print Layout

Every handover, branch transfer and external return request receives a unique automatic document number generated and stored by the system. Number generation is concurrency-safe and follows a type/year/sequence format such as `QAY-2026-000001` or `TRF-2026-000001`.

The printable document contains:

- company name from the `portal_company_name` configuration value at the top, with `Super Optik` as the local default
- `Tehvil-teslim`, `Filial transferi` or `Qaytarma` title based on operation type/status
- document number, date, source and destination
- item rows with sequence, item name, quantity, measurement unit, unit price, MAC, serial number and note
- status and approval information
- blank handwritten signature areas labelled `Tehvil veren` and `Tehvil alan`

The document remains printable after approval and preserves the values that existed when it was created. Later catalog edits must not alter historical document lines.

## Billing Customer Identity

New customer creation requires:

- FIN code
- identity card/document number
- mobile phone
- address

Validation is enforced on the server and mirrored with HTML `required` controls. FIN is normalized to uppercase and duplicate active customer FIN values are rejected. Mobile phone is normalized through the existing phone helper.

The same fields are editable on the existing customer edit page. Existing customers with missing values are not blocked from read-only views, payments or service operations, but the edit page clearly marks the missing fields and requires them on the next profile save.

FIN and document number become first-class account fields so list search and reporting do not depend on JSON payload scans. The profile payload mirrors them for compatibility with existing profile consumers.

## Reports and Statistics

The Reports page no longer renders `Statistics context not configured`. When no module context is supplied, it shows explicit links to Finance, Inventory, HR and Billing statistics. When a valid module/report context exists, the contextual statistics link is retained.

Report action buttons wrap without overlap. Long backup names, paths and metric values use `min-width: 0`, wrapping and responsive grid rules. Report cards stack before content becomes unreadable.

## AI and Subsequent Modules

AI replaces its flat ten-item tab strip with grouped dropdown navigation:

- Overview and Copilot
- Customer and Network Analytics
- Finance, Risks and Recommendations
- Automations, Audit and Model Status

The active group and active child remain visible. AI KPI text receives the same overflow protection used in Finance, HR, Inventory and Billing. List pages use a maximum page size of 50 and AJAX refresh only their module content.

The reusable grouped navigation and responsive card rules become the default for Messaging, Statistics and Network modules when they are migrated. Module permissions continue to be enforced server-side; hiding navigation is not treated as authorization.

## Permissions

- Finance entry and counterparty creation: Finance role or admin.
- HR employee creation: HR role or admin.
- Employee portal role assignment and access activation: admin only.
- Inventory intake and transfer request creation: Warehouse role or admin.
- Inventory external removal approval: admin-assigned `warehouse_manager` authorization role or admin.
- Billing customer creation and identity update: Billing-authorized role or admin.

Every POST endpoint verifies CSRF and permission before reading business fields. Unauthorized requests return an error and do not write audit success records.

## Error Handling and Audit

Validation errors keep the modal open and display a field-oriented message. Database failures roll back the transaction and produce one failed audit record without exposing SQL details. Stock conflicts report the current available quantity. Approval conflicts report that the request is already finalized or has changed.

Successful create, approval, rejection and transfer operations write `mgs_operation_actions` with entity type, entity id/document number, actor, summary and structured payload.

## Testing

Tests are written before production changes and must demonstrate RED then GREEN for:

- manual income creation and statistics inclusion
- counterparty creation and aggregate linking
- HR employee creation without authorization role escalation
- admin-only role assignment
- required Billing identity fields and duplicate FIN rejection
- inventory intake location and global balance deltas
- branch transfer source/destination deltas with unchanged global stock
- pending return causing no stock change
- approved return applying exactly one stock removal
- rejection causing no stock change
- automatic document number uniqueness
- printable document snapshot fields and signature labels
- Reports fallback statistics links and responsive markers
- grouped AI navigation, 50-row pagination and AJAX markers

Existing Finance, HR, Inventory, Billing and AI separation smoke suites remain mandatory regression checks. All changed PHP files must pass `php -l`.

## Out of Scope

- Electronic signature integration
- Accounting-system export beyond the printable document
- Automatic supplier refund accounting
- Replacing the current portal authorization model
- Rewriting all modules into a new framework
