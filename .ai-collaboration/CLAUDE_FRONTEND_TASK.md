# Claude frontend tapsirigi

Status: `REMAINING_PAGES_READY`

Islemeye baslamazdan evvel:

1. `.ai-collaboration/TASK.md` oxu ve statusun `READY` oldugunu yoxla.
2. `.ai-collaboration/FILE_OWNERSHIP.md` oxu.
3. `.ai-collaboration/BACKEND_CONTRACT.md` oxu.
4. Yalniz Claude frontend sahibliyine verilen fayllari deyis.
5. Qarisiq ve backend fayllarini deyisme.
6. Movcud biznes mentiqini, endpoint contract-ini, dil idareetmesini ve real melumati qoru.
7. Mock ve hardcoded istifadeci melumati yaratma.
8. AJAX islerinde tam sehife reload yaratma; movcud partial-fetch axinini qoru.
9. Desktop, tablet ve mobil gorunusu yoxla.
10. Loading, empty, error, success, focus ve keyboard hallarini tamamla.
11. Orta yasli emekdaslar ucun esas emeliyyatlari aydin, oxunaqli ve boyuk klik sahesi ile qur.
12. Frontend syntax ve movcud UI smoke testlerini islet.
13. Neticeni `.ai-collaboration/CLAUDE_FRONTEND_RESULT.md` faylina yaz.

## Aktiv dizayn tapsirigi

- `views/whatsapp-monitor/module.php` daxilindeki boyuk inline CSS blokunu `css/whatsapp-monitor.css` faylina cixar.
- `whatsapp-monitor.php` daxilindeki frontend polling kodunu `script/whatsapp-monitor.js` faylina cixar; backend/auth/POST hissesine toxunma.
- Catlar sehifesini aydin iki-panel is axini kimi qur: axtaris, nomre/emekdas metadata-si, mesaj sayi, secilmis cat basligi, yeni mesaj veziyyeti ve empty state.
- `number_id + chat_id` link/data contract-indan istifade et.
- QR sehifesinde worker endpoint kimi texniki metni esas istifadeci goruntusunden cixar; proses statusunu sade Azerbaycan dilinde goster.
- Esas emeliyyatlari 5-7 secimde saxla, ikinci dereceli naviqasiyani qruplasdir.
- Mobil ekranda cat siyahisi ve yazisma arasinda aydin kecid yarat; ic-ice genis scroll yaratma.
- Buton, status, focus, loading, error ve reduced-motion hallarini tamamla.
- Movcud portal dizayn tokenlerini istifade et, yeni agır framework elave etme.

Real frontend fayl deyisikliyi ve test subutu yoxdursa merhele tamamlanmis sayilmir.

## Ikinci kecid ucun konkret duzelisler

Codex inteqrasiya yoxlamasindan sonra yalniz asagidaki frontend meselelerini duzelt:

1. Context-deki `chat_selection_explicit` deyerini istifade et. Desktop-da default ilk cat gorune biler, mobilde URL secimi yoxdursa siyahi gorunmelidir. `Siyahiya qayit` yeniden ilk cati acmamalidir.
2. Bos cat aciq iken polling ile ilk mesaj gelirse `.mgs-empty-state` blokunu mesaj elave edilmezden evvel sil.
3. `GET api/whatsapp-monitor/chat-list.php` endpoint-ini 5 saniyelik yüngül polling ile istifade et. Movcud setirlerin preview, tarix ve mesaj sayini yenile; yeni cat gelirse sehifeni reload etmeden siyahiya elave et.
4. Cat basliginda sade canli status elementi yarat: `Canli`, `Yenilenir`, `Baglanti zeifdir`. Texniki endpoint ve xeta metni gosterme.
5. Sehife arxa planda olanda lazimsiz polling-i azalt ve yeniden aktiv olanda derhal yenile.
6. Axtarisde Azerbaycan herfleri ucun locale-aware kiciltme istifade et.
7. Backend, API, PHP context, auth, CSRF ve permission kodunu deyisme.
8. Neticeni eyni `CLAUDE_FRONTEND_RESULT.md` faylinda `Ikinci kecid` bolmesi ile tamamla.

## Qalan səhifələr üçün aktiv keçid

Yalnız aşağıdakı frontend fayllarında işləyin:

- `views/whatsapp-monitor/overview.php`
- `views/whatsapp-monitor/screen-wall.php`
- `views/whatsapp-monitor/numbers.php`
- `views/whatsapp-monitor/devices.php`
- `views/whatsapp-monitor/cases.php`
- `views/whatsapp-monitor/performance.php`
- `views/whatsapp-monitor/settings.php`
- `views/whatsapp-monitor/audit.php`
- `css/whatsapp-monitor.css`
- nəticə qeydi üçün `.ai-collaboration/CLAUDE_FRONTEND_RESULT.md`

Tələblər:

1. Görünən bütün mətnləri düzgün və təbii Azərbaycan dilinə çevir; DB status key, form name/value, action, URL və data atributlarını dəyişmə.
2. İngiliscə texniki statusları istifadəçi label-ları ilə göstər, lakin saxlanan dəyərləri eyni saxla.
3. Overview KPI-lərini skan edilə bilən qruplara sal; eyni məlumatı təkrarlama.
4. Screen wall grid, `cols` filtri və fullscreen contract-ini qoru; cihaz kartlarını daha aydın status, metadata və case formu ilə düzəlt.
5. Numbers/devices/cases/audit cədvəllərini desktop-da cədvəl, mobil ekranda label-li kart kimi oxunaqlı et. Mövcud form və action-ları qoru.
6. Yaratma formalarını aydın başlıq, qısa izah və responsive input grid ilə qur; texniki opsional sahələri əsas axından ayır.
7. Performance və settings boş/readonly hallarını sadə dildə göstər.
8. Böyük radius, yeni framework, gradient, dekorativ orb və ağır animasiya əlavə etmə; mövcud portal tokenlərindən istifadə et.
9. `module.php`, `chats.php`, `pairing.php`, JavaScript, backend, API, DB, auth, permission və CSRF koduna toxunma.
10. Dəyişiklikləri manual review et və nəticəni `CLAUDE_FRONTEND_RESULT.md` daxilində `Qalan səhifələr keçidi` bölməsinə yaz.
