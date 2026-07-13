<?php
declare(strict_types=1);

require_once __DIR__ . '/ai-module-lib.php';

$aiSection = app_ai_section(['ai_section' => (string) ($aiSection ?? 'overview')]);
$user = billing_require_employee(false, 'ai');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    exit('AI melumat bazasi aktiv deyil.');
}
$aiContext = app_ai_build_context($db, $user, $aiSection, $_GET);
$lang = (string) ($aiContext['lang'] ?? 'az');
$title = (string) (($aiContext['sections'] ?? [])[$aiSection] ?? 'AI');
?>
<!doctype html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title); ?> - AI</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
<?php billing_render_header('AI', 'ai-' . $aiSection, $lang); ?>
<main class="portal-layout"><?php require __DIR__ . '/views/ai/module.php'; ?></main>
<script src="<?= e(app_asset('script/portal.js')); ?>"></script>
<script>
(() => {
    const module = document.querySelector('[data-ai-module]');
    if (!module || !window.fetch) return;
    const sectionLabels = <?= json_encode(app_ai_sections(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const sectionRoutes = <?= json_encode(app_ai_routes(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const routeToSection = Object.entries(sectionRoutes).reduce((items, entry) => {
        items[String(entry[1]).toLowerCase()] = entry[0];
        return items;
    }, {});
    let controller = null;

    const sectionFromUrl = (url, override = '') => {
        if (override && sectionLabels[override]) return override;
        const target = new URL(url, window.location.href);
        const requested = target.searchParams.get('ai_section') || '';
        if (requested && sectionLabels[requested]) return requested;
        const route = target.pathname.split('/').pop().toLowerCase();
        return routeToSection[route] || module.dataset.aiSection || 'overview';
    };

    const updateShell = section => {
        if (!sectionLabels[section]) return;
        module.dataset.aiSection = section;
        const label = sectionLabels[section];
        const title = module.querySelector('[data-ai-title]');
        const kicker = module.querySelector('[data-ai-kicker]');
        if (title) title.textContent = label;
        if (kicker) kicker.textContent = 'AI / ' + label;
        document.title = label + ' - AI';
        module.querySelectorAll('[data-ai-nav]').forEach(link => {
            const linkSection = sectionFromUrl(link.href, link.dataset.moduleSection || '');
            link.classList.toggle('is-active', linkSection === section);
        });
        module.querySelectorAll('[data-module-nav-group]').forEach(group => {
            const active = !!group.querySelector('[data-ai-nav].is-active');
            group.classList.toggle('is-active-group', active);
            if (active) group.open = true;
        });
    };

    const load = async (url, push = true, sectionOverride = '') => {
        if (controller) controller.abort();
        controller = new AbortController();
        module.classList.add('is-loading');
        const target = new URL(url, window.location.href);
        const targetSection = sectionFromUrl(target, sectionOverride);
        const request = new URL('ai-module-partial.php', window.location.href);
        target.searchParams.forEach((value, key) => request.searchParams.set(key, value));
        request.searchParams.set('ai_section', targetSection);
        request.searchParams.set('ai_fragment', 'content');
        try {
            const response = await fetch(request, {headers: {'X-Requested-With': 'fetch'}, signal: controller.signal});
            if (!response.ok) throw new Error('AI partial request failed');
            const content = module.querySelector('[data-ai-content]');
            if (content) content.innerHTML = await response.text();
            updateShell(targetSection);
            if (push) history.pushState({ai: true}, '', target);
        } catch (error) {
            if (error.name !== 'AbortError') {
                const status = module.querySelector('[data-ai-status]');
                if (status) status.textContent = 'Melumat yenilenmedi. Yeniden cehd edin.';
            }
        } finally {
            module.classList.remove('is-loading');
        }
    };
    module.addEventListener('submit', async event => {
        const form = event.target.closest('[data-ai-filter]');
        if (!form) return;
        event.preventDefault();
        const url = new URL(form.action || window.location.href, window.location.href);
        new FormData(form).forEach((value, key) => url.searchParams.set(key, value));
        url.searchParams.delete('page');
        load(url);
    });
    module.addEventListener('click', event => {
        const nav = event.target.closest('[data-ai-nav]');
        if (nav) {
            event.preventDefault();
            load(nav.href, true, nav.dataset.moduleSection || '');
            return;
        }
        const link = event.target.closest('[data-ai-page]');
        if (!link) return;
        event.preventDefault();
        load(link.href);
    });
    module.addEventListener('submit', async event => {
        const form = event.target.closest('[data-ai-copilot-form]');
        if (!form) return;
        event.preventDefault();
        const output = module.querySelector('[data-ai-copilot-output]');
        const payload = Object.fromEntries(new FormData(form).entries());
        if (output) output.textContent = 'Superpilot analiz edir...';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-Requested-With': 'fetch'},
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.text || result.reason || 'Sorqu ugursuzdur');
            if (output) output.textContent = result.text || 'Cavab yoxdur.';
        } catch (error) {
            if (output) output.textContent = error.message || 'Superpilot cavab vermedi.';
        }
    });
    window.addEventListener('popstate', () => load(window.location.href, false));
})();
</script>
</body>
</html>
