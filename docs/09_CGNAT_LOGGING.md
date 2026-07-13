# 09 CGNAT Logging

## Purpose

Store, search and retain CGNAT/NAT logs safely at ISP scale without collapsing the primary billing database.

## Required Search

- Find customer by local IP, NAT/public IP, port and time.
- Find NAT/public IP usage history for a customer.
- Find high fan-out or suspicious NAT behavior.
- Export legal request results with audit trail.

## Storage Strategy

- Current v1 may store reduced NAT log samples or imported logs in MySQL for development/testing.
- Target production storage is ClickHouse with time partitioning.
- PostgreSQL stores metadata, requests, audit logs and search jobs.
- Raw high-volume logs must not be stored in unbounded primary tables.
- ClickHouse production schema is stored at `deploy/clickhouse/superoptic-cgnat.sql`.
- Readiness validator: `php tools/cgnat-storage-readiness.php`.

## Retention

- Retention must be configurable by policy and legal requirement.
- Older partitions must be archived or dropped automatically.
- Search requests must record who searched, reason, filters and exported output.

## Production Cutover

1. Install ClickHouse on the analytics server.
2. Apply `deploy/clickhouse/superoptic-cgnat.sql`.
3. Set `CLICKHOUSE_HOST`, `CLICKHOUSE_PORT`, `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, `CLICKHOUSE_DATABASE` and `CLICKHOUSE_SECURE`.
4. Run `php tools/cgnat-storage-readiness.php` and require `status=ready`.
5. Keep MySQL `radius_nat_logs` only for short development/search samples; route high-volume CGNAT/syslog ingestion to ClickHouse.

## Security

- CGNAT search is sensitive and requires explicit permission.
- Every search is audited.
- Exported files must be time-limited and access-controlled.
- AI can summarize authorized results but must not expose CGNAT data to unauthorized users.

## Analytics

- Detect NAT fan-out.
- Detect infected or abusive customer patterns.
- Correlate with RADIUS sessions, security anomalies, tickets and customer complaints.
