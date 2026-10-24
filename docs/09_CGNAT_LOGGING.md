# 09 CGNAT Logging

## Purpose

Store, search and retain CGNAT/NAT logs safely at ISP scale without collapsing the primary billing database.

## Required Search

- Find customer by local IP, NAT/public IP, port and time.
- Find NAT/public IP usage history for a customer.
- Find high fan-out or suspicious NAT behavior.
- Export legal request results with audit trail.

## Storage Strategy

- Production router currently exports Cisco NAT Event Logging (NetFlow v9/NEL) to `10.99.99.12:2055/UDP`.
- The local `nfcapd` collector stores five-minute files below `/var/log/superoptic/netflow/YYYY/MM/DD/HH` with LZ4 compression and automatic expiration.
- The live collector guardrail is 80 GB maximum size, 30 days maximum age and 90% low watermark. The size limit can shorten effective retention and is not a substitute for the final legal archive.
- `tools/nfdump-law-export.php` provides an immediate CLI-only, approval-gated export path from raw NEL files and records the query scope plus output SHA-256 in an append-only JSONL audit.
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

1. Keep the router NEL exporter and `nfcapd` collector healthy; alert on missing five-minute files, sequence failures and disk pressure.
2. Install ClickHouse on the analytics server or attach a dedicated encrypted log volume sized for the measured event rate.
3. Apply `deploy/clickhouse/superoptic-cgnat.sql`.
4. Set `CLICKHOUSE_HOST`, `CLICKHOUSE_PORT`, `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, `CLICKHOUSE_DATABASE` and `CLICKHOUSE_SECURE`.
5. Run `php tools/cgnat-storage-readiness.php` and require `status=ready`.
6. Keep MySQL `radius_nat_logs` only for short development/search samples; route high-volume CGNAT/NEL ingestion to ClickHouse.

## Security

- CGNAT search is sensitive and requires explicit permission.
- Every search is audited.
- Exported files must be time-limited and access-controlled.
- AI can summarize authorized results but must not expose CGNAT data to unauthorized users.

## Analytics

- Detect NAT fan-out.
- Detect infected or abusive customer patterns.
- Correlate with RADIUS sessions, security anomalies, tickets and customer complaints.
