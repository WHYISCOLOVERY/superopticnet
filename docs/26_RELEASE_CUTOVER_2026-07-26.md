# 26 iyul 2026 sistem keçidi

## Məqsəd

Mövcud SuperOptik əməliyyatlarını 25 iyul 2026 tarixində dondurulmuş və yoxlanmış buraxılışa hazırlamaq, 26 iyul 2026 tarixindən etibarən yeni portal üzərindən idarə etmək.

## 16 iyul vəziyyəti

- Tam regressiya: `132 pass`, `0 fail`, `0 timeout`, `4 controlled skip`.
- Production readiness (production): `69/69` yoxlama keçib, kritik və warning xətası yoxdur; PHP `Asia/Baku`, tətbiq offset-i `+04:00`, DB offset-i `240` dəqiqədir.
- Real brauzer: 8 əsas modul `1440x1000` və `390x844` ölçülərində yoxlanıb; üfüqi daşma, mojibake və idarəetmə elementlərinin üst-üstə düşməsi tapılmayıb.
- Sahə ssenarisi: 6 texnik, 1 anbardar, 2 operator, 12 yeni müştəri, 12 modem quraşdırılması, 7 problem həlli, 6 bonus/maaş hesabı və audit izi uğurla yoxlanıb.
- Test məlumatları təmizlənib; dəyişdirilməz təhlükəsizlik audit qeydləri saxlanılıb.

## Buraxılışa daxil olan işlər

- Portal modulları rol əsasında ayrılıb: Maliyyə, HR, Anbar, Billing, AI, Mesaj mərkəzi, Statistika və Şəbəkə/NOC.
- NOC monitorunda saxlanılan profil, cihaz status rəngləri, mobil görünüş, scan nəticəsinin seçimlə monitora əlavə olunması və modal axını standartlaşdırılıb.
- Köhnə siyahı səhifələrində axtarış, seçim və tarix filtrləri tam səhifə yenilənmədən işləyir; köhnə sorğular ləğv olunur və back/forward history qorunur.
- Maliyyə, HR və Anbar modallarında fokus dövrü, tək scroll sahibi və mobil ölçülər tətbiq olunub.
- Cədvəllər mobil kart görünüşünə keçir, 25/50/100 səhifələmə müqavilələri qorunur.
- Azərbaycan dili status/rol/əməliyyat terminləri mərkəzi xəritədən göstərilir; köhnə kodlama qüsurları üçün təhlükəsiz bərpa testi mövcuddur.
- Super Admin idarəetməsi və dəyişdirilməz audit jurnalı qorunur.

## Canlı keçidi bloklayan gate-lər

Bu dörd maddə bağlanmadan 26 iyul keçidi `GO` sayıla bilməz:

1. **VLAN 550 management path**: router/management yolu qoşulmalı, aktiv edilməli və cihaz probe yenidən keçməlidir.
2. **Canlı OLT/ONT optik oxu**: management yolu açıldıqdan sonra yalnız seçilmiş test OLT/ONT üzərində signal və inventar oxusu təsdiqlənməlidir.
3. **WhatsApp Web bridge**: worker `connected` vəziyyətinə gəlməli, hər nömrə QR ilə bağlanmalı və monitor chat ingest testi keçməlidir.
4. **Vaxt zonası uyğunluğu**: PHP runtime, MySQL/MariaDB sessiya və sistem vaxt zonası, queue/cron/worker prosesləri və əməliyyat sistemi `Asia/Baku` ilə eyni vaxtı göstərməlidir. Audit, ödəniş və inventar üzrə nümunə qeydlərin vaxtı müqayisə edilmədən `GO` verilə bilməz.

Canlı təsirə malik, nəzarətli icra tələb edən maddələr:

- FreeRADIUS CoA/disconnect yalnız seçilmiş test sessiyasında.
- Bir ödənişli SMS yalnız təsdiqlənmiş test nömrəsinə.
- Telegram live polling yalnız düzgün qrup/chat təsdiqindən sonra.
- Real OLT restart və konfiqurasiya yazısı bu buraxılışa daxil deyil.

## Tarix planı

| Tarix | Məcburi nəticə |
|---|---|
| 16-20 iyul | Xarici bloklayıcıların sahibləri təyin edilir; VLAN 550/router və WhatsApp bridge hazırlanır. |
| 21 iyul | Seçilmiş test müştəridə ödəniş -> RADIUS aktivləşmə -> IP -> müddət bitməsi ssenarisi. |
| 22 iyul | DB və tətbiq backup/restore məşqi; restore müddəti və fayl hash-i qeydə alınır. |
| 23 iyul | Rol UAT: Super Admin, admin, maliyyə, HR, anbardar, operator, texnik və NOC. |
| 24 iyul | Release candidate dondurulur; yalnız blocker düzəlişi qəbul edilir. |
| 25 iyul | Son full regression, production readiness, live cutover gate, PHP/DB/worker `Asia/Baku` yoxlaması, DB backup və `GO/NO-GO` protokolu. |
| 26 iyul | `GO` olduqda keçid; ilk iş günü audit, ödəniş, Radius, mesaj və inventar monitorinqi. |

## 25 iyul son yoxlama əmrləri

```powershell
D:\xampp\php\php.exe tools\full-system-regression.php
D:\xampp\php\php.exe tools\production-readiness-check.php --production
D:\xampp\php\php.exe tools\live-cutover-checklist.php --strict --markdown
D:\xampp\php\php.exe tools\validate-nginx-gateway.php
```

Hamısı uğurlu olmadan tətbiq versiyası və verilənlər bazası dəyişdirilməməlidir.

Əmrlərin nəticələrinə əlavə olaraq `GO` protokolunda PHP runtime, DB və bütün worker/cron proseslərinin `Asia/Baku` vaxt zonasında olduğu, eyni test əməliyyatının vaxtının tətbiq, DB və audit jurnalında uyğun göründüyü ayrıca imza ilə təsdiqlənməlidir.

## Keçid ardıcıllığı

1. Köhnə sistemə yazma dayandırılır və başlanma saatı audit jurnalında qeyd olunur.
2. MySQL tam backup, tətbiq arxivi və konfiqurasiya backupı yaradılır; hash-lər saxlanılır.
3. PHP runtime, DB və worker/cron proseslərində `Asia/Baku` vaxt zonası yoxlanılır; test audit qeydinin vaxtı bütün qatlarda müqayisə edilir.
4. Release commit/tag deploy edilir, DB migration bir dəfə icra olunur.
5. Super Admin giriş, rol görünüşü və audit jurnalı yoxlanılır.
6. Bir test müştəridə ödəniş, Radius açılma, IP, paket və müddət yoxlanılır.
7. Bir anbar qəbulu, əməkdaşa təhvil, müştəriyə quraşdırma və geri qaytarma yoxlanılır.
8. SMS/Telegram/WhatsApp yalnız təsdiqlənmiş test ünvanlarında yoxlanılır.
9. Maliyyə yekunu köhnə sistemin eyni tarix snapshotı ilə müqayisə olunur.
10. Nəticələr audit jurnalına əlavə edilir və sistem yazmaya açılır.

## Rollback

Aşağıdakılardan biri baş verərsə yazma dayandırılır və rollback başlanır:

- Admin və ya əsas rol girişləri işləmir.
- Ödəniş DB-yə düşmür, təkrarlanır və ya müştərinin müddəti səhv hesablanır.
- Test müştəri ödənişdən sonra Radius-da açılmır və ya düzgün IP/paket almır.
- Maliyyə və ya inventar balansı keçid snapshotı ilə uyğun gəlmir.
- Audit jurnalı kritik əməliyyatı qeyd etmir.

Rollback ardıcıllığı:

1. Yeni sistemə yazma dayandırılır və rollback başlanma vaxtı qeyd olunur.
2. DB restore-dan əvvəl keçiddən sonra yaranmış ödəniş, inventar hərəkəti və audit əməliyyatlarının delta ixracı yaradılır; fayl sayı, məbləğ, hash və son əməliyyat vaxtı qeydə alınır.
3. DNS/Nginx/upstream trafik marşrutu köhnə sistemə qaytarılır və sağlamlıq yoxlaması ilə istifadəçi trafikinin köhnə sistemə çatdığı təsdiqlənir.
4. Tətbiqin əvvəlki tag-i və worker-lərin əvvəlki versiyası aktiv edilir; lazım olduqda pre-cutover DB restore olunur.
5. Delta ödəniş və inventar əməliyyatları nəzarətli şəkildə replay/reconciliation prosesindən keçirilir; audit əməliyyatları silinmədən əlaqələndirilir və fərqlər ayrıca protokollaşdırılır.
6. Test müştəri, maliyyə, inventar və audit snapshotları ilə yekun doğrulama aparılır.

DNS TTL, Nginx/upstream əvvəlki hədəfləri, delta ixrac formatı, replay məsulu və reconciliation təsdiq edən şəxslər 25 iyul `GO/NO-GO` protokolunda əvvəlcədən qeyd edilməlidir.

## Sübut faylları

- `tmp/full-system-regression-20260717-000301.json`
- `tmp/browser-visual-20260716/browser-visual-report.json`
- `tmp/field-operations-simulation-SIM26-260716205925-b62fc4.json`
- `tmp/field-operations-cleanup-SIM26-260716205925-b62fc4.json`

`tmp` artefaktları release arxivinə ayrıca daxil edilməlidir; Git mənbə koduna test məlumatı kimi əlavə olunmamalıdır.

Sahə simulyasiyası JSON artefaktında `retained_for_review: true` yazılması artefaktın baxış üçün saxlanıldığını bildirir; bu, DB-də test məlumatlarının qalması demək deyil. DB run statusunun `cleaned` olduğunu və simulyasiya əməkdaşları, müştəriləri, inventar/əməliyyat qeydlərinin təmizləndiyini göstərən ayrıca cleanup nəticəsi release arxivinə əlavə edilməlidir. Dəyişdirilməz təhlükəsizlik audit qeydlərinin saxlanılması həmin sübutda ayrıca qeyd olunmalıdır.
