# SuperOptic mobil əməkdaş və rəhbər sistemi — hazırlıq auditi

Tarix: 2026-09-29. Qərar: **canlı istifadəyə hazır deyil**.

## 2026-09-30 düzəliş statusu

Auditdən sonra kritik boşluqların kod səviyyəsində düzəldilməsinə başlanıb. Aşağıdakılar mənbə kodunda tamamlanıb, lakin miqrasiya canlı bazaya tətbiq edilməyib və real SMS/GPS/bilet mutasiyası aparılmayıb:

- 20260930_520_mobile_ecosystem_hardening.php miqrasiyası OTP hash/müddət/cəhd limiti, server növbə sessiyası, razılıq versiyası, GPS UUID-si və outbox payload hash sahələrini əlavə edir.
- OTP yalnız biletə təyin olunmuş texnik tərəfindən istənə və istifadə oluna bilər. Kod açıq mətnlə saxlanmır, 10 dəqiqəlikdir, beş cəhdlə məhdudlaşır, bir dəfə istifadə edilir və mesaj növbəyə yazılmadıqda uğur qaytarılmır.
- Bilet bağlanması sübut, material, audit hadisəsi, OTP istehlakı və idempotency qeydi ilə eyni verilənlər bazası tranzaksiyasına daxil edilib.
- client_uuid məcburidir; account/action/payload uyğunluğu SHA-256 ilə yoxlanır və paralel icra əvvəlcədən rezervasiya olunan outbox sətri ilə qorunur.
- Mobil tətbiqdə daimi SQLite outbox yaradılıb. Tapşırıq və alınmış GPS nöqtəsi şəbəkə sorğusundan əvvəl cihazda saxlanır, eyni UUID ilə yenidən göndərilir və hesab üzrə ayrılır.
- Refresh token istehlakı FOR UPDATE tranzaksiyası ilə atomikləşdirilib; itkin və ya ləğv edilmiş cihaz rədd edilir. Mobil tərəfdə paralel refresh sorğuları single-flight olur və hər sorğu yalnız bir dəfə təkrarlanır.
- GPS serverdə cari razılıq versiyası və aktiv server növbəsi ilə məcburi bağlanıb. Müştərinin göndərdiyi is_on_duty qəbul edilmir. GPS tapılmayanda fotoya Bakı koordinatı yazılmır.
- Rəhbər fleet görünüşü və tapşırıq bölgüsü rəhbərin ərazi əhatəsinə məhdudlaşdırılıb. Tapşırıq təyinatı ilə audit hadisəsi eyni tranzaksiyadadır və düzgün event_note sütunundan istifadə edir.
- Lokal .env üçün ayrıca təsadüfi MOBILE_JWT_SECRET və MOBILE_OTP_PEPPER yaradılıb; mənbə kodundakı sabit ehtiyat sirr silinib. .env.example yalnız dəyişən adlarını saxlayır.

Yoxlama nəticəsi: mobil təhlükəsizlik unit skripti **8/8 keçdi**; mobil API və miqrasiyalar daxil olmaqla **72 PHP faylı sintaksis yoxlamasından keçdi**; 510 və 520 miqrasiyaları ayrıca müvəqqəti MySQL bazasında tətbiq olundu, gözlənilən cədvəl/sütunlar yoxlanıldı, geri söküldü və test bazası silindi; köhnə sabit JWT sirri, debug OTP, saxta Bakı watermark koordinatı və yalancı outbox bildirişi üçün qadağan olunmuş nümunələr tapılmadı.

Qalan buraxılış blokları: izolyasiya edilmiş bazada endpoint səviyyəli funksional və paralel sorğu testləri, saxta SMS adapteri ilə OTP ssenarisi, Flutter SDK ilə pub get/analyze/test, Android/iOS layihə qovluqlarının yaradılması, real cihazda background GPS/batareya testi və test APK/AAB. Buna görə ümumi qərar hələ də **canlı istifadəyə hazır deyil** olaraq qalır.

## 2026-10-01 mobil əməliyyat mərkəzi statusu

Lokal test mühitində rol əsaslı mobil əməliyyat mərkəzi işlək vəziyyətə gətirildi:

- Super admin və admin üçün icmal, tapşırıq təyini, foto/OTP sübutunun təsdiqi, canlı əməkdaş xəritəsi və hadisə lentindən ibarət dörd bölməli panel yaradıldı.
- Hadisə lenti açıq MGS alarmlarını, admin təsdiqi gözləyən AI tapşırıqlarını və uğursuz Telegram/app/SMS göndərişlərini real cədvəllərdən birləşdirir.
- Operator paneli açıq biletləri, icradakı işləri, mesaj növbəsini, uğursuz göndərişləri və günün göndəriş sayını göstərir. Mühəndis paneli canlı alarm, kritik alarm, qəbul edilmiş alarm və texniki bilet metriklərini göstərir.
- Texnik API-də köhnə `billing_payments.created_by_account_id` asılılığı cari sxemə uyğunlaşdırıldı. Texnik və mühəndis texniki endpoint-lərə daxil olur; operator və idarəetmə səlahiyyəti olmayan rollar 403 alır.
- Apache/FastCGI üçün `Authorization: Bearer` başlığının ötürülməsi əsas, cPanel şablonu və çıxış paketinin `.htaccess` fayllarında qorundu.
- Lokal bazada yalnız `mobile.qa.*` hesabları və ayrıca QA bileti ilə tapşırıq təyini və OTP-təsdiqli sübutun qəbul edilməsi başdan sona yoxlanıldı; hər iki əməliyyat audit hadisəsi yaratdı.
- Flutter analizi xətasız keçdi, iki widget/rol testi keçdi, debug APK yaradıldı və Samsung SM-S921B cihazına ADB ilə quraşdırıldı. Super admin paneli cihazda real lokal məlumatla açıldı; tab kəsilməsi və kart overflow problemi düzəldilib yenidən quraşdırıldı.

Bu nəticələr lokal inkişaf mühitinə aiddir. Canlı miqrasiya/deploy, real push bildiriş infrastrukturu, uzunmüddətli background GPS/batareya sınağı, real SMS OTP pilotu və iOS paketi ayrıca buraxılış yoxlaması tələb edir; buna görə ümumi qərar hələ **canlı istifadəyə hazır deyil** olaraq qalır.

## 2026-10-03 appmasterprompt icra statusu

`mobile/superoptic_ops/docs/appmasterprompt.md` yenidən qəbul meyarı kimi yoxlandı. Bu mərhələdə promptdakı rəhbər nəzarəti, ərazi və davamiyyət boşluqları üzrə aşağıdakı tamamlamalar edildi:

- Menecer panelində köhnə/stale AI bildirişləri 24 saatla məhdudlaşdırıldı; köhnə `LEGACY-MGS-*` tapşırıqları rəhbər və əməkdaş mobil siyahılarından təmizləndi.
- Ərazi sərhədi üçün `mobile_area_geofences` və `mobile_geofence_events` miqrasiyası lokal bazada tətbiq edildi. `location/ping.php` artıq həm əsas `portal_accounts.area`, həm də `portal_account_areas` əlaqəsini nəzərə alır.
- `manager/geofences.php` endpoint-i və Flutter-də `ManagerGeofencesScreen` əlavə edildi. Rəhbər ərazilər üzrə mərkəz koordinatı, radius və aktivlik statusu qura bilir.
- Rəhbər üçün `manager/attendance-report.php` endpoint-i əlavə edildi. Gün/həftə/ay/il periodu üzrə qeydli gün, gecikmə, tez çıxış, çıxışsız gün, işlənmiş dəqiqə, eksik dəqiqə və əməkdaş siyahısı qaytarılır.
- Flutter-də `ManagerAttendanceReportScreen` əlavə edildi və menecer icmalına “Davamiyyət və gecikmə hesabatı” keçidi qoşuldu.
- Davamiyyət API ssenarisi test edildi: check-in, təkrar `client_uuid` ilə idempotency, check-out və real `recorded_at` vaxtına görə saxlanma yoxlandı; test məlumatları təmizləndi.
- Geofence lifecycle test edildi: Baki üçün müvəqqəti sərhəd quruldu, ərazidən çıxış `left_area`, geri dönüş `returned_to_area` kimi yazıldı; test məlumatları təmizləndi.
- Yeni davamiyyət hesabatı canlı lokal API-də super admin tokeni ilə `day`, `week`, `month`, `year` periodları üçün yoxlandı.

Yoxlama nəticələri:

- PHP lint: `manager/attendance-report.php`, `manager/dashboard.php`, `manager/geofences.php`, `location/ping.php` keçdi.
- Mobil təhlükəsizlik unit: **8/8 keçdi**.
- Mobil API smoke: **9/9 keçdi**.
- Flutter analyze: **No issues found**.
- Flutter test: **2/2 keçdi**. C diskində yer az olduğu üçün ilk test cəhdi temp yazısında dayandı; `TEMP/TMP` D diskinə yönləndiriləndən sonra keçdi.
- Samsung SM-S921B cihazında mövcud APK açıldı və super admin icmalı göründü. Lakin son dəyişiklikləri daşıyan yeni APK build-i C diskində yalnız təxminən 1.09 GB boş yer qaldığı və Gradle-in `assembleDebug` mərhələsində uzun müddət tamamlanmadığı üçün telefona yazılmadı. Köhnə APK-də yeni “Davamiyyət və gecikmə hesabatı” keçidi görünmür.

Qalan praktik bloklar:

- C diskində Android/Flutter build üçün kifayət qədər yer boşaldılmalı və ya Android build mühiti tam D diskinə köçürülməlidir; bundan sonra APK yenidən build/install edilməlidir.
- Ərazi sərhədlərinin real mərkəz/radius dəyərləri rəhbər tərəfindən doldurulmalıdır. Test koordinatları saxlanılmayıb.
- Real sahə marşrutunda background/foreground GPS, batareya və icazə davranışı ayrıca pilotla yoxlanmalıdır.
- Promptdakı daha böyük portfolio/bonus, inventar-material maliyyə lifecycle, auto-resolve engine və aylıq/illik rəhbər hesabatlarının tam məhsul səviyyəsi hələ mərhələli iş olaraq qalır.

## 2026-10-04 telefon testi və mobil panel düzəliş statusu

2026-10-03-də qalan Android build blokunun praktik həlli üçün Flutter/Gradle mühiti D diskinə yönləndirildi və Gradle yaddaş ayarları maşının real RAM limitinə uyğunlaşdırıldı. Bundan sonra yeni debug APK yaradılıb Samsung SM-S921B telefonuna ADB ilə təmiz quraşdırıldı.

- Login ekranına lokal QA üçün rol seçim düymələri əlavə edildi: Super admin, Admin, Texnik, Operator və Mühəndis. Hər seçim uyğun `mobile.qa.*` hesabını doldurur və test girişini başladır.
- Super admin girişi real telefonda yoxlandı. Əməliyyat Mərkəzi açıldı və rəhbər icmalında canlı xəritə, tapşırıq yaratma/icraçı təyini, iş ərazisi sərhədləri və davamiyyət hesabatı keçidləri göründü.
- `Davamiyyət və gecikmə hesabatı` real telefonda açıldı. Gün, həftə, ay və il periodları, qeydli gün, gecikmə, tez çıxış, çıxışsız gün, iş saatı, eksik saat və əməkdaş siyahısı UI-da təsdiqləndi.
- Mobil API lokal telefon testi üçün `http://127.0.0.1:8081/superopticnet/api/mobile/v1` ünvanına yönləndirildi və ADB reverse `tcp:8081 -> tcp:80` ilə işlədi. Production buraxılışında bu dəyər `--dart-define` ilə canlı API-yə verilməli və ya mənbədə production default-a qaytarılmalıdır.
- Telefonda görünən köhnə import səs-küyü düzəldildi: rəhbər, operator/engineer və texnik mobil siyahılarında yalnız `LEGACY-MGS-*` deyil, bütün `LEGACY-*` biletləri 24 saatdan köhnədirsə gizlədilir. Alarm izahında da `LEGACY-*` bilet nömrəsi istifadəçiyə göstərilmir.
- Canlı lokal API ilə super admin dashboard yoxlandı: 50 tapşırıq cavabında `LEGACY-TICKET-*` və ümumi `LEGACY-*` sayı 0 oldu. Texnik tapşırıq siyahısı da yoxlandı: 1 real QA tapşırığı gəldi, `LEGACY-*` sayı 0 oldu.

Yoxlama nəticələri:

- PHP lint: `manager/dashboard.php`, `technician/tasks.php`, `technician/dashboard.php`, `staff/dashboard.php`, `lib/mobile-alert-feed-lib.php` keçdi.
- Mobil təhlükəsizlik unit: **8/8 keçdi**.
- Mobil API smoke: **9/9 keçdi**.
- Flutter analyze: **No issues found**.
- Flutter test: **2/2 keçdi**.
- Flutter debug APK build olundu və telefona quraşdırıldı; super admin axını və davamiyyət hesabatı real cihazda yoxlandı.

Canlı istifadəyə buraxılış qərarı hələ dəyişmir: real production API ünvanı ilə release build, push bildiriş infrastrukturu, uzunmüddətli GPS/batareya pilotu, real SMS OTP pilotu, ərazi sərhədlərinin real koordinatlarla doldurulması və maliyyə/bonus/inventar lifecycle hissələrinin tamamlanması ayrıca tələb olunur.
### 2026-10-04 dizayn yeniləməsi

İstifadəçi telefon üzərində mövcud UI-nin görünüş və istifadə rahatlığını yetərsiz saydığı üçün mobil tətbiqin əsas əməliyyat ekranları yenidən dizayn edilməyə başlandı.

- Ümumi Flutter theme yeniləndi: parlaq cyan/neon görünüşü yerinə dərin navy, indigo və amber vurğulu daha ciddi əməliyyat palitrası tətbiq edildi; kart, input, tab, snackbar və button tokenləri eyniləşdirildi.
- Rəhbər/Super admin icmalı yenidən quruldu: yuxarıda status hero bloku, əsas əməliyyat metrikləri, 2x2 sürətli idarəetmə paneli və kompakt sahə komandası siyahısı əlavə edildi. Uzun və qarışıq düymə siyahısı yığcam idarəetmə kartları ilə əvəzləndi.
- Operator və mühəndis ekranları eyni vizual sistemə keçirildi: rol əsaslı hero, daha oxunaqlı metrik kartları və qruplaşdırılmış hadisə kartları əlavə edildi.
- Texnik panelinə `Portfel, bonus və risk nəzarəti` keçidi əlavə edildi. Yeni `technician/portfolio-bonus.php` endpoint-i texnikin portfel xülasəsini, borc/problem risklərini, bonus xülasəsini, bonus xəbərdarlıqlarını və HR skorunu mobil tətbiqə qaytarır.
- Flutter debug APK yenidən build edildi. ADB həmin anda telefonu görmədiyi üçün yeni APK cihazda quraşdırılmadı; telefon yenidən ADB-də görünəndə `build/app/outputs/flutter-apk/app-debug.apk` quraşdırılmalıdır.

Yoxlama nəticələri:

- PHP lint: `technician/portfolio-bonus.php` keçdi.
- Canlı lokal API: QA texnik tokeni ilə `technician/portfolio-bonus.php?period=month` cavabı uğurla alındı.
- Flutter analyze: **No issues found**.
- Flutter test: **2/2 keçdi**.
- Flutter debug APK build: **keçdi**.

## Əhatə və sübut səviyyəsi

İstifadəçinin əlavə etdiyi mobil ekosistem tələbləri PHP və Flutter mənbə kodu ilə tutuşduruldu. Uyğun kod `D:/xampp/htdocs/superopticnet` daxilində tapıldı; söhbətin `C:/Users/Serdar/Downloads/superopticnet` qovluğu daha köhnə nüsxədir. Kodun Antigravity tərəfindən yazıldığı ayrıca təsdiqlənmədi.

Graphify mövcud əlaqələrin axtarışına kömək etdi, lakin yeni mobil fayllar barədə yetərli məlumat vermədi. Aşağıdakı tapıntılar birbaşa mənbə kodunun yoxlanmasına əsaslanır, qrafdan çıxarılmış canlı sistem nəticələri deyil.

Canlı bazaya miqrasiya, API mutasiyası, SMS, GPS izləməsi, bilet bağlama və xidmət restartı edilmədi. Mövcud dəyişikliklərə toxunulmadı. Agent sisteminin tam 24/7 istehsal hazırlığı bu auditdə təsdiqlənməyib.

## Mövcud hissələr

- Mobil autentifikasiya, token yeniləmə/ləğv, razılıq və cihaz qeydiyyatı API-ləri.
- Texnikin tapşırıq/müştəri ekranları, foto/OCR, OTP və işin bağlanması üçün ilkin kod.
- Rəhbər xəritəsi, əməkdaş mövqeləri və tapşırıq təyin etmə API-ləri.
- Yeddi mobil cədvəl üçün `db/migrations/20260927_510_mobile_ecosystem_core.php` miqrasiyası. Bazada tətbiqi yoxlanılmayıb.
- Flutter `lib/` və `pubspec.yaml`. Yoxlanmış tətbiq qovluğunda Android/iOS layihə qovluqları, testlər, APK/AAB və işlək Drift/outbox implementasiyası tapılmadı.

## Canlı istifadəyə buraxılışı bloklayan tapıntılar

| Prioritet | Tapıntı | Mənbə və təsir |
|---|---|---|
| P0 | OTP-siz və ya əvvəlcədən verilməmiş kodla bilet bağlana bilir | `api/mobile/v1/technician/task-resolve.php:48`: kod verilməyibsə belə bağlanma davam edir; verilmiş OTP yoxdursa istənilən boş olmayan dəyər təsdiq sayılır. OTP müddəti və cəhd limiti görünmür. |
| P0 | Texnikin biletə aidiyyəti yoxlanmır | `technician/task-resolve.php:32` və `technician/request-otp.php`: ümumi employee rolu kifayət edir; sorğu yalnız bilet ID-si ilə edilir. |
| P0 | İdempotentlik paralel sorğuları qorumur | `lib/mobile-auth-lib.php:269`: nəticə yalnız client_uuid ilə axtarılır, account/action/payload uyğunluğu yoxlanmır; biznes əməliyyatı qeydiyyatdan əvvəl icra olunur, atomik tranzaksiya yoxdur. |
| P0 | Offline saxlanma barədə yanlış bildiriş | `mobile/superoptic_ops/lib/features/technician/task_resolve_screen.dart:167`: xəta zamanı Outbox-a yazıldığı göstərilir, lakin yazma əməliyyatı yoxdur. Hər göndərmədə yeni UUID yaradılır. |
| P0 | GPS razılığı/növbə serverdə məcburi şərt deyil | `api/mobile/v1/location/ping.php`: razılıq və server növbəsi yoxlanmır, is_on_duty müştəridən alınır və standart olaraq 1-dir. Rəhbər fleet sorğusu şöbə/ərazi əhatəsini məhdudlaşdırmır. |
| P0 | Token təhlükəsizlik boşluqları | `lib/mobile-auth-lib.php:55,76`: mənbədə sabit ehtiyat imzalama sirri var; runtime override təsdiqlənməyib. Cihaz qeydi yoxdursa autentifikasiya rədd edilmir. Refresh istehlakı atomik deyil. |
| P1 | GPS olmadığı halda saxta dəqiqlik | `task_resolve_screen.dart:72`: mövqe alınmayanda fotoya sabit Bakı koordinatı yazılır. Naməlum mövqe naməlum kimi saxlanmalıdır. |
| P1 | OTP çatdırılması təsdiqlənmədən uğur bildirilir | `technician/request-otp.php:57`: göndərmə funksiyası yoxdursa da otp_sent=true; production xaricində OTP cavabda qaytarılır və tətbiq onu avtomatik doldurur. |
| P1 | Bilet/material/audit yazıları vahid tranzaksiya deyil | `technician/task-resolve.php` material xətasını udur; `manager/assign-task.php` hadisə tarixçəsi xətasını udur. Qismən uğur mümkündür. |
| P1 | Mobil refresh dövrəsi məhdudlaşdırılmayıb | `lib/core/network/api_client.dart`: retry marker/limit və paralel refresh üçün single-flight görünmür. |
| P1 | Mövcud smoke test təhlükəsiz izolyasiya edilməyib | `tools/mobile-api-smoke.php:53,102,111`: real aktiv hesabı seçir, ona razılıq və GPS yazır. GPS sətri cleanup-da silinmir. Test icra edilmədi. |

P0 burada canlı istifadəyə buraxılış üçün ən yüksək prioriteti bildirir; tapıntılar real istismar və ya hücum baş verdiyinin sübutu deyil. Fayl yolları qısaldıldıqda PHP mənbələri `api/mobile/v1/`, Dart mənbələri `mobile/superoptic_ops/` kökünə aiddir.

## İcra edilmiş yoxlama

`D:/xampp/php/php.exe -n -l` ilə mobil API, miqrasiya və smoke test daxil olmaqla **19 PHP faylı: 19 keçdi, 0 sintaksis xətası**. Bu yalnız sintaksis nəticəsidir, funksional və təhlükəsizlik testi deyil. Flutter/Dart PATH-də tapılmadığı üçün build/analyze icra olunmadı; ayrıca diskdə SDK mövcudluğu istisna edilmir.

## Tamamlama mərhələləri və qəbul şərtləri

1. **İzolyasiya edilmiş backend təhlükəsizliyi:** yalnız test bazası və saxta SMS adapteri; məcburi təhlükəsiz imzalama konfiqurasiyası; aktiv cihaz; bilet sahiblik/RBAC; OTP hash, son istifadə müddəti və limit; atomik refresh/idempotentlik; yazıların tranzaksiyası. Mənfi və paralel sorğu testləri keçməlidir.
2. **Məlumatın itməməsi və məxfilik:** davamlı Drift outbox, sabit əməliyyat UUID-si, hesabdan çıxışda növbələrin ayrılması; restart/offline/retry testləri; server növbəsi və razılıq versiyası; işdənkənar GPS-in rəddi; retention və rəhbər əhatəsi; GPS yoxdursa naməlum dəyər.
3. **Mobil paket və sahə pilotu:** Android/iOS konfiqurasiyası, icazələr, real cihazda batareya/fon işi testləri, test APK; kiçik təsdiqli pilot, bərpa planı və audit jurnalı. Saxta uğur bildirişləri qalmamalıdır.
4. **Agent inteqrasiyası:** əvvəl yalnız oxu, gecikmə/SLA xəbərdarlığı və təklif. Tapşırıq paylama və digər dəyişikliklər məhsulun səlahiyyət/təsdiq mexanizmindən keçməlidir. Əməkdaş tətbiqinin əsas işi LLM əlçatanlığından asılı olmamalıdır. Maliyyə, əməkhaqqı və intizam qərarları avtomatik GPS/OCR nəticəsinə bağlanmamalıdır.

Bu sənəd audit və icra sırasıdır; sadalanan düzəlişlərin həyata keçirildiyi, miqrasiyanın tətbiq olunduğu və ya hazır mobil paket olduğu mənasına gəlmir.

