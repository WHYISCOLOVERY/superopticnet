# SuperOptic MGS Windows Server Quraşdırma Runbook

Bu runbook `85.132.57.219` uzerinde qurulacaq yeni SuperOptic MGS/billing serveri ucundur. Meqsed kohne billing silinmeden evvel sistemi lokalda tam hazir veziyyete getirmek, sonra DNS/proxy cutover zamani `billing.superoptic.net` altinda ishletmekdir.

## Hedef qurulush

- Public domen: `billing.superoptic.net`
- Admin/MGS domeni: `mgs.superoptic.net`
- API gateway domeni: `api.superoptic.net`
- Lokal web root: `D:\xampp\htdocs\superopticnet`
- MGS Node app: `D:\xampp\htdocs\oltidareetme`
- MySQL/MariaDB: XAMPP MySQL veya ayrica MySQL service
- RADIUS: ayrica Docker/VM/Proxmox FreeRADIUS; bu installer RADIUS server qurmur

## Installer ne edir

- PHP/XAMPP pathlerini yoxlayir.
- MySQL portunu ve PHP `pdo_mysql` extensionunu yoxlayir.
- Node.js, Java ve SNMP alətlərini yoxlayir.
- `config.local.php` yoxdursa production template-den yaradir.
- Lazim olan MySQL schema bootstrap edir.
- MGS Node app ucun `npm install`, DB setup ve start scriptlerini ishledir.
- Windows Scheduled Task yaradir:
  - billing maintenance
  - communication queue
  - RADIUS CoA/disconnect worker
  - modem/Superpilot Telegram bot poller
  - haftalik backup
  - gundelik retention/index optimizer
  - 5 deqiqeden bir security anomaly scanner
- Backup/update sehifesinin ishlemesi ucun restore/update merkezini saxlayir.

## Installer ne etmir

- FreeRADIUS server qurmur.
- Router configini deyishmir.
- OLT/router uzerinde canli komanda icra etmir.
- DNS cutover etmir.
- SSL sertifikati avtomatik almaya mecbur etmir; bunun ucun nginx gateway runbook ayridir.

## Quraşdırma addımları

1. Serverde `D:\xampp` qur ve Apache/MySQL service-lərini ishlet.
2. Layiheni bu pathlere kopyala:
   - `D:\xampp\htdocs\superopticnet`
   - `D:\xampp\htdocs\oltidareetme`
3. Administrator PowerShell ac.
4. Dry-run yoxlama et:

```powershell
PowerShell -ExecutionPolicy Bypass -File D:\xampp\htdocs\superopticnet\deploy\windows\install-superoptic-mgs.ps1 -DryRun
```

5. Real quraşdırma et:

```powershell
PowerShell -ExecutionPolicy Bypass -File D:\xampp\htdocs\superopticnet\deploy\windows\install-superoptic-mgs.ps1 `
  -DbName superoptic_portal `
  -DbUser superoptic_app `
  -RadiusSqlHost 127.0.0.1 `
  -RadiusSqlDb superoptic_portal `
  -RadiusSqlUser radius `
  -RadiusCoreNasIp 85.132.57.218
```

6. Installer RADIUS parollarini prompt ile isteye biler. Real sirleri yalniz serverde daxil et.
7. Apache virtual host ve ya nginx gateway ile `billing.superoptic.net`-i `D:\xampp\htdocs\superopticnet`-e yonelt.

## RADIUS qoşulması

FreeRADIUS bu Windows installer ile qurulmur. Movcud ve ya ayrica qurulacaq Ubuntu FreeRADIUS serveri sonradan bu melumatlarla sisteme qosulacaq:

- RADIUS SQL host
- RADIUS SQL database
- RADIUS SQL user/password
- Core NAS IP
- RADIUS shared secret
- CoA port: `3799`
- Auth/acct portlari: `1812/1813`

Installer bu deyerleri `config.local.php`-ye yazir ve billing maintenance RADIUS SQL cedvellerini sync ede bilir. FreeRADIUS-un oz qurasdirilmasi ayrica qalir.

Ubuntu FreeRADIUS serveri movcuddursa ve yalniz yeni SuperOptic DB-ye baglanacaqsa, Windows qurasdirmasindan sonra bu script istifade olunur:

```bash
sudo DB_HOST='85.132.57.219' DB_NAME='superoptic_portal' DB_USER='radius' DB_PASS='REAL_RADIUS_SQL_PASSWORD' RADIUS_SECRET='REAL_RADIUS_SHARED_SECRET' CORE_NAS_IP='85.132.57.218' bash ubuntu-connect-existing-freeradius.sh
```

Detalli sened: `docs/ubuntu-freeradius-connect.md`.

## Gündəlik servis yoxlaması

```powershell
schtasks /Query /TN "SuperOptic MGS Billing Maintenance"
schtasks /Query /TN "SuperOptic MGS Communication Queue"
schtasks /Query /TN "SuperOptic MGS Radius CoA Worker"
schtasks /Query /TN "SuperOptic MGS Modem Bot"
schtasks /Query /TN "SuperOptic MGS Weekly Backup"
schtasks /Query /TN "SuperOptic MGS Daily Optimizer"
schtasks /Query /TN "SuperOptic MGS Security Anomaly Scan"
```

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\production-readiness-check.php
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\full-system-regression.php
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\system-maintenance-optimizer.php --json
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\security-anomaly-scan.php
```

## Backup və update

- Backup/update sehifesi: `Operations Center -> Backup`
- Haftalik avtomatik backup scheduled task ile qurulur.
- Update paketi yuklenende sistem evvel backup yaratmalidir.
- Restore etmeden evvel `restore_verify` axini ishledilmelidir.

## Canli cutover checklist

- MySQL backup var.
- Kohne billing exportu yoxlanilib.
- Provider API lookup/payment smoke pass edir.
- Musteri login ve staff login pass edir.
- RADIUS SQL sync pass edir.
- CoA/disconnect dry-run pass edir.
- VLAN 550 qosulandan sonra NOC/SNMP/trap live test pass edir.
- DNS `billing.superoptic.net` yeni servere yonelir.
