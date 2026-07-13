# 20 Testing Organization

## Purpose

Define continuous role-agent testing so SuperOptic MGS is validated like a real ISP operation before release.

## Virtual Agents

- CEO.
- Billing Operator.
- NOC Engineer.
- GPON Engineer.
- Technician.
- Inventory Manager.
- HR Manager.
- Customer Support.
- Customer.
- Security Red Team.
- UX Designer.
- QA Lead.
- AI Auditor.

## Required Simulations

- Create customer, assign package, IP and RADIUS profile.
- Receive provider payment and activate service.
- Decrease billing days and suspend expired customer.
- Customer portal login, payments, traffic and ticket view.
- Staff login by role.
- NOC device add, IP scan and alarm review.
- OLT/ONT search and optical analysis.
- Ticket open, assign, resolve and close.
- Inventory stock entry, exit, handover and return.
- HR employee create, attendance and KPI.
- Superpilot question answering and confirmation-gated action.
- Security red-team checks: brute-force, SQL injection pattern, authorization bypass and suspicious traffic.

## Regression Rules

- Run focused tests after each module change.
- Run full regression before release or broad status claim.
- Live-risk tests are skipped unless explicitly approved.
- Skipped tests must record reason: no VLAN, no live device, no provider credential, paid SMS disabled or RADIUS not connected.

## Release Gate

Release only after critical tests pass, skipped live-risk tests are documented and rollback path exists.
