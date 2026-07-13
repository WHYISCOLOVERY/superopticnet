# Claude frontend tapsirigi: Mesaj Merkezi

## Meqsed

Mesaj Merkezini orta yasli operatorun qisa telimle istifade ede bileceyi sade, aydin ve responsiv qurulusa sal. Backend mentiqini ve butun form contract-larini qoru.

## Mutleq neticeler

1. `overview` gundelik is xulasesi olsun: kanal statistikasi, novbe/provider veziyyeti ve esas kecidler.
2. SMS credential, WhatsApp bridge/limit, CLI ve worker detallari `settings` gorunusune kecsin.
3. `settings` yalniz movcud admin sertleri daxilinde render olunsun.
4. Texniki CLI ve worker cedveli default bagli `details` bolmesinde olsun.
5. Ferdi mesaj, qrup mesaj, tarixce, audit, sablon ve test sehifelerinde qisa ve tebii Azerbaycan dili istifade olunsun.
6. Mobilde form, button, KPI ve cedveller bir-birinin ustune minmesin; daxili sehife scroll-u yaratma.
7. Cedvel mobil gorunusunde basliqlar data label kimi oxunsun.
8. Movcud AJAX kecid, filter ve pagination hook-larini qoru.

## Deyise bileceyin fayllar

- `message-center.php` faylinin yalniz `<!DOCTYPE html>`-dan sonrakı teqdimat hissesi
- `css/message-center.css` (yeni)
- `script/message-center.js` (yeni)
- `.ai-collaboration/CLAUDE_MESSAGE_CENTER_RESULT.md`

## Deyise bilmeyeceyin saheler

- `message-center.php` faylinin PHP/backend hazirliq hissesi
- SQL, auth, permission, CSRF, provider, DB ve queue mentiqi
- movcud form field adlari, action deyerleri ve data hook-lari
- diger modul fayllari
- git emeliyyatlari

## Vizual istiqamet

- Movcud portal dizayn sistemi ve Font Awesome ikonlari ile uygun qal.
- Radius maksimum 8px olsun; nested card yaratma.
- Qisa basliqlar, aydin label-lar ve konkret duymeler istifade et.
- Texniki terminlerin yaninda istifadeci ucun aydin netice yaz.
- Hero iri reklam sahesi kimi yox, kompakt emeliyyat basligi kimi qalmalidir.
- Rengler status menasi dasimalidir: yasil hazir/gonderilib, narinci gozleyir, qirmizi xeta.

## Yoxlama

Is bitende `CLAUDE_MESSAGE_CENTER_RESULT.md` daxilinde:

- deyisen fayllari
- qorunan contract-lari
- mobil/desktop UX neticesini
- qalan riskleri

qisa yaz.
