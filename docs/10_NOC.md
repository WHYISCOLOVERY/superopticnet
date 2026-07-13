# 10 NOC

## Purpose

NOC is the operational center for network health, alarms, topology, diagnostics, capacity and root-cause analysis.

## Required Views

- NOC dashboard with current status summary.
- Device monitor with add device, edit device and IP scan.
- Topology/map with draggable, zoomable, status-colored devices and operator notes.
- Active alarms and alarm grouping.
- Mass outage view.
- Digital twin and dependency view.
- RADIUS/NAS status.
- OLT/ONT status.
- Security/anomaly monitor.
- Capacity planning.
- Maintenance window management.

## Priority Rules

- Priority 1: device offline/online, core router status, uplink down, high temperature, fan/power failure, major PON outage and root-cause processing.
- Priority 2: ONT optical monitoring, MAC movement, ASR/session anomaly, SFP trends and ticket updates.
- Priority 3: normal metrics and dashboard updates.
- Priority 4: config backup, full SNMP walk, rediscovery, rollups and long-term analytics.

## Quick Actions

- Run diagnostics.
- Create ticket.
- Create incident.
- Mark maintenance.
- Open Customer360 from affected ONT/customer.
- Open device details.
- Trigger safe polling job.

## Integration Requirements

- SNMP polling.
- SNMP trap receiver on configured trap server.
- Device current-state tables.
- Alarm events and history.
- Telegram/SMS notification rules.
- Superpilot root-cause explanation.

## Safety Rule

Heavy discovery, full SNMP walks, backups and long reports must never block critical monitoring.
