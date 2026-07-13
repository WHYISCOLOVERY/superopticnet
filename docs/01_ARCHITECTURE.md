# 01 Architecture

## Purpose

Define how SuperOptic MGS evolves from the current working platform into a carrier-grade ISP Operating System without breaking existing operations.

## Current Platform Boundary

The current PHP/MySQL platform remains the protected v1 system. New work must be incremental, tested and reversible. Legacy modules can be exposed through APIs before being migrated.

## Target Platform

- Backend: FastAPI services.
- Frontend: React, TypeScript, Tailwind and Shadcn.
- Primary database: PostgreSQL.
- Analytics database: ClickHouse.
- Cache: Redis.
- Queue/event bus: RabbitMQ.
- Reverse proxy/API gateway: Nginx.
- Observability: Prometheus, Grafana and Loki.
- Containers: Docker.
- Authentication: JWT, RBAC and MFA.
- AI: OpenAI-compatible APIs plus local LLM support.

## Logical Layers

- Presentation layer: staff portal, customer portal, dashboard and mobile-friendly views.
- API layer: billing, customer, RADIUS, NOC, OLT/ONT, inventory, HR, notification, security and AI APIs.
- Domain layer: business rules, workflow states, policy engine and confirmation gates.
- Event layer: payment received, service expired, ONT degraded, ticket opened, device down, stock moved, login failed and anomaly detected.
- Data layer: current state, history, audit logs, search indexes, backups and analytics.
- Integration layer: FreeRADIUS, core router, OLTs, SNMP, Telegram, SMS, payment terminals, customer portal and future MyGov.

## Architecture Rules

- Single Pane Of Glass is the UX goal, but modules remain separated behind APIs.
- Heavy jobs must run through queues and never block critical alarms, offline detection or root-cause analysis.
- Every state-changing action must be authenticated, authorized and audited.
- Every external integration must have a contract, retries, idempotency where applicable and error logging.

## Migration Strategy

Wrap current working behavior behind stable API contracts, then migrate one module at a time to FastAPI/React/PostgreSQL. Do not replace working code until the new module passes role-agent tests, regression tests and cutover checks.
