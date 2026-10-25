# SuperOptikNet — Deploy Checklist (server 85.132.57.219 + hosting)

Son yenilənmə: 2026-07-25. Bu sənəd produksiyaya keçid üçün addım-addım təlimatdır.

## Arxitektura
- **Hosting**: `superoptic.net` (publik sayt) + **müştəri paneli** (müştəri login edib öz məlumatını görür).
- **Server `85.132.57.219`** (`billing.superoptic.net` ora yönəlir): əsas sistem —
  daxili şəbəkədə OLT/switch/router idarəsi, terminal ödəniş qəbulu (API),
  müştəri aktiv/deaktiv, SMS, bütün emekdaş işi.

---

## 1. Server hazırlığı (85.132.57.219)
- [ ] PHP 8.x + extensions: `pdo_mysql`, `mbstring`, `json`, `curl`, `openssl`, `zip`.
- [ ] MySQL/MariaDB qurulub, `superopticnet` DB və istifadəçi yaradılıb.
- [ ] **OPcache aktiv** (produksiya performansı üçün vacib):
  ```ini
  opcache.enable=1
  opcache.memory_consumption=256
  opcache.max_accelerated_files=20000
  opcache.validate_timestamps=0   ; deploy zamanı reload lazım
  opcache.jit=tracing             ; PHP 8.1+ (optional)
  ```
- [ ] `realpath_cache_size=4096K`, `realpath_cache_ttl=600`.

## 2. Fayllar
- [ ] Kod server-ə köçürülüb (git pull / rsync). `whatsapp-bridge/node_modules` daxil edilməsin (server-də `npm ci`).
- [ ] **`data/` qovluğu web-server istifadəçisi üçün YAZILA BİLƏN** olmalıdır — KRİTİK.
  Migration/bootstrap stamp və cache oraya yazılır:
  `data/db-migrations-ok.json`, `data/db-bootstrap-ok.json`, `data/cache/`.
  Əks halda hər request-də migration probes işləyəcək (~200ms/səhifə yavaşlıq).
  ```bash
  chown -R www-data:www-data data/ && chmod -R 775 data/
  ```

## 3. Konfiqurasiya
- [ ] `config.local.production.example.php` → `config.local.php` kopyalanıb doldurulub:
  - [ ] DB host/port/name/user/pass
  - [ ] `portal_company_name`
  - [ ] SMS/WhatsApp provider açarları
  - [ ] Ödəniş provider (mPay/UP) HMAC/bearer açarları
- [ ] `AI_MODEL_PROVIDER=local`, lokal LLM endpoint (Ollama server-də — güclü RAM/GPU olan maşında).
- [ ] `.env` və `config.local.php` **git-ə düşməsin** (secrets).

## 4. Verilənlər bazası
- [ ] DB import edilib (dump).
- [ ] İlk açılışda migration avtomatik işləyəcək (stamp `data/`-a yazılacaq).
- [ ] Yoxla: `billing_debt_amount` generated sütunu + `idx_portal_accounts_debt` yarandı
  (performans üçün lazım). Yaranmayıbsa `db_bootstrap_mode` müvəqqəti `always` et, bir səhifə aç, geri qaytar.

## 5. Şəbəkə/təhlükəsizlik
- [ ] `billing.superoptic.net` → 85.132.57.219 DNS/reverse-proxy düzgündür.
- [ ] HTTPS sertifikatı (Let's Encrypt).
- [ ] Ödəniş API endpointləri yalnız terminal provider IP-lərinə açıqdır (firewall/allowlist).
- [ ] OLT/switch/router idarəsi loopback/daxili şəbəkə ilə məhdudlaşdırılıb.
- [ ] Admin/emekdaş paneli publik internetdən lazımsız açıq deyil (VPN/allowlist tövsiyə).

## 6. Ödəniş API smoke (deploy sonrası)
- [ ] `php tools/provider-api-smoke.php` → ok
- [ ] `php tools/provider-up-api-smoke.php` → ok (mPay terminal)
- [ ] Real terminaldan test ödənişi → `billing_payments`-ə oturur, status yenilənir, duplicate qorunur.

## 7. Funksional smoke
- [ ] `php tools/billing-flow-smoke.php` → exit 0
- [ ] `php tools/admin-inventory-copilot-ui-smoke.php` → status ok
- [ ] Login → dashboard, müştəri siyahısı, balanslar açılır və **sürətlidir**.
- [ ] Anbar → Təhvil/saha → "Anbar sənədləri — Çap" → sənəd çap olunur (imza yerləri ilə).

## 8. Performans yoxlaması (deploy sonrası)
- [ ] Dashboard təkrar açılışda <100ms (45s cache). İlk açılış (soyuq cache) bir dəfə ~1-2s normaldır.
- [ ] `data/cache/` dolur (billing_overview_*.json faylları görünür).
- [ ] Səhifələr 2-3 saniyə DEYİL — əks halda `data/` icazələrini yoxla (migration hər request işləyir).

## 9. Cron/worker (varsa)
- [ ] Ödəniş outbox worker (`billing_payment_outbox` emalı) cron-da işləyir.
- [ ] Communication/SMS queue worker cron-da.
- [ ] RADIUS/ONT status heartbeat işləyir.

## 10. Geri dönüş planı
- [ ] Deploy öncəsi DB dump alınıb.
- [ ] Köhnə kod versiyası saxlanıb (git tag / backup).
- [ ] Problem olarsa: kod geri, DB restore.

---

### Qeyd — performans düzəlişləri (2026-07-25)
Bu buraxılışda DB qatı hər request-də 229ms→6ms endirildi, dashboard 3084ms→~38ms (cache),
balances 2166ms→~1000ms (generated debt sütunu). Bunların hamısı `data/` yazıla bilməsindən
və migration-ın bir dəfə işləyib stamp-lanmasından asılıdır — 2-ci və 4-cü addımları mütləq yoxla.
