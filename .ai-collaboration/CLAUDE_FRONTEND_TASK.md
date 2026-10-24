# Claude frontend tapsirigi

Status: `DONE`

## Meqsed

WhatsApp chat monitorunu nomre/emekdas uzre ayirmaq ve stream olmayan canli monitor kartlarini kompakt hala getirmek.

## Fayllar

- `views/whatsapp-monitor/chats.php`
- `views/whatsapp-monitor/screen-wall.php`
- `css/whatsapp-monitor.css`

## Telebler

1. `chat_number_groups` ile nomre/emekdas secici yarat.
2. `chat_rows` yalniz secilmis nomrenin chatlari kimi gosterilsin.
3. `data-selected-number-id` hook-u `data-wa-chat-monitor` elementinde olsun.
4. Stream olmayan cihazda boyuk qara frame render etme.
5. Stream olmayan cihaz ucun status sebebi ve acilib-baglanan kompakt `Qeyd yarat` formu goster.
6. Real stream ucun iframe ve fullscreen qorunsun.
7. Mobilde daxili scroll ve input/button ust-uste minmesi olmasin.
8. Gorunen metnler temiz ve tebii Azerbaycan dilinde olsun.

## Qadağalar

- Backend, DB, API, auth, permission, CSRF ve JS fayllarini deyisme.
- Movcud istifadeci deyisikliklerini geri qaytarma.
- Form action/name/value contractlarini deyisme.

## Netice

Deyisdirilen fayllari ve lint neticesini `.ai-collaboration/CLAUDE_FRONTEND_RESULT.md` faylina elave et.
