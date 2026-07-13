# SuperOptic MGS Nginx / API Gateway Layer

This folder contains the production reverse-proxy layer for SuperOptic MGS.

## Purpose

- Serve all public domains through one hardened gateway.
- Terminate SSL/TLS.
- Route frontend, customer portal and API services.
- Apply rate limiting for login, OTP, diagnostics and provider APIs.
- Keep admin/internal services separated from public API routes.
- Produce JSON access logs suitable for Loki/Promtail.
- Prepare load-balancing and failover for future microservices.

## Domains

| Domain | Purpose | Upstream |
| --- | --- | --- |
| `mgs.superoptic.net` | Admin/operator panel | `mgs_php_frontend` |
| `billing.superoptic.net` | Customer portal | `customer_portal` |
| `api.superoptic.net` | API gateway | service-specific upstreams |
| `status.superoptic.net` | Public read-only health/status page | `mgs_php_frontend/status.php` and `/api/health.php` |

## Required upstream ports

The default config expects these local services:

| Upstream | Default address |
| --- | --- |
| MGS PHP frontend / Apache / PHP-FPM frontend | `127.0.0.1:8080` |
| Billing service | `127.0.0.1:8001` |
| Radius service | `127.0.0.1:8002` |
| NOC service | `127.0.0.1:8003` |
| OLT service | `127.0.0.1:8004` |
| Diagnostics service | `127.0.0.1:8005` |
| CRM service | `127.0.0.1:8006` |
| Inventory service | `127.0.0.1:8008` |
| Notification service | `127.0.0.1:8009` |
| WebSocket service | `127.0.0.1:8010` |

If the current XAMPP/Apache instance listens on another port, update `conf.d/superoptic-upstreams.conf`.

## Access control

Provider API whitelist is configured in:

```text
conf.d/superoptic-access-control.conf
```

Before production cutover, add real provider source IPs under `$provider_api_allowed`.
By default, private lab/VPN networks are allowed and all other public IPs are rejected on provider-grade routes.

Core gateway options such as JSON log format, gzip, request size, timeout control and suspicious user-agent blocking are in:

```text
conf.d/superoptic-core.conf
```

## Route map

| Public route | Target |
| --- | --- |
| `https://mgs.superoptic.net/` | Admin panel |
| `https://billing.superoptic.net/` | Customer portal |
| `https://api.superoptic.net/api/billing/` | `billing-service:8001` |
| `https://api.superoptic.net/api/radius/` | `radius-service:8002` |
| `https://api.superoptic.net/api/noc/` | `noc-service:8003` |
| `https://api.superoptic.net/api/olt/` | `olt-service:8004` |
| `https://api.superoptic.net/api/diagnostics/` | `diagnostics-service:8005` |
| `https://api.superoptic.net/api/crm/` | `crm-service:8006` |
| `https://api.superoptic.net/api/inventory/` | `inventory-service:8008` |
| `https://api.superoptic.net/api/notification/` | `notification-service:8009` |
| `https://api.superoptic.net/api/notification/otp/` | OTP notification route with stricter limit |
| `https://api.superoptic.net/api/mgs/` | Existing PHP `/api/*` endpoints |
| `https://api.superoptic.net/ws/` | WebSocket service |
| `https://status.superoptic.net/` | Read-only status page |
| `https://status.superoptic.net/api/health.php` | Compatibility health endpoint for the status page link |

## Health check

The application now exposes:

```text
/api/health
```

The status domain root proxies to:

```text
/status.php
```

This page is read-only and shows application, DB, Radius table, message queue,
ticket and gateway readiness checks without exposing secrets.

Expected healthy response:

```json
{
  "success": true,
  "status": "ok",
  "service": "superoptic-mgs"
}
```

Nginx Open Source uses passive upstream failover with `max_fails` and `fail_timeout`.
For active health checks, add Nginx Plus, HAProxy, keepalived, Prometheus blackbox, or an external watchdog that polls `/api/health`.

## Install on Ubuntu

Copy the folder to the server, then run:

```bash
cd /path/to/superopticnet/deploy/nginx
sudo bash install-superoptic-nginx.sh
```

Then install certificates. Example with Certbot:

```bash
sudo certbot --nginx \
  -d mgs.superoptic.net \
  -d billing.superoptic.net \
  -d api.superoptic.net \
  -d status.superoptic.net
```

Validate and reload:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Before copying to the server, run the static gateway validator from the project
root. It checks required files, domains, route map, upstreams, rate limits,
headers, health endpoint and access-control maps without requiring Nginx on the
local machine:

```bash
php tools/validate-nginx-gateway.php
```

## Important production notes

- Point `mgs_php_frontend` to the real local Apache/PHP listener. If Apache serves this project at `/superopticnet` in lab mode, production should use a vhost where the project is served from `/`.
- Keep MySQL, FreeRADIUS, SSH, SNMP and device management ports private.
- Add office/VPN IP ranges in `conf.d/superoptic-access-control.conf` before enabling public admin access. The `$office_or_vpn_allowed` map is intentionally prepared but not forced by default, so the first cutover does not lock operators out accidentally.
- Provider APIs should also validate signed tokens in the application, not only IP whitelist at Nginx.
- JSON logs are written to `/var/log/nginx/superoptic-access.log`; ship this file to Loki with Promtail if required.
- If a microservice is not ready yet, keep the route but leave the upstream service stopped. Nginx will return upstream failure for that route only.
