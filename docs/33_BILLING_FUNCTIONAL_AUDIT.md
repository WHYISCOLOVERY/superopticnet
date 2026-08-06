# Billing funksional auditi

Tarix: 2026-07-19  
Status: Kod deyisikliyinden evvel audit

## 1. Yoxlama ehatəsi

Audit musteri, profil, odenis, RADIUS xidmeti, paket, balans, provider, sened, hesabat, statistika ve muraciet axinlarini frontendden DB/integration qatina qeder ehatə edir.

Faktiki yoxlama:

- 23 Billing/CRM PHP fayli lint yoxlamasindan kecib;
- auto-filter, stale-request abort, mobil table/modal ve module-entry smoke testleri kecib;
- anonim sehifeler login-e `302`, customer-search API `403` qaytarib;
- yazan real POST-lar data deyismeme sertine gore icra edilmeyib;
- DB count-lar read-only transaction ile alinib.

## 2. Kritik tesdiqlenmis tapintilar

1. **Kritik:** `db.php:5695+` odenisi DB transaction-dan sonra RADIUS-a yazir. RADIUS xetasi payment-i geri qaytarmir; retry duplicate odenis yarada biler.
2. **Yuksek:** `config.php:1231+` Billing-i admin, finance, operator, night_operator, support ve sales rollarina verir; yazan bir cox emelde ayrica action permission yoxdur.
3. **Yuksek:** `billing-payments.php:145+` provider CSV importunda CSRF, MIME ve fayl olcusu qoruması gorunmur.
4. **Tesdiqlenib:** `billing-module-lib.php:269+` aylıq statistikada gunluk neticeni `LIMIT 12` ile kesir.
5. **Tesdiqlenib:** borc gorunusu `billing.debt_raw`, odenis ise `billing.balance` deyisir; canonical borc menbeyi yoxdur.
6. **Tesdiqlenib:** SQL xetalari bos massiv/sifir kimi qaytarilir; UI real xeta ile heqiqi sifiri ayirmir (`billing-module-lib.php:232+`).
7. **Tesdiqlenib:** aktiv router ve customer binding sayi 0-dır; atomik yeni musteri axini `no_active_router` ile dayanacaq.
8. **Tesdiqlenib:** `Kopyala`, `Excel`, `CSV`, `Cap` duymeleri ucun handler tapilmayib (`billing-payments.php:547+`).
9. **Tesdiqlenib:** payment, IP, service ve RADIUS refresh ucun tam audit izi yoxdur.

## 3. Is axinlari

### 3.1 Billing umumi baxisi

| Sahe | Netice |
|---|---|
| Is axini | Musteri, odenis, xidmet ve qisa KPI-lari gosterme |
| Istifadeci rolu | Billing moduluna icazeli roller |
| Baslangic noqtesi | `billing-dashboard.php` / `billing-section-page.php?section=overview` |
| Teleb olunan melumat | Optional tarix/filter |
| Route/fayl | `billing-section-page.php:8+`, overview view |
| API/service | `app_billing_overview_context()` |
| DB | `portal_accounts`, `billing_payments`, `billing_radius_services`, metadata |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Read auditi yoxdur |
| Faktiki test | Render/module smoke kecib; real count oxunub |
| Mock/demo | DB nasazliginda fallback riski var |
| Catismayan funksiya | DB error state ve KPI definition/provenance |
| Tekrar funksiya | Statistika/hesabat ile eyni KPI-lar |
| Tehlukesizlik riski | Rol uzre ferqli KPI scope-u aydin deyil |
| Qerar | Saxla; KPI ve error modelini duzelt |

### 3.2 Musteri siyahisi ve axtaris

| Sahe | Netice |
|---|---|
| Is axini | Ad/kod/status/erazi ile musterini tapmaq |
| Istifadeci rolu | Butun Billing rollari; PII sahesi rol uzre olmalidir |
| Baslangic noqtesi | `billing-customers.php` |
| Teleb olunan melumat | Search, status, erazi, page |
| Route/fayl | `billing-customers.php:1185+`, filter JS |
| API/service | Customer query ve `api/customer-search.php` |
| DB | `portal_accounts`, `billing_radius_services`, `radacct` |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Read yoxdur; PII axtaris pozuntusu security audit-e dusmelidir |
| Faktiki test | Auto-filter/stale-abort kecib; anonim API 403 |
| Mock/demo | DB bos/nasaz olduqda mock fallback var |
| Catismayan funksiya | Field-level PII maskalama ve mock-free error |
| Tekrar funksiya | CRM customer search eyni melumati ayrica alir |
| Tehlukesizlik riski | Butun employee rollarina genis PII qaytara biler |
| Qerar | Saxla; PII scope, pagination ve production mock legvi |

### 3.3 Yeni musteri yaratmaq

| Sahe | Netice |
|---|---|
| Is axini | Sexsiyyet, elaqe, tarif, router, IP ve RADIUS ile musteri yaratmaq |
| Istifadeci rolu | Praktik olaraq Admin; ayrica `create_customer` permission teleb olunur |
| Baslangic noqtesi | `billing-customers.php` yaratma modali |
| Teleb olunan melumat | Ad, mobil, FIN, vesiqe kodu, adres, tarif, pool/IP, router; portal parol qaydasi |
| Route/fayl | `billing-customers.php:976+`, `router-registry-lib.php:595+` |
| API/service | Atomic customer/router/RADIUS onboarding service |
| DB | Account, identity/FIN claim, tariff/service, pool/IP, RADIUS, router binding, audit |
| Xarici inteqrasiya | FreeRADIUS ve router |
| Audit qeydi | Identity ve binding var; butun addimlar vahid correlation ile olmalidir |
| Faktiki test | UI contract kecib; write E2E icra edilmeyib; aktiv router 0 |
| Mock/demo | Form real service-e baglidir, amma real router yoxdur |
| Catismayan funksiya | Aktiv router provisioning, compensating rollback ve aydin step summary |
| Tekrar funksiya | CRM/portal-da musteri yaratma girisleri ola biler |
| Tehlukesizlik riski | Modul icazesi action icazesi deyil; role escalation/PII riski |
| Qerar | Duzelt; router RADIUS E2E olmadan go-live etme |

### 3.4 Musterini redakte ve parol reset

| Sahe | Netice |
|---|---|
| Is axini | Identity/elaqe/tarif melumatini yenilemek, portal/RADIUS parolunu reset etmek |
| Istifadeci rolu | Ayrica permission; parol reset Admin/authorized support |
| Baslangic noqtesi | `billing-customer-edit.php` ve customer profile |
| Teleb olunan melumat | Customer ID, deyisen sahələr, CSRF, sebeb; reset ucun tesdiq |
| Route/fayl | `billing-customer-edit.php:88+`, `portal-security-lib.php:424+` |
| API/service | Identity upsert, RADIUS sync, password reset service |
| DB | Account, FIN claims, RADIUS check/reply/service, audit |
| Xarici inteqrasiya | RADIUS; customer WhatsApp recovery ayrica provider axinidir |
| Audit qeydi | Identity/sensitive/reset qismen var |
| Faktiki test | CSRF/auth static contract kecib; real update/reset icra edilmeyib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Action-level permission, before/after, forced password change |
| Tekrar funksiya | Profile daxilinde eyni emeller var |
| Tehlukesizlik riski | Finance/support/sales modul icazesi ile reset ede biler |
| Qerar | Duzelt ve permission-lari ayir |

### 3.5 Musteri profili ve xidmet emelleri

| Sahe | Netice |
|---|---|
| Is axini | Odenis, IP, router binding, RADIUS refresh, xidmet ve mesaj kontekstini idare etmek |
| Istifadeci rolu | Read butun Billing; her write ayrica permission |
| Baslangic noqtesi | `billing-customer-profile.php` |
| Teleb olunan melumat | Customer, action-a gore IP/service/router/message input ve sebeb |
| Route/fayl | `billing-customer-profile.php:297+` |
| API/service | IP, service, binding, RADIUS, communication services |
| DB | Account, IP history/pool, RADIUS, binding, communication tables |
| Xarici inteqrasiya | Router, RADIUS, message provider |
| Audit qeydi | Qismendir; payment/IP/service/refresh tam deyil |
| Faktiki test | Read/render testleri kecib; real external write yoxlanilmayib |
| Mock/demo | DB fallback riski |
| Catismayan funksiya | Action summary, compensating rollback, unified audit correlation |
| Tekrar funksiya | Balanslar/xidmetler ayrica sehifede tekrar edilir |
| Tehlukesizlik riski | Bir sehifede cox privileged action ve genis rol scope-u |
| Qerar | Saxla detail hub kimi; action service/permission-lari ayir |

### 3.6 Odenisler ve provider importu

| Sahe | Netice |
|---|---|
| Is axini | Odenis siyahisi, provider CSV importu, callback/API odenisi |
| Istifadeci rolu | Read ayrica; import/adjustment Finance/Admin |
| Baslangic noqtesi | `billing-payments.php` |
| Teleb olunan melumat | Filterler; import file/provider; callback idempotency key |
| Route/fayl | `billing-payments.php:145+`, `api/provider/payment.php` |
| API/service | `app_billing_apply_customer_payment()` |
| DB | `billing_payments`, account balance/metadata, RADIUS service |
| Xarici inteqrasiya | Payment provider ve RADIUS |
| Audit qeydi | Tam payment audit yoxdur |
| Faktiki test | 132,481 payment read; real callback/import yazisi icra edilmeyib |
| Mock/demo | DB fallback mock var |
| Catismayan funksiya | CSRF/MIME/size, import preview, idempotency, export handlers |
| Tekrar funksiya | Finance odenisleri eyni cedveli oxuyur |
| Tehlukesizlik riski | Unsafe upload ve payment/RADIUS qeyri-atomikliyi |
| Qerar | Kritik duzelt; ortaq payment read-service saxla |

### 3.7 Nagd/manual odenis

| Sahe | Netice |
|---|---|
| Is axini | Operatorun musteriye nagd/manual odenis yazmasi |
| Istifadeci rolu | Cashier/Finance/authorized operator, ayrica permission |
| Baslangic noqtesi | `billing-cash-payment.php` |
| Teleb olunan melumat | Customer, mebleg, metod, qeyd, CSRF, idempotency key |
| Route/fayl | `billing-cash-payment.php:137+` |
| API/service | Eyni payment service |
| DB | Payment, balance, RADIUS service, audit |
| Xarici inteqrasiya | RADIUS/CoA |
| Audit qeydi | Natamam |
| Faktiki test | CSRF var; real POST icra edilmeyib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Idempotency, kassir novbesi/receipt, RADIUS failure compensation |
| Tekrar funksiya | Odenisler sehifesini tekrar edir |
| Tehlukesizlik riski | Retry duplicate payment |
| Qerar | `Odenis al` composer-ine birlesdir |

### 3.8 Paketler

| Sahe | Netice |
|---|---|
| Is axini | Tarif siyahisini gormek ve admin CRUD-a kecmek |
| Istifadeci rolu | Read Billing; CRUD Admin/Product/Network policy |
| Baslangic noqtesi | `billing-section-page.php?section=packages` |
| Teleb olunan melumat | Paket adi, qiymet, suret, RADIUS atributlari, aktivlik |
| Route/fayl | `views/billing/packages.php:5+`, `radius-billing.php:26+` |
| API/service | Cari read query; real CRUD tapilmayib |
| DB | `billing_tariffs` |
| Xarici inteqrasiya | RADIUS tariff mapping |
| Audit qeydi | CRUD olmadigi ucun yoxdur |
| Faktiki test | 78 tariff oxunub |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Real create/update/deactivate ve impact preview |
| Tekrar funksiya | Radius Billing linki idareetme kimi gorunur, amma CRUD etmir |
| Tehlukesizlik riski | Yanlis istiqametlendirme operatoru aldatir |
| Qerar | Read saxla; real CRUD qur ve ya duymeni legv et |

### 3.9 Balanslar ve xidmetler

| Sahe | Netice |
|---|---|
| Is axini | Customer balance/debt ve RADIUS service siyahisini oxumaq |
| Istifadeci rolu | Billing read |
| Baslangic noqtesi | `section=balances|services` |
| Teleb olunan melumat | Search/status/page |
| Route/fayl | `billing-module-lib.php:116+,144+` |
| API/service | Read context/query |
| DB | Account, payments, `billing_radius_services` |
| Xarici inteqrasiya | RADIUS status metadata |
| Audit qeydi | Read yoxdur |
| Faktiki test | 6,057 radius service; pagination 50 |
| Mock/demo | Error bos netice kimi gizlene biler |
| Catismayan funksiya | Canonical debt/balance formula ve integration status |
| Tekrar funksiya | Customer profile ile eyni data |
| Tehlukesizlik riski | `debt_raw` ve balance ferqi yanlis biznes qerari yaradir |
| Qerar | Customer list/profile-e gorunus/filter kimi birlesdir |

### 3.10 Odenis kanallari/provider gorunusu

| Sahe | Netice |
|---|---|
| Is axini | Payment method paylanmasini gosterme |
| Istifadeci rolu | Billing/Finance read |
| Baslangic noqtesi | `section=providers` |
| Teleb olunan melumat | Tarix/provider filteri |
| Route/fayl | `billing-module-lib.php:168+` |
| API/service | Payment-method aggregate |
| DB | `billing_payments.payment_method` |
| Xarici inteqrasiya | Yoxdur; bu provider konfiqurasiyasi deyil |
| Audit qeydi | Yoxdur |
| Faktiki test | Aggregate query tesdiqlenib |
| Mock/demo | Error sifir kimi gorune biler |
| Catismayan funksiya | Provider health, callback, reconciliation, config admin |
| Tekrar funksiya | Payments/statistics charti |
| Tehlukesizlik riski | Sehife adi funksiyani yanlis teqdim edir |
| Qerar | `Odenis kanallari` adlandir; config-i Admin inteqrasiyalara cixar |

### 3.11 Billing senedleri

| Sahe | Netice |
|---|---|
| Is axini | Payment/contract reference-larini gosterme |
| Istifadeci rolu | Billing read |
| Baslangic noqtesi | `section=documents` |
| Teleb olunan melumat | Customer/reference/filter |
| Route/fayl | `billing-module-lib.php:185+` |
| API/service | Read context |
| DB | Payments ve contract reference metadata |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Yoxdur |
| Faktiki test | Read query tesdiqlenib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Generated receipt/contract, version, download ve print service |
| Tekrar funksiya | Payments listinin reference gorunusu |
| Tehlukesizlik riski | Sened varmis kimi teqdim olunan reference operatoru yanilda biler |
| Qerar | Real document service-e qeder Payments-e birlesdir |

### 3.12 Hesabat ve statistika

| Sahe | Netice |
|---|---|
| Is axini | Secilmis chart/tarix uzre AJAX neticesi gosterme |
| Istifadeci rolu | Billing read, data scope rol uzre |
| Baslangic noqtesi | `billing-statistics.php`, `section=reports|statistics` |
| Teleb olunan melumat | Chart, tarix araligi, custom dates, capacity |
| Route/fayl | `billing-section-page.php:64+`, `billing-module-lib.php:269+,291+` |
| API/service | Statistics context/partial render |
| DB | Payments, customers, services, packages, provider aggregates |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Export/read audit yoxdur |
| Faktiki test | AJAX partial refresh kecib |
| Mock/demo | DB xetasi sifir/mock ola biler |
| Catismayan funksiya | `LIMIT 12` fix; butun datasetlere tarix semantikasi; visible error |
| Tekrar funksiya | Reports statistik context-i birbasa tekrar edir |
| Tehlukesizlik riski | Yanlis chart maliyye qerarini tehrif edir |
| Qerar | Birlesdir ve query kontraktini duzelt |

### 3.13 Muracietler ve CRM

| Sahe | Netice |
|---|---|
| Is axini | Ticket siyahisi, musteri axtarisi, mesul texnik ve status emeli |
| Istifadeci rolu | Messaging/CRM scope; cari CRM istənilən employee-ye acila bilir |
| Baslangic noqtesi | `billing-requests.php`, `crm.php` |
| Teleb olunan melumat | Search, status, priority, customer, employee, text |
| Route/fayl | `billing-requests.php:15+`, `crm.php:244+`, `portal-actions.php:140+` |
| API/service | Customer search, ticket action, Telegram notification |
| DB | `portal_tickets`, customer/account, operation audit |
| Xarici inteqrasiya | Telegram |
| Audit qeydi | Ticket action qismen var |
| Faktiki test | 10,459 ticket read; write icra edilmeyib |
| Mock/demo | Texnik siyahisi hemise mock; empty customer result mock yukleyir |
| Catismayan funksiya | Real HR employee source, pagination, messaging role/scope |
| Tekrar funksiya | Billing requests CRM-i tekrar edir |
| Tehlukesizlik riski | Employee-wide PII search ve production mock |
| Qerar | Billing-den cixar, canonical CRM/ticket novbesine birlesdir |

## 4. Esas duyme ve action xeritəsi

| Duyme/action | Frontend/dispatcher | API/service | DB/xarici tesir | Status |
|---|---|---|---|---|
| Yeni musteri / Bagla | `billing-customers.php` modal POST | Router registry atomic onboarding | Account, identity, tariff/service, IP, RADIUS, router binding, audit | Qismen; aktiv router yoxdur |
| Musterini redakte et / Saxla | `billing-customer-edit.php` POST | Identity update + optional RADIUS sync | Account/FIN/RADIUS/audit | Qismen |
| Portal parolunu reset et | Customer edit/profile action | Portal security reset service | Password hash/session/audit | Qismen; permission genisdir |
| RADIUS parolunu deyis | Customer profile action | RADIUS credential service | `radcheck`/service/audit + router tesiri | Yoxlanilmayib real router |
| Router binding saxla | Customer profile action | Router registry binding service | Binding cedveli/audit | Qismen |
| IP yenile/azad et | Customer profile action | IP allocation/release service | IP pool/history/RADIUS/audit | Qismen |
| RADIUS yenile | Customer profile action | RADIUS sync/refresh | RADIUS tables ve external session | Qismen; audit natamam |
| Xidmet elave et | Customer profile action | Billing service create | `billing_radius_services`, RADIUS/audit | Qismen |
| Mesaj novbesine elave et | Customer profile action | Communication queue | `communication_messages` + provider worker | Qismen |
| Provider CSV import | `billing-payments.php` upload POST | Payment apply service | Payments/balance/RADIUS | Yuksek risk; CSRF/MIME/size natamam |
| Nagd odenisi saxla | `billing-cash-payment.php` POST | Payment apply service | Payment/balance/RADIUS | Qismen; idempotency yoxdur |
| Odenis API callback | `api/provider/payment.php` | Payment apply service | Payment/balance/RADIUS | Yoxlanilmayib real provider |
| Kopyala/Excel/CSV/Cap | `billing-payments.php` toolbar | Handler tapilmayib | Heç bir tesir yoxdur | Istifadesiz/yarimciq |
| Paket idareetmesi | Packages link | `radius-billing.php` | Yalniz read/test sync | Yanlis target; CRUD yoxdur |
| Statistika Yenile/Uygula | AJAX form | Billing statistics partial/context | Read-only queries | Tesdiqlenib, query sehvleri var |
| Ticket action | CRM/`portal-actions.php` | Ticket service + Telegram | `portal_tickets`, audit, notification | Qismen; mock employee/customer riski |

## 5. DB snapshot ve SQL menbeyi

| Olcu | Netice |
|---|---:|
| Customer account | 6,304 |
| `billing_payments` | 132,481 |
| Odenis cemi | 2,421,554.41 AZN |
| `portal_tickets` | 10,459 |
| `billing_radius_services` | 6,057 |
| `billing_tariffs` | 78 |
| `radacct` | 989 |

Bu saylar SQL neticesidir, amma test/demo data `id > 0` serti ile ayrilmir. Ona gore biznes KPI-si kimi temizlenmeden istifade edilmemelidir.

## 6. Funksional audit matrisi

| Is axini | FE | Duyme | Service | DB R/W | Real data | Mock yoxdur | Rol | Audit | State | Mobil | Tekrar | E2E |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Overview | Var | Var | Var | R | Beli | Xeyr | Var | N/A | Qismen | Qismen | Var | Qismen |
| Customer list | Var | Var | Var | R | Beli | Xeyr | Qismen | N/A | Qismen | Qismen | Var | Qismen |
| Customer create | Var | Var | Var | R/W | Xeyr | Beli | Qismen | Qismen | Qismen | Qismen | Var | Xeyr |
| Customer edit/reset | Var | Var | Var | R/W | Qismen | Beli | Xeyr | Qismen | Qismen | Qismen | Var | Xeyr |
| Customer profile actions | Var | Var | Var | R/W | Qismen | Qismen | Xeyr | Qismen | Qismen | Qismen | Var | Xeyr |
| Payments/import | Var | Qismen | Var | R/W | Beli | Xeyr | Xeyr | Xeyr | Qismen | Qismen | Var | Xeyr |
| Cash payment | Var | Var | Var | R/W | Qismen | Beli | Qismen | Xeyr | Qismen | Qismen | Var | Xeyr |
| Packages | Var | Yanlis target | Read-only | R | Beli | Beli | Qismen | N/A | Qismen | Qismen | Var | Qismen |
| Balance/services | Var | Var | Var | R | Beli | Qismen | Var | N/A | Qismen | Qismen | Var | Qismen |
| Reports/statistics | Var | Var | Var | R | Beli | Qismen | Var | Xeyr | Qismen | Qismen | Var | Qismen |
| CRM/tickets | Var | Var | Var | R/W | Beli | Xeyr | Xeyr | Qismen | Qismen | Qismen | Var | Xeyr |

## 7. Qebul meyarlari ve rollback

- Payment DB + RADIUS/CoA axini idempotent saga ve ya kompensasiya olunan state machine-dir.
- Her write action ayrica backend permission ve audit correlation teleb edir.
- CSV import CSRF, MIME/size, preview, row validation ve idempotency ile qorunur.
- Production-da mock customer/technician/payment data gosterilmir.
- Statistics `LIMIT 12` sehvini aradan qaldirir ve dataset tarix semantikasini aciq gosterir.
- Router registry/binding real testden kecmeden customer onboarding go-live olmur.
- Kohne route-lar alias qalir; yeni shell feature flag ile rollback edilir; payment qeydi rollback ucun silinmir, reversal entry yazilir.
