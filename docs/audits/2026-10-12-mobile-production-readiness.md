# Mobil əməliyyat sistemi — 12 oktyabr buraxılış hazırlığı

Tarix: 2026-10-08  
Mühit: lokal XAMPP API + fiziki Android cihaz (`RFCX41J685B`)

## Hazır olan funksiyalar

- 13 real əməkdaş hesabı yaradılıb və rollar tətbiq edilib.
- `s.abdullayev@superoptic.net` super admin hesabı bütün rəhbər səlahiyyətlərinə malikdir.
- Super admin mobil tətbiqdən əməkdaşların son girişini, uğursuz girişlərini və aktiv cihazlarını görür; parolu sıfırlaya və bütün əvvəlki sessiyaları ləğv edə bilir.
- Ruslan Gülaliyev admin kimi bütün ərazilərə, rəhbər, operator, texnik, baş texnik, NOC, mühəndis, anbar və maliyyə funksiyalarına çıxış alıb.
- Suğra Əhmədzadə və Rüfanə Dəmirçiyeva operator və anbardar səlahiyyətlərinə malikdir.
- Məhsəti Məmmədova və Gülüzar Əhmədli operator/NOC monitorinqinə çıxış alıb.
- Operator panelində canlı NOC göstəriciləri, şəbəkə hadisələri, əməkdaşların xəritədə mövqeyi, cari işi və yaxın biletlər görünür.
- Bilet axını `İcraya başladım -> Təhvil verdim` modelinə keçirilib. Əvvəlki işdən növbəti işə keçən vaxt yol vaxtı kimi hesablanır.
- Gün sonu rəhbər hesabatı görülən, başlanmış, tamamlanmış, yarımçıq işləri, iş və keçid vaxtlarını hesablayır.
- GPS ərazi və evdə qalma nəzarəti, nahar/iş vaxtı istisnaları və rəhbər bildirişləri tətbiq edilib.
- Müştəri bonusu eyni ayda yalnız bir əməkdaşa bağlana bilir.
- QA giriş düymələri tətbiqdən çıxarılıb və 5 QA hesabı bazadan silinib.

## 8 oktyabr yoxlaması

- 13/13 yekun hesab real HTTP login API-si ilə uğurla daxil oldu.
- Super admin hesab təhlükəsizliyi API-si işləyir.
- Parol sıfırlama, sessiyaların ləğvi və yeni parolla giriş sınaqdan keçdi.
- Suğra və Rüfanə anbar panelinə daxil oldu.
- Operator NOC paneli və Ruslanın rəhbər/anbar panelləri cavab verdi.
- `flutter analyze`: problem tapılmadı.
- Flutter testləri: 4/4 keçdi.
- Mobil təhlükəsizlik: 8 yoxlama keçdi.
- Mobil əsas API smoke: 9/9 keçdi.
- Mobil nəzarət və bonus unikal sahib testləri keçdi.
- APK fiziki telefona quruldu; tətbiq cold start ilə açıldı və Android jurnalında `FATAL EXCEPTION`, `Bad notification` və şəbəkə xətası görünmədi.
- ADB bağlantısı: `tcp:8081 -> tcp:80`.

## 10 oktyabr operator və müştəri səlahiyyətləri

- Copilot test texnikləri, Xəyyam Fərəcov və Heydər Əliyev hesabları silindi; keçmiş bilet tarixçəsi saxlanıldı.
- Bir əməkdaş hesabı eyni vaxtda yalnız bir aktiv cihazda işləyir; super admin cihaz sessiyasını ləğv edə bilir.
- Operator tətbiqdən yeni müştəri yarada, avtomatik müştəri kodu və boş IP ala, router/paket/pool seçə bilir.
- Operator müştərinin paketini, telefonunu və 30 günlük/stabil ödəniş rejimini dəyişə bilir; hər əməliyyat səbəb və icraçı ilə auditə yazılır.
- Operator əlavə xidmət və avadanlıq üçün məbləğ və sənəd nömrəsi ilə borc yaza bilir.
- Müştəriyə Bakı təqvim ayı ərzində yalnız bir dəfə, maksimum 3 borc günü verilə bilər. Günlər növbəti tam ödəniş müddətindən çıxılır.
- Operator müştəri panelinin giriş adını görə və səbəb yazmaqla müvəqqəti şifrə yarada bilir; şifrə ilk girişdə dəyişdirilməlidir.
- Müştərinin ad-soyadı, ünvanı və FIN-i ilkin qeydiyyatdan sonra yalnız təyin edilmiş super admin tərəfindən dəyişdirilə bilər.
- Operator Static IP, Local/NAT IP və RADIUS yeniləmə əməliyyatlarını icra edə bilir; IP tarixçəsi icraçı və səbəblə saxlanılır.
- Operator, dəstək və NOC canlı müştəri/modem texniki vəziyyətini görə bilir; texnik yalnız özünə təhkim olunmuş müştərilərlə məhdudlaşır.
- Gülüzar Əhmədli və Məhsəti Məmmədova üçün gecə növbəsi və saatlıq tətbiq aktivliyi nəzarəti işləyir; gecikmə rəhbər xəbərdarlığı yaradır.
- GPS internet olmadıqda telefonda vaxt və koordinatla saxlanır, internet gələndə serverə sinxron edilir. Offline vaxtda rəhbər yalnız son sinxron mövqeni görür.

Yoxlama: operator siyasəti üzrə 16 izolyasiya edilmiş sınaq, borc gününün ödənişdən çıxılması üzrə 4 sınaq, mobil təhlükəsizlik üzrə 8 sınaq, əsas mobil API üzrə 9 sınaq, Flutter statik analiz və 4 dar ekran testi keçdi.

## Buraxılışdan əvvəl əməliyyat addımları

1. Lokal ADB ünvanını istehsal HTTPS API ünvanı ilə əvəz edib imzalı release APK/AAB yığmaq.
2. Android release signing açarını təhlükəsiz saxlayıb Play/App Distribution kanalını seçmək.
3. SMS/OTP, push bildirişi və GPS fon izləməsini real istehsal provayderləri ilə qəbul sınağından keçirmək.
4. Ərazi geofence-lərini və əməkdaşların ev koordinatlarını yalnız səlahiyyətli HR/super admin vasitəsilə doldurmaq.
5. İlkin parolları əməkdaşlara fərdi və təhlükəsiz kanalla çatdırmaq; ilk girişdən sonra dəyişdirmək.
6. 12 oktyabr səhəri verilənlər bazası ehtiyat nüsxəsi, worker vəziyyəti, HTTPS sertifikatı və API health yoxlaması aparmaq.

## Artefaktlar

- Android test APK: `mobile/superoptic_ops/build/app/outputs/flutter-apk/app-debug.apk`
- Yekun giriş məlumatları: `data/private/mobile-production-credentials-20261008.json`
- Telefon ekran sübutu: `mobile/superoptic_ops/build/phone-final.png`

Giriş məlumatları faylı repozitoriyaya və ümumi paylaşım kanalına göndərilməməlidir.
