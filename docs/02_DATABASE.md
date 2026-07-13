# 02 Database

## Purpose

Define the data model required for a scalable ISP Operating System and the migration path from current MySQL to PostgreSQL and ClickHouse.

## Current and Target Databases

- Current protected database: MySQL used by the PHP platform.
- Target primary database: PostgreSQL.
- Target analytics database: ClickHouse for traffic, RADIUS accounting, NAT logs, metrics, alarms and long historical analysis.
- Cache and coordination: Redis for sessions, rate limits, locks, idempotency keys and short-lived computed state.

## Data Separation

- Current state: latest customer, service, RADIUS, device, interface, ONT and alarm state used by dashboards.
- Historical metrics: raw samples, 5-minute rollups, hourly rollups and daily rollups.
- Logs: RADIUS, NAT, device, SMS, Telegram, auth and audit logs.
- Backups: device configs, database backup metadata, restore points and hashes.
- Tickets and alarms: lifecycle, SLA, assignment, evidence and root-cause links.
- Search indexes: customer code, FIN, phone, IP, MAC, SN, username, device and ticket text.
- Audit logs: sensitive reads, writes, permission decisions and AI-confirmed actions.

## Scale Rules

- Dashboards read current-state tables and summary views, not raw historical scans.
- Large historical tables are time-partitioned.
- High-volume analytics moves to ClickHouse or rollups before production scale.
- Raw data retention is configurable.
- Indexes exist for common customer, device, IP, MAC, SN, payment, ticket and employee searches.

## Migration Rules

- Every schema change requires a migration and a smoke test.
- Every migration includes rollback or a documented recovery path.
- No destructive data change is allowed without backup and explicit cutover plan.
- Migration scripts support Windows/XAMPP development and Ubuntu/server deployment.

## Required Core Tables

- customers, customer_services, customer_devices, customer_ip_assignments.
- packages, payments, balance_movements, invoices, provider_transactions.
- radius_users, radius_sessions, radius_logs and radacct sync tables.
- ip_pools, ip_addresses, ip_assignment_history.
- devices, device_current_status, interfaces_current_status, onts_current_status.
- tickets, ticket_events, ticket_assignments, alarms, alarm_events.
- inventory_items, stock_movements, employee_assets.
- employees, roles, permissions, attendance, kpi_events.
- notification_templates, notification_queue, message_history.
- ai_actions, ai_findings, audit_logs, security_anomaly_events.
