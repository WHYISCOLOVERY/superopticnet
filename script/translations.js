const LangManager = (() => {
    const supportedLangs = ['az', 'en', 'ru'];

    function currentLang() {
        const lang = document.documentElement.lang?.toLowerCase() || 'az';
        return supportedLangs.includes(lang) ? lang : 'az';
    }

    function setFlag(lang) {
        const flagMap = {
            az: 'azerbaijan.png',
            en: 'eng.png',
            ru: 'rus.png',
        };

        const selectedLangFlag = document.getElementById('selectedLangFlag');
        const selectedLangName = document.getElementById('selectedLangName');

        if (selectedLangFlag) {
            selectedLangFlag.src = `images/${flagMap[lang]}`;
            selectedLangFlag.alt = lang.toUpperCase();
        }

        if (selectedLangName) {
            selectedLangName.textContent = lang.toUpperCase();
        }
    }

    function navigateToLang(lang) {
        const nextLang = supportedLangs.includes(lang) ? lang : 'az';
        const url = new URL(window.location.href);
        url.searchParams.set('lang', nextLang);
        localStorage.setItem('preferred_lang', nextLang);
        document.cookie = `site_lang=${nextLang}; path=/; max-age=31536000; SameSite=Lax`;
        window.location.href = url.toString();
    }

    function init() {
        const lang = currentLang();
        setFlag(lang);

        document.querySelectorAll('.lang-option').forEach((option) => {
            option.addEventListener('click', (event) => {
                event.preventDefault();
                navigateToLang(option.dataset.lang || 'az');
            });
        });
    }

    return { init };
})();

document.addEventListener('DOMContentLoaded', () => {
    LangManager.init();
});
