# 25 Roadmap

## Purpose

Track the path from the current working PHP/MySQL platform to the full AI-powered ISP Operating System.

## Phase 1: Stabilize Current Platform

- Customer list, Customer360 and customer portal must show real data reliably.
- Billing day countdown, stable billing day, package price, custom price and add-on services must work.
- Provider payment API lookup and callback must be tested.
- Staff login and customer login must be secured and role-aware.
- RADIUS docker/external integration must pass dry-run tests.
- Inventory, HR/KPI, NOC and ticket modules from legacy MGS must work through unified MGS pages/APIs.
- Full regression must run with live-risk skips documented.

## Phase 2: Single Pane Operations

- Customer360 becomes the main customer work context.
- NOC becomes the main network work context.
- Inventory becomes the main stock/asset work context.
- HR becomes the main employee/KPI work context.
- Security Center becomes the main anomaly/audit work context.
- Superpilot works as a compact assistant drawer across contexts.

## Phase 3: Production Readiness

- Backup Manager and restore points.
- Update Manager with versioned packages.
- Nginx gateway with SSL, rate limits and provider protection.
- Monitoring with logs, metrics and health checks.
- Windows and Ubuntu installers.
- Role-agent and security regression gates.

## Phase 4: AI and Self-Healing

- Superpilot correlates payments, RADIUS, OLT/ONT, tickets, inventory, HR and security.
- AI recommendations become explainable and auditable.
- Safe automated actions are policy-driven.
- Dangerous actions remain confirmation-gated.
- Security anomaly detection supports infected/router/ONT abuse investigation.
- Plugin-enabled delivery uses Statsig, Data Analytics, Figma, RewriteLy, Linear and Sales/Gmail workflows through the tracked plan in `docs/superpowers/plans/2026-07-11-plugin-enabled-product-system.md`.

## Phase 5: Target Stack Migration

- Introduce FastAPI modules behind existing API contracts.
- Introduce React/TypeScript/Tailwind/Shadcn UI by module.
- Migrate primary data to PostgreSQL with migrations and rollback plan.
- Move high-volume analytics to ClickHouse.
- Add Redis and RabbitMQ for cache and queues.
- Containerize services with Docker.

## Current Rule

Move incrementally. Protect working functionality. Test before release. Do not cut over production DNS, RADIUS or router integration until evidence and rollback are ready.

## Active initiative: retire the separate Node "SuperOptic MGS" app

There is a second, fully separate Node.js program (`D:\xampp\htdocs\oltidareetme`) with its own MySQL database (`olt_control_center`) holding real network telemetry, alarms and tickets. It auto-started alongside superopticnet and bridged users out via SSO. The direction is to consolidate everything into superopticnet (one system, module by module) instead of running two programs side by side. See `docs/39_MGS_NODE_CONSOLIDATION.md` for the gap analysis and phased plan. Phase 0 (stop local auto-start) is done; Phase 1 (native telemetry/alarm tables) is next.
