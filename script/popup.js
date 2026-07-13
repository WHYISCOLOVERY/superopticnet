// Star Popup - tam məlumatlı versiya
document.addEventListener('DOMContentLoaded', function() {
    const popup = document.getElementById('starPopup');
    const closeBtn = document.getElementById('closeStarPopup');
    const dontShowCheckbox = document.getElementById('dontShowPopupAgain');
    const popupPackageType = document.getElementById('popupPackageType');
    const popupSpeedSpan = document.getElementById('popupSpeed');
    const popupPriceSpan = document.getElementById('popupPrice');
    const orderBtn = document.getElementById('popupOrderBtn');

    // 1. Təsadüfi tarif seç və bütün məlumatları al
    function getRandomTariffDetails() {
        const tariffCards = document.querySelectorAll('.tariffCard');
        if (!tariffCards.length) {
            return {
                type: 'İnternet',
                speed: '100',
                price: '25',
                currency: '₼',
                period: '/ay'
            };
        }
        const randomIndex = Math.floor(Math.random() * tariffCards.length);
        const card = tariffCards[randomIndex];
        
        // Paket tipini tap (tariffType içindən)
        let typeElem = card.querySelector('.tariffType');
        let type = typeElem ? typeElem.innerText.trim() : 'İnternet';
        
        // Sürəti tap (data-speed və ya speedNumber)
        let speed = card.getAttribute('data-speed') || '100';
        let speedElem = card.querySelector('.speedNumber');
        if (speedElem) speed = speedElem.innerText.trim();
        
        // Qiyməti tap
        let price = '25';
        let priceElem = card.querySelector('.tariffPrice');
        if (priceElem) {
            let priceText = priceElem.innerText;
            let match = priceText.match(/(\d+)/);
            if (match) price = match[1];
        }
        
        return { type, speed, price, currency: '₼', period: '/ay' };
    }

    // 2. Popup məzmununu yenilə
    function updatePopupContent() {
        const tariff = getRandomTariffDetails();
        if (popupPackageType) popupPackageType.innerText = tariff.type;
        if (popupSpeedSpan) popupSpeedSpan.innerText = tariff.speed;
        if (popupPriceSpan) popupPriceSpan.innerText = tariff.price;
        // Vahidlər sabit olduğu üçün HTML-də "Mb/s", "₼", "/ay" yazılıb
    }

    // 3. Popup göstərmə/gizlətmə (əvvəlki kimi)
    function showPopup() {
        if (localStorage.getItem('hideStarPopup') === 'true') return;
        updatePopupContent();
        popup.classList.add('active');
    }
    function hidePopup() {
        popup.classList.remove('active');
        if (dontShowCheckbox && dontShowCheckbox.checked) {
            localStorage.setItem('hideStarPopup', 'true');
        }
    }

    if (closeBtn) closeBtn.addEventListener('click', hidePopup);
    if (orderBtn) {
        orderBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof fullpage_api !== 'undefined') fullpage_api.moveTo('TariffPG');
            else document.querySelector('#TariffPG')?.scrollIntoView({ behavior: 'smooth' });
            hidePopup();
        });
    }
    if (popup) popup.addEventListener('click', function(e) { if (e.target === popup) hidePopup(); });
    
    setTimeout(showPopup, 3000);
});