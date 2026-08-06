(() => {
    const current = document.currentScript;
    if (!current || document.querySelector('script[data-superoptic-design-system]')) {
        return;
    }
    const source = new URL(current.src, window.location.href);
    const runtime = document.createElement('script');
    runtime.src = new URL('design-system.js', source).toString();
    runtime.dataset.superopticDesignSystem = '';
    runtime.defer = true;
    document.head.appendChild(runtime);
})();

window.superopticInitModuleGroupNav = (root = document) => {
    const navs = [];
    if (root instanceof Element && root.matches('[data-module-group-nav]')) {
        navs.push(root);
    }
    root.querySelectorAll?.('[data-module-group-nav]').forEach((nav) => navs.push(nav));

    navs.forEach((nav) => {
        if (nav.dataset.moduleGroupNavReady === '1') {
            return;
        }
        nav.dataset.moduleGroupNavReady = '1';

        const groups = () => Array.from(nav.querySelectorAll('details.billing-nav-group'));
        const closeAll = (except = null) => {
            groups().forEach((group) => {
                if (group !== except) {
                    group.open = false;
                }
            });
        };
        const syncPopover = () => {
            const open = groups().find((group) => group.open);
            nav.classList.toggle('has-open', Boolean(open));
            if (!open || window.matchMedia('(max-width: 560px)').matches) {
                return;
            }

            const title = open.querySelector('.billing-nav-title');
            if (!title) {
                return;
            }
            const rect = title.getBoundingClientRect();
            const width = Math.max(240, Math.min(380, Math.round(rect.width)));
            const left = Math.max(12, Math.min(window.innerWidth - width - 12, Math.round(rect.left)));
            const top = Math.max(12, Math.min(window.innerHeight - 80, Math.round(rect.bottom + 8)));
            nav.style.setProperty('--billing-nav-popover-top', `${top}px`);
            nav.style.setProperty('--billing-nav-popover-left', `${left}px`);
            nav.style.setProperty('--billing-nav-popover-width', `${width}px`);
        };

        groups().forEach((group) => {
            group.addEventListener('toggle', () => {
                if (group.open) {
                    closeAll(group);
                }
                window.requestAnimationFrame(syncPopover);
            });
        });

        if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            let leaveTimer = 0;
            groups().forEach((group) => {
                group.addEventListener('mouseenter', () => {
                    closeAll(group);
                    group.open = true;
                });
            });
            nav.addEventListener('mouseleave', () => {
                leaveTimer = window.setTimeout(() => closeAll(), 150);
            });
            nav.addEventListener('mouseenter', () => {
                window.clearTimeout(leaveTimer);
                leaveTimer = 0;
            });
        }

        document.addEventListener('click', (event) => {
            if (!nav.contains(event.target)) {
                closeAll();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAll();
            }
        });
        window.addEventListener('resize', syncPopover, {passive: true});
        window.addEventListener('scroll', syncPopover, {passive: true});
        syncPopover();
    });
};

document.addEventListener('DOMContentLoaded', () => {
    const topbar = document.querySelector('.portal-topbar');
    const roleButtons = document.querySelectorAll('[data-role-switch]');
    const roleInput = document.querySelector('[data-role-input]');
    const roleLabel = document.querySelector('[data-role-label]');
    const roleLoginInput = document.querySelector('[data-role-login-input]');
    const mygovLoginLink = document.querySelector('[data-mygov-login-link]');
    const revealTargets = document.querySelectorAll('.portal-reveal, .portal-metric-card, .portal-card');
    const ticketMessages = document.querySelectorAll('[data-ticket-message]');
    const crmLookupRoot = document.querySelector('[data-crm-customer-lookup]');
    const alerts = document.querySelectorAll('[data-autohide-alert]');
    const customerProfileNav = document.querySelector('.customer-profile-nav');
    const customerProfileSections = document.querySelectorAll('.mgs-profile-main .customer-profile-section');
    const customerProfileSectionGroups = document.querySelectorAll('.mgs-profile-main .customer-profile-section-group');
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const syncMobileTables = () => {
        document.querySelectorAll('.portal-table').forEach((table) => {
            const headers = Array.from(table.querySelectorAll('thead th')).map((header) => header.textContent.trim());
            table.querySelectorAll('tbody tr').forEach((row) => {
                Array.from(row.children).forEach((cell, index) => {
                    if (!cell.dataset.label && headers[index]) {
                        cell.dataset.label = headers[index];
                    }
                });
            });
        });
    };
    syncMobileTables();
    const tableObserver = new MutationObserver(() => requestAnimationFrame(syncMobileTables));
    tableObserver.observe(document.body, {childList: true, subtree: true});
    document.addEventListener('superoptic:table-refresh', syncMobileTables);

    function syncTopbarState() {
        topbar?.classList.toggle('is-scrolled', window.scrollY > 12);
    }

    function applyRole(role) {
        roleButtons.forEach((button) => {
            button.classList.toggle('is-active', button.dataset.roleSwitch === role);
        });

        if (roleInput) {
            roleInput.value = role;
        }

        if (roleLabel && roleLoginInput) {
            roleLabel.textContent = role === 'employee'
                ? (roleLoginInput.dataset.employeeLabel || 'Employee')
                : (roleLoginInput.dataset.customerLabel || 'Customer');

            roleLoginInput.placeholder = role === 'employee'
                ? (roleLoginInput.dataset.employeePlaceholder || '')
                : (roleLoginInput.dataset.customerPlaceholder || '');
        }

        if (mygovLoginLink) {
            const url = new URL(mygovLoginLink.dataset.baseHref || mygovLoginLink.getAttribute('href') || 'mygov-auth.php', window.location.href);
            url.searchParams.set('role', role);
            const lang = mygovLoginLink.dataset.lang || '';
            if (lang && lang !== 'az') {
                url.searchParams.set('lang', lang);
            }
            mygovLoginLink.setAttribute('href', `${url.pathname.split('/').pop()}${url.search}`);
        }
    }

    syncTopbarState();
    window.addEventListener('scroll', syncTopbarState, { passive: true });

    window.superopticInitModuleGroupNav(document);

    if (customerProfileNav && customerProfileSections.length > 0) {
        const navLinks = Array.from(customerProfileNav.querySelectorAll('a[href^="#profile-"]'));
        const profileActionLinks = Array.from(document.querySelectorAll('a[href^="#profile-"]'));
        const profileSectionAliases = {
            'profile-overview': 'profile-main',
            'profile-summary': 'profile-main',
            'profile-ont': 'profile-modems',
            'profile-optic': 'profile-modems',
            'profile-optical': 'profile-modems',
            'profile-radius-log': 'profile-radius',
        };
        const resolveProfileSectionId = (rawTargetId) => {
            const targetId = (rawTargetId || '').replace(/^#/, '');
            if (!targetId) {
                return '';
            }

            if (document.getElementById(targetId)?.classList.contains('customer-profile-section')) {
                return targetId;
            }

            const alias = profileSectionAliases[targetId] || '';
            if (alias && document.getElementById(alias)?.classList.contains('customer-profile-section')) {
                return alias;
            }

            return '';
        };
        const syncProfileSectionGroups = () => {
            customerProfileSectionGroups.forEach((group) => {
                const hasVisibleSection = Array.from(group.querySelectorAll('.customer-profile-section'))
                    .some((section) => !section.hidden);
                group.hidden = !hasVisibleSection;
            });
        };

        const activateProfileSection = (rawTargetId, updateHash = true) => {
            const targetId = resolveProfileSectionId(rawTargetId);
            const target = targetId ? document.getElementById(targetId) : null;
            if (!target || !target.classList.contains('customer-profile-section')) {
                return;
            }

            customerProfileSections.forEach((section) => {
                section.hidden = section.id !== targetId;
            });
            navLinks.forEach((link) => {
                link.classList.toggle('is-active', link.getAttribute('href') === `#${targetId}`);
            });
            syncProfileSectionGroups();
            target.classList.add('is-visible');
            target.closest('.portal-reveal')?.classList.add('is-visible');

            if (updateHash) {
                window.history.replaceState({}, document.title, `#${targetId}`);
                target.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth', block: 'start' });
            }
        };

        navLinks.forEach((link) => {
            link.addEventListener('click', (event) => {
                const targetId = resolveProfileSectionId(link.getAttribute('href') || '');
                if (!targetId) {
                    return;
                }
                event.preventDefault();
                activateProfileSection(targetId);
            });
        });

        profileActionLinks.forEach((link) => {
            if (navLinks.includes(link)) {
                return;
            }

            link.addEventListener('click', (event) => {
                const targetId = resolveProfileSectionId(link.getAttribute('href') || '');
                if (!targetId) {
                    return;
                }

                event.preventDefault();
                activateProfileSection(targetId);
            });
        });

        window.addEventListener('hashchange', () => {
            const targetId = resolveProfileSectionId(window.location.hash);
            if (targetId) {
                activateProfileSection(targetId, false);
            }
        });

        const initialTarget = resolveProfileSectionId(window.location.hash) || 'profile-main';
        activateProfileSection(initialTarget, false);
    }

    const openDetailsFromHash = () => {
        const hash = window.location.hash.replace('#', '');
        if (!hash) {
            return;
        }

        const target = document.getElementById(hash);
        if (target instanceof HTMLDetailsElement) {
            target.open = true;
            target.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth', block: 'start' });
        }
    };

    window.addEventListener('hashchange', openDetailsFromHash);
    openDetailsFromHash();

    roleButtons.forEach((button) => {
        button.addEventListener('click', () => applyRole(button.dataset.roleSwitch));
    });

    if (crmLookupRoot) {
        const codeInput = document.querySelector('[data-customer-code-input]');
        const nameInput = document.querySelector('[data-customer-name-input]');
        const phoneInput = document.querySelector('[data-customer-phone-input]');
        const areaInput = document.querySelector('[data-customer-area-input]');
        const areaSelect = document.querySelector('[data-crm-area-select]');
        const addressInput = document.querySelector('[data-customer-address-input]');
        const customerAccountIdInput = document.querySelector('[data-customer-account-id-input]');
        const customerOptions = document.getElementById('crmCustomerOptions');
        const previewCard = document.querySelector('[data-crm-customer-preview]');
        const previewTitle = document.querySelector('[data-crm-customer-preview-title]');
        const previewMeta = document.querySelector('[data-crm-customer-preview-meta]');
        const customerCache = new Map();
        let crmLookupAbortController = null;
        let crmLookupTimer = 0;
        let lastLookupResults = [];

        const normalizeDigits = (value) => String(value || '').replace(/\D+/g, '');

        const syncAreaSelect = (value) => {
            if (!areaSelect) {
                return;
            }

            const areaValue = String(value || '').trim();
            const hasExactOption = Array.from(areaSelect.options).some((option) => option.value === areaValue);
            areaSelect.value = hasExactOption ? areaValue : '';
        };

        const clearCustomerPreview = () => {
            if (customerAccountIdInput) {
                customerAccountIdInput.value = '';
            }
            if (previewTitle) {
                previewTitle.textContent = 'Musteri secilmeyib';
            }
            if (previewMeta) {
                previewMeta.textContent = 'Kod, ad ve ya telefon daxil edin. Tek netice olduqda melumatlar avtomatik dolacaq.';
            }
            if (previewCard) {
                previewCard.hidden = true;
            }
        };

        const renderCustomerPreview = (item) => {
            if (!item) {
                clearCustomerPreview();
                return;
            }

            if (previewTitle) {
                previewTitle.textContent = item.full_name || item.subscriber_code || 'Musteri';
            }
            if (previewMeta) {
                previewMeta.textContent = [
                    item.subscriber_code || '',
                    item.contract_no || '',
                    item.phone || '',
                    item.area || '',
                    item.plan || '',
                ].filter(Boolean).join(' • ');
            }
            if (previewCard) {
                previewCard.hidden = false;
            }
        };

        const renderCustomerOptions = (results) => {
            if (!customerOptions) {
                return;
            }

            customerOptions.innerHTML = '';
            customerCache.clear();
            lastLookupResults = Array.isArray(results) ? results.filter(Boolean) : [];

            lastLookupResults.forEach((item) => {
                if (!item || !item.subscriber_code) {
                    return;
                }

                const option = document.createElement('option');
                option.value = String(item.subscriber_code);
                option.label = [item.full_name || '', item.phone || '', item.area || ''].filter(Boolean).join(' • ');
                customerOptions.appendChild(option);
                customerCache.set(String(item.subscriber_code).toLowerCase(), item);
                if (item.contract_no) {
                    customerCache.set(String(item.contract_no).toLowerCase(), item);
                }
                if (item.login) {
                    customerCache.set(String(item.login).toLowerCase(), item);
                }
            });
        };

        const findCustomerMatch = (query) => {
            const normalizedQuery = String(query || '').trim();
            if (normalizedQuery === '') {
                return null;
            }

            const cacheHit = customerCache.get(normalizedQuery.toLowerCase());
            if (cacheHit) {
                return cacheHit;
            }

            const queryDigits = normalizeDigits(normalizedQuery);
            const loweredQuery = normalizedQuery.toLowerCase();
            const exactMatch = lastLookupResults.find((item) => {
                const candidate = item || {};
                return String(candidate.subscriber_code || '').toLowerCase() === loweredQuery
                    || String(candidate.contract_no || '').toLowerCase() === loweredQuery
                    || String(candidate.login || '').toLowerCase() === loweredQuery
                    || String(candidate.full_name || '').toLowerCase() === loweredQuery
                    || (queryDigits !== '' && normalizeDigits(candidate.phone || '') === queryDigits);
            });

            if (exactMatch) {
                return exactMatch;
            }

            return lastLookupResults.length === 1 ? lastLookupResults[0] : null;
        };

        const applyCustomerLookupResult = (candidate = null) => {
            const item = candidate || findCustomerMatch(codeInput?.value || '');
            if (!item) {
                clearCustomerPreview();
                return;
            }

            if (customerAccountIdInput) {
                customerAccountIdInput.value = String(item.id || '');
            }
            if (codeInput && item.subscriber_code) {
                codeInput.value = item.subscriber_code;
            }
            if (nameInput) {
                nameInput.value = item.full_name || '';
            }
            if (phoneInput) {
                phoneInput.value = item.phone || '';
            }
            if (areaInput) {
                areaInput.value = item.area || '';
            }
            syncAreaSelect(item.area || '');
            if (addressInput) {
                addressInput.value = item.address || '';
            }
            renderCustomerPreview(item);
        };

        const fetchCustomerLookup = (query) => {
            if (!codeInput || !customerOptions) {
                return;
            }
            if (crmLookupAbortController) {
                crmLookupAbortController.abort();
            }
            crmLookupAbortController = new AbortController();
            const url = new URL('api/customer-search.php', window.location.href);
            url.searchParams.set('q', query);
            fetch(url, {
                headers: {
                    Accept: 'application/json',
                },
                signal: crmLookupAbortController.signal,
                credentials: 'same-origin',
            })
                .then((response) => response.ok ? response.json() : Promise.reject(new Error(`lookup_${response.status}`)))
                .then((payload) => {
                    const results = Array.isArray(payload?.results) ? payload.results : [];
                    renderCustomerOptions(results);
                    applyCustomerLookupResult();
                })
                .catch((error) => {
                    if (error?.name === 'AbortError') {
                        return;
                    }
                    lastLookupResults = [];
                    renderCustomerOptions([]);
                    renderCustomerPreview(null);
                });
        };

        const preloadCustomers = document.querySelector('[data-crm-recent-customers]');
        if (preloadCustomers?.textContent) {
            try {
                const preloadedResults = JSON.parse(preloadCustomers.textContent);
                if (Array.isArray(preloadedResults) && preloadedResults.length > 0) {
                    renderCustomerOptions(preloadedResults);
                }
            } catch (error) {
                lastLookupResults = [];
            }
        }

        if (codeInput) {
            codeInput.addEventListener('input', () => {
                window.clearTimeout(crmLookupTimer);
                const query = codeInput.value.trim();
                if (query.length < 2) {
                    lastLookupResults = [];
                    renderCustomerOptions([]);
                    renderCustomerPreview(null);
                    return;
                }
                crmLookupTimer = window.setTimeout(() => fetchCustomerLookup(query), 220);
            });
            codeInput.addEventListener('change', () => applyCustomerLookupResult());
            codeInput.addEventListener('blur', () => applyCustomerLookupResult());
        }

        if (areaSelect && areaInput) {
            areaSelect.addEventListener('change', () => {
                if (areaSelect.value.trim() !== '') {
                    areaInput.value = areaSelect.value;
                }
            });
            areaInput.addEventListener('input', () => syncAreaSelect(areaInput.value));
        }

        clearCustomerPreview();
    }

    ticketMessages.forEach((field) => {
        const counter = field.parentElement?.querySelector('[data-char-counter]');
        const syncCounter = () => {
            const length = field.value.length;
            const maxLength = Number.parseInt(field.getAttribute('maxlength') || '160', 10) || 160;
            if (counter) {
                counter.textContent = `${length} / ${maxLength}`;
            }
        };

        field.addEventListener('input', syncCounter);
        syncCounter();
    });

    alerts.forEach((alert) => {
        window.setTimeout(() => {
            alert.style.transition = 'opacity 220ms ease, transform 220ms ease';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-8px)';
            window.setTimeout(() => alert.remove(), 240);
        }, 1800);
    });

    if (alerts.length > 0 && 'replaceState' in window.history) {
        const url = new URL(window.location.href);
        let changed = false;
        ['status', 'created', 'updated', 'errors'].forEach((key) => {
            if (url.searchParams.has(key)) {
                url.searchParams.delete(key);
                changed = true;
            }
        });

        if (changed) {
            window.history.replaceState({}, document.title, url.toString());
        }
    }

    let autoFilterRequest = null;
    let autoFilterRequestId = 0;
    let autoFilterTimer = 0;

    const cancelAutoFilterRequest = () => {
        autoFilterRequestId += 1;
        if (!autoFilterRequest) return;
        autoFilterRequest.abort();
        autoFilterRequest = null;
    };

    const autoFilterTargets = (form) => String(form.dataset.autoFilterTargets || '')
        .split(',')
        .map((key) => key.trim())
        .filter(Boolean);

    const setAutoFilterStatus = (form, message, isError = false) => {
        const status = form.querySelector('[data-auto-filter-status]');
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-error', isError);
    };

    const syncAutoFilterForm = (form, parsed) => {
        if (!form.id) return;
        const incoming = parsed.getElementById(form.id);
        if (!incoming) return;

        Array.from(form.elements).forEach((field) => {
            if (!field.name) return;
            const matches = Array.from(incoming.elements).filter((candidate) => candidate.name === field.name);
            if (matches.length === 0) return;

            if (field instanceof HTMLInputElement && ['checkbox', 'radio'].includes(field.type)) {
                const match = matches.find((candidate) => candidate.value === field.value);
                field.checked = Boolean(match?.checked);
                return;
            }
            if (field instanceof HTMLSelectElement && field.multiple) {
                const values = new Set(matches.flatMap((candidate) => Array.from(candidate.selectedOptions || []).map((option) => option.value)));
                Array.from(field.options).forEach((option) => {
                    option.selected = values.has(option.value);
                });
                return;
            }

            field.value = matches[0].value;
        });

        const currentQuickFilters = form.querySelector('.mgs-quick-filters');
        const incomingQuickFilters = incoming.querySelector('.mgs-quick-filters');
        if (currentQuickFilters && incomingQuickFilters) {
            currentQuickFilters.replaceWith(document.importNode(incomingQuickFilters, true));
        }
    };

    const loadAutoFilterResults = async (form, targetUrl = null, historyMode = 'replace') => {
        const keys = autoFilterTargets(form);
        if (keys.length === 0) return;

        const url = targetUrl instanceof URL
            ? targetUrl
            : new URL(form.action || window.location.href, window.location.href);
        if (!(targetUrl instanceof URL)) {
            new FormData(form).forEach((value, key) => url.searchParams.set(key, String(value)));
            url.searchParams.delete('page');
        }

        cancelAutoFilterRequest();
        const requestId = autoFilterRequestId;
        const requestController = new AbortController();
        autoFilterRequest = requestController;
        const isCurrentRequest = () => autoFilterRequestId === requestId
            && autoFilterRequest === requestController
            && !requestController.signal.aborted;
        const targets = keys
            .map((key) => document.querySelector(`[data-auto-filter-results="${CSS.escape(key)}"]`))
            .filter(Boolean);
        targets.forEach((target) => target.setAttribute('aria-busy', 'true'));
        setAutoFilterStatus(form, 'Nəticələr yenilənir...');

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                signal: requestController.signal,
            });
            if (!isCurrentRequest()) return;
            if (!response.ok) throw new Error(`auto_filter_${response.status}`);

            const responseText = await response.text();
            if (!isCurrentRequest()) return;
            const parsed = new DOMParser().parseFromString(responseText, 'text/html');
            if (!isCurrentRequest()) return;
            syncAutoFilterForm(form, parsed);
            keys.forEach((key) => {
                const current = document.querySelector(`[data-auto-filter-results="${CSS.escape(key)}"]`);
                const replacement = parsed.querySelector(`[data-auto-filter-results="${CSS.escape(key)}"]`);
                if (!current || !replacement) throw new Error(`auto_filter_target_${key}`);
                current.replaceWith(document.importNode(replacement, true));
            });

            const nextLocation = `${url.pathname}${url.search}${url.hash}`;
            if (historyMode === 'push') history.pushState({autoFilter: true}, '', nextLocation);
            else if (historyMode !== 'none') history.replaceState({autoFilter: true}, '', nextLocation);
            setAutoFilterStatus(form, 'Nəticələr yeniləndi.');
            syncMobileTables();
            document.dispatchEvent(new CustomEvent('superoptic:auto-filter-loaded', {detail: {url: url.href}}));
        } catch (error) {
            if (error?.name === 'AbortError' || !isCurrentRequest()) return;
            targets.forEach((target) => target.removeAttribute('aria-busy'));
            setAutoFilterStatus(form, 'Yeniləmə alınmadı. Bir daha cəhd edin.', true);
        } finally {
            if (autoFilterRequest === requestController) {
                autoFilterRequest = null;
            }
        }
    };

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-auto-filter]');
        if (!form) return;
        event.preventDefault();
        window.clearTimeout(autoFilterTimer);
        autoFilterTimer = 0;
        loadAutoFilterResults(form, null, 'push');
    });

    document.addEventListener('input', (event) => {
        const field = event.target.closest('form[data-auto-filter] input[type="text"],form[data-auto-filter] input[type="search"],form[data-auto-filter] textarea');
        if (!field) return;
        const form = field.closest('form[data-auto-filter]');
        window.clearTimeout(autoFilterTimer);
        cancelAutoFilterRequest();
        autoFilterTimer = window.setTimeout(() => loadAutoFilterResults(form), 350);
    });

    document.addEventListener('change', (event) => {
        const field = event.target.closest('form[data-auto-filter] select,form[data-auto-filter] input[type="date"],form[data-auto-filter] input[type="month"],form[data-auto-filter] input[type="checkbox"],form[data-auto-filter] input[type="radio"]');
        if (!field) return;
        window.clearTimeout(autoFilterTimer);
        autoFilterTimer = 0;
        cancelAutoFilterRequest();
        loadAutoFilterResults(field.closest('form[data-auto-filter]'));
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-auto-filter-results] .portal-pagination a[href],[data-auto-filter-results] .stats-pagination a[href],[data-auto-filter-link][href]');
        if (!link || link.classList.contains('is-disabled') || link.classList.contains('portal-disabled-link')) return;
        const owner = link.closest('[data-auto-filter-results]');
        const formId = owner?.dataset.autoFilterForm || link.dataset.autoFilterForm || '';
        const form = formId ? document.getElementById(formId) : document.querySelector('form[data-auto-filter]');
        if (!form) return;
        event.preventDefault();
        loadAutoFilterResults(form, new URL(link.href, window.location.href), 'push');
    });

    window.addEventListener('popstate', () => {
        const form = document.querySelector('form[data-auto-filter]');
        if (form) loadAutoFilterResults(form, new URL(window.location.href), 'none');
    });

    if (prefersReducedMotion || !('IntersectionObserver' in window)) {
        revealTargets.forEach((item) => item.classList.add('is-visible'));
        return;
    }

    revealTargets.forEach((item, index) => {
        item.style.transitionDelay = `${Math.min(index % 6, 5) * 60}ms`;
    });

    const revealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, {
        threshold: 0.12,
        rootMargin: '0px 0px -8% 0px',
    });

    revealTargets.forEach((item) => revealObserver.observe(item));
});

document.addEventListener('click', (event) => {
    const actionButton = event.target.closest('[data-table-action]');
    if (!actionButton) {
        return;
    }
    const action = actionButton.dataset.tableAction;
    if (action === 'print') {
        window.print();
        return;
    }
    const toolbar = actionButton.closest('[data-table-toolbar]');
    const targetId = toolbar?.dataset.targetTable || '';
    const table = targetId ? document.getElementById(targetId) : null;
    if (!table) {
        return;
    }
    const rows = Array.from(table.querySelectorAll('tr')).map(
        (row) => Array.from(row.querySelectorAll('th,td')).map((cell) => cell.textContent.replace(/\s+/g, ' ').trim())
    );
    if (action === 'copy') {
        const text = rows.map((row) => row.join('\t')).join('\n');
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(text).then(() => {
                const original = actionButton.textContent;
                actionButton.textContent = 'Kopyalandı';
                setTimeout(() => { actionButton.textContent = original; }, 1500);
            }).catch(() => {});
        }
        return;
    }
    if (action === 'csv' || action === 'excel') {
        const csvBody = rows.map(
            (row) => row.map((cell) => '"' + cell.replace(/"/g, '""') + '"').join(',')
        ).join('\r\n');
        const isExcel = action === 'excel';
        const byteOrderMark = String.fromCharCode(0xFEFF);
        const blob = new Blob([byteOrderMark + csvBody], { type: `${isExcel ? 'application/vnd.ms-excel' : 'text/csv'};charset=utf-8` });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${targetId || 'cedvel'}-${new Date().toISOString().slice(0, 10)}.${isExcel ? 'xls' : 'csv'}`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
});
