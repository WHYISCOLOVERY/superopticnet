# Claude frontend neticesi: Mesaj merkezi

## Deyisen fayllar

- `message-center.php` - yalniz `<!DOCTYPE html>`-dan sonrakı teqdimat hissesi
- `css/message-center.css` (yeni)
- `script/message-center.js` (yeni)

## Nə edildi

1. `overview` gunluk emeliyyat paneline sadelesdirildi: kanal/status qrafiki, novbe/kanal siyahisi, provider hazirlik siyahisi ve (admin ucun) "Ayarlara kec" linki. SMS credential, WhatsApp bridge/QR, guard limitleri, CLI ve worker cedveli buradan cixarildi.
2. Yeni `view=settings` bolmesi elave edildi (`app_portal_is_admin($user) && $messageView === 'settings'` serti ile). SMS provider forması (`id="sms-provider-settings"`, nav-dakı `#sms-provider-settings` lövhəsi ilə uyğun), WhatsApp bridge/QR, guard statistikası ve limit formu bu bolmeye kocdu.
3. "Novbe CLI komandalari", "Provider test CLI komandalari" ve "Son worker icralari" defolt bagli `<details class="message-details">` daxilinde verildi.
4. Inline `<style>` ve inline AJAX-nav `<script>` bloklari `css/message-center.css` ve `script/message-center.js` fayllarina kocuruldu (mezmun deyismedi, yalniz radiuslar 8px-e endirildi ve gradient fonlar duz reng ile evez olundu).
5. Tarixce, audit, sablon ve worker cedvellerinde mobil `data-label` atributlari elave edildi (portal.css-in movcud mobil `td::before{content:attr(data-label)}` qaydasindan istifade edir).
6. Hero ve bezi label mətnləri daha təbii Azərbaycan dilinə uyğunlaşdırıldı ("Username"/"Password" -> "Istifadeci adi"/"Sifre"); provider/texniki terminler saxlanildi.

## Qorunan contract-lar

- Butun POST `action` deyerleri (`send_single`, `send_group`, `provider_test`, `save_sms_provider_credentials`, `retry_message`, `bulk_retry_failed`, `update_whatsapp_guard`) ve butun `name`/`id`/`csrf_token` sahələri deyismeyib.
- `app_portal_is_admin($user)` sertleri butun admin UI-larin (qrup mesaj, provider test, settings, guard formu, retry) etrafinda saxlanilib, hetta bir yerde elave edilib (yeni `settings` view uchun).
- `data-message-nav`, `data-message-content`, `data-message-shell`, `data-ticket-message`, `data-char-counter` hook-lari deyismeyib; AJAX navigasiya/popstate/filter/pagination scripti funksional olaraq eynidir (yalniz ayrica fayla kocurulub).
- `view` acarlarinin siyahisi ve menasi (backend hazirlaninda) deyismeyib; yalniz `settings` ucun ilk defe presentation-da real content elave olunub (evvel bos qalirdi).

## Mobil/Desktop UX

- Kart radiuslari 8px-e endirildi, gradient fonlar cixarildi (command-strip, whatsapp-bridge).
- Guard grid, command actions, filter bar ve worker grid 900/760/560px-de tek suetuna keçir; `message-layout` 1100px-de tek sutuna keçir.
- Cedveller (tarixce/audit/sablon/worker) portal.css-in movcud mobil cedvel qaydasi ile `data-label` gosterir, umumi ust-uste minme yaranmir.
- Custom duymeler (`message-soft-btn`, `message-danger-btn`, `message-inline-action button`, `message-details summary`) ucun `:focus-visible` konturu elave edildi.
- Real provider test checkbox-u (`provider_apply`) defolt secilmemis qalib.

## Qalan risk

- `message-center.css`/`message-center.js` ilk defe yaradilan fayllardir; server-de `app_asset()` fayl mtime-ina gore versiya qoyur, cache-i yoxlamaq lazimdir.
- Realistic görüntü smoke testi (real DB ve login ile) subagent daxilinde aparilmadi; PHP sintaksisi elle balans yoxlamasi (if/endif, div/section/form/table sayi) ile teqdiq olundu, `php -l` iceridən icra icazesi tələb etdiyi üçün işlədilmədi.
