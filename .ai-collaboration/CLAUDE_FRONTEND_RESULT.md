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

- `chat-list.php` polling-i yalniz `chat_rows` bos olmayanda (yeni `.wa-chat-layout` DOM-da movcud olanda) baslayir; sistemde hec bir cat yoxdursa (tam bos hal) ve worker ilk mesaji o zaman gonderirse, sehife hele de reload teleb edir. Bu, tapsirigin "movcud setirleri yenile / yeni cat siyahiya elave et" umumi ssenarisinden kenardadir, lakin qeyd olunmalidir. **Bu mesele ucuncu kecidde duzeldildi, asagiya bax.**

## Ucuncu kecid (dar duzelis)

Status: `DONE`

Ikinci kecidde qeyd olunan qalan riski ve elave 5 dar duzelisi tetbiq etdim.

### Deyisdirilen fayllar

- `views/whatsapp-monitor/chats.php`:
  - `$chatRows === []` yoxlamasi artiq butun `wa-chat-layout` blokunu (axtaris, `data-wa-chat-list`, cat thread paneli) gizletmir. Layout, axtaris qutusu ve `data-wa-chat-list` konteyneri **hemise** render olunur; yalniz siyahi bos olanda `data-wa-chat-list` daxilinde `billing_render_empty_state(...)` (`.mgs-empty-state`) gorunur. Beleliklecat monitor shell-i ve chat-list polling ilk yuklemede cat olmasa da aktiv olur; worker ilk mesaji gonderende sehife reload olmadan siyahiya dusur (JS terefinde `.mgs-empty-state` avtomatik silinir).
  - Basliqdaki `<span class="portal-pill">` elementine `data-wa-chat-count-total` atributu elave edildi ki, JS chat-list polling neticesine gore aktiv cat sayini yenileye bilsin.
- `script/whatsapp-monitor.js`:
  - `refreshChatList()`: server cavabinda `rows.length > 0` olanda `data-wa-chat-list` daxilindeki `.mgs-empty-state` bloku silinir (mesajlar elave olunmazdan evvel); hemin funksiya sonunda `updateChatCountTotal(rows.length)` cagirilaraq basliqdaki `N aktiv cat` mətni chat-list polling neticesine gore yenilenir.
  - Uc async polling novune (mesaj polling, chat-list polling, QR pairing polling) in-flight guard elave edildi: her birinde `…InFlight` bul dəyişəni evvelki sorgu bitmeyibse yeni sorgunu buraxir (`if (…InFlight) return;` / `try { … } finally { …InFlight = false; }`). Bu, yavas sebeke seraitinde eyni novde sorgularin ust-uste dusmesinin qarsisini alir.
  - QR pairing polling (`data-wa-pairing-live`) `document.visibilitychange` ile idare olunmaga baslandi: sehife arxa planda olanda interval 2s -> 15s-e seyrelir, yeniden aktiv olanda derhal bir sorgu gedir ve interval 2s-e qayidir. Terminal statuslarda (`connected/expired/failed/revoked`) `pairingStopped` bayragi polling-i tamamile saxlayir ve visibilitychange handler-i bir daha yenidenbaslatmir.
  - Butun dinamik server metni (`textContent`) ile yazilir; `URLSearchParams`/`URL.searchParams.set(...)` contract-i (mesaj, cat-list, pairing endpoint-lerinde) deyismedi.

Backend, API, PHP context, auth, CSRF ve permission kodu deyismedi; yalniz `views/whatsapp-monitor/chats.php` ve `script/whatsapp-monitor.js` toxunuldu (`css/whatsapp-monitor.css` bu kecidde deyisiklik teleb etmedi, movcud `.mgs-empty-state` ve `.wa-chat-list-scroll` stilleri yeni bos-hal yerlesimini deyisiklik olmadan destekleyir).

### Isledilen testler

- Deyisdirilen `chats.php` ve `whatsapp-monitor.js` elle sintaksis review edildi: PHP `if/else/endif` ve `foreach/endforeach` cutleri sayildi (6 aç/6 bağla, iç-içə səviyyələr yoxlanıldı), JS-de `{`/`}` ve `(`/`)` sayi (78/78, 214/214) beraber tapildi.
- `php -l` ve `node --check` bu sessiyada alet icazesi tesdiqlenmedi (eyni mehdudiyyet evvelki iki kecidde de qeyd olunmusdu); avtomatik icra alinmadi, manual review ile evez edildi.
- Brauzerde canli smoke test aparilmadi. Tovsiye olunan yoxlama: sistemde hec bir WhatsApp cati olmayan bir hesabla `whatsapp-monitor.php?view=chats` ac, bos hal gorununu tesdiq et, sonra worker/test alətiylə ilk mesaji simulyasiya edib sehife reload olmadan cat-in siyahiya dusdüyünü ve basliqdaki "N aktiv cat" sayinin yenilendiyini yoxla; DevTools network throttling ile yavas sebeke seraitinde ust-uste polling sorgusu getmediyini (in-flight guard) tesdiqle; sekmeni arxa plana at/geri qaytar ve QR sehifesinde pairing polling-in seyrelib/derhal yenilendiyini yoxla.

### Qalan risk

- Hec bir qalan mesele qeyd olunmur; ikinci kecidin qalan riski (tam bos hal ucun chat-list shell-inin aktiv olmamasi) bu kecidde aradan qaldirildi.

## Dördüncü keçid (dil və texniki mətn cilası)

Status: `DONE`

Yalniz dil polish tapsirigi: `views/whatsapp-monitor/module.php`, `views/whatsapp-monitor/chats.php`, `views/whatsapp-monitor/pairing.php`, `css/whatsapp-monitor.css`.

### Deyisdirilen fayllar

- `views/whatsapp-monitor/module.php`, `views/whatsapp-monitor/chats.php`, `views/whatsapp-monitor/pairing.php`: bütün istifadəçiyə görünən mətnlər düzgün Azərbaycan orfoqrafiyasına (ə, ı, ö, ü, ş, ç, ğ) keçirildi — məs. `cat`→`çat`, `nomre`→`nömrə`, `emekdas`→`əməkdaş`, `qosulub`→`Qoşulub`, `siyahi`→`siyahı`, `Baglanti`→`Bağlantı`, `gorunecek`→`görünəcək`. Texniki identifikatorlar (`data-*` atributları, DB status key-ləri: `requested`, `qr_ready`, `connected` və s., URL parametrləri) toxunulmadı.
- `views/whatsapp-monitor/pairing.php`: "Son QR sessiyası" kartında `Session key` və `Worker ID` əsas görünüşdən çıxarılıb, açılıb-bağlana bilən `<details class="wa-tech-details"><summary>Texniki məlumat</summary>…</details>` bloku daxilinə köçürüldü (defolt bağlı). Bitmə vaxtı və xəta mesajı istifadəçi üçün faydalı olduğundan əsas kartda saxlanıldı.
- `css/whatsapp-monitor.css`: `.wa-tech-details` üçün minimal stil (summary — muted, kiçik, cursor:pointer) əlavə edildi; mövcud Azərbaycan dilində olan kod şərhləri də düzgün orfoqrafiyaya keçirildi.

Backend, API, JS contract, `data-*` atributları, DB status key-ləri və biznes məntiqi dəyişmədi.

### İşlədilən testlər

- Dəyişdirilən üç PHP faylı və CSS faylı əl ilə sintaksis review edildi (PHP `if/foreach/endif/endforeach` cütləri, HTML/`<details>` teq balansı, CSS `{`/`}` balansı yoxlanıldı); struktur dəyişməyib, yalnız mətn məzmunu və bir `<details>` sarğısı əlavə olunub.
- `php -l` bu sessiyada alət icazəsi təsdiqlənmədi (əvvəlki keçidlərdə də eyni məhdudiyyət qeyd olunmuşdu); manual review ilə əvəz edildi.
- Brauzerdə canlı smoke test aparılmadı. Tövsiyə olunan yoxlama: `whatsapp-monitor.php?view=chats` və `?view=pairing` səhifələrini aç, bütün mətnlərin düzgün Azərbaycan dilində göründüyünü, QR kartında "Texniki məlumat" bölməsinin defolt bağlı olduğunu və açılanda Session key/Worker ID-ni göstərdiyini yoxla.

### Qalan risk

- Heç bir qalan mesələ qeyd olunmur.

## Qalan səhifələr keçidi

Status: `DONE`

CLAUDE_FRONTEND_TASK.md-dəki "Qalan səhifələr üçün aktiv keçid" siyahısında göstərilən 8 view faylı və `css/whatsapp-monitor.css` üzərində işləndi. `module.php`, `chats.php`, `pairing.php`, JS, backend, API, DB, auth, permission və CSRF koduna toxunulmadı.

### Dəyişdirilən fayllar

- `views/whatsapp-monitor/overview.php`:
  - Bütün mətnlər düzgün Azərbaycan orfoqrafiyasına keçirildi.
  - 10 KPI kartından ibarət tək düz siyahı 3 skan edilə bilən qrupa bölündü: "Nömrələr və QR" (Aktiv nömrə, QR qoşulu, QR gözləyir + "Rejim: İdarə/Baxış" pill-i başlıqda), "Cihazlar" (Onlayn, Oflayn/xəbərdarlıq), "Case və performans" (Açıq case, Kritik, Bugünkü ticket, Orta cavab). Əvvəlki "Rejim" kartı ayrıca metrik kimi saxlanmadı, qrup başlığında kiçik pill kimi göstərilir — təkrarçılıq azaldıldı.
  - Cihaz statusu (`online/offline/warning/disabled`) artıq xam ingiliscə deyil, lokal `match`-la hesablanan "Onlayn/Oflayn/Xəbərdarlıq/Deaktiv" label-ı ilə göstərilir; saxlanan `status` dəyəri və `is-<status>` class-ı toxunulmadı.
  - "Açıq case-lər" cədvəlinin `<td>`-lərinə `data-label` atributları əlavə edildi (mobil kart görünüşü üçün).
- `views/whatsapp-monitor/screen-wall.php`:
  - Mətnlər düzəldildi ("Onlayn", "Razılıq gözləyən", "sütun", "Yenilənmə" və s.), `cols`/`status`/`policy_missing` URL parametrləri, fullscreen `data-screen-wall-fullscreen` atributu və `--wa-cols` contract-i toxunulmadı.
  - Cihaz kartında status label-ı (yuxarıdakı eyni `match`) tətbiq edildi; metadata sətirləri (cihaz adı/kodu, son aktivlik, açıq case sayı) ayrıca `.wa-screen-meta` bloku ilə ikonlu, aydın sətirlərə ayrıldı (əvvəlki üç ardıcıl `<small>` sətri indi vizual qruplaşdırılıb).
  - Case yaratma formunda `case_type`/`priority` `<option>` dəyərləri eyni saxlandı, yalnız label-lar düzgün Azərbaycan dilinə keçirildi.
- `views/whatsapp-monitor/numbers.php`:
  - Mətnlər düzəldildi, nömrə statusu (`active/inactive/blocked/lost`) üçün lokal label mapping (Aktiv/Passiv/Bloklu/İtib) əlavə edildi (saxlanan dəyər dəyişmədi).
  - Yaratma formu iki hissəyə ayrıldı: əsas sahələr (Əməkdaş, Nömrə, WhatsApp adı, Status, Monitorinq razılığı) açıq grid-də, texniki/opsional sahələr (Ərazi, Policy versiya, "Şirkət nömrəsidir" checkbox, Qeyd) mövcud `.wa-tech-details`/`<details>` naxışı ilə "Əlavə seçimlər" bölməsinə köçürüldü.
  - Cədvəlin bütün `<td>`-lərinə `data-label` əlavə edildi.
- `views/whatsapp-monitor/devices.php`: eyni struktur — mətn düzəlişi, cihaz status label mapping-i, yaratma formunda əsas sahələr (Cihaz adı, Cihaz kodu, Əməkdaş, Nömrə, Status, Monitorinq aktivdir) və `<details>` daxilində texniki sahələr (Ərazi, Cihaz tipi, Bağlantı, Stream URL, Qeyd), cədvəldə `data-label`.
- `views/whatsapp-monitor/cases.php`:
  - Case növü (`case_type`), prioritet (`priority`) və status (`status`) üçün lokal label mapping-lər əlavə edildi (Cavabsız/Şikayət/Ödəniş/Texniki/Satış/Digər, Kritik/Yüksək/Normal/Aşağı, Yeni/Cavab gözləyir/İşdədir/Cavablandı/Eskalasiya/Həll edildi/Nəzərə alınmadı) — əvvəllər cədvəldə xam DB dəyəri göstərilirdi.
  - Cədvəlin bütün `<td>`-lərinə `data-label` əlavə edildi; forma sahə adları/action/URL parametrləri (`status=open`/`status=resolved`, `case_status` seçimləri) toxunulmadı.
- `views/whatsapp-monitor/performance.php`: mətn düzəlişi, cədvələ `data-label` əlavə edildi.
- `views/whatsapp-monitor/settings.php`: mətn düzəlişi ("Yalnız baxış", "Qaydaları dəyişmək üçün super admin lazımdır." və s.); ayar sahələrinin `name`/`value` render məntiqi dəyişmədi.
- `views/whatsapp-monitor/audit.php`: mətn düzəlişi, cədvələ `data-label` əlavə edildi.
- `css/whatsapp-monitor.css`:
  - `.wa-kpi-groups`, `.wa-kpi-group-head` (overview.php-dəki qruplaşdırma üçün) əlavə edildi.
  - `.wa-screen-meta` (screen-wall.php cihaz kartında aydın metadata sətirləri üçün) əlavə edildi.
  - `@media (max-width:680px)` blokuna `.whatsapp-monitor-module .portal-table td[data-label]::before { content: attr(data-label); ... }` əlavə edildi — bu, portalın ümumi `css/portal.css`-dəki `data-label` konvensiyasına (məs. `admin-users.php`) uyğundur və mobil kartlarda sahə adını göstərir. Yeni framework, gradient, böyük radius və ya ağır animasiya əlavə edilmədi.

### İşlədilən testlər

- Dəyişdirilən 8 PHP faylı və CSS faylı əl ilə tam oxunaraq review edildi: hər faylda `<?php if/foreach`-lərin uyğun `endif;`/`endforeach;` cütləri, HTML teq balansı, mövcud `name`/`value`/`action`/href/data-* atributlarının toxunulmadığı sətir-sətir yoxlanıldı; CSS-də əlavə olunan bloklarda `{`/`}` balansı təsdiqləndi.
- `php -l` bu sessiyada da alət icazəsi almadı (əvvəlki keçidlərdə də eyni məhdudiyyət qeyd olunmuşdu); avtomatik skript işlətmək əvəzinə tam əl ilə sintaksis review aparıldı. Qeyd: müvəqqəti yoxlama skripti səhvən `tmp/` qovluğuna yazılmışdı, dərhal aşkarlanıb silindi (FILE_OWNERSHIP.md-də `tmp/**` bütün agentlərə bağlıdır).
- Brauzerdə canlı smoke test aparılmadı. Tövsiyə olunan yoxlama: `whatsapp-monitor.php?view=overview`, `?view=screen-wall`, `?view=numbers`, `?view=devices`, `?view=cases`, `?view=performance`, `?view=settings`, `?view=audit` səhifələrini super admin və adi əməkdaş hesabları ilə desktop/mobil enlərdə aç; overview-də KPI qruplarının, screen-wall-da cihaz kartı metadata blokunun, numbers/devices formalarındakı "Əlavə seçimlər" `<details>` bölməsinin və mobil enlikdə (≤680px) cədvəllərin `data-label`-li kart görünüşünün düzgün işlədiyini təsdiqlə.

### Qalan risk

- Heç bir qalan mesələ qeyd olunmur.

## İnteqrasiya düzəlişi keçidi

Status: `DONE`

İki konkret tapılan mesələ düzəldildi: overview.php-də case prioritet/status xam DB dəyəri, və "Manual" sözünün istifadəçiyə görünən yerlərdə ingiliscə qalması.

### Dəyişdirilən fayllar

- `views/whatsapp-monitor/overview.php`: "Açıq case-lər" cədvəlində prioritet və status artıq cases.php-dəki eyni `$waPriorityLabel`/`$waCaseStatusLabel` `match` mapping-i ilə göstərilir (əvvəllər xam `$case['priority']`/`$case['status']` DB dəyəri çıxırdı). Saxlanan `$case['priority']`/`$case['status']` dəyərləri və `data-label` atributları toxunulmadı — yalnız görünən mətn dəyişdi.
- `views/whatsapp-monitor/cases.php`: "Manual case yarat" başlığı → "Əl ilə case yarat"; boş hal mesajı "Manual case yaradıldıqda..." → "Əl ilə case yaradıldıqda...". `action=create_case` və digər form sahələri dəyişmədi.
- `views/whatsapp-monitor/devices.php`: `connection_type` seçimində `value="manual"` saxlanıldı, yalnız label "Manual" → "Əl ilə" oldu.
- `views/whatsapp-monitor/performance.php`: "MVP-də manual case-lərə əsaslanır" → "MVP-də əl ilə yaradılan case-lərə əsaslanır".

Backend, DB, API, auth, CSRF və biznes məntiqi kodu toxunulmadı; heç bir `name`/`value`/context key dəyişmədi.

### İşlədilən testlər

- Dəyişdirilən 4 PHP faylı əl ilə review edildi: `match` mapping-lərinin cases.php ilə eyni olduğu, form `value` atributlarının (`priority`, `status`, `connection_type=manual`) toxunulmadığı sətir-sətir yoxlanıldı.
- `php -l` bu sessiyada alət icazəsi almadı (əvvəlki keçidlərdə də eyni məhdudiyyət qeyd olunmuşdu); manual review ilə əvəz edildi.
- Brauzerdə canlı smoke test aparılmadı. Tövsiyə olunan yoxlama: `whatsapp-monitor.php?view=overview` səhifəsində açıq case-lərin prioritet/status sütununun Azərbaycan dilində göründüyünü, `?view=cases` və `?view=devices`-də "Əl ilə" mətninin düzgün render olunduğunu yoxla.

### Qalan risk

- Heç bir qalan mesələ qeyd olunmur.

## Vizual inteqrasiya düzəlişi

Status: `DONE`

- Claude mobil `cases` ekranındakı sıx action formalarını `.wa-case-actions` və responsive grid ilə yenidən qurdu; select, input və düymələr mobil ekranda tam enli, minimum 44px klik sahəsi ilə göstərilir.
- İstifadəçiyə görünən `case`, `ticket` və `Screen Wall` terminləri monitorinq qeydi, CRM müraciəti və canlı monitor ifadələri ilə sadələşdirildi; daxili action, field, DB və URL dəyərləri qorundu.
- Codex inteqrasiya baxışında radius 8px portal standartına uyğunlaşdırıldı.
- Codex Playwright ilə əvvəlki chat/QR ssenariləri daxil olmaqla `22/22` desktop və mobil vizual yoxlamanı keçirdi; overflow və console xətası yoxdur.

## SAR-5 shell keçidi

Status: `DONE`

CLAUDE_FRONTEND_TASK.md-də təyin olunan SAR-5 birinci mərhələ: Maliyyə, HR, Anbar və Billing modullarının ortaq shell-inin böyük inline `<style>` bloklarından təmizlənməsi, ortaq `css/operations-modules.css` faylına köçürülməsi və görünən mətnlərin düzgün Azərbaycan dilinə keçirilməsi.

### Dəyişdirilən fayllar

- `views/_module-group-nav.php` — inline `<style>` bloku (`.module-group-nav.billing-shell-nav` və `760px` media sorğusu) çıxarıldı. Struktur (`$moduleNavGroups`, `data-module-group-nav`, `data-module-nav-group`, `data-module-section`, `<details name>` davranışı) toxunulmadı.
- `views/finance/module.php` — böyük inline `<style>` bloku (kpi/tabs/filter/dialog/chart/responsive qaydaları) çıxarıldı. Görünən mətn dəyişmədi (artıq təbii Azərbaycan dilində idi).
- `views/hr/module.php` — eyni qaydada inline `<style>` bloku çıxarıldı. Görünən mətn dəyişmədi.
- `views/inventory/module.php` — inline `<style>` bloku (ən böyük blok: command-strip, action-panel/grid/card daxil) çıxarıldı. İki yerdə qarışıq dil düzəldildi: baş mətndə "lifecycle" → "istismar dövrü", naviqasiya qrup etiketlərində "Tehvil ve assignment" → "Tehvil ve tayinat" və "Lifecycle ve hesabat" → "Istismar dovru ve hesabat". `repair-rma` tab açarı (URL/data dəyəri) toxunulmadı.
- `views/billing/module.php` — inline `<style>` bloku (is-loading, panels, chart-grid, live-status daxil) çıxarıldı. Görünən mətn dəyişmədi.
- **Yeni:** `css/operations-modules.css` — beş faylın bütün stilləri buraya köçürüldü. Dörd modulda hərfbəhərf eyni olan qaydalar (module-head, breadcrumb, tabs, kpi grid/kart, shortcuts, pagination, page-links, empty state, chart-grid/chart/track, dialog backdrop/close/open, portal-table mobil kart görünüşü) ortaq selektorlarla (`.finance-x,.hr-x,.inventory-x,.billing-x{...}`) birləşdirildi; modula xas fərqlər (kpi `strong` üçün clamp maksimumu: finance 1.25rem, digərləri 1.2rem; filter grid-template-columns; billing-in fərqli chart-grid gap-ı və chart-row birinci sütunu; inventory-in command-strip/action-panel/action-card blokları; hr-in çap (`@media print`) qaydası; finance-in tək-panelli dialoq, hr/inventory-in başlıq+gövdə bölünmüş dialoqu) ayrıca qaydalar kimi saxlanıldı.

`operations-center.php` və `billing-section-page.php` (Codex mülkiyyətində) artıq `css/portal.css`-dən sonra `css/operations-modules.css`-i yükləyir — bu keçiddə yoxlanıldı, dəyişdirilmədi.

Backend, DB, API, auth, permission, CSRF kodu, form `action`/`name`/`value`, URL/query parametrləri və `data-finance-nav`/`data-hr-nav`/`data-inventory-nav`/`data-module-*` atributları toxunulmadı.

### İşlədilən testlər

- Bütün 5 dəyişdirilən PHP faylı və yeni CSS faylı əl ilə tam oxunaraq review edildi: hər PHP faylda `<?php … ?>`, `if/foreach/endif/endforeach` cütləri və HTML teq balansı `<style>` bloku çıxarılmazdan əvvəlki versiya ilə sətir-sətir tutuşduruldu (yalnız `<style>` bloku silindi, ətraf HTML strukturu dəyişmədi).
- `css/operations-modules.css`-də `{`/`}` balansı yoxlanıldı: **98/98** bərabər tapıldı.
- Hər modulun əvvəlki inline CSS-inin son (source-order) hesablanmış nəticəsi yeni ortaq faylda seçici-seçici müqayisə edildi (məs. `.finance-kpi strong`-un iki ayrı qaydadan (bazadakı `font-size:1.25rem` + sondakı `clamp(...)` override) yaranan son görünüşü tək qaydada saxlanıldı) ki, vizual nəticə dəyişməsin.
- `php -l` bu sessiyada da alət icazəsi almadı (əvvəlki keçidlərdə də eyni məhdudiyyət qeyd olunmuşdu); avtomatik icra əvəzinə tam əl ilə sintaksis review aparıldı.
- Brauzerdə canlı smoke test aparılmadı. Tövsiyə olunan yoxlama: `operations-center.php` (Maliyyə/HR/Anbar tabları) və `billing-section-page.php`-i 1280px və 390px enlərdə aç; KPI mətninin qutudan daşmadığını, başlıq action düymələrinin üst-üstə düşmədiyini, modul naviqasiyasında ikinci horizontal scroll olmadığını, modal pəncərələrin (finance/hr/inventory) viewport daxilində qaldığını və filter/statistika submit-inin səhifəni tam reload etmədiyini təsdiqlə.

### Qalan risk

- Canlı brauzer/vizual regressiya bu sessiyada icra olunmadı — yuxarıdakı tövsiyə olunan yoxlama Codex və ya istifadəçi tərəfindən real serverdə aparılmalıdır.
- CSS konsolidasiyası zamanı bəzi ekvivalent qaydalar (məs. `.finance-module,.finance-kpi{min-width:0}` + sonrakı override) tək yekun qaydaya sadələşdirildi; hesablanmış nəticə əl ilə yoxlanılsa da, brauzer devtools-da faktiki tərtibatın təsdiqlənməsi tövsiyə olunur.
