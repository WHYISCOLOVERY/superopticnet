# Backend contract - Cross-module defect duzelisi round

Bu sened evvelki WhatsApp-only backend kontraktini legv etmir; o hele qüvvədədir (asagida qorunur) ve bu round ucun umumi invariantlar elave olunur.

## Ümumi invariantlar (butun modullar, bu round)

- Auth/session, CSRF, rol/permission ve object-scope yoxlamalari servis qatinda qalir; UI-da gizletmek permission yoxlamasinin evezi deyil.
- Append-only audit jurnali davam edir; her write action audit qeydi yaradir.
- Payment/RADIUS idempotency: movcud state machine ve compensation davranisi deyismir; retry duplicate yaratmamalidir.
- Schema deyisikliyi yalniz elave (additive), versiyali ve rollback-li migration ile.
- Movcud service/API-ler tekrarlanmir; duzelis movcud qati genisləndirir.
- Production write/deploy, real mesaj gonderisi ve canli device/router emeliyyati bu roundda tetiklenmir.

## WhatsApp chat monitor kontrakti (evvelki rounddan, hele qüvvədədir)

`views/whatsapp-monitor/chats.php` ucun:

- `chat_number_groups`: `number_id`, `company_number`, `employee_name`, `chat_count`, `message_count`, `last_message_at`, `qr_status`.
- `selected_number_id`: aktiv sirket WhatsApp nomresinin ID-si.
- `chat_rows`: yalniz `selected_number_id` ucun chatlar.
- `selected_chat_id`, `selected_chat`, `chat_messages`: secilmis nomre daxilindeki thread.

`api/whatsapp-monitor/chat-list.php` `number_id` teleb edir. `number_id` olmadan `422 number_scope_required` qaytarir.

Frontend hook-lari: `data-wa-chat-monitor`, `data-selected-number-id`, `data-wa-chat-thread`, `data-wa-chat-list`, `data-wa-chat-search-item`, `data-screen-wall`, `data-screen-wall-fullscreen`.

`api/provider/whatsapp-status.php` yalniz admin ucun read-only JSON endpointdir. Provider token browser-e verilmir. Cavab: `connected`, `reachable`, `status`, `provider_number`, `last_qr_at`, `last_error`; `qr` yalniz `data:image/` URI kimi. Stream iframe yalniz backend `canShowStream` serti dogru olduqda render edilir.

## Bu round ucun elave qayda

Claude bu roundda backend/API/DB/permission kodunu birbasa deyise bilir, amma:

- yuxaridaki umumi invariantlari ve WhatsApp kontraktini pozmur;
- production writes/deploy/external send/live device action tetikleyen yeni yol elave etmir;
- her deyisiklik ucun fokuslu smoke/regression testi elave edir ve ishe salir;
- natamam/yoxlanilmamis sahede real data yoxdursa mock/demo fallback yaratmaq evezine acıq bos/error veziyyeti saxlayir;
- Codex-in implementasiyadan sonrakı review-u production go-live-dan evvel mecburidir; bu sened tek basina go-live tesdiqi sayilmir.

## Tehlukesizlik

`docs/32_UNIFIED_UX_AUDIT.md` bolme 4-de qeyd olunan tesdiqlenmis problemler (payment/RADIUS idempotency, Finance GET side-effect, NOC credential/permission, plaintext sirr riski) bu roundun prioritet siyahisinin bir hissesidir; hell olunmadan uygun go-live blocker kimi qalir.
