<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-module-lib.php';

$billingSection = app_billing_section(['billing_section' => (string) ($billingSection ?? 'overview')]);
$lang = app_lang();
$user = billing_require_employee(false, 'billing');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    exit('Billing melumat bazasi aktiv deyil.');
}
$billingContext = app_billing_build_context($db, $user, $billingSection, $_GET);
$billingTitle = (string) (($billingContext['sections'] ?? [])[$billingSection] ?? 'Billing');
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($billingTitle); ?> - Billing</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
<?php billing_render_header($billingTitle, $billingSection, $lang); ?>
<main class="portal-layout"><?php require __DIR__ . '/views/billing/module.php'; ?></main>
<script src="<?= e(app_asset('script/portal.js')); ?>"></script>
<script>
(() => {
    const module = document.querySelector('[data-billing-module]');
    if (!module || !window.fetch) return;
    let controller = null;
    let filterTimer = null;
    const buildFormUrl = form => {
        const url = new URL(form.action || window.location.href, window.location.href);
        new FormData(form).forEach((value, key) => url.searchParams.set(key, value));
        return url;
    };
    const load = async (url, push = true) => {
        if (controller) controller.abort();
        controller = new AbortController();
        module.classList.add('is-loading');
        const target = new URL(url, window.location.href);
        const request = new URL('billing-module-partial.php', window.location.href);
        target.searchParams.forEach((value, key) => request.searchParams.set(key, value));
        request.searchParams.set('billing_section', module.dataset.billingSection || 'overview');
        request.searchParams.set('billing_fragment', 'content');
        try {
            const response = await fetch(request, {headers: {'X-Requested-With': 'fetch'}, signal: controller.signal});
            if (!response.ok) throw new Error('Billing partial request failed');
            const content = module.querySelector('[data-billing-content]');
            if (content) content.innerHTML = await response.text();
            if (push === 'replace') history.replaceState({billing: true}, '', target);
            else if (push) history.pushState({billing: true}, '', target);
        } catch (error) {
            if (error.name !== 'AbortError') window.location.assign(target);
        } finally {
            module.classList.remove('is-loading');
        }
    };
    const loadStatistics = async (form, push = true) => {
        const target = buildFormUrl(form);
        const request = new URL('billing-module-partial.php', window.location.href);
        target.searchParams.forEach((value, key) => request.searchParams.set(key, value));
        request.searchParams.set('billing_section', 'statistics');
        request.searchParams.set('billing_fragment', 'statistics-results');
        const status = form.closest('[data-billing-statistics]')?.querySelector('[data-billing-statistics-status]');
        const current = module.querySelector('[data-billing-statistics-results]');
        if (!current) {
            load(target, push);
            return;
        }
        if (controller) controller.abort();
        controller = new AbortController();
        current.classList.add('is-loading');
        if (status) status.textContent = 'Chart yenilenir...';
        try {
            const response = await fetch(request, {headers: {'X-Requested-With': 'fetch'}, signal: controller.signal});
            if (!response.ok) throw new Error('Billing statistics request failed');
            current.outerHTML = await response.text();
            if (status) status.textContent = 'Chart yenilendi.';
            if (push === 'replace') history.replaceState({billing: true, billingStatistics: true}, '', target);
            else if (push) history.pushState({billing: true, billingStatistics: true}, '', target);
        } catch (error) {
            if (error.name !== 'AbortError') window.location.assign(target);
        }
    };
    module.addEventListener('submit', event => {
        const form = event.target.closest('[data-billing-filter], [data-billing-statistics-form]');
        if (!form) return;
        event.preventDefault();
        const historyMode = form.dataset.filterHistory === 'replace' ? 'replace' : true;
        delete form.dataset.filterHistory;
        if (form.matches('[data-billing-statistics-form]')) {
            loadStatistics(form, historyMode);
            return;
        }
        load(buildFormUrl(form), historyMode);
    });
    const scheduleFilter = (field, delay) => {
        const form = field.closest('[data-billing-filter], [data-billing-statistics-form]');
        if (!form) return;
        window.clearTimeout(filterTimer);
        filterTimer = window.setTimeout(() => {
            form.dataset.filterHistory = 'replace';
            form.requestSubmit();
        }, delay);
    };
    module.addEventListener('input', event => {
        const field = event.target.closest('[data-billing-filter] input, [data-billing-filter] textarea, [data-billing-statistics-form] input');
        if (!field || ['hidden', 'submit', 'button', 'reset'].includes(field.type)) return;
        scheduleFilter(field, field.type === 'search' || field.type === 'text' ? 320 : 180);
    });
    module.addEventListener('change', event => {
        const field = event.target.closest('[data-billing-filter] select, [data-billing-filter] input, [data-billing-statistics-form] select, [data-billing-statistics-form] input');
        if (!field) return;
        scheduleFilter(field, 0);
    });
    module.addEventListener('click', event => {
        const link = event.target.closest('[data-billing-page]');
        if (!link) return;
        event.preventDefault();
        load(link.href);
    });
    window.addEventListener('popstate', () => load(window.location.href, false));
})();
</script>
</body>
</html>
