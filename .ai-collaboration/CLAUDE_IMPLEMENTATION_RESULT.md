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
