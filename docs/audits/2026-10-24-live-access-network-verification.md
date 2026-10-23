# Canlı giriş şəbəkəsi yoxlaması — 2026-10-24

## Təsdiqlənənlər

- RADIUS accounting cədvəlində son 20 dəqiqədə 2,925 unikal PPPoE istifadəçisi görünür.
- Eyni 20 dəqiqəlik pəncərədə bir istifadəçi adına aid paralel iki accounting qeydi tapılmadı.
- 4000395 hesabı RADIUS-dan IP alıb və routerdə real DNS/HTTPS NAT trafik yaradıb.
- Mandarin korporativ hesabı öz statik IP-si ilə aktiv sessiya və real trafik göstərib.
- 17 statik IP təyinatı unikaldır. Onlardan 15-i yoxlama anında eyni istifadəçi/IP ilə canlı sessiyada idi, 1000964 vaxtı bitmişdi, 2320443 üçün canlı sessiya yox idi.
- Routerin idarəetmə prosessor yükü təxminən 5%, QFP yükü 13–14% idi.
- Router syslog-u 10.99.99.12 ünvanında qəbul olunur və rotasiya edilir.
- iPhone üçün `/ops/` HTTPS ilə açılır, PWA manifesti və yenilənmə faylları keşlənmədən verilir.

## Aşkarlanan boşluqlar

- Routerdə bəzi sürətli yenidən qoşulma cəhdlərində əvvəlki virtual interfeys bağlanmamış eyni IP üçün `already in use` xəbərdarlığı yaranır. RADIUS bazasında son 20 dəqiqədə paralel sessiya görünmədiyi üçün bu, iki müştəriyə eyni IP verilməsindən çox, köhnə PPP virtual interfeysinin gec bağlanmasına uyğundur.
- `Simultaneous-Use` hazırda tətbiq olunmur. Mövcud 167 mindən çox köhnə açıq accounting sətrinə əsaslanan qaydanı birbaşa aktivləşdirmək düzgün deyil; real NAS yoxlaması üçün Cisco SNMP community məlumatı RADIUS `nas` sətrində yoxdur.
- NetFlow qəbul faylı mövcuddur, amma routerdə flow exporter/monitor qurulmayıb. Buna görə mənbə IP/port, təyinat IP/port və vaxt üzrə hüquqi NAT jurnalı hələ formalaşmır.
- Routerdə əvvəlcədən olan `172.16.5.2/32 via 192.168.100.201` host marşrutu ilə serverin OLT mənbə ünvanı bərpa edildi. Canlı probe 17 cihazdan 16-sına çatdı; yalnız 172.16.5.26 cavab vermədi. Doqquz OLT və 172.16.5.41 switch-də SSH portu açıqdır. HSGQ SSH giriş məlumatı və işlək SNMP profili olmadığı üçün real SN/MAC inventarı hələ təsdiqlənməyib.

## Təhlükəsiz tamamlama ardıcıllığı

1. HSGQ SSH məlumatını və cihazlarda işləyən SNMP read profillərini vault-a daxil etmək.
2. Hər OLT üçün autentifikasiyalı SNMP/SSH probe-u keçirib yalnız uğurlu cihazları canlı axtarışa daxil etmək.
3. ASR1006-da əvvəlcə seçilmiş/sampled Flexible NetFlow pilotu qurmaq, collector itkisiz qəbul etdikdən sonra tam CGNAT ixracını mərhələli artırmaq.
4. ClickHouse CGNAT sxemini və retention siyasətini aktivləşdirib hüquq-mühafizə ixracını canlı məlumatla sınaqdan keçirmək.
5. Cisco NAS üçün təhlükəsiz real-session verification qurulduqdan sonra `Simultaneous-Use := 1` qaydasını pilot istifadəçilərlə sınaqdan keçirmək.
