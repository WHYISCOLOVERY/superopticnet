// Support Section - Wi-Fi Guide, FAQ, Contact toggles
document.addEventListener('DOMContentLoaded', function() {
    // Elementləri seç
    const wifiGuideSection = document.getElementById('wifiGuideSection');
    const faqSection = document.getElementById('faqSection');
    const contactSection = document.getElementById('contactSection');
    
    const wifiTrigger = document.querySelector('.wifi-guide-trigger');
    const faqTrigger = document.querySelector('.faq-trigger');
    const contactTrigger = document.querySelector('.contact-trigger');
    
    const closeWifi = document.querySelector('.close-wifi-guide');
    const closeFaq = document.querySelector('.close-faq');
    const closeContact = document.querySelector('.close-contact');

    // Bütün bölmələri gizlət
    function hideAllSections() {
        if (wifiGuideSection) wifiGuideSection.style.display = 'none';
        if (faqSection) faqSection.style.display = 'none';
        if (contactSection) contactSection.style.display = 'none';
    }

    // Wi-Fi təlimatlarını göstər
    if (wifiTrigger) {
        wifiTrigger.addEventListener('click', function() {
            hideAllSections();
            if (wifiGuideSection) wifiGuideSection.style.display = 'block';
            window.scrollTo({ top: wifiGuideSection.offsetTop - 80, behavior: 'smooth' });
        });
    }
    if (closeWifi) {
        closeWifi.addEventListener('click', function() {
            wifiGuideSection.style.display = 'none';
        });
    }

    // FAQ göstər
    if (faqTrigger) {
        faqTrigger.addEventListener('click', function() {
            hideAllSections();
            if (faqSection) faqSection.style.display = 'block';
            window.scrollTo({ top: faqSection.offsetTop - 80, behavior: 'smooth' });
        });
    }
    if (closeFaq) {
        closeFaq.addEventListener('click', function() {
            faqSection.style.display = 'none';
        });
    }

    // Kontakt göstər
    if (contactTrigger) {
        contactTrigger.addEventListener('click', function() {
            hideAllSections();
            if (contactSection) contactSection.style.display = 'block';
            window.scrollTo({ top: contactSection.offsetTop - 80, behavior: 'smooth' });
        });
    }
    if (closeContact) {
        closeContact.addEventListener('click', function() {
            contactSection.style.display = 'none';
        });
    }

    // FAQ dropdown (sual-cavab açılması)
    const faqItems = document.querySelectorAll('.faq-item');
    faqItems.forEach(item => {
        const question = item.querySelector('.faq-question');
        question.addEventListener('click', () => {
            item.classList.toggle('active');
        });
    });

    // Modem düymələri üçün əvvəlki script (wifi-guide.js-də olduğu kimi)
    const modemBtns = document.querySelectorAll('.modem-btn');
    const instructionDivs = document.querySelectorAll('.instruction-content');
    function showInstructions(modemId) {
        instructionDivs.forEach(div => {
            div.classList.remove('active');
            if (div.id === `instructions-${modemId}`) div.classList.add('active');
        });
        modemBtns.forEach(btn => {
            btn.classList.remove('active');
            if (btn.getAttribute('data-modem') === modemId) btn.classList.add('active');
        });
    }
    modemBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            showInstructions(this.getAttribute('data-modem'));
        });
    });
    // Defolt HSGQ göstər
    if (modemBtns.length) showInstructions('hsgq');
});
// Bölmələr göstərildikdə fullPage-i yenilə
function refreshFullPage() {
    if (window.fullpage_api) {
        window.fullpage_api.reBuild();
    }
}
// Hər dəfə bölmə göstərildikdə çağır
document.querySelector('.wifi-guide-trigger')?.addEventListener('click', refreshFullPage);
document.querySelector('.faq-trigger')?.addEventListener('click', refreshFullPage);
document.querySelector('.contact-trigger')?.addEventListener('click', refreshFullPage);