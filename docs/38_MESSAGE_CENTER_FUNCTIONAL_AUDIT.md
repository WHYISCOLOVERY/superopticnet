# Mesaj Merkezi ve WhatsApp funksional auditi

Tarix: 2026-07-19  
Status: Kod deyisikliyinden evvel audit  
Qerar sahibi: Super Admin  

Bu hesabat UI route siyahisi deyil. Her is axini frontendden provider/DB qatina qeder yoxlanilib. WhatsApp ucun hedef yalniz monitorinq, tarixce ve gelecek analizdir. WhatsApp-dan mesaj gondermek, cavab vermek ve mesaj silmek bu hedefin xaricindedir.

## 1. Status sozluyu

| Status | Menasi |
|---|---|
| Tesdiqlenib | Kod, DB ve ya tehlukesiz smoke test ile subut olunub |
| Qismen tesdiqlenib | Axinin bir hissesi isleyir, tam E2E ve ya real provider testi yoxdur |
| Yoxlanilmayib | Real provider, real cihaz ve ya production serti olmadan fakt sayila bilmez |
| Mock/demo | Production gorunusune saxta ve ya test melumati qarisir |
| Istifadesiz | Aktiv route ve ya sehifede istifadesi tapilmayib |
| Teklif | Hedef arxitekturadir, movcud funksiya kimi qiymetlendirilmir |

## 2. Indiki qurulus

### 2.1 Route ve fayllar

| Sahe | Movcud route/fayl | Faktiki vezife |
|---|---|---|
| Gonderisler | `message-center.php?view=single|group` | SMS, sistem bildirisi ve tesdiqli kampaniya gonderisi |
| Sablonlar | `message-center.php?view=templates` | Composer ucun sablon idareetmesi |
| Kanal tarixcesi | `message-center.php?view=history` | `communication_messages` umumi kanal qeydlari |
| Provider/audit | `message-center.php?view=audit|test|settings` | Provider cehdleri, test ve admin ayarlari |
| WhatsApp monitor | `whatsapp-monitor.php?view=chats` | Nomreye gore chat siyahisi ve secilmis chat tarixcesi |
| Bos ekran monitoru | `whatsapp-monitor.php?view=screen-wall` | Device stream ucun kartlar; metn chati monitoru deyil |
| Nomre/session | `whatsapp-monitor.php?view=numbers|pairing|devices` | Sirket nomresi, QR session ve cihaz idareetmesi |

`whatsapp-monitor-lib.php:267-280` on ayrica WhatsApp gorunusu, `message-center.php:591-594` ise sekkiz Mesaj Merkezi gorunusu saxlayir. Bu, eyni emeliyyat sahelerini ferqli naviqasiyalarda tekrarlayir.

### 2.2 Melumat elaqesi

Tesdiqlenmis cari elaqe:

```text
whatsapp_employee_numbers.id
  -> whatsapp_monitor_messages.number_id
       -> (number_id, chat_id) uzre chat
            -> occurred_at uzre mesaj tarixcesi
```

- `whatsapp-monitor-lib.php:138-159` mesaj sxemini yaradir.
- `whatsapp-monitor-lib.php:793-820` chatlari `(number_id, chat_id)` uzre qruplayir.
- `whatsapp-monitor-lib.php:822-850` tarixceni hem `number_id`, hem `chat_id` ile mehdudlasdirir.
- `api/whatsapp-monitor/chat-list.php` `number_id` teleb edir.
- `api/whatsapp-monitor/chat-messages.php` `number_id` ve `chat_id` teleb edir.

Qerar: ferqli kontaktlar DB query seviyyesinde qarisdirilmir. Problem backend elaqesi deyil, bu elaqenin bir sirket nomresi kartinda vahid ve anlasilan sekilde gosterilmemesidir.

## 3. Is axinlari

### 3.1 Sirket WhatsApp nomresini qosmaq

| Sahə | Netice |
|---|---|
| Is axini | Sirket nomresi ucun QR session yaratmaq ve qosmaq |
| Istifadeci rolu | Super Admin idare edir; icazeli rollar baxir |
| Baslangic noqtesi | `whatsapp-monitor.php?view=pairing` ve `numbers` |
| Teleb olunan melumat | Nomre qeydi, emekdas/sahib, session key, QR worker |
| Route/fayl | `whatsapp-monitor.php`, `views/whatsapp-monitor/pairing.php`, `whatsapp-monitor-lib.php:630-717` |
| API/service | `pairing-queue.php`, `pairing-update.php`, `pairing-status.php`; bridge `/api/qr`, `/api/refresh`, `/api/status` |
| DB | `whatsapp_employee_numbers`, `whatsapp_monitor_pairings`, monitor audit cedvelleri |
| Xarici inteqrasiya | Node WhatsApp bridge ve WhatsApp Web session |
| Audit qeydi | Lokal monitor auditi ve `mgs_operation_actions` yazilir |
| Faktiki test | `whatsapp-monitor-module` kecib; `whatsapp-pairing-live` DB/worker/QR-ni gorub, amma connected session ucun kohne `qr_ready` gozlemesi sebebinden ugursuz olub |
| Mock/demo | QR hazir olmadiqda placeholder gorunur; bu, test data deyil |
| Catismayan funksiya | Davamli worker heartbeat, aydin `last_sync_at`, eyni yerde `Sessiyani yenile` emeli ve sebebli status |
| Tekrar funksiya | Nomre, pairing ve device ayrica esas tab kimi gosterilir |
| Tehlukesizlik riski | Worker token ve session sirrleri UI/auditde aciq gosterilmemelidir |
| Qerar | Duzelt: admin drawer-da birlesdir; kohne route-lari kecid muddetinde alias saxla |

### 3.2 WhatsApp mesaj ingest ve sinxronizasiya

| Sahə | Netice |
|---|---|
| Is axini | Qosulmus session-dan chat ve mesajlari DB-ye yazmaq |
| Istifadeci rolu | Worker token; insan istifadecisi birbasa yazmir |
| Baslangic noqtesi | Bridge session `ready` ve `message_create` event-i |
| Teleb olunan melumat | Session key, provider message ID, number/chat, istiqamet, metn, vaxt |
| Route/fayl | `whatsapp-bridge/server.js:390+`, `whatsapp-monitor-lib.php:720-765` |
| API/service | `api/whatsapp-monitor/message-ingest.php` |
| DB | `whatsapp_monitor_messages`, `whatsapp_monitor_pairings`, `whatsapp_employee_numbers` |
| Xarici inteqrasiya | WhatsApp Web bridge |
| Audit qeydi | Ayrica her ingest ucun tam actor auditi yoxdur |
| Faktiki test | `whatsapp-chat-ingest` kecib; DB-de 1,014 mesaj ve 64 chat cari snapshot-da gorunub |
| Mock/demo | Ingest yolunda mock tapilmayib |
| Catismayan funksiya | Periodik tam resync, sync cursor, `last_sync_at`, read status ve sync xeta sebebi |
| Tekrar funksiya | Message Center tarixcesi ferqli `communication_messages` menbeyini oxuyur; fiziki birlesdirilmemelidir |
| Tehlukesizlik riski | Idempotency yalniz provider message ID kontraktindan asilidir; worker token rotation ve rate limit test olunmalidir |
| Qerar | Saxla ve uzat: movcud ingest kontraktini pozmadan sync/heartbeat metadata elave et |

### 3.3 Sirket nomresinin canli chat monitoru

| Sahə | Netice |
|---|---|
| Is axini | Bir sirket nomresinin son chatlarini ve secilmis kontakt tarixcesini oxumaq |
| Istifadeci rolu | Admin/support/NOC/CRM/operator manager umumi scope; digerleri oz nomresi |
| Baslangic noqtesi | Hazirda `whatsapp-monitor.php?view=chats` |
| Teleb olunan melumat | `number_id`, secimde `chat_id`; hedefde axtaris ve filtrler |
| Route/fayl | `views/whatsapp-monitor/chats.php:15-98`, `script/whatsapp-monitor.js:153-190,291-329` |
| API/service | `chat-list.php`, `chat-messages.php` |
| DB | `whatsapp_employee_numbers`, `whatsapp_monitor_messages` |
| Xarici inteqrasiya | Yoxdur; DB read model |
| Audit qeydi | Oxuma emeli ucun ayrica jurnal gozlenilmir; icaze pozuntusu security audit-e dusmelidir |
| Faktiki test | Chat ingest/scope testleri kecib; real brauzer mobil E2E bu auditde icra edilmeyib |
| Mock/demo | Chat API-de mock tapilmayib |
| Catismayan funksiya | Kartda son 5-10 chat, unread, cavabsiz, date/direction/read filtrleri, loading/retry paneli |
| Tekrar funksiya | `chats` ayrica monitor, `screen-wall` ayrica monitor, overview ayrica gorunusdur |
| Tehlukesizlik riski | `message-center.php?view=history` messaging icazeli istifadeciye qlobal tarixce gostere bilir; scope sertlesmelidir |
| Qerar | Birlesdir: esas gorunus yalniz nomre kartlarindan ibaret `Canli Monitor` olsun |

### 3.4 WhatsApp statusunu gosterme

| Sahə | Netice |
|---|---|
| Is axini | Nomrenin connected/connecting/offline/disabled veziyyetini gostermek |
| Istifadeci rolu | Monitoru goren butun icazeli rollar |
| Baslangic noqtesi | Pairing, number, live monitor kartlari |
| Teleb olunan melumat | Session statusu, worker heartbeat, son sync, aktivlik ve sebeb |
| Route/fayl | `whatsapp-monitor-lib.php:630-717,720-765,1043+`, `whatsapp-bridge/server.js:122+` |
| API/service | `pairing-status.php`, provider `whatsapp-status.php`, bridge `/api/status` |
| DB | `whatsapp_monitor_pairings`, `whatsapp_employee_numbers` |
| Xarici inteqrasiya | WhatsApp bridge |
| Audit qeydi | Status deyisikliyi qismen audit olunur; davamli heartbeat transition auditi yoxdur |
| Faktiki test | Bridge aktivdir ve bir pairing connected gorunub; esas Message Center provider client-i disconnected olub |
| Mock/demo | Yoxdur, amma frontend statusu natamam melumatdan cixarir |
| Catismayan funksiya | Davamli heartbeat ve canonical backend status response |
| Tekrar funksiya | Eyni session ferqli sehifelerde ferqli badge/melumatla gosterilir |
| Tehlukesizlik riski | `connected` yazisinin kohne qalmasi operatoru yanlis neticeye aparir |
| Qerar | Duzelt: status yalniz backend terefinden hesablanmis canonical response olsun |

Canonical hedef response:

```json
{
  "status": "online|sync_delayed|offline|disabled",
  "last_activity_at": "datetime|null",
  "last_sync_at": "datetime|null",
  "status_reason": "string",
  "unread_count": 0
}
```

Reng qaydasi:

- yasil: session connected ve heartbeat tezedir;
- sari: session qosulur ve ya sync muddeti konfiqurasiya olunmus heddi kecib;
- qirmizi: session/bridge elaqesi yoxdur;
- boz: nomre deaktivdir ve ya hele qosulmayib.

`last_activity_at` son mesaj/real aktivlik, `last_sync_at` ise worker-in ugurlu sinxronizasiya vaxtidir; eyni sahe kimi istifade edilmemelidir. Stale heddleri kodda sabit yox, sistem ayarinda saxlanmalidir.

### 3.5 SMS, sistem bildirisi ve kampaniya gonderisi

| Sahə | Netice |
|---|---|
| Is axini | Tek SMS/bildiris ve tesdiqli kampaniya yaratmaq, queue worker ile gondermek |
| Istifadeci rolu | `messaging` modulu; qrup/test/settings ucun daha sert admin yoxlamasi |
| Baslangic noqtesi | `message-center.php?view=single|group` |
| Teleb olunan melumat | Kanal, alici/segment, sablon/metn, kampaniya ucun tesdiq |
| Route/fayl | `message-center.php:340+,359+,393+,1282+,1353+`, `communication-queue-lib.php` |
| API/service | Queue service; `tools/process-communication-queue.php`; kanal provider adapterleri |
| DB | `communication_messages`, campaign/approval, delivery attempt ve audit cedvelleri |
| Xarici inteqrasiya | SMS provider, Telegram/app ve konfiqurasiya olunan kanallar |
| Audit qeydi | Queue/provider cehdleri ve worker run-lari saxlanir |
| Faktiki test | UI contract, channel chart, campaign approval, channel switch ve delivery state smoke testleri kecib; provider testlerinde real request sayi 0 olub |
| Mock/demo | `message-center.php:768+` real tarixce bos olanda saxta `TEST MESAJI` yaradir |
| Catismayan funksiya | Production mock-un legvi, gonderis neticesinin aydin error/retry veziyyeti |
| Tekrar funksiya | Single, group ve template ayrica esas sehifedir |
| Tehlukesizlik riski | Provider sirrleri UI/HTML/auditde gosterilmemeli; kampaniya tesdiqi yaradan ve tesdiqleyen ferqli olmalidir |
| Qerar | Saxla ve `Gonderisler` composer-inde birlesdir; WhatsApp monitor ile qarisdirma |

Qeyd: bridge-de `/api/send` olmasi WhatsApp Canli Monitor-a gonderme funksiyasi elave etmek ucun esas deyil. Monitor read-only qalir.

### 3.6 Sablon idareetmesi

| Sahə | Netice |
|---|---|
| Is axini | SMS/sistem/kampaniya composer-i ucun sablon secmek ve idare etmek |
| Istifadeci rolu | Messaging operatoru; silme/deyisdirme backend rol qaydasina tabedir |
| Baslangic noqtesi | Hazirda `message-center.php?view=templates` |
| Teleb olunan melumat | Kanal, sablon adi, metn, deyisenler, aktivlik |
| Route/fayl | `message-center.php:1453+` |
| API/service | Message Center action dispatcher |
| DB | Communication template cedvelleri |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Deyisiklik emeli actor ve evvel/sonra ile jurnal olunmalidir; cari iz qismen tesdiqlenib |
| Faktiki test | Composer contract smoke testleri kecib; tam template CRUD E2E bu auditde yoxlanilmayib |
| Mock/demo | Yoxlanilmayib |
| Catismayan funksiya | Composer kontekstinde aydin preview ve validation |
| Tekrar funksiya | Ayrica esas menu kimi gosterilir |
| Tehlukesizlik riski | Tesdiqsiz kampaniya sablonunun kutlevi gonderilmesine yol verilmemelidir |
| Qerar | Birlesdir: sablon yalniz `Gonderisler` composer-inin daxilinde olsun |

### 3.7 Audit, performans ve case

| Sahə | Netice |
|---|---|
| Is axini | Emeliyyat, delivery ve cavabsiz chat neticelerini izlemek |
| Istifadeci rolu | Kontekst scope-u olan emekdas; qlobal jurnal admin |
| Baslangic noqtesi | Hazirda her iki modulda ayrica audit/performance/cases |
| Teleb olunan melumat | Modul, actor, nomre/chat, tarix, netice, correlation ID |
| Route/fayl | `message-center.php?view=audit`, `whatsapp-monitor.php?view=cases|performance|audit` |
| API/service | Monitor audit helper `whatsapp-monitor-lib.php:335-360`; communication delivery audit |
| DB | Monitor audit/cases, communication attempts, `mgs_operation_actions`, `security_audit_ledger` |
| Xarici inteqrasiya | Yoxdur |
| Audit qeydi | Ozudur; deyisdirilmez qlobal jurnal esas menbe olmalidir |
| Faktiki test | Campaign approval ve delivery transition testleri kecib; ingest actor izi natamamdir |
| Mock/demo | WhatsApp case sayi cari DB snapshot-da 0-dir |
| Catismayan funksiya | Qlobal jurnal ile canonical elaqe ve context deeplink |
| Tekrar funksiya | Iki audit ve ayrica performance sehifesi |
| Tehlukesizlik riski | Qlobal mesaj tarixcesinin role/scope olmadan gorunmesi melumat sizmasidir |
| Qerar | Birlesdir: qlobal Jurnal/Hesabat preset-i; cavabsiz status kart/chat filtridir |

## 4. Faktiki DB snapshot

### 4.1 Esas duyme ve action xeritəsi

| Duyme/action | Frontend dispatcher | API/service | DB/xarici tesir | Status |
|---|---|---|---|---|
| QR yarat | Pairing view + `script/whatsapp-monitor.js` | Pairing queue API, bridge `/api/qr` | Pairing session/QR state yazir | Qismen tesdiqlenib |
| Sessiyani yenile | Hazir UI-da vahid action kimi catismir | Bridge `/api/refresh` ve pairing update | Session/QR state yenilenmelidir | Catismayan funksiya |
| Sessiyani legv et | Pairing action | Pairing cancel/update service | Pairing status ve audit yazir | Qismen tesdiqlenib |
| Chat sec | `whatsapp-monitor.js` | `chat-messages.php?number_id&chat_id` | Yalniz read | Tesdiqlenib |
| Chat axtar | Hazirda client-side filter | `chat-list.php?number_id` | Yalniz read | Qismen tesdiqlenib |
| WhatsApp gonder/cavab/sil | Canli Monitorda olmamalidir | Yeni endpoint yaradilmamalidir | Heç bir write olmamalidir | Qerar: legv etme, elave etme |
| Tek gonderis | `message-center.php` single action | Communication queue service | `communication_messages`, delivery queue/provider | Qismen tesdiqlenib |
| Qrup/kampaniya yarat | Message Center group action | Campaign/queue service | Campaign, recipients, communication queue | Qismen tesdiqlenib |
| Kampaniyani tesdiqle | Approval action | Campaign approval service | Approval/audit ve sonra queue | Tesdiqlenib smoke |
| Sablon saxla/deyis | Template action dispatcher | Template service | Template cedvelleri ve audit | Qismen tesdiqlenib |
| Provider test et | Test/settings action | Provider adapter/health | Xarici test; sirr loglanmamalidir | Qismen tesdiqlenib |
| Filtrle | `script/message-center.js` auto/AJAX | History/audit read context | Read-only | Tesdiqlenib |

2026-07-19 tarixinde lokal `superoptic_portal` bazasinda read-only count:

| Cedvel | Say |
|---|---:|
| `communication_messages` | 729 |
| `whatsapp_employee_numbers` | 2 |
| `whatsapp_monitor_pairings` | 6 |
| `whatsapp_monitor_messages` | 1,014 |
| `whatsapp_monitor_cases` | 0 |

Bu saylar UI KPI-si deyil ve biznes neticesi kimi serh edilmir. SQL menbeyi master auditde tam verilib.

## 5. Funksional audit matrisi

| Is axini | Frontend | Duyme | API/service | DB R/W | Real data | Mock yoxdur | Backend rol | Audit | State-ler | Mobil | Tekrar | E2E |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| QR/session qosulmasi | Var | Qismen | Var | R/W | Qismen | Beli | Var | Qismen | Qismen | Yoxlanilmayib | Var | Qismen |
| Mesaj ingest | Worker | N/A | Var | R/W | Beli | Beli | Worker token | Qismen | Qismen | N/A | Yox | Tesdiqlenib |
| Canli chat monitoru | Var | Var | Var | R | Beli | Beli | Var | Qismen | Qismen | Qismen | Var | Qismen |
| Canonical status | Parcalanib | N/A | Qismen | R/W | Qismen | Beli | Var | Qismen | Qismen | Qismen | Var | Xeyr |
| SMS/bildiris gonderisi | Var | Var | Var | R/W | Qismen | Xeyr | Var | Var | Qismen | Qismen | Var | Qismen |
| Kampaniya tesdiqi | Var | Var | Var | R/W | Test data | Beli | Var | Var | Var | Yoxlanilmayib | Yox | Tesdiqlenib |
| Sablonlar | Var | Var | Var | R/W | Qismen | Yoxlanilmayib | Qismen | Qismen | Qismen | Qismen | Var | Qismen |
| Audit/performance | Var | Var | Var | R | Beli | Qismen | Qismen | N/A | Qismen | Qismen | Var | Qismen |

## 6. Hedef qurulus

### 6.1 Esas istifadeci gorunusu: Canli Monitor

Normal telefon karti:

- emekdas adi ve sirket nomresi;
- backend statusu, `status_reason`;
- son aktivlik ve son sinxronizasiya;
- oxunmamis say;
- son 5-10 chat: kontakt/nomre, son mesaj, vaxt, cavabsiz badge-i.

Genislenmis kart:

- solda yalniz hemin sirket nomresinin chatlari;
- sagda yalniz secilmis kontaktin tam metn tarixcesi;
- kontakt/nomre axtarisi;
- tarix araligi;
- gelen/gonderilen;
- oxunmus/oxunmamis filtrleri.

`screen-wall` metn monitorundan cixarilir. Bos `Stream URL yoxdur` bloklari gosterilmir. Ayrica `Tarixce` esas menyusu yaradilmır.

### 6.2 Ayrica is sahesi: Gonderisler

- SMS, sistem bildirisi ve tesdiqli kampaniya rejimleri;
- sablon composer-in daxilinde;
- WhatsApp monitor nomreleri bu composer ile qarisdirilmir;
- WhatsApp cavab, silme ve gonderme emeli yoxdur.

### 6.3 Admin drawer

QR/session, nomre aktivliyi, worker statusu, provider qaydalari ve texniki sebebler yalniz icazeli admin drawer-da gosterilir.

## 7. Qebul meyarlari

1. Ilk acilan Mesaj Merkezi gorunusu yalniz `Canli Monitor`dur.
2. Bir kartdaki chatlar basqa sirket nomresinin ve ya kontaktin mesaji ile qarisdirilmir.
3. API status, `last_activity_at`, `last_sync_at`, `status_reason` qaytarir.
4. Status rengini frontend texmin etmir.
5. WhatsApp ucun send/reply/delete UI, route ve API elave edilmir.
6. SMS/sistem/kampaniya `Gonderisler`de real queue ve approval qatini saxlayir.
7. Production tarixcesinde saxta `TEST MESAJI` yaranmir.
8. Her AJAX sahe loading, empty, error ve retry veziyyetine malikdir.
9. `390x844` ve `430x932` olculerinde ic-ice scroll, dasma ve iten esas action yoxdur.
10. Kohne route-lar ilk merhelede alias qalir; rollback menyu feature flag-i ile mumkundur.

## 8. Test ve rollback

Mecburi testler:

- number/chat/message isolation contract;
- status transition ve stale-heartbeat contract;
- pairing refresh ve connected-session E2E;
- role/scope matrix;
- provider sirrlerinin HTML/log-da gorunmemesi;
- SMS campaign maker-checker;
- mock-free production history;
- mobile Playwright screenshot ve overflow yoxlamasi;
- kohne route alias contract.

Rollback: DB deyisikliyi yalniz additive migration olacaq; kohne API kontraktlari saxlanacaq; yeni `Canli Monitor` feature flag ile acilacaq; problem olduqda menyu kohne views-a qaytarilacaq. Mesajlar ve audit qeydlari rollback zamani silinmeyecek.
