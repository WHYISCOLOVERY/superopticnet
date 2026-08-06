# Anbar funksional auditi

Tarix: 2026-07-19  
Status: Kod deyisikliyinden evvel audit

## 1. Ehatə

Audit `operations-center.php?section=inventory`, `inventory-module-lib.php`, `inventory-operations-lib.php` ve `views/inventory/*` uzre frontendden DB emeliyyatina qeder aparilib. Cari rol qaydasi `inventory-operations-lib.php:16-17` uzre Anbardar/Admin yazir, Manager/Admin tesdiq edir.

## 2. Esas service ve DB kontrakti

- Kataloq yenileme/deaktivasiya/adjustment: `inventory-operations-lib.php:74-122`.
- Location balance ve avtomatik sened nomresi: `inventory-operations-lib.php:123-132`.
- Medaxil: `inventory-operations-lib.php:138+`.
- Filial/location transferi: `inventory-operations-lib.php:169+`.
- Emekdasa tehvil: `inventory-operations-lib.php:174+`; tehvil tesdiqi `:201+`.
- Musteride istifade: `inventory-operations-lib.php:234+`.
- Emekdasdan qaytarma: `inventory-operations-lib.php:258+`.
- Xarici/sirkete qaytarma sorğusu: `inventory-operations-lib.php:287+`; tesdiq `:337+`; imtina `:390+`.
- Senedler: `inventory-operations-lib.php:405-406`.

## 3. Is axinlari

### 3.1 Kataloqda mal yaratmaq, yenilemek ve deaktiv etmek

| Sahe | Netice |
|---|---|
| Is axini | Material, modem, kabel ve inventar tipini kataloqda saxlamaq |
| Istifadeci rolu | Anbardar/Admin yazir; diger icazeli roller oxuyur |
| Baslangic noqtesi | `inventory_tab=catalog`, `views/inventory/catalog.php` |
| Teleb olunan melumat | SKU/ad, kateqoriya, olcu vahidi, serial/MAC telebi, qiymet, aktivlik |
| Route/fayl | `operations-center.php`, `inventory-operations-lib.php:74-102` |
| API/service | Catalog create/update/deactivate service action-lari |
| DB | `inventory_stock_items`, audit/action cedvelleri |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Actor, item, evvel/sonra ve sebeb saxlanmalidir |
| Faktiki test | `inventory-catalog-adjustment` ve modul separation testleri kecib |
| Mock/demo | Service axininda mock tapilmayib |
| Catismayan funksiya | Deaktiv item ucun asili stok/asset tesirinin UI xulasesi |
| Tekrar funksiya | `devices`, `materials`, `cable-drums` kataloq filterleri kimi eyni menbedir |
| Tehlukesizlik riski | Hard delete stok tarixcesini poza biler; deaktivasiya esas olmalidir |
| Qerar | Saxla; tip tablarini ayrica modul yox, saxlanmis filter et |

### 3.2 Anbara mal medaxil etmek

| Sahe | Netice |
|---|---|
| Is axini | Alis/tehvil senedi ile anbar qaligini artirmaq |
| Istifadeci rolu | Anbardar/Admin |
| Baslangic noqtesi | `inventory_tab=movements`, `views/inventory/movements.php:24-27,104+` |
| Teleb olunan melumat | Mal, miqdar, location/filial, sirket/tehvil veren, qiymet, qeyd, serial/MAC varsa siyahi |
| Route/fayl | `operations-center.php`, `inventory-operations-lib.php:138+` |
| API/service | Intake service; avtomatik document sequence |
| DB | `inventory_stock_movements`, `inventory_stock_items`, balance/location, `inventory_documents`, serial/asset cedvelleri |
| Xarici inteqrasiya | Maliyye/e-qaime elaqesi gelecek merheledir |
| Audit qeydi | Medaxil, miqdar, qiymet, sened nomresi ve actor yazilir |
| Faktiki test | `inventory-lifecycle` ve `inventory-operational-integrity` kecib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Supplier/counterparty seciminin Maliyye canonical company menbeyi ile tam elaqesi |
| Tekrar funksiya | Overview quick action ve movements formu eyni axina aparir |
| Tehlukesizlik riski | Eyni xarici senedin iki defe medaxili idempotency ile bloklanmalidir |
| Qerar | Saxla ve `Medaxil et` esas Anbar emeli kimi daha gorunen et |

### 3.3 Filiala/anbara transfer

| Sahe | Netice |
|---|---|
| Is axini | Stoku bir location/filialdan digerine transfer etmek |
| Istifadeci rolu | Anbardar/Admin; hedef filial tehvil tesdiqi teleb ede biler |
| Baslangic noqtesi | `inventory_tab=movements`, transfer formu `views/inventory/movements.php:174+` |
| Teleb olunan melumat | Menbe, hedef, mal, miqdar, serial/MAC, sebeb |
| Route/fayl | `inventory-operations-lib.php:169+` |
| API/service | Location transfer service |
| DB | Stock movements, location balances, document/audit |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Menbe ve hedef eyni operation/correlation ID ile yazilir |
| Faktiki test | `inventory-location-transfer` kecib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Yolda/tesdiq gozleyen transfer statusu ve hedef filial qebulu UI-da tam deyil |
| Tekrar funksiya | Filial stock listleri ayrica deyil, location filteri ile eyni menbedir |
| Tehlukesizlik riski | Menfi qaliq ve eyni serialin iki location-da qalmasi transaction-da bloklanmalidir |
| Qerar | Saxla; optional maker-checker ile tamamla |

### 3.4 Emekdasa mal tehvil etmek ve tesdiqletmek

| Sahe | Netice |
|---|---|
| Is axini | Texnike/inventar sahibine mal vermek, emekdas tesdiqi ile adina kecirmek |
| Istifadeci rolu | Anbardar yaradir; emekdas oz tehvilini tesdiq edir; Admin nezaret edir |
| Baslangic noqtesi | `inventory_tab=handover`, `views/inventory/handover.php:15-29` |
| Teleb olunan melumat | Emekdas, mal/asset, miqdar, serial/MAC, tapşiriq/customer baglantisi, qeyd |
| Route/fayl | `inventory-operations-lib.php:174-233` |
| API/service | Handover create ve confirm service |
| DB | Stock movements/balances, employee custody/asset, documents, audit |
| Xarici inteqrasiya | HR employee ve texniki task/customer konteksti |
| Audit qeydi | Tehvil veren, alan, vaxt ve tesdiq ayrica yazilir |
| Faktiki test | `inventory-equipment-kpi-flow` kecib: texnik stoku 1-den 0-a dusub, asset musteriye kecib |
| Mock/demo | Synthetic rollback testi, service real DB strukturunu isledib |
| Catismayan funksiya | Mobil emekdas tesdiqi, imza/OTP politikasi ve gecikmis tehvil novbesi |
| Tekrar funksiya | Technicians tab eyni custody datasi ucun ayrica gorunusdur |
| Tehlukesizlik riski | Anbardar emekdas adindan tesdiq ede bilmemelidir; actor/owner ayrilmalidir |
| Qerar | Saxla ve iki terefli tesdiqi backendde mecburi et |

### 3.5 Musteride mal istifadesi ve texnik stokundan silinme

| Sahe | Netice |
|---|---|
| Is axini | Texnikin adindaki modemi/materiali secilmis musteri qurasdirmasinda istifade etmek |
| Istifadeci rolu | Texnik oz custody-si; səlahiyyətli support/admin nezaret |
| Baslangic noqtesi | Texniki qurasdirma baglanisi / customer consume action |
| Teleb olunan melumat | Customer, task, item, miqdar, serial/MAC, netice |
| Route/fayl | `inventory-operations-lib.php:234+`; qurasdirma service inteqrasiyasi |
| API/service | Customer consume service |
| DB | Employee stock/custody, stock movements, `inventory_assets`, customer equipment, audit |
| Xarici inteqrasiya | Billing customer ve performance/bonus |
| Audit qeydi | Texnik, musteri, task ve asset correlation yazilir |
| Faktiki test | `inventory-equipment-kpi-flow` tam service zencirini kecib |
| Mock/demo | Test musteri/emekdas datasi rollback olunub |
| Catismayan funksiya | UI-da task/customer seciminin mecburi ve aydin olmasi, offline sahə retry-si |
| Tekrar funksiya | Customer tab eyni asset elaqesini ayrica siyahida gosterir |
| Tehlukesizlik riski | Texnik basqa emekdas custody-sinden mal sile bilmemelidir |
| Qerar | Saxla; qurasdirma E2E-nin canonical inventory addimi et |

### 3.6 Emekdasdan anbara qaytarma

| Sahe | Netice |
|---|---|
| Is axini | Istifade olunmayan/qalan mali emekdas custody-sinden anbara qaytarmaq |
| Istifadeci rolu | Emekdas qaytarma verir; Anbardar qebul/veziyyet tesdiq edir |
| Baslangic noqtesi | Handover/returns axini |
| Teleb olunan melumat | Item/asset, miqdar, serial/MAC, veziyyet, sebeb, menbe/hedef |
| Route/fayl | `inventory-operations-lib.php:258+`, `views/inventory/returns-inspection.php` |
| API/service | Employee return service |
| DB | Custody, movements, balances, asset status, document, audit |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Qaytaran/qebul eden, veziyyet ve sebeb saxlanir |
| Faktiki test | `inventory-lifecycle` kecib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Inspection neticesinden repair/scrap/stock statusuna tam UI transition |
| Tekrar funksiya | Returns-inspection sehifesi qismen read-only siyahidir |
| Tehlukesizlik riski | Xarab asset saglam stok kimi avtomatik qaytarilmamalidir |
| Qerar | Duzelt; qebul + inspection status transition-ni tamamla |

### 3.7 Sirkete/quruma xarici qaytarma

| Sahe | Netice |
|---|---|
| Is axini | Xarab, yanlis ve ya lazimsiz mali sirkete/quruma qaytarmaq ve stokdan silmek |
| Istifadeci rolu | Anbardar sorğu yaradir; Manager/Admin tesdiq/imtina edir |
| Baslangic noqtesi | Returns/approvals gorunusu |
| Teleb olunan melumat | Counterparty, status/sened tipi, item setirleri, miqdar, olcu, qiymet, serial/MAC, qeyd, tehvil veren/alan |
| Route/fayl | `inventory-operations-lib.php:287-404`, `views/inventory/approvals.php` |
| API/service | Return request, approve, reject service |
| DB | `inventory_asset_returns`, stock movements/balances, documents, audit |
| Xarici inteqrasiya | Maliyye counterparties ile canonical secim hedeflenir |
| Audit qeydi | Sorğu, tesdiq/imtina, sebeb ve sened nomresi yazilir |
| Faktiki test | `inventory-return-approval` kecib; cari DB-de 1 asset return var |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Cap edilmis qaytarma senedinin tam business formati ve counterparty master-data elaqesi |
| Tekrar funksiya | Approvals ayrica tab, returns ile eyni workflow-un status novbesidir |
| Tehlukesizlik riski | Yaradan oz sorğusunu tesdiq etmemelidir; stock deduction yalniz tesdiqde olmalidir |
| Qerar | Saxla ve maker-checker + cap senedi ile tamamla |

### 3.8 Stok duzelisi, temir/RMA ve sened capi

| Sahe | Netice |
|---|---|
| Is axini | Sebebli qaliq duzelisi, repair/RMA statusu ve movement senedini cap etmek |
| Istifadeci rolu | Anbardar/Admin; kritik silme Manager/Admin tesdiqi |
| Baslangic noqtesi | `movements` adjustment, `repair-rma`, document action |
| Teleb olunan melumat | Item/asset, delta/status, sebeb, service company, qiymet ve sened tipi |
| Route/fayl | `inventory-operations-lib.php:103-132,405-406`, `views/inventory/repair-rma.php` |
| API/service | Adjustment ve document read service; repair emeliyyati natamamdir |
| DB | Movements, assets, documents, audit |
| Xarici inteqrasiya | Xarici servis/supplier yoxdur |
| Audit qeydi | Adjustment testde jurnal olunur; repair transition auditi tam yoxlanilmayib |
| Faktiki test | Catalog-adjustment ve document-print testleri kecib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Repair/RMA create/send/receive/close service zenciri ve inspection action-lari |
| Tekrar funksiya | Ayrica repair sehifesi movcuddur, amma tam backend workflow deyil |
| Tehlukesizlik riski | Birbasa adjustment tesdiqsiz stok silinmesine cevrile biler |
| Qerar | Adjustment saxla amma limit/maker-checker et; Repair/RMA-ni duzeltmeden islek sayma |

## 4. Esas duyme ve action xeritəsi

| Duyme/action | Frontend/dispatcher | API/service | DB/xarici tesir | Status |
|---|---|---|---|---|
| Yeni mal yarat | Catalog modal | Catalog create service | `inventory_stock_items`, audit | Tesdiqlenib smoke |
| Mali yenile | Catalog edit modal | Catalog update service | Stock item, audit | Tesdiqlenib smoke |
| Deaktiv et | Catalog row action | Deactivate service | Item status, audit | Tesdiqlenib; hard delete deyil |
| Qaliq duzelisi | Movements adjustment form | Adjustment service | Stock movement/balance/audit | Tesdiqlenib; approval limit lazimdir |
| Medaxil et | Movements intake form | Intake service | Movement, balance, document, serial/assets, audit | Tesdiqlenib |
| Transfer et | Movements transfer form | Location transfer service | Menbe/hedef movement/balance/document/audit | Tesdiqlenib |
| Emekdasa tehvil et | Handover form | Handover create service | Custody, movement, document, audit | Tesdiqlenib |
| Tehvili tesdiqle | Employee handover action | Confirm service | Custody status/audit | Tesdiqlenib; actor ayrimi qorunmalidir |
| Musteride istifade et | Technical/customer action | Customer consume service | Employee stock azalir, customer asset yaranir | Tesdiqlenib E2E smoke |
| Emekdasdan qaytar | Return form | Employee return service | Custody, movement, asset status/audit | Qismen |
| Sirkete qaytarma sorğusu | Return form | External return request | `inventory_asset_returns`, pending document/audit | Tesdiqlenib smoke |
| Qaytarmani tesdiqle | Approval queue | Approve service | Stock deduction, return/document/audit | Tesdiqlenib smoke |
| Qaytarmadan imtina et | Approval queue | Reject service | Request status/audit | Tesdiqlenib smoke |
| Inspection neticesi | Returns-inspection view | Tam transition service tapilmayib | Natamam | Catismayan funksiya |
| Repair/RMA gonder/qebul/bagla | Repair-RMA view | Tam service zenciri tapilmayib | Natamam | Catismayan funksiya |
| Senedi cap et | Document action | Document read/render | Read-only document | Tesdiqlenib smoke |

## 5. DB snapshot

| Cedvel | Say |
|---|---:|
| `inventory_stock_movements` | 260 |
| `inventory_stock_items` | 230 |
| `inventory_assets` | 111 |
| `inventory_documents` | 115 |
| `inventory_asset_returns` | 1 |

## 6. Funksional audit matrisi

| Is axini | FE | Duyme | Service | DB R/W | Real data | Mock yoxdur | Rol | Audit | State | Mobil | Tekrar | E2E |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Kataloq | Var | Var | Var | R/W | Beli | Beli | Var | Var | Qismen | Qismen | Var | Tesdiqlenib |
| Medaxil | Var | Var | Var | R/W | Beli | Beli | Var | Var | Qismen | Qismen | Yox | Tesdiqlenib |
| Transfer | Var | Var | Var | R/W | Beli | Beli | Var | Var | Qismen | Qismen | Az | Tesdiqlenib |
| Emekdasa tehvil | Var | Var | Var | R/W | Beli | Beli | Var | Var | Qismen | Qismen | Var | Tesdiqlenib |
| Musteride istifade | Qismen | Var | Var | R/W | Beli | Beli | Var | Var | Qismen | Qismen | Var | Tesdiqlenib |
| Emekdas qaytarmasi | Var | Qismen | Var | R/W | Beli | Beli | Var | Var | Qismen | Qismen | Var | Qismen |
| Xarici qaytarma | Var | Var | Var | R/W | Beli | Beli | Var | Var | Qismen | Qismen | Az | Tesdiqlenib |
| Adjustment | Var | Var | Var | R/W | Beli | Beli | Var | Var | Qismen | Qismen | Az | Tesdiqlenib |
| Repair/RMA | Var | Qismen | Natamam | Qismen | Qismen | Beli | Qismen | Qismen | Qismen | Qismen | Var | Xeyr |
| Sened capi | Var | Var | Var | R | Beli | Beli | Var | Var | Qismen | Qismen | Az | Tesdiqlenib |

## 7. Qebul meyarlari ve rollback

- Medaxil, transfer, tehvil, musteri consume ve qaytarma eyni item/location ledger-i isledir.
- Heç bir stok emeli menfi qaliq, ikiqat serial/MAC ve ya tesdiqsiz hard delete yaratmir.
- Tehvil veren ve alan ayri actor kimi saxlanir; sened nomresi sistem terefinden unik verilir.
- Xarici qaytarma yalniz tesdiqden sonra stokdan silinir ve cap senedi yaradir.
- Repair/RMA create-send-receive-close E2E olmadan modul hazir sayilmir.
- Siyahilar default 50 setir ve pagination; filterler avtomatik, full reload-suz isleyir.
- Migration additive; movement ledger geri silinmir; yeni UI feature flag ile rollback edilir.
