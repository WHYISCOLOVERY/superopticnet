# 19 DevOps

## Purpose

Define deployment, monitoring, backup, update, logging and release operations for on-prem and cloud-ready MGS.

## Required Platform Services

- Docker for future service packaging.
- Nginx as reverse proxy/API gateway.
- SSL/TLS termination.
- Prometheus metrics.
- Grafana dashboards.
- Loki logs.
- Backup Manager.
- Update Manager.
- Health checks.
- Queue monitoring.

## Nginx Gateway

- Route admin/staff portal, customer portal and APIs.
- Apply SSL/TLS and HSTS.
- Apply rate limits for login, OTP, public APIs and provider APIs.
- Protect provider APIs with whitelisting.
- Hide internal services from public access.

## Backup and Update

- Weekly backup of MGS application, database and device configs.
- Manual restore point before upgrades.
- Update packages with version metadata.
- Rollback instructions per update.
- Backup verification logs.

## Release Rule

Production release requires backup, test report, migration report, known-risk list and rollback path.
