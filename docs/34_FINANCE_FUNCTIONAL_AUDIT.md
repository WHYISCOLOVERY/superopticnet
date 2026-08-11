# Maliyye funksional auditi

Tarix: 2026-07-19  
Status: Kod deyisikliyinden evvel audit

## 1. Yoxlama ehatəsi

Audit `operations-center.php?section=finance`, `finance-module-lib.php`, `finance-operations-lib.php`, `finance-bonus-lib.php`, `mgs-integration-lib.php` ve `views/finance/*` uzre aparilib. Real CRUD smoke skriptleri live DB-ye yazdigi ucun icra edilmeyib; lint, read-only SQL ve statik service/permission analizi aparilib.

## 2. Kritik tesdiqlenmis tapintilar

1. **Kritik:** `app_finance_build_context()` sehife renderinde schema ve default bonus seed funksiyalarini cagirir (`finance-module-lib.php:593`, `finance-bonus-lib.php:200`). GET/read DB-ni deyismemelidir.
2. **Kritik:** vahid ledger yoxdur. Manual gelir ve bonus `finance_entries`-e, adi xerc `mgs_operation_actions.payload_json`-a yazilir (`operations-center.php:894`, `config.php:3152`).
3. **Yuksek:** odenmis bonus dovru yeniden hesablana bilir, amma `BONUS-<period>` ledger meblegi yenilenmir (`finance-bonus-lib.php:648,694`).
4. **Yuksek:** e-qaime importunda evvel borc, sonra import setri yazilir; ikinci addim ugursuz olsa borc qalir (`mgs-integration-lib.php:2871,2931`).
5. **Yuksek:** amortizasiya ve inventardan silinme vahid transaction/maker-checker ile qorunmur (`operations-center.php:906`, `mgs-integration-lib.php:2355`).
6. **Yuksek:** yoxlanilan Finance/bonus cedvellerinde FK sayi 0-dır.
7. **Yuksek:** Statistika `Hamisi` yalniz dord dataset gosterir; borc/counterparty secilmis tarixe tabe deyil (`views/finance/_statistics-results.php:5`, `finance-module-lib.php:459`).
8. **Yuksek data keyfiyyeti:** `billing_payments`-de 2099 tarixli 12 odenis, cemi 300 AZN var.
9. **Orta:** write duymeleri sales/read-only istifadeciye gorune biler; bəzi action-lar merkez permission map-de yoxdur, daxili service sonradan bloklayir.

## 3. Is axinlari

### 3.1 Umumi baxıs

| Sahe | Netice |
|---|---|
| Is axini | Gelir, xerc, borc, invoice ve payment KPI-larini gosterme |
| Istifadeci rolu | Finance/Admin; Sales read-only |
| Baslangic noqtesi | `section=finance&finance_tab=overview` |
| Teleb olunan melumat | Optional tarix araligi |
| Route/fayl | `views/finance/overview.php:8+`, `finance-module-lib.php:536+` |
| API/service | Finance context builder |
| DB | `billing_payments`, `finance_entries`, `finance_debt_ledger`, `finance_e_invoice_imports`, `mgs_operation_actions` |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Read auditi yoxdur |
| Faktiki test | Read-only query aparilib; sehife renderi GET-de schema/seed yazdigi ucun live render edilmemisdir |
| Mock/demo | Default bonus seed side effect-dir, mock kimi gosterilmemelidir |
| Catismayan funksiya | Side-effect-free read; canonical KPI definitions; real due-date aging |
| Tekrar funksiya | Statistics ve cash-bank KPI-lari |
| Tehlukesizlik riski | GET DB deyisdirir; overdue yalniz `open` statusuna baxir, `partial` itir |
| Qerar | Duzelt; migration/seed-i deploy-e cixar |

### 3.2 E-qaimeler

| Sahe | Netice |
|---|---|
| Is axini | E-qaime setrlerini import edib payable/receivable borc yaratmaq |
| Istifadeci rolu | Finance/Admin import; Sales read |
| Baslangic noqtesi | `finance_tab=invoices` |
| Teleb olunan melumat | CSV metni/file, sened no, teref, mebleg, son tarix |
| Route/fayl | `views/finance/invoices.php:36+`, `operations-center.php:962+` |
| API/service | `app_mgs_import_finance_e_invoice_rows()` |
| DB | `finance_debt_ledger`, `finance_e_invoice_imports`, audit |
| Xarici inteqrasiya | Real e-qaime provider/API yoxdur |
| Audit qeydi | Import summary operation log-a yazilir |
| Faktiki test | Live cedvellerde 0 qeyd; write E2E icra edilmeyib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | File upload validation, unique sened, idempotency, failed/skipped row ve transaction |
| Tekrar funksiya | Export duymesi qaimeni yox, umumi borclari cixarir |
| Tehlukesizlik riski | Qismen import DB-ni ziddiyyetli qoyur |
| Qerar | Duzelt; atomik import + row result ledger |

### 3.3 Billing odenislerinin Maliyye gorunusu

| Sahe | Netice |
|---|---|
| Is axini | Billing odenislerini metod/tarix/axtaris ile oxumaq |
| Istifadeci rolu | Finance/Admin/Sales read scope |
| Baslangic noqtesi | `finance_tab=payments` |
| Teleb olunan melumat | Search, method, date, page |
| Route/fayl | `finance-module-lib.php:149+`, payments view |
| API/service | Read query |
| DB | `billing_payments LEFT JOIN portal_accounts` |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Read/export auditi yoxdur |
| Faktiki test | 132,481 qeyd, 2,421,554.41 AZN; tarix 2022-02-03 - 2099-01-05 |
| Mock/demo | Real DB; 2099 data anomaliyadir |
| Catismayan funksiya | Data quality flag, shared export service |
| Tekrar funksiya | `billing-payments.php` ile eynidir |
| Tehlukesizlik riski | Future tarix KPI-lari tehrif edir |
| Qerar | Saxla, amma Billing ile ortaq canonical read-service/component |

### 3.4 Gelir yaratmaq ve legv etmek

| Sahe | Netice |
|---|---|
| Is axini | Manual gelir qeydini yaratmaq, counterparty ile baglamaq, sebebli void etmek |
| Istifadeci rolu | Finance/Admin write |
| Baslangic noqtesi | `finance_tab=income` |
| Teleb olunan melumat | Basliq, kateqoriya, mebleg, valyuta, tarix, filial, teref, menbe, reference, qeyd |
| Route/fayl | `views/finance/income.php:40+`, `operations-center.php:489+` |
| API/service | `finance-operations-lib.php:38+` create/void/link service |
| DB | `finance_entries`, `finance_counterparties`, audit |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Transaction ve before/after qismen var |
| Faktiki test | Live gelir qeydi 0; write E2E icra edilmeyib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Currency conversion/rate snapshot, approval ve correction modeli |
| Tekrar funksiya | Billing income statistikasi ile semantik overlap |
| Tehlukesizlik riski | USD/EUR qebul edilir, statistika yalniz AZN sayir; label iki valyuta gosterə biler |
| Qerar | Saxla; vahid ledger/currency kontrakti ile duzelt |

### 3.5 Sirketler ve qarsi terefler

| Sahe | Netice |
|---|---|
| Is axini | Supplier/customer/person master-data yaratmaq ve borc/gelirle baglamaq |
| Istifadeci rolu | Finance/Admin write; Sales read |
| Baslangic noqtesi | `finance_tab=companies` |
| Teleb olunan melumat | Ad, nov, VOEN/FIN, telefon, email, unvan, status |
| Route/fayl | `views/finance/companies.php:17+`, `finance-operations-lib.php:77+` |
| API/service | Counterparty create/update/link service |
| DB | `finance_counterparties`, `finance_debt_ledger`, audit |
| Xarici inteqrasiya | Inventory supplier/return target hedef elaqesi |
| Audit qeydi | Create var; update before/after/reason guclendirilmelidir |
| Faktiki test | Live counterparty 0 |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Unique normalized VOEN/FIN, duplicate merge, soft deactivate |
| Tekrar funksiya | Inventory company/supplier inputlari ile tekrar |
| Tehlukesizlik riski | DB unique/FK olmadan parallel duplicate |
| Qerar | Saxla ve sirket master-data menbeyi et |

### 3.6 Xercler

| Sahe | Netice |
|---|---|
| Is axini | Xerc yaratmaq ve filial/emekdas/counterparty ile elaqelendirmek |
| Istifadeci rolu | Finance/Admin write; read-only rola duyme gizlenmelidir |
| Baslangic noqtesi | `finance_tab=expenses` |
| Teleb olunan melumat | Basliq, kateqoriya, mebleg, valyuta, tarix, filial, emekdas/teref, sened, qeyd |
| Route/fayl | `views/finance/expenses.php:29+`, `config.php:3152+` |
| API/service | `/api/service/finance/entries`; MGS fallback |
| DB | Hazirda fallback `mgs_operation_actions.payload_json`; hedef `finance_entries` |
| Xarici inteqrasiya | Lokal MGS port 3000; audit vaxti elcatmaz olub |
| Audit qeydi | Biznes xerc qeydi audit cedveline qarisib |
| Faktiki test | Live xerc 0; MGS reachability false |
| Mock/demo | Fallback real write edir, mock deyil, amma yanlis storage-dir |
| Catismayan funksiya | Edit/void/approval/reference/currency ve selected employee persistence |
| Tekrar funksiya | Finance ledger ve operation audit iki menbedir |
| Tehlukesizlik riski | Audit retention/schema biznes ledger telebini odemir |
| Qerar | Vahid `finance_entries` ledger-ine birlesdir |

### 3.7 Kassa ve bank

| Sahe | Netice |
|---|---|
| Is axini | Kassa/bank kanallari ve hereketleri idare etmek |
| Istifadeci rolu | Finance/Admin |
| Baslangic noqtesi | `finance_tab=cash-bank` |
| Teleb olunan melumat | Hazirda yalniz tarix/report; hedef account, transfer, statement |
| Route/fayl | `views/finance/cash-bank.php:5+`, `finance-module-lib.php:471+` |
| API/service | Hazirda payment-method aggregate |
| DB | Yalniz `billing_payments` |
| Xarici inteqrasiya | Bank statement/provider yoxdur |
| Audit qeydi | Yoxdur |
| Faktiki test | Read query tesdiqlenib; operation yoxdur |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Kassa hesabi, qaliq, transfer, bank import ve reconciliation |
| Tekrar funksiya | Odenisler/statistika |
| Tehlukesizlik riski | Sehife adi real kassa ledger-i varmis kimi gorunur |
| Qerar | Cari gorunusu Odenislere birlesdir; real cash/bank ledger ayrica qur |

### 3.8 Borc ve uzlasma

| Sahe | Netice |
|---|---|
| Is axini | Payable/receivable borc, qismen odenis ve uzlasma saxlamaq |
| Istifadeci rolu | Finance/Admin write |
| Baslangic noqtesi | `finance_tab=reconciliation` |
| Teleb olunan melumat | Counterparty, istiqamet, mebleg, sened, due date; payment, method, reference |
| Route/fayl | `views/finance/reconciliation.php:28+`, `operations-center.php:925+` |
| API/service | Debt create ve payment service `mgs-integration-lib.php:2676+` |
| DB | `finance_debt_ledger`, `finance_debt_payments`, audit |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Audit commit-den sonra yazilir; atomic audit deyil |
| Faktiki test | Live debt/payment 0; service row lock kodu tesdiqlenib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Reversal, matching, aging, statement, mecburi counterparty |
| Tekrar funksiya | Invoice import borc yaradir, amma atomic elaqe/FK yoxdur |
| Tehlukesizlik riski | Artıq odenis sessiz kesilir; FK yoxdur |
| Qerar | Saxla ve canonical debt ledger kimi duzelt |

### 3.9 Esas vesait ve amortizasiya

| Sahe | Netice |
|---|---|
| Is axini | Inventory asset-i faizle amortizasiya etmek ve icazeli halda il sonunda silmek |
| Istifadeci rolu | Finance hesablayir; silinme Manager/Super Admin tesdiqi |
| Baslangic noqtesi | `finance_tab=assets` |
| Teleb olunan melumat | Asset/stock, ilkin qiymet, faiz/useful life, il, silinme limiti, sebeb |
| Route/fayl | `views/finance/assets.php:38+`, `operations-center.php:906+`, `mgs-integration-lib.php:2355+` |
| API/service | Depreciation/writeoff service |
| DB | `inventory_assets`, `inventory_stock_items`, `finance_asset_depreciations`, audit |
| Xarici inteqrasiya | Inventory |
| Audit qeydi | Qismendir, transaction ile eyni commit deyil |
| Faktiki test | 111 asset, 230 stock item oxunub; amortizasiya 0; write test edilmeyib |
| Mock/demo | Yoxdur |
| Catismayan funksiya | Approval, useful life, accumulated depreciation, reversal, asset-only print |
| Tekrar funksiya | Inventory asset statusu ile iki modul yazir |
| Tehlukesizlik riski | Istifadeci qiymet/faiz/limit daxil edib aktivi derhal sile bilir |
| Qerar | Duzelt; silinmeni maker-checker ve vahid transaction olmadan acma |

### 3.10 Emekdas bonuslari ve odenise hazirliq

| Sahe | Netice |
|---|---|
| Is axini | Base salary, yeni qosuntu, aktiv gun, keyfiyyet ve correction ile bonus hesablamaq/tesdiq etmek |
| Istifadeci rolu | Emekdas oz neticesi; Finance/Admin hamisi; Super Admin qayda ve payout |
| Baslangic noqtesi | `finance_tab=employee-bonuses` |
| Teleb olunan melumat | Dovr, emekdas, rule/tier/bracket, correction sebebi, approval |
| Route/fayl | `finance-bonus-lib.php:540+,648+,694+` ve bonus views |
| API/service | Calculate, adjust, approve, payout service |
| DB | Bonus period/summary/item/adjustment/assignment/notification/rule/audit; payout `finance_entries` |
| Xarici inteqrasiya | HR employee, Billing customers/payments/tickets |
| Audit qeydi | 138 bonus audit; 88 test/smoke metnli |
| Faktiki test | Live period/summary/item 0, assignment 12; hesablamanin production E2E-si yoxdur |
| Mock/demo | Aktiv gun 30 gun ferzi, ticket regex keyfiyyeti ve test auditleri var |
| Catismayan funksiya | Source attribution, idempotent recalc/payout, paid-period lock/reversal |
| Tekrar funksiya | HR payroll/performance ile overlap |
| Tehlukesizlik riski | Recalc paid ledger meblegini yenilemir; eyni musteri bir nece emekdasa sayila biler |
| Qerar | Saxla, amma go-live-den evvel formula ve payout tam duzelsin |

### 3.11 Maliyye statistikasi

| Sahe | Netice |
|---|---|
| Is axini | Gelir/xerc/borc/counterparty/payment chartlarini AJAX ile yenilemek |
| Istifadeci rolu | Finance modulu read scope |
| Baslangic noqtesi | `finance_tab=statistics` |
| Teleb olunan melumat | Report tipi, tarix araligi |
| Route/fayl | `views/finance/statistics.php:13+`, `operations-center.php:3903+` |
| API/service | Finance statistics context/partial render |
| DB | Parcalanmis Finance, Billing ve operation-action menbeleri |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Read/export auditi yoxdur |
| Faktiki test | AJAX full page reload etmir; query code audit olunub |
| Mock/demo | Sifir cedvelleri realdir, amma bonus seed/read side effect var |
| Catismayan funksiya | Hamisi butun datasetler, borc/counterparty date filter, visible error |
| Tekrar funksiya | Overview, cash-bank ve Billing analytics |
| Tehlukesizlik riski | Parcalanmis ledger chartlari ziddiyyetli edir |
| Qerar | Saxla; vahid ledger/read-model uzre yeniden qur |

## 4. Esas duyme ve action xeritəsi

| Duyme/action | Frontend/dispatcher | API/service | DB/xarici tesir | Status |
|---|---|---|---|---|
| Qaimeleri import et | `views/finance/invoices.php` -> `operations-center.php` | `app_mgs_import_finance_e_invoice_rows()` | Debt + invoice import + audit | Yuksek risk, qeyri-atomik |
| Qaime/borc export | Invoice/reconciliation toolbar | Export context | Hazirda umumi debt read | Yanlis scope/qismen |
| Gelir elave et | Income modal -> dispatcher | `finance-operations-lib.php` create | `finance_entries`, counterparty, audit | Qismen |
| Geliri legv et | Income row action | Void service | `finance_entries` status/correction, audit | Qismen; permission map natamam |
| Qarsi terefe bagla | Income action | Link service | Finance entry/counterparty relation | Qismen; permission map natamam |
| Sirket yarat/yenile | Companies modal | Counterparty service | `finance_counterparties`, audit | Qismen; unique/FK yoxdur |
| Xerc elave et | Expenses modal | `/api/service/finance/entries`, fallback | Hazirda `mgs_operation_actions.payload_json` | Kritik yanlis storage |
| Borc yarat | Reconciliation modal | Debt create service | `finance_debt_ledger`, audit | Qismen |
| Borca odenis et | Debt row/modal | Debt payment service | `finance_debt_payments`, debt status, audit | Qismen; reversal yoxdur |
| Amortizasiya hesabla/saxla | Assets modal | Depreciation service | `finance_asset_depreciations` | Qismen |
| Aktivi sil | Assets action | Writeoff service | `inventory_assets`, depreciation/audit | Yuksek risk; tesdiq/transaction natamam |
| Bonus hesabla | Bonus period action | Bonus calculation service | Period/summary/items/audit | Qismen/mock ferzler |
| Bonus duzelisi saxla | Bonus adjustment action | Adjustment service | Adjustment/summary/audit | Qismen |
| Odenise hazirla/tesdiqle | Bonus approval/payout | Payout service | `finance_entries`, bonus period/audit | Yuksek risk; recalc inconsistency |
| Statistika Uygula | AJAX form | Finance statistics context | Read-only, parcalanmis menbeler | Qismen; Hamisi/date sehvleri |
| CSV/Cap | Modul toolbar/`window.print()` | Export/print helper yoxdur ve ya page print | Read-only | Qismen/yanlis kontent |

## 5. DB snapshot

| Cedvel | Say |
|---|---:|
| `finance_entries` | 0 |
| `finance_counterparties` | 0 |
| `finance_debt_ledger` | 0 |
| `finance_debt_payments` | 0 |
| `finance_e_invoice_imports` | 0 |
| `finance_asset_depreciations` | 0 |
| `billing_payments` | 132,481 |
| `inventory_assets` | 111 |
| `inventory_stock_items` | 230 |

`information_schema.KEY_COLUMN_USAGE` yoxlamasinda ehatə olunan Finance/bonus cedvelleri ucun FK sayi 0 alınıb.

## 6. Funksional audit matrisi

| Is axini | FE | Duyme | Service | DB R/W | Real data | Mock yoxdur | Rol | Audit | State | Mobil | Tekrar | E2E |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Overview | Var | Var | Var | R + side effect | Qismen | Xeyr | Var | N/A | Qismen | Xeyr | Var | Xeyr |
| E-qaime | Var | Var | Var | R/W | Xeyr | Beli | Var | Qismen | Qismen | Xeyr | Var | Xeyr |
| Payments read | Var | Var | Var | R | Beli | Beli | Var | N/A | Qismen | Xeyr | Var | Qismen |
| Income | Var | Var | Var | R/W | Xeyr | Beli | Var | Var | Qismen | Xeyr | Az | Xeyr |
| Counterparties | Var | Var | Var | R/W | Xeyr | Beli | Var | Qismen | Qismen | Xeyr | Var | Xeyr |
| Expenses | Var | Var | Parcalanib | R/W | Xeyr | Beli | Qismen | Yanlis storage | Qismen | Xeyr | Var | Xeyr |
| Cash-bank | Var | Qismen | Read aggregate | R | Qismen | Beli | Var | N/A | Qismen | Xeyr | Var | Xeyr |
| Debt/reconciliation | Var | Var | Var | R/W | Xeyr | Beli | Var | Qismen | Qismen | Xeyr | Var | Xeyr |
| Asset/depreciation | Var | Var | Var | R/W | Qismen | Beli | Var | Qismen | Qismen | Xeyr | Var | Xeyr |
| Bonus/payout | Var | Var | Var | R/W | Qismen | Xeyr | Var | Var | Qismen | Xeyr | Var | Xeyr |
| Statistics | Var | Var | Var | R | Qismen | Qismen | Var | N/A | Qismen | Xeyr | Var | Qismen |

## 7. Go-live qebul meyarlari

- GET/render hec bir DDL, seed ve ya biznes write etmir.
- Gelir, xerc, bonus payout, debt payment ve correction vahid `finance_entries`/ledger kontraktina tabe olur.
- E-qaime importu transaction, idempotency ve row-level result saxlayir.
- Borc/odenis, invoice/debt, depreciation/asset elaqeleri FK ve ya eyni seviyyeli DB constraint ile qorunur.
- Aktiv silinme iki terefli tesdiq, sebeb, qiymet limiti, print document ve reversal teleb edir.
- Paid bonus dovru sessiz recalc olunmur; correction/reversal ledger-i yaradir.
- Butun listler 50 default pagination, auto-filter, mobil label ve visible error state ile isleyir.
- Migration additive; ledger qeydi silinmir; rollback reversal/correction ve feature flag ile aparilir.
