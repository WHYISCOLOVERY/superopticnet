# Fayl sahibliyi - Cross-module defect duzelisi (2026-08-02 round)

Bu sened evvelki WhatsApp-only sahiblik siyahisini legv etmir, bu round ucun genisləndirir. Evvelki siyahi asagida "Evvelki round sahibliyi" bolmesinde qorunur.

## Claude Code (bu round)

Implementasiya lideri kimi asagidaki sahelerde oxuma+yazma icazesi var:

- Billing: `billing-*.php`, `views/billing/**`
- Maliyye: `finance-*.php`, `views/finance/**`
- HR: `hr-*.php`, `views/hr/**`
- Anbar: `inventory-*.php`, `views/inventory/**`
- Sebeke/NOC: `noc-*.php`, `operations-center.php`, `mgs-integration*.php`, `router-registry-lib.php`, `radius-*.php`, `network-definitions.php`, `noc-authorization-lib.php`
- Mesaj Merkezi/SMS/Telegram/WhatsApp: `message-center.php`, `whatsapp-monitor*.php`, `communication-*.php`, `views/whatsapp-monitor/**`, `form-telegram.php`, `script/message-center.js`, `script/whatsapp-monitor.js`, `css/message-center.css`, `css/whatsapp-monitor.css`
- Statistika: `statistics-center*.php`, `views/statistics/**`, `problem-statistics.php`
- CRM: `crm.php`
- Rol/admin: `admin-users.php`, `admin-positions.php`, `role-agent-lib.php`, `operations-permission-lib.php`, `feature-flags-lib.php`
- Portal shell: `portal.php`, `portal-actions.php`, `portal-login.php`, `index.php`, `config.php`, `page-helpers.php`, `views/_module-group-nav.php`, `css/portal.css`, `script/portal.js`
- Elaqeli `api/**` endpoint-leri ve service/lib fayllari, yalniz movcud is axinini duzeltmek ucun
- `db/schema.sql` ve `db/migrations/**`: YALNIZ elave (additive), versiyali, rollback-li migration yolu ile
- Elaqeli `tools/*-smoke.php` test fayllari

## Qadağan olunan sahe (bu roundda da)

- Production secrets, `.env*`, `config.local.php`, real provider credential
- Real SMS/Telegram/WhatsApp gonderisi ve ya canli RADIUS/OLT/router emeliyyati tetikleyen kod yollarinin production-da icrasi
- Destruktiv/geri qaytarilmaz DB emeliyyati (DROP/TRUNCATE/data-loss migration)

## Codex

- Implementasiyadan sonra review, regression ve production tehlukesizlik tesdiqi
- `.ai-collaboration/**` yekun hesabat fayllarinin bu rounddan evvelki tarixi qeydleri

## Evvelki round sahibliyi (COMPLETE, qorunub, bu roundda evez olunub)

WhatsApp round-da Claude yalniz bu fayllara malik idi: `views/whatsapp-monitor/chats.php`, `views/whatsapp-monitor/screen-wall.php`, `css/whatsapp-monitor.css`, netice qeydi ucun `.ai-collaboration/CLAUDE_FRONTEND_RESULT.md`. Qalan her sey (`communication-provider.php`, `message-center.php`, `whatsapp-monitor-lib.php`, API-ler, JS, `whatsapp-bridge/**`) Codex-e aid idi. Bu dar serhed bu round ucun yuxaridaki genis sahiblik ile evez olunub.

## Deyisdirilmez contract saheleri (butun roundlarda)

- form `action`, `name`, `value` ve hidden input-lar movcud is axinlarinda sebebsiz deyisdirilmir
- auth, rol, permission, CSRF, DB ve audit qaydalari zeiflədilmir
- message ingest ve pairing worker token contracti
- `number_id`, `chat_id`, `after_id` query adlari

## Runtime ve mexfi saheler

- `config.local.php`
- `.env*`
- `tmp/**`
- `whatsapp-bridge/.wwebjs_auth/**`
- `whatsapp-bridge/.wwebjs_cache/**`
