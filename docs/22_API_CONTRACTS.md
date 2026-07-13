# 22 API Contracts

## Purpose

Define stable API contracts for internal services, customer portal, provider payments, network integrations and future FastAPI migration.

## Required API Domains

- Customer API.
- Customer360 API.
- Billing API.
- Provider payment API.
- RADIUS API.
- IP management API.
- NOC API.
- OLT/ONT API.
- Ticket API.
- Inventory API.
- HR/KPI API.
- Notification API.
- Security API.
- Superpilot API.
- Installer/update/backup API.

## Provider Payment Contract

- Lookup endpoint: provider sends customer/contract code and receives customer display name, balance/payable amount and status.
- Payment endpoint: provider sends transaction ID, amount, customer code, timestamp and terminal/provider identity.
- Idempotency: same provider transaction ID must not double-credit.
- Security: IP whitelist, rate limit, structured logs and optional signature where provider supports it.
- Response: clear success/failure code, message and reference ID.

## Internal API Rules

- Authenticated by session/JWT depending platform stage.
- Authorized by RBAC.
- Structured JSON responses.
- Pagination for lists.
- Filters for searches.
- Audit for sensitive reads and all writes.
- OpenAPI for future FastAPI services.

## Versioning

- Preserve v1 PHP endpoints while adding stable API wrappers.
- New contracts should support future FastAPI implementation without frontend rewrites.
