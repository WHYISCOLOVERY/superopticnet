# 08 IP Management

## Purpose

Manage local IP, NAT public IP and static public IP from MGS and deliver the correct assignment to customers through RADIUS and network policies.

## IP Types

- Local IP: CGNAT/internal address such as 10.11.x.x.
- NAT public IP: public egress address used for customer NAT mapping.
- Static public IP: dedicated public IP assigned to one customer/service.

## Pool Management

- Create local, NAT public and static public pools.
- Bind pools to zone and branch.
- Show total, used, free, reserved and blacklisted IPs.
- Detect IP conflicts.
- Prevent reserved or blacklisted IP assignment.
- Keep assignment and release history.
- Show utilization charts and warnings.

## Customer Assignment

- Dynamic local IP: operator selects pool and system assigns a free local IP or RADIUS pool.
- NAT selection: operator selects NAT public IP from allowed NAT pool.
- Static IP: operator reserves a static IP for customer and RADIUS returns Framed-IP-Address.
- IP changes write history with old/new IP, reason, user and timestamp.
- If active session exists, UI offers CoA/disconnect after confirmation.

## Required Tables

- ip_pools, ip_addresses, customer_ip_assignments and ip_assignment_history.

## Validation Rules

- Same IP cannot be assigned to two active customers.
- Static IP cannot be in dynamic allocation if reserved.
- Blacklisted NAT IP cannot be assigned.
- Released IP must be auditable.
