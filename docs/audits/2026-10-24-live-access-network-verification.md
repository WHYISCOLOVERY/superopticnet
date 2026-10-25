# Canlı giriş şəbəkəsi yoxlaması — 2026-10-24

## Təsdiqlənənlər

- RADIUS accounting cədvəlində son 20 dəqiqədə 2,925 unikal PPPoE istifadəçisi görünür.
- Eyni 20 dəqiqəlik pəncərədə bir istifadəçi adına aid paralel iki accounting qeydi tapılmadı.
- 4000395 hesabı RADIUS-dan IP alıb və routerdə real DNS/HTTPS NAT trafik yaradıb.
- Mandarin korporativ hesabı öz statik IP-si ilə aktiv sessiya və real trafik göstərib.
- 17 statik IP təyinatı unikaldır. Onlardan 15-i yoxlama anında eyni istifadəçi/IP ilə canlı sessiyada idi, 1000964 vaxtı bitmişdi, 2320443 üçün canlı sessiya yox idi.
- Routerin idarəetmə prosessor yükü NetFlow/NEL aktiv olduqdan sonra 3–5%, QFP emal yükü 3–4% idi.
- Router syslog-u 10.99.99.12 ünvanında qəbul olunur və rotasiya edilir.
- Routerin NAT event logging (NEL) ixracı `10.99.99.12:2055/UDP` kollektoruna aktiv və startup-config-də qalıcıdır.
- Kollektor NAT hadisələrində vaxtı, protokolu, daxili IP/portu, publik çevrilmiş IP/portu, hədəf IP/portu və ADD/DELETE hadisəsini itkisiz oxuyur. İlk tam 5 dəqiqəlik faylda 1,451,566 hadisə və 0 sequence failure qeydə alındı.
- `nfcapd` LZ4 sıxılma, avtomatik expiry, 80 GB həcm limiti, 30 günlük vaxt limiti və 90% watermark ilə işləyir. İlk tam sıxılmış faylda 1,895,884 hadisə 68 MB tutdu, sequence failure 0 qaldı və fayl yenidən uğurla oxundu.
- iPhone üçün `/ops/` HTTPS ilə açılır, PWA manifesti və yenilənmə faylları keşlənmədən verilir.

## Aşkarlanan boşluqlar

- Routerdə bəzi sürətli yenidən qoşulma cəhdlərində əvvəlki virtual interfeys bağlanmamış eyni IP üçün `already in use` xəbərdarlığı yaranır. RADIUS bazasında son 20 dəqiqədə paralel sessiya görünmədiyi üçün bu, iki müştəriyə eyni IP verilməsindən çox, köhnə PPP virtual interfeysinin gec bağlanmasına uyğundur.
- `Simultaneous-Use` hazırda tətbiq olunmur. Mövcud 167 mindən çox köhnə açıq accounting sətrinə əsaslanan qaydanı birbaşa aktivləşdirmək düzgün deyil; real NAS yoxlaması üçün Cisco SNMP community məlumatı RADIUS `nas` sətrində yoxdur.
- Xam NEL jurnalı formalaşır və `nfdump -o nel` ilə axtarıla bilir. Ölçülən sıxılmış həcm cari trafikdə təxminən 20 GB/gün səviyyəsinə çata bilər; 80 GB limit buna görə təxminən dörd günlük tampon verir. ClickHouse/ayrıca böyük log diski və arxiv nüsxəsi qurulana qədər bu, qısamüddətli əməliyyat jurnalıdır.
- `tools/nfdump-law-export.php` canlı xam fayllardan IP/port/vaxt üzrə məhdud ixrac yaradır. Sorğunu edən, səbəb və təsdiqləyən şəxs məcburidir; nəticənin SHA-256 hash-i və sorğu sahəsi append-only audit jurnalına yazılır.
- İlk nümunədə 1001196 hesabının `10.11.17.110` IP-sindən çoxsaylı xarici ünvanların TCP/23 portuna sürətli çıxış aşkarlandı. Bu, abunəçi cihazında bot/skaner ehtimalıdır və ayrıca təhlükəsizlik araşdırması tələb edir.
- Routerdə əvvəlcədən olan `172.16.5.2/32 via 192.168.100.201` host marşrutu ilə serverin OLT mənbə ünvanı bərpa edildi. Canlı probe 17 cihazdan 16-sına çatdı; yalnız 172.16.5.26 cavab vermədi. Doqquz OLT və 172.16.5.41 switch-də SSH portu açıqdır. HSGQ SSH giriş məlumatı və işlək SNMP profili olmadığı üçün real SN/MAC inventarı hələ təsdiqlənməyib.

## Təhlükəsiz tamamlama ardıcıllığı

1. HSGQ SSH məlumatını və cihazlarda işləyən SNMP read profillərini vault-a daxil etmək.
2. Hər OLT üçün autentifikasiyalı SNMP/SSH probe-u keçirib yalnız uğurlu cihazları canlı axtarışa daxil etmək.
3. Xam NEL fayllarını ayrıca böyük diskə və ya ClickHouse ingest node-a köçürmək; hüquqi retention müddətini disk hesabına və tətbiq olunan qaydalara görə təsdiqləmək.
4. ClickHouse CGNAT sxemini və retention siyasətini aktivləşdirib hüquq-mühafizə ixracını canlı məlumatla sınaqdan keçirmək. Cari MySQL cədvəlinə bütün xam axını yazmaq olmaz.
5. Cisco NAS üçün təhlükəsiz real-session verification qurulduqdan sonra `Simultaneous-Use := 1` qaydasını pilot istifadəçilərlə sınaqdan keçirmək.
