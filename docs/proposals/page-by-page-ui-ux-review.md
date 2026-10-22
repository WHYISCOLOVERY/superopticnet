# superoptic.net səhifə-səhifə UI/UX icmalı (22.10.2026)

Metod: Chromium ilə desktop (1440px) və mobil (390px) üzrə hər səhifə tam scroll edilib, tam səhifə skrinşotları və DOM yoxlamaları götürülüb. Menyu sınması, təkrarlanan "Dəstək", təkrar hero xəbərdarlığı və tap-target məsələləri PR #6-da olduğu üçün təkrar yazılmayıb. Qeyd: tam səhifə skrinşotunda sticky header ortada görünür, bu skrinşot artefaktıdır, xəta deyil. Kod dəyişdirilməyib.

## 1. Ana səhifə (/)

**Yaxşıdır**
- Hero aydındır: başlıq, qiymət (25 AZN), üç fayda kartı, əsas CTA və qoşulma formu bir ekranda.
- Statistika (99.98%, 8 ms, 120 Gbps, 18 450+) scroll-dan sonra düzgün sayır, sayğaclar sınıq deyil.
- 3 əsas paket + "Tam tarif cədvəli" (Bütün/Ev/Biznes filtri) qərarı sadələşdirir.
- Əhatə xəritəsi, rayon çipləri, FAQ akkordeonları, TVNET kanal axtarışı (119 kanal), ödəniş kanalları, 3 dilli (AZ/EN/RU) versiya, mobil hamburger menyu, qaranlıq/işıqlı rejim düyməsi.
- title/description/canonical/og var, h1 tək, alt atributları tam, sınıq şəkil və konsol xətası yoxdur. Preloader ~1.5 san.

**Çatışmır**
- Ödəniş kartlarında loqo yoxdur, rəngli düzbucaqlıda yalnız mətn var (eManat, HESAB.AZ, million, BirBank, IBA). Mobildə 5 hündür kart alt-alta düzülür.
- Müştəri rəyi, qoşulma addımları ("müraciət → texniki baxış → qoşulma"), sertifikat/etibar elementləri yoxdur.
- Tarif cədvəlində bütün sətirlər "100 Mbit" göstərir, fərq yalnız TV/telefon qatışığıdır. Müqayisə cədvəli və "nəyi daxildir" izahı yoxdur.
- Mobildə səhifə ~14 400px hündürlükdədir, xəbər lentindən 9 kart alt-alta gəlir.
- TVNET lent bölməsinin sol hissəsində 3 sətirdən sonra ~300px boş qara sahə qalır (desktop və mobil).

**Düzəldilməlidir**
1. Paket kartlarındakı düymə "Göndər" yazır, "Paketi seç" / "Sifariş et" olmalıdır. Mövcud forma düyməsi ilə eyni sözdür, mənanı itirir.
2. Kartlarda "Download / Upload / 4K TV / Gaming / Streaming" ingilis dilindədir, AZ və RU versiyada da tərcümə olunmayıb.
3. Fon "parıltı" nöqtələri mətn və kartların üstündə qalır (başlıq hərflərinin üstündə mavi ləkələr). Mətn oxunaqlığı azalır, z-index aşağı salınmalıdır.
4. Header fonsuzdur: scroll edəndə altındakı məzmun (düymələr, bölmə başlığı) menyunun arxasından görünür. Bərk fon və ya blur lazımdır.
5. RU versiyada mobildə üfüqi overflow var (scrollWidth 398 > 390). Tarif sətirləri və dekorativ canvas hissələri səbəb ola bilər, `overflow-x: clip` və ya konteyner düzəlişi lazımdır.
6. Telefon nömrəsi header-də iki sətrə düşür ("…88 / 11"), `white-space: nowrap` (menyu sınması PR #6-dadırsa orada yoxlanmalıdır).
7. Kiçik şrift: DOM-da 12px-dən kiçik mətn olan çoxlu element tapıldı (əsasən kart alt yazıları, "Paket: Standart", xəbər tarixləri). Mobildə minimum 12-13px olmalıdır.

## 2. Haqqımızda (/haqqimizda.php)

**Yaxşıdır:** təmiz başlıq, 3 dəyər kartı, oxunaqlı mətn, footer və menyu ardıcıldır.

**Çatışmır:** komanda/şirkət tarixi, rəqəmlər, şəkil və ya foto, lisenziya/ICTA məlumatı, əlaqə CTA-sı. Səhifə qısadır və boş görünür.

**Düzəldilməlidir:** hər kartın başlığı üstündə boş ağ kvadrat ikon yerləri var (ikon yüklənməyib və ya ikon sinfi yoxdur). Ya real ikon qoyulmalı, ya silinməlidir. canonical və og etiketləri yoxdur.

## 3. Keyfiyyət göstəriciləri (/keyfiyyet-gostericileri.php)

**Yaxşıdır:** mətn qısa və aydındır, vədlər konkretdir (1 təqvim günü, 1000 Mb/s).

**Çatışmır:** real göstərici yoxdur, yalnız bullet siyahı var. Uptime, orta cavab müddəti, müraciət sayı kimi rəqəm kartları (ana səhifədəki statistika ilə eyni mənbədən) və tarix göstəricisi lazımdır.

**Düzəldilməlidir:** bullet siyahı sıradan, kartsız, sol tərəfdə dar və hər iki yanda boş sahə ilə qalıb, digər səhifələrin kart dilindən fərqlənir. Səhifə ~1000px-dir, boşluqlar çoxdur. canonical/og yoxdur.

## 4. Cihazlar (/cihazlar.php)

**Yaxşıdır:** 5 cihaz, qiymət (65-100 AZN), qısa təsvir, aydın kart quruluşu.

**Çatışmır:** cihaz şəkilləri, texniki göstəricilər (Wi-Fi standartı, port sayı), "sifariş et / soruş" CTA-sı, müqayisə. Hər kart eyni görünür, alış qərarına kömək etmir.

**Düzəldilməlidir:** qiymətlər yalnız mətn kimidir, vurğulanmayıb. Mobildə "BDCOM dual-band modem" başlığı söz ortasından bölünür ("dual-/band").

## 5. Qaydalar və şərtlər (/qaydalar-ve-sertler.php)

**Yaxşıdır:** nömrələnmiş bölmələr, oxunaqlı sətir uzunluğu, bölmə başlıqları qalındır.

**Çatışmır:** mündəricat (anchor naviqasiya), "yuxarı qayıt" düyməsi, son yenilənmə tarixi. Mobildə ~6700px-lik divar mətnidir.

**Düzəldilməlidir:** mətn başlıq bloku ilə (sola) düzülməyib, məzmun daha dar və mərkəzə sürüşüb, hizalanma qırılır. Bullet + "1.1." qoşa işarələmə lazımsızdır. Fon parıltı nöqtələri mətn üstündə qalır. canonical/og yoxdur.

## 6. Gizlilik siyasəti (/gizlilik-siyaseti.php)

Qaydalar səhifəsi ilə eyni şablondur, eyni qeydlər keçərlidir (mündəricat yox, uzun mətn, hizalanma, son yenilənmə tarixi yoxdur). Əlavə olaraq: kuki/analitika izahı və məlumat sorğusu üçün əlaqə ünvanı görünən yerdə olmalıdır.

## 7. Portal girişi (/portal-login.php)

**Yaxşıdır:** sadə forma, abunəçi kodu + şifrə, robot yoxlaması, "Şifrəni unutmusunuz? WhatsApp kodu ilə" axını.

**Çatışmır:** şifrəni göstər düyməsi, qayıt/ana səhifə linki, dil seçimi, meta description.

**Düzəldilməlidir:**
1. Dizayn qopuqdur: ana sayt tünd, portal açıq rəngli və narıncı düymədir. Brend hissi itir.
2. Mətn diakritiksizdir: "Musteri", "Sifre", "Meselen", "odenis", "muraciet", "acilacaq". Azərbaycan hərfləri düzgün olmalıdır. Başlıqda "Təhlükəsizlik hesablaması" isə düzgün yazılıb, yəni səhifə özü ilə ziddiyyətlidir.
3. "Daxil ol" düyməsi kiçik və sola yığılıb (forma enində deyil), əsas əməliyyat üçün zəif.
4. Doldurulmamış etiketlər: 1 sahə (hesablama cavabı) label/aria-label olmadan qalıb. Səhifə yüklənəndə 1 ədəd 404 resurs sorğusu var (konsolda).
5. "Sayta qayıt" linki zəif görünür, kiçik mətndir.

## 8. Digər tapıntılar

- /status.php 404 qaytarır (heç yerdən linklənməyib, lakin xəta səhifəsi stilsizdir). Mobildə 404 səhifəsi 595px enində üfüqi scroll verir. Brendli 404 səhifəsi lazımdır.
- Footer-də ana səhifədə qlobus ikonu, daxili səhifələrdə Instagram ikonu var, ardıcıl deyil. Başlıq altındakı ilk link başlığa yapışıb (Haqqımızda başlığı ilə arasındakı boşluq digər sətirlərdən az).
- Daxili səhifələrdə "skip to content" linki, breadcrumb və CTA yoxdur. Ana səhifədə də skip link yoxdur.
- Əlçatanlıq: tünd mövzuda boz mətnin kontrastı (xəbər tarixləri, "Standart" yazıları, footer copyright) AA həddinə yaxındır və ya altındadır, ayrıca ölçülməlidir.

## Prioritet siyahı

1. Portal giriş səhifəsində diakritik düzəlişi + tünd mövzuya uyğunlaşdırma.
2. Paket düymələri ("Göndər" → "Seç") və tərcümə olunmamış ingilis etiketlər.
3. Header fonu və dekorativ parıltının mətn üstündən çıxarılması.
4. RU mobil overflow və 404 səhifə overflow.
5. Daxili səhifələrin (Haqqımızda, Keyfiyyət, Cihazlar) məzmun zənginləşdirilməsi və boş ikon kvadratlarının düzəldilməsi.
6. Ödəniş loqoları və TVNET boş sahəsi.
7. Hüquqi səhifələrdə mündəricat və son yenilənmə tarixi, canonical/og etiketləri.

Qeyd: yoxlama canlı www.superoptic.net üzərində aparılıb. PR #5 branch kodu ilə fərq ola bilər. Düzəlişlərə başlamazdan əvvəl sizdən icazə gözləyirəm.
