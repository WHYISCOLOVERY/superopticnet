# Inteqrasiya hesabati

Status: `SECOND_FRONTEND_PASS_REQUIRED`

## Isleyen inteqrasiyalar

- Claude birinci frontend kecidinde inline CSS/JS-i modul assetlerine ayirib.
- Cat secimi `number_id + chat_id` ile backend scope-a salinib.
- PHP lint, JavaScript syntax, chat ingest, monitor module ve WhatsApp guard smoke testleri kecib.
- WhatsApp bridge health endpoint-i `ok` qaytarib.

## Islemeyen inteqrasiyalar

- Mobil `Siyahiya qayit` davranisi explicit secim bayragina kecirilmelidir.
- Bos catda ilk polling mesaji gelende movcud empty-state DOM-dan silinmelidir.
- Cat siyahisi yeni `api/whatsapp-monitor/chat-list.php` endpoint-i ile reload-suz yenilenmelidir.
- Polling xetasi istifadeciye sessiz qalmaq evezine sade canli-status kimi gosterilmelidir.

## Backend deyisiklikleri

- Cat mesaji sorğusu sirket nomresi ve chat ID ile izolyasiya edilib.
- Ilkin mesaj sorğusu en son 300 setri xronoloji qaytarir.
- Worker queue token-i URL query-den cixarilib; header contract-i qorunub.
- Chat list endpoint-i ve explicit mobil secim context-i elave edilib.

## Claude-a qaytarilacaq frontend meseleleri

- Yuxaridaki dord frontend meselesi ikinci Claude kecidine qaytarilir.

## Qalan riskler

- In-app browser runtime lokal kernel asset xetasi verdiyi ucun vizual yoxlama hele tamamlanmayib.
- CLI syntax ve funksional smoke testleri ugurludur; ikinci kecidden sonra vizual smoke alternativ lokal Playwright yolu ile yoxlanacaq.
