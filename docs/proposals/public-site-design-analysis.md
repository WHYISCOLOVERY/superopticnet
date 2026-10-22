# superoptic.net — dizayn analizi (yalnız oxuma, kodda dəyişiklik yoxdur)

Yoxlama: 22.10.2026, Chromium, masaüstü 1440px və mobil 390px. Ekran görüntüləri: `desktop-top.png`, `mobile-top.png`.

## Güclü tərəflər
- Tünd mavi/cyan/bənövşəyi palitra ardıcıldır, CTA düymələri (gradient) aydın seçilir.
- Başlıq iyerarxiyası, kartların künc radiusu və boşluqlar bütün bölmələrdə vahiddir.
- Mobil: üfüqi səhifə scrollu yoxdur (scrollWidth = 390), kartlar tək sütuna düzgün düşür, 12 şəklin hamısında alt mətn var.
- Orta paket ("Ailə üçün") vizual olaraq düzgün vurğulanıb.

## Problemlər (əhəmiyyətə görə)
1. **Yükləmə ekranı (preloader) çox qalır.** "Super Optik Tech" splash ekranı `networkidle`-dan sonra da görünürdü, yalnız ~5 san gözləyəndə keçdi. Ziyarətçi boş tünd ekran görür; həmçinin loqo adı ("Super Optik Tech") saytdakı ("SuperOptik") ilə fərqlidir.
2. **Yuxarı menyu masaüstündə sıxışıb.** "Dəstək mərkəzi" və telefon nömrəsi (+994 12 525 88 11) iki sətrə sınır, "Dəstək" menyuda iki dəfə var (biri "Dəstək mərkəzi", biri "Dəstək"). Menyu elementləri çoxdur, hizalanma pozulur.
3. **Hero formasında işıq kürələri mətnin üstündən keçir.** Parlayan "orb"lar "1 gün qoşulma planı" və forma etiketlərinin oxunaqlığını azaldır; "Ad və soyad" etiketi digərlərindən solğun görünür (fərqli rəng/opaklıq).
4. **Forma uyğunsuzluqları.** İki xəbərdarlıq mətni üst-üstə düşür ("Əvvəlcə ərazi və nömrəni yazın" bir yerdə, "1 dəqiqə çəkir" ayrıca), kiçik boz mətn tünd fonda zəif kontrastdadır. Select sahələri ("Ərazini seçin", "Xidməti seçin") input-lardan fərqli mətn rəngi və ölçüsü ilə görünür. Əsas paketlərdə düymə adı "Göndər" qalır, "Seç" ilə qarışır (tarif cədvəlində "Seç"), ardıcıllıq yoxdur.
5. **Statistika sayğacları 0 göstərir** (Uptime, Orta ping, Şəbəkə tutumu, Aktiv abunəçi) və Download/Upload zolaqları boşdur. Animasiya scroll olmadan başlamır ola bilər; lakin skrinşotda və yavaş şəbəkədə "0" görünür. Real dəyərlər ya skeleton, ya fallback olmalıdır.
6. **Tarif cədvəli karuseli.** Masaüstündə sağ kənarda kəsilmiş kart görünür, mobil görünüşdə ikinci kart yarımçıq kəsilir (bu qəsdən swipe işarəsidir, amma oxlar mobildə yoxdur və nöqtə göstəricisi yoxdur). Ölçmə: `.tariff-row` və `.tr-btn` viewport sərhədindən çıxır (overflow), yəni mobil jest olmadan istifadəçi onu anlamaya bilər.
7. **TV kanalları bölməsi.** Sol "lent" kanal xətti yarıya qədər boşdur (3 sətir, altında böyük boş sahə ~300px) və sağdakı axtarış paneli ilə hündürlük uyğunsuzdur. Kanal adları kiril/latın qarışıqdır, bu normaldır, lakin ikon "36/AB/XƏ" abbreviaturaları mənasızdır, loqolar yoxdur.
8. **Fiber xətti ("Backbone → Ev") və xəritə kartı.** Texniki sxemin mətni çox solğun (boz üzərində boz, kontrast aşağı), xəritə kartı demək olar boşdur (az nöqtə, region adları yoxdur), "Əhatə" çipləri ilə əlaqəsi zəifdir.
9. **Ödəniş kanalları.** Loqolar əvəzinə rəngli düzbucaqlı + mətn var (eManat+ ağ fon, HESAB.AZ yaşıl, million/BirBank qırmızı). Palitradan tamamilə kənardır və mətn kartın alt hissəsində, mərkəzlənməyib. Real loqo lazımdır.
10. **Xəbər kartları** ardıcıl və oxunaqlıdır, amma bütün tarixlər 17-24 aprel 2026-dır (6 ay köhnə), "Kripto" kateqoriyası internet provayderi üçün kontekstdən kənardır.
11. **Şriftlər.** Hesablanmış şrift ailəsi `-apple-system`/Arial görünür, lakin skrinşotda Inter + monospace rəqəmlər (tariflər) var. Rəqəmlər üçün monospace şrift ("100 Mbit", "25 AZN") digər mətnlə üslub baxımından fərqlənir; qərar verilməlidir (ya hər yerdə, ya heç yerdə).
12. **Toxunma hədəfləri.** Mobildə 12, masaüstündə 14 interaktiv element 32px-dən kiçikdir (çip filtrlər, sosial ikonlar footer-də, karusel oxları). Tövsiyə: minimum 44×44px.
13. **Footer.** Loqo ətrafında parıltı dairəsi kəsilir (sol-yuxarı), alt hüquq yazısı bütövlüklə böyük hərflə və solğundur, "ICT" ikonu digərlərindən fərqli (mətn).
14. **WhatsApp düyməsi** sağ-aşağıda sabitdir və mobildə formanın/mətnin üzərinə düşür (hero skrinşotunda mətnlə kəsişir).

## Tövsiyə olunan ilk 5 addım
1. Preloader-i 1 saniyədən çox saxlamamaq (və ya ləğv etmək), brend adını vahidləşdirmək.
2. Menyunu qısaltmaq (dublikat "Dəstək"i silmək), telefonu `white-space: nowrap` etmək.
3. Hero formasında dekorativ orb-ları formanın arxasına salmaq (`z-index`), xəbərdarlıq mətnlərini birləşdirmək.
4. Sayğacları real/fallback dəyərlə göstərmək, boş Download/Upload zolaqlarını doldurmaq və ya silmək.
5. Ödəniş loqolarını real SVG ilə əvəz etmək, toxunma hədəflərini 44px-ə çatdırmaq.

## Məhdudiyyətlər
- Yalnız ana səhifə (`/`) yoxlanıb; digər ictimai səhifələr (haqqimizda, cihazlar, gizlilik, qaydalar, keyfiyyət göstəriciləri, portal-login) hələ baxılmayıb.
- Dizayn qiymətləndirməsi skrinşotlara və DOM ölçülərinə əsaslanır; animasiyaların (sayğac, zolaq) real brauzerdə necə oynadığı ayrıca yoxlanmalıdır.
- Hesabatdakı bəzi detallar (məs. "Xidməti seçin" yazılışı) kiçik skrinşotdan oxunub, kodda təsdiqlənməyib.
