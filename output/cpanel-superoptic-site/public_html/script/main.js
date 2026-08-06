document.addEventListener('DOMContentLoaded', () => {
    const header = document.querySelector('.site-header');
    const mobileToggle = document.querySelector('[data-mobile-nav-toggle]');
    const mobileNav = document.querySelector('[data-mobile-nav]');
    const mobileActions = document.querySelector('[data-mobile-actions]');
    const flashNotice = document.querySelector('[data-flash-notice]');

    function syncHeaderState() {
        header?.classList.toggle('is-scrolled', window.scrollY > 18);
    }

    syncHeaderState();
    window.addEventListener('scroll', syncHeaderState, { passive: true });

    if (flashNotice) {
        window.setTimeout(() => {
            flashNotice.classList.add('is-hiding');

            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.delete('status');
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            window.history.replaceState({}, '', currentUrl.toString());

            window.setTimeout(() => {
                flashNotice.remove();
            }, 260);
        }, 1800);
    }

    if (mobileToggle && mobileNav) {
        mobileToggle.addEventListener('click', () => {
            mobileNav.classList.toggle('is-open');
            mobileActions?.classList.toggle('is-open');
        });

        mobileNav.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => {
                mobileNav.classList.remove('is-open');
                mobileActions?.classList.remove('is-open');
            });
        });
    }

    const languageOptions = document.querySelectorAll('.lang-option');

    languageOptions.forEach((option) => {
        option.addEventListener('click', (event) => {
            event.preventDefault();

            const nextLang = option.dataset.lang;
            if (!nextLang) {
                return;
            }

            const currentUrl = new URL(window.location.href);

            if (nextLang === 'az') {
                currentUrl.searchParams.delete('lang');
            } else {
                currentUrl.searchParams.set('lang', nextLang);
            }

            window.location.href = currentUrl.toString();
        });
    });

    const panelTabs = document.querySelectorAll('[data-tab-trigger]');
    const panelContents = document.querySelectorAll('[data-tab-panel]');

    panelTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tabTrigger;

            panelTabs.forEach((item) => item.classList.remove('is-active'));
            panelContents.forEach((item) => item.classList.remove('is-active'));

            tab.classList.add('is-active');
            document.querySelector(`[data-tab-panel="${target}"]`)?.classList.add('is-active');
        });
    });

    const filterButtons = document.querySelectorAll('.filter-pill');
    const audienceButtons = document.querySelectorAll('.audience-pill');
    const filterItems = document.querySelectorAll('[data-filter-item]');
    const tariffTrack = document.querySelector('[data-tariff-track]');
    const tariffScrollButtons = document.querySelectorAll('[data-tariff-scroll]');
    let activeFilter = 'all';
    let activeAudience = 'all';

    function applyTariffFilters() {
        filterItems.forEach((item) => {
            const filterMatch = activeFilter === 'all' || item.dataset.filterItem === activeFilter;
            const audienceMatch = activeAudience === 'all' || item.dataset.audienceItem === activeAudience;
            item.style.display = filterMatch && audienceMatch ? '' : 'none';
        });

        if (tariffTrack) {
            tariffTrack.scrollTo({ left: 0, behavior: 'smooth' });
        }
    }

    filterButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activeFilter = button.dataset.filter || 'all';
            filterButtons.forEach((item) => item.classList.remove('is-active'));
            button.classList.add('is-active');
            applyTariffFilters();
        });
    });

    audienceButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activeAudience = button.dataset.audience || 'all';
            audienceButtons.forEach((item) => item.classList.remove('is-active'));
            button.classList.add('is-active');
            applyTariffFilters();
        });
    });

    applyTariffFilters();

    tariffScrollButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (!tariffTrack) {
                return;
            }

            const direction = button.dataset.tariffScroll === 'prev' ? -1 : 1;
            const amount = Math.max(Math.round(tariffTrack.clientWidth * 0.72), 260);
            tariffTrack.scrollBy({ left: direction * amount, behavior: 'smooth' });
        });
    });

    const newsTabs = document.querySelectorAll('.news-tab');
    const newsCards = document.querySelectorAll('[data-news-category]');
    const channelSearch = document.querySelector('[data-channel-search]');
    const channelResultCards = document.querySelectorAll('[data-channel-result]');
    const channelEmpty = document.querySelector('[data-channel-empty]');
    const coverageSearch = document.querySelector('[data-coverage-search]');
    const coverageItems = document.querySelectorAll('[data-coverage-item]');
    const coverageEmpty = document.querySelector('[data-coverage-empty]');

    const normalizeText = (value) => String(value || '')
        .toLocaleLowerCase('az')
        .replace(/[ə]/g, 'e')
        .replace(/[ı]/g, 'i')
        .replace(/[ö]/g, 'o')
        .replace(/[ü]/g, 'u')
        .replace(/[ş]/g, 's')
        .replace(/[ç]/g, 'c')
        .replace(/[ğ]/g, 'g')
        .replace(/[ñ]/g, 'n')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, ' ')
        .trim();

    function setNewsTab(category) {
        newsTabs.forEach((tab) => tab.classList.toggle('is-active', tab.dataset.newsTab === category));
        newsCards.forEach((card) => card.classList.toggle('is-visible', card.dataset.newsCategory === category));
    }

    newsTabs.forEach((tab) => {
        tab.addEventListener('click', () => setNewsTab(tab.dataset.newsTab));
    });

    setNewsTab('network');

    if (channelSearch && channelResultCards.length) {
        const updateChannelResults = () => {
            const query = normalizeText(channelSearch.value.trim());
            let visibleCount = 0;

            channelResultCards.forEach((card) => {
                const rawName = card.dataset.channelName || '';
                const matches = query === '' || normalizeText(rawName).includes(query);
                const shouldShow = matches;
                card.hidden = !shouldShow;
                if (shouldShow) {
                    visibleCount += 1;
                }
            });

            if (channelEmpty) {
                channelEmpty.hidden = visibleCount > 0;
            }
        };

        channelSearch.addEventListener('input', updateChannelResults);
        channelSearch.addEventListener('change', updateChannelResults);
        channelSearch.addEventListener('keyup', updateChannelResults);
        updateChannelResults();
    }

    if (coverageSearch && coverageItems.length) {
        const updateCoverageResults = () => {
            const query = normalizeText(coverageSearch.value.trim());
            let visibleCount = 0;

            coverageItems.forEach((item) => {
                const rawName = item.dataset.coverageKey || item.dataset.coverageName || item.textContent || '';
                const matches = query === '' || normalizeText(rawName).includes(query);
                item.hidden = !matches;
                item.style.display = matches ? '' : 'none';
                if (matches) {
                    visibleCount += 1;
                }
            });

            if (coverageEmpty) {
                coverageEmpty.hidden = visibleCount > 0;
            }
        };

        coverageSearch.addEventListener('input', updateCoverageResults);
        coverageSearch.addEventListener('change', updateCoverageResults);
        coverageSearch.addEventListener('keyup', updateCoverageResults);
        updateCoverageResults();
    }

    const sectionTargets = [...document.querySelectorAll('main section[id]')];
    const navLinks = [...document.querySelectorAll('.main-nav a[href^="#"]')];

    function setActiveNav(id) {
        navLinks.forEach((link) => {
            const isMatch = link.getAttribute('href') === `#${id}`;
            link.classList.toggle('is-active', isMatch);
        });
    }

    if (sectionTargets.length && navLinks.length && 'IntersectionObserver' in window) {
        const navObserver = new IntersectionObserver((entries) => {
            const visibleEntry = entries
                .filter((entry) => entry.isIntersecting)
                .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

            if (visibleEntry?.target?.id) {
                setActiveNav(visibleEntry.target.id);
            }
        }, {
            rootMargin: '-35% 0px -45% 0px',
            threshold: [0.15, 0.35, 0.6],
        });

        sectionTargets.forEach((section) => navObserver.observe(section));
    }

    const revealTargets = document.querySelectorAll(
        '.hero-copy, .hero-panel, .section-heading, .tariff-card, .service-card, .coverage-copy, .coverage-visual, .support-card, .news-card, .contact-cta, .site-footer'
    );
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    revealTargets.forEach((item, index) => {
        item.classList.add('reveal-on-scroll');
        item.style.setProperty('--reveal-delay', `${Math.min(index % 6, 5) * 70}ms`);
    });

    if (prefersReducedMotion || !('IntersectionObserver' in window)) {
        revealTargets.forEach((item) => item.classList.add('is-revealed'));
    } else {
        const revealObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                entry.target.classList.add('is-revealed');
                observer.unobserve(entry.target);
            });
        }, {
            threshold: 0.14,
            rootMargin: '0px 0px -10% 0px',
        });

        revealTargets.forEach((item) => revealObserver.observe(item));
    }

    const form = document.querySelector('form[action="form-telegram.php"]');
    const submitButton = document.querySelector('[data-submit-label]');
    if (form && submitButton) {
        form.addEventListener('submit', () => {
            submitButton.disabled = true;
            submitButton.textContent = submitButton.dataset.submittingLabel || 'Sending...';
        });
    }

    const popupOverlay = document.getElementById('tariffPopup');
    const popupCard = document.querySelector('[data-popup-card]');
    const popupClose = document.querySelector('[data-popup-close]');
    const popupAction = document.querySelector('[data-popup-action]');
    const popupVisual = document.getElementById('popupVisual');
    const popupThemeLabel = document.getElementById('popupThemeLabel');
    const popupTitle = document.getElementById('popupTitle');
    const popupPackageType = document.getElementById('popupPackageType');
    const popupSpeed = document.getElementById('popupSpeed');
    const popupPrice = document.getElementById('popupPrice');
    const popupText = document.getElementById('popupText');
    const tariffCards = document.querySelectorAll('[data-tariff-card]');
    const pageLang = ['az', 'en', 'ru'].includes(document.documentElement.lang) ? document.documentElement.lang : 'az';
    const popupSessionKey = 'superopticPromoSeen';
    const popupThemeContent = {
        az: {
            family: {
                label: 'Ailə paketi',
                intro: 'Evdə gündəlik istifadə, TV və video zənglər üçün balanslı seçim.',
                image: 'images/promoaile.jfif',
                alt: 'Ailə üçün internet paketi təsviri',
            },
            business: {
                label: 'Biznes tarifi',
                intro: 'Ofis komandası, kassalar və iş axını üçün sabit fiber seçim.',
                image: 'images/promooffices.jfif',
                alt: 'Biznes internet paketi təsviri',
            },
            gaming: {
                label: 'Sürətli paket',
                intro: 'Oyun, stream və yüksək trafik tələb edən istifadə üçün güclü seçim.',
                image: 'images/promogame.jfif',
                alt: 'Sürətli internet paketi təsviri',
            },
        },
        en: {
            family: {
                label: 'Family plan',
                intro: 'Balanced choice for daily home use, TV and video calls.',
                image: 'images/promoaile.jfif',
                alt: 'Family internet package illustration',
            },
            business: {
                label: 'Business plan',
                intro: 'Stable fiber option for office teams, tills and workflow.',
                image: 'images/promooffices.jfif',
                alt: 'Business internet package illustration',
            },
            gaming: {
                label: 'High-speed plan',
                intro: 'Strong option for gaming, streaming and heavier traffic.',
                image: 'images/promogame.jfif',
                alt: 'High-speed internet package illustration',
            },
        },
        ru: {
            family: {
                label: 'Семейный пакет',
                intro: 'Сбалансированный выбор для дома, ТВ и видеозвонков.',
                image: 'images/promoaile.jfif',
                alt: 'Иллюстрация семейного интернет-пакета',
            },
            business: {
                label: 'Бизнес тариф',
                intro: 'Стабильный fiber-вариант для офиса, касс и рабочих процессов.',
                image: 'images/promooffices.jfif',
                alt: 'Иллюстрация бизнес интернет-пакета',
            },
            gaming: {
                label: 'Скоростной пакет',
                intro: 'Подходит для игр, стриминга и высокой нагрузки.',
                image: 'images/promogame.jfif',
                alt: 'Иллюстрация скоростного интернет-пакета',
            },
        },
    };

    const popupCopy = popupThemeContent[pageLang] || popupThemeContent.az;

    function showPopup() {
        if (!popupOverlay) {
            return;
        }

        const visibleCards = [...tariffCards].filter((card) => card.style.display !== 'none');
        const pool = visibleCards.length ? visibleCards : [...tariffCards];
        const randomCard = pool[Math.floor(Math.random() * pool.length)];

        if (randomCard) {
            const themeKey = randomCard.dataset.popupTheme || 'family';
            const theme = popupCopy[themeKey] || popupCopy.family;
            const type = randomCard.querySelector('.tariff-type')?.textContent?.trim() || popupPackageType.textContent;
            const speedText = randomCard.querySelector('.tariff-speed')?.textContent || '';
            const priceText = randomCard.querySelector('.tariff-price')?.textContent || '';
            const featureItems = [...randomCard.querySelectorAll('.tariff-features li')]
                .map((item) => item.textContent?.trim())
                .filter(Boolean)
                .slice(0, 3);

            if (popupTitle) {
                popupTitle.textContent = type;
            }

            if (popupThemeLabel) {
                popupThemeLabel.textContent = theme.label;
            }

            if (popupPackageType) {
                popupPackageType.textContent = theme.intro;
            }

            popupSpeed.textContent = speedText.replace(/[^\d]/g, '') || '100';
            popupPrice.textContent = priceText.replace(/[^\d]/g, '') || '30';

            if (popupText) {
                popupText.textContent = featureItems.length
                    ? featureItems.join(' • ')
                    : theme.intro;
            }

            if (popupVisual) {
                popupVisual.src = theme.image;
                popupVisual.alt = theme.alt;
            }

            if (popupCard) {
                popupCard.dataset.theme = themeKey;
            }
        }

        window.sessionStorage.setItem(popupSessionKey, 'true');
        popupOverlay.hidden = false;
        requestAnimationFrame(() => popupOverlay.classList.add('is-visible'));
    }

    function closePopup() {
        if (!popupOverlay) {
            return;
        }

        popupOverlay.classList.remove('is-visible');

        window.setTimeout(() => {
            popupOverlay.hidden = true;
        }, 220);
    }

    popupClose?.addEventListener('click', closePopup);
    popupAction?.addEventListener('click', closePopup);
    popupOverlay?.addEventListener('click', (event) => {
        if (event.target === popupOverlay) {
            closePopup();
        }
    });

    if (popupOverlay && window.sessionStorage.getItem(popupSessionKey) !== 'true') {
        window.setTimeout(showPopup, 1100);
    }
});
