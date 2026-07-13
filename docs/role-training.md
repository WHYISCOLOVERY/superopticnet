# SuperOptic MGS Rol Uzre Telimati

Bu sened sistem ishcilerinin isi bir panelden oyrenmesi ucundur. Esas prinsip: musteri, odeme, RADIUS, OLT/ONT, ticket, anbar, HR/KPI ve hesabat melumatlari ayri-ayri proqramlarda yox, SuperOptic MGS daxilinde kontekste gore idare olunur.

## Umumi giris qaydasi

- Musteri girisi: `https://billing.superoptic.net/portal-login.php`
- Emekdash girisi: `https://billing.superoptic.net/staff-login.php`
- Admin/NOC/MGS funksiyalari: emekdash logininden sonra rol icazesi ile acilir.
- MyGov ID modulu hazir saxlanilib, amma aktiv deyil. Aktivlesdirilene qeder login/parol ve rol icazeleri esas goturulur.
- Superpilot agenti emekdashin roluna gore cavab verir ve icra emeliyyatlarinda tesdiq isteyir.

## Super admin / rehber

**Esas sehifeler**
- Dashboard
- Ayarlar
- Istifadeciler ve rollar
- Operations Center
- Backup / Update
- Analitika
- HR & KPI

**Gundelik ish**
- Sistem statusuna bax: MySQL, MGS Node, RADIUS SQL, backup, worker statusu.
- Aktiv alarmlar, kritik ticketler ve SLA gecikmelerini yoxla.
- Emekdash rollarini, icazelerini ve telegram chat id melumatlarini idare et.
- Backup restore pointlerini ve update paketlerini yoxla.
- Superpilotdan rehber suallari sorush: gelir, borclular, riskli musteriler, texnik performansi, kritik stok.

**Tehlukesizlik**
- Real router/OLT/RADIUS icra emeliyyatlari yalniz audit log ile aparilmalidir.
- Update paketinden evvel avtomatik backup yaranmalidir.
- Canli RADIUS/router emeliyyatinda aktiv abonentlere tesir ede bilecek addimlar iki defe yoxlanmalidir.

## Operator / support

**Esas sehifeler**
- Musteriler
- Musteri 360 profil
- Tickets
- SMS & Bildirishler
- Billing oxu rejimi

**Musteri muracieti axini**
- Musteri kodu, telefon, FIN, IP, MAC ve ya SN ile axtar.
- Musteri 360 profilinde status, paket, qalan gun, borc, online/offline ve son ticketlere bax.
- Problem varsa ticket ac, problem tipi ve erazi sec.
- Eyni PON uzerinde coxlu oxshar ticket varsa tek-tek ticket yaratma; mass outage/netvork problemi kimi yonlendir.
- Hazir mesaj shablonundan musteriye melumat gonder.

**Superpilot numuneleri**
- `2320025 niye qirilir?`
- `2320025 odenisi neye cixib?`
- `SN123 kimdedir?`
- `2320025 ucun ticket ac internet yoxdur`

## NOC muhendisi

**Esas sehifeler**
- Network / NOC
- NOC cihaz paneli
- OLT / ONT
- RADIUS
- Aktiv alarmlar
- SNMP trap/loglar

**Gundelik ish**
- Network monitorunda cihaz renglerini ve statuslarini yoxla.
- IP scan ve device discovery ile yeni cihazlari tap.
- Trap server: `172.16.5.2:162`, read community `public`, write community `private`.
- OLT/PON/ONT tree view uzerinden mass outage ve poor signal qruplarini analiz et.
- RADIUS online sessiya, reject sebebi, CoA/disconnect novbesini yoxla.

**Canli emeliyyat qaydasi**
- Router configini deyishmeden evvel movcud configi oxu ve backup et.
- Full SNMP walk ve backup ishlerini kritik alarm polling-den ustun tutma.
- Real VLAN 550 qosulduqda cihaz monitor, SNMP, trap ve live optical read testleri ayrica aparilmalidir.

## Texnik

**Esas sehifeler**
- Mene teyin edilen ticketler
- Texniki ish paneli
- Musteri 360 profilinin ONT/Optik tab-i
- Anbardan mene verilenler

**Saha ish axini**
- Ticketi qebul et.
- Musteri profilinde ONT serial, MAC, OLT/PON yeri, RX/TX, temperatur ve son restart melumatlarina bax.
- Ishde istifade olunan modem, kabel, fast connector ve diger mallari ticket icinde qeyd et.
- Ish bitdikde hell yazisi, istifade olunan material, shekil/qeyd ve netice daxil et.
- Avadanliq tehvil alindi/verildi emeliyyatlari tesdiqle baglanmalidir.

## Anbardar

**Esas sehifeler**
- Anbar
- Avadanliq siyahisi
- Medaxil / mexaric
- Texnike verilenler
- Kritik stok
- Barkod scan

**Gundelik ish**
- Yeni mal medaxil et: SKU, serial/MAC, kateqoriya, vahid, qiymet, filial.
- Texnike mal ver: emekdash sec, mal sec, say/serial sec, tesdiq al.
- Geri qaytarma ve xarab/itki emeliyyatlarini ayrica qeyd et.
- Musteriye baglanan ONT/modem ticket baglananda avtomatik musterinin profilinde gorunmelidir.

**Superpilot numuneleri**
- `Bu modem kimdedir?`
- `Samir Abdullayev adinda hansi mallar var?`
- `SN123 hansi musteride qosulub?`

## Muhasib / maliyye

**Esas sehifeler**
- Billing
- Odenish siyahisi
- Nagh odenish
- Provider odenishleri
- Paketler
- Borclular
- Reconciliation

**Gundelik ish**
- Terminal/API odenishlerini yoxla.
- Nagh odenish daxil et ve qebz/SMS secimini yoxla.
- Musteri koduna gore balans, son odenish ve qalan gunu yoxla.
- Providerlerden gelen duplikat ve ugursuz transactionlari reconciliation sehifesinde izl.
- Paket qiymeti + elave xidmet qiymetleri umumilikde aylig cixilmaya daxil olmalidir.

## HR / KPI

**Esas sehifeler**
- HR & KPI
- Ishciler
- Novbeler
- Davamiyyet
- Texnik performansi
- Ticket SLA

**Gundelik ish**
- Emekdash siyahisini yoxla ve redakte et.
- Novbe ve davamiyyet qeydlerini izle.
- Texnik uzre ticket sayi, SLA gecikme, tekrar muraciet ve anbar istifadesi KPI-larina bax.
- Yeni emekdash yaradarken employee rolunu, tipini, telefon/email ve telegram chat id-ni qeyd et.

## Superpilot istifadəsi

Superpilot yalniz oxumaq ucun deyil; icra istedikde tesdiq isteyir. Meselen ticket acmaq, mal vermek, radius yenilemek kimi emeliyyatlarda sistem `TESDIQ` teleb edir.

**Cavab vere bileceyi movzular**
- Musteri: status, odenish, borc, qalan gun, ticketler, SMS, radius loglari.
- Network: OLT/ONT yeri, signal, temperatur, trap, alarm, mass outage.
- RADIUS: online sessiya, reject sebebi, IP, CoA/disconnect novbesi.
- Anbar: mal kimdedir, hansi texnikde ne var, musteride hansi modem var.
- KPI: emekdash performansi, geciken ticket, sahada ish yuklenmesi.
- Maliyye: odenish tarixcesi, provider xetasi, reconciliation.

## Musteri portal support

Musteri portalinda router gizli melumatlari gosterilmir. Musteri yalniz oz odenishlerini, paketini, muracietlerini, profil/parol yenilemesini ve icaze verilen cihaz temperaturu kimi sade melumatlari gore bilir.

Support ishcisi musteriye portal parolunu yenilemekde komek ede biler, amma RADIUS/router sirlerini musteriyə dememelidir.
