# HR funksional auditi

Tarix: 2026-07-19  
Status: Kod deyisikliyinden evvel audit

## 1. Ehatə ve fakt statusu

Bu audit `operations-center.php?section=hr`, `hr-employee-service.php`, `hr-module-lib.php`, `mgs-integration-lib.php` ve `views/hr/*` uzre aparilib. UI-da formun gorunmesi is axininin islek sayilmasi ucun kifayet deyil.

| Status | Netice |
|---|---|
| Tesdiqlenib | Kod + DB + tehlukesiz smoke test uygun gelir |
| Qismen tesdiqlenib | Service movcuddur, amma tam E2E ve ya bir kecid ugursuzdur |
| Yoxlanilmayib | Real emek haqqi/hüquqi production neticesi ve ya brauzer E2E yoxdur |
| Teklif | Hedef UX/arxitektura, cari fakt deyil |

## 2. Modul serhedi ve rol qaydasi

- `config.php:1163-1228` HR-i ayrica modul kimi qeyd edir; `config.php:1231-1255` rol-modul xeritəsini verir.
- `operations-center.php:639-670` emekdas yaratma/yenileme action-larini dispatch edir.
- `hr-employee-service.php:8-20` HR ve admin ucun create/update icazesini service qatinda yoxlayir.
- `hr-employee-service.php:23-48` yeni emekdasi passiv/inaktiv profil kimi transaction-da yaradir; optional kompensasiya bazasi yazir ve audit edir.
- HR emekdas profili yaradir, amma rol vermir. Giris hesabi ve rol yalniz admin istifadeci idareetmesinde verilmelidir.

## 3. Is axinlari

### 3.1 Emekdas profili yaratmaq ve yenilemek

| Sahe | Netice |
|---|---|
| Is axini | Sexsi/is melumatlari ile HR profili yaratmaq ve yenilemek |
| Istifadeci rolu | HR ve Admin; rol teyin etme yalniz Admin |
| Baslangic noqtesi | `operations-center.php?section=hr&hr_tab=employees` modal |
| Teleb olunan melumat | Ad, login/email, vezife, filial/erazi, status; optional maas tipi ve gross/net baza |
| Route/fayl | `views/hr/employees.php:21-23`, `operations-center.php:639-670`, `hr-employee-service.php` |
| API/service | `hr_employee_create()` / `hr_employee_update()` service funksiyalari |
| DB | `portal_accounts`; optional `hr_employee_compensations`; operation audit |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Actor, action ve profil ID-si yazilir |
| Faktiki test | `hr-employee-create-role` kecib: HR profil yaradir, rol/admin serhedi qorunur |
| Mock/demo | Axinda mock tapilmayib |
| Catismayan funksiya | Login hesabi yaradilmadan sonrakı admin novbesinin daha aydin statusu |
| Tekrar funksiya | `admin-users.php` ile profil melumati qismen tekrarlanir |
| Tehlukesizlik riski | HR formuna role input qaytarilsa privilege escalation yaradar; service rol yazmamalidir |
| Qerar | Saxla; HR profilini ve Admin hesab/rolunu aydin iki merhele et |

### 3.2 Davamiyyet profili ve gelis-cixis

| Sahe | Netice |
|---|---|
| Is axini | Is qrafiki/RFID profilini saxlamaq ve gelis-cixis hadiselerini yazmaq |
| Istifadeci rolu | HR/Admin profil idare edir; emekdas oz hadisəsini qeyd ede biler |
| Baslangic noqtesi | `hr_tab=attendance`, `views/hr/attendance.php:10-23` |
| Teleb olunan melumat | Emekdas, filial, kart/RFID, is baslama/bitme vaxti, gecikme limiti; event tipi/vaxti |
| Route/fayl | `operations-center.php:987-1017`, `mgs-integration-lib.php:1134+` |
| API/service | Attendance profile/event service funksiyalari |
| DB | `hr_attendance_profiles`, `hr_attendance_events` |
| Xarici inteqrasiya | Gelecek RFID/terminal; cari test DB service seviyyesindedir |
| Audit qeydi | Profil ve event action-lari jurnal olunur |
| Faktiki test | HR modul separation testi kecib; cari DB-de 9 attendance event var |
| Mock/demo | Terminal inteqrasiyasi yoxlanilmayib |
| Catismayan funksiya | Real terminal idempotency, duplicate punch ve timezone contract |
| Tekrar funksiya | Ayrica attendance sehifesi saxlanir, HR overview KPI-lari ile eyni data ola biler |
| Tehlukesizlik riski | Emekdas basqasinin eventini yaza bilmemelidir; employee scope backendde qalmalidir |
| Qerar | Saxla ve real terminaldan evvel event-source/idempotency testi elave et |

### 3.3 Mezuniyyet ve ezamiyyet

| Sahe | Netice |
|---|---|
| Is axini | Mezuniyyet/ezamiyyet talebi yaratmaq, status kecidi ve payroll tesirini saxlamaq |
| Istifadeci rolu | Emekdas oz talebi; HR/Admin baxir ve status deyisir |
| Baslangic noqtesi | `hr_tab=leave-travel`, `views/hr/leave-travel.php:15-21` |
| Teleb olunan melumat | Emekdas, tip, baslama/bitme, gun, sebeb; ezamiyyet yeri/gunluk/xerc; status sebebi |
| Route/fayl | `operations-center.php:1070-1107`, `mgs-integration-lib.php:1275+,1327+` |
| API/service | Leave ve travel create/update service funksiyalari |
| DB | `hr_leave_requests`, `hr_travel_requests`, audit |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Create/status actor ve sebeb ile yazilmalidir |
| Faktiki test | `hr-leave-travel-docs` kecib; cari DB-de leave/travel qeydleri 0-dır |
| Mock/demo | Testler rollback/synthetic data ile aparilib |
| Catismayan funksiya | Azerbaycan qanun profili uzre illik limit, bayram/is gunu calendari ve approval matrisi production datasi ile yoxlanmayib |
| Tekrar funksiya | Eyni sehifede iki ayri biznes axini var, amma eyni emekdas/tarix kontekstini paylasir |
| Tehlukesizlik riski | Status sebebsiz ve tarix toqqusmasi yoxlanmadan deyismemelidir |
| Qerar | Saxla; vahid request drawer, ayri tip ve qanun qaydasi service qatinda |

### 3.4 Maas, vergi ve qanuni payroll hesablamasi

| Sahe | Netice |
|---|---|
| Is axini | Gross/net bazasindan xalis maas, isci vergisi ve sirketin tam xercini hesablamaq |
| Istifadeci rolu | HR/Maliyye/Admin; emekdas yalniz oz neticesini gorur |
| Baslangic noqtesi | `hr_tab=payroll`, `views/hr/payroll.php:17-23` |
| Teleb olunan melumat | Emekdas, dovr, gross/net bazasi, hüquqi profil, mezuniyyet/ezamiyyet, elaveler/tutmalar, sebeb |
| Route/fayl | `operations-center.php:1018-1033,1050-1069`, `mgs-integration-lib.php:996-1072,1559-1626` |
| API/service | Compensation ve payroll calculation/save service |
| DB | `hr_employee_compensations`, `hr_payroll_records`, legal-profile/config cedvelleri, audit |
| Xarici inteqrasiya | Vergi orqanina real e-beyanname inteqrasiyasi yoxdur |
| Audit qeydi | Hesablama ve status actor/reason ile yazilmalidir |
| Faktiki test | `hr-salary-score` ve `hr-payroll-legal` kecib; cari DB-de 13 compensation, 6 payroll qeydi var |
| Mock/demo | Hüquqi reqemlerin production normativ menbeyi bu auditde xaricden verifikasiya edilmeyib |
| Catismayan funksiya | Qanun profilinin effective-date/version menbeyi, payroll lock ve tekrar hesablama ferq hesabatı |
| Tekrar funksiya | Bonus/Maliyye bolmesinde emekdas odenisine hazirliq ile qismen tekrarlanir |
| Tehlukesizlik riski | Keçmiş dovrun hüquqi profili sonradan deyisse netice sessiz deyismemelidir; snapshot/version saxlanmalidir |
| Qerar | Duzelt: hesablamani service olaraq saxla, hüquqi versiya ve immutable payroll snapshot elave et |

### 3.5 Sicil bali, ceza ve mukafat

| Sahe | Netice |
|---|---|
| Is axini | 100 bal bazasina ceza/mukafat event-i yazmaq ve dovr uzre hesablamaq |
| Istifadeci rolu | HR/Admin idare edir; emekdas oz profilinde gorur |
| Baslangic noqtesi | `hr_tab=performance` |
| Teleb olunan melumat | Emekdas, event tipi, bal, sebeb, tarix, dovr |
| Route/fayl | `operations-center.php:1034-1049`, `mgs-integration-lib.php:1689-1753` |
| API/service | Score event service |
| DB | `hr_score_events` ve audit |
| Xarici inteqrasiya | Ticket/install/performance eventleri ile gelecek avtomatik elaqe |
| Audit qeydi | Her bal deyisikliyi actor ve sebeble yazilir |
| Faktiki test | `hr-salary-score` kecib; cari DB-de 7 score event var |
| Mock/demo | Avtomatik event inteqrasiyasi qismen syntheticdir |
| Catismayan funksiya | Illik/secilmis dovr resetinin event-sourcing qaydasi ve ikiqat ceza dedup-u |
| Tekrar funksiya | Inventory/install bonusu ile payroll bonusu ayrica hesablanir |
| Tehlukesizlik riski | Bal birbasa overwrite edilmemeli; immutable event ve correction event olmalidir |
| Qerar | Saxla; overwrite yox, event + reset/correction modeli ile tamamla |

### 3.6 Emekdas senedleri

| Sahe | Netice |
|---|---|
| Is axini | Sened tipi, son tarix ve tamliq statusunu saxlamaq |
| Istifadeci rolu | HR/Admin; emekdas oz sened statusunu gore biler |
| Baslangic noqtesi | `hr_tab=documents`, `views/hr/documents.php:11-17` |
| Teleb olunan melumat | Emekdas, sened tipi/nomresi, tarixler, fayl metadata, status |
| Route/fayl | `operations-center.php:1108-1125`, `mgs-integration-lib.php:1379+` |
| API/service | HR document service |
| DB | `hr_employee_documents`, audit |
| Xarici inteqrasiya | Fayl storage contracti yoxlanilmayib |
| Audit qeydi | Metadata/status deyisikliyi jurnal olunur |
| Faktiki test | `hr-leave-travel-docs` kecib; cari DB-de real document qeydi 0-dır |
| Mock/demo | Synthetic test |
| Catismayan funksiya | Virus scan, MIME/size policy, retention ve expiry notification E2E |
| Tekrar funksiya | Admin user profil attachment-lari ile birlesdirilmemelidir |
| Tehlukesizlik riski | Sened fayllari public webroot-da ve guessable adla saxlanmamalidir |
| Qerar | Duzelt ve tehlukesiz private document service ile tamamla |

### 3.7 Payroll-dan Maliyye odenisine kecid

| Sahe | Netice |
|---|---|
| Is axini | Tesdiqlenmis payroll neticesini Maliyye entry/odenis novbesine oturmek |
| Istifadeci rolu | HR tesdiq edir, Maliyye odenise hazirlayir, Admin nezaret edir |
| Baslangic noqtesi | Payroll status emeli ve finance bridge action |
| Teleb olunan melumat | Payroll ID, kecid statusu, mebleg, dovr, mecburi sebeb |
| Route/fayl | `operations-center.php:1126-1149`, `mgs-integration-lib.php:1916-1960,1979+` |
| API/service | Payroll status transition ve HR-finance bridge |
| DB | `hr_payroll_records`, finance entry/bridge/audit cedvelleri |
| Xarici inteqrasiya | Bank/API yoxdur |
| Audit qeydi | Status deyisikliyinde sebeb teleb olunur |
| Faktiki test | `hr-finance-bridge` ugursuz olub: smoke test yeni mecburi `reason` parametrini gondermir; production UI sebeb teleb edir |
| Mock/demo | Test syntheticdir |
| Catismayan funksiya | Test/service kontraktinin eynilesdirilmesi ve tam payroll->finance->paid E2E |
| Tekrar funksiya | Maliyye employee bonus/payment preparation ile overlap var |
| Tehlukesizlik riski | Eyni payroll-un iki defe finance-e kecmesi idempotency key ile bloklanmalidir |
| Qerar | Duzelt; tam E2E kecmeden islek kimi qebul etme |

## 4. Esas duyme ve action xeritəsi

| Duyme/action | Frontend/dispatcher | API/service | DB/xarici tesir | Status |
|---|---|---|---|---|
| Yeni emekdas / Yadda saxla | Employees modal -> `operations-center.php:639+` | `hr_employee_create()` | Account profil, optional compensation, audit | Tesdiqlenib; rol yazmir |
| Emekdasi yenile | Employee edit modal | `hr_employee_update()` | Account profil, audit | Tesdiqlenib |
| Giris hesabi/rol ver | HR-da olmamalidir; `admin-users.php` | Admin user/role service | Account auth/role/audit | Qerar: yalniz Admin |
| Davamiyyet profili saxla | Attendance modal | Attendance profile service | `hr_attendance_profiles`, audit | Qismen |
| Gelis/cixis qeyd et | Attendance action | Attendance event service | `hr_attendance_events`, audit | Qismen |
| Maas bazasi saxla | Compensation form | Compensation service | `hr_employee_compensations`, audit | Tesdiqlenib smoke |
| Payroll hesabla/saxla | Payroll form | Payroll/legal calculator | `hr_payroll_records`, snapshot/audit | Qismen |
| Payroll statusunu deyis | Payroll row action | Status transition service | Payroll status/audit; sebeb mecburi | Qismen |
| Maliyye-e otur | Payroll finance bridge | HR-finance bridge service | Payroll + finance bridge/entry/audit | Ugursuz smoke |
| Mezuniyyet yarat/tesdiq et | Leave/travel modal | Leave service | `hr_leave_requests`, audit | Qismen |
| Ezamiyyet yarat/tesdiq et | Leave/travel modal | Travel service | `hr_travel_requests`, audit | Qismen |
| Bal/ceza/mukafat elave et | Performance form | Score event service | `hr_score_events`, audit | Tesdiqlenib smoke |
| Sened elave et/status deyis | Documents modal | Document service | `hr_employee_documents`, audit/storage metadata | Qismen |
| Filtrle/axtar | Modul auto-filter | HR context read | Read-only | Tesdiqlenib module smoke |

## 5. DB snapshot

| Cedvel | Say |
|---|---:|
| `hr_attendance_events` | 9 |
| `hr_employee_compensations` | 13 |
| `hr_leave_requests` | 0 |
| `hr_travel_requests` | 0 |
| `hr_payroll_records` | 6 |
| `hr_employee_documents` | 0 |
| `hr_score_events` | 7 |

Saylar 2026-07-19 read-only count snapshotidir; funksiyanin islekliyini tekbasina subut etmir.

## 6. Funksional audit matrisi

| Is axini | FE | Duyme | Service | DB R/W | Real data | Mock yoxdur | Rol | Audit | State | Mobil | Tekrar | E2E |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Emekdas profili | Var | Var | Var | R/W | Beli | Beli | Var | Var | Qismen | Qismen | Var | Tesdiqlenib |
| Davamiyyet | Var | Var | Var | R/W | Qismen | Qismen | Var | Var | Qismen | Qismen | Az | Qismen |
| Mezuniyyet/ezamiyyet | Var | Var | Var | R/W | Xeyr | Qismen | Var | Var | Qismen | Qismen | Az | Qismen |
| Maas/vergi | Var | Var | Var | R/W | Qismen | Qismen | Var | Var | Qismen | Qismen | Var | Qismen |
| Sicil bali | Var | Var | Var | R/W | Qismen | Beli | Var | Var | Qismen | Qismen | Var | Qismen |
| Senedler | Var | Var | Var | R/W | Xeyr | Qismen | Var | Var | Qismen | Qismen | Az | Qismen |
| HR->Maliyye | Var | Var | Var | R/W | Qismen | Beli | Var | Var | Qismen | Yoxlanilmayib | Var | Ugursuz |

## 7. Tesdiqlenmis problemler ve qerarlar

1. **Qismen tesdiqlenib:** HR->Maliyye smoke testi yeni mecburi sebeb kontrakti ile uyusmur. Qerar: test ve E2E duzeldilmeden go-live yoxdur.
2. **Tesdiqlenib:** HR profil yaradir, rol vermir. Qerar: saxla; rol yalniz Admin.
3. **Qismen tesdiqlenib:** hüquqi payroll hesablayicisi var, amma normativ versiyasin xarici/production verifikasiyasi yoxdur. Qerar: effective-date ile hüquqi profil versiyasi.
4. **Teklif:** Emekdas detail drawer-da profil, davamiyyet, mezuniyyet, maas, bal ve sened summary-si; esas listler ise ayri is axini qalir.
5. **Teklif:** Modul daxili `Statistika` ayrica hesablama menbeyi olmur; qlobal Hesabatlar HR preset-ine gedir.

## 8. Qebul meyarlari ve rollback

- HR create hec bir halda role/permission yazmir.
- Admin hesabi yaratmadan profil `Giris hesabi yoxdur` statusu gosterir.
- Payroll neticesi hüquqi profil versiyasi ve input snapshot saxlayir.
- Mezuniyyet/ezamiyyet tarix toqqusmasi ve is gunu calendari ile yoxlanir.
- Payroll->Maliyye bir defe icra olunur, sebeb mecburidir ve E2E test kecir.
- Butun CRUD modal/drawer-lari loading/error/dirty-state ve mobil testden kecir.
- Migration additive; kohne HR tab route-lari ilk merhelede saxlanir; yeni UI feature flag ile geri qaytarila bilir.
