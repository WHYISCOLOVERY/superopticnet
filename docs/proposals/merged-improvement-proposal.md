# superoptic.net: DeepSeek və Claude icmalının birləşdirilmiş təklifi (22.10.2026)

Mənbələr: (A) mənim səhifə-səhifə icmalım ([page-by-page-ui-ux-review.md](/mnt/project-files/site-analysis/page-by-page-ui-ux-review.md)), (B) DeepSeek təhlili. DeepSeek-in hər iddiası canlı sayt və `claude/rebased-on-live` kodu ilə yoxlanıb. Heç nə dəyişdirilməyib, heç bir real müraciət göndərilməyib.

Statuslar: **TƏSDİQ** (düzdür), **QİSMƏN** (bir hissəsi düzdür), **RƏDD** (səhvdir), **YOXLANMAYIB** (bu mühitdən yoxlamaq mümkün olmadı).

## 1. DeepSeek iddialarının yoxlanması

| # | DeepSeek iddiası | Status | Nə tapdım |
|---|---|---|---|
| 1 | "0 Uptime / 0 Ping / 0 Tutum" göstəriciləri etibarsızlıq yaradır | **QİSMƏN** | Səhifə scroll olunanda sayğaclar 99.98%, 8 ms, 120 Gbps, 18 450+ göstərir (xəta yox idi). Amma HTML-də başlanğıc dəyər `0`-dır (`index.php:1516-1519`, `data-count`), sayğac yalnız JS və IntersectionObserver ilə dolur (`index.php:~1826`). JS işləməsə, axtarış robotu və ya ekran oxuyucu `0` görür. Əsas problem başqadır: bu rəqəmlər **real vaxtlı deyil, koda yazılmış sabitdir** (99.98%, 18 450+). Real monitorinqdən gəlmirlər. |
| 2 | Qoşulma formu backend-ə qoşulmayıb | **RƏDD** | Form `muraciet-gonder.php`-ə gedir (`index.php:1487`). Canlı paketdəki fayl `deploy/cpanel/muraciet-gonder.php`-dir: CSRF, honeypot, rate limit, telefon yoxlaması, reCAPTCHA, sonra **imzalı sorğu ilə `https://mgt.superoptic.net/api/public/website-lead.php`** (`app_website_lead_process`), alınmasa Telegram ehtiyatı. Canlı sayt CSRF yoxlamasına cavab verir (`status=csrf_failed`), yəni handler işləyir. Real müraciət göndərmədiyim üçün CRM-də görünməsi **YOXLANMAYIB**. |
| 3 | Kripto/xəbər lenti ISP saytını çaşdırır | **TƏSDİQ** | 9 xəbərdən 2-si kriptodur, ayrıca "Kripto bazar icmalı" tabı və qeyd var. Əlavə tapıntı: bütün xəbərlər 17-24.04.2026 tarixlidir, yəni **6 aya yaxın köhnədir**, hamısı `index.php`-də sabit massivdədir (admin paneldən idarə olunmur). |
| 4 | Dizayn "köhnə, bloklu, zəif iyerarxiya" | **QİSMƏN / əsasən RƏDD** | Sayt müasir tünd dizayndır (kartlar, ikonlar, gradient CTA, animasiyalı fiber axını). DeepSeek yəqin ki, yalnız mətni oxuyub. Haqlı olduğu yer: tarif kartları bir-birindən zəif fərqlənir, mobil səhifə çox uzundur (~14 400px), daxili səhifələr boşdur (A hesabatında var). |
| 5 | Mətn "sistem tərəfindən generasiya olunmuş" səslənir | **QİSMƏN** | Subyektivdir, amma "Əvvəlcə ən çox satılan paketləri göstəririk. Tam tarif cədvəli aşağıda qalır" kimi cümlələr daxili izah tonundadır, müştəriyə faydası yoxdur. Mətnlər `index.php`-də `$featuredOfferSection`, `$copy` massivlərindədir. |
| 6 | Mobil sıxlıq riski | **QİSMƏN** | Mobildə kartlar oxunaqlıdır. Real problemlər: 12px-dən kiçik alt yazılar, RU mobil overflow (+8px), 5 hündür ödəniş kartı. |
| 7 | `site:superoptic.net` nəticəsizdir, `robots.txt`/`sitemap.xml` problemi ola bilər | **TƏSDİQ (daha ciddi)** | Canlıda `/robots.txt` və `/sitemap.xml` ikisi də **404** qaytarır (www və apex). Repoda fayllar var, amma cPanel paketi onları köçürmür: `deploy/build-cpanel-public-site.ps1` və `output/cpanel-superoptic-site/public_html/` içində yoxdur. `site:` axtarışında mən də heç bir superoptic.net nəticəsi tapmadım (zəif sübut: axtarış vasitəsi etibarlı indeks yoxlaması deyil). |
| 8 | Şəkillər optimallaşdırılmalıdır (WebP, lazy) | **QİSMƏN** | Ana HTML-də 12 `<img>`-dən yalnız 6-sı `loading="lazy"`-dir. Repoda `images/` içində 5.3 MB (`iptelephone.jpg`), 3.4 MB (`aboutUs.jpg`), 2.6 MB (`laninstallation.jpg`) fayllar var. Bunların ictimai səhifələrdə istifadəsini yoxlamadım: **YOXLANMAYIB**. |
| 9 | Əhatə bölməsi MGS ilə dinamik olmalıdır | **TƏSDİQ (təklif kimi)** | Hazırda 12-15 rayon çipi sabit siyahıdır (`$copy['coverage']['areas']`). Məqsədəuyğundur, amma real ünvan yoxlaması yoxdur. |
| 10 | Saytı superopticnet-in "front-office"-i kimi qurmaq | **QİSMƏN artıq var** | Lead axını, portal giriş linki (`mgt.superoptic.net/portal-login.php`) və ödəniş inteqrasiyası artıq backend-dədir. Çatışan: tarif seçimini formaya ötürmək və müraciət statusunu göstərmək. |

## 2. Birləşdirilmiş təklif siyahısı

Qaydalara görə: P1 = bu həftə, P2 = bu ay, P3 = sonra. Sütun "Mənbə": A = mənim icmalım, D = DeepSeek, A+D = hər ikisi.

### P1. Yüksək prioritet

| # | Dəyişiklik | Harada | Mənbə | Status |
|---|---|---|---|---|
| 1 | `robots.txt` və `sitemap.xml`-i cPanel paketinə əlavə et, canlıda 200 qaytarsın. Sitemap-ə www/apex uyğunluğunu və dil variantlarını (`?lang=en/ru`) yaz | `deploy/build-cpanel-public-site.ps1` (Copy-Item sətirləri), `robots.txt`, `sitemap.xml`; serverdə `public_html/` | D (A-da yox idi) | TƏSDİQ |
| 2 | Bir əsas host seç (www və ya apex), o birini 301 ilə yönləndir. Canonical hazırda `https://superoptic.net`-dir, amma sayt `www`-da da açılır. İndeksləmə bölünür | `deploy/cpanel/.htaccess`, `index.php:672,1299` (`$siteUrl`) | yeni | TƏSDİQ |
| 3 | Tarif kartlarındakı və forma düymələrindəki "Göndər" sözünü "Paketi seç" / "Müraciət et" ilə əvəz et. Seçilən paketi formaya ötür (`service_type` və ya `message`-ə): hazırda "Seç" düyməsi yalnız `#connection-form`-a scroll edir | `index.php:1548,1576` (`href="#connection-form"`), forma `1487-1531`, `$copy['lead']['submit']` | A (+D CTA) | TƏSDİQ |
| 4 | Sayğac rəqəmlərini HTML-də real dəyərlə yaz (JS yalnız animasiya etsin) ki, `0` heç vaxt görünməsin. İdeal: uptime/ping/abunəçi rəqəmlərini real mənbədən (`system-health`/billing) və ya "sistem" yox, "orta aylıq göstərici" izahı ilə ver. Yoxlana bilməyən rəqəm (18 450+, 99.98%) hüquqi və etibar riskidir, ya sübutla saxla, ya çıxar | `index.php:1516-1519`, JS `~1826` | D (qismən) | QİSMƏN |
| 5 | Portal girişində diakritiki bərpa et (Müştəri, Şifrə, Məsələn…), dizaynı tünd mövzuya uyğunlaşdır, "Daxil ol" düyməsini tam enli et | `deploy/cpanel/portal-login.php` (canlı), repoda `portal-login.php` + `css/portal.css` | A | TƏSDİQ |
| 6 | Canlı formanın CRM-ə düşdüyünü **bir test müraciəti ilə** yoxla (sizin icazənizlə, test nömrəsi ilə) və Telegram fallback-in işlədiyini təsdiqlə | `deploy/cpanel/muraciet-gonder.php`, `api/public/website-lead.php` | D (təsdiq üçün) | YOXLANMAYIB |

### P2. Orta prioritet

| # | Dəyişiklik | Harada | Mənbə |
|---|---|---|---|
| 7 | Kripto tabını və 2 kripto xəbərini ana səhifədən çıxar. Xəbərləri ayrıca `/xeberler.php` səhifəsinə köçür. Ana səhifədə yalnız 3 son ISP xəbəri qalsın. Xəbərləri yenilə (indi 6 ay köhnədir) və ya tarixsiz "faydalı məsləhətlər" adlandır | `index.php:119-129` (tabs, disclaimer, items), `$newsItemsOverride` (~380), footer "Xəbər" linki | A+D |
| 8 | Header-ə bərk/blur fon ver, dekorativ parıltı nöqtələrini mətn və kartların altına sal (`z-index`), kontrastı ölç | `css/fiber-site.css` (header, `.fiber-bg`), `script/fiber-bg.js` | A |
| 9 | İngilis etiketləri tərcümə et: Download/Upload, 4K TV/Gaming/Streaming (AZ və RU-da) | `index.php:1556-1560` (`spd-row`, `usecase`), `$copy`/lang faylları | A |
| 10 | Tarif kartlarını fərqləndir: sürət, qiymət, daxil olanlar (TV kanal sayı, telefon) ikonlarla, "ən çox seçilən" nişanı, mini müqayisə cədvəli | `index.php:1540-1580`, `css/fiber-site.css` (`.plan`, `.tariff-row`) | A+D |
| 11 | Daxili səhifələri zənginləşdir: Haqqımızda (rəqəm, foto, lisenziya/ICTA, CTA; boş ikon kvadratlarını düzəlt), Keyfiyyət (rəqəm kartları, tarix), Cihazlar (şəkil, texniki göstərici, "soruş" düyməsi) | `haqqimizda.php`, `keyfiyyet-gostericileri.php`, `cihazlar.php`, `deploy/cpanel/` paketinə uyğun versiyalar | A |
| 12 | Hüquqi səhifələrə mündəricat, "yuxarı" düyməsi, son yenilənmə tarixi. Daxili səhifələrə canonical, og və hreflang əlavə et | `qaydalar-ve-sertler.php`, `gizlilik-siyaseti.php`, `page-helpers.php` | A |
| 13 | Ödəniş kartlarına real loqolar (`images/payment-*.svg/png` artıq repoda var) və mobildə 2-3 sütunlu şəbəkə | `index.php` ödəniş bölməsi, `css/fiber-site.css` | A |
| 14 | RU mobil overflow (+8px), 404 səhifə overflow, TVNET lent boş sahə (~300px), 12px-dən kiçik mətnlər | `css/fiber-site.css`, TV bölməsi `#tvnet` | A |
| 15 | Struktur data (JSON-LD `Organization`/`InternetServiceProvider`, `Offer`) və xəritə üçün `LocalBusiness` əlavə et. Hazırda ana səhifədə yoxdur | `index.php` `<head>` (~1299) | yeni |
| 16 | og:image brend şəkli olsun (indi `news-network-expansion.png`), ayrıca 1200x630 | `index.php:1316` (`$seoImage`) | yeni |

### P3. Sonra

| # | Dəyişiklik | Harada | Mənbə |
|---|---|---|---|
| 17 | Əhatə bölməsində ünvan/rayon yoxlaması: MGS/OLT məlumatından hansı küçələrin əhatə olunduğunu çıxarıb göstərmək (istifadəçi ünvan yazsın, "bəli/xeyr/sorğu burax") | `index.php` `#coverage`, `area-interest.php`, MGS API | D |
| 18 | Müraciət statusu: müraciətdən sonra "qəbul olundu, №…" göstər və ya SMS/WhatsApp təsdiq | `deploy/cpanel/muraciet-gonder.php` cavabı, `index.php` `status=request_sent` mesajı | D |
| 19 | Real-time şəbəkə statusu səhifəsi (hazırda `/status.php` 404). Uptime sübutu kimi | yeni `status.php` (ictimai, təhlükəsiz xülasə) | D+A |
| 20 | Müştəri rəyləri, qoşulma addımları ("müraciət → baxış → qoşulma"), sertifikatlar | `index.php` yeni bölmə | A |
| 21 | Şəkil optimallaşdırması: WebP/AVIF, bütün şəkillərə `loading="lazy"`, böyük JPG-ləri kiçilt (5 MB-lıq faylların ictimai istifadəsini əvvəlcə yoxla) | `images/`, `index.php` `<img>` | D |
| 22 | Mətn tonu: "sistem" cümlələrini müştəri dilinə sal ("Ən çox seçilən 3 paket. Bütün tariflər aşağıdadır") | `$featuredOfferSection`, `$copy` | D |

## 3. DeepSeek-in düzgün olmayan və ya diqqətlə qəbul olunmalı yerləri

- "Form backend-ə qoşulmayıb" demək səhvdir (yuxarıda 2-ci sətir). Bu iddia əsasında inteqrasiya işi planlaşdırmaq lazım deyil. Yalnız test müraciəti ilə təsdiq lazımdır.
- "0 Uptime" bug deyil, animasiyadır. Lakin rəqəmlər sabit olduğu üçün etibar problemi qalır.
- "Dizayn köhnədir, ikon və kart yoxdur" faktla uyğun gəlmir. Saytda ikon, kart və animasiya var, çatışan əsasən məzmun və fərqləndirmədir.
- "Mobil responsive riski" ümumi yazılıb, konkret nöqtələr A hesabatındadır.

## 4. Təklif olunan icra ardıcıllığı

1. P1 #1, #2 (SEO/host), #3 (CTA söz və paket ötürmə), #4 (sayğac), #5 (portal). Hamısı kiçik, risksiz, cPanel paketinə təsir edir.
2. #6 test müraciəti (sizin razılığınızla).
3. P2 qruplarla: xəbər/kripto (#7), vizual (#8-#10, #13, #14), daxili səhifələr (#11, #12), SEO əlavələri (#15, #16).
4. P3 ayrıca planla.

Qeyd: canlı sayt `deploy/cpanel/` paketindən yüklənir, ona görə düzəlişlər həm `index.php`-də, həm də `output/cpanel-superoptic-site/public_html/` və ya `build-cpanel-public-site.ps1` ilə yenidən qurulan paketdə əks olunmalıdır (PR #5 və #6 ilə toqquşma riskini nəzərə almaq lazımdır). Kod dəyişikliyinə başlamazdan əvvəl sizdən icazə gözləyirəm.

---

## 5. Performans: səhifələr gec açılır, keçid dəqiqələr çəkir (22.10.2026, təcili)

Əhatə: `claude/rebased-on-live` (PR #5) kodu oxundu, canlı sayt kənardan ölçüldü. Daxili səhifələrə giriş olmadığı üçün onların real vaxtı ölçülməyib; aşağıdakı səbəblər kodda görünən mexanizmlərdir. "Təsdiqlənməlidir" qeydi olanlar canlı serverdə yoxlanmalıdır. Heç bir kod, DB və ya konfiqurasiya dəyişdirilməyib.

### Kənardan ölçmə (bu mühitdən, proksi üzərindən; baza gecikmə ~0,7 s)

| URL | TTFB | Cəmi | Qeyd |
|---|---|---|---|
| superoptic.net/ | 0,69 – 1,55 s | 1,1 – 1,9 s | HTML gzip ilə 20 KB, yaxşı |
| mgt.superoptic.net/portal-login.php (5 ölçmə) | 0,79 – 1,02 s | eyni | 4 KB |
| superoptic.net/css/fiber-site.css (statik) | 0,72 s | 0,99 s | Müqayisə üçün: statik fayl da ~0,7 s |
| superoptic.net/portal-login.php | bir ölçmədə 302, bir ölçmədə 12 s-də vaxt aşımı | | təkrarlanmalıdır |

Nəticə: açıq sayt və giriş səhifəsi yavaşlığın əsas yeri deyil. Problem giriş etdikdən sonrakı daxili səhifələrdədir. Açıq saytda tək real yük şəkillərdir (bax D6).

### Səbəblər, prioritet sırası ilə

**D1 (kritik). Sessiya kilidi heç vaxt buraxılmır, uzun xarici çağırışlar bütün keçidləri bloklayır**
- Sübut: `config.php:514` `app_session_boot()` hər sorğuda `session_start()` çağırır. Bütün kodda `session_write_close()` yoxdur (grep nəticəsi 0). PHP-nin standart fayl sessiyasında bir istifadəçinin sorğuları növbə ilə işləyir.
- Üstəgəl `script/message-center.js:106` hər 3 saniyədən bir `api/provider/whatsapp-status.php` sorğusu atır. Bu endpoint `billing_require_employee()` ilə sessiyanı açır (kilid), sonra WhatsApp körpüsünə iki sinxron HTTP çağırışı edir (`communication-provider.php:356`, `:419`). **Düzəliş (22.10, PR #11 hazırlanarkən):** əvvəlki versiyada "20 s timeout" yazmışdım, bu səhv idi. Bu iki çağırış `http_request_raw(..., 2)` ilə 2 s-lə məhduddur və status 15 s fayl keşindədir. Yəni körpü düşəndə hər sorğu kilidi ~2 s (QR çağırışı) tutur, 3 s-lik taymerlə bu vaxtın ~2/3-də kilid məşğul olur. Bu hiss olunan yavaşlıq verir, amma tək başına "dəqiqələr" izah etmir; D2 və D3 daha güman edilən səbəblərdir.
- `script/whatsapp-monitor.js` (3 `fetch`, `setInterval`) və `views/whatsapp-monitor/live-monitor.php:225` eyni nümunədir.
- Düzəliş: (a) `app_session_boot()` ilə `$_SESSION` oxunandan dərhal sonra, xarici çağırışdan əvvəl `session_write_close()` (yalnız oxuyan endpointlərdə); (b) QR çağırışını da qısa keşlə (status artıq keşlidir); (c) JS taymerini 3 s-dən 10–15 s-ə və yalnız panel görünəndə işlət; (d) iki çağırışı (status+qr) birləşdir.
- Gözlənilən qazanc: səhifə keçidlərindəki onlarla saniyəlik/dəqiqəlik gözləmənin aradan qalxması. Ən böyük tək təsir.
- Risk: sessiyaya yazan kod `session_write_close()` sonrası itir; yalnız oxuyan endpointlərdə tətbiq edilməlidir.

**D2 (kritik, təsdiqlənməlidir). Sxem yoxlaması/miqrasiya hər sorğuda təkrarlanır**
- Sübut: `mgs-integration-lib.php:86` `app_mgs_ensure_schema()` heç bir `static` qoruyucu və ya stamp olmadan işləyir. `db_bootstrap_mode` "off" deyilsə, hər çağırışda 36 `CREATE TABLE IF NOT EXISTS`, sütun yoxlamaları (`information_schema.COLUMNS`) və seed əməliyyatları icra olunur. Kodda 113 çağırış yeri var (`noc-devices.php`, `billing-customers.php` daxil). "off" olduqda da `app_mgs_schema_ready()` hər çağırışda `information_schema.TABLES` sorğusu atır, keşi yoxdur.
- Eyni nümunə: `app_bonus_ensure_schema` (24 çağırış), `app_audit_ensure_schema` (35 çağırış, hər dəfə marker SELECT), `app_feature_flags_ensure_schema`, `app_security_anomaly_ensure_schema`, `app_portal_recovery_ensure_schema` — heç birində `static` qoruyucu yoxdur.
- `deploy/ubuntu-install-superoptic-mgs.sh:612` quraşdırma zamanı `db_bootstrap_mode=always` yazır və yalnız bütün addımlar uğurlu olarsa (`:792`) "off"-a qaytarır. Yarımçıq qalmış deploy canlıda "always" saxlaya bilər. Standart dəyər isə "cached"-dir, "off" deyil.
- Təsdiqləmə: canlı `config.local.php`-də `db_bootstrap_mode` dəyərinə baxmaq və MySQL slow log/`SHOW PROCESSLIST` ilə `information_schema`/`CREATE TABLE IF NOT EXISTS` sorğularını axtarmaq.
- Düzəliş: (a) canlıda `db_bootstrap_mode=off` (əvvəlcə migrasiyaların tətbiq olunduğuna əmin olmaqla); (b) hər `*_ensure_schema` və `*_schema_ready` funksiyasına `static $ready` və ya sorğu daxili keş; (c) `db.php`-dəki stamp mexanizmini `mgs`, `bonus` və digərlərinə də tətbiq etmək.
- Gözlənilən qazanc: "always/cached" olduqda səhifə başına yüzlərlə ms-dən saniyələrə qədər. "off" olduqda səhifə başına bir neçə `information_schema` sorğusu (hər biri 10–100 ms) azalır.

**D3 (yüksək). `radacct` üzərində ağır və indekssiz sorğular**
- `radius-active.php:48–78`: hər açılışda üç böyük sorğu — 24 saatlıq `SUM`, `GROUP BY username` ilə iki derived-table (`traffic`, `current_session` + `MAX(radacctid)`), `LIKE '%…%'` axtarışı və `LIMIT 200`-dən əvvəl bütün nəticənin birləşdirilməsi. `radacct` milyonlarla sətir olduqda saniyələr çəkir.
- `radius-logs.php:91–99`: NAS siyahısı üçün `SELECT nasipaddress FROM radacct … UNION …` bütün cədvəli skan edir, `nasipaddress` indeksi yoxdur (`db.php:921–926` yalnız username, session id, start/stop+username, class).
- `radius-logs.php:85`: `WHERE DATE(acctstarttime) BETWEEN …` indeksi istifadə edə bilmir (sargable deyil).
- `radius-active.php:47`, `radius-nas.php:102`: eyni 24 saatlıq cəmi hər açılışda yenidən hesablayır.
- Düzəliş: `radacct(nasipaddress)`, `radacct(framedipaddress)`, `radacct(callingstationid)` indeksləri (miqrasiya, DB dəyişikliyi — icazə lazımdır); `DATE(col)` əvəzinə `col >= :start AND col < :end + 1 day`; NAS siyahısını `nas` cədvəlindən götürmək və ya keşləmək; 24 saatlıq cəmləri 1–5 dəqiqə keşləmək və ya xülasə cədvəlinə yazmaq; axtarışı prefiks (`q%`) etmək.
- Gözlənilən qazanc: RADIUS səhifələrində saniyələrdən yüzlərlə ms-ə.
- Təsdiqləmə: canlı DB-də `EXPLAIN` və `SELECT COUNT(*) FROM radacct`.

**D4 (yüksək). Daxili səhifələrdə sinxron xarici/şəbəkə çağırışları üçün qısa timeout yoxdur**
- Ümumi HTTP köməkçiləri (`config.php:914 http_post_json`, `communication-provider.php:1027`, `mgs-integration-lib.php:5069`) hamısı `CURLOPT_TIMEOUT=20` işlədir, `CONNECTTIMEOUT` ya yoxdur, ya 8 s-dir. Telegram `getUpdates` timeout-u 25 s-ə qədər (`mgs-integration-lib.php:5140`) uzun sorğudur; o, yalnız `tools/process-telegram-modem-search-bot.php` işçisindən çağırılır, səhifə yükləmədən yox (düzgün).
- Qayda: səhifə yüklənməsi zamanı heç bir xarici API (MGS, RADIUS CoA, provayder, Telegram, WhatsApp körpüsü) sinxron çağırılmamalıdır; status üçün keşlənmiş nəticə və ya asinxron yeniləmə.
- Düzəliş: `CONNECTTIMEOUT=2`, `TIMEOUT=5` standartı, uğursuz körpü üçün 30–60 s "circuit breaker" (son uğursuzluğu keşlə və bu müddətdə çağırma).
- Qeyd: `noc-devices.php`, `operations-center.php` və `huawei-olt-lib.php`-də səhifə yükləmədə SNMP/SSH çağırışı grep ilə tapılmadı; yenə də canlıda `strace`/slow-log ilə təsdiqləmək lazımdır.

**D5 (orta). Çoxlu sorğu/N+1 şübhəsi və böyük səhifələr**
- `operations-center.php` 5145 sətir, 150 `foreach`; `mgs-integration-lib.php` 13 878 sətir və tək fayl kimi hər səhifədə yüklənir (`require`). PHP OPcache aktiv deyilsə, hər sorğuda parse olunur (təsdiqlənməlidir: `opcache.enable`, `opcache.validate_timestamps`).
- `billing-customers.php:700–718`: `foreach ($customers …)` üç dəfə ayrı dövr; əvvəlki hissədə id-lər üzrə toplu sorğular var (yaxşı), amma sətir başına əlavə sorğu olub-olmaması `EXPLAIN`/query log ilə yoxlanmalıdır.
- `db.php:104–112`: `app_db()` hər sorğuda yeni PDO bağlantısı açır (persistent deyil) və `SET time_zone` əlavə sorğusu göndərir; `PDO::ATTR_PERSISTENT` və ya `init_command` ilə birləşdirmək olar (kiçik qazanc, ~5–20 ms).
- `config.php:476–483`: bütün HTML çıxışı `ob_start` ilə `app_repair_mojibake` (≈100 cüt `strtr`) + `mb_convert_encoding` dövrləri üçün keçir; `e()` də hər çağırışda bunu təkrarlayır. Ölçmə: 600 KB HTML üçün ~38 ms, `e()` 100 000 çağırış üçün 162 ms (adi `htmlspecialchars` 24 ms). Yəni səbəb deyil, kiçik əlavə yükdür; mojibake mənbəyi (DB/fayl kodlaşdırması) düzəldilsə, tamamilə silinə bilər.

**D6 (orta). Açıq saytda böyük şəkillər və keşləmə**
- `images/iptelephone.jpg` 5,3 MB, `aboutUs.jpg` 3,4 MB, `laninstallation.jpg` 2,6 MB, `guneshli.jpg` 1,5 MB, `bg-overlay.jpeg` 1,3 MB (7 gün keş var, amma ilk yükləmə ağırdır).
- Ana səhifə HTML-i `Cache-Control: no-store` ilə gəlir və hər ziyarətdə sessiya cookie-si qoyur (`superoptic_public`), ona görə LiteSpeed/CDN keşi mümkün deyil.
- Düzəliş: şəkilləri WebP/AVIF və 1600 px-ə qədər kiçiltmək (səhifə çəkisini onlarla MB-dan bir neçə yüz KB-a), `loading="lazy"`; ana səhifədə sessiyanı yalnız forma/CSRF lazım olanda başlatmaq; qısa (30–60 s) `public, s-maxage` və ya LiteSpeed keşi.
- Qazanc: ilk yükləmə vaxtında 3–10 dəfə azalma (mobil), amma daxili sistemin yavaşlığına təsiri yoxdur.

### Təcili yoxlama planı (serverdə, kod dəyişikliyi olmadan)
1. `config.local.php`: `db_bootstrap_mode` dəyəri. "always" və ya "cached"-dirsə dərhal "off" (migrasiyalar tətbiq olunubsa) və səhifə vaxtını müqayisə et.
2. MySQL: `SHOW FULL PROCESSLIST` yavaş səhifə açılanda; slow query log (`long_query_time=1`) 10 dəqiqə.
3. PHP: `opcache.enable=1`, `session.save_path` yerli SSD-dədirmi, php-fpm/LiteSpeed işçi sayı (uzun sorğular işçiləri tutur).
4. WhatsApp körpüsü (`whatsapp-bridge/server.js`) cavab verirmi: `curl -m 5 <endpoint>/api/status`. Cavab verməzsə, D1 səbəbi təsdiqlənir. Müvəqqəti: mesaj mərkəzində WhatsApp panelini bağlı saxlamaq.
5. Brauzer DevTools → Network: keçid zamanı `whatsapp-status.php` sorğularının "Pending" müddəti.

### Təklif olunan icra sırası
1. D2-nin yoxlanması və `db_bootstrap_mode=off` (konfiqurasiya, kodsuz, ən sürətli).
2. D1: status endpointində `session_write_close()` + qısa timeout + keş + taymer artırımı (kiçik PR, risk aşağı).
3. D3: `radacct` indeksləri (DB dəyişikliyi, ayrıca icazə) və sargable tarix sorğuları.
4. D2 kod hissəsi: `static` qoruyucular.
5. D4, D5, D6 ayrı PR-lərdə. Toqquşma yoxlaması (PR fayl siyahılarına görə): #8 `index.php`, `page-helpers.php`, açıq sayt səhifələri və `css/fiber-site.css`; #9 `portal-login.php`, `css/portal.css`; #10 `billing-ui.php`, `noc-devices.php` (yalnız CSS sətirləri), `css/portal.css`, `css/fontawesome-lite.css`. D1–D5 bu fayllara toxunmur (`communication-provider.php`, `api/provider/whatsapp-status.php`, `script/message-center.js`, `script/whatsapp-monitor.js`, `mgs-integration-lib.php`, `db.php`, `radius-*.php`, `config.php`). Yalnız D6 (`index.php`, `images/`) #8 ilə üst-üstə düşür, ona görə #8 birləşəndən sonra edilməlidir.

Kod, DB və konfiqurasiya dəyişikliyinə, push və PR-ə başlamazdan əvvəl sizdən icazə gözləyirəm.
