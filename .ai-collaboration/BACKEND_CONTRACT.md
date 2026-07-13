# Backend contract

Status: `READY_FOR_FRONTEND`

## Sehife contract-i

`GET /whatsapp-monitor.php?view=chats&number_id={id}&chat_id={id}`

- Employee session ve `whatsapp_monitor` modul icazesi teleb olunur.
- `can_view_all` rollari butun sirket nomrelerini gore biler.
- Diger emekdas yalniz oz `employee_id` scope-undaki nomre ve mesajlari gore biler.
- Secilmis cat hem `number_id`, hem `chat_id` ile identifikasiya olunur.

View context:

- `chat_rows`: cat xulasesi; her setirde `number_id`, `chat_id`, `contact_name`, `company_number`, `employee_name`, `last_message`, `last_message_at`, `last_message_id`, `message_count`.
- `selected_number_id`: secilmis sirket nomresinin DB ID-si.
- `selected_chat_id`: WhatsApp chat ID-si.
- `selected_chat`: secilmis xulase setri.
- `chat_selection_explicit`: URL-de istifadeci secimi varsa `true`; mobil geri kecid bunu istifade edir.
- `chat_messages`: son 300 mesaj, kohne -> yeni ardicilliqda.
- `can_manage`: yalniz super admin.
- `can_view_all`: admin ve monitorinq rollari.

## Real-time mesaj endpoint-i

`GET /api/whatsapp-monitor/chat-messages.php?number_id={id}&chat_id={id}&after_id={id}`

- Employee session ve modul icazesi teleb olunur.
- `number_id` musbet tam eded, `chat_id` bos olmayan metn olmalidir.
- `after_id=0` son 300 mesaji xronoloji qaytarir.
- `after_id>0` yalniz yeni mesajlari artan ID ardicilligi ile qaytarir.

Ugurlu cavab:

```json
{
  "ok": true,
  "number_id": 12,
  "chat_id": "994501234567@c.us",
  "last_message_id": 9001,
  "rows": []
}
```

Validation xetasi `422`, icaze xetasi movcud session/permission qati ile `403` qaytarir.

## Real-time cat siyahisi endpoint-i

`GET /api/whatsapp-monitor/chat-list.php`

- Employee session ve modul icazesi teleb olunur.
- Eyni rol scope-u ile `chat_rows` massivini qaytarir.
- Sehifeni reload etmeden yeni cat, son mesaj metni, tarix ve say yenilenmesi ucundur.

## QR status endpoint-i

`GET /api/whatsapp-monitor/pairing-status.php?session_key={key}`

- Employee session teleb olunur.
- Admin/monitor rollari butun scope-u, emekdas yalniz oz nomresinin sessiyasini gore bilir.
- Cavab `status`, `status_label`, `qr_image_data_uri`, `worker_id`, `failure_reason` verir.

## Worker endpoint-leri

- `GET /api/whatsapp-monitor/pairing-queue.php`
- `POST /api/whatsapp-monitor/pairing-update.php`
- `POST /api/whatsapp-monitor/message-ingest.php`

Worker token `X-WhatsApp-Monitor-Token` header-i ile gonderilir. Claude bu endpoint-leri ve worker contract-ini deyismemelidir.

## Deyisdirilmemeli biznes qaydalari

- QR sessiyasini yalniz super admin yarada ve legv ede biler.
- Nomre aktiv ve monitoring policy tesdiqli olmalidir.
- Connected statusu kohne `qr_ready/scanned` update-i ile geri salina bilmez.
- Worker ingest deduplication `whatsapp_message_id` ile qorunur.
- Sehife mesaj gondermir; yalniz monitorinq edir.
- CSRF-li POST emeliyyatlari ve audit log qorunur.

## Umumi JSON formati

Yeni AJAX endpoint yaradilarse standart format:

```json
{
  "ok": true,
  "data": {},
  "message": ""
}
```

Xeta formati:

```json
{
  "ok": false,
  "message": "Istifadeci ucun aydin xeta mesaji",
  "errors": {
    "field": "Sahe uzre xeta"
  }
}
```

Movcud endpoint ferqli contract istifade edirse, uygunlasdirma geriye uyumlulugu pozmamalidir.
