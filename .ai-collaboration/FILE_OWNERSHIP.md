# Fayl sahibliyi - SAR-5 shell ve dil standarti

## Codex

- `ui-language-lib.php`
- `operations-center.php`
- `billing-section-page.php`
- `tools/ui-language-shell-smoke.php`
- yeni ve movcud shell/vizual smoke testleri
- `.ai-collaboration/**` contract ve yekun hesabat fayllari

## Claude

- `views/_module-group-nav.php`
- `views/finance/module.php`
- `views/hr/module.php`
- `views/inventory/module.php`
- `views/billing/module.php`
- yeni `css/operations-modules.css`
- netice qeydi ucun `.ai-collaboration/CLAUDE_FRONTEND_RESULT.md`

Claude bu siyahidan kenar source fayli deyismemelidir.

## Deyisdirilmez contract saheleri

- form `action`, `name`, `value` ve hidden input-lar
- URL/query parametrleri ve `data-*` atributlari
- auth, rol, permission, CSRF, DB ve API kodu
- AJAX partial endpointleri ve movcud event wiring

## Runtime ve mexfi saheler

- `config.local.php`
- `.env*`
- `tmp/**`
- `.tools/**`
- `whatsapp-bridge/.wwebjs_auth/**`
- `whatsapp-bridge/.wwebjs_cache/**`
