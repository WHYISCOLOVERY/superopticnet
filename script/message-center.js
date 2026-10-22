(() => {
    const shell = document.querySelector('[data-message-shell]');
    if (!shell || !window.fetch || !window.DOMParser) return;
    let controller = null;
    let filterTimer = null;

    const initializeContent = () => {
        shell.querySelectorAll('[data-ticket-message]').forEach(field => {
            if (field.dataset.messageCounterReady === '1') return;
            const counter = field.parentElement?.querySelector('[data-char-counter]');
            if (!counter) return;
            const updateCounter = () => {
                counter.textContent = `${field.value.length} / ${field.maxLength > 0 ? field.maxLength : 640}`;
            };
            field.dataset.messageCounterReady = '1';
            field.addEventListener('input', updateCounter);
            updateCounter();
        });
    };
    const viewFromUrl = url => {
        const target = new URL(url, window.location.href);
        return target.searchParams.get('view') || 'overview';
    };
    const updateShell = (url, html) => {
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const nextShell = parsed.querySelector('[data-message-shell]');
        const nextContent = parsed.querySelector('[data-message-content]');
        const currentContent = shell.querySelector('[data-message-content]');
        if (!nextContent || !currentContent) throw new Error('Message partial content not found');
        currentContent.innerHTML = nextContent.innerHTML;
        const view = nextShell?.dataset.messageView || viewFromUrl(url);
        shell.dataset.messageView = view;
        shell.querySelectorAll('[data-message-nav]').forEach(link => {
            const linkView = link.dataset.moduleSection || viewFromUrl(link.href);
            link.classList.toggle('is-active', linkView === view);
        });
        shell.querySelectorAll('[data-module-nav-group]').forEach(group => {
            const active = !!group.querySelector('[data-message-nav].is-active');
            group.classList.toggle('is-active-group', active);
            if (active) group.open = true;
        });
        const status = shell.querySelector('[data-message-status]');
        if (status) status.textContent = '';
        initializeContent();
        if (url.hash) {
            requestAnimationFrame(() => {
                const target = shell.querySelector(url.hash);
                if (target) target.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        }
        return view;
    };
    const loadMessage = async (url, push = true) => {
        if (controller) controller.abort();
        controller = new AbortController();
        shell.classList.add('is-loading');
        shell.setAttribute('aria-busy', 'true');
        const target = new URL(url, window.location.href);
        try {
            const response = await fetch(target, {headers: {'X-Requested-With': 'fetch'}, signal: controller.signal});
            if (!response.ok) throw new Error('Mesaj merkezi yenilenmedi');
            const resolvedView = updateShell(target, await response.text());
            if (push) {
                target.searchParams.set('view', resolvedView);
                history.pushState({messageCenter: true}, '', target);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                const status = shell.querySelector('[data-message-status]');
                if (status) status.textContent = error.message || 'Mesaj merkezi yenilenmedi.';
            }
        } finally {
            shell.classList.remove('is-loading');
            shell.removeAttribute('aria-busy');
        }
    };
    shell.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || link.classList.contains('is-disabled')) return;
        const target = new URL(link.href, window.location.href);
        if (!link.matches('[data-message-nav]') && target.pathname.split('/').pop() !== 'message-center.php') return;
        event.preventDefault();
        loadMessage(target);
    });
    shell.addEventListener('submit', event => {
        const form = event.target.closest('form');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        const target = new URL(form.action || window.location.href, window.location.href);
        new FormData(form).forEach((value, key) => target.searchParams.set(key, value));
        loadMessage(target);
    });
    shell.addEventListener('input', event => {
        const field = event.target.closest('[data-message-auto-filter] input[type="text"], [data-message-auto-filter] input[type="search"]');
        if (!field) return;
        const form = field.closest('form');
        const delay = Number(form?.dataset.messageFilterDelay || 350);
        window.clearTimeout(filterTimer);
        filterTimer = window.setTimeout(() => form?.requestSubmit(), Math.max(150, delay));
    });
    shell.addEventListener('change', event => {
        const field = event.target.closest('[data-message-auto-filter] select');
        field?.closest('form')?.requestSubmit();
    });
    shell.addEventListener('click', event => {
        const result = event.target.closest('[data-message-customer-code]');
        if (!result) return;
        const subscriberInput = shell.querySelector('#singleSubscriber');
        if (!subscriberInput) return;
        subscriberInput.value = result.dataset.messageCustomerCode || '';
        subscriberInput.focus();
    });
    window.addEventListener('popstate', () => loadMessage(window.location.href, false));
    initializeContent();
})();


(() => {
    const bind = (root) => {
        (root || document).querySelectorAll('[data-template-select]').forEach((select) => {
            if (select.dataset.previewBound === '1') return;
            select.dataset.previewBound = '1';
            const preview = select.parentElement?.querySelector('[data-template-preview]');
            const text = preview?.querySelector('p');
            if (!preview || !text) return;
            const sync = () => {
                const body = select.selectedOptions[0]?.dataset.templateBody || '';
                text.textContent = body;
                preview.hidden = body === '';
            };
            select.addEventListener('change', sync);
            sync();
        });
    };
    bind(document);
    new MutationObserver(() => bind(document)).observe(document.body, {childList: true, subtree: true});
})();
