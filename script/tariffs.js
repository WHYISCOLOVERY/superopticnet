document.addEventListener('DOMContentLoaded', function() {
    console.log('Tarif filtr yükləndi'); // Yükləndiyini yoxla

    const filterBtns = document.querySelectorAll('.filterBtn');
    const tariffCards = document.querySelectorAll('.tariffCard');

    if (filterBtns.length === 0) console.error('filterBtn tapılmadı');
    if (tariffCards.length === 0) console.error('tariffCard tapılmadı');

    function filterTariffs(speed) {
        tariffCards.forEach(card => {
            const cardSpeed = card.getAttribute('data-speed');
            if (speed === 'all' || cardSpeed === speed) {
                card.style.display = 'flex'; // və ya '' (boş)
            } else {
                card.style.display = 'none';
            }
        });
    }

    filterBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            const speed = this.getAttribute('data-filter');
            filterTariffs(speed);
            filterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // Səhifə açılanda 'all' filtrlə başla
    const defaultBtn = document.querySelector('.filterBtn.active') || document.querySelector('.filterBtn[data-filter="all"]');
    if (defaultBtn) defaultBtn.click();
});