# Köhnə billing, RADIUS və ödəniş miqrasiyası — 2026-10-16

## 2026-10-21 yekun keçid vəziyyəti

- Keçid 21 oktyabr 2026, Bakı vaxtı ilə 12:38-də tamamlandı.
- `billing.superoptic.net` (`85.132.57.220`) üçün TCP 80/443 NAT yeni MGT serverinə (`192.168.100.201`) yönəldildi. Köhnə VM-in tam 1:1 NAT sətri silindi və inbound ACL yalnız 80/443 ilə məhdudlaşdırıldı.
- Router RADIUS hədəfi `10.99.99.12:1812/1813` oldu və konfiqurasiya yaddaşa yazıldı.
- Son reconciliation zamanı köhnə və yeni sistemdə `147027` ödəniş sətri eyni idi.
- Post-cutover readiness 13/13 keçdi: 5599 müştəri/xidmət, 78 aktiv tarif, 4 IP hovuzu, çatışmayan aktiv PPPoE auth sətri 0, vaxtı bitmiş aktiv xidmət 0, outbox qalığı 0.
- Public ödəniş endpointi HTTP 200 və autentifikasiyasız sorğu üçün gözlənən `status=408` kontraktını qaytarır.
- Köhnə Proxmox VM 100 dayandırılıb; rollback üçün silinməyib.
- Router keçiddən 7 dəqiqə sonra yeni RADIUS-u `UP` göstərirdi: 22030 auth sorğusu, 19579 accept, 2453 reject, 1 timeout; orta auth cavabı 124 ms idi.
- Keçiddən əvvəl şifrəli seyfdən parolu yenidən oxumayan RADIUS resync xətası düzəldildi və 2667/2667 çatışmayan auth sətri bərpa edildi.
- Hazırlıq zamanı kommunikasiya worker-i səhv ardıcıllıqla xarici Telegram göndərişini qısa müddət aktivləşdirdi; 109 köhnə queued mesaj `sent`, 20-si `failed` oldu. Təkrar göndərişin qarşısı üçün statuslar saxlanıldı və keçid helper-ində xarici çatdırılma ayrıca son mərhələ qapısına keçirildi.

## Məqsəd və təhlükəsiz icra qaydası

Bu yoxlama köhnə `billing.superoptic.net` sisteminin funksional davranışını yeni
`mgt.superoptic.net` sisteminə köçürmək üçündür. Köhnə VM söndürülməyib, router
RADIUS ünvanı dəyişdirilməyib və real ödəniş yazısı göndərilməyib. Köhnə VM-in
`erewr` snapshotı yalnız oxuma rejimində açılıb və hər yoxlamadan sonra NBD
qoşulması ayrılıb.

Müştəri məlumatı və konfiqurasiya mənbələri `data/private` altında saxlanılır;
bu sənəddə parol, API açarı, RADIUS shared secret və şəxsi müştəri məlumatı yoxdur.

## Faktiki tapıntılar

### Köhnə billing məlumatı

- Canlı siyahıda 5 576 müştəri var.
- 5 576 profilin hamısı ixrac edilib; unikal müştəri sayı 5 576, parse xətası 0,
  profil xətası 0-dır.
- Hər profil üzrə internet məlumatı, əlavə xidmət cədvəlləri, ödəniş tarixçəsi,
  müştəri dəyişiklik logu, RADIUS sessiya tarixçəsi və giriş cəhdləri saxlanılıb.
- Qorunan server arxivi:
  `/var/backups/superopticnet/legacy-billing-live-20261016.tgz`
- Arxiv SHA-256:
  `6b2803bd60621366a976b42145bde542636cb0065975f7e2f0b78731df97c79d`

### Köhnə RADIUS quruluşu

- Ubuntu 24.04 və FreeRADIUS 3 istifadə olunur.
- MySQL SQL modulu aktivdir; `sql`, `pap`, `chap` və `mschap` axınları işlədilir.
- Köhnə sxemdə `users`, `nas`, `radacct`, `radacct_active`, `radpostauth`,
  `definitions` və `port_mappings` cədvəlləri var.
- `radius-cleanup`, `radius-disconnect`, `radius-status` və `radius-watchdog`
  systemd xidmətləri, həmçinin dəqiqəlik sync/borc/xidmət cronları mövcuddur.
- Bu quruluş olduğu kimi kopyalanmır. Yeni sistem standart `radcheck`, `radreply`,
  `radacct`, `radpostauth` modeli ilə eyni əməliyyat nəticəsini daha məhdud SQL
  səlahiyyətləri ilə təmin edir.

### Köhnə ödəniş inteqrasiyası

Köhnə kontraktın faktiki endpointləri:

- `GET /payments/index.php?id=<customer>` — müştəri sorğusu;
- `POST /payments/payments.php` — `id`, `amount`, `transaction-id`, istəyə görə
  `agent` və `paymen`;
- `GET /payments/check.php?id=<transaction>` — ödəniş yoxlaması.

Köhnə UI tarixçəsində Million, Yığım, Kapital Bank, Paşa Bank, ePul,
ExpressPay, Emanat, FPay, Payriff və Hesab.az mənbələri fərqləndirilir. Faktiki
server kontraktı statik Basic credential xəritəsindən provider adını çıxarır,
transaction ID üzrə təkrar yazını yoxlayır, balansı artırır, əlavə xidmət
borclarını ödəyir və kifayət edən balansda internet xidmətini aktivləşdirir.

Köhnə implementasiyada bütöv əməliyyatı əhatə edən DB transaction yoxdur;
balans, xidmət və tarixçə yazılarından biri yarıda qala bilər. HMAC/timestamp
və source-IP yoxlaması da yeni kontraktdakı səviyyədə deyil. Köhnə mənbədə
hardcoded SMS credential tapılıb; dəyər bu hesabatda saxlanılmayıb və köhnə
sistem söndürülməzdən əvvəl provider tərəfində dəyişdirilməlidir.

Yeni sistemdə Emanat və United Payment üçün ayrıca credential, HMAC-SHA256,
timestamp, provider IP allowlist, idempotency key, correlation ID və asinxron
`payment_state` axını mövcuddur. `mgt.superoptic.net` üzərində hər iki providerin
imzalanmış, mövcud olmayan müştəri sorğusu gözlənən cavabla keçib; real pul
əməliyyatı göndərilməyib.

## Yeni MGT-də görülən işlər

- MySQL tətbiq credentialı ilə Apache runtime credentialı yenidən uzlaşdırılıb.
- `https://mgt.superoptic.net/api/health.php` DB bağlantısı ilə `status=ok`
  qaytarır.
- Dəyişiklikdən əvvəl qorunan DB backup alınıb:
  `/var/backups/superopticnet/superoptic_portal-before-legacy-import-20261016-094123.sql.gz`
- Köhnə ödəniş endpointinin 146 225 sətri idempotent emal edilib:
  1 073 yeni yazı yaradılıb, 145 152 mövcud external ID yenilənib, 0 sətr
  format xətası ilə rədd edilib.
- Paket kataloqunda 74 paket və köhnə IP hovuz kataloqu yenilənib.
- FreeRADIUS 3.2.5 izolyasiya rejimində işləyir:
  `127.0.0.1:11812` auth və `127.0.0.1:11813` accounting.
- `freeradius -C` konfiqurasiya yoxlaması və müvəqqəti istifadəçi ilə real
  `Access-Accept` testi keçib; test sətrləri dərhal silinib.

## İdxal və RADIUS yekun yoxlaması

- 5 576 müştərinin hamısı idxal olunub: 19 hesab yaradılıb, 5 557 hesab
  yenilənib, failed 0 və çatışmayan PPPoE sirri 0-dır.
- Bir mənbə IP konflikti idxal zamanı təhlükəsiz şəkildə qeydiyyata alınıb.
  Yekun aktiv xidmət yoxlamasında təkrarlanan IP sayı 0-dır.
- 5 576 hesab üzrə 55 760 qorunan profil bölməsi yazılıb; missing 0, error 0-dır.
- 146 225 ödənişin hamısı external ID ilə uyğunlaşdırılıb; reject 0-dır.
- 5 576 köhnə hesabın hər birində xidmət snapshotı, aktiv IP təyinatı və
  AES-256-GCM ilə qorunan PPPoE sirri var. Açıq mətn sirr və yararsız şifrəli
  sətr sayı 0-dır.
- RADIUS sinxronu ümumilikdə 5 599 xidmət üçün tamamlanıb; failed 0-dır.
  Sinxron sonrası loopback `Access-Accept`, accounting Start və accounting Stop
  testləri real paketlə keçib və test sətrləri silinib.
- CoA/disconnect plan testi keçir, lakin canlı NAS hədəfi yeni MGT-də hələ
  qeydiyyata alınmadığı üçün canlı transport dry-run başlanmır. Router keçidi
  zamanı NAS IP, shared secret və source-IP allowlist əlavə edildikdən sonra bu
  yoxlama təkrarlanmalıdır.

## Qalan xarici qəbul addımları

1. Ödəniş providerlərindən test source IP-ləri və test transaction pəncərəsi
   alınmalıdır. Emanat və United Payment üçün imzalanmış lookup artıq keçir;
   test payment, eyni transaction retry və status reconciliation providerlə
   birlikdə yoxlanmalıdır.
2. Router NAS hədəfi əlavə edilməli, CoA/disconnect dry-run keçirilməli və sonra
   yeni FreeRADIUS standart `1812/1813` portlarına açılmalıdır.
3. Routerdə RADIUS IP dəyişdirildikdən sonra auth, accounting və disconnect
   nəticələri ən azı bir paralel reconciliation dövründə köhnə sistemlə
   müqayisə edilməlidir. Bu dövr bitənədək köhnə billing söndürülməməlidir.
