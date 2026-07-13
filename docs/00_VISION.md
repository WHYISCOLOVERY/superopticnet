# 00 Vision

## Mission

SuperOptic MGS is the ISP AI Operating System for SuperOptic. It is not only a billing system, CRM, monitoring page or ticket desk. It is the unified operating layer where billing, network, customer care, inventory, HR, security, analytics and AI work from one context-aware platform.

## Core Philosophy

- Single Pane Of Glass: no role should need to jump between unrelated applications for routine work.
- Context-first execution: customer work happens from Customer360, network work from NOC, inventory work from Inventory, HR work from HR, and security work from Security Center.
- API-first integration: legacy modules, FreeRADIUS, provider payment APIs, Telegram bots, SMS provider and future services must integrate through controlled APIs.
- Event-driven operations: payments, alarms, device status, tickets, inventory handovers, messages and security findings create auditable events.
- AI-assisted, not AI-uncontrolled: Superpilot explains, analyzes, correlates and recommends; dangerous actions require human confirmation.

## Target Qualities

- ISP-grade and carrier-grade.
- Multi-tenant and branch-aware.
- HA-ready and backup-ready.
- Self-healing where safe.
- Cloud-ready and on-prem-ready.
- Secure by design.
- Observable by default.
- Incrementally migratable from the current working PHP/MySQL platform.

## Non-Negotiable Engineering Rules

- Analyze existing code before changing it.
- Never perform massive rewrites.
- Refactor incrementally and preserve working flows.
- Generate migrations for data changes.
- Generate tests for behavior changes.
- Generate API contracts for integrations.
- Generate documentation for operations and handover.
- Build reusable UI/design-system components instead of one-off pages.
- Every feature must include backend logic, frontend surface, permissions, audit logs, tests and monitoring evidence.

## End Goal

SuperOptic MGS must manage a real telecom operator from one platform: customers, payments, RADIUS, OLT/ONT, tickets, inventory, HR, analytics, security, backup/update and AI-assisted decision support.
