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

## Nömrə/əməkdaş ayırma və kompakt monitor kartları

Status: `DONE`

CLAUDE_FRONTEND_TASK.md-də təyin olunan tapşırıq: backend-in artıq təqdim etdiyi `chat_number_groups`-dan istifadə edərək çat monitorunda nömrə/əməkdaş seçicisi qurmaq və stream olmayan canlı monitor kartlarını böyük qara çərçivə əvəzinə kompakt statusa keçirmək.

### Dəyişdirilən fayllar

- `views/whatsapp-monitor/chats.php`:
  - `data-wa-chat-monitor` elementinə `data-selected-number-id="<seçilmiş nömrə ID>"` atributu əlavə edildi (BACKEND_CONTRACT.md-də tələb olunan hook).
  - `$context['chat_number_groups']` əsasında başlıqla `.wa-chat-layout` arasında üfüqi sürüşkən nömrə/əməkdaş seçici (`role="tablist"`, `.wa-number-chip`) əlavə edildi: hər çip şirkət nömrəsini, əməkdaş adını və `whatsapp_monitor_pairing_status_label()` ilə mövcud `.wa-status` naxışında QR statusunu göstərir, `whatsapp_monitor_url('chats', ['number_id' => ...])` linki ilə naviqasiya edir, aktiv nömrə `is-active` class-ı ilə vurğulanır. `chat_rows` artıq (əvvəldən) yalnız `selected_number_id`-ə uyğun çatları göstərirdi (backend `whatsapp_monitor_chat_rows($db, $actor, $selectedNumberId)`) — bu dəyişməyib, yalnız seçim UI-si əlavə olundu.
  - Form `action`/`name`/`value`, `data-wa-chat-thread`/`data-wa-chat-list`/`data-wa-chat-search-item` hook-ları və `number_id`/`chat_id` query adları toxunulmadı.
- `views/whatsapp-monitor/screen-wall.php`:
  - Stream olmayan kartlarda əvvəllər həmişə render olunan böyük qara `.wa-screen-frame` (min-height 180px, tünd fon) qutusu artıq yalnız `$canShowStream` doğru olduqda göstərilir.
  - Stream olmayan kart üçün səbəb mətni (`$streamReason`: "Cihaz deaktiv edilib" / "Monitorinq razılığı gözlənilir" / "Monitorinq aktiv deyil" / "Stream ünvanı təyin olunmayıb" / "Stream hazırda əlçatan deyil") kompakt bir sətirdə göstərilir.
  - "Qeyd yarat" forması `ob_start()/ob_get_clean()` ilə bir dəfə tərtib olunub iki yerdə eyni instansiya kimi istifadə edilir: stream olan kartda əvvəlki kimi həmişə açıq, stream olmayan kartda `<details class="wa-case-toggle"><summary>Qeyd yarat</summary>…</details>` içində defolt bağlı (açılıb-bağlana bilən, kompakt). Form `action=create_case`, `name`/`value` sahələri (o cümlədən `csrf_token`, `device_id`) dəyişmədi.
  - Real stream olan kartlarda iframe və fullscreen (`data-screen-wall-fullscreen`) davranışı toxunulmadı.
- `css/whatsapp-monitor.css`:
  - `.wa-number-switch`/`.wa-number-chip`/`.wa-number-chip-badge` — üfüqi sürüşkən (`overflow-x:auto`) nömrə seçici üçün, tək istiqamətli scroll, `.wa-chat-list-scroll`-un daxili scroll-u ilə ziddiyyət yaratmır.
  - `.wa-screen-status-line` — kompakt status sətri (tünd qutu əvəzinə).
  - `.wa-case-toggle`/`summary` — açılıb-bağlanan "Qeyd yarat" düyməsi görünüşü, `:focus-visible` konturu ilə.

Backend, DB, API, auth, CSRF, permission kodu və JS fayllarına toxunulmadı; `whatsapp_monitor_chat_number_groups()`, `whatsapp_monitor_pairing_status_label()`, `whatsapp_monitor_url()` mövcud Codex funksiyaları olduğu kimi istifadə edildi.

### İşlədilən testlər

- `php -l views/whatsapp-monitor/chats.php` və `php -l views/whatsapp-monitor/screen-wall.php` (XAMPP `php.exe` ilə) — hər ikisi **"No syntax errors detected"** verdi.
- Dəyişdirilən iki PHP faylı və CSS əlavələri əl ilə review edildi: `<?php if/foreach/endif/endforeach` cütləri, `ob_start()/ob_get_clean()` balansı, HTML/`<details>` teq balansı və mövcud `data-*`/`name`/`value`/`action` atributlarının toxunulmadığı təsdiqləndi.
- Brauzerdə canlı smoke test aparılmadı. Tövsiyə olunan yoxlama: `whatsapp-monitor.php?view=chats` səhifəsində birdən çox şirkət nömrəsi olan hesabla nömrə çipləri arasında keçid edib yalnız seçilmiş nömrənin çatlarının göründüyünü yoxla; `?view=screen-wall`-da stream URL-i olmayan/razılığı gözləyən cihazlarda böyük qara qutu əvəzinə kompakt status sətrinin və bağlı "Qeyd yarat" `<details>`-inin göründüyünü, açılanda formun işlədiyini təsdiqlə; mobil enlikdə (≤680px) nömrə seçicisinin üfüqi sürüşdüyünü və çat siyahısı/paneli arasındakı keçidlə üst-üstə düşmədiyini yoxla.

### Qalan risk

- Canlı brauzer/vizual regressiya bu sessiyada icra olunmadı (yalnız `php -l` və əl ilə kod review aparıldı) — yuxarıdakı tövsiyə olunan yoxlama real serverdə aparılmalıdır.
- `chat_number_groups` boş qayıdarsa (heç bir nömrə yoxdursa) seçici render olunmur — bu, mövcud boş-hal mesajları ilə üst-üstə düşməsin deyə qəsdən belə saxlanıldı.

## 2026-07-25 - Publik sayt paketleri, lokal DB env ve Stage 2 dizayn sistemi pilotu

### Deyisdirilen fayllar

- `index.php` - tarif cedveline ferdi 500 Mbit/45 AZN, 1000 Mbit/70 AZN ve biznes 500/50, 1000/75 paketleri elave edildi (uc dilde); coverage-map noqtelerine `coverage-point-flip` sinfi elave edildi.
- `css/style.css` - `.coverage-map`-a `overflow:hidden`, sag terefdeki noqteler ucun `.coverage-point-flip span` qaydasi; mobil 375px-de horizontal overflow (401px) aradan qaldirildi.
- `config.php` - bootstrap-da `app_load_environment_file()`: kok `.env` faylini (ve ya `SUPEROPTIC_SECRET_ENV_FILE`) process environment-e yukleyir; real env deyisenleri hemise ustundur. Lokal Windows/XAMPP-da DB_PASS teleb eden butun sistem yeniden isledi (status.php 503 -> 200).
- `.env` (gitignored) - lokal development sirrleri; `.env.example` movcud sablondur.
- `design-system-lib.php` - `app_design_system_enabled()`/`app_design_system_body_attribute()` nullable PDO qebul edir ve ozu `app_db()` cagirir.
- `portal.php`, `admin-users.php`, `billing-customers.php`, `operations-center.php` - Stage 2 pilot: `css/design-system.css`, `script/design-system.js` ve `data-design-system` body atributu.
- `mgs-integration.php`, `network-definitions.php` - design-system assetleri + native confirm evezine `data-ui-confirm` dialoqu.
- `admin-users.php` - 2 delete formunda native `confirm()` evezine `data-ui-confirm`.
- `script/design-system.js` - MutationObserver-de elave olunan elementin ozu dirty-form selektoruna uygundursa initialize olunur (bug fix).
- Yeni: `tools/stage2-feature-cutover.php`, `tools/stage2-design-system-contract-smoke.php`, `tools/render-stage2-design-system-fixture.php`, `docs/stage2/02_STAGE2_ACCEPTANCE_2026-07-25.md`.
- Migration `20260723_030_stage2_design_system` tetbiq edildi; `stage2_design_system` flag 100% enabled.

### Testler

- PHP lint: butun deyisdirilen fayllar temiz; `node --check script/design-system.js` temiz.
- `tools/stage2-design-system-contract-smoke.php`: 41 pass / 0 fail.
- Canli brauzer fixture: toast, confirm dialog, modal + Escape, dirty-form ve dirty-navigation guard tesdiqləndi.
- `tools/mobile-layout-smoke.php` ok; `tools/automatic-filter-contract-smoke.php` passed (2 ardicil run); `tools/billing-ui-smoke.php` ok.
- Rollback dovru yoxlanildi: disable -> migration rollback -> reapply -> enable.
- Publik sehifeler HTTP 200, konsol xetasiz; mobil 375px overflow yoxdur; 20 tarif karti (4 yeni) render olunur.

## 2026-07-25 - Stage 3: rol-esasli modul is saheleri

### Deyisdirilen fayllar

- `config.php` - `app_portal_request_module()`: operations-center `noc|olt|network` -> network, `bots` -> ai modul xeritesi (auditde tesdiqlenmis read-scope bosluqu baglandi; flag-siz, hemise aktiv).
- `operations-center.php` - axtaris ve overview data bloklari modul icazesine gore scope olunur (customers->billing, tickets/messages->messaging, radius/vlan->network); modul kartlari ve legacy xerite `module` acari ile isarelenib flag aktivken rola gore filtrlenir.
- Yeni `workspace-lib.php` - `app_stage3_workspaces_enabled()`, `app_stage3_filter_workspace_cards()`.
- `whatsapp-monitor-lib.php` - stage3 aktivken `screen-wall` menyudan cixir, birbasa URL alias kimi qalir.
- Yeni migration `20260725_040_stage3_module_workspaces` (flag disabled seed), `tools/stage3-feature-cutover.php`, `tools/stage3-workspace-contract-smoke.php`, `docs/stage3/01_STAGE3_ACCEPTANCE_2026-07-25.md`.
- Flag `stage3_module_workspaces` hazirda 100% enabled.

### Testler

- `stage3-workspace-contract-smoke`: flag off 29/0, flag on 32/0.
- `portal-module-access-smoke` ok, `operations-module-smoke` ok, `whatsapp-monitor-module-smoke` passed.
- PHP lint temiz; HTTP sanity (operations-center, section=bots/search, whatsapp-monitor, billing-customers) PHP xetasiz.
- Rollback dovru: disable -> migration rollback -> reapply -> enable yoxlanildi.

## 2026-07-25 - Stage 4: Mesaj Merkezi / Canli Monitor

### Deyisdirilen fayllar

- `whatsapp-monitor-lib.php` - canonical status kontrakti `whatsapp_monitor_number_status()` (online|sync_delayed|offline|disabled + status_reason + last_activity_at + last_sync_at + unread_count); `whatsapp_monitor_number_status_cards()`; `heartbeat_stale_minutes` (10) ve `sync_stale_minutes` (30) settings; stage4 flag helper, `whatsapp_monitor_default_view()`, `whatsapp_monitor_all_view_keys()`; tabs flag-e gore reorder.
- Yeni `api/whatsapp-monitor/number-status.php` - modul-guarded status API (live poll).
- Yeni `views/whatsapp-monitor/live-monitor.php` - read-only nomre kartlari + self-contained poll script (mesaj gondermir).
- `views/whatsapp-monitor/module.php` - stage4 aktivken command strip/nav Canli Monitor-u primary edir; screen-wall menyudan cixir (alias qalir).
- `whatsapp-monitor.php` - default view stage4-de live-monitor; view validation `all_view_keys` ile (screen-wall direct URL isleyir).
- `css/whatsapp-monitor.css` - `wa-lm-*` live monitor kart stilleri (mevcud `wa-live-status` ile toqquşmur).
- Yeni migration `20260725_050_stage4_message_center`, `tools/stage4-feature-cutover.php`, `tools/stage4-message-center-contract-smoke.php`, `docs/stage4/01_STAGE4_ACCEPTANCE_2026-07-25.md`.
- Flag `stage4_message_center` hazirda 100% enabled.
- Production TEST MESAJI mock artiq `app_demo_fixtures_enabled()` ile qorunub (env=production -> OFF); kod deyisilmedi, smoke ile kilidlendi.

### Testler

- `stage4-message-center-contract-smoke`: flag off 28/0, flag on 29/0.
- `whatsapp-monitor-module-smoke` passed; `message-center-ui-contract-smoke` ok.
- Canli brauzer (admin login): default view Live Monitor render olunur, 2 real kart (0506020055 -> Oflayn -> "Heartbeat kesilib"), status API canonical payload, poll timestamp yenilenir, konsol xetasiz, 375px overflow yoxdur, ?view=screen-wall 200.
- Rollback dovru: disable -> migration rollback -> reapply -> enable.

### Lokal muhit qeydi

- staff-login lokalda: `serdar@sp.az` / `SuperOptic!2026` (admin). Google reCAPTCHA superoptic.net-e baglidir, localhost-da islemir -> `.env`-de RECAPTCHA_SECRET serhe alindi, sistem "local" riyazi captcha-ya kecir. Production-da o setri geri ac.

## 2026-07-25 - Canli Monitor telefon-grid + modal

- `views/whatsapp-monitor/live-monitor.php` yeniden qduruldu: her nomre telefon-karti (3 sutun; 960px-de 2, 600px-de 1). Kartda status badge+sebeb, chat/cavabsiz say ve daxili son chatlar preview-u (`recent_chats`). Karta klik/Enter modal acir (sehife deyismir): solda hemin nomrenin tam chat siyahisi + kontakt axtarisi, sagda secilmis chatin tam mesaj tarixcesi + mesaj axtarisi. aria-modal dialog; backdrop/close/Escape baglayir. Read-only qalir.
- `whatsapp-monitor-lib.php`: `whatsapp_monitor_number_status_cards()` `recent_chats` (ilk 6 chat) elave edir; `$withChats` parametri ile poll yungul saxlanir.
- `api/whatsapp-monitor/number-status.php`: poll `$withChats=false` (chat preview gondermir); modal `chat-list.php`/`chat-messages.php`-den yuklenir.
- `css/whatsapp-monitor.css`: `wa-phone-*` telefon kart stilleri, `wa-modal-*` modal/thread/mesaj bubble stilleri; grid 3->2->1 responsive.
- Brauzer testi (admin): 3 sutun desktop, 2 telefon karti her birinde 6 chat preview; modal acilir, 13 chat + mesajlar yuklenir, chat/mesaj axtarisi filtrleyir, Escape baglayir, konsol xetasiz, overflow yoxdur.
- `stage4-message-center-contract-smoke`: 39/0 (yeni phone/modal marker assertionlari elave olundu); `whatsapp-monitor-module-smoke` passed.

## 2026-07-25 - Stage 5: Vahid Agent Is Sahesi

### Deyisdirilen/yeni fayllar

- Yeni `agent-workspace-lib.php`: canonical service allowlist registry (`app_agent_action_registry`), gate (`app_agent_action_gate` - allowlist + go-live blocker + modul icazesi), preview (`app_agent_action_preview`), prepare (injection+gate+duplicate), injection detektoru, secret/PII sanitizer, idempotency guard, undo (`app_agent_action_undo` - reversed status, hard delete yox). Modullar list ola bilir (create_ticket = messaging|network).
- `copilot-agent.php`: `agent-workspace-lib.php` require; `app_copilot_agent_pending_action` staged action-i `app_agent_prepare_action`-dan kecirir (defense-in-depth, resolved employee account uzerinde). Go-live blocker/injection/duplicate/modul imtinasi ucun azerbaycanca mesajlar.
- Yeni migration `20260725_060_stage5_agent_workspace`: `mgs_operation_actions.status` enum-a `reversed` elave (additive), `stage5_agent_workspace` flag (disabled seed).
- `audit-log-lib.php` (x2) ve `mgs-integration-lib.php` canonical enum tərifləri `reversed` ile yenilendi ki, audit drift detektoru uyğun qalsın.
- Yeni `tools/stage5-feature-cutover.php`, `tools/stage5-agent-eval-suite.php`, `docs/stage5/01_STAGE5_ACCEPTANCE_2026-07-25.md`.
- Flag `stage5_agent_workspace` hazirda 100% enabled.

### Go-live blockerlər agentə YAZILMIR

billing_payment, finance_payout, noc_command, role_change registry-de var amma `go_live_blocker=true` — admin daxil hər kəs üçün refuse.

### Testler

- `stage5-agent-eval-suite`: 46/0 (flag off ve on). Bloklayir: icazesiz action, go-live blocker (admin daxil), namelum kind, prompt injection, secret/PII, duplicate retry; undo reversed status qoyur (hard delete yox), ikinci undo no-op; technician create_ticket-i network ile alir, send_customer_message-den bloklanir.
- `copilot-agent-smoke`: status ok (movcud axin qorundu).
- `operations-action-permission-smoke` passed; `audit-permission-smoke` ve `audit-ledger-smoke` ok (enum drift hell olundu).
- PHP lint temiz; rollback dovru: disable -> migration rollback -> reapply -> enable.

## 2026-07-25 - Stage 6: Huawei OLT/ONT SNMP + CLI matrisi (staged)

### Yeni fayllar

- `huawei-olt-lib.php`: MA5800-X7 (V100R024C10) + MA5608T (V800R019C10) profilleri; ONT modelleri EG8141A5, EG8245W5-6T (capability metadata); 11 standart MIB SNMP OID (MIB-II/IF-MIB/ENTITY-MIB, Huawei enterprise 1.3.6.1.4.1.2011 YOX); kateqoriyali CLI matrisi (read_olt, read_ont, config_change, vlan_push, ont_control); render + context prefix helper; `app_huawei_live_test_plan()` — operatorun 4 sualina gore addim plani (OLT gorunur? ONT gorunur? deyisiklik? VLAN?); `app_huawei_live_ready()` hard gate.
- `db/migrations/20260725_070_stage6_huawei_adapter.php` (flag disabled seed), `tools/stage6-feature-cutover.php`, `tools/stage6-huawei-matrix-smoke.php`, `docs/stage6/01_STAGE6_HUAWEI_MATRIX_2026-07-25.md`.
- Flag `stage6_huawei_adapter` enabled, amma canli icra hele de bloklu.

### Tehlukesizlik: canli hec ne islemir

`app_huawei_live_ready`: model desteklenmirse `unsupported_model`; flag off `feature_flag_disabled`; cihazda `hardware_validated!=true` ise `real_hardware_not_validated` — FLAG ON olsa bele. Yalniz desteklenen model + flag on + hardware_validated=true "live" qaytarir. Dangerous komandalar ayrica movcud `app_mgs_execute_device_command_plan` gate-inden (NOC auth, management path, dangerous-word explicit apply, audit) kecir.

### Testler

- `stage6-huawei-matrix-smoke`: flag off 54/0, flag on 55/0 (mock-only, cihaza qosulmur). Yoxlayir: profiller/modeller, butun kateqoriyalar, read komandalari dangerous deyil / provisioning-vlan-control dangerous, SNMP standart-tree only, hər iki modelde plan komandalari placeholder qalmadan render olunur (display ont info 3 7, interface gpon 0/2), live gate bagli/unsupported reject/unvalidated block.
- PHP lint temiz; rollback dovru yoxlanildi.

### Real OLT gelende

1. Cihazi HUAWEI/MA5800-X7|MA5608T kimi real SSH/SNMP credential ile qeydiyyat. 2. hardware_validated=true. 3. `app_huawei_live_test_plan()` read addimlarini (safe) once isslet — OLT/ONT gorunur? SNMP walk? sonra config/vlan addimlarini explicit apply ile, her expect_marker-i canli output ile tutuşdur.

## 2026-07-25 - Agent intent qatı (Superpilot "anlaşılmadı" problemi)

### Problem
Screenshot-da "musteri yarat astarada" -> generic "anlasilmadi". Customer-create intent yox idi, generic error spec-i pozur.

### Deyisiklik (copilot-agent.php)
- `app_copilot_agent_norm`: mb_strtolower + combining diacritic strip (İ->i, MÜŞTƏRİ->musteri).
- `app_copilot_agent_classify_intent`: intent family classifier (customer_create/show/edit, inventory, ticket, report, performance, analytics), longest-match-wins, typo variantlari (muster/musder/mustri/mstr yarat, yeni abuneci, yeni client elave).
- `app_copilot_agent_customer_create_flow`: area (astarada->Astara)/telefon/FIN/sened/unvan/ad cixarir; catismayan sahələri KONKRET sorur; hamisi varsa confirmation-gated pending create_customer.
- `app_copilot_agent_execute_create_customer`: real `app_billing_upsert_customer_with_identity` cagirir, service-in konkret sahe xetalarini qaytarir.
- `app_copilot_agent_guided_fallback`: generic error EVEZINE intent-aware konkret sual; taninmayan input -> imkan bələdçisi (error yox).
- `create_customer` Stage 5 registry-de (billing, confirmation, reversible=deactivate, blocker deyil).

### Testler
- `agent-intent-understanding-smoke`: 43/0.
- `copilot-agent-smoke`: ok (movcud axinlar qorundu); `stage5-agent-eval-suite`: 46/0.

### LLM qeydi
Deterministik qat offline isleyir. Tam free-form NL ucun DEEPSEEK_API_KEY qoy (app_ai_deepseek_chat artiq fallback-dir). Sened: docs/stage5/02_AGENT_INTENT_LAYER_2026-07-25.md.

## 2026-07-25 - Daxili (on-prem) LLM provideri, kənar servis yox

### Qərar
Kənardan işləyən agent istənmir; AI tam lokal olmalıdır. DeepSeek default deyil, agent daxili LLM runtime-ə (Ollama/OpenAI-uyğun) qoşulur.

### Yeni fayllar / dəyişiklik
- `ai-local-lib.php`: loopback-only lokal LLM (127.0.0.0/8, ::1, localhost). `app_ai_local_config`, `app_ai_local_is_loopback_url`, `app_ai_local_http_transport` (qeyri-loopback bloklanır), `app_ai_local_chat` (Ollama /api/chat + OpenAI /v1/chat/completions), `app_ai_local_status`, `app_ai_agent_chat` (əvvəl lokal; lokal seçilibsə səssizcə buluda keçmir).
- `copilot-agent.php`: `app_ai_deepseek_chat` -> `app_ai_agent_chat`; LLM əlçatmazsa deterministik intent mühərriki cavab verir.
- `.env` / `.env.example`: AI_MODEL_PROVIDER=local, LOCAL_LLM_ENDPOINT=http://127.0.0.1:11434, LOCAL_LLM_MODEL, LOCAL_LLM_API.
- Ollama bu maşında quraşdırılıb və işləyir; model çəkilir (qwen2.5:3b-instruct).

### Testlər
- `tools/ai-local-provider-smoke.php`: 23/0 (loopback qapı, transport rəddi, config, sirr redaksiyası, Ollama+OpenAI parse, boş cavab, wiring).

Sənəd: docs/stage5/03_LOCAL_LLM_2026-07-25.md.

### Canlı yoxlama (2026-07-25)
Ollama bu maşında işləyir; `qwen2.5:3b-instruct` çəkildi. Real end-to-end: `app_ai_local_chat` ok=1 (Azərbaycanca cavab, kontekstlə analiz), `app_copilot_agent_handle_message` free-form sual -> kind=read_only_local_answer, provider=local. `.env` LOCAL_LLM_MODEL=qwen2.5:3b-instruct. Daha yaxşı analiz keyfiyyəti üçün 7b tövsiyə (`ollama pull qwen2.5:7b-instruct`, .env-də modeli dəyiş). Smoke-lar: ai-local 23/0, agent-intent 43/0, copilot ok.

## UX / Superpilot / modem axtarisi duzelisleri (istifadecinin birbasa tapsirigi)

- Superpilot widget-i yalniz billing-ui ve admin-users sehifelerinde idi; indi butun emekdas sehifelerinde (NOC, mesaj merkezi, CRM, RADIUS, portal ve s.) gorunur. Endpoint yolu SCRIPT_NAME-e gore hesablanir, JSON olmayan cavab/401 aydin gosterilir, Enter ile gonderilir.
- Superpilot SN/MAC tokenlerini cumleden ayirib OLT axtarisina verir.
- OLT canli axtarisi: sebebler qaytarilir (VLAN 550 yolu isharelenmeyib, SSH melumati yox, OLT erisilmez, OLT-de tapilmadi); NOC preview-da "not_found" evezine bu sebeb yazilir. SN uygunlasdirilmis (tire/boslug/registr) uygunlasma ile axtarilir.
- Tek linkli menyu qruplari acilan menyu evezine birbasa link olur (iki klik -> bir klik).
- Mesaj merkezinde sablon secende metn onizlemesi (ferdi ve qrup).
- Yoxlama: php -l, message-center-ui-contract-smoke, subsequent-module-shell-smoke, billing-nav-layout-smoke kecdi; DB olmadigi ucun canli OLT/Superpilot testi aparilmayib.
