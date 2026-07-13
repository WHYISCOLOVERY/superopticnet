# Fayl sahibliyi

Aktiv WhatsApp Monitoring tapsirigi ucun daraldilmis fayl serhedleri asagidadir.

## A. Codex backend fayllari

- `whatsapp-monitor-lib.php`
- `api/whatsapp-monitor/**`
- `whatsapp-monitor.php` daxilindeki auth, POST action ve AJAX data wiring
- `whatsapp-bridge/server.js`
- `tools/whatsapp-*.php`
- `tools/full-system-regression.php`

Claude bu fayllari yalniz oxuya biler; deyise bilmez.

## B. Claude frontend fayllari

- `views/whatsapp-monitor/**`
- WhatsApp modulu ucun yeni `css/whatsapp-monitor.css`
- WhatsApp modulu ucun yeni `script/whatsapp-monitor.js`
- yalniz WhatsApp gorunusu yaradan yeni partial ve komponentler

Codex inteqrasiya merhelesinde bu fayllari sebebsiz yeniden dizayn etmemelidir.

## C. Qarisiq fayllar

- `whatsapp-monitor.php`

Claude `whatsapp-monitor.php` faylinda yalniz frontend script/style importu ve gorunus wiring hissesine toxuna biler. Auth, POST action, CSRF ve context yaratma hissesini deyise bilmez.

## Runtime ve mexfi saheler

Asagidaki yollar hec bir agente source deyisikliyi ucun verilmir:

- `config.local.php`
- `whatsapp-bridge/.env`
- `whatsapp-bridge/.wwebjs_auth/**`
- `whatsapp-bridge/.wwebjs_cache/**`
- `tmp/**`
- `.tools/**`
