# Sebeke ve NOC funksional auditi

Tarix: 2026-07-19  
Status: Kod deyisikliyinden evvel audit

## 1. Ehatə ve mehdudiyyet

Audit `noc-devices.php`, `mgs-integration.php`, `mgs-integration-lib.php`, RADIUS/router servisleri ve NOC view-lari uzre aparilib. Real router, Huawei OLT ve ONT hele qosulmadigi ucun SSH/SNMP/CoA neticeleri `Yoxlanilmayib`; mock adapter testi real cihaz testi kimi teqdim edilmir.

## 2. Indiki route xeritəsi

| Route | Gorunusler | Problem |
|---|---|---|
| `noc-devices.php` | `monitor`, `devices`, `backups`, `ont-search` | Esas NOC isleri, amma bir nece kritik POST-da action permission natamamdir |
| `operations-center.php?section=noc|olt|technical` | NOC/OLT/texniki summary | `noc` ve `olt` section-lari request-module resolver-de ayrica module map almaya bilir |
| `mgs-integration.php` | overview, processes, actions, bots, devices, backups, logs | Device/backup/log gorunuslerini tekrar edir |
| RADIUS/IP/NAT sehifeleri | NAS/router, session, pool, NAT log | NOC ile eyni biznes kontekstidir, ferqli shell-lere bolunub |

## 3. Is axinlari

### 3.1 IP scan ve tapilmis cihazi secmek

| Sahe | Netice |
|---|---|
| Is axini | CIDR scan edib tapilan hostlari modalda gostermek, secileni cihaza cevirmek |
| Istifadeci rolu | NOC/Admin; create ucun ayri action icazesi teleb olunmalidir |
| Baslangic noqtesi | `noc-devices.php` scan action |
| Teleb olunan melumat | CIDR/interface, timeout/credential profile, secilmis host |
| Route/fayl | `noc-devices.php:36-82` |
| API/service | `/api/service/network-scan`; sonra `/api/service/devices` |
| DB | Scan discovery/cache; yalniz secimden sonra device registry/profile |
| Xarici inteqrasiya | ICMP/TCP/SNMP probe |
| Audit qeydi | Scan ve device create actor/correlation ile ayrica yazilmalidir |
| Faktiki test | Network monitor/guard smoke testleri kecib; real subnet device secim E2E bu auditde aparilmayib |
| Mock/demo | Huawei adapter testleri mock; real scan neticesi kimi sayilmir |
| Catismayan funksiya | Cari ugur mesaji scan neticesinin monitor data qatinda saxlandigini deyir; tapilan hostlar evvel modal candidate olmalidir |
| Tekrar funksiya | Discovery MGS daxilinde de ayrica gorunur |
| Tehlukesizlik riski | Istifadeci scan edib avtomatik qeyd yazmamalidir; create action backend role ile ayrilmalidir |
| Qerar | Duzelt: scan read-only candidate, `Monitor-a elave et` ayri tesdiqli action |

### 3.2 Cihaz qeydiyyati ve management path

| Sahe | Netice |
|---|---|
| Is axini | Router/OLT/switch-i registry-ye elave etmek ve management path yoxlamaq |
| Istifadeci rolu | NOC Admin/Super Admin |
| Baslangic noqtesi | `noc-devices.php?view=devices` |
| Teleb olunan melumat | Ad, vendor/model, IP, filial, management VLAN/path, protokol ve credential reference |
| Route/fayl | `noc-devices.php:57-101`, `router-registry-lib.php` |
| API/service | `/api/service/devices`, management-path service |
| DB | Device learning/profile, router registry, access references, audit |
| Xarici inteqrasiya | ICMP/SSH/SNMP |
| Audit qeydi | Create/update/path result yazilmalidir |
| Faktiki test | `network-management-path-guard` path down olduqda yan tesir olmadan kecib; router registry testleri kecib |
| Mock/demo | Aktiv router ve binding sayi 0; real cihaz yoxdur |
| Catismayan funksiya | Multi-router production onboarding ve real credential-vault reference E2E |
| Tekrar funksiya | MGS `devices` eyni registry-ni ferqli UI-da gosterir |
| Tehlukesizlik riski | Plain credential DB saxlanmasi yolverilmez; secret reference istifade olunmalidir |
| Qerar | Birlesdir: bir Device Registry, NOC detail drawer ve admin credential service |

### 3.3 Status probe ve canli monitor

| Sahe | Netice |
|---|---|
| Is axini | Butun ve ya tek cihazi probe edib online/offline/degraded statusu saxlamaq |
| Istifadeci rolu | NOC/Support read; probe action NOC/Admin |
| Baslangic noqtesi | `noc-devices.php?view=monitor` |
| Teleb olunan melumat | Device ID veya all, probe profile, timeout |
| Route/fayl | `noc-devices.php:102-140`, `mgs-integration-lib.php:7322+` |
| API/service | Probe-all/probe-single service |
| DB | Device learning profiles, alarm events, transition/audit |
| Xarici inteqrasiya | ICMP/SNMP/SSH probe |
| Audit qeydi | Probe summary ve status transition saxlanir |
| Faktiki test | Native monitor ve profile testleri kecib; notification transition dedup down/up testleri kecib |
| Mock/demo | Real avadanliq offline oldugu ucun live accuracy yoxlanilmayib |
| Catismayan funksiya | Status tooltip sebebi, online/offline muddeti, last success/failure, full-screen NOC layout |
| Tekrar funksiya | Operations NOC, MGS devices ve NOC monitor eyni statusu gosterir |
| Tehlukesizlik riski | Frontend kohne statusu online kimi saxlamamalidir; backend stale rule olmalidir |
| Qerar | Birlesdir ve backend canonical status/transition engine et |

### 3.4 Alarmdan ticket ve bildiris yaratmaq

| Sahe | Netice |
|---|---|
| Is axini | Device/ONT status kecidinden bir defe alarm, ticket ve notification yaratmaq |
| Istifadeci rolu | Worker/system; NOC emekdasi baxir ve idare edir |
| Baslangic noqtesi | Probe/collector transition |
| Teleb olunan melumat | Device/ONT, evvelki/yeni status, sebeb, timestamp, dedup key |
| Route/fayl | `mgs-integration-lib.php` alarm/ticket services, notification transition library |
| API/service | Alarm transition, ticket create, communication queue |
| DB | `mgs_alarm_events`, `portal_tickets`, communication/audit tables |
| Xarici inteqrasiya | Telegram/SMS/WhatsApp/app aktiv kanal qaydasi |
| Audit qeydi | Transition, ticket ve delivery correlation yazilir |
| Faktiki test | `notification-transition-dedup` ve `network-olt-ticket-rules` kecib; synthetic test 8 device/18 ONT/3 alert-ticket istifade edib |
| Mock/demo | Syntheticdir; real OLT yoxdur |
| Catismayan funksiya | Her qrup ucun aydin Azerbaycan dili sablonu ve kanal on/off ayari ile production E2E |
| Tekrar funksiya | Bots/MGS notification qaydalari ferqli sehifelerde gosterilir |
| Tehlukesizlik riski | Her probe-da eyni offline mesaji/ticket tekrari resurs ve operator yukudur |
| Qerar | Saxla; transition-only + dedup + recovery message qaydasini canonical et |

### 3.5 Backup ve CLI discovery

| Sahe | Netice |
|---|---|
| Is axini | Cihaz konfigurasiya backup-i almaq ve read-only CLI discovery icra etmek |
| Istifadeci rolu | NOC/Admin; config store/apply Super Admin veya ayrica privileged action |
| Baslangic noqtesi | `noc-devices.php?view=backups` ve device detail |
| Teleb olunan melumat | Device, credential ref, command map, store/apply flag, sebeb |
| Route/fayl | `noc-devices.php:117-170`, `mgs-integration-lib.php:5801-5881,9218+` |
| API/service | Backup worker, command plan/SSH executor, CLI discovery |
| DB | Backup snapshots/metadata, command action/audit |
| Xarici inteqrasiya | SSH/CLI |
| Audit qeydi | Command plan, actor, device, hash ve netice yazilmalidir; sirr/xam config loglanmamalidir |
| Faktiki test | Command-map guard testleri kecib; real SSH backup yoxlanilmayib |
| Mock/demo | Mock command output testleri var |
| Catismayan funksiya | Real vendor E2E, backup encryption/retention/restore verification |
| Tekrar funksiya | NOC ve MGS ayrica backup sehifesi saxlayir |
| Tehlukesizlik riski | `run_cli_discovery` store backup yoluna explicit admin gate olmadan daxil ola bilir; action gate sertlesmelidir |
| Qerar | Birlesdir; read-only discovery ve config-store permission-larini ayir |

### 3.6 Huawei OLT/ONT axtarisi ve emr xeritəsi

| Sahe | Netice |
|---|---|
| Is axini | MA5608T/MA5800-X7 uzre ONT-ni SN/MAC/port ile tapmaq ve read-only melumat almaq |
| Istifadeci rolu | NOC/Support read; config emeli ayrica privileged approval |
| Baslangic noqtesi | `noc-devices.php?view=ont-search` |
| Teleb olunan melumat | OLT, frame/slot/port ve ya SN/MAC, vendor command map |
| Route/fayl | `noc-devices.php:202-288`, Huawei adapter ve command-map fayllari |
| API/service | Command map, live ONT read, SNMP/CLI adapter |
| DB | OLT/device profiles, ONT discovery/cache, action audit |
| Xarici inteqrasiya | Huawei CLI/SNMP |
| Audit qeydi | Read query ve her potensial config plan yazilmalidir |
| Faktiki test | `ont-command-map` kecib; Huawei read-only adapter-de 10 mock test kecib |
| Mock/demo | Tam olaraq mock-only; real Huawei cihaz testi yoxdur |
| Catismayan funksiya | Real CLI prompt/pagination/error parser ve vendor firmware versiya matrisi |
| Tekrar funksiya | Operations OLT summary ve NOC ONT search ayridir |
| Tehlukesizlik riski | Vendor command override ile dangerous command injection; allowlist ve preview/approval mecburidir |
| Qerar | Qismen saxla; real OLT testinden evvel yalniz read-only preview |

### 3.7 Credential, vendor default ve monitoring mode idareetmesi

| Sahe | Netice |
|---|---|
| Is axini | Device access override, vendor command default ve monitor mode deyismek |
| Istifadeci rolu | Yalniz Super Admin/Network Admin olmalidir |
| Baslangic noqtesi | Device/admin ayarlari |
| Teleb olunan melumat | Device/vendor, username, secret reference, protokol/port, mode, sebeb |
| Route/fayl | `noc-devices.php:171-259`, `mgs-integration-lib.php:6715-6805,6807+,7444+` |
| API/service | Access override, vendor default, monitoring mode services |
| DB | `device_access_overrides`, command/vendor config, device profiles, audit |
| Xarici inteqrasiya | SSH/SNMP |
| Audit qeydi | Secret deyer yox, reference ve rotation metadata yazilmalidir |
| Faktiki test | UI security guard smoke 403 kontraktini kecib; service-level action gate problemi kod auditinde tesdiqlenib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Her service daxilinde mecburi actor permission, vault/reference ve secret rotation |
| Tekrar funksiya | Sistem ayarlari ve NOC sehifesinde parcalanib |
| Tehlukesizlik riski | Yuksek: controller kritik POST-larda admin gate etmir; SSH password DB-de birbasa saxlanir |
| Qerar | Derhal duzelt; go-live blocker |

### 3.8 RADIUS/router session ve CoA

| Sahe | Netice |
|---|---|
| Is axini | Musterini router-e baglamaq, paket/IP/sifre deyisimi ve payment/expiry uzre session acmaq-baglamaq |
| Istifadeci rolu | Billing/Support read; privileged action service permission; worker automation |
| Baslangic noqtesi | Customer profile, RADIUS/NAS/router admin sehifeleri |
| Teleb olunan melumat | Customer/service, router binding, NAS, credentials/reference, framed IP/pool, tariff, action reason |
| Route/fayl | `router-registry-lib.php`, `radius-billing.php`, `radius-nas.php`, CoA worker |
| API/service | Router registry, RADIUS control plan, disconnect worker |
| DB | Router registry/bindings, radcheck/radreply, `radacct`, `radius_nat_logs`, audit |
| Xarici inteqrasiya | FreeRADIUS, router RADIUS/CoA 1812/1813/3799 |
| Audit qeydi | Plan, execute result, router/NAS ve customer correlation yazilmalidir |
| Faktiki test | Registry ve RADIUS control plan synthetic DB testleri kecib; disconnect worker yalniz dry-run kecib |
| Mock/demo | Real router qosulu deyil; live CoA yoxlanilmayib |
| Catismayan funksiya | Aktiv router yoxdur; real payment-open, expiry-close, IP/paket/sifre E2E gozleyir |
| Tekrar funksiya | Billing customer profile ve ayrica RADIUS sehifeleri emeliyyati tekrar gosterir |
| Tehlukesizlik riski | Router secimi olmayan multi-router musteri yanlis NAS-a yaza biler; binding mecburi olmalidir |
| Qerar | Saxla, amma real router E2E olmadan production-ready sayma |

## 4. Esas duyme ve action xeritəsi

| Duyme/action | Frontend/dispatcher | API/service | DB/xarici tesir | Status |
|---|---|---|---|---|
| IP scan et | `noc-devices.php:36+` | `/api/service/network-scan` | Candidate/discovery read; persistent create olmamalidir | Qismen |
| Monitor-a elave et | Scan result modal -> create action | `/api/service/devices` | Device registry/profile/audit | Qismen; ayri tesdiq lazimdir |
| Management path yoxla | `noc-devices.php:83+` | Management-path service | Probe/path result/audit | Qismen, real path yoxdur |
| Hamisini probe et | `noc-devices.php:102+` | Probe-all service | Device states/alarm transitions | Qismen |
| Tek cihazi probe et | `noc-devices.php:128+` | Probe-single service | Device profile/status/audit | Qismen |
| Backup worker-i islet | `noc-devices.php:117+` | Backup worker | Backup metadata/snapshot/audit + SSH | Yoxlanilmayib real device |
| Tek backup al | `noc-devices.php:141+` | Backup service | Backup snapshot/audit + SSH | Yoxlanilmayib |
| CLI discovery | `noc-devices.php:157+` | Command plan/SSH executor | Discovery + optional backup store | Yuksek permission riski |
| Monitor mode saxla | `noc-devices.php:171+` | Monitoring-mode service | Device profile/audit | Permission natamam |
| Access override saxla | `noc-devices.php:181+` | Access-override service | Credential record/audit | Kritik; plain password yolu |
| Command map saxla | `noc-devices.php:202+` | Command-map admin service | Vendor/device command config/audit | Qismen; allowlist mecburi |
| Vendor default saxla | `noc-devices.php:248+` | Vendor-default service | Vendor config/audit | Permission natamam |
| ONT live oxu | `noc-devices.php:260+` | ONT read/CLI-SNMP adapter | Read/cache/audit | Mock-only |
| Manual CLI icra et | `noc-devices.php:289+` | SSH command executor | Cihaz tesiri + audit | Real device yox; privileged only |
| Router elave/bind et | Router registry UI/service | Router registry/binding | Registry, customer binding, audit | Qismen; aktiv router 0 |
| RADIUS disconnect/CoA | Worker/control plan | RADIUS disconnect service | Session/accounting/audit + router | Dry-run only |

## 5. DB snapshot

| Cedvel | Say |
|---|---:|
| `device_learning_profiles` | 9 |
| `mgs_alarm_events` | 1,121 |
| `router_registry` | 0 |
| `radacct` | 989 |
| `radius_nat_logs` | 2,952 |

## 6. Funksional audit matrisi

| Is axini | FE | Duyme | Service | DB R/W | Real data | Mock yoxdur | Rol | Audit | State | Mobil | Tekrar | E2E |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| IP scan/candidate | Var | Var | Var | Qismen | Yoxlanilmayib | Qismen | Qismen | Qismen | Qismen | Qismen | Var | Xeyr |
| Device registry | Var | Var | Var | R/W | Qismen | Beli | Qismen | Var | Qismen | Qismen | Var | Qismen |
| Status monitor | Var | Var | Var | R/W | Qismen | Beli | Var | Var | Qismen | Qismen | Var | Qismen |
| Alarm/ticket/dedup | Qismen | Worker | Var | R/W | Qismen | Beli | Worker | Var | Qismen | Qismen | Var | Synthetic kecib |
| Backup/discovery | Var | Var | Var | R/W | Xeyr | Qismen | Qismen | Qismen | Qismen | Qismen | Var | Xeyr |
| Huawei ONT read | Var | Var | Var | R/W | Xeyr | Xeyr | Var | Var | Qismen | Qismen | Var | Mock-only |
| Credential/default | Var | Var | Var | R/W | Qismen | Beli | Xeyr | Qismen | Qismen | Qismen | Var | Xeyr |
| RADIUS/router | Var | Var | Var | R/W | Qismen | Qismen | Qismen | Qismen | Qismen | Qismen | Var | Dry-run |

## 7. Go-live blockerleri

1. **Tesdiqlenib:** device access override ve vendor/monitor ayarlarinda service-level action permission natamamdir.
2. **Tesdiqlenib:** SSH password-un DB-de birbasa saxlanma yolu var; secret reference/vault teleb olunur.
3. **Tesdiqlenib:** aktiv router registry/binding 0-dır; real musteri lifecycle router-e baglana bilmez.
4. **Yoxlanilmayib:** real Huawei OLT CLI/SNMP, real backup/restore ve real RADIUS CoA.
5. **Teklif:** `operations-center`, `noc-devices` ve `mgs-integration` eyni canonical Device/Alarm/Backup servicelerini saxlayib vahid NOC workbench-de birlesdirilsin.

## 8. Qebul ve rollback

- Scan avtomatik persistent device yaratmir; secim ve tesdiq teleb edir.
- Status backendde last success/failure/heartbeat ile hesablanir; transition-only alert yaradir.
- Credential deyerleri DB/HTML/auditde yoxdur, yalniz secret reference saxlanir.
- Dangerous CLI/SNMP write emeli allowlist, preview, sebeb ve ikinci tesdiq teleb edir.
- Real MA5608T, MA5800-X7 ve router test matrisi kecmeden `real device ready` statusu verilmir.
- NOC yeni shell feature flag ile acilir; kohne route-lar alias qalir; DB migration additive ve backup snapshotlari immutable qalir.
