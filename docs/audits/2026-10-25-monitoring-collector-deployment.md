# Monitoring collector deployment — 2026-10-25

## Purpose

VM 202 is the dedicated monitoring/log collector. It keeps high-volume operational telemetry off the MGT application VM while MGT remains the system of record for normalized alarms, tickets and customer views.

## Collector services

| Service | Bind | Retention | Status |
| --- | --- | --- | --- |
| Remote syslog | UDP/TCP 514 on `172.16.5.4` | 90 daily rotations | Active and acceptance-tested from MGT |
| SNMP traps | UDP 162 on `172.16.5.4` | 90 daily rotations | Active and locally tested |
| NetFlow/IPFIX input | UDP 2055 on `172.16.5.4` | raw flows 30 days | Active; no exporter configured yet |
| Health | `http://192.168.100.202/healthz` | current state | Active |

The collector uses `172.16.5.4/32` as its VLAN 550 source identity, routed via `192.168.100.1`. This avoids an overlapping subnet assignment while giving network equipment a stable monitoring-source address.

## Verified results

- MGT sent a real syslog test to the collector. It was saved with the source IP and timestamp.
- SNMPv2 trap reception was tested locally and was written to the dedicated trap log.
- NetFlow collector is listening and produces its capture files.
- Nginx health response confirms the syslog, trap and NetFlow services are active.

## Current device-side blocker

Read-only SNMP requests from both MGT (`172.16.5.2`) and the collector (`172.16.5.4`) to the registered Cisco core and Cisco switch time out. The collector's TCP probes to those devices are also blocked. This is not a PHP/UI error and must be resolved in the VLAN 550 management ACL or device SNMP configuration.

The core's stored internal SSH profile is configured for port 22, but that port is unreachable from MGT. The externally stated management port was also unavailable from MGT. Do not replace existing access or apply router configuration until the intended server-only management path is reachable.

## Required network-device configuration

For each core/router, Cisco switch and HSGQ OLT, permit the collector source `172.16.5.4`:

- SNMP read-only UDP 161.
- Syslog UDP 514 to `172.16.5.4`.
- SNMP trap UDP 162 to `172.16.5.4`.
- NetFlow/IPFIX UDP 2055 to `172.16.5.4`, where supported.
- SSH only if CLI collection is required; keep it source-restricted and read-only for the collector account.

After those rules exist, validate a single Cisco device with `sysDescr.0`, run its IF-MIB inventory, then enable a bounded traffic poll. Validate a single HSGQ OLT CLI/SNMP profile before full ONT discovery. This sequence prevents a failed full walk from loading the network or generating misleading tickets.

## Data flow after access is restored

```mermaid
flowchart LR
  D[Core / switches / OLTs] -->|syslog, SNMP traps, NetFlow, SNMP polls| C[VM 202 collector]
  C -->|normalized samples, alarms, rollups| M[MGT application]
  M -->|NOC, Customer360, tickets, notifications| U[Operators and field staff]
```

MGT must retain normalized interface/ONT/alarm history and ticket correlations. The collector keeps raw packet-flow and event intake so high-volume storage and parsing do not delay billing, login or mobile APIs.
