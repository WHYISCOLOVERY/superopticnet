document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form[action="form-telegram.php"]');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        const phone = form.querySelector('input[name="telefon"]').value.trim();
        const idNumber = form.querySelector('input[name="vezife_no"]').value.trim();
        const email = form.querySelector('input[name="email"]').value.trim();
        const fullName = form.querySelector('input[name="ad_soyad"]').value.trim();
        const reasonText = form.querySelector('input[name="muraciet_no"]').value.trim();
        const captcha = typeof grecaptcha !== 'undefined' ? grecaptcha.getResponse() : '';

        const normalizedPhone = phone.replace(/\s+/g, '').replace(/-/g, '');
        const phoneRegex = /^\+994(10|50|51|55|60|70|77)\d{7}$/;

        if (!phoneRegex.test(normalizedPhone)) {
            alert("Mobil nömrəni düzgün daxil edin. Nümunə: +994501234567");
            e.preventDefault();
            return;
        }

        if (!/^\d{7}$/.test(idNumber)) {
            alert("Şəxsiyyət vəsiqəsi nömrəsi 7 rəqəmdən ibarət olmalıdır.");
            e.preventDefault();
            return;
        }

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            alert("Etibarlı e-mail adresi daxil edin.");
            e.preventDefault();
            return;
        }

        const nameRegex = /^[A-Za-zƏəÖöÜüIıİiĞğÇçŞş\s\-]{3,}$/;
        if (!nameRegex.test(fullName)) {
            alert("Ad və soyadı düzgün daxil edin.");
            e.preventDefault();
            return;
        }

        if (reasonText.length < 5) {
            alert("Müraciət məzmununu biraz daha ətraflı yazın.");
            e.preventDefault();
            return;
        }

        if (typeof grecaptcha !== 'undefined' && captcha.length === 0) {
            e.preventDefault();
            alert("Lütfən 'Robot deyiləm' yoxlamasından keçin.");
            return;
        }
    });
});