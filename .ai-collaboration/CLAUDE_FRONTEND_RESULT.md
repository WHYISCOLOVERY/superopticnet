# Claude frontend neticesi

Status: `DONE`

## Deyisdirilen fayllar

- `views/whatsapp-monitor/module.php` — boyuk inline `<style>` bloku cixarildi, yeni `css/whatsapp-monitor.css` faylina daşındı.
- `whatsapp-monitor.php` — yalniz frontend hissesine toxunuldu: `<head>`-e `css/whatsapp-monitor.css` link elave edildi, `</body>`-den evvelki inline polling/fullscreen JS-i `script/whatsapp-monitor.js` faylina cixarildi. Auth, POST action ve context yaratma kodu deyismedi.
- `views/whatsapp-monitor/chats.php` — catlar sehifesi aydin iki-panel is axini kimi yeniden quruldu (axtaris qutusu, mesaj sayi rozetkasi, secilmis cat basligi, "Yeni mesaj" veziyyeti, bos hal mesajlari, mobil "Siyahiya qayit" kecidi).
- `views/whatsapp-monitor/pairing.php` — "Worker endpoint: api/whatsapp-monitor/pairing-update.php" kimi texniki metn cixarildi, proses 3 addimlik sade Azerbaycan dilinde siyahiya (`wa-process-steps`) cevrildi, "QR worker gozlenilir" -> "QR kodu hazirlanir", "Worker: ..." -> "Baglanti nomresi: ...".
- **Yeni:** `css/whatsapp-monitor.css` — modulun butun stilleri (movcud + yeni axtaris/mobil-kecid/fokus/reduced-motion stilleri).
- **Yeni:** `script/whatsapp-monitor.js` — movcud QR ve cat polling kodu + yeni cat axtaris filtri ve "yeni mesaj" rozetkasi mentiqi.

`number_id + chat_id` link/data contract-i (`whatsapp_monitor_url('chats', ['number_id' => ..., 'chat_id' => ...])`, `data-number-id` / `data-chat-id` atributlari) toxunulmadan qorundu. Backend, API, DB, auth, CSRF ve permission kodu deyismedi.

## Tetbiq edilen UI/UX deyisiklikleri

- Catlar: axtaris (ad/nomre/emekdas uzre client-side filtr), her cat setrinde mesaj sayi rozetkasi, secilmis catda "Yeni mesaj" flash-badge (yeni mesaj polling ile gelende gorunur), bos hal mesajlari (cat yoxdur / cat secilmeyib / mesaj yoxdur).
- Mobil ekran (<=680px): cat siyahisi ve yazisma paneli bir-birini evez edir (`data-has-selection` atributu ile), "Siyahiya qayit" linki gorunur, ic-ice lazimsiz scroll saxlanmir (list/mesaj scroll-lari mobil ucun `max-height:none` edildi).
- QR sehifesi: worker/texniki dil aradan qaldirildi, sade 3 addimli proses siyahisi, QR gozleme vaxti ucun sade spinner (`is-loading`), reduced-motion zamani animasiya deaktivdir.
- Esas emeliyyatlar komanda zolağında 6 seçim + basliq (5-7 aralığında) saxlanildi, deyisiklik edilmedi.
- Fokus veziyyeti: axtaris input-u, cat linkleri ve "Siyahiya qayit" ucun `:focus-visible` konturlari elave edildi.

## Isledilen testler

- Butun deyisdirilen/yaradilan PHP, CSS ve JS fayllari elle sintaksis review edildi (PHP teg balansı, HTML element uygunlugu, CSS `{`/`}` sayi 103/103 beraberliyi teyid edildi).
- `php -l` ve `node --check` bu mühitde alet icazesi olmadigi ucun avtomatik ise salinmadi (icra icazesi tesdiqlenmedi); manual review ile evez edildi.
- Brauzerde canli smoke test aparilmadi — tovsiye: `whatsapp-monitor.php?view=chats` ve `?view=pairing` sehifelerini desktop/mobil enlerde ac, axtaris, mobil "Siyahiya qayit" kecidi ve QR canli statusu yoxla.

## Qalan frontend riskleri

- `php -l` / brauzer smoke testi bu sessiyada icra edile bilmedi (alet icazesi yoxdur); Codex ve ya istifadeci terefinden real serverde vizual tesdiq tovsiye olunur.
- `screen-wall.php` (canli monitor divari) bu merhelede deyisdirilmedi — CLAUDE_FRONTEND_TASK.md-deki konkret siyahida acıq sekilde qeyd olunmamisdi, scope-u genislendirmemek ucun toxunulmadi.

## Ikinci kecid

Status: `DONE`

INTEGRATION_REPORT.md-de qeyd olunan dord mesele ve CLAUDE_FRONTEND_TASK.md-deki 6 konkret duzelis tetbiq edildi.

### Deyisdirilen fayllar

- `views/whatsapp-monitor/chats.php`:
  - `data-has-selection` atributu artiq `chat_selection_explicit` context deyerinden goturulur (avvelki versiyada backend-in avtomatik secdiyi ilk cat da "explicit selection" kimi sayilirdi). Nice: desktop-da ilk cat yene defolt gorunur, mobil-de URL-de acıq secim olmadan siyahi acilir, "Siyahiya qayit" yeniden ilk cati acmir.
  - Cat siyahisi setirlerine `data-number-id`, `data-chat-id`, `data-wa-chat-name`, `data-wa-chat-time`, `data-wa-chat-preview`, `data-wa-chat-count` atributlari elave edildi ki, JS movcud setirleri tapıb yenile bilsin.
  - Axtaris ucun serverde hesablanan `data-wa-chat-search-text` artiq kicilmeden ham metn kimi saxlanir; kicilme tam JS terefinde locale-aware aparilir.
  - Cat basligina sade canli status rozetkasi elave edildi: `<span data-wa-live-status>` (`Canli` / `Yenilenir` / `Baglanti zeifdir`).
  - Bos-cat empty state (`.mgs-empty-state`) markup-u deyismedi; silinme JS terefinde aparilir.
- `script/whatsapp-monitor.js`:
  - Mesaj polling-inde ilk mesaj gelende `.mgs-empty-state` bloku silinir, sonra mesaj elave olunur.
  - Yeni `refreshChatList()` funksiyasi `api/whatsapp-monitor/chat-list.php`-i 5 saniyede bir sorgular; movcud setirlerin ad/tarix/preview/say melumatini yeniler, yeni cat gelirse sehifeni reload etmeden siyahiya elave edir (DOM sira-duzumu backend cavabinin sirasi ile saxlanilir).
  - Canli status rozetkasi mesaj polling ugur/xeta veziyyetine baglanib: ugurlu sorgu -> `Canli`, ardıcıl 2 uğursuz sorgu -> `Baglanti zeifdir`, sehife arxa plandan geri qayidanda -> qisa `Yenilenir` haleti.
  - `document.visibilitychange` ile: sehife arxa planda olanda mesaj polling 2.5s->20s, cat siyahisi polling 5s->30s intervalina keçir; sehife yeniden aktiv olanda derhal bir defe yenilenir ve norma intervala qayidir.
  - Axtaris filtri JS terefinde `toLocaleLowerCase('az')` ile Azerbaycan herfleri (İ/I/ı/ş/ç/ə ve s.) ucun locale-aware kicilmeye kecirildi; filtr funksiyasi hem statik, hem polling ile elave olunan setirlere tetbiq edilir.
- `css/whatsapp-monitor.css`: `.wa-live-status` rozetkasi ucun uc hal (`live`/`refreshing`/`weak`) stili ve mobil `.wa-chat-thread-head` ucun `flex-wrap` elave edildi.

Backend, API, PHP context, auth, CSRF ve permission kodu deyismedi; yalniz `views/whatsapp-monitor/chats.php`, `script/whatsapp-monitor.js` ve `css/whatsapp-monitor.css` toxunuldu.

### Isledilen testler

- Deyisdirilen PHP/CSS/JS fayllari elle sintaksis review edildi (PHP `if/foreach/endif/endforeach` cutleri, CSS `{`/`}` balansi, JS mötərizə/oxlu mötərizə balansi yoxlanildi).
- `php -l` ve `node --check` bu sessiyada da alet icazesi tesdiqlenmedi (eyni mehdudiyyet birinci kecidde de qeyd olunmusdu); avtomatik icra alinmadi, manual review ile evez edildi.
- Brauzerde canli smoke test aparilmadi. Tovsiye olunan yoxlama: `whatsapp-monitor.php?view=chats` sehifesini ac, bir cat sec, basqa sekmede/worker terefden yeni mesaj + yeni cat simulyasiya et, canli status rozetkasini ve mobil enlikde "Siyahiya qayit" davranisini yoxla.

### Qalan risk

- `chat-list.php` polling-i yalniz `chat_rows` bos olmayanda (yeni `.wa-chat-layout` DOM-da movcud olanda) baslayir; sistemde hec bir cat yoxdursa (tam bos hal) ve worker ilk mesaji o zaman gonderirse, sehife hele de reload teleb edir. Bu, tapsirigin "movcud setirleri yenile / yeni cat siyahiya elave et" umumi ssenarisinden kenardadir, lakin qeyd olunmalidir.
