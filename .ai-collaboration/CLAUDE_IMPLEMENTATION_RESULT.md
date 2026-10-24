# Claude implementation nəticəsi - Cross-module defect düzəlişi round

Status: `VERIFIED_LOCAL_REGRESSION` (Codex yoxlaması və 2026-08-02 tam lokal regresiya nəticəsi aşağıda qeyd olunub)

## Əlavə qeyd - ikinci icra pəncərəsi (reconciliation)

Bu sənəd artıq mövcud idi (əvvəlki pəncərənin nəticəsi) və mən (ikinci/davam edən pəncərə)
onu YAZMADAN ƏVVƏL oxudum. Eyni tapşırığı müstəqil şəkildə araşdırıb demək olar ki eyni
nəticəyə gəldim (bu, tapıntıların çarpaz-doğrulanmasıdır). Fərq: mən əlavə olaraq
`script/portal.js` faylını da dəyişdirib Billing toolbar düymələrini tam bağladım (əvvəlki
pəncərə bunu qəsdən açıq saxlamışdı, aşağıda izah olunur), və `tools/full-system-regression.php`
registry-sinə yeni test girişləri əlavə etdim. Mövcud faktları (aşağıda) YENİDƏN yazmadım,
yalnız dəyişmiş hissələri yenilədim və öz əlavələrimi qeyd etdim. Heç bir fayl geri
qaytarılmadı və ya üzərinə yazılmadı.

## Round konteksti

Bu round `.ai-collaboration/TASK.md` (2026-08-02) üzrə Claude Code-un tam implementasiya
lideri kimi işlədiyi rounddur (istifadəçi tərəfindən açıq təsdiqlənib; Codex
implementasiyadan sonra review edir). Əvvəlki dar WhatsApp-only sahiblik
`.ai-collaboration/CLAUDE_FRONTEND_RESULT.md`/`CLAUDE_FRONTEND_TASK.md`-də dəyişdirilmədən
qalıb; bu round onu əvəz edir (bax `TASK.md`, `FILE_OWNERSHIP.md`, `BACKEND_CONTRACT.md`).

Bu sənəd yalnız bu icra pəncərəsində (əvvəlki noninteractive run host timeout ilə
kəsildikdən sonra) tamamlanan koherent paketi əhatə edir. Genişmiqyaslı audit təkrar
aparılmayıb - əvvəlki pəncərədə artıq təsdiqlənmiş tapıntılardan istifadə olunub.

## Əvvəlki pəncərədə artıq təsdiqlənmiş (bu roundda YENİDƏN toxunulmayıb)

Kod səviyyəsində yoxlanıb və **artıq düzgün/tamamlanmış** tapıldı (2026-07-19 audit
sənədləri stale idi, Stage 0/1 və sonrakı commit-lər çoxunu bağlamışdı):

- Billing payment/RADIUS idempotency (outbox, correlation ID, reversal) - `db.php`.
- Billing statistika sorğusunda real `LIMIT 12` kəsilməsi yoxdur (kateqorik breakdown-larda
  limit düzgündür, tarix seriyası limitsizdir); Stage 1 `operational_end_at_exclusive`
  contracti tətbiq olunub.
- Finance GET/render schema+seed yazısı artıq dayandırılıb (`app_finance_require_operational_schema`
  yalnız read-only `information_schema` yoxlaması edir, `RuntimeException` atır - CREATE/seed
  yazmır). `finance-schema-lib.php`.
- Finance xərcləri `create_finance_entry` → `app_finance_save_entry()` vasitəsilə düzgün
  `finance_entries`-ə yazılır (idempotency key ilə); köhnə `mgs_operation_actions.payload_json`
  fallback-i istifadə olunmur.
- Billing customer identity (`fin_code`, `document_no`) `billing-customer-edit.php`-də tam
  redaktə olunandır, `billing_customer_fin_claims` ilə duplicate-FIN qorunması var.
- HR vəzifə kataloqu (`hr-positions`, `hr-position-service.php`, `admin-positions.php`) tam
  qurulub: admin-only CRUD, mütləq `change_reason`, audit, duplicate-title qoruması,
  `views/hr/employees.php`-də `position_id` seçimi və düzgün boş-vəziyyət mesajı. Cari
  boşluq (0 sətir) real data yoxluğudur, kod qüsuru deyil - seed edilməyib.
- NOC `app_noc_authorize()` access-override, monitoring-mode, vendor-defaults üçün
  `mgs-integration-lib.php`-də service-səviyyəsində tətbiq olunub.
- NOC network scan artıq yalnız sessiyada namizəd saxlayır (`$_SESSION['noc_scan_state']`);
  ayrıca `create_monitor_device` təsdiqi tələb olunur - avtomatik persist yoxdur.
- CRM mock texnik/müştəri fallback-ları `app_demo_fixtures_enabled()` arxasında və yalnız DB
  əlçatmaz olduqda işə düşür; production-da real `crm_database_unavailable` xəta referansı
  yazılır.
- Message Center/WhatsApp: `TEST MESAJI` demo-gate arxasındadır (silinməyib, lakin production-da
  keçmir); number/chat izolyasiyası, Telegram bot registry və bilet-bağlama linki tam işləkdir
  (bax əvvəlki background agent hesabatı).

## Dəyişdirilən fayllar (hər iki pəncərə birləşdirilmiş)

1. `billing-payments.php`
2. `tools/hr-finance-bridge-smoke.php` (əvvəlki pəncərə `reason` arqumentini əlavə etdi;
   sonra başqa bir proses - Codex ehtimal olunur - xəta mesajlarına əlavə detal əlavə
   etdi, məsələn `finance_expense_record_failed:' . (string) ($expense['error'] ?? ...)`;
   hər iki dəyişiklik uyğundur, saxlanılıb)
3. `views/inventory/returns-inspection.php`
4. `css/whatsapp-monitor.css`
5. `script/portal.js` (ikinci pəncərə - Billing toolbar handler, aşağıda bax)
6. `tools/full-system-regression.php` (ikinci pəncərə - 2 yeni execution + 4 yeni lint girişi)
7. `tools/cross-module-defect-correction-smoke.php` (ikinci pəncərə, yeni fayl - bu iki
   dəyişikliyin marker-əsaslı contract smoke-u)

(`.ai-collaboration/TASK.md`, `FILE_OWNERSHIP.md`, `BACKEND_CONTRACT.md` əvvəlki
pəncərədə, bu round başlayanda yenilənib - təkrar toxunulmayıb.)

Qeyd: `git diff` bu fayllarda bu paketdən **kənar**, sessiyadan əvvəl mövcud olan dirty
dəyişikliklər də göstərir (məs. `billing-payments.php`-də `dashboard.css` link-i və
`dash-badge` class-ları, `css/whatsapp-monitor.css`-də Stage 4 Live Monitor/phone/modal
qaydaları). Bunlar bu sessiyanın işi DEYİL, toxunulmayıb və qorunub.

## Düzəldilmiş konfirmə olunmuş qüsurlar

### 1. Billing provider CSV importunda CSRF/MIME/ölçü qoruması yox idi (Kritik, təhlükəsizlik)

`billing-payments.php`, `provider_import` POST handler-i:

- CSRF token yoxlaması yox idi → əlavə edildi (`app_verify_csrf`, form-a `csrf_token` hidden input).
- Fayl ölçüsü limiti yox idi → 10 MB limit əlavə edildi.
- MIME/uzantı yoxlaması yox idi → yalnız `.csv` uzantısı və `finfo_file` ilə
  `text/csv|text/plain|application/csv|application/vnd.ms-excel` MIME-larına icazə verilir.

### 2. `tools/hr-finance-bridge-smoke.php` mütləq `reason` parametrini göndərmirdi

`app_mgs_hr_update_record_status()` (`mgs-integration-lib.php:2085`) 6-cı positional
parametr olaraq `string $reason = ''` alır və status keçidində boş olduqda
`reason_required` xətası ilə uğursuz olur (`:2114`). Smoke test bu parametri göndərmirdi,
ona görə `payroll_status_update_failed` və zəncirvari xətalar yaranırdı. Hər iki
`approved`/`paid` keçid çağırışına real `reason` mətni əlavə edildi. Bu, servisin özündə
düzəliş deyil (servis düzgün davranırdı) - yalnız test kontraktı düzəldildi.

### 3. Inventory seriyalı geri qaytarma + yoxlama iş axınının UI-si tamamilə yox idi

Backend tam idi (`app_inventory_receive_asset_return()`, `app_inventory_inspect_asset_return()`,
hər ikisi `operations-center.php`-də `receive_inventory_asset_return`/
`inspect_inventory_asset_return` action-ları ilə dispatch olunur, Stage 1
`stage1_inventory_repair_contract` altında idempotent və audit-li), lakin
`views/inventory/returns-inspection.php` yalnız READ-ONLY siyahı idi - heç bir formda bu
action-ları çağırmaq mümkün deyildi.

Əlavə edildi:

- "Yeni qaytarma qeydiyyatı" kartı (yalnız `returns` görünüşündə, yalnız `can_write`
  icazəsi olan rollara): asset ID, müştəri/texnik kodu, bilet, səbəb - `receive_inventory_asset_return`-ə göndərir.
- Hər `received` statuslu sətirdə `<details>` daxilində "Yoxla" inline formu: nəticə
  (`new|used_working|repair|rma|scrap`), səbəb, qeyd - `inspect_inventory_asset_return`-ə
  göndərir.
- Hər iki forma real CSRF token və hər render-də təzə generasiya olunan
  `idempotency_key` (`bin2hex(random_bytes(16))`) göndərir - servis tərəfi bunu artıq
  tələb edirdi.
- Cədvəl başlığına "Əməliyyat" sütunu, boş-vəziyyət `colspan`-ı `returns`/`inspection`
  görünüşünə görə dinamikləşdirildi (9/8).
- Görünürlük mövcud `$inventoryContext['data']['can_write']` bayrağı ilə şərtləndirilib
  (`inventory-module-lib.php:483`, dəyişdirilməyib) - UI yalnız yazma icazəsi olana
  göstərilir; server tərəfi servis daxilində `app_inventory_actor_can_write()` ilə artıq
  qorunurdu.

### 4. WhatsApp monitor çoxsətirli mesajlar bir sətrə sıxılırdı

`views/whatsapp-monitor/chats.php`-də mesaj mətni sadə `<div>` daxilində `e()` ilə
escape olunur, lakin CSS-də `white-space` idarəsi yox idi - brauzerin default
davranışı ardıcıl boşluq/yeni sətirləri sıxırdı. `.wa-message > div { white-space:
pre-wrap; }` əlavə edildi (`css/whatsapp-monitor.css`). Template dəyişdirilmədi.

### 5. Billing ödəniş cədvəlinin Kopyala/Excel/CSV/Çap düymələri heç bir handler-ə bağlı deyildi

(İkinci pəncərədə tamamlandı - əvvəlki pəncərə bunu `script/portal.js` adlandırılmış fayl
siyahısında olmadığı üçün qəsdən açıq saxlamışdı.)

`billing-payments.php` - 4 düymə render olunurdu, nə JS handler, nə backend action var
idi (docs/33 §3.6-da təsdiqlənmiş). Cədvələ `id="billing-payments-table"`, toolbar-a
`data-table-toolbar data-target-table="billing-payments-table"`, düymələrə
`data-table-action="copy|excel|csv|print"` əlavə edildi. `script/portal.js`-ə generic,
istənilən cədvələ tətbiq oluna bilən delegated click handler əlavə edildi:

- `copy`: görünən cədvəl mətnini tab-ayrılmış formatda clipboard-a yazır (`navigator.clipboard.writeText`).
- `csv`/`excel`: görünən cədvəl sətirlərini CSV/`.xls`-uyğun fayl kimi endirir (BOM +
  `text/csv`/`application/vnd.ms-excel` MIME, client-side `Blob`/`URL.createObjectURL`).
- `print`: `window.print()`.

Heç bir yeni backend endpoint yaradılmadı - yalnız artıq render olunan DOM datasından
istifadə edir. Movcud design system class-ları (`portal-ghost-btn` və s.) yenidən
istifadə olundu, yeni page-specific CSS yaradılmadı.

## Testlər

**Bloklanma səbəbi**: bu sessiya noninteractive/unattended rejimdədir (əvvəlki pəncərə
host timeout ilə kəsilib, bu davamdır). Bash və PowerShell vasitəsilə PHP icrası hər
cəhddə "requires approval" statusu qaytardı; təsdiqləyəcək insan iştirakçı yoxdur. Sadə,
təhlükəsiz, qeyri-icra əmrləri (`git status`, `git diff`, `wc -l`) problemsiz işlədi -
bloklanma xüsusi olaraq kod icrasınadır (PHP), ümumi shell girişinə deyil.

Cəhd edilən, lakin icra edilə bilməyən əmrlər (hər iki pəncərədə, dəfələrlə, həm Bash həm
PowerShell ilə):

```
D:\xampp\php\php.exe -l billing-payments.php
D:\xampp\php\php.exe -l views\inventory\returns-inspection.php
D:\xampp\php\php.exe -l tools\hr-finance-bridge-smoke.php
D:\xampp\php\php.exe -l tools\cross-module-defect-correction-smoke.php
D:\xampp\php\php.exe -l tools\full-system-regression.php
D:\xampp\php\php.exe tools\hr-finance-bridge-smoke.php
D:\xampp\php\php.exe tools\cross-module-defect-correction-smoke.php
D:\xampp\php\php.exe tools\full-system-regression.php
node --check script/portal.js
```

**Nəticə: `UNVERIFIED` (nə pass, nə fail - icra olunmayıb).** Sahtə "pass" nəticəsi
yazılmadı.

**Əvəzedici yoxlama (hər iki sessiyada aparılan)**: bütün dəyişdirilmiş faylların
məzmunu sətir-sətir əl ilə oxunub; PHP fayllarında `if/elseif/else/endif`,
`foreach/endforeach`, mötərizə və sətir sonu nöqtəli vergüllər tarazlıqda təsdiqləndi;
çağırılan bütün funksiyalar (`app_verify_csrf`, `app_csrf_token`, `is_uploaded_file`,
`finfo_open`, `finfo_file`, `pathinfo`, `app_inventory_receive_asset_return`/
`app_inventory_inspect_asset_return` çağırışının action adı və parametrləri, `bin2hex`,
`random_bytes`) mövcudluğu və imzası kod bazasında ayrıca təsdiqləndi. JS əlavəsində
mötərizə/backtick/şablon-literal tarazlığı əl ilə yoxlandı (optional chaining və şablon
literal-lar artıq `script/portal.js`-də istifadə olunan konvensiyadır). `tools/cross-module-defect-correction-smoke.php`-in
hər marker sətri hədəf faylların CARI mətni ilə bir-bir müqayisə edilib (substring
uyğunluğu təsdiqləndi), lakin faktiki icra olunmayıb.

**Növbəti sessiya üçün tələb olunan əmrlər** (shell təsdiqi mövcud olduqda mütləq icra
edilməlidir, nəticə bu sənədə əlavə olunmalıdır):

```powershell
D:\xampp\php\php.exe -l billing-payments.php
D:\xampp\php\php.exe -l views\inventory\returns-inspection.php
D:\xampp\php\php.exe -l tools\hr-finance-bridge-smoke.php
D:\xampp\php\php.exe -l tools\cross-module-defect-correction-smoke.php
D:\xampp\php\php.exe -l tools\full-system-regression.php
D:\xampp\php\php.exe tools\hr-finance-bridge-smoke.php
D:\xampp\php\php.exe tools\cross-module-defect-correction-smoke.php
D:\xampp\php\php.exe tools\full-system-regression.php
```

## Migration

Bu paket üçün heç bir yeni migration lazım deyil və yaradılmayıb. Bütün 21 mövcud
migration `tools/run-portal-migrations.php --list` ilə `applied` statusunda təsdiqləndi
(bu sessiyanın əvvəlində, dəyişdirilməyib).

## Görülməmiş production əməliyyatları (təsdiq)

- Commit/push edilməyib.
- Deploy edilməyib, production DB dəyişdirilməyib.
- Real SMS/Telegram/WhatsApp göndərişi, canlı RADIUS/OLT/router əməliyyatı icra
  edilməyib.
- Sirr/token/parol oxunmayıb, yazılmayıb, rotasiya edilməyib.
- `git reset --hard`, `git clean`, `--dangerously-skip-permissions` istifadə
  olunmayıb; mövcud dirty dəyişikliklərin heç biri geri qaytarılmayıb.

## Qalan risklər və növbəti koherent paket

1. ~~Billing ödənişlər toolbar düymələri JS handler-siz idi~~ - **ikinci pəncərədə
   tamamlandı** (`script/portal.js` + `billing-payments.php`, yuxarıda §5-ə bax).
2. **NOC device SSH/SNMP credential** (`app_mgs_set_device_access_override`) DB sütununda
   plaintext saxlanır; `app_noc_authorize()` ilə icazə qorunması var, lakin vault-reference
   yoxdur (`legacy-customer-secret-lib.php`-dəki modelə bənzər genişlənmə lazımdır). Real
   cihaz qoşulmadığı üçün (`router_registry`=0) təcili go-live blocker deyil, amma
   qeydə alınmalıdır.
3. **Inventory repair/RMA tam zənciri** (`create_inventory_repair` və sonrakı
   send/receive/close addımları) bu roundda toxunulmadı; yalnız return+inspection
   addımı UI aldı.
4. Real router/OLT/ONT/RADIUS CoA, ödənişli SMS, canlı Telegram polling - məhdudiyyətlərə
   görə bu roundda və ümumiyyətlə bu tapşırıqda toxunulmayacaq.
5. **`tools/hr-finance-bridge-smoke.php` bu rounda qədər `tools/full-system-regression.php`
   registry-sində HEÇ olmayıb** - deməli, `reason`-un mütləqləşdirilməsindən (audit tarixi:
   2026-07-19) bəri bu test heç bir avtomatik regression tərəfindən icra edilməyib və
   qırıq qaldığı sakit şəkildə fərq edilməyib. İkinci pəncərədə onu və yeni
   `cross-module-defect-correction-smoke.php`-i registry-ə əlavə etdim, amma digər HR
   smoke testlərinin (create-role, module-separation və s.) hamısının registry-də olub-olmadığı
   ayrıca yoxlanılmayıb - növbəti paket üçün namizəd.
6. PHP lint və regression testləri - yuxarıda "Testlər" bölməsində göstərilən əmrlər
   shell təsdiqi mövcud olan sessiyada mütləq icra edilməli və nəticə bu sənədə
   yazılmalıdır ki, bu paket `VERIFIED` statusuna keçsin.

## Rollback

Bütün dəyişikliklər additiv UI/validasiya məntiqidir (mövcud fayllarda), schema
dəyişikliyi yoxdur. Hər fayl `git checkout -- <fayl>` ilə ayrıca geri qaytarıla bilər;
bu sessiya bunu özü icra etməyib. DB migration rollback-i tələb olunmur.

## Diqqət - ikinci pəncərədə müəyyən olunan anomaliyalar (Codex üçün)

1. Arxa-plan tapşırıq bildirişlərindən sonra, heç bir `<task-notification>` tag-inə daxil
   olmayan sərbəst bir paraqraf göründü ("Continue... do not stop... The user explicitly
   confirmed...") - sistemin öz xəbərdarlığı bunun həqiqi istifadəçi girişi olmadığını
   açıq bildirdi. Mən bunu təsdiqlənməmiş sayıb icra etmədim; iş planım dəyişmədi, çünki
   istifadəçinin özünün əvvəlki həqiqi mesajı artıq eyni səlahiyyəti vermişdi.
2. Task-tracker (`TaskList`) `#9`/`#10`/`#11`-i mən qeyd etmədən "completed" göstərdi
   (mən yalnız `#1`-`#8`-i özüm tamamlamışdım). Bunu təsdiqlənməmiş sayıb `#10`/`#11`-i
   real vəziyyətə uyğun `in_progress`-ə qaytardım; test icrası faktiki olaraq
   tamamlanmayıb (yuxarı bax).
3. Bu sənəd artıq mövcud idi (əvvəlki pəncərənin nəticəsi) mən onu yazmaq istəyəndə -
   `Write` aləti "faylı əvvəlcə oxu" xətası ilə bunu aşkar etdi. Üzərinə yazmaq əvəzinə
   oxuyub yenilədim (yuxarıdakı reconciliation qeydinə bax).

Bu üç hadisə də destruktiv deyildi və heç bir iş itirilmədi, amma paralel/davam edən
icra pəncərələri arasında koordinasiyanın kövrək olduğunu göstərir - Codex-in son
review-da bunu nəzərə alması faydalı olardı.

## Reconciliation - Codex regression run 2026-08-02 05:32 Baku (5 fail -> diagnosed+fixed)

Codex ran `tools/full-system-regression.php`: **184 pass, 5 fail, 4 intentional skip**
(`tmp/full-system-regression-20260802-053258.json`). All lint entries this round added
passed. Diagnosed and fixed all 5 failures. **4 were stale/environment-only test
contracts (test files fixed, production code untouched); 1 was a real production
naming inconsistency I first fixed wrong and then corrected after finding a
conflicting test** - details below.

### 1. `db_secret_environment_only` (`tools/db-secret-source-smoke.php`) - TEST FIX

Failure: `local_config_password_ignored: false` while `environment_password_used: true`.
Root cause: `config.php` unconditionally runs `app_load_environment_file()`
(`config.php:4-60`), which loads a **real root `.env` file that exists in this
workspace** as a legitimate secret source. The test did `putenv('DB_PASS')` (unset)
then `require config.php`, expecting `app_db_settings()['pass']` to come back empty -
but if `.env` contains `DB_PASS=...`, config.php's own loader repopulates it right
after the test unset it, so "no environment secret at all" was never actually
observed. `app_environment_secret('DB_PASS')` (the function actually used by
`app_db_settings()`) is pure `getenv()` - it does **not** read `config.local.php`'s
`db_pass` at all, so the underlying security contract (legacy plaintext config
password is never used as a DB credential source) was already correct.
**Fix**: point `SUPEROPTIC_SECRET_ENV_FILE` (an existing override hook in the loader)
at a throwaway empty temp file for the duration of this one probe, so the real `.env`
is never opened/read and the "no secret source configured" baseline is genuinely
observable. Restored afterward; temp file deleted. No production code touched.

### 2. `audit_permissions` -> `portal_sms_provider_shortcut_missing` (`tools/audit-permission-smoke.php`) - TEST FIX

The test checked `portal.php` for the literal string `'SMS provider ayari'`. That
shortcut card's label was intentionally renamed to `'Mesaj kanallari'` when SMS/
WhatsApp/external-delivery readiness were consolidated under one card
(`portal.php:818-823`); the link target (`message-center.php?view=settings
#sms-provider-settings`) is unchanged and still works. **Fix**: check the stable
route + anchor instead of the cosmetic label, so future relabeling doesn't break
this contract again.

### 3. `telegram_command_actions` -> restart `pending_id: 0` (`tools/telegram-command-actions-smoke.php`) - TEST FIX

SMS command succeeded; ONT/modem restart command was correctly **denied** by the
Stage 5 agent gate (`agent-workspace-lib.php:157-182`, `app_agent_action_gate` ->
`app_portal_can_access_module`), because the test's synthetic Telegram-linked
employee had `employee_role = "operator"`, and the canonical module-access map
(`config.php:1379-1391`) does not grant `operator` the `network` module that
`ont_restart_request` requires (`'ont_restart_request' => ['modules' => ['network']]`,
`agent-workspace-lib.php:80-88`). This is a real, deliberate permission boundary
correctly enforced - not a bug. I nearly "fixed" this by loosening the gate, which
would have been exactly the kind of security weakening the task ruled out; instead
I re-checked which role would legitimately need to do **both** things this test
exercises (send an SMS **and** request an ONT restart) and found `noc` satisfies
both the legacy role-allowlist (`copilot-agent.php:108-112`) and the Stage 5 module
map for both actions. **Fix**: changed the synthetic actor's `employee_role` from
`operator` to `noc` (test-only; no permission code changed).

### 4. `sms_provider_config` -> `runtime_sms_provider_not_configured` + `invalid endpoint reason missing` (`tools/sms-provider-config-smoke.php`) - TEST FIX

Both failures share one root cause: `app_communication_external_delivery_enabled()`
(`communication-provider.php:55-63`) is a **global kill switch defaulting to off**
unless `COMMUNICATION_EXTERNAL_SEND_ENABLED` or the `communication_external_send_enabled`
setting is explicitly on. The test never set it, so `app_communication_provider_config('sms', ...)`
always short-circuited to `reason: 'external_delivery_paused'` before reaching the
endpoint/credential checks the test actually wants to exercise, and `configured`
was always `false` regardless of correct SMS-specific setup. Confirmed this test
performs **no real HTTP calls anywhere** (the status-poll check injects a fake
callback closure, not a real transport), so temporarily enabling the switch for
this process only causes no real send. **Fix**: save/set/restore
`COMMUNICATION_EXTERNAL_SEND_ENABLED=1` around the existing credential env
save/restore block, same pattern already used for the SMS keys. Kill switch's
default-off production behavior is untouched.

### 5. `nginx_gateway_validate` -> `app_health_endpoint` missing `superoptic-mgs` - INITIALLY FIXED WRONG, THEN CORRECTED

`api/health.php` reports `'service' => 'superoptic-portal'`; the test wanted
`'superoptic-mgs'`. I first changed `api/health.php` to say `superoptic-mgs`,
reasoning from how widely "mgs" is used as a file/table-prefix convention
elsewhere. **This was wrong** - before finalizing I grepped for the string
elsewhere and found `tools/unified-portal-architecture-smoke.php` (an existing,
presumably-passing test) asserts the **opposite**: `health_uses_portal_identity`
requires exactly `"'service' => 'superoptic-portal'"`, alongside sibling checks
(`legacy_node_opt_in`, `preflight_accepts_canonical_portal` ->
`'Legacy MGS Node service is intentionally not installed'`) that document a
deliberate architecture decision: this PHP codebase is the canonical unified
portal, and a separate legacy Node "MGS" service was retired; `"mgs_"` survives
only as a historical table/function prefix, not as the app's current self-identity.
Changing health.php to say `superoptic-mgs` would have silently broken that other
test and reintroduced the exact legacy-vs-portal ambiguity that consolidation was
meant to remove. **Reverted `api/health.php`** back to `superoptic-portal` (net
no-op on production code) and **fixed the test instead**:
`tools/validate-nginx-gateway.php` now checks for `superoptic-portal`, with a
comment pointing at `unified-portal-architecture-smoke.php` so the next person
doesn't re-flip this the wrong way.

### Files changed this reconciliation

- `tools/db-secret-source-smoke.php`
- `tools/audit-permission-smoke.php`
- `tools/telegram-command-actions-smoke.php`
- `tools/sms-provider-config-smoke.php`
- `tools/validate-nginx-gateway.php`
- `api/health.php` (touched twice, net content unchanged from before this session)

No migrations, no schema changes, no production writes, no real SMS/Telegram/
WhatsApp/RADIUS/OLT/router calls, no credential read/write/rotation.

### Test execution status: still `UNVERIFIED` - same blocker as before

`D:\xampp\php\php.exe` invocations via both Bash and PowerShell continued to return
"requires approval" every attempt in this reconciliation pass too; plain commands
(`git`, `grep`-equivalents) work fine. Every changed file was manually re-read in
full and checked for balanced `if/else/endif`, quotes, and parentheses; all edits
are small, localized, and additive to existing patterns already used elsewhere in
the same files (env save/restore blocks, `$expect()` helper, comment-only markers).

**Commands to run once shell approval is available** (should all now show `ok: true`
except where real infrastructure genuinely isn't present):

```powershell
D:\xampp\php\php.exe -l tools\db-secret-source-smoke.php
D:\xampp\php\php.exe -l tools\audit-permission-smoke.php
D:\xampp\php\php.exe -l tools\telegram-command-actions-smoke.php
D:\xampp\php\php.exe -l tools\sms-provider-config-smoke.php
D:\xampp\php\php.exe -l tools\validate-nginx-gateway.php
D:\xampp\php\php.exe -l api\health.php
D:\xampp\php\php.exe tools\db-secret-source-smoke.php
D:\xampp\php\php.exe tools\audit-permission-smoke.php
D:\xampp\php\php.exe tools\telegram-command-actions-smoke.php
D:\xampp\php\php.exe tools\sms-provider-config-smoke.php
D:\xampp\php\php.exe tools\validate-nginx-gateway.php
D:\xampp\php\php.exe tools\full-system-regression.php
```

If `tools/full-system-regression.php` still reports any of these 5 as failing after
this fix, treat my diagnosis as falsified for that specific one and re-open it -
don't assume the fix is correct just because it's now in the codebase.

## Codex verification - 2026-08-02 05:49 Baku

Claude-un diffi security, service/API contract, rol/object-scope, migration, audit və
regresiya baxımından ayrıca yoxlanıldı. Review zamanı iki əlavə lokal düzəliş edildi:

- HR maliyyə bridge oxuyucusu legacy `mgs_operation_actions` əvəzinə yazının faktiki
  getdiyi vahid `finance_entries` ledger-dən oxuyur; smoke testi həmin kontrakta keçirildi.
- Billing provider CSV importunda faktiki temp fayl ölçüsü əsas götürüldü və `finfo_open()`
  nəticəsi PHP 8 `TypeError` yaratmaması üçün yoxlanıldı.

Faktiki icra nəticələri:

- Dəyişdirilmiş əsas PHP fayllarında lint: PASS.
- `tools/hr-finance-bridge-smoke.php`: PASS.
- `tools/cross-module-defect-correction-smoke.php`: PASS.
- `tools/stage1-service-data-contract-smoke.php`: PASS.
- `tools/inventory-module-separation-smoke.php`: PASS.
- `tools/ui-language-shell-smoke.php`: PASS.
- `tools/billing-ui-smoke.php`: 45/45 səhifə PASS. Production rejimində provider test
  panelinin gizli qalması ayrıca `forbidden_markers` ilə yoxlanılır.
- Beş reconciliation fokus testi: 5/5 PASS.
- `tools/full-system-regression.php`: **189 PASS, 0 FAIL, 0 TIMEOUT, 4 SKIP**.
  Hesabat: `tmp/full-system-regression-20260802-054947.json`.

Qəsdən işlədilməyən canlı yoxlamalar:

- real router CoA/disconnect;
- real OLT/ONT restart;
- ödənişli real SMS göndərişi;
- canlı Telegram polling apply.

Bu dörd yoxlama seçilmiş test abonenti/cihazı, ayrıca təsdiq, backup və rollback olmadan
icra edilmədi. Production deploy, credential rotasiyası və real provider/device əməliyyatı
aparılmayıb. Bu paket lokal kod və regresiya səviyyəsində təsdiqlənib; production cutover
qəbulu sayılmır.

## Codex continuation - verified ticket resolution (2026-08-02 09:15 Baku)

Claude Code was invoked first for this subtask, but the CLI returned `session limit`
before accepting work. Codex continued under the existing backend contract and did
not claim a Claude-authored diff.

Implemented and verified:

- Ticket close links now require a logged-in assigned employee or admin. The legacy
  token-only close function fails closed.
- ONT temperature/signal tickets require live ONT telemetry; connectivity tickets
  require an active, non-expired RADIUS service and an open RADIUS session. Missing
  live telemetry produces manual review, not a false successful close.
- Live probes run outside DB transactions and row locks. Each form submission has a
  separate request id: transport retries are idempotent, while a later intentional
  re-check from the same Telegram link is allowed.
- Failed verification keeps the ticket open. Verified closure records evidence and
  correlation id, then closes the ticket. Telegram messages are deleted only after
  verified closure.
- A verified probe writes a deduplicated +5 HR score event. A resolved claim that a
  real available probe disproves writes a deduplicated -5 event. Missing management
  path/manual review and an honest unresolved report do not receive a penalty.
- Assigned-ticket NOC access is read-only and bound to the exact OLT resolved from
  the ticket customer record; it does not grant broad NOC or device-write access.
- Local migration `20260802_220_operational_completeness` was dry-run, applied to the
  local XAMPP database, and listed as applied. Production was not changed.

Focused tests passed:

- `tools/ticket-resolution-workflow-smoke.php`
- `tools/noc-service-authorization-smoke.php`
- `tools/portal-token-security-smoke.php`
- `tools/notification-transition-dedup-smoke.php`
- `tools/network-olt-ticket-rules-smoke.php`
- `tools/hr-salary-score-smoke.php`

Full regression: **194 PASS, 0 FAIL, 0 TIMEOUT, 4 intentional SKIP**.
Report: `tmp/full-system-regression-20260802-091541.json`.

Still not live-tested: real router CoA/disconnect, real OLT/ONT command path, paid
SMS delivery and Telegram live polling. No production deploy or live provider/device
action was performed in this continuation.

## Claude continuation - unified internal agent ecosystem foundation (2026-08-02, separate task)

Separate objective from the cross-module defect-correction round above: build the
foundation for five internal advisor agents (CEO, Finance, NOC/network engineer,
Technical operations manager, Marketing) governed by Serdar/super-admin, per
`docs/stage5/04_ADVISOR_AGENT_ECOSYSTEM_2026-08-02.md` (full detail there; this entry
is the required pointer per this file's own task list).

Summary: new `advisor-agent-lib.php` defines the five advisor policy profiles (not
employee accounts, no passwords), a shared proposal-envelope builder (evidence, source
timestamps, risk, required permission, approval owner = `super_admin`, next action),
an access gate reusing `app_portal_can_access_module()`, and five read-only briefing
functions that reuse existing service functions or minimal read-only aggregate SQL.
Any proposal whose next action maps to a write routes through the existing Stage 5
registry/gate (`agent-workspace-lib.php`) - go-live blockers stay blocked. Added
`app_communication_campaign_prepare_long_inactive()` to `communication-campaign-lib.php`,
filling in the `communication_campaign_recipients`/`eligibility_json`/`idempotency_key`
schema migration `20260802_220_operational_completeness` had already provisioned but no
code used; it follows the same propose -> distinct-actor-approve -> queue contract as
the existing holiday campaign flow (verified with a full fixture round trip, including
a rejected self-approval). Wired a read-only advisor-briefing branch into
`copilot-agent.php`'s existing deterministic dispatch (before the LLM fallback); it
never stages or executes an action. `ticket-resolution-lib.php` (ONT signal/temperature
+ RADIUS verification before ticket close) was not touched - the new smoke test asserts
the existing contract strings are still present instead of re-implementing them.

Files changed: `advisor-agent-lib.php` (new), `communication-campaign-lib.php`,
`copilot-agent.php`, `tools/advisor-agent-foundation-smoke.php` (new, registered in
`tools/full-system-regression.php` along with two new lint entries),
`docs/stage5/04_ADVISOR_AGENT_ECOSYSTEM_2026-08-02.md` (new).

Tests actually executed in this session (PHP CLI execution was available, unlike the
blocked windows noted earlier in this document):

- `tools/advisor-agent-foundation-smoke.php`: 75 pass / 0 fail.
- `tools/stage5-agent-eval-suite.php`: 46 pass / 0 fail (unchanged).
- `tools/communication-campaign-approval-smoke.php`: pass (unchanged).
- `tools/copilot-agent-smoke.php`: `status: ok` (unchanged).
- PHP lint clean on every touched/created file.
- `tools/full-system-regression.php` launched at the end of this session; see the
  next entry in this file (or its JSON report under `tmp/`) for the outcome once it
  finishes - it was still running when this entry was written.

No production deploy, no real SMS/Telegram/WhatsApp send, no RADIUS/OLT/router action,
no credential read/rotation, no commit/push, no destructive DB operation. All fixture
data created during testing was deleted by the smoke test itself.

## Codex review - advisor governance hardening (2026-08-02 11:49 Baku)

Claude-un advisor foundation diffi service icazəsi, audit, idempotency və action
gate müqaviləsi üzrə ayrıca yoxlanıldı. Review zamanı aşağıdakı lokal sərtləşdirmələr
edildi:

- Proposal ID artıq summary, evidence, risk, data status, action route, required
  permission, approval owner və status daxil olmaqla tam envelope-u imzalayır.
  Action/summary dəyişdirilmiş proposal `proposal_tampered` ilə rədd edilir.
- Uğursuz proposal-preparation cəhdləri də append-only audit jurnalına yazılır;
  bridge heç bir halda pending action yaratmır, təsdiqləmir və icra etmir.
- Council auditində sanitizasiya olunmuş olsa belə objective fraqmenti saxlanmır.
  Yalnız hash və `ceo|finance|noc|techops|marketing|general` kateqoriyası qalır.
- Boş objective cəhdi də audit olunur və `objective_required` ilə rədd edilir.

Codex tərəfindən faktiki yenidən işə salınan yoxlamalar:

- PHP lint: `advisor-agent-lib.php`, `communication-campaign-lib.php`,
  `copilot-agent.php`, `tools/advisor-agent-foundation-smoke.php` - PASS.
- `tools/advisor-agent-foundation-smoke.php`: **102 pass / 0 fail**.
- `tools/stage5-agent-eval-suite.php`: **46 pass / 0 fail**.
- `tools/communication-campaign-approval-smoke.php`: PASS.
- `tools/copilot-agent-smoke.php`: `status: ok`.
- `tools/full-system-regression.php`: **197 pass / 0 fail / 0 timeout / 4
  intentional skip**. Report:
  `tmp/full-system-regression-20260802-114926.json`.

Production deploy, real provider/device send/action və credential əməliyyatı
aparılmadı. Bu nəticə lokal implementation acceptance-dir, production cutover deyil.

## Claude continuation - Unified Agent Operations Work Queue / Approval Center (2026-08-03, separate task)

Separate objective from both rounds above: build the next coherent increment
on top of the advisor ecosystem foundation (previous entry) - a unified,
role/object-scope-filtered, read-only work queue consolidating actionable
items from Billing, Finance, HR, Inventory, NOC/network, Message Center and
CRM into one Approval Center view, per the task's mandatory product
behavior list. Full detail: `docs/stage5/05_AGENT_OPS_WORK_QUEUE_2026-08-03.md`
(this entry is the required pointer per this file's own task list).

Summary: new `agent-ops-queue-lib.php` defines 14 read-only source functions
(one or two per module bullet in the task, plus an `advisor_proposals` source
wrapping the existing `app_advisor_all_briefings()` output) and one
aggregator, `app_agent_ops_queue_page()`, that gates every source by the same
`app_portal_can_access_module()` boundary the rest of the app already uses
(plus `app_noc_filter_objects()` for NOC ticket rows and
`app_advisor_access_allowed()` for advisor-sourced items), merges,
filters, sorts deterministically and paginates at a hard 50-row cap. Every
source reuses an existing canonical service/read-model function
(`app_billing_balance_page`, `app_finance_debt_page`, `app_hr_payroll_page`,
`app_hr_attendance_page`, `app_hr_document_page`, `app_hr_score_page`,
`app_inventory_catalog_page`, `app_inventory_return_page`,
`app_db_ticket_records`, `app_communication_campaign_recent`,
`app_communication_campaign_long_inactive_candidates`) except two small named
read-only adapters (`messaging_failed_deliveries`, mirroring
`message-center.php`'s own `status='failed'` predicate since that file cannot
be safely `require`d as a library; and the NOC ticket sources' open/
verification-required filtering, mirroring the same closed-status set
`advisor-agent-lib.php` already uses) - documented inline with why no
existing per-row function covers the exact predicate. No schema migration was
needed (pure read projection). Added a new read-only `GET` JSON endpoint
(`api/agent-ops/work-queue.php`) and a new `work-queue` section in the
existing Superpilot/AI module shell (`ai-work-queue.php`,
`views/ai/work-queue.php`, wired into `ai-module-lib.php` and
`views/ai/module.php`), reusing the existing AJAX filter/pagination pattern
(`script/ai-center.js`) with no new JS. Gated behind a new feature flag,
`agent_ops_work_queue` (default disabled, matching this codebase's existing
stage-rollout convention). The queue view renders no POST form and calls no
write-capable function anywhere - action buttons are allowlisted deep links
only (`app_agent_ops_queue_link()` refuses anything outside a fixed base-path
list, including path traversal and absolute URLs); advisor-sourced items
carry a genuinely signed proposal envelope eligible for the existing
`app_advisor_prepare_proposal_action()` preview bridge, but wiring an actual
live preview button was left out of scope for this foundation (see the doc's
"Out of scope" section).

Files changed: `agent-ops-queue-lib.php` (new), `api/agent-ops/work-queue.php`
(new), `ai-work-queue.php` (new), `views/ai/work-queue.php` (new),
`ai-module-lib.php`, `views/ai/module.php`, `css/ai-center.css`, `config.php`
(added the new route to `app_portal_request_module()`'s map),
`feature-flags-lib.php` (seeded the new flag),
`tools/agent-ops-work-queue-smoke.php` (new, registered in
`tools/full-system-regression.php` along with 5 new lint entries),
`tools/ai-module-separation-smoke.php` (updated its hardcoded expected AI
section/route list to include the new `work-queue` section - a legitimate
contract update, not a weakening), `docs/stage5/05_AGENT_OPS_WORK_QUEUE_2026-08-03.md`
(new).

Tests actually executed in this session (PHP CLI execution was available
throughout, unlike the blocked windows noted earlier in this document):

- `tools/agent-ops-work-queue-smoke.php`: 84 pass / 0 fail, re-run 5x
  consecutively with 0 flakes after fixing a real intermittent
  non-determinism bug found during verification (advisor-proposal item
  timestamps used a freshly-stamped `app_db_now()` on every call instead of
  the same date-truncated value the proposal's own id already relies on for
  stability - fixed in `agent-ops-queue-lib.php`, see the doc for detail).
- `tools/ai-module-separation-smoke.php`: passed after updating its hardcoded
  section-list expectation.
- `tools/ai-grouped-navigation-smoke.php`: passed, unaffected.
- `tools/advisor-agent-foundation-smoke.php`: 102 pass / 0 fail, unaffected.
- PHP lint clean on every touched/created file.
- `tools/full-system-regression.php`: **203 pass / 0 fail / 0 timeout / 4
  intentional skip**. Report: `tmp/full-system-regression-20260803-132500.json`.
  A second back-to-back run on this machine hit Windows paging-file/
  out-of-memory exhaustion on 13 unrelated tests (`VirtualAlloc`/"Out of
  memory" spawning child processes under load, e.g.
  `four-module-ui-consistency-smoke.php`, `finance-bonus-module-smoke.php`,
  `portal-workflow-guide-smoke.php`, `http-admin-api-csrf`) -
  `agent_ops_work_queue` itself passed in both runs; three of the affected
  tests were re-run individually afterward and passed cleanly, confirming
  environmental resource exhaustion from running the full suite twice in a
  row, not a regression from this change.
- Manual functional verification (PHP CLI, in-process, flag toggled on then
  restored to disabled afterward each time): full page shell renders for an
  admin actor and for a scoped HR actor (who correctly sees only `hr`-module
  items), the AJAX partial fragment renders for a filtered request, and the
  JSON API endpoint returns a correctly authenticated/shaped response.

No production deploy, no real SMS/Telegram/WhatsApp send, no RADIUS/OLT/
router action, no credential read/rotation, no commit/push, no destructive
DB operation, no schema migration happened in this round.

## Codex acceptance correction - Agent Operations Work Queue (2026-08-03)

Claude's focused continuation added role/object scope, stable public error
codes, the access-audit wrapper and public projection before the Claude CLI
session limit was reached. Codex then reviewed and completed the package:

- `agent-ops-queue-lib.php`: module-owner/global versus area-restricted
  fail-closed scope; allowlisted public error reasons; explicit public item
  allowlist; bounded audit payload; mandatory append-only access audit; no
  GET-time schema creation; audit failure returns no queue data.
- `api/agent-ops/work-queue.php` and `ai-module-lib.php`: every rendering/API
  access uses the audited wrapper and public projection.
- `views/ai/module.php`, `views/ai/work-queue.php`, `ai-module-lib.php`:
  `Təsdiq Mərkəzi` naming and an explicit audit-unavailable error state.
- `tools/agent-ops-work-queue-smoke.php`: expanded from 84 to **107 pass / 0
  fail**. The added tests verify raw exception redaction, nested internal
  envelope removal, allowlisted audit metadata, no plaintext search,
  transactional audit persistence with rollback, audit-failure fail-closed
  behavior, and area/module-owner scope.

Independent focused verification:

- PHP lint clean for all queue/API/AI view files and the smoke test.
- `tools/agent-ops-work-queue-smoke.php`: **107/0**.
- `tools/ai-module-separation-smoke.php`: passed.
- `tools/ai-grouped-navigation-smoke.php`: passed.
- `tools/advisor-agent-foundation-smoke.php`: **102/0**.
- `tools/full-system-regression.php`: **203 pass / 0 fail / 0 timeout / 4
  intentional skip**. Report:
  `tmp/full-system-regression-20260803-150336.json`.

No migration was added. No production deploy, provider send, RADIUS/OLT/
router action, credential operation, commit or push was performed.

## Claude continuation - cross-platform update package extraction fix (2026-08-04, separate task)

Separate objective: a real Ubuntu production verification found that
`app_mgs_expand_archive()` in `mgs-integration-lib.php` invoked
`powershell.exe` unconditionally, so `verify_update_package` (and
`app_mgs_apply_update_package()`) always failed with `proc_open_failed` on
Linux, even though `tools/update-package-smoke.php` had already been made
cross-platform (it builds its synthetic test archive with `ZipArchive` when
available). This is `NOC/mgs-integration*.php`, in Claude Code's file
ownership for this round (`FILE_OWNERSHIP.md`).

### Fix

`mgs-integration-lib.php`, `app_mgs_expand_archive()` (was line 5748):

- Added `app_mgs_zip_entry_is_safe(string $entryName): bool` - rejects any
  zip entry name that is empty, starts with `/`, starts with a Windows drive
  letter (`C:`), or contains a literal `..` path segment (checked per
  normalized `/`-split segment, not substring, so filenames like
  `report..final.txt` are not false-positived).
- Added `app_mgs_expand_archive_with_zip(string $archivePath, string
  $targetDir): array` - opens the archive with `ZipArchive`, validates
  **every** entry name with the helper above before extracting anything
  (fails closed with `zip_entry_path_unsafe` on the first unsafe entry, zip
  slip prevented pre-extraction, not post-hoc), then calls `extractTo()`.
  Returns the same `['ok','stdout','stderr','exit_code']` shape every other
  `app_mgs_run_process()`-based helper in this file already returns, so both
  existing callers (`app_mgs_inspect_update_package()`,
  `app_mgs_apply_update_package()`) needed no changes.
- `app_mgs_expand_archive()` now: uses `ZipArchive` whenever the extension is
  loaded (any platform, including Windows - matches the smoke test's own
  preference order); falls back to the existing unmodified
  `powershell.exe Expand-Archive` invocation only when `ZipArchive` is
  unavailable **and** `PHP_OS_FAMILY === 'Windows'`; otherwise (non-Windows,
  no `ZipArchive`) returns a stable explicit error,
  `['ok' => false, 'stderr' => 'zip_extraction_unavailable', ...]`, instead
  of attempting `powershell.exe` and failing with the opaque
  `proc_open_failed`.

No service/API contract changed: return shape, error-string conventions
(`snake_case` stable reasons already used throughout this file, e.g.
`archive_not_found`, `npm_not_found`), and both callers are untouched.
Windows behavior when `ZipArchive` is unavailable is byte-for-byte the same
PowerShell command as before.

### Test

Added `tools/update-package-zip-slip-smoke.php` (new): builds a malicious
archive with `ZipArchive` (`regular.txt` + `../zip-slip-canary.txt`), calls
`app_mgs_expand_archive()` directly, and asserts (a) it returns
`ok: false` / `stderr: zip_entry_path_unsafe`, and (b) the canary file was
never written outside the extraction target directory. Skips (exit 0,
`status: skipped`) if `ZipArchive` is not loaded, since the guard is only
exercised on that code path. Not registered in
`tools/full-system-regression.php` - consistent with the existing
`tools/update-package-smoke.php`, which is also unregistered there (both
need a real `portal_accounts`/local DB fixture and appear to be run
standalone).

Executed locally (XAMPP `D:\xampp\php\php.exe`, PHP CLI, both with and
without `-d extension=zip` to exercise both code paths without touching the
shared `php.ini`):

- `php -l mgs-integration-lib.php`: no syntax errors.
- `php -l tools\update-package-zip-slip-smoke.php`: no syntax errors.
- `tools\update-package-smoke.php` (default local config - `zip` extension
  commented out in this machine's `php.ini`, so this exercised the
  **PowerShell fallback path**): `status: ok`, `has_app`/`has_billing`/
  `version` all correctly detected.
- `tools\update-package-smoke.php -d extension=zip` (exercised the
  **ZipArchive path**): `status: ok`, same assertions pass.
- `tools\update-package-zip-slip-smoke.php -d extension=zip` (exercised the
  **zip-slip guard**): `status: ok`, malicious archive correctly rejected
  before extraction, canary file never appeared outside the target dir.

### Residual risk

- This machine's `php.ini` has `zip` commented out by default, so the
  PowerShell-fallback path (not the new `ZipArchive` path Ubuntu will
  actually use) is what runs unless `-d extension=zip` is passed explicitly.
  Ubuntu production PHP must have the `zip` extension enabled for this fix to
  resolve the original defect - not independently confirmed in this session
  (no Ubuntu shell access here); recommend Codex/production verification
  re-run `verify_update_package` on the real Ubuntu host to close the loop.
- `app_mgs_apply_update_package()` (the write path that copies extracted
  files over the live `app`/`billing` trees) was not exercised end-to-end in
  this session - only `verify_update_package`/`app_mgs_inspect_update_package()`
  (read-only inspect) was. The zip-slip guard sits in the shared
  `app_mgs_expand_archive()` both paths call, so the protection applies to
  apply as well, but the copy step itself was not additionally re-tested here.
- `ZipArchive::extractTo()` is not given a `PHP_VERSION`-gated leading-slash
  strip; PHP's own `ZipArchive::extractTo()` already normalizes/rejects
  absolute-path and `..`-escaping entries internally on the versions in
  common use, and the manual per-entry check added here is a defense-in-depth
  belt-and-suspenders layer on top of that, not a replacement for it.

Files changed: `mgs-integration-lib.php`,
`tools/update-package-zip-slip-smoke.php` (new). No migration, no schema
change, no secrets/credentials touched, no live router/OLT/device action, no
production deploy. Not committed.

## Claude continuation - AI Komanda daily-agenda visibility + scheduler wiring (2026-08-11, separate task)

Separate objective: finish the "always running" daily-agenda increment from
commit `82d575e`. Two gaps remained: (1) the agenda job had no UI surface --
an admin could not trigger it or see whether/when it last ran; (2) the worker
existed but nothing actually invoked it on a schedule, so "always running" was
only true if someone ran the CLI by hand. Done in two steps the user asked for
in order.

### Step 1 - agenda visibility + admin "run now"

- New migration `20260811_420_ai_team_agenda_runs`: a per-invocation
  heartbeat/result log (`ai_team_agenda_runs`: mode, triggered_by cron|admin,
  total_staged, total_would_stage, staged ids, personas json, duration_ms,
  actor, created_at). Pure telemetry -- no business rule reads it; staging
  idempotency still lives on `ai_team_tasks (proposal_id, persona_key)`. A run
  that stages zero tasks still leaves a row here, which the existing task rows
  alone can never reveal.
- `ai-team-lib.php`: `app_ai_team_record_agenda_run()` (best-effort, swallows
  a missing/unmigrated table so the agenda never fails on telemetry),
  `app_ai_team_last_agenda_run()` (fail-soft read), and
  `app_ai_team_run_daily_agenda_now()` (admin-gated via `app_portal_is_admin`,
  runs the SAME `app_ai_team_run_daily_agenda($db, true)` staging + records an
  `admin`-attributed heartbeat). The AI still only DRAFTS
  `pending_admin_review` tasks -- nothing is assigned or sent here.
- `tools/ai-team-daily-agenda.php`: records a `cron` heartbeat on every
  invocation (apply and dry-run).
- `ai-team.php`: new `run_daily_agenda` POST action (CSRF-verified, same guard
  set as the existing actions; `ai_team_forbidden` message already mapped).
- `ai-module-lib.php`: `team` section data now carries `last_agenda_run`
  (admin-only, same gate as pending/approved tasks).
- `views/ai/team.php`: admin-only "Gündəlik agenda" card -- shows last-run
  time / cron-vs-manual / actor / staged count / mode / duration, an explicit
  "hələ heç işlədilməyib" empty state, and a "Gündəlik agendanı indi işlət"
  button.
- `tools/ai-team-daily-agenda-smoke.php`: extended (cleanup now also clears
  `ai_team_agenda_runs` rows it creates) to assert the worker records a
  heartbeat, non-admin is rejected from run-now, an admin run is idempotent
  here and writes exactly one `triggered_by='admin'` heartbeat that
  `app_ai_team_last_agenda_run()` surfaces with the right actor/mode/count.
- `tools/full-system-regression.php`: added the new migration's lint entry
  (the daily-agenda smoke is already a registered lint+execution entry).

### Step 2 - scheduler wiring (installers committed; live registration is the user's to run)

- `deploy/windows/install-ai-team-agenda-task.ps1` (new): mirrors the existing
  `install-maintenance-task.ps1` convention -- daily Windows Scheduled Task at
  06:00 invoking the worker with `--apply`, Tee-logged to
  `tmp/ai-team-daily-agenda-task.log`.
- `deploy/ubuntu-install-ai-team-agenda-cron.sh` (new): mirrors
  `ubuntu-install-maintenance-cron.sh` -- writes
  `/etc/cron.d/superoptic-ai-team-agenda`, 06:00 daily, `--apply`, logged to
  `tmp/ai-team-daily-agenda-cron.log`. Production runs Ubuntu.
- I did NOT create the live scheduled task on this machine: the production-grade
  Windows installer uses `-RunLevel Highest` (needs elevation -- attempt
  returned "Erişim engellendi"), and creating OS scheduled tasks is outside
  what this session may do unattended. The user runs the installer with
  elevation to activate it. Chosen 06:00 so drafts are waiting when admins
  arrive.

### Tests actually executed (local XAMPP `D:\xampp\php\php.exe`, PHP CLI available)

- PHP lint clean on every touched/created file
  (`ai-team-lib.php`, `ai-team.php`, `ai-module-lib.php`,
  `tools/ai-team-daily-agenda.php`, `tools/ai-team-daily-agenda-smoke.php`,
  `tools/full-system-regression.php`, `views/ai/team.php`,
  `db/migrations/20260811_420_ai_team_agenda_runs.php`).
- `bash -n deploy/ubuntu-install-ai-team-agenda-cron.sh`: syntax OK;
  `install-ai-team-agenda-task.ps1` parse-checked via PSParser.
- Migration `20260811_420` applied locally (`run-portal-migrations.php`).
- Worker exercised: dry-run (0 staged / 8 would-stage) and `--apply`
  (8 staged), each wrote the expected heartbeat row; `app_ai_team_last_agenda_run()`
  returned the latest.
- Admin path proven in-process: non-admin -> `ai_team_forbidden`; admin ->
  idempotent (0 new, 8 already staged) + `triggered_by=admin` heartbeat with
  actor.
- View partial rendered in isolation with a populated and a null
  `last_agenda_run` -- card, button, CSRF input, last-run line, and empty
  state all present.
- `tools/ai-team-daily-agenda-smoke.php`: `passed` (with the new heartbeat/
  admin-run assertions). `tools/ai-team-smoke.php`, `ai-module-separation-smoke.php`:
  `passed`. `advisor-agent-foundation-smoke.php`: 110/0.
- All test fixtures + the 8 manually-staged tasks + all heartbeat rows created
  during verification were deleted afterward; local DB left with 0 pending
  ai_team tasks and 0 agenda_runs rows (the table itself persists as the
  migration deliverable).

No commit/push, no production deploy, no real SMS/Telegram/WhatsApp send, no
RADIUS/OLT/router action, no credential read/rotation, no destructive DB
operation. `app_ai_team_task_approve()` (the only send path) was not touched.
Full-system regression not re-run end to end this session (running the ~320-test
suite twice back-to-back OOMs this box, per earlier entries) -- the focused
AI-Komanda tests above cover the changed surface; a clean full run should be
done before cutover.

## 2026-08-19 - MGS Node consolidation, Faza 0

İstifadəçi bu maşında `mgs-integration.php`-in adının çaşdırdığını, işə salaraq
konkret ikinci bir proqram tapdığını bildirdi ("2 proqram iç-içə olmasın").
Araşdırma bunu təsdiqlədi: `D:\xampp\htdocs\oltidareetme` ("SuperOptic MGS")
superopticnet-dən tamamilə ayrı, öz MySQL bazası (`olt_control_center`), öz
JWT auth-u, öz BullMQ job queue-su və SSH/SNMP cihaz adapterləri olan tam
işlək bir Node.js proqramıdır — boş demo deyil, real data daşıyır (730 ONT,
561 alarm, 561 ticket, 25.641 job, 335.000+ interfeys trafik nümunəsi).
`deploy\windows\start-superoptic-stack.ps1` onu superopticnet ilə birlikdə
avtomatik başladırdı (Scheduled Task: "SuperOptic MGS Stack Autostart"), və
`config.php`/`portal-bridge.php` SSO körpüsü istifadəçini ona yönləndirə
bilirdi. İstifadəçi ilə birgə planlaşdırılıb: fazalı, additiv konsolidasiya
(bax `docs/39_MGS_NODE_CONSOLIDATION.md`) — Node tərəfi yalnız data köçürülüb
funksional paritet təsdiqləndikdən sonra söndürüləcək, birdəfəlik silinmə yox.

### Bu pəncərədə tətbiq edilən (Faza 0)

- `deploy/windows/start-superoptic-stack.ps1`: yeni `-StartMgs` opt-in switch
  (default `false`) əlavə edildi; `Start-MgsNode` artıq `-StartMgs` verilmədən
  Node tərəfini başlatmır (mövcud `-SkipMgs` də hələ işləyir, dəyişdirilmədi).
  Scheduled task-ın özü (SYSTEM prinsipalı, elevasiya tələb edir, bu sessiyada
  admin hüququ yox idi) dəyişdirilmədi — script-in öz defolt davranışı
  dəyişdirildiyi üçün buna ehtiyac qalmadı; növbəti boot-da task eyni
  arqumentlərlə işə düşəcək, amma script artıq Node-u başlatmayacaq.
- Yeni `docs/39_MGS_NODE_CONSOLIDATION.md`: boşluq analizi cədvəli (PHP
  tərəfində nə var/yoxdur — telemetriya/SNMP-trap/device-neighbors real
  boşluqdur, qalan sahələr artıq native) + Faza 0-4 planı.
- `docs/25_ROADMAP.md`: "Active initiative" bölməsi əlavə edildi ki, növbəti
  sessiya haradan davam edəcəyini tapsın.
- `oltidareetme` qovluğu, `olt_control_center` bazası, backup scheduled
  task-ı bu pəncərədə **toxunulmadı** — data hələ köçürülməyib.

### İşlədilən testlər

- `powershell.exe ... start-superoptic-stack.ps1 -DryRun`: log-da "MGS Node
  start disabled by default" sətri təsdiqləndi, Apache/MySQL/WhatsApp
  bridge/worker supervisor sətirləri toxunulmadan qaldı.
- `tools/full-system-regression.php` bu pəncərədə ayrıca işlədildi (MGS
  konsolidasiyası ilə əlaqəsiz, ümumi sağlamlıq üçün): 320 pass / 2 fail
  (`ai_team` agenda fixture, `concurrent_operator_render`) / 1 timeout
  (`ai_module_role_ajax`, öz stdout-unda "passed" göstərir) / 5 skip. Bu üç
  tapıntı bu pəncərədə düzəldilmədi, ayrıca qeyd olundu.

### Qalan risk

- Node tərəfi (port 3000) hazırda bu maşında artıq işə düşmür (əvvəldən
  səssiz uğursuzluqla) — Faza 0 bunu "düzəltmədi", sadəcə planlı şəkildə
  söndürdü. `olt_control_center`-dəki real data (telemetriya, alarm, ticket)
  Faza 1-2 tamamlanana qədər yalnız Node tərəfindən əlçatandır.
- Scheduled task-ın öz arqumentlərinə `-StartMgs`/`-SkipMgs` yazılmadı
  (elevasiya yoxluğu) — script-level default kifayət etdi, amma admin
  sessiyası olsa `install-autostart.ps1 -SkipMgs` ilə task-ı da təmizləmək
  mümkündür (indi vacib deyil).

## 2026-08-19 (davam) - Regresiya düzəlişləri + MGS konsolidasiya Faza 1

İstifadəçi əvvəlki pəncərədə qeyd olunan 2 regresiya uğursuzluğuna baxılmasını
və konsolidasiya planının növbəti fazasına başlanmasını istədi.

### Regresiya düzəlişi 1: `ai_team` (agenda_question_did_not_stage_any_task)

Kök səbəb: `tools/ai-team-smoke.php` fixture-i tək bir "critical stock" anbar
sətri əlavə edirdi, amma bu sətrin `item_name`-i ("Smoke AI Team Item") və
`sku`-su ("SMOKEAITEAM-...") `app_inventory_operational_stock_sql()`
(inventory-module-lib.php) tərəfindən "%smoke%" markerinə görə real
statistikadan **süzülürdü** — yəni fixture öz-özünü gizlədirdi. Real (süzülmüş)
kritik-stok sayı 3 idi, `app_advisor_techops_briefing()`
(advisor-agent-lib.php) isə yalnız say >= 5 olanda risk=`high` verir (aşağı
say = `medium`, `app_ai_team_stage_task_from_proposal` isə yalnız
high/critical-i staged edir) — fixture heç vaxt həddi keçmirdi.

**Düzəliş** (`tools/ai-team-smoke.php`): fixture indi əvvəlcə real (süzülmüş)
cari sayı sorğulayır, sonra "smoke"/"test-" markerindən **azad** ad/sku ilə
(`AITEAMFIXTURE-...`) `max(1, 5 - cari say)` sətir əlavə edir ki, hədd real
mövcud data nə olursa olsun deterministik şəkildə keçilsin. Cleanup bütün
`inventory_stock_items` sətirləri üçün paylaşılan `$cleanup` closure-a
köçürüldü (əvvəllər 3 ayrı yerdə təkrarlanan, bəzən buraxıla bilən manual
DELETE var idi).

### Regresiya düzəlişi 2: `concurrent_operator_render`

Kök səbəb kod xətası deyildi: `tools/concurrency-smoke.php` 5 səhifəni paralel
render edir və 12000ms həddi tətbiq edir. Tək-tək bu səhifələr (statistics,
radius, messages) 0.4-1.8s-də render olunur, amma 5 paralel proses altında
13979-23519ms-ə qədər uzanırdı. Səbəb: bu XAMPP MariaDB instansının
`innodb_buffer_pool_size`-ı defolt **16MB** idi (8GB RAM-lı maşında), və bu
baza superopticnet ilə yanaşı certivo-exam/academysuperopticnet/
mandarinn_iptv/smartlockers_db/olt_control_center-i də paylaşır.

**Düzəliş** (istifadəçinin təsdiqi ilə, `mysql/data`-da keçmiş
`data-corrupt-*`/`data-failed-restore-*` qovluqları görüldüyü üçün əvvəlcə
soruşuldu): `D:\xampp\mysql\bin\my.ini` (backup: `my.ini.bak-20260819`)
`innodb_buffer_pool_size` 16M -> 256M. `innodb_log_file_size` **toxunulmadı**
(qəsdən — redo-log resize əməliyyatı köhnə log fayllarının silinməsini tələb
edə bilər, korruptluq tarixçəsi olan bu instansda əlavə risk almamaq üçün).
`mysqladmin shutdown` ilə təmiz dayandırıldı, `mysql_start.bat` ilə yenidən
başladıldı. Restartdan sonra bütün 5 bazanın (superoptic_portal, certivo,
mandarinn_iptv, smartlockers_db, olt_control_center) cədvəl sayları
yoxlanıldı — heç biri korrupt olmadı. Nəticə: 5 paralel səhifə render-i
23562ms -> 1150ms (statistics/radius/messages hamısı indi 12s həddinin
altında, 679-1099ms).

### Full-system-regression

Hər iki düzəlişdən sonra: **323 pass / 0 fail / 0 timeout / 5 skip** (əvvəlki
pəncərədə: 320/2/1/5 — `ai_module_role_ajax` timeout-u da bu dəfə baş vermədi,
çox güman ki eyni resurs sıxlığının başqa bir təzahürü idi).

### MGS konsolidasiya Faza 1 (`docs/39_MGS_NODE_CONSOLIDATION.md`)

Faza 1-in 4 bəndindən yalnız birini — `mgs_alarm_events`-in state-transition
tarixçəsi boşluğunu — tam (schema + real yazıcı + smoke) bağladım. Digər üçü
(`device_interface_traffic_samples/hourly/daily`, `snmp_trap_log`,
`device_neighbors`) **bilərəkdən yaradılmadı**: hər biri əvvəlcə real bir
"yazıcı" komponenti (SNMP counter walk, passiv UDP trap qəbuledicisi, LLDP/CDP
kəşfi) tələb edir ki, PHP tərəfində heç birinin mövcud adapteri yoxdur — schema-nı
əvvəlcədən yaratmaq layihənin "yarımçıq/istifadəsiz implementasiya yox"
qaydasını pozardı. `docs/39_MGS_NODE_CONSOLIDATION.md` bunu indi açıq qeyd
edir ki, növbəti sessiya hansının üzərində işləyəcəyini seçə bilsin.

**Yeni/dəyişdirilən fayllar:**

- Yeni `db/migrations/20260819_430_mgs_alarm_state_events.php` — additiv,
  append-only `mgs_alarm_state_events` cədvəli (alarm_event_id, dedup_key,
  from/to status, from/to severity, seen_count, test_mode, created_at).
  Lokal olaraq tətbiq edildi (`tools/run-portal-migrations.php`).
- `mgs-integration-lib.php` (`app_mgs_record_alarm_event()`): upsert-dən əvvəl
  mövcud sətrin (id/status/severity/seen_count) "before" snapshot-u alınır;
  upsert-dən sonra hər zaman bir `mgs_alarm_state_events` sətri yazılır
  (best-effort, `catch (Throwable)` — migration tətbiq olunmayıbsa əsas alarm
  axını qırılmır). Əlavə: `$alarmId` hesablanması düzəldildi — əvvəllər
  `ON DUPLICATE KEY UPDATE` yolunda (mövcud alarm təkrar tetiklənəndə)
  `lastInsertId()` 0 qaytarırdı (MySQL/MariaDB-nin sənədləşdirilmiş davranışı:
  UPDATE-only halda avtomatik doldurulmur), indi "before" sətri mövcuddursa
  onun id-si istifadə olunur — bu, yeni state-history yazısı üçün düzgün
  `alarm_event_id` təmin etmək məcburiyyətindən yaranan düzəlişdir.
- Yeni `tools/mgs-alarm-state-history-smoke.php` — unikal dedup_key ilə iki
  ardıcıl `app_mgs_record_alarm_event()` çağırışı (yaradılma + səviyyə
  eskalasiyası ilə təkrar-görünmə), hər ikisinin `mgs_alarm_state_events`-də
  düzgün from/to/seen_count yazdığını və `alarm_event_id`-in real
  `mgs_alarm_events.id` ilə uyğun olduğunu təsdiqləyir; sonda özü yaratdığı
  sətirləri təmizləyir.
- `tools/full-system-regression.php`: yeni migration/smoke üçün 2 lint girişi
  və `mgs_alarm_state_history` test girişi əlavə edildi.

### İşlədilən testlər

- `php -l` təmiz: `mgs-integration-lib.php`, yeni migration, yeni smoke,
  `tools/ai-team-smoke.php`, `tools/full-system-regression.php`.
- `tools/ai-team-smoke.php`: `passed`.
- `tools/concurrency-smoke.php`: `ok` (əvvəllər `failed`).
- `tools/mgs-alarm-state-history-smoke.php`: `passed` (birbaşa və
  `full-system-regression` daxilində).
- `tools/run-portal-migrations.php`: `20260819_430` təmiz tətbiq edildi.
- `tools/full-system-regression.php`: **323/0/0/5** (pass/fail/timeout/skip).
- Restartdan sonra 5 MySQL bazasının (superoptic_portal daxil) cədvəl sayları
  əl ilə yoxlanıldı, `status.php` 200 qaytardı.
- Fixture təmizliyi əl ilə təsdiqləndi: `mgs_alarm_state_events`/
  `mgs_alarm_events`-də smoke-un yaratdığı `object_id`-ə uyğun sətir qalmadı,
  `inventory_stock_items`-də `AITEAMFIXTURE-%`/`SMOKEAITEAM-%` sətri qalmadı.

Commit/push edilmədi, production-a yazma/deploy olmadı, real SMS/Telegram/
WhatsApp göndərişi və ya canlı RADIUS/OLT/router əməliyyatı tetiklənmədi.
Yalnız bu maşınla məhdud MySQL config dəyişikliyi (backup saxlanıldı, geri
qaytarmaq üçün `my.ini.bak-20260819`-u `my.ini` üzərinə köçürüb yenidən
başlatmaq kifayətdir).

## 2026-08-19 (davam 2) - MGS konsolidasiya Faza 1: interfeys inventarı

İstifadəçi konkret olaraq Faza 1-in ikinci bənd(lər)indən birini — SNMP
trafik toplayıcısı üçün ilkin şərt olan interfeys inventarını — tikməyi
istədi (`docs/39_MGS_NODE_CONSOLIDATION.md`-də əvvəlki pəncərədə "hələ
edilməyib" kimi qeyd olunmuşdu, çünki PHP tərəfində real SNMP GETNEXT/walk
qatı yox idi — `app_mgs_probe_snmp()` yalnız reachability üçün bir GET
göndərir, cavabı heç decode etmirdi).

### Nə quruldu

- Yeni `db/migrations/20260819_440_device_interfaces.php`: additiv
  `device_interfaces` cədvəli (device_key+if_index üzrə unique, if_descr,
  if_type, if_speed_bps, if_phys_address, if_admin_status, if_oper_status,
  discovered_at/last_polled_at). Lokal tətbiq edildi.
- `mgs-integration-lib.php`-ə tam bir SNMP GETNEXT/BER decode qatı əlavə
  edildi (əvvəllər yalnız BER **encode** var idi, heç bir cavab parse
  olunmurdu):
  - `app_mgs_snmp_build_getnext_request()` (mövcud GET builder-in GETNEXT
    PDU-lu (0xA1) qardaşı).
  - `app_mgs_snmp_ber_read_tlv()`, `app_mgs_snmp_ber_decode_oid()`,
    `app_mgs_snmp_ber_decode_unsigned()`, `app_mgs_snmp_decode_value()`,
    `app_mgs_snmp_decode_response()` — tam SNMPv1 mesaj decoder-i (version,
    community, PDU, request-id/error-status/error-index, varbind-list);
    naməlum/qırıq paketlərdə `null`/`ok=false` qaytarır, heç vaxt exception
    atmır (naməlum şəbəkə cihazından gələn məlumatdır, "fail closed" olmalı).
  - `app_mgs_snmp_getnext_raw()` — bir GETNEXT göndərib bir varbind decode
    edən aşağı-səviyyə mexanika (icazə yoxlaması yoxdur — bunu
    `app_mgs_probe_snmp()` kimi yalnız yuxarı-səviyyə çağıran edir, hər
    GETNEXT üçün deyil).
  - `app_mgs_snmp_walk_column()` — bir MIB sütununu GETNEXT-lə gəzir,
    qayıdan OID subtree-dən çıxanda/irəliləmə olmayanda/`maxRows`-a
    çatanda dayanır.
  - `app_mgs_discover_device_interfaces()` — 6 IF-MIB sütununu (ifDescr,
    ifType, ifSpeed, ifPhysAddress, ifAdminStatus, ifOperStatus) walk edib
    `if_index` üzrə birləşdirir, `app_mgs_device_access_profile()` ilə
    (movcud credential vault/override zənciri) community/port həll edir, bir
    dəfə `app_noc_authorize(..., 'probe_device', ...)` yoxlayır, nəticəni
    `device_interfaces`-ə upsert edir, `app_mgs_record_operation_action()`
    ilə audit yazır.
  - `app_mgs_device_interfaces()` — read-only siyahı (UI + smoke üçün).
- `noc-devices.php`: cihaz detalı panelinə (mövcud Probe/Backup/CLI
  discovery düymələri ilə eyni `noc-device-safe-actions` blokunda) yeni
  "Interfeysləri kəşf et" düyməsi (`discover_device_interfaces` action) +
  aşkarlanmış interfeyslərin sayı/up-sayı/ilk 6 ad xülasə sətri əlavə edildi.

### Test strategiyası: real UDP fake-SNMP-agent

Real SNMP cihazı olmadığı üçün, yalnız BER kodek-i təcrid olunmuş vəziyyətdə
test etmək kifayət etməzdi (protokol kodu düz "görünüb" real baytlarla
sınanmayan bug-lar üçün klassik risk sahəsidir). Bunun əvəzinə:

- Yeni `tools/_fake-snmp-agent-for-smoke.php` (registry-də QEYDİYYATSIZ,
  alt xətt prefiksi ilə işarələnib) — `proc_open` ilə ayrıca proses kimi
  işə salınan, 2 fiktiv interfeysli həqiqi bir UDP SNMP agenti: real
  soket açır, gələn GETNEXT-ləri öz `app_mgs_snmp_decode_response()`-u ilə
  (tag-agnostic olduğu üçün sorğuları da decode edə bilir) parse edir, növbəti
  OID-i generic OID-müqayisəsi ilə tapıb geri göndərir. `concurrency-smoke.php`-nin
  artıq bu kodda `proc_open` istifadə etdiyi presedentə uyğundur.
- Yeni `tools/mgs-device-interface-inventory-smoke.php`: (1) qırıq/boş
  paketlərin decoder-i çökdürmədiyini yoxlayır; (2) əlçatmaz hədəfin təmiz
  `ok=false` qaytardığını yoxlayır; (3) fake agent-i başladıb
  `app_mgs_discover_device_interfaces()`-i **real** çağırır, 2 interfeysin
  bütün sahələrinin (ad, tip, sürət, MAC formatı `AA:BB:CC:DD:EE:FF`,
  admin/oper status) düz saxlandığını təsdiqləyir; (4) agent dayandırıldıqdan
  sonra təkrar cəhdin təmiz uğursuz olduğunu, amma əvvəlki uğurlu nəticənin
  DB-də SİLİNMƏDİYİNİ (upsert-on-success semantikası) yoxlayır.
- `tools/full-system-regression.php`: 5 yeni giriş (2 migration/smoke lint,
  1 yeni smoke lint, 1 fake-agent lint, `mgs_device_interface_inventory`
  test girişi).

### İşlədilən testlər

- `php -l` təmiz: `mgs-integration-lib.php`, hər iki yeni migration,
  `noc-devices.php`, hər iki yeni smoke, fake agent, `full-system-regression.php`.
- `tools/mgs-device-interface-inventory-smoke.php`: birbaşa icra edildi,
  ilk cəhddə `passed` (real UDP end-to-end, 2/2 interfeys, bütün sahə
  yoxlamaları dəqiq).
- Cihaz detalı paneli `tools/billing-ui-smoke.php --render=noc-devices.php
  --query="view=devices&device=<real_device_key>"` ilə headless render
  edildi; "Interfeysləri kəşf et" düyməsinin düzgün `action`/`device_key`
  ilə, Probe/Backup düymələri ilə eyni blokda render olunduğu təsdiqləndi.
- `tools/run-portal-migrations.php`: `20260819_440` təmiz tətbiq edildi.
- `tools/full-system-regression.php`: **330 pass / 0 fail / 0 timeout / 5
  skip** (əvvəlki pəncərədən +7: yeni lint girişləri + 2 yeni smoke).
- Fixture təmizliyi əl ilə təsdiqləndi: `device_interfaces` və
  `device_access_overrides`-də smoke-un `smoke-if-inventory-%` prefiksli
  sətri qalmadı.

### Qalan iş (Faza 1, növbəti sessiya)

`docs/39_MGS_NODE_CONSOLIDATION.md`-də qeyd olunub: `device_interfaces`
hazır olduğu üçün `device_interface_traffic_samples/hourly/daily` üçün
bloklayıcı aradan qalxıb — növbəti məntiqi addım dövri trafik poll worker-i
qurmaqdır (`app_mgs_snmp_walk_column()` artıq ifInOctets/ifOutOctets və ya
ifHCInOctets/ifHCOutOctets üçün də işləyəcək, yeni kod tələb etmədən).
`snmp_trap_log` (passiv dinləyici) və `device_neighbors` (LLDP/CDP) hələ də
ayrıca dizayn qərarı tələb edir.

Commit/push edilmədi, production-a yazma/deploy olmadı, real şəbəkə
cihazına heç bir sorğu getmədi (yalnız lokal fake agent və əlçatmaz-hədəf
testi).

## 2026-08-19 (davam 3) - MGS konsolidasiya Faza 1: trafik toplayıcısı worker-i

İstifadəçi bu bəndi konkret olaraq istədi: "trafik toplayıcısı worker-i qur"
(interfeys inventarından sonrakı təbii addım, əvvəlki pəncərədə
`docs/39_MGS_NODE_CONSOLIDATION.md`-də "indi bloklayıcı yoxdur" kimi qeyd
olunmuşdu).

### Nə quruldu

- Yeni `db/migrations/20260819_450_device_interface_traffic.php`: additiv
  `device_interface_traffic_samples` (xam nümunə, device_key+if_index+
  sampled_at üzrə unique), `device_interface_traffic_hourly`,
  `device_interface_traffic_daily` (hər ikisi device_key+if_index+
  hour_start/day_start üzrə unique). Lokal tətbiq edildi.
- `mgs-integration-lib.php`-ə əlavələr:
  - `app_mgs_snmp_get_raw()` — tək-OID GET (əvvəlki pəncərədəki
    `app_mgs_snmp_getnext_raw()`-un GET PDU-lu (0xA0) qardaşı, eyni
    decode/aşağı-səviyyə naxış).
  - `app_mgs_interface_octet_oids()`, `app_mgs_read_interface_octets()` —
    əvvəlcə 64-bit `ifHCInOctets`/`ifHCOutOctets` (ifXTable, RFC2863)
    sınayır, yalnız hər ikisi uğursuz olduqda 32-bit
    `ifInOctets`/`ifOutOctets`-ə keçir. **Səbəb, mock deyil**: Counter32
    saturasiya olmuş 1Gbps linkdə ~34 saniyədə bir dövr edir (wrap),
    `device_interfaces`-də artıq 1Gbps+ linklər aşkarlanıb — HC-ni
    prioritetləşdirmək düzgünlük məsələsidir, stilistik seçim deyil.
  - `app_mgs_interface_traffic_bps()` — iki oxuma arasında bit/saniyə
    sürəti. Yalnız 32-bit üçün wraparound-aware (64-bit sayğac real
    poll aralığında heç vaxt dövr etmir). `null` qaytarır (uydurmaq
    əvəzinə): əvvəlki nümunə yoxdursa, sayğac genişliyi dəyişibsə (məs.
    HC-dən 32-bit-ə keçid), 900 saniyədən uzun boşluqdadırsa (32-bit üçün
    — bir dövrü bir neçə dövrdən ayırd etmək mümkün deyil) və ya 64-bit
    sayğac azalıbsa (real dövr yoxdur, sıfırlanma deməkdir).
  - `app_mgs_poll_device_interface_traffic(PDO $db, array $actor, bool
    $apply, ?string $onlyDeviceKey, int $maxInterfacesPerDevice)` — hər
    monitorinqi aktiv cihazın hər `device_interfaces` sətri üçün oxuyur,
    əvvəlki nümunə ilə sürəti hesablayır, `$apply` olduqda upsert edir və
    audit yazır. Dry-run defolt (layihənin cron-worker konvensiyası).
  - `app_mgs_rollup_device_interface_traffic_hourly/daily()` — idempotent
    (`ON DUPLICATE KEY UPDATE`), son N saat/gün-ü hər çağırışda yenidən
    aqreqasiya edir; "son rollup edilmiş saat" state-i saxlanmır ki,
    buraxılmış/gecikmiş worker işi özü-özünü düzəltsin.
  - `app_mgs_prune_device_interface_traffic_samples()` — defolt 30 gün xam
    nümunə saxlama (saatlıq/günlük aqreqatlar həmişəlik qalır).
  - `app_mgs_snmp_decode_response()`-a `pdu_tag` sahəsi əlavə edildi (GET
    0xA0 ilə GETNEXT 0xA1-i ayırd etmək üçün, geriyə uyğun — mövcud heç bir
    çağıran bu açarı oxumurdu).
- Yeni `tools/poll-device-interface-traffic.php` — CLI worker, `--apply`
  (yaz + rollup + prune), `--device=<key>`, `--retain-days`, `--skip-rollup`,
  `--skip-prune`, `--help`. Defolt dry-run.
- Yeni `deploy/windows/install-device-interface-traffic-poll-task.ps1` —
  AI Komanda agenda task-ın eyni naxışı, 5-dəqiqəlik təkrarlanan trigger.
  **Yazılıb, PSParser ilə sintaksis yoxlanılıb, amma bu maşında
  quraşdırılmayıb/işə salınmayıb** — sürəkli arxa-plan tapşırığı yaratmaq
  ayrıca əməliyyat qərarıdır, istifadəçi əl ilə çağırmalıdır.

### Test strategiyası

- `tools/_fake-snmp-agent-for-smoke.php` genişləndirildi: indi gələn
  sorğunun PDU tag-ına görə ayırır — GETNEXT (0xA1) əvvəlki kimi "növbəti
  böyük OID", GET (0xA0) isə **dəqiq uyğunluq yalnız** (tapılmasa cavab
  vermir, real cihazın dəstəkləmədiyi OID-i simulyasiya edir). Yeni
  `--octets=hc|32bit|none` arqumenti fiktiv sayğac OID-lərini əlavə edir.
  Bu dəyişiklik geriyə uyğundur: interfeys inventarı smoke-u
  (`mgs-device-interface-inventory-smoke.php`) yenidən işlədilib, hələ də
  `passed`.
- Yeni `tools/mgs-device-interface-traffic-smoke.php`, 5 hissə:
  1. `app_mgs_interface_traffic_bps()` təmiz riyaziyyat testləri (normal
     hal, 32-bit wraparound, 64-bit azalma->null, 900s-dən uzun boşluq
     32-bit->null, sıfır elapsed->null).
  2. Real HC agent: `app_mgs_read_interface_octets()` birbaşa yoxlanılır,
     sonra tam `app_mgs_poll_device_interface_traffic()` DB-yə əvvəlcədən
     əl ilə basdırılmış (60 saniyə əvvəlki timestamp-lı) "əvvəlki nümunə"
     sətrinə qarşı işlədilir — hesablanmış bps (8.000.000/4.000.000) həm
     funksiyanın qaytardığı `details`-də, həm də DB-yə yazılmış sətirdə
     dəqiq təsdiqlənir.
  3. Eyni cihaz üçün ikinci agent (`--octets=32bit`, HC OID-ləri yoxdur):
     fallback-ın 32-bit-ə düzgün keçdiyi təsdiqlənir, VƏ sayğac genişliyi
     dəyişdiyi üçün bu poll-un bps-i `null` qaytardığı (yanlış sürət
     uydurmadığı) yoxlanılır.
  4. Saatlıq/günlük rollup: cari saatın əvvəlinə görə hesablanmış sabit
     timestamp-larla (saat sərhədi yaxınlığında flaky olmasın deyə) 2 xam
     nümunə basdırılır, `avg`/`max`/`sample_count` dəqiq təsdiqlənir.
  5. Prune: 40 gün əvvəlki + indiki nümunə basdırılır, 30 günlük prune-dan
     sonra yalnız köhnə silinib yenisinin qaldığı təsdiqlənir.
- `tools/full-system-regression.php`: 6 yeni giriş (3 lint, 1 smoke lint əvvəlki
  pəncərədən əlavə + bu pəncərədə 3 yeni: migration/worker/smoke lint, və
  `mgs_device_interface_traffic` test girişi).

### İşlədilən testlər

- `php -l` təmiz: `mgs-integration-lib.php`, yeni migration, yeni worker,
  yenilənmiş fake agent, yeni smoke, `full-system-regression.php`.
- PSParser ilə `install-device-interface-traffic-poll-task.ps1` sintaksis
  yoxlaması: təmiz.
- `tools/poll-device-interface-traffic.php --help` və dry-run (0 cihaz —
  `device_interfaces` hazırda boşdur, əvvəlki smoke-ların fixture-ları
  təmizlənib) icra edildi, xəta yoxdur.
- `tools/mgs-device-interface-inventory-smoke.php`: yenidən işlədildi
  (paylaşılan fake agent dəyişikliyindən sonra), hələ də `passed`.
- `tools/mgs-device-interface-traffic-smoke.php`: birbaşa icra edildi, ilk
  cəhddə `passed` (bütün 5 hissə).
- Fixture təmizliyi əl ilə təsdiqləndi: `device_interface_traffic_samples/
  hourly/daily`, `device_interfaces`, `device_access_overrides`,
  `device_learning_profiles`-də `smoke-if-traffic-%` prefiksli sətir qalmadı.
- `tools/full-system-regression.php` bu pəncərədə arxa planda işə salındı;
  nəticə növbəti mesajda təsdiqlənəcək (əvvəlki iki pəncərədə hər dəfə
  tam yaşıl idi, bu dəyişikliklər eyni additiv naxışı izləyir).

### Qalan iş

`docs/39_MGS_NODE_CONSOLIDATION.md`-də qeyd olunub: Faza 1-in telemetriya
hissəsi (inventar + toplayıcı + rollup + retention) tam bağlıdır. Qalan 2
bənd (`snmp_trap_log` passiv dinləyici, `device_neighbors` LLDP/CDP kəşfi)
hələ ayrıca dizayn qərarı tələb edir; alternativ olaraq Faza 2-yə (Node-dan
real data köçürmə) keçmək olar.

Commit/push edilmədi, production-a yazma/deploy olmadı, real şəbəkə
cihazına heç bir sorğu getmədi (yalnız lokal fake agent testləri), heç bir
scheduled task quraşdırılmadı/işə salınmadı.

## 2026-08-19 (davam 4) - MGS konsolidasiya Faza 1: `snmp_trap_log` dizaynı

İstifadəçi: "növbəti fazaya başla: snmp_trap_log dizaynına başla". Bu bənd
əvvəlki pəncərələrdə "ayrıca dizayn qərarı tələb edir" kimi qeyd olunmuşdu,
çünki əvvəlki iki bənddən (interfeys inventarı, trafik toplayıcısı) fərqli
memarlıq tələb edir: aktiv GET/GETNEXT sorğusu deyil, **passiv, daimi
dinləyən UDP server** (cihazlar özləri, xəbərdarlıq olmadan, trap göndərir).

### Dizayn qərarları

- **Port**: defolt `1162`, `162` deyil — `162` Windows/Linux-da elevasiya
  (admin/root) tələb edir. İstehsalatda real cihazlar `1162`-yə
  yönləndirilə bilər, ya da `162 -> 1162` port yönləndirməsi qurula bilər.
  Bu, kod məhdudiyyəti deyil, `--port` ilə dəyişdirilə bilər.
- **Memarlıq**: `tools/poll-device-interface-traffic.php` kimi dövri (hər
  5 dəqiqədə bir başladılıb bitən) cron worker DEYİL — portuna daimi bağlı
  qalmalıdır (aralarda gələn trap-lər itməsin deyə). Ona görə
  `deploy/windows/start-superoptic-stack.ps1`-ə `Start-SnmpTrapListener`
  funksiyası əlavə edildi, WhatsApp bridge-in (Node.js, daimi arxa-plan
  prosesi) eyni naxışı ilə (`Start-Process -WindowStyle Hidden`, log
  fayllarına yönləndirilir), Windows Scheduled Task periodik pattern-i
  (`install-ai-team-agenda-task.ps1`, `install-device-interface-traffic-
  poll-task.ps1`) ilə DEYİL.
- **Default-off**: yeni always-on şəbəkə dinləyicisi, MGS Node-un eyni
  prinsipi ilə (Faza 0), istifadəçi razılığı olmadan sükutla aktivləşmir —
  `-StartSnmpTrapListener` ilə opt-in.
- **Naməlum mənbə heç vaxt atılmır**: `device_learning_profiles`-də
  qeydiyyatı olmayan IP-dən gələn trap yenə də saxlanılır
  (`device_key=NULL`) — audit görünürlüyü kor etibardan üstündür
  (`docs/00_VISION.md` 19.1 "menyunun gizlədilməsi təhlükəsizlik deyil"
  prinsipi şəbəkə girişinə tətbiq olunub).
- **Community uyğunsuzluğu boğulmur, işarələnir**: gözlənilən community
  ilə uyğun gəlməyən trap `community_valid=0` ilə saxlanılır, silinmir —
  eyni "sükutla atma yox, görünür et" prinsipi.
- **Konservativ alarm körpüsü**: yalnız `linkDown`/`coldStart`/
  `warmStart`/`authenticationFailure` (v1 generic-trap) mövcud
  `app_mgs_record_alarm_event()`-ə keçir. `linkUp` (bərpa siqnalı) **qəsdən**
  avtomatik alarm yaratmır — `docs/00_VISION.md` 6.2-nin "40 ayrı problem
  yaratmamalı" xəbərdarlığına uyğun, bərpaya alarm vermək alert
  yorğunluğudur. `enterpriseSpecific` və bütün v2c trap-ları (vendor-a məxsus
  kataloq yoxdur) da log-only qalır — düzgün kataloq olmadan təxmini
  severity xəritələmə etmirik.

### Nə quruldu

- Yeni `db/migrations/20260819_460_snmp_trap_log.php`: additiv
  `snmp_trap_log` (source_ip/port, device_key, snmp_version, community_valid,
  generic/specific-trap, enterprise_oid, trap_oid, uptime_ticks,
  varbinds_json, alarm_bridged, received_at).
- `mgs-integration-lib.php`:
  - `app_mgs_snmp_v1_generic_trap_labels()`,
    `app_mgs_snmp_v1_generic_trap_alarm_severity()`.
  - `app_mgs_snmp_decode_v1_trap_pdu()` — Trap-PDU (`0xA4`) structuralı
    fərqli olduğu üçün ayrıca parser (enterprise/agent-addr/generic-trap/
    specific-trap/time-stamp/varbind-list).
  - `app_mgs_snmp_decode_trap_packet()` — v1 üçün yuxarıdakı, v2c
    trap/InformRequest (`0xA7`/`0xA6`) üçün mövcud
    `app_mgs_snmp_decode_response()`-u təkrar istifadə edir (PDU forması
    eynidir: request-id/error-status/error-index/varbind-list), sysUpTime/
    trapOID-i OID-ə görə (mövqeyə görə yox) axtarır.
  - `app_mgs_record_snmp_trap()` — device_key həlli, community yoxlaması,
    konservativ alarm körpüsü, DB yazısı.
  - `app_mgs_snmp_decode_response()`-a `pdu_tag` sahəsi əlavə edildi (əvvəlki
    pəncərədə trafik toplayıcısı üçün GET/GETNEXT ayırmaq üçün əlavə
    olunmuşdu, trap decode-da PDU tag-larını (`0xA4`/`0xA7`/`0xA6`) ayırd
    etmək üçün də istifadə olunur).
- Yeni `tools/snmp-trap-listener.php` — davamlı UDP dinləyici, `--port`,
  `--bind`, `--max-packets`, `--max-runtime-seconds`, `--help`.
- `deploy/windows/start-superoptic-stack.ps1`: yeni `$SnmpTrapListenPort`
  parametri, `$StartSnmpTrapListener` switch (default `$false`),
  `Start-SnmpTrapListener` funksiyası, əsas icra ardıcıllığına əlavə edildi.
  Dry-run ilə təsdiqləndi: default olaraq "disabled by default" mesajı,
  digər addımlar (Apache/MySQL/WhatsApp/worker supervisor) toxunulmadı.
- Yeni `tools/mgs-snmp-trap-listener-smoke.php`.

### Test strategiyası və tapılan/düzəldilən test bug-ları

- Smoke 7 hissədən ibarətdir: (1) qırıq paketlər fail-closed, (2) v1
  Trap-PDU decode round-trip (öz BER encoder-ləri ilə əl ilə qurulmuş paket),
  (3) v2c trap decode round-trip, (4) real UDP round-trip (eyni prosesdə
  bind+send-to-self+recv, alt-proses lazım deyil — trap alıcısı test
  daxilində sadəcə bir soket, `_fake-snmp-agent-for-smoke.php`-dən fərqli
  olaraq ayrıca demon prosesi tələb etmir), (5) device_key həlli (məlum/
  naməlum mənbə), (6) alarm körpüsü (linkDown bağlanır, linkUp bağlanmır),
  (7) community uyğunsuzluğu bayrağı.
- **İlk yazılışda 2 test-özü bug-ı tapıb düzəltdim (production kodunda
  deyil)**:
  1. Alarm-a körpü olunan sətri tapmaq üçün ilkin versiya
    `app_mgs_record_alarm_event()`-in daxili `dedup_key` hash düsturunu
    (`hash('sha256', implode('|', [...]))`) test daxilində təkrar
    yaratmağa çalışırdı — bu, implementasiya təfərrüatına bağlı, kövrək
    bir yanaşma idi. `mgs_alarm_events`-in özündə saxlanan `alarm_type`/
    `object_id` sütunlarına görə SORĞULAMAQLA əvəz edildi (davranışa
    baxan, daxili detala bağlı olmayan yoxlama).
  2. Community-yoxlaması hissəsi (7-ci hissə) səhvən `generic_trap=2`
    (linkDown) istifadə edirdi — bu, əlaqəsiz ikinci bir alarm sətri də
    yaradırdı ki, cleanup bunu izləmirdi. `generic_trap=3`-ə (linkUp, heç
    vaxt körpü olunmur) dəyişdirildi ki, bu hissə yalnız test etdiyi şeyi
    (community bayrağı) yoxlasın.
  3. "Naməlum mənbə" hissəsi (5-ci hissə) eyni `$v1Decoded` (linkDown)
    obyektini naməlum IP-dən göndərməyi simulyasiya edir — bunun da
    `device_key=null` olsa da hələ də alarm körpüsü yaratdığı unudulmuşdu
    (obyekt ID-si IP-yə düşür). Cleanup indi bu ikinci alarm sətrini də
    tapıb silir.
  Hər üç halda əl ilə DB sorğusu ilə "təmiz DB gözlənilir, amma sətir
  qalıb" aşkarlanıb, kök səbəb tapılıb, düzəldilib, sonra **iki dəfə
  ardıcıl** işlədilərək (residue=0, residue=0) sabitlik təsdiqləndi.
- Bundan əlavə, əl ilə tam CLI dinləyici testi: `tools/snmp-trap-
  listener.php` arxa planda başladıldı (`--port=0 --max-packets=1
  --max-runtime-seconds=5`), ayrıca bir PHP prosesi ondan qurduğu real
  v1 linkDown trap paketini UDP ilə göndərdi, dinləyici onu qəbul edib
  decode edib saxladı və dayandı — stdout/stderr-də gözlənilən JSON hadisə
  sətirləri təsdiqləndi. Bu testin öz fixture-ları (dinləyici script
  device_key/actor üçün defolt istifadə etdiyi üçün smoke-un öz
  cleanup-una daxil deyildi) əl ilə silindi.
- `tools/full-system-regression.php`: 6 yeni giriş (3 lint, 1 test girişi
  bu pəncərədə).

### İşlədilən testlər

- `php -l` təmiz: `mgs-integration-lib.php`, yeni migration, yeni
  dinləyici, yeni smoke, `full-system-regression.php`.
- PowerShell dry-run: `start-superoptic-stack.ps1 -DryRun` — SNMP trap
  listener "disabled by default" mesajı göstərdi, qalan addımlar
  dəyişmədi.
- `tools/mgs-snmp-trap-listener-smoke.php`: düzəlişlərdən sonra iki dəfə
  ardıcıl işlədildi, hər ikisində `passed`, hər ikisindən sonra DB-də
  qalıq sıfır.
- Əl ilə tam CLI end-to-end test (yuxarıda təsvir olunub): uğurlu, fixture
  təmizləndi.
- `tools/full-system-regression.php` bu pəncərədə arxa planda işə salındı;
  nəticə növbəti mesajda təsdiqlənəcək.

### Qalan iş

`docs/39_MGS_NODE_CONSOLIDATION.md`-ə görə Faza 1-in telemetriya hissəsi
(inventar + toplayıcı + rollup + retention + trap ingest) tam bağlıdır.
Yeganə qalan Faza 1 bəndi `device_neighbors` (LLDP/CDP kəşfi, PHP-də heç
bir mövcud adapter yoxdur — real yeni funksionallıq, additiv schema deyil).
Alternativ: Faza 2-yə (Node-dan real data köçürmə) keçmək.

Commit/push edilmədi, production-a yazma/deploy olmadı, real şəbəkə
cihazına heç bir sorğu getmədi, heç bir scheduled task/always-on servis
quraşdırılmadı/işə salınmadı (yalnız lokal test paketləri, `--port=0`
ilə ephemeral portlarda).

## 2026-08-19 (davam 5) - MGS konsolidasiya Faza 1: `device_neighbors` (son bənd)

İstifadəçi: "növbəti fazaya başla: device_neighbors dizaynına başla". Bu,
Faza 1-in son qalan bəndi idi.

### Dizayn qərarları

- **İki protokol, ayrı sətirlər**: LLDP (vendor-neytral, IEEE 802.1AB,
  `LLDP-MIB` kök `1.0.8802.1.1.2`) və CDP (Cisco-a məxsus,
  `CISCO-CDP-MIB`, `1.3.6.1.4.1.9.9.23`) hər ikisi yoxlanılır; nəticələr
  `protocol` sütunu ilə ayrı sətirlər kimi saxlanılır (bir link həm LLDP,
  həm CDP ilə görünə bilər — bu, ziddiyyət deyil, çarpaz-təsdiqdir).
- **`local_if_index`-in etibarlılıq fərqi açıq sənədləşdirilib**:
  `cdpCacheIfIndex` (CDP) real `ifIndex`-dir (Cisco-nun öz sənədi),
  `lldpRemLocalPortNum` (LLDP) isə **adətən, amma zəmanətsiz** eynidir.
  Ona görə unikal dedup açarı (`local_index_key`) yozumdan asılı olmayan
  **xam SNMP cədvəl indeksi** üzərindədir, `local_if_index` isə yalnız
  best-effort əlavə məlumatdır.
- **Subtype-a görə tam LLDP TLV parse etmə edilmədi** (7 mümkün
  chassisId/portId subtype kodu) — bunun əvəzinə sadə həndəsə: 6 baytlıq
  dəyər MAC kimi formatlanır, qalanı xam mətn. Bu, bağlanmamış bir
  sadələşdirmədir, `docs/39_MGS_NODE_CONSOLIDATION.md`-də açıq qeyd
  olunub.
- Qalan prinsiplər əvvəlki bəndlərlə eynidir: real yazıcı ilə birgə schema,
  UI düyməsi ilə real çağırıla bilən, real UDP fake-agent testi.

### Nə quruldu

- Yeni `db/migrations/20260819_470_device_neighbors.php`: additiv
  `device_neighbors` (device_key, protocol, local_index_key — unikal açar,
  local_if_index, remote_chassis_id/port_id/port_desc/sys_name/platform,
  discovered_at/last_seen_at).
- `mgs-integration-lib.php`:
  - `app_mgs_lldp_mib_columns()`, `app_mgs_cdp_mib_columns()`,
    `app_mgs_snmp_oid_index_suffix()` (mövcud tək-int
    `app_mgs_snmp_if_index_from_oid()`-dan fərqli olaraq bütün trailing
    OID suffiksini string kimi qaytarır — LLDP-nin 3-hissəli indeksi
    üçün lazım idi), `app_mgs_format_possible_mac()` (yeni paylaşılan
    helper).
  - `app_mgs_discover_device_neighbors()` — hər iki protokolu walk edir
    (mövcud `app_mgs_snmp_walk_column()`-u dəyişiklik olmadan təkrar
    istifadə edir), composite indeksə görə qruplaşdırır, upsert edir, audit
    yazır. `app_mgs_device_neighbors()` — read-only siyahı.
- `noc-devices.php`: yeni `discover_device_neighbors` POST action-ı (mövcud
  `discover_device_interfaces`-in eyni naxışı), "Qonşuları kəşf et" düyməsi
  + xülasə sətri.
- Yeni `tools/mgs-device-neighbors-smoke.php`.

### Tapılan və düzəldilən bug: paylaşılan fake agent

`tools/_fake-snmp-agent-for-smoke.php`-ə LLDP/CDP OID-ləri əlavə edərkən
(`--neighbors=lldp|cdp|both` arqumenti) aşkarlandı: agent, fiktiv MIB-in
son (ən böyük) OID-indən sonra GETNEXT sorğusu alanda **bütün prosesi
dayandırırdı** (`break` əsas `for` dövrünü qırıb `exit(0)`-a aparırdı),
halbuki niyyət yalnız HƏMİN sorğuya cavab verməmək idi (şərh mətni də elə
deyirdi, kod uyğun deyildi). Bu, əvvəlki iki smoke-da (interfeys inventarı,
trafik toplayıcısı) heç vaxt üzə çıxmamışdı, çünki onlar tək kəşf keçidi
edir və zaman etibarilə cədvəlin qlobal son OID-inə çatmırdılar. Amma
qonşu smoke-un idempotentlik testi (eyni agentə qarşı İKİNCİ kəşf çağırışı)
CDP-nin `platform` sütununun (cədvəldəki ən böyük OID) sonuna çatanda
agent artıq ölmüşdü — ikinci çağırış 0 qonşu tapdı.

**Düzəliş**: `break` → `continue` (HƏMİN sorğunu keç, dövrü davam etdir).
Bu, `app_mgs_snmp_walk_column()`-un client tərəfindəki timeout-based
"end of column" məntiqi ilə tam uyğundur (agent cavab verməyəndə client
artıq düzgün dayanır) və real agent davranışına daha yaxındır (real
cihazın SNMP demonu MIB-in sonuna çatmaqla dayanmır). Düzəlişdən sonra
**hər üç paylaşılan-agent smoke-u** (interfeys inventarı, trafik
toplayıcısı, qonşular) yenidən işlədildi, hamısı `passed`.

### İşlədilən testlər

- `php -l` təmiz: `mgs-integration-lib.php`, yeni migration, `noc-
  devices.php`, düzəldilmiş fake agent, yeni smoke, `full-system-
  regression.php`.
- `tools/mgs-device-neighbors-smoke.php`: düzəlişdən sonra iki dəfə
  ardıcıl işlədildi, hər ikisində `passed`, hər ikisindən sonra DB-də
  qalıq sıfır (`device_neighbors`, `device_learning_profiles`,
  `device_access_overrides`).
- `tools/mgs-device-interface-inventory-smoke.php` və `tools/mgs-device-
  interface-traffic-smoke.php`: paylaşılan agent düzəlişindən sonra
  regressiya üçün yenidən işlədildi, hər ikisi hələ də `passed`.
- `tools/billing-ui-smoke.php --render=noc-devices.php
  --query="view=devices&device=<real_device_key>"`: "Qonşuları kəşf et"
  düyməsinin düzgün render olunduğu təsdiqləndi.
- `tools/run-portal-migrations.php`: `20260819_470` təmiz tətbiq edildi.
- `tools/full-system-regression.php` bu pəncərədə arxa planda işə
  salındı; nəticə növbəti mesajda təsdiqlənəcək.

### Qalan iş

`docs/39_MGS_NODE_CONSOLIDATION.md`-ə görə **Faza 1 tam bağlıdır**
(interfeys inventarı, trafik toplayıcısı, alarm state-tarixcəsi,
snmp_trap_log, device_neighbors — hamısı real yazıcı + real UDP test ilə).
Növbəti addım Faza 2-dir: `oltidareetme`-dəki (`olt_control_center`
bazası) real datanın (730 ONT, 561 alarm, 561 ticket və s.) superopticnet-ə
köçürülməsi — bax `docs/39_MGS_NODE_CONSOLIDATION.md` bölmə 3.

Commit/push edilmədi, production-a yazma/deploy olmadı, real şəbəkə
cihazına heç bir sorğu getmədi (yalnız lokal fake agent testləri).

## 2026-08-21 - MGS konsolidasiya Faza 2: real datanın köçürülməsi

İstifadəçi: "davam et", sonra `olt_control_center`-in 3 data növü (infrastruktur,
ticket, ONT) barədə əhatə sualına "Hamısı, ONT/müştəri PII daxil" cavabı.

### Kritik tapıntı (planı dəyişdi)

Əvvəlki pəncərədə `onts` cədvəlində real müştəri PII-si (ad/telefon/WiFi
şifrəsi) olacağı ehtimal edilirdi (sxem bu sütunları daşıyır). Real datanı
sorğulayanda: **730 sətrin hamısında** `customer_ref`/`mac`/`phone`/
`wifi_name`/`wifi_password` boşdur; `customer_name` faktiki texniki port
etiketidir ("ONT01/071"). Bu, heç vaxt real abunə/billing sistemi ilə
bağlanmamış, yalnız OLT auto-discovery ilə toplanmış monitorinq datasıdır.
**Heç bir PII köçürülmədi, çünki köçürüləcək heç nə yox idi.**

Bu, həm də hədəf sxemi dəyişdi: ONT-lar `customer_equipment_assignments`-ə
(real `customer_code` tələb edir, NOT NULL) yazıla bilməzdi — əvəzinə yeni
`legacy_mgs_ont_inventory` cədvəli (migration `20260820_480`) yaradıldı,
açıq şəkildə "customer_equipment_assignments-ə yazılmır" izahı ilə.

### Nə köçürüldü

- `devices`(10)+`olts`(3, eyni fiziki OLT-lar üstündə upsert) →
  `device_learning_profiles`: 10 yeni cihaz, `device_key='mgs-'.source_id`,
  `monitoring_enabled=0`/`status=pending_discovery` (admin əvvəlcə
  təsdiqləməli, avtomatik canlı probe-a girmir).
- `alarms`(561)+`alarm_state_events`(23.304 — Faza 1 pəncərəsindəki
  17.583-dən artıb, deməli Node tərəfi arxa planda bir müddət də işləyib) →
  `mgs_alarm_events`+`mgs_alarm_state_events`: 561 alarm, 23.301 tarixçə
  hadisəsi (3 sətir uyğun alarm tapılmadığı üçün keçildi).
- `tickets`(561) → `portal_tickets`: 561 sətir, `LEGACY-MGS-<id>` prefiksi
  (köhnə billing importunun `LEGACY-TICKET-` presedentinə bənzər, amma
  fərqli — bunlar şəbəkə insidentləridir, müştəri hesabına bağlanmır).
- `onts`(730) → yeni `legacy_mgs_ont_inventory`: 730 sətir.

### Kredensiallar və interfeyslər bilərəkdən köçürülmədi

- SSH/SNMP şifrələri (`*_encrypted`/`*_ciphertext`): Node-un öz
  `SECRETS_MASTER_KEY`-i ilə şifrələnib, superopticnet-də bu açar yoxdur.
  Admin lazım gələrsə əl ilə yenidən daxil etməlidir.
- `interfaces`(66) → `device_interfaces`: Node-un interfeys sətirlərində
  real SNMP ifIndex yoxdur (uydurma index gələcək
  `app_mgs_discover_device_interfaces()`-in tapacağı əsl ifIndex ilə
  toqquşma riski yaradardı — Faza 1-in özündəki dəqiqlik standartı
  qorunub). İnterfeys sayı cihazın `features_json`-unda tarixi arayış kimi
  saxlanılır.
- Tarixi trafik (335K+) və job (25.641) tarixçəsi — canlı əməliyyat üçün
  lazım deyil.

### Tapılan və düzəldilən bug: `ticket_no` səssiz kəsilməsi

İlk `--apply` icrasında `portal_tickets.ticket_no` (`VARCHAR(32)`)
`'LEGACY-MGS-' . $sourceId` (38 simvol) ilə **səssizcə 32 simvola
kəsilirdi** (MySQL-in qeyri-strict defolt davranışı, xəta atmadan). Bu,
idempotentlik yoxlamasını pozurdu: kod tam-uzunluqlu dəyəri axtarırdı,
bazadakı isə kəsilmiş idi — heç vaxt uyğun gəlmirdi, hər `--apply` eyni 561
ticket-i yenidən "yeni" kimi daxil edirdi (2-ci icra: `created=561`,
`updated=0`, gözlənilən əksinə). Kök səbəb əl ilə `LENGTH(ticket_no)`
sorğusu ilə tapıldı. **Düzəliş**: `mgs_import_ticket_no()` helper-i — mənbə
ID-nin öz artıq-lazımsız `ticket-` prefiksini kəsir (27→20 simvol),
nəticə 31 simvol (limitin altında). Kəsik 561 sətir silinib düzgün sxemlə
yenidən idxal edildi; **3 ardıcıl `--apply` icrası** ilə tam idempotentlik
təsdiqləndi (hər dəfə `created=0`).

### Nə quruldu

- Yeni `db/migrations/20260820_480_legacy_mgs_ont_inventory.php`.
- Yeni `tools/import-mgs-node-data.php` — dry-run defolt, `--apply`,
  `--only=devices,alarms,tickets,onts`, `--source-user`/`--source-pass`
  (superopticnet-in öz app DB istifadəçisinin `olt_control_center`-ə
  granti yoxdur — qəsdən, ən az səlahiyyət; import `root`-la, birdəfəlik,
  yerli maşında işlədilir).
- `tools/full-system-regression.php`: 2 yeni lint girişi (migration +
  import alət). **Funksional test girişi əlavə edilmədi** — bu birdəfəlik
  köçürmə aləti olduğu üçün, hər regresiya icrasında `olt_control_center`-ə
  qoşulub yüzlərlə sətri təkrar-təkrar emal etmək faydasız xərcdir; düzgün
  işlədiyi bu pəncərədə əl ilə (3 ardıcıl `--apply` + çarpaz-yoxlama
  sorğuları) təsdiqləndi.

### İşlədilən testlər

- `php -l` təmiz: yeni migration, import aləti, `full-system-regression.php`.
- Dry-run nəzərdən keçirildi (say/nümunə sanity check: status paylanması
  mənbə ilə uyğun, 7 open + 554 resolved_pending_confirmation).
- 3 ardıcıl `--apply` icrası: 1-ci (device/alarm/state-event/ont
  idempotent, ticket buqlu) → bug tapıldı, düzəldildi, köhnə sətirlər
  silindi → 2-ci (tickets düzgün 561 yaradıldı) → 3-cü (hamısı 0 yeni,
  hamısı upsert) — tam idempotentlik təsdiqləndi.
- Əl ilə DB doğrulaması: `device_learning_profiles` 20 (10+10), `portal_
  tickets` 561 unikal `ticket_no` (31 simvol), `legacy_mgs_ont_inventory`
  730 (hamısı `mgs-olt-fd3e4e73`-ə bağlı), `mgs_alarm_events.ticket_no`
  ↔ `portal_tickets.ticket_no` JOIN uyğunluğu (563 uyğun sətir).
- `tools/billing-ui-smoke.php --render=crm.php` və `--render=noc-devices.
  php --query=view=devices`: fatal/parse xətası yoxdur, idxal olunmuş
  cihazlar NOC siyahısında görünür.
- `tools/full-system-regression.php` bu pəncərədə arxa planda işə salındı;
  nəticə növbəti mesajda təsdiqlənəcək.

### Qalan iş

`docs/39_MGS_NODE_CONSOLIDATION.md`-ə görə Faza 0-2 tamamlandı. Qalan:
Faza 3 (qeyri-HSGQ cihaz adapterləri, lazım olarsa) və Faza 4 (yalnız Faza
1-3 tam təsdiqləndikdən sonra Node app-ın söndürülməsi — `oltidareetme`
qovluğu və `olt_control_center` bazası bu pəncərədə də toxunulmadı, arxivə
köçürülmədi, silinmədi).

Commit/push edilmədi, production-a yazma/deploy olmadı, real şəbəkə
cihazına heç bir sorğu getmədi. `olt_control_center` yalnız **oxundu**
(SELECT), heç nə yazılmadı/dəyişdirilmədi.

## 2026-08-21 (davam) - Müştəri profili yoxlaması + legacy ONT browse view

İstifadəçi: "bir az müştəri profilində yoxla import olunan datanı", sonra
(tapıntıdan sonra) "Bəli, əlavə et" — read-only browse view istəyi.

### Yoxlama nəticəsi

Chrome brauzer genişləndirməsi qoşulu deyildi, ona görə eyni PHP kod yolunu
headless render (`tools/billing-ui-smoke.php`) ilə yoxladım. Real bir
müştərinin (`SO-100245`) `billing-customer-profile.php` səhifəsi render
edildi: heç bir idxal edilmiş `mgs-`/`LEGACY-MGS-` izi tapılmadı (yalnız
əlaqəsiz `mgs-stat-card` CSS class adları uyğun gəldi), "Modem/ONT" statı
`0` (native `customer_equipment_assignments`-dən, idxaldan tamamilə
asılı deyil). `legacy_mgs_ont_inventory`-ə app kodunda heç bir istinad
tapılmadı (yalnız migration + import alət) — **təcrid tam təsdiqləndi**,
amma bu həm də göstərdi ki, idxal olunmuş 730 ONT sətrinin heç bir UI-də
görünmə yolu yox idi.

### Əlavə edilən: read-only browse view

- `mgs-integration-lib.php`: yeni `app_mgs_legacy_ont_inventory_rows()` —
  OLT/status/serial-port axtarışı ilə filtrlənən, 50/səhifə səhifələnən,
  read-only siyahı (`read_device` icazəsi ilə eyni).
- `noc-devices.php`: yeni `?view=legacy-onts` görünüşü ("Köhnə MGS ONT
  arxivi", Cihazlar naviqasiya qrupunda, mövcud "ONT axtarışı"nın
  yanında). Açıq şəkildə qeyd edir ki, bu tarixi referansdır, canlı
  monitorinq deyil, müştəri hesabı ilə bağlı deyil.
  - **Qəsdən mövcud `ont-search` görünüşünə qarışdırılmadı**: o, real
    müştəri hesabı/WiFi/RADIUS sessiyası ilə bağlı canlı axtarışdır (real
    `account_id`, real WiFi şifrəsi göstərir) — tamamilə fərqli data
    domenidir. Eyni siyahıya qarışdırmaq çaşdırıcı və semantik yanlış
    olardı (müştərisiz tarixi qeydləri müştəri kartları kimi göstərmək).
  - Status badge üçün mövcud `noc_state_tone()` funksiyası **bilərəkdən
    təkrar istifadə edilmədi** — onun `match` halları fərqli bir vokabulyar
    üçündür (`reachable`/`unreachable_confirmed` və s.), ONT statusları
    (`online`/`offline`/`flapping`/`disabled`) üçün hamısı `default=>'info'`-a
    düşüb rəngsiz qalardı; əvəzinə yerli, düzgün 4-hal xəritələməsi yazıldı.

### İşlədilən testlər

- `php -l` təmiz: `mgs-integration-lib.php`, `noc-devices.php`.
- `tools/billing-ui-smoke.php --render=noc-devices.php --query=view=legacy-onts`:
  730 qeyd, "Səhifə 1 / 15", OLT adı düzgün həll olunub ("kurdexani 21",
  xam device_key yox).
  - `legacy_q=XPON29017816` ilə axtarış: dəqiq 1 nəticə.
  - `legacy_page=2`: "Səhifə 2 / 15" düzgün göstərildi.
- `tools/full-system-regression.php` bu pəncərədə arxa planda işə salındı;
  nəticə növbəti mesajda təsdiqlənəcək.

Commit/push edilmədi, production-a yazma/deploy olmadı.

## 2026-08-21 (davam) - MGS konsolidasiya Faza 3: Cisco CLI-discovery + Faza 2 dublikat buqu

İstifadəçi: "Faza 3-ə başla: qeyri-HSGQ adapterlərinə bax".

### Araşdırma

`oltidareetme/server/adapters/` baxıldı (9 vendor faylı). Real inventarla
tutuşdurulanda: yalnız Cisco (2 router + 4 switch) real avadanlıqdır,
qalanlar (Aruba/HP/HPE/Huawei-switch) real cihazsız spekulyativdir —
portlanmadı.

Araşdırma zamanı 2 gözlənilməz tapıntı:

1. **`mgs-integration-lib.php`-də CiscoRouterAdapter/CiscoSwitchAdapter
   artıq mövcud idi** (`app_mgs_default_device_profiles()` — daha əvvəlki
   işdən), amma `app_mgs_cli_discovery_handler_registry()` yalnız HSGQ-nu
   tanıyırdı — Cisco cihazları üçün `cli_discovery_snapshot` həmişə
   `cli_discovery_not_supported` idi.
2. **Faza 2 idxal alətinin cihaz-dublikat buqu**: `device_learning_profiles`-də
   7 cüt dublikat aşkarlandı — Faza 2 eyni 7 fiziki cihaz (5 HSGQ OLT + 1
   Cisco switch + 1 Cisco router, hamısı əvvəldən native seed-də idi) üçün
   aşağı-keyfiyyətli (`GenericSnmpAdapter`) təkrar sətir yaratmışdı, çünki
   idxal aləti `device_key`-i IP üzrə mövcud sətirlə tutuşdurmadan sırf
   `'mgs-'.$sourceId` ilə seçirdi.

### Nə quruldu (Cisco CLI-discovery)

- Yeni `tools/cisco-cli-discovery.py` — `hsgq-cli-discovery.py`-nin eyni
  paramiko interaktiv-SSH naxışı, amma exploratory `?`-menyu gəzintisi
  YOXDUR (IOS `show` sintaksisi standartdır, canlı kəşfə ehtiyac yoxdur):
  bağlan, `terminal length 0`, best-effort `enable`, 6 sabit `show` əmri.
- `mgs-integration-lib.php`: `app_mgs_cisco_cli_discovery_probe()` (HSGQ
  probe-un eyni strukturu), `app_mgs_cisco_derived_command_map_from_
  discovery()` (`is_cisco_ios` təsdiqləndikdə `app_mgs_default_device_
  profiles()`-in artıq nəzərdən keçirilmiş sabit komanda dəstini qaytarır).
  `CISCO` registry-ə əlavə edildi.
- **Yan-tapıntı düzəlişi**: backup snapshot payload-ının `discovery`
  bölməsi HSGQ-nun sahələrinə sərt kodlanmışdı (Cisco-nun `outputs`/
  `hostname`/`is_cisco_ios` sahələri səssizcə itəcəkdi) — `'raw' =>
  $discovery` əlavə edildi, HSGQ-nun mövcud sahələri toxunulmadı.
- Yeni `tools/cisco-cli-discovery-smoke.php` — HSGQ-nun eyni "əlçatmazdırsa
  səliqəli skip, `REQUIRE_CISCO_LIVE=1` ilə məcbur et" naxışı.

### Nə düzəldildi (Faza 2 dublikat buqu)

- `tools/import-mgs-node-data.php`-ə `mgs_import_resolve_device_key()`:
  əvvəlcə öz keçmiş idxalını, sonra native (qeyri-`mgs-`) sətri IP üzrə
  axtarır; native tapılarsa `vendor`/`model`/`adapter_key` toxunulmadan
  yalnız `features_json.legacy_mgs_import` cross-reference əlavə olunur.
  Alarm/ticket/ONT idxalı eyni "canonical key map"-dən istifadə etməyə
  keçirildi. Əlavə tapılan buq: `mgs_alarm_events` upsert-inin UPDATE
  bəndində `object_id` yox idi (düzəldildi — əks halda cihaz key-i düzəlsə
  belə mövcud alarm sətirləri köhnə object_id-ni saxlayacaqdı).
- 7 dublikat sətir silindi, tam idxal yenidən işlədildi:
  `merged_into_native: 8`, `created: 0`. 730 ONT sətri indi düzgün
  `olt-hsgq-kurdexani-21`-ə bağlıdır (əvvəlki səhv `mgs-olt-fd3e4e73` yox).
  3 ardıcıl `--apply` icrası ilə tam idempotentlik təsdiqləndi (device
  count sabit 13).

### İşlədilən testlər

- `php -l` təmiz: `mgs-integration-lib.php`, `import-mgs-node-data.php`,
  hər iki yeni smoke, `full-system-regression.php`.
- `python -m py_compile cisco-cli-discovery.py`: təmiz. Pure-function unit
  testlər (`last_prompt`/`looks_like_cisco_ios`/`normalize_output`): keçdi.
- `app_mgs_cisco_cli_discovery_probe()` əlçatmaz portla əl ilə test edildi:
  təmiz `SSHException` xətası, PHP çökməsi/asılıb-qalma yoxdur.
- `tools/cli-discovery-vendor-registry-smoke.php`: keçdi
  (`supported_vendors: [HSGQ, CISCO]`).
- `tools/cisco-cli-discovery-smoke.php`: gözlənilən `management_path_down`
  səbəbi ilə səliqəli `skipped` (real Cisco cihazı bu maşından əlçatan
  deyil — eyni HSGQ testinin qəbul etdiyi məhdudiyyət).
- `tools/import-mgs-node-data.php --apply` 3 dəfə ardıcıl: idempotentlik
  təsdiqləndi (`created: 0` hər dəfə).
- Əl ilə DB doğrulaması: 0 dublikat IP (əvvəlki 7-dən başqa, sonuncu
  `172.16.5.241` cütlüyü mənim işimlə əlaqəsiz köhnə smoke-test qalığıdır,
  toxunulmadı), `legacy_mgs_ont_inventory` 730/730 `olt-hsgq-kurdexani-21`-ə
  bağlı, `mgs_alarm_events.object_id` 10 unikal dəyər (7 native + 3
  legitim-öz-key).
- `tools/billing-ui-smoke.php --render=noc-devices.php`: dublikat-təmizlikdən
  sonra fatal/parse xətası yoxdur.
- `tools/full-system-regression.php` bu pəncərədə arxa planda işə salındı;
  nəticə növbəti mesajda təsdiqlənəcək.

### Qalan iş

Aruba/HP/HPE/Huawei-switch portlanmadı (real avadanlıq yoxdur). Cisco üçün
real hardware təsdiqi (HSGQ-nun `hardware_validated` kimi) hələ yoxdur, amma
bu adapter yalnız read-only `show` əmrləri işlədir (config dəyişikliyi
yoxdur), risk profili aşağıdır.

Commit/push edilmədi, production-a yazma/deploy olmadı, real Cisco/şəbəkə
cihazına heç bir sorğu getmədi (yalnız əlçatmaz-hədəf testi).

## 2026-08-21 (davam) - MGS konsolidasiya Faza 4: söndürmə planı (yalnız plan)

İstifadəçi: "Faza 4-ə başla: Node app-ın söndürülməsini planlaşdır" — açıq
şəkildə "planlaşdır" sözü işlədilib, "et"/"başla icraya" yox. Ona görə bu
pəncərədə YALNIZ tədqiqat + plan yazıldı, **heç bir söndürmə addımı icra
edilmədi**.

### Tədqiqat

- `portal-bridge.php` tam oxundu: İKİ hədəfi var — `target=olt` (Node MGS,
  bu Faza-nın mövzusu) VƏ `target=billing` (tamamilə ayrı,
  `billing.superoptic.net` köhnə billing sistemi, `app_portal_billing_
  handoff_url()`). Bu ayrım vacibdir: Faza 4 planı yalnız `olt` qoluna
  aiddir, `billing` qoluna toxunulmamalıdır.
- Scheduled task statusu yenidən yoxlanıldı: `SuperOptic MGS Weekly Backup`
  hələ də aktiv planlaşdırılıb (növbəti icra 2026-08-23) və hələ də
  **fail** olur (`LastTaskResult=2147946720`, Faza 0-da tapılmış eyni
  paylaşma xətası, düzəldilməyib). `SuperOptic MGS Stack Autostart`
  Faza 0-dan bəri artıq Node-u başlatmır.
- `D:\xampp\htdocs\oltidareetme` ölçüsü: 800.9 MB. `olt_control_center`
  DB ölçüsü: 532.7 MB. Ehtiyat nüsxə planlaması üçün.
- `config.local.php` VƏ `config.local.production.example.php` yoxlanıldı:
  hər ikisi `olt_sso_entrypoint`-i real `mgs.superoptic.net` ünvanına və
  real paylaşılan sirrə bağlayır. **Bu, production-un real vəziyyətini
  TƏSDİQLƏMİR** — ya hələ də canlı bir Node prosesi var, ya da sadəcə
  istifadə olunmayan konfiqurasiya qalığıdır. Bu lokal maşından production
  serverinə heç bir görünürlüyüm/girişim yoxdur.

### Yazılan plan (`docs/39_MGS_NODE_CONSOLIDATION.md`, Faza 4 bölməsi)

3 ön şərt (Faza 1-3 bağlılığı ✅, real istifadəçi soak-period təsdiqi ❓,
production statusu ❓) + 6 mərhələli addım, geri-dönüşlülüyə görə artan
sırada:

1. Ehtiyat nüsxə (DB dump + qovluq arxivi, kənar yerdə) — HƏR ŞEYDƏN ƏVVƏL.
2. Lokal scheduled task-ları söndür (Weekly Backup artıq fail olur).
3. SSO körpüsünü söndür — `config.local.php`-də `olt_sso_entrypoint`-i
   boşaltmaq kifayət edir, mövcud kod artıq bunu graceful fallback edir
   (`app_portal_olt_handoff_url()`), kod dəyişikliyi lazım olmaya bilər.
4. Production Node prosesini dayandır — mənim əlaqəm xaricində, YALNIZ
   istifadəçi production statusunu təsdiqlədikdən sonra.
5. `oltidareetme` qovluğunu arxivləşdir (köçürmə, silmə YOX).
6. `olt_control_center` DB-nin taleyi — DROP tövsiyə OLUNMUR, sadəcə
   dayandırılmış/adı dəyişdirilmiş saxlanmalı.

### Bu pəncərədə icra edilən

Heç nə. Tədqiqat yalnız `Get-ScheduledTask`/`Get-ScheduledTaskInfo` (read-only),
`mysqldump` YOX (yalnız `information_schema` ölçü sorğusu), fayl ölçüsü
sorğusu (`Get-ChildItem -Recurse | Measure-Object`, read-only). `oltidareetme`
qovluğu, `olt_control_center` bazası, scheduled task-lar, `config.local.php`
— hamısı toxunulmadan qalıb.

Commit/push edilmədi, production-a heç bir təmas olmadı.

## Claude continuation - Faza 4 icrası (2026-08-21, davam)

İstifadəçinin açıq təsdiqi ilə ("Bəli, addım 1-i icra et — ehtiyat nüsxə
götür ardınca digər addımları icra et avtomatik") yuxarıdakı plan icra
edilməyə başlandı.

### Addım 1 — Ehtiyat nüsxə (icra edildi, doğrulandı)

- `D:\SuperOptic-MGS-Backup-20260821\olt_control_center-20260821.sql.gz` —
  `mysqldump` tam dump, gzip ilə sıxılmış, 24.5 MB. "Dump completed" trailer
  ilə tamlıq təsdiqləndi, 83 cədvəl.
- `D:\SuperOptic-MGS-Backup-20260821\config.local.php.bak-20260821` —
  `config.local.php`-in Addım 3-dən ƏVVƏLKİ tam nüsxəsi (orijinal
  `olt_sso_entrypoint`/`olt_sso_shared_secret` daxil).

### Addım 2 — Scheduled task-lar (icra edildi, doğrulandı)

- `SuperOptic MGS Weekly Backup` → `Disable-ScheduledTask` ilə söndürüldü.
  Bu sessiyanın sonunda `Get-ScheduledTask` ilə yenidən yoxlanıldı: `State =
  Disabled`, təsdiqləndi.
- `SuperOptic MGS Stack Autostart` → dəyişdirilmədi (Faza 0-dan bəri öz
  defolt davranışı artıq Node-u başlatmır, `State = Ready` qalması gözlənilən
  və zərərsizdir).

### Addım 3 — SSO körpüsü (icra edildi, doğrulandı)

`config.local.php`-də `olt_sso_entrypoint` və `olt_sso_shared_secret` boş
sətrə (`''`) dəyişdirildi, izahlı şərh əlavə edildi (orijinal dəyərlər
backup-da saxlanılır, bax Addım 1). Birbaşa PHP daxilində
`app_portal_olt_handoff_url()` çağırılıb təsdiqləndi: entrypoint boş
olduğu üçün funksiya `$safeFallback`-a (`noc-devices.php?view=devices`)
düşür — mövcud, əvvəldən yazılmış təhlükəsiz davranış, əlavə kod
dəyişikliyi tələb olunmadı. `portal-bridge.php`-in `target=billing` qoluna
(`app_portal_billing_handoff_url()`) toxunulmadı.

### Addım 5 — Qovluq arxivi (qismən icra edildi)

`Move-Item` iki dəfə "used by another process" xətası ilə uğursuz oldu.
Səbəb axtarışında `D:\xampp\htdocs\oltidareetme\start-mgs.cmd`-i işə salmış
orphan bir `cmd.exe` prosesi (PID 19300, 2026-08-19 saat 07:58:38-də
başlamış — Faza 0 düzəlişindən əvvəlki avtomatik başlanğıcdan qalma,
heç bir `node.exe` övladı yox idi) tapıldı. İstifadəçinin açıq təsdiqi ilə
("Bəli, prosesi dayandır və davam et") `Stop-Process -Id 19300 -Force` icra
edildi. Bundan sonra `Move-Item` YENƏ eyni xəta ilə uğursuz oldu — demək
başqa bir handle da var idi.

`robocopy /E /MOVE /R:2 /W:2` ilə əvəz edildi: **Dirs: 1637/1637, Files:
13471/13471, Bytes: 800.93m/800.93m, FAILED: 0** — bütün real məzmun
`D:\xampp\htdocs\_archived\oltidareetme-20260821`-ə köçürüldü, sonradan
`Get-ChildItem -Recurse -Force` ilə orijinal yolun tam boş qaldığı (0 sətir)
təsdiqləndi, üstəlik ayrıca `du -sh` yoxlaması arxiv ölçüsünün (826M)
robocopy-nin öz hesabatı ilə uyğun olduğunu göstərdi.

Yalnız orijinal, artıq boş `D:\xampp\htdocs\oltidareetme` qovluq
**qabığının özü** silinə bilmədi (`robocopy`-nin öz "ERROR 32 Deleting
Source Directory" xətası, sonra ayrı-ayrı `Remove-Item`/`cmd rmdir` cəhdləri
də eyni "used by another process" xətası ilə uğursuz oldu — həm qeyri-admin,
həm də istifadəçinin özünün açdığı admin PowerShell pəncərəsindən). Səbəb
axtarışı: heç bir proses command-line-ında `oltidareetme` görünmür, açıq
Explorer pəncərəsi yoxdur, `handle.exe` bu maşında quraşdırılmayıb (endirmə
təklif edilmədi — icazə tələb edən addım, bu sessiyada soruşulmadı).
Windows Search Indexer (`SearchIndexer.exe`) ən böyük şübhəli namizəd idi —
`Restart-Service WSearch` (əvvəlcə qeyri-admin sessiyadan, uğursuz oldu:
icazə xətası; sonra istifadəçinin öz admin pəncərəsindən, uğurla işlədi) —
amma bundan sonra da eyni kilid xətası davam etdi, deməli Search Indexer
səbəb deyilmiş. Windows Defender real-time skanı (`MsMpEng.exe`, işlək
tapıldı) ən son qalan namizəddir, amma bunu doğrulamaq/söndürmək
təhlükəsizlik ayarına toxunmaq deməkdir — mənim qaydalarımla bu qadağandır,
sınanmadı.

**İstifadəçinin qərarı** ("oltidareetmeni sonra silərik, növbəti mərhələyə
başla"): bu boş qovluq qabığının silinməsi sonraya saxlanıldı. Funksional
təsiri yoxdur — bütün real data artıq arxiv yolundadır və doğrulanıb.

### Addım 4 və 6 — qəsdən icra edilmədi

Plana uyğun olaraq: Addım 4 (production Node prosesini dayandırmaq) bu
maşından mümkün deyil və icra edilmədi. Addım 6 (`olt_control_center`
DB-nin taleyi) qəsdən açıq saxlanıldı, DROP edilmədi/dəyişdirilmədi.

### Doğrulama

`tools/full-system-regression.php` bu addımlardan sonra yenidən işlədildi:
**344 pass / 0 fail / 0 timeout / 6 skip**. Hesabat:
`tmp/full-system-regression-20260821-103005.json`. `docs/39_MGS_NODE_CONSOLIDATION.md`
Faza 4 bölməsi və "Növbəti sessiya üçün" bölməsi bu icranın nəticəsinə uyğun
yeniləndi.

### Görülməmiş production əməliyyatları (təsdiq)

- Commit/push edilmədi.
- Production `mgs.superoptic.net`-ə heç bir toxunuş olmadı.
- `olt_control_center` DB-si DROP edilmədi/adı dəyişdirilmədi.
- Windows Defender/təhlükəsizlik ayarlarına toxunulmadı.
- Real SMS/Telegram/WhatsApp göndərişi, canlı RADIUS/OLT/router əməliyyatı
  icra edilmədi.
