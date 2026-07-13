# 03 Backend

## Purpose

Define backend rules for the current PHP platform and the target FastAPI service architecture.

## Current Backend Rule

The current PHP endpoints and service helpers are the v1 backend. They must remain working while new APIs, tests and migration adapters are added. Do not replace a working PHP flow with a new service until the new service passes role-agent tests and regression gates.

## Target Backend Stack

- FastAPI for modular services.
- OpenAPI contracts for every public and internal API.
- JWT authentication, RBAC authorization and MFA support.
- PostgreSQL repositories for primary data.
- ClickHouse repositories for high-volume analytics.
- Redis for cache, sessions, rate limits and locks.
- RabbitMQ for background jobs and event processing.

## Service Boundaries

- Customer service: customer profile, Customer360, customer portal and identity.
- Billing service: packages, balances, payments, debt, invoices and reconciliation.
- RADIUS service: PPPoE/IPoE users, sessions, accounting, CoA/disconnect and NAS state.
- IP service: local, NAT and static pool assignment.
- NOC service: device status, alarms, root cause, mass outage and capacity.
- OLT/ONT service: discovery, optical metrics, ONT actions and topology.
- Ticket service: lifecycle, SLA, assignment and auto-ticket rules.
- Inventory service: stock, handover, barcode, assets and reports.
- HR/KPI service: employees, attendance, schedules, performance and roles.
- Notification service: SMS, Telegram, WhatsApp/app fallback and templates.
- Security service: auth hardening, anomaly detection, audit and policy enforcement.
- AI service: Superpilot analysis, recommendation, confirmation and knowledge retrieval.

## Feature Contract

Every backend feature must include endpoint/service method, validation, permission check, audit log, tests, monitoring signal and API contract update.

## Safety Rules

- Live device actions must have dry-run mode where possible.
- Dangerous actions such as RADIUS disconnect, ONT restart, port shut, quarantine or stock write-off require confirmation.
- External APIs must support retries, timeouts, idempotency keys where applicable and clear error logs.
- Background jobs must use priority queues so heavy reports, backups and discovery never block critical monitoring.
