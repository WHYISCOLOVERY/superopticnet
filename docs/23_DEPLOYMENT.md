# 23 Deployment

## Purpose

Define deployment, domain routing, cutover and rollback for SuperOptic MGS.

## Domains

- mgs.superoptic.net: admin/staff platform.
- billing.superoptic.net: customer portal and billing-facing flows.
- api.superoptic.net: API gateway.
- status.superoptic.net: status page.

## Current Deployment Note

The existing billing.superoptic.net may still point to the legacy billing during development. New MGS deployment must be tested locally/on staging before production DNS cutover to 85.132.57.219 or another selected host.

## Reverse Proxy

- Nginx terminates SSL/TLS.
- Routes frontend and API paths.
- Applies rate limits.
- Protects provider APIs with whitelisting.
- Separates public, customer and internal/admin surfaces.

## Cutover Requirements

- Database backup.
- Application backup.
- Provider payment test.
- Customer login test.
- Staff login test.
- RADIUS dry-run or live-approved test.
- Rollback plan.
- DNS plan.
- Known skipped live-risk test list.

## Rule

Do not cut over production DNS, RADIUS or core-router integration until tests and rollback plan are ready.
