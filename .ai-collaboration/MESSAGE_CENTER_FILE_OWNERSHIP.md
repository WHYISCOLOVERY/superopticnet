# Mesaj Merkezi fayl sahibliyi

Aktiv paket: Mesaj Merkezinin gundelik istifade, ayarlar ve texniki nezaret gorunuslerine ayrilmasi.

## Codex sahesi

- `message-center.php` faylinin PHP emeliyyatlari, SQL ve context hazirlayan 1-789-cu setirleri
- `communication-provider.php`
- `communication-queue-lib.php`
- `mgs-integration-lib.php`
- `config.php`
- `views/_module-group-nav.php`
- `tools/message-center-*.php`
- `tools/sms-provider-config-smoke.php`
- `tools/full-system-regression.php`

Claude bu fayllardaki backend mentiqini deyise bilmez.

## Claude frontend sahesi

- `message-center.php` faylinda `<!DOCTYPE html>` ile baslayan teqdimat hissesi
- yeni `css/message-center.css`
- yeni `script/message-center.js`
- `.ai-collaboration/CLAUDE_MESSAGE_CENTER_RESULT.md`

## Qarisiq fayl qaydasi

`message-center.php` qarisiq fayldir. Claude teqdimat hissesinde HTML qruplasmasi, CSS/JS importu ve istifadeci metnlerini deyise biler. Asagidakilari deyise bilmez:

- PHP funksiya, SQL, provider ve queue mentiqi
- `name`, `value`, `id`, `method` ve `action` form contract-lari
- `csrf_token` inputlari
- `app_portal_is_admin($user)` sertleri
- `data-message-*`, `data-module-*`, `data-ticket-message`, `data-char-counter` hook-lari
- `view` acarlarinin menasi

## Qadagan saheler

- `config.local.php`
- provider token ve sifreleri
- `whatsapp-bridge/.env`
- `tmp/**`
- `.tools/**`
- DB schema ve real data
