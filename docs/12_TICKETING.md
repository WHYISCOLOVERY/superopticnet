# 12 Ticketing

## Purpose

Ticketing manages customer complaints, automatic network/customer incidents, technical work, SLA and employee performance.

## Required Views

- Kanban: Open, Assigned, In Progress, Resolved, Closed.
- List/table for advanced filters and export.
- SLA view for delayed tickets.
- Zone/map view for field work.
- Automatic tickets view.
- My assigned tickets.

## Ticket Context

- Customer identity, address, phone and Customer360 link.
- ONT/modem, OLT/PON, SN/MAC and optical state.
- RADIUS state, session history, rejects and IP.
- Diagnostics and AI explanation.
- Assigned technician/employee.
- Inventory items used.
- Comments, photos/files and resolution.
- SLA timer and source: manual, auto, customer portal, Telegram or AI.

## Required Actions

- Create ticket from customer, NOC alarm, ONT finding, Superpilot or customer portal.
- Assign technician.
- Add diagnosis and comments.
- Link inventory usage.
- Close with resolution code and customer confirmation where required.
- Reopen if repeated issue appears.

## Automation Rules

- If many customers on the same PON report similar issue, group into network problem.
- Auto tickets must be explainable and linked to evidence.
- Customer360 must show open and historical tickets.
- Employee KPI must include assigned, resolved, delayed and quality metrics.

## Safety

- AI may suggest ticket creation and resolution steps.
- AI-created tickets must identify source evidence.
- Closing a ticket requires operator/technician action or approved automation.
