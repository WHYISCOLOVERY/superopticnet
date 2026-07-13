# 18 Installer

## Purpose

Install and bootstrap SuperOptic MGS on Windows and Ubuntu while allowing FreeRADIUS to remain a separately managed service.

## Current Installer Requirements

- Windows installer/package for XAMPP/PHP/MySQL development and local server deployment.
- Ubuntu installer for server deployment.
- Configure PHP, database access, required tools, scheduled jobs and web paths.
- Prepare SNMP tools where required.
- Prepare Node/JS tools only where required by current modules.
- Create backup, update and optimizer tasks.
- Provide external FreeRADIUS connection settings without forcing FreeRADIUS install.

## Future Installer Requirements

- Docker deployment.
- FastAPI services.
- React frontend build.
- PostgreSQL.
- ClickHouse.
- Redis.
- RabbitMQ.
- Nginx gateway.
- Prometheus/Grafana/Loki.

## FreeRADIUS Rule

FreeRADIUS is external unless explicitly installed by a separate RADIUS guide. MGS must be able to connect to an existing FreeRADIUS server after platform installation.

## Installer Safety

- Installer must not overwrite production data without confirmation.
- Installer must create backup before upgrade.
- Update Manager must support versioned update packages.
- Restore points must exist for application and database state.
- Logs must show every installation step and failure clearly.
