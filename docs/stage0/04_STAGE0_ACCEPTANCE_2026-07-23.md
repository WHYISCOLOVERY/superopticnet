# Stage 0 yekun qebul - 2026-07-23

## Qerar

`STAGE 0 CODE AND LOCAL ACCEPTANCE: PASSED`

Stage 0-da nezere tutulan go-live blocker kod deyisiklikleri, migration-lar, permission, idempotency, failure-recovery, rollback contracti ve lokal E2E regressiya qebul olunub. Bu qerar real router/OLT ve xarici provider cutover-inin bitdiyini bildirmir; onlar asagida ayrica kontrollu canli qapi kimi saxlanir.

## Final test neticesi

- Hesabat: `tmp/full-system-regression-20260723-013125.json`
- Pass: `179`
- Fail: `0`
- Timeout: `0`
- Controlled external skip: `4`
- Test muddeti: `257.7 saniye`
- Muveqqeti HTTP test hesablari cleanup-dan sonra: `0`

Kritik neticeler:

- `local_http_security_fixture`: pass
- `employee_login_compat`: pass
- `http_admin_api_csrf`: pass
- `http_customer_search_rate_limit`: pass
- `local_http_security_fixture_cleanup`: pass
- `billing_payment_outbox_recovery`: pass
- `noc_service_authorization`: pass
- `finance_render_readonly`: pass
- `production_fallback_guard`: pass
- `production_readiness` lokal profil: pass

HTTP login testleri Google reCAPTCHA bypass etmir. Izole localhost serveri Google secret-i almadan lokal nonce, hesablamali cavab, confirmation ve minimum gozleme vaxtini real formada tamamlayir.

## Secret cutover neticesi

`tools/run-stage0-local-verification.ps1` movcud credential-lari deyerleri gostermeden yalniz child process environment-ine kecirib:

- legacy secret source: `18`
- migration-ready reference: `18`
- not ready: `0`
- unmapped: `0`
- exposed value: `0`

`tools/secret-cutover-smoke.php` uzre config scrub, runtime setting scrub, swap-file cleanup ve inventory redaction yoxlamalarinin hamisi kecib.

Bu lokal tesdiq `process-only`-dir. Ubuntu production qurulumunda eyni reference-lar `deploy/set-superoptic-secrets.sh` ile service environment faylina yazilmali, owner/permission mehdudlasdirilmali ve service restart-dan sonra visibility preflight kecmelidir. Yeni reference islek olmadan legacy credential silinmir.

## Tetbiq olunan migration-lar

- `db/migrations/20260719_0001_finance_schema.php`
- `db/migrations/20260719_010_stage0_payment_outbox.php`

Her ikisi `tools/run-portal-migrations.php --list` neticesinde `applied` statusundadir.

## Baglanan blocker-ler

1. NOC read/write/action servislerinde rol ve object-scope authorization.
2. Finance GET/render zamani schema ve seed yazisinin dayandirilmasi.
3. Finance schema ve default seed-in versiyali migration/CLI prosesine cixarilmasi.
4. Production demo customer ve `TEST MESAJI` fallback-lerinin legvi.
5. Billing payment idempotency key ve correlation ID.
6. RADIUS ucun DB transaction-dan ayri outbox/job modeli.
7. Retry, failure attempt, manual reconciliation, reversal ve compensation.
8. Eyni payment/action/job-in retry zamani ikinci defe yaranmasinin bloklanmasi.
9. Login compatibility, API CSRF ve customer-search rate-limit E2E testleri.
10. Muveqqeti test hesabinin ve test serverinin avtomatik cleanup-i.

## Real inteqrasiya yoxlamalari

- FreeRADIUS lifecycle: `19/19 pass`. Expired reject, payment-den sonra aktivlesme, IP assignment, accounting start/stop, parol ve paket deyisikliyi, avtomatik expiry suspend ve yeniden aktivlesme yoxlanib.
- Telegram: `8/8` konfiqurasiya olunmus bot profili real API gonderisi ile cavab verib. Finance profilinin ilk cehdi ugursuz olub, kontrollu retry ugurla bitib.
- SMS: `2320025` ucun bir real cehd provider terefinden `(ERRGTW0002) Session expired please login` ile redd edilib. Provider message ID yaranmayib ve SMS qebul olunmayib; tekrar real gonderis edilmeyib.
- Sirket WhatsApp provider-i: bridge cavab verir, amma session `qr` veziyyetindedir ve `connected=false`. Yeni QR fiziki telefondan oxudulmadan real gonderis testi kecmis sayilmir.
- Router CoA: DB-deki real NAS `85.132.57.218` ve router registry secret reference ile fake-session `Disconnect-Request` gonderilib. UDP `3799` uzre cavab alinmayib (`timeout_waiting_for_radius_response`, `response_verified=false`). Auth/accounting islekdir, lakin CoA hele qebul edilmeyib.
- Huawei MA5608T ve MA5800-X7: resmi Huawei command reference ve troubleshooting menbelerine bagli read-only command kataloglari yaradilib; standart SNMPv2-MIB/IF-MIB/ENTITY-MIB OID-leri elave olunub. Private Huawei OID-leri konkret release MIB paketi ve real cihaz yoxlamasi olmadan texmin edilmeyib. Adapter smoke: `11/11 pass`; hardware validation hele gozleyir.

## Production data temizliyi

- Ilkin tesdiqli temizlemede `611` test musteri, `238` test/bot isci, `226` saxta stok mehsulu, `1` saxta asset modeli ve `85` saxta asset silinib.
- Final regression-in yaratdigi `42` test musteri, `15` test isci, `3` stok mehsulu, `1` asset modeli ve `3` asset ayrica silinib.
- Final dry-run: musteri `0`, isci `0`, stok mehsulu `0`, asset modeli `0`, asset `0`, tarif `0`; elaqeli setir `0`.
- Qorunan real hesablar: musteri `2320025` ve super-admin `s.abdullayev@superoptic.net` aktiv qalib.
- Son backup: `tmp/pre-production-cleanup-20260723-013227/superoptic_portal.sql`, `277088893` bayt, SHA-256 `AABE4B65FD322B4AABA437B707DBEFDCE36889AD5B550C407D97D573CA098476`.
- Apply manifest: `tmp/production-test-cleanup-20260723-013301/manifest-before.json`; cleanup audit action `113873`, immutable ledger `2528`.
- Sifir netice manifesti: `tmp/production-test-cleanup-20260723-013310/manifest-before.json`.

## Billing veziyyetleri

- `payment_recorded`
- `service_action_pending`
- `service_action_processing`
- `service_action_completed`
- `service_action_retrying`
- `manual_reconciliation_required`
- `reversed`

## Rollback yoxlamasi

- Payment outbox migration-i 22 iyul testinde rollback edilib, cedvellerin legvi yoxlanib ve yeniden ugurla apply olunub.
- Finance rollback dolu `bonus_rule_settings` cedvelinde qesden fail-closed olub ve data silmeyib.
- Payment smoke fixture cleanup ikinci dry-run-da `0/0/0` neticesi verib.
- Cari run muveqqeti HTTP hesabini silib; DB-de `stage0-http-*` hesabi qalmayib.

## Controlled external test qapilari

Asagidakilar Stage 0 kod failure-i deyil ve real hedef olmadan avtomatik icra edilmemelidir:

1. `real_router_coa_disconnect` - real NAS-a fake-session transport cehdi edilib, amma UDP 3799 cavabi alinmayib. Router dynamic-author client/source ACL ve FreeRADIUS-dan gonderis yolu duzeltilmelidir; sonra secilmis real test session ile yoxlanmalidir.
2. `real_olt_ont_restart` - management VLAN, canli OLT ve tesdiqli test ONT teleb edir.
3. `real_sms_send` - tesdiqli `2320025` hedefinde provider session expired cavabi alindi. Yeni islek public/private session reference verildikden sonra tek cehdle tekrar yoxlanmalidir.
4. `real_whatsapp_send` - provider bridge aktivdir, amma sirket nomresinin QR session-i hele connected deyil.

Telegram real gonderis qapisi kecib. FreeRADIUS AAA lifecycle kecib. CoA, WhatsApp, SMS ve Huawei hardware qapilari `controlled pending` statusundadir ve production cutover-da saxta `passed` kimi gosterilmir.

## Deyisdirilen esas fayllar

Payment ve migration:

- `billing-payment-workflow-lib.php`
- `billing-cash-payment.php`
- `db.php`
- `db/migrations/20260719_0001_finance_schema.php`
- `db/migrations/20260719_010_stage0_payment_outbox.php`
- `tools/process-billing-payment-outbox.php`
- `tools/reconcile-billing-payment.php`
- `tools/run-portal-migrations.php`

NOC ve permission:

- `noc-authorization-lib.php`
- `mgs-integration-lib.php`
- `noc-devices.php`
- `noc-security.php`
- `operations-permission-lib.php`

Finance ve production fallback:

- `finance-schema-lib.php`
- `finance-operations-lib.php`
- `finance-bonus-lib.php`
- `finance-module-lib.php`
- `billing-ui.php`
- `message-center.php`
- `crm.php`
- `config.php`

Verification:

- `tools/billing-payment-outbox-smoke.php`
- `tools/noc-service-authorization-smoke.php`
- `tools/finance-render-readonly-smoke.php`
- `tools/production-fallback-guard-smoke.php`
- `tools/full-system-regression.php`
- `tools/admin-api-csrf-smoke.py`
- `tools/customer-search-rate-limit-smoke.py`
- `tools/run-stage0-local-verification.ps1`
- `tools/secret-source-inventory.php`
- `tools/secret-cutover-smoke.php`
- `tools/live-provider-status.php`
- `tools/production-test-data-cleanup.php`
- `tools/radius-live-coa-smoke.php`
- `tools/huawei-readonly-adapter-smoke.py`

Huawei vendor katalogu:

- `vendors/huawei/catalog.schema.json`
- `vendors/huawei/ma5608t-v800r019c10.json`
- `vendors/huawei/ma5800-x7-v100r024c10.json`
- `vendors/huawei/README.md`
- `tools/huawei-readonly-adapter.py`

## Son qebul meyari

Stage 0 kod ve lokal qebul meyarlari tam kecib. Stage 1 kod isine kecmek olar, amma real sirket cutover-i hele qebul edilmir. Ubuntu deploy zamani persistent secret visibility, SMS credential session-i, WhatsApp QR, router CoA/UDP 3799 ve Huawei hardware yoxlamalari ayrica cutover checklist-de baglanmalidir; onlar haqqinda saxta `passed` statusu verilmir.
