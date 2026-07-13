# 07 Radius

## Purpose

RADIUS controls PPPoE/IPoE access, IP assignment, sessions, accounting, service activation, suspension and disconnect workflows.

## Current Integration Rule

FreeRADIUS can run separately from the application. MGS must prepare users, packages, pools and accounting logic so the existing RADIUS server can be connected without changing the active core router configuration during development.

## Required Functions

- PPPoE and IPoE user provisioning.
- NAS device management.
- RADIUS package/profile attributes.
- Online session list.
- Accounting ingestion and search.
- Auth/reject logs.
- CoA/disconnect preparation and execution with confirmation.
- RADIUS sync status per customer/service.
- Dry-run mode for tests and pre-cutover validation.

## Required Attributes

- PPPoE: Service-Type, Framed-Protocol, Framed-IP-Address, Framed-Pool, Framed-Route, Cisco-AVPair and speed/rate attributes as supported by NAS.
- IPoE: Framed-IP-Address, pool mapping, DHCP/Option-82 mapping where supported and delegated IPv6 prefix when introduced.
- Accounting: Acct-Status-Type, session ID, username, NAS, framed IP, input/output octets, start/stop time and termination cause.

## Safety Rules

- Do not change live core router config without explicit approval.
- CoA/disconnect is a dangerous action and requires permission plus confirmation.
- If active session exists after IP/package change, UI must offer disconnect/CoA rather than silently cutting service.
- Failed RADIUS sync must be visible in Customer360 and RADIUS pages.

## Test Requirements

- Local/docker RADIUS test.
- Positive Access-Accept for active user.
- Reject/suspend behavior for inactive user.
- Accounting ingestion test.
- Dry-run CoA test.
- Provider package-to-radius sync test.
