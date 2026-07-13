# Fayl sahibliyi

Bu siyahi ilkin serheddir. Her konkret tapsiriqda yalniz elaqeli fayllar daha dar siyahi ile qeyd edilmelidir.

## A. Codex backend fayllari

- `api/**`
- `db/**`
- `db.php`
- `db-helpers.php`
- `*-lib.php`
- `*-module-lib.php`
- `*-module-partial.php`
- `provider-api-lib.php`
- `operations-permission-lib.php`
- `audit-log-lib.php`
- `tools/*.php`, `tools/*.py`, `tools/*.js` test ve maintenance skriptleri
- `deploy/**`

Claude bu fayllari yalniz oxuya biler; deyise bilmez.

## B. Claude frontend fayllari

- `views/**`
- `css/**`
- `script/**`
- `images/**` yalniz optimizasiya ve UI asset isi ucun
- yalniz gorunus yaradan yeni partial ve komponentler

Codex inteqrasiya merhelesinde bu fayllari sebebsiz yeniden dizayn etmemelidir.

## C. Qarisiq fayllar

- `operations-center.php`
- `billing-section-page.php`
- `portal.php`
- `whatsapp-monitor.php`
- `message-center.php`
- `billing-ui.php`
- `page-helpers.php`
- kokde HTML, PHP biznes mentiqi ve JavaScript-i birlikde saxlayan diger sehifeler

Qarisiq fayl Claude-a verilmezden evvel Codex konkret deyisiklik serhedini yazmali veya view hissesini partial-a ayirmalidir.

## Runtime ve mexfi saheler

Asagidaki yollar hec bir agente source deyisikliyi ucun verilmir:

- `config.local.php`
- `whatsapp-bridge/.env`
- `whatsapp-bridge/.wwebjs_auth/**`
- `whatsapp-bridge/.wwebjs_cache/**`
- `tmp/**`
- `.tools/**`
