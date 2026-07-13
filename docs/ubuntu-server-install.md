# SuperOptic MGS Ubuntu Server Quraşdırma

Bu sənəd SuperOptic MGS sistemini Ubuntu serverə qurmaq üçündür. FreeRADIUS bu installer-ə daxil deyil; o ayrıca Ubuntu/VM/Docker üzərində qalacaq və sistem hazır olandan sonra RADIUS SQL/CoA məlumatları ilə qoşulacaq.

## Nə qurulur

- Apache + PHP + lazımi PHP extension-lar
- MySQL server/client
- Node.js + npm
- Java runtime
- SNMP tools (`snmpwalk`)
- SuperOptic PHP billing portalı
- MGS Node operations engine
- systemd service/timer-lər:
  - MGS Node
  - billing maintenance
  - message queue
  - Radius CoA queue worker
  - Superpilot/modem bot poller
  - daily retention/index optimizer
  - security anomaly scanner

## Nə qurulmur

- FreeRADIUS server
- Router/OLT config
- DNS cutover
- SSL sertifikat

## Bir komanda ilə quraşdırma

Layihə fayllarının yanında `superopticnet` və `oltidareetme` qovluqları varsa:

```bash
sudo APP_DOMAIN='billing.superoptic.net' \
  APP_URL='https://billing.superoptic.net' \
  DB_NAME='superoptic_portal' \
  DB_USER='superoptic_app' \
  DB_PASS='REAL_APP_DB_PASSWORD' \
  RADIUS_SQL_HOST='127.0.0.1' \
  RADIUS_SQL_DB='superoptic_portal' \
  RADIUS_SQL_USER='radius' \
  RADIUS_SQL_PASSWORD='REAL_RADIUS_SQL_PASSWORD' \
  RADIUS_SHARED_SECRET='REAL_RADIUS_SHARED_SECRET' \
  RADIUS_CORE_NAS_IP='85.132.57.218' \
  bash deploy/ubuntu-install-superoptic-mgs.sh
```

Əgər script birbaşa `superopticnet` qovluğunun içindən işə salınırsa:

```bash
sudo SOURCE_WEB_ROOT="$PWD" SOURCE_MGS_ROOT="/opt/source/oltidareetme" bash deploy/ubuntu-install-superoptic-mgs.sh
```

## Dry-run

Serverə toxunmadan nə edəcəyini görmək üçün:

```bash
sudo DRY_RUN=1 SOURCE_WEB_ROOT="$PWD" SOURCE_MGS_ROOT="/path/to/oltidareetme" bash deploy/ubuntu-install-superoptic-mgs.sh
```

## Apache

Default olaraq Apache virtual host yaradılır:

- ServerName: `billing.superoptic.net`
- DocumentRoot: `/var/www/superopticnet`

Əgər nginx gateway istifadə olunacaqsa:

```bash
sudo CONFIGURE_APACHE=0 bash deploy/ubuntu-install-superoptic-mgs.sh
```

Sonra `deploy/nginx` runbookuna uyğun nginx layer qurulur.

## RADIUS sonradan qoşulacaq

Bu installer yalnız `config.local.php` daxilində RADIUS SQL/CoA parametrlərini saxlayır. FreeRADIUS server ayrıca hazır olduqda:

- FreeRADIUS SQL config `superoptic_portal` bazasına yönəlir.
- Router secret və NAS IP eyni saxlanır.
- Billing maintenance `radcheck`, `radreply`, `radacct`, `radpostauth` ilə işləyir.

Əgər mövcud Ubuntu FreeRADIUS-u yeni DB-yə bağlamaq lazımdırsa ayrıca sənəd:

```text
docs/ubuntu-freeradius-connect.md
```

## Servis yoxlamaları

```bash
systemctl status apache2
systemctl status superoptic-mgs-node
systemctl list-timers 'superoptic-*'
```

```bash
php /var/www/superopticnet/tools/backup-center-smoke.php
php /var/www/superopticnet/tools/update-package-smoke.php
php /var/www/superopticnet/tools/provider-api-smoke.php
php /var/www/superopticnet/tools/provider-up-api-smoke.php
php /var/www/superopticnet/tools/system-maintenance-optimizer.php --json
php /var/www/superopticnet/tools/security-anomaly-scan.php
```

## Cutover checklist

- `config.local.php` real sirrlərlə serverdə doldurulub.
- MySQL backup aktivdir.
- Provider API smoke pass edir.
- Staff login və customer login pass edir.
- MGS Node service `active` statusdadır.
- VLAN 550 qoşulduqdan sonra NOC/SNMP/trap live test pass edir.
- FreeRADIUS ayrıca qoşulub `radtest` Access-Accept verir.
