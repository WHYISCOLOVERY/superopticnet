(() => {
    const module = document.querySelector('[data-statistics-module]');
    if (!module || !window.fetch) return;

    const form = module.querySelector('[data-statistics-filter]');
    const status = module.querySelector('[data-statistics-status]');
    let controller = null;

    const currentResults = () => module.querySelector('[data-statistics-results]');

    const load = (push) => {
        if (!form) return;
        if (controller) controller.abort();
        controller = new AbortController();
        const activeController = controller;

        const canonical = new URL(form.action || window.location.href, window.location.href);
        new FormData(form).forEach((value, key) => canonical.searchParams.set(key, String(value)));

        const request = new URL('statistics-center-partial.php', window.location.href);
        canonical.searchParams.forEach((value, key) => request.searchParams.set(key, value));
        request.searchParams.set('statistics_fragment', 'results');

        module.classList.add('is-loading');
        if (status) status.textContent = 'Yenilenir...';

        fetch(request, {headers: {'X-Requested-With': 'fetch'}, signal: activeController.signal})
            .then(response => {
                if (!response.ok) throw new Error('statistics_partial_failed');
                return response.text();
            })
            .then(html => {
                const template = document.createElement('template');
                template.innerHTML = html;
                const replacement = template.content.querySelector('[data-statistics-results]');
                const target = currentResults();
                if (!replacement || !target) throw new Error('statistics_results_missing');
                target.replaceWith(replacement);
                if (status) status.textContent = 'Melumat yenilendi.';
                if (push) history.replaceState({statistics: true}, '', canonical);
            })
            .catch(error => {
                if (error.name === 'AbortError') return;
                if (status) status.textContent = 'Melumat yenilenmedi. Yeniden cehd edin.';
            })
            .finally(() => {
                if (controller === activeController) module.classList.remove('is-loading');
            });
    };

    if (form) {
        form.addEventListener('submit', event => {
            event.preventDefault();
            load(true);
        });

        form.addEventListener('change', event => {
            if (event.target.closest('[data-statistics-filter-control]')) {
                load(true);
            }
        });

        form.querySelectorAll('[data-statistics-date]').forEach(dateInput => {
            dateInput.addEventListener('change', () => {
                const rangeSelect = form.querySelector('[data-statistics-range]');
                if (rangeSelect && rangeSelect.value !== 'custom') {
                    rangeSelect.value = 'custom';
                }
                load(true);
            });
        });
    }

    window.addEventListener('popstate', () => load(false));
})();
