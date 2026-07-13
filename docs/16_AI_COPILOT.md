# 16 AI Copilot

## Purpose

Superpilot is the platform AI assistant. It supports operators, NOC engineers, technicians, inventory staff, finance, HR, security and management by analyzing the full MGS context.

## AI Principles

- Explain what it found.
- Analyze data across modules.
- Correlate customer, network, payment, ticket, inventory and security evidence.
- Recommend next action.
- Never blindly execute dangerous actions.
- Always respect RBAC permissions.
- Ask for confirmation before state-changing actions.

## Context Sources

- Customer360.
- Payments and balance movements.
- RADIUS sessions, accounting and rejects.
- OLT/ONT optical data and restart/flapping history.
- Tickets and resolutions.
- SMS/Telegram/app/WhatsApp message history.
- Inventory and employee asset handovers.
- HR/KPI and attendance.
- NOC alarms, topology and device state.
- Security anomaly events.
- Knowledge base and historical resolutions.

## Example Questions

- Why is customer 2320025 disconnecting?
- What was this payment used for?
- Who has this modem?
- Which employee has this item?
- Open a ticket for this customer because internet is down.
- Which customers may have virus or abnormal traffic?
- Why did many tickets open on this PON?
- Which work is delayed for this technician?

## Actions With Confirmation

- Open ticket.
- Send message.
- Assign inventory handover.
- Prepare RADIUS disconnect/CoA.
- Recommend ONT restart.
- Create security incident.
- Suggest quarantine or rate-limit.

## AI Architecture

- OpenAI-compatible API support.
- Local LLM support for on-prem mode.
- Retrieval layer for customer/ticket/inventory/network knowledge.
- Tool execution layer with permission checks and confirmation gates.
- Audit log for prompt, detected intent, evidence, recommendation and confirmed action.

## Safety

Superpilot can prepare and recommend. It must not silently perform financial corrections, service cuts, router/OLT changes, ONT restart, stock write-off or security quarantine.
