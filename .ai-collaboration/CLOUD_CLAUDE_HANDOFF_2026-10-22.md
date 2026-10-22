# Bulud Claude sessiyasının təhvil qeydi (2026-10-22)

Bu sənəd yerli Claude üçündür: PR #5-in nələr dəyişdiyini, hansı faylların serverdə əllə dəyişdirildiyini (birləşdirmə riski) və nələrin yoxlanılmadığını göstərir.
Parol, açar və token bu sənəddə yoxdur.

## Mənbə və branch

- PR: https://github.com/WHYISCOLOVERY/superopticnet/pull/5 (draft)
- Branch: `claude/rebased-on-live`, baza: `agent/ai-crew-bridge` (istifadəçinin 2026-10-22 səhər push etdiyi server versiyası).
- `claude/ux-superpilot-olt-search` köhnə `main` üzərində idi (PR #4, bağlanıb) və artıq lazım deyil.
- 46 fayl dəyişib. Canlı DB/OLT/Flutter SDK olmadığından yalnız `php -l`, mövcud UI smoke testləri və Dart mötərizə balansı yoxlanıb.

## Dəyişikliklər

### Backend (PHP)
- `copilot-widget.php`: SuperPilot yalnız işçi (`employee`) rolu üçün render olunur, endpoint yolu `SCRIPT_NAME`-dən hesablanır, JSON olmayan cavab/401 göstərilir, Enter ilə göndərilir.
- ~30 işçi səhifəsinin `</body>`-sindən əvvəl bir sətir: `require_once copilot-widget.php; app_render_copilot_widget(...)` (billing-*, crm, noc-*, radius-*, operations-center, portal, message-center və s.).
- `copilot-agent.php`: mesajdan SN/MAC tokenləri ayrılır və modem axtarışına verilir (`$actor` ötürülür).
- `mgs-integration-lib.php`:
  - `app_mgs_hsgq_live_find_ont_by_query(..., array $actor = [], ?string &$reason = null)`: uğursuzluq səbəbləri (`management_path_down`, `no_olt_profile`, `no_ssh_credentials`, `olt_unreachable`, `not_on_olt`), SN normallaşdırılması (tire/boşluq/registr).
  - `app_mgs_live_lookup_reason_text()`, `app_mgs_build_modem_search_reply()` not_found cavabına `live_reason` və `text` əlavə edir.
  - `app_mgs_store_live_ont_snapshot()`: canlı oxunan TX/RX/temperatur müştərinin `ont_profiles` qeydinə yazılır (OLT cavab verməyəndə son dəyər göstərilir). Yalnız müştəriyə artıq bağlı ONT üçün işləyir.
- `config.php`: `app_csrf_field()` köməkçisi əlavə olundu. Repoda heç yerdə təyin olunmamışdı, amma `noc-devices.php` çağırırdı (müştərisi tapılan ONT axtarışında "Live optical oxu" düyməsi funksiya yoxdursa fatal xəta verir).
- `api/mobile/v1/staff/modem-search.php`: 404 cavabında `live_reason` və səbəb mətni qaytarılır.
- `api/mobile/v1/manager/route-history.php` (yeni): bir əməkdaşın seçilmiş günü üçün bütün GPS nöqtələri (≤1500-ə seyrəldilir), dayanacaqlar (60 m daxilində ≥5 dəq), gəliş/bitiş nöqtələri, məsafə, iş sessiyaları. Ərazi icazəsi `fleet-live.php` ilə eynidir. Son 31 gün.

### Frontend (PHP/CSS/JS)
- `noc-devices.php`: ONT axtarışı görünüşündə böyük başlıq və stat kartları gizlədilir (axtarış yuxarıda); canlı OLT nəticəsi "OLT-dən canlı nəticə" başlığı altındadır; heç nə tapılmayanda səbəb göstərilir; monitor cihazları kartları iki qrupda (router-lər yuxarı, OLT-lər 4 sütunlu şəbəkə), bütün cihazlar göstərilir (əvvəl 12), mövqe/ölçü saxlamasında xəta görünür (`has-save-error`), yarımçıq saxlama `pagehide`-da tamamlanır, localStorage ehtiyatı.
- `billing-customer-profile.php`: "Müştəri xülasəsi" və "Sağlamlıq" kartları silindi (hero ilə təkrar idi); İcmal tab-ına "Son fəaliyyət" (ticket / mesaj / verilmiş avadanlıq) əlavə olundu; KPI və əməliyyat düymələri şəbəkəsi `auto-fit`.
- `message-center.php`, `script/message-center.js`, `css/message-center.css`: şablon seçəndə mətn önizləməsi (fərdi və qrup).
- `views/_module-group-nav.php`, `css/portal.css`: server artıq tək keçidli qrupları birbaşa keçid edirdi; mənim variantım atıldı, yalnız CSS əlavələri qaldı.

### Mobil (Flutter)
- `billing_summary_screen.dart`: `GridView.count(childAspectRatio)` → `SliverGridDelegateWithFixedCrossAxisCount(mainAxisExtent: 176)` ("Bottom overflowed by 3.8 pixels" düzəlişi). Digər ekranlarda hələ `childAspectRatio` var (`portfolio_bonus`, `noc_monitor`, `manager_attendance_report`, `manager_operations`, `role_dashboard`), eyni riski daşıya bilər.
- `manager_fleet_map_screen.dart`: xəta lenti (səssiz udulmur), boş siyahı mətni, "Günün marşrutu"/"Başqa gün", gün xətti, gəliş/bitiş bayraqları, dayanacaq nişanları.
- `background_location_service.dart`: GPS 403 (razılıq/rol) olanda foreground bildirişdə səbəb yazılır.
- `api_endpoints.dart`: `managerRouteHistory`.

## Serverdə əllə dəyişdirilmiş fayllar (BİRLƏŞDİRMƏ RİSKİ)

Server `/var/www/superopticnet` git deposu deyil. 2026-10-21 tarixli `.bak-*` və `.before-*` nüsxələrindən məlum olanlar:
- `billing-cash-payment.php` (`.bak-urgent-20261021133616`)
- `billing-customer-profile.php` (`.bak-pppoe-20261021160528`)
- `billing-ui.php` (`.bak-urgent-20261021133616`, `.bak-pppoe-20261021160528`)
- `config.php` (`.before-telegram-disable-20261021`)

PR #5 bunlardan `billing-ui.php`, `billing-customer-profile.php`, `config.php` və `billing-cash-payment.php`-ə toxunur.
Bunları serverin üzərinə KOPYALAMAYIN. Əvvəl serverdəki faylı `agent/ai-crew-bridge` versiyası ilə `diff` edin, sonra yalnız PR-ın konkret dəyişikliyini (aşağıda) əl ilə tətbiq edin:
- `config.php`: yalnız `app_csrf_field()` blokunu əlavə edin (əgər serverdə yoxdursa).
- Digər 4 fayl: yalnız `</body>`-dən əvvəl widget sətri; `billing-customer-profile.php`-də yuxarıdakı kart dəyişiklikləri.

Qalan fayllar üçün də serverin cari faylını `agent/ai-crew-bridge` ilə müqayisə edin, çünki server bu branch-dan sonra da dəyişdirilmiş ola bilər.

## Təhlükəsizlik: açıq ehtiyat fayllar (təcili)

Aşağıdakı fayllar saytın kök qovluğunda dayanır və internetdən 200 qaytarır (məzmunu oxunmayıb):
- `config.php.before-telegram-disable-20261021`
- `billing-cash-payment.php.bak-urgent-20261021133616`

Digər `.bak-*` faylları yoxlanılmayıb. Tövsiyə: bütün `*.bak-*`, `*.before-*`, `*.orig` fayllarını `/root` altına köçürmək (silmədən), `.htaccess`-də bu şablonları bloklamaq, və o `config.php` nüsxəsində gizli məlumat varsa onları dəyişmək.
Bu dəyişiklik hələ edilməyib.

## Edilməyib / yoxlanılmayıb

- Heç bir fayl serverə yüklənməyib, serverdə heç bir əmr icra olunmayıb (Proxmox və VM 201-ə bulud mühitindən çıxış yoxdur).
- NOC terminal səhifəsi icazə sistemi tərəfindən rədd edildi və yazılmadı. Serverdə `noc-devices.php`-də "Gözləyən cihaz əmri sorğuları" (başqa admin təsdiqi ilə write əmrləri) var. Terminal bu növbəyə qoşulmalıdır.
- Mobil: Flutter SDK olmadığı üçün `flutter analyze`/`build` işlədilməyib. Əvvəlcə `flutter analyze` işlədin.
- `/ops/` web tətbiqi: serverdə yalnız qismən yüklənib (`main.dart.js`, `manifest.json`, `assets/`, ikonlar 404) və `index.html`-də `<base href="/">` var. Düzəliş: `flutter build web --release --base-href /ops/` və `build/web`-in hamısını `/ops/`-a yükləmək.
- Xəritədə əməkdaşların görünməməsinin səbəbi təsdiq edilməyib. Server tərəfi məntiqlidir: check-in iş sessiyası və ilk nöqtəni yazır, `fleet-live.php` son nöqtəni göstərir. Mümkün səbəblər: GPS razılığı (403), `mobile_duty_sessions` ilə uyğunsuzluq (409), menecerin ərazisi təyin olunmayıb.
- SN/MAC-ı müştəriyə bağlamaq mobil `modem-link.php` ilə edilir (inventar təyinatı + ONT profili). Web NOC üçün yarımçıq bağlama formasını yazıb sonra sildim.
- `subsequent-module-shell-smoke` serverin versiyasında da uğursuzdur (mesaj qrupu etiketləri), PR-dan asılı deyil.

## Təhlükəsiz yeniləmə sırası (təklif)

1. Proxmox snapshot (RAM-sız) və `/var/www/superopticnet` üçün tar ehtiyatı.
2. Açıq ehtiyat faylları kök qovluqdan çıxarmaq.
3. Serverin kodunu `agent/ai-crew-bridge` ilə `diff -rq` etmək, fərqləri GitHub-a ayrıca branch kimi göndərmək.
4. PR #5-i həmin yeni baza üzərinə birləşdirmək, `php -l` ilə bütün dəyişən faylları yoxlamaq.
5. Fayllar bir-bir (əvvəl az riskli: `copilot-widget.php`, CSS/JS), hər addımdan sonra giriş və əsas səhifələr yoxlanır.
6. Apache-ni yenidən başlatmaq lazım deyil (PHP faylları). Kernel yeniləməsi üçün planlı yenidən başlatma ayrıca.
7. Billinq/SMS/RADIUS/ödəniş axınlarına toxunan fayllar (`billing-cash-payment.php`, `billing-ui.php`, `config.php`) ən sonda və yalnız iş saatından kənar.
