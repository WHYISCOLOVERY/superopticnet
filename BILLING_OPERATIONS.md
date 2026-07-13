# SuperOptic Billing Operations

Bu sənəd billing axınının production-da təhlükəsiz işlədilməsi üçün qısa əməliyyat qaydasıdır.

## Əsas Axın

- Müştərinin aktiv günləri `billing_services.expires_at` əsasında hesablanır.
- Vaxtı bitmiş aktiv xidmətlər maintenance prosesi ilə `suspended` vəziyyətinə keçirilir.
- RADIUS sinxronu zamanı vaxtı bitmiş və ya deaktiv müştərilər üçün `radcheck` daxilində `Auth-Type := Reject` yazılır.
- Ödəniş qəbul ediləndə məbləğ boş və ya `0` verilibsə, sistem internet paket qiyməti + aktiv əlavə xidmətləri avtomatik hesablayır.
- Aktiv əlavə xidmətlərə IPTV, TV, SIP phone, static IP və digər xidmətlər daxildir.

## Lokal Yoxlama Komandaları

Windows/XAMPP:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-maintenance.php --limit=25
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-operation-check.php
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\optimize-billing-performance.php
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-flow-smoke.php
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-ui-smoke.php
```

Dry-run default rejimdir. Birinci komanda DB-ni dəyişmir, yalnız namizədləri göstərir.

## Tətbiq Komandaları

Gün sayını yeniləmək və vaxtı bitənləri dayandırmaq:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-maintenance.php --apply --limit=1000
```

Test aralığında RADIUS sinxronunu da işlətmək:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-maintenance.php --apply --limit=1000 --sync-radius --range=1100000:1100040
```

## Ubuntu Cron Nümunəsi

15 dəqiqədən bir gün/vaxt və suspension yoxlaması:

```cron
*/15 * * * * /usr/bin/php /var/www/superopticnet/tools/billing-maintenance.php --apply --limit=1000 >> /var/log/superoptic-billing-maintenance.log 2>&1
```

Gecə RADIUS sinxronu:

```cron
30 3 * * * /usr/bin/php /var/www/superopticnet/tools/billing-maintenance.php --apply --limit=5000 --sync-radius >> /var/log/superoptic-radius-sync.log 2>&1
```

## Smoke Test

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-flow-smoke.php
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-ui-smoke.php
```

Bu test:

- müvəqqəti test müştərisi yaradır;
- internet paketi və aktiv əlavə xidmət əlavə edir;
- vaxtı bitmiş halda RADIUS reject gözləyir;
- ödəniş qəbul edib tam aylıq məbləği yoxlayır;
- aktiv RADIUS cavablarını yoxlayır;
- test məlumatlarını silir.

## Provider/Terminal Ödəniş Importu

CSV faylda ən azı müştəri kodu və məbləğ olmalıdır. Tövsiyə olunan kolonlar:

```csv
subscriber_code,amount,transaction_id,external_id,paid_at
1200000,25.00,EMANAT-123456,emanat-EMANAT-123456,2026-05-20 09:30:00
```

Dry-run:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\import-provider-payments.php --file=D:\imports\payments.csv --method=emanat --days=0
```

Real tətbiq:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\import-provider-payments.php --file=D:\imports\payments.csv --method=emanat --days=0 --apply
```

Eyni axın UI-da da var:

```text
Billing -> Ödəniş siyahısı -> Provider/terminal ödəniş importu
```

Qaydalar:

- `external_id` təkrar ödənişin ikinci dəfə tətbiq olunmasının qarşısını alır.
- `--days=0` seçilərsə, müştərinin tarifindəki gün sayı, yoxdursa 30 gün istifadə edilir.
- Müştəri tapılmayan və məbləği səhv olan sətrlər tətbiq edilmir, nəticədə ayrıca göstərilir.
- `--apply` olmadan import yalnız yoxlama aparır.

## Təhlükəsizlik Qaydası

- `--apply` olmadan heç bir real dəyişiklik edilmir.
- `--sync-radius` yalnız ayrıca verildikdə RADIUS cədvəllərinə sinxron edir.
- Kütləvi production işdən əvvəl əvvəlcə `--limit=25` ilə dry-run nəticəsinə baxılmalıdır.
- Real core routerdə aktiv minlərlə istifadəçi olduğu üçün RADIUS dəyişiklikləri əvvəl test aralığında yoxlanmalıdır.
# Production readiness check

Before cutover to the real gateway/RADIUS environment, run:

```bash
php tools/production-readiness-check.php --production
php tools/validate-nginx-gateway.php
```

Use `config.local.production.example.php` as the production configuration
template. The readiness check intentionally blocks production mode when
`app_url`, `ticket_close_base_url`, SSO callbacks or myGov redirects still point
to localhost/private IPs, because those links would break Telegram ticket close,
customer portal and admin handoff flows.

## Communication provider smoke tests

Queued billing/customer messages are processed by:

```bash
php tools/process-communication-queue.php --limit=50
php tools/process-communication-queue.php --apply --limit=50
```

Provider adapters can be tested without creating tickets, alarms or DB message
rows:

```bash
php tools/test-communication-provider.php --channel=telegram
php tools/test-communication-provider.php --channel=sms --destination=994XXXXXXXXX
php tools/test-communication-provider.php --channel=whatsapp --destination=994XXXXXXXXX
```

The provider test is dry-run by default. Add `--apply` only when a real
`TEST MESAJI` should be sent through the configured provider.

## Billing UI smoke test

Run this after billing code or UI changes:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-ui-smoke.php
```

The test renders dashboard, customer list, customer 360 profile, cash payment,
payments, statistics, RADIUS, message center, IP pool and billing check pages
with an employee session. It fails on PHP fatal/warning/notice output, auth
redirects, missing list/profile markers, or empty critical page render output.

## Billing performance indexes

After large import or schema migration, run:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\optimize-billing-performance.php
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\optimize-billing-performance.php --apply
```

The dry-run lists missing indexes. The apply mode creates only missing safe
read indexes for customer lists, payment extracts, RADIUS views, ticket/message
counts and dashboard maintenance widgets. It does not change customer balances,
RADIUS rows or payment data.

## Billing page speed notes

- Normal page requests use `data/db-bootstrap-ok.json` to skip heavy schema
  migration/bootstrap checks after the DB is ready.
- Set `db_bootstrap_mode => 'always'` only during schema development; keep the
  default cached mode for production-like use.
- All admin/billing pages use local `css/fontawesome-lite.css` instead of the
  external FontAwesome CDN, so weak internet does not block page rendering.
