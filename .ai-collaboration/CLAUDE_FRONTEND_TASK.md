# Claude frontend tapsirigi

Status: `READY`

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
