# Mesaj Merkezi backend contract

Status: `READY_FOR_FRONTEND`

## Giris ve icaze

- Sehife employee session teleb edir: `billing_require_employee()`.
- Ferdi mesaj ve oxuma gorunusleri modul icazesi olan emekdas ucundur.
- Qrup mesaj, provider testi, SMS/WhatsApp ayarlari ve retry emeliyyatlari yalniz `app_portal_is_admin($user)` serti ile gorunur ve icra olunur.
- Butun POST emeliyyatlari movcud CSRF yoxlamasini saxlamalidir.

## View contract

- `overview`: kanal istifadəsi, novbe ve provider veziyyetinin sade xulasesi
- `single`: ferdi mesaj formu ve musteri axtarisi
- `group`: admin ucun qrup mesaji
- `templates`: hazir mesaj sablonlari
- `history`: filter, tarixce, sehifeleme ve retry
- `audit`: real provider cehdleri
- `test`: admin ucun provider testi
- `settings`: admin ucun SMS credential, WhatsApp bridge/limit ve texniki worker detallari

View kecidleri `data-message-nav` ve `data-message-content` ile sehifeni tam yenilemeden islemelidir. Browser geri/ireli duymeleri `popstate` ile eyni gorunusu qaytarmalidir.

## Deyisdirilmez POST action-lar

- `send_single`
- `send_group`
- `provider_test`
- `save_sms_provider_credentials`
- `retry_message`
- `bulk_retry_failed`
- `update_whatsapp_guard`

Butun form `name`-leri, hidden `action` deyerleri, `csrf_token`, checkbox ve select deyerleri geriye uyumlu qalmalidir.

## UX neticesi

- Umumi baxisda credential formu, CLI emrleri ve uzun texniki ayarlar gorunmemelidir.
- Ayarlar yalniz admin ucun ayrica `settings` gorunusunde olmalidir.
- Gundelik operator emeliyyatlari ilk baxishda Ferdi mesaj, Tarixce ve status xulasesi kimi gorunmelidir.
- Texniki melumatlar `details` daxilinde bagli veziyyetde verilmelidir.
- Mobil cedveller data label ile oxunmali, sehifede horizontal ust-uste minme olmamalidir.
- Istifadeci metnleri tebii Azerbaycan dilinde olmalidir; provider/API terminleri yalniz menani qorumaq ucun saxlanila biler.

## Tehlukesizlik

- Credential deyerleri maskalanmis qalir; password sehifeye geri yazilmir.
- Real provider test checkbox-u default secilmemis qalir.
- Retry yalniz `failed` statusuna tetbiq olunur.
- Claude hec bir real mesaj gondermir ve provider credential-i deyismir.
