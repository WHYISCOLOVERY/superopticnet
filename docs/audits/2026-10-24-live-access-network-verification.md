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
- Server 10.99.99.12-dən OLT VLAN-a paket göndərir, lakin OLT-lər 10.99.99.0/24 şəbəkəsinə dönüş marşrutu vermir. Router özü 172.16.5.x OLT-ləri görür. HSGQ cihazlarının SSH giriş məlumatı vault-da olmadığı üçün serverdən real SN/MAC axtarışı hələ təsdiqlənməyib.

## Təhlükəsiz tamamlama ardıcıllığı

1. OLT-lərdə `10.99.99.0/24 via 172.16.5.5` dönüş marşrutunu tətbiq etmək və HSGQ SSH məlumatını vault-a daxil etmək.
2. Hər OLT üçün SNMP/SSH probe-u keçirib yalnız uğurlu cihazları canlı axtarışa daxil etmək.
3. ASR1006-da əvvəlcə seçilmiş/sampled Flexible NetFlow pilotu qurmaq, collector itkisiz qəbul etdikdən sonra tam CGNAT ixracını mərhələli artırmaq.
4. ClickHouse CGNAT sxemini və retention siyasətini aktivləşdirib hüquq-mühafizə ixracını canlı məlumatla sınaqdan keçirmək.
5. Cisco NAS üçün təhlükəsiz real-session verification qurulduqdan sonra `Simultaneous-Use := 1` qaydasını pilot istifadəçilərlə sınaqdan keçirmək.
