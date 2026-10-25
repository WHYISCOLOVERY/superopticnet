<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

// Sessiyanı hər hansı çıxışdan ƏVVƏL başlat ki, CSRF cookie-si düzgün qoyulsun.
if (function_exists('app_session_boot')) {
    app_session_boot();
}

$lang = app_lang();
$flash = app_flash_message();
$hasRecaptcha = app_config('recaptcha_site_key') !== '';
$portalLoginUrl = rtrim(app_config('portal_url', 'https://mgt.superoptic.net/portal-login.php'), '/');
$status = $_GET['status'] ?? '';

$flagMap = [
    'az' => 'azerbaijan.png',
    'en' => 'eng.png',
    'ru' => 'rus.png',
];

$content = [
    'az' => [
        'brand_suffix' => 'Tech',
        'nav' => ['Ana', 'Tariflər', 'Xidmət', 'Əhatə', 'Dəstək', 'Xəbər', 'Əlaqə'],
        'pay_now' => 'Ödəniş et',
        'portal' => 'Portal',
        'hero_badge' => 'Fiber internet və sistem həlləri',
        'hero_title' => 'Ev və biznes üçün dayanıqlı fiber internet',
        'hero_text' => 'Fərdi istifadəçilər və obyektlər üçün fiber internet, TV, telefon xətti, modem seçimi və şəbəkə həllərini bir yerdə təqdim edirik.',
        'hero_actions' => ['Tariflərə bax', 'Dəstək mərkəzi'],
        'highlights' => [
            ['icon' => 'fa-solid fa-gauge-high', 'title' => '100-1000 Mb/s', 'text' => 'Ev və biznes üçün fərqli sürət planları'],
            ['icon' => 'fa-solid fa-headset', 'title' => '7/24 dəstək', 'text' => 'Texniki komandadan operativ geri dönüş'],
            ['icon' => 'fa-solid fa-network-wired', 'title' => 'Sistem həlləri', 'text' => 'LAN/WAN, server, IP telefoniya və təhlükəsizlik'],
        ],
        'panel_tabs' => ['Müraciət', 'Şəxsi kabinet'],
        'lead' => [
            'title' => 'Qoşulma və əlaqə',
            'subtitle' => 'Sadə formu doldurun, komandamız sizinlə qısa zamanda əlaqə saxlasın.',
            'name' => 'Ad və soyad',
            'phone' => 'Telefon',
            'email' => 'E-mail',
            'service' => 'Maraqlandığınız xidmət',
            'area' => 'Ərazi',
            'note' => 'Qısa qeyd',
            'placeholders' => ['Ad və soyad', '+994501234567', 'info@example.com', 'Müraciətinizi qısa şəkildə yazın'],
            'options' => ['Xidməti seçin', 'Ev interneti', 'Biznes interneti', 'Texniki dəstək', 'Şəbəkə və sistem həlli'],
            'area_default' => 'Ərazini seçin',
            'submit' => 'Göndər',
            'submitting' => 'Göndərilir...',
        ],
        'portal_box' => [
            'title' => 'Portal girişi',
            'text' => 'Müştəri billing məlumatlarını, əməkdaş isə roluna uyğun billing və OLT keçidlərini bu portaldan istifadə edəcək.',
            'points' => ['Müştəri: trafik, ödəniş, çıxarış və müraciətlər', 'Əməkdaş: billing keçidi və OLT idarəetməsi'],
            'action' => 'Portal giriş səhifəsini aç',
        ],
        'tariffs' => [
            'eyebrow' => 'Tariflər',
            'title' => 'Fərdi və biznes internet paketləri',
            'subtitle' => 'Ev və biznes tariflərini ayrıca seçin. Fərdi tariflərdə TV +6 AZN, telefon +4 AZN; biznes tariflərində isə TV +10 AZN və telefon +10 AZN əlavə olunur.',
            'audiences' => ['Bütün paketlər', 'Fərdi tariflər', 'Biznes tarifləri'],
            'filters' => ['Bütün tariflər', 'İnternet', 'İnternet + TV', 'İnternet + Telefon', 'İnternet + TV + Telefon xətti'],
            'segment_home' => 'Fərdi',
            'segment_business' => 'Biznes',
            'per_month' => '/ay',
            'order' => 'Sifariş et',
            'modems' => [
                ['title' => 'HSGQ GPON modem', 'text' => 'Standart fiber abonələr üçün stabil həll'],
                ['title' => 'Huawei dual-band modem', 'text' => '2.4 GHz və 5 GHz istifadəsi üçün uyğun seçim'],
                ['title' => 'TP-Link router', 'text' => 'Böyük ev və ofislər üçün əlavə Wi-Fi paylanması'],
            ],
        ],
        'services' => [
            'eyebrow' => 'Ev və biznes üçün xidmətlər',
            'title' => 'İnternetdən kənar dəyər yaradan həllər',
            'subtitle' => 'Sadəcə internet satışı yox, həm də şəbəkə, sistem, IT dəstək, proqram təminatı, telefoniya və təhlükəsizlik üzrə əməli dəstək təqdim edirik.',
            'items' => [
                ['icon' => 'fa-solid fa-house-signal', 'title' => 'Ev interneti', 'summary' => 'Ailə və fərdi istifadə üçün fiber internet, TV və telefon paketləri.', 'details' => 'Quraşdırma, modem seçimi, Wi-Fi tövsiyəsi və ödəniş yönləndirilməsi bir axında təqdim olunur.'],
                ['icon' => 'fa-solid fa-building', 'title' => 'Biznes interneti', 'summary' => 'Ofis və obyektlər üçün daha sabit əlaqə və prioritet dəstək.', 'details' => 'Biznes tariflərində internet baza qiyməti fərdi tarifdən 5 AZN yuxarı olur, TV və telefon isə ayrıca əlavə olunur.'],
                ['icon' => 'fa-solid fa-diagram-project', 'title' => 'Şəbəkə layihələndirilməsi', 'summary' => 'LAN/WAN topologiyası, switch və router konfiqurasiyası.', 'details' => 'Kiçik ofisdən çoxmərtəbəli obyektə qədər daxili və uzaq şəbəkə layihələri qurula bilər.'],
                ['icon' => 'fa-solid fa-server', 'title' => 'Server və monitorinq', 'summary' => 'Fayl serveri, backup, monitorinq və mərkəzləşdirilmiş idarəetmə.', 'details' => 'Xidmətlərin dayanıqlı işləməsi üçün server, backup və monitorinq həlləri ayrıca planlana bilər.'],
                ['icon' => 'fa-solid fa-phone-volume', 'title' => 'IP telefoniya', 'summary' => 'Daxili nömrələmə, çağrı yönləndirmə və IVR həlləri.', 'details' => 'Telefon xətti əlavə olunan paketlərdə biznes üçün daha rahat əlaqə axını qurmaq mümkündür.'],
                ['icon' => 'fa-solid fa-shield-halved', 'title' => 'Təhlükəsizlik və lisenziya', 'summary' => 'Şəbəkə təhlükəsizliyi, lisenziyalaşdırma və əsas audit dəstəyi.', 'details' => 'Kamera, server və ofis şəbəkələrində giriş nəzarəti, yenilənmə planı və təhlükəsizlik qaydaları qurula bilər.'],
            ],
        ],
        'coverage' => [
            'eyebrow' => 'Əhatə dairəsi',
            'title' => 'Bakı və bölgələr üzrə xidmət göstərdiyimiz ərazilər',
            'subtitle' => 'Əhatə dairəsini həm siyahı, həm də xəritə üzərində görə bilərsiniz.',
            'note_title' => 'Yeni ərazi əlavə olunursa',
            'note_text' => 'Qısa müraciət buraxın, komanda texniki imkan və qoşulma müddətini sizə geri dönüşdə təqdim etsin.',
            'areas' => ['Astara', 'Masallı', 'Lənkəran', 'Neftçala', 'İsmayıllı', 'Nizami', 'Sabunçu', 'Suraxanı', 'Abşeron', 'Xətai', 'Binəqədi', 'Kürdəxanı', 'Pirşağı', 'Mehdiabad', 'Məmmədli', 'Zabrat', 'Qaraçuxur', 'Bakıxanov', 'Əhmədli', 'Biləcəri', 'Günəşli'],
            'points' => ['Astara', 'Masallı', 'Lənkəran', 'Neftçala', 'İsmayıllı', 'Abşeron', 'Bakı', 'Suraxanı', 'Sabunçu', 'Xətai', 'Binəqədi', 'Günəşli'],
        ],
        'support' => [
            'eyebrow' => 'Dəstək mərkəzi',
            'title' => 'Ətraflı izah və özünəxidmət blokları',
            'subtitle' => 'Modemlər üzrə şifrə yeniləmə təlimatı, geniş FAQ və birbaşa əlaqə kanalları burada toplanır.',
            'wifi_title' => 'Wi-Fi şifrəsini dəyişmək',
            'wifi_text' => 'Modem markasına görə fərqli idarəetmə paneli olsa da, əsas addımlar aşağıdakı kimidir.',
            'faq_title' => 'Tez-tez verilən suallar',
            'contact_title' => 'Birbaşa əlaqə',
            'guides' => [
                ['title' => 'HSGQ modemləri', 'summary' => 'Brauzerdə `192.168.1.1` və ya cihazın üzərində yazılan ünvana daxil olun.', 'steps' => ['Wireless və ya Wi-Fi bölməsini açın.', '2.4 GHz və 5 GHz üçün SSID adlarını ayrıca yoxlayın.', 'Yeni şifrəni `WPA2/WPA3` formatında təyin edib yadda saxlayın.', 'Dəyişiklikdən sonra cihazları yeni şifrə ilə yenidən qoşun.']],
                ['title' => 'Huawei modemləri', 'summary' => 'Ən çox giriş ünvanı `192.168.100.1` və ya `192.168.1.1` olur.', 'steps' => ['WLAN bölməsinə keçin.', '2.4G və 5G şəbəkələrini ayrıca yoxlayın.', 'SSID Name və WPA PreSharedKey sahələrini yeniləyin.', 'Yadda saxladıqdan sonra modem qısa yenilənmə edə bilər.']],
                ['title' => 'TP-Link modem və routerlər', 'summary' => '`tplinkwifi.net` və ya `192.168.0.1` / `192.168.1.1` üzərindən idarəetməyə daxil olun.', 'steps' => ['Wireless Settings və ya Basic Wireless bölməsini açın.', 'Smart Connect aktivdirsə 2.4 və 5 GHz eyni adda görünə bilər.', 'Wi-Fi adını və şifrəsini yeniləyin.', 'Uzaq otaqlarda zəif siqnal varsa 2.4 GHz və ya əlavə router istifadə edin.']],
                ['title' => 'BDCOM modemləri', 'summary' => 'WLAN və ya Network bölməsində Wi-Fi ayarları yerləşir.', 'steps' => ['Mövcud SSID profilini seçin.', 'Təhlükəsizlik növünü `WPA2-PSK` və ya `WPA2/WPA3` olaraq saxlayın.', 'Yeni şifrəni daxil edib apply edin.', 'TV Box istifadə olunursa yalnız Wi-Fi hissəsini dəyişin.']],
            ],
            'faq' => [
                ['q' => 'İnternet sürətim aşağıdırsa nə etməliyəm?', 'a' => 'Əvvəlcə modemi 10-15 saniyə söndürüb yenidən başladın. Sonra sürəti mümkün olduqda kabel ilə yoxlayın. Wi-Fi üzərindən sürət zəifdirsə buna qalın divarlar, 2.4 GHz-də sıxlıq, çox cihazın eyni anda işləməsi və ya modem yerləşimi səbəb ola bilər. Kabeldə nəticə normaldırsa problem əsasən Wi-Fi mühitində olur.'],
                ['q' => '2.4 GHz ilə 5 GHz arasındakı fərq nədir?', 'a' => '2.4 GHz daha uzağa çatır və divarlardan daha rahat keçir, amma sürət baxımından daha zəif və daha sıx istifadə olunan diapazondur. 5 GHz isə daha yüksək sürət və daha yaxşı gecikmə verir, amma məsafə artdıqca siqnal tez zəifləyir. Modemə yaxın otaqlarda 5 GHz, uzaq otaqlarda isə 2.4 GHz daha uyğun olur.'],
                ['q' => 'Ödənişi necə edə bilərəm və gecikmə olanda nə baş verir?', 'a' => 'Ödənişi onlayn ödəniş səhifəsi, bank və partnyor kanallar üzərindən etmək mümkündür. Gecikmə olduqda xidmət məhdudlaşa və ya dayana bilər. Ödəniş etdikdən sonra bərpa adətən qısa müddətdə tamamlanır. Gecikmə davam edirsə, qəbz və ya ödəniş tarixini dəstək komandası ilə paylaşmaq lazımdır.'],
                ['q' => 'Wi-Fi şifrəsini unutmuşamsa və ya siqnal zəifdirsə nə etməliyəm?', 'a' => 'Əvvəlcə modem stikerindəki ilkin məlumatları yoxlayın. Əgər şifrə dəyişdirilibsə, modem panelindən yeni şifrə təyin etmək daha doğrudur. Siqnal zəifdirsə modem mərkəzi nöqtəyə yerləşdirilməli, metal səthlərdən və TV arxasından uzaq tutulmalıdır. Böyük evlərdə əlavə access point və ya mesh həlli daha uyğun olur.'],
            ],
        ],
        'news' => [
            'eyebrow' => 'Xəbərlər və yeniliklər',
            'title' => 'Müştərilər üçün faydalı informasiya lenti',
            'subtitle' => 'İnternet, sistem təhlükəsizliyi və kripto bazar mövzularını ayrıca lentdə göstərən səliqəli struktur.',
            'tabs' => ['İnternet və şəbəkə', 'Sistem və təhlükəsizlik', 'Kripto bazar icmalı'],
            'disclaimer' => 'Kripto bölməsi məlumat xarakterlidir, investisiya məsləhəti deyil.',
            'items' => [
                ['category' => 'network', 'category_label' => 'İnternet və şəbəkə', 'source' => 'Super Optik redaksiyası', 'title' => 'Fiber qoşulmada stabil sürət üçün ev daxilində 3 vacib yoxlama', 'summary' => 'Modem yeri, kabel vəziyyəti və Wi-Fi kanal seçimi istifadəçi təcrübəsinə birbaşa təsir edir. Müştərilər üçün sadə yoxlama siyahısı ayrıca paylaşılır.', 'date' => '22.04.2026'],
                ['category' => 'network', 'category_label' => 'İnternet və şəbəkə', 'source' => 'Super Optik redaksiyası', 'title' => 'FTTH və klassik qoşulma arasında fərq: hansı istifadəçiyə hansı model uyğundur?', 'summary' => 'Fiber infrastrukturun praktik üstünlükləri sadə dildə izah olunur ki, müştərilər daha rahat seçim edə bilsin.', 'date' => '20.04.2026'],
                ['category' => 'systems', 'category_label' => 'Sistem və təhlükəsizlik', 'source' => 'Super Optik redaksiyası', 'title' => 'Ofis şəbəkəsində zəif nöqtələri azaltmaq üçün minimal təhlükəsizlik paketi', 'summary' => 'Güclü parol siyasəti, ehtiyat nüsxə intizamı və girişlərin məhdudlaşdırılması kiçik bizneslər üçün ilkin qoruma qatını formalaşdırır.', 'date' => '21.04.2026'],
                ['category' => 'systems', 'category_label' => 'Sistem və təhlükəsizlik', 'source' => 'Super Optik redaksiyası', 'title' => 'Kamera, server və şəbəkə cihazları üçün lisenziya və yeniləmə planı niyə vacibdir?', 'summary' => 'Yalnız qurulum deyil, yenilənmə və lisenziya nəzarəti də dayanıqlı sistem işinin vacib hissəsidir.', 'date' => '18.04.2026'],
                ['category' => 'crypto', 'category_label' => 'Kripto bazar icmalı', 'source' => 'Bazar qeydi', 'title' => 'Kripto bazarda volatillik artanda risk idarəetməsi niyə birinci yerdə olmalıdır?', 'summary' => 'Qiymət proqnozu vermək əvəzinə risk, xəbər axını və qərar intizamı kimi əsas prinsiplər xatırladılır.', 'date' => '22.04.2026'],
                ['category' => 'crypto', 'category_label' => 'Kripto bazar icmalı', 'source' => 'Bazar qeydi', 'title' => 'Makro xəbərlər kripto sentimentinə necə təsir edir?', 'summary' => 'Faiz dərəcələri, tənzimləmə xəbərləri və likvidlik mühiti qısa icmal şəklində təqdim olunur.', 'date' => '19.04.2026'],
            ],
        ],
        'contact' => [
            'eyebrow' => 'Sadə müraciət və əlaqə',
            'title' => 'Qoşulma, dəstək və biznes təklifləri üçün birbaşa bizimlə danışın',
            'text' => 'Yeni qoşulma, texniki problem, biznes interneti və sistem layihələri üçün eyni kanaldan müraciət edə bilərsiniz.',
        ],
        'footer' => 'Fiber internet, texniki dəstək və sistem həllərini bir platformada birləşdirən xidmət modeli.',
        'popup' => ['label' => 'Tövsiyə olunan paket', 'title' => 'Sürətli internet, daha rahat istifadə', 'offer' => 'İnternet', 'text' => 'İstifadə vərdişinizə uyğun tarifi seçib daha sabit bağlantı əldə edin.', 'dont_show' => 'Bir daha göstərmə'],
    ],
    'en' => [
        'brand_suffix' => 'Tech',
        'nav' => ['Home', 'Tariffs', 'Services', 'Coverage', 'Support', 'News', 'Contact'],
        'pay_now' => 'Pay now',
        'portal' => 'Portal',
        'hero_badge' => 'Fiber internet and system solutions',
        'hero_title' => 'Reliable fiber internet for home and business',
        'hero_text' => 'We provide fiber internet, TV, phone line, modem options and network solutions for homes and business sites in one place.',
        'hero_actions' => ['View tariffs', 'Support center'],
        'highlights' => [
            ['icon' => 'fa-solid fa-gauge-high', 'title' => '100-1000 Mb/s', 'text' => 'Different speed plans for home and business'],
            ['icon' => 'fa-solid fa-headset', 'title' => '24/7 support', 'text' => 'Fast response from the technical team'],
            ['icon' => 'fa-solid fa-network-wired', 'title' => 'System solutions', 'text' => 'LAN/WAN, servers, IP telephony and security'],
        ],
        'panel_tabs' => ['Request', 'Personal portal'],
        'lead' => [
            'title' => 'Connection and contact',
            'subtitle' => 'Fill in the simple form and our team will contact you shortly.',
            'name' => 'Full name',
            'phone' => 'Phone',
            'email' => 'E-mail',
            'service' => 'Service of interest',
            'area' => 'Area',
            'note' => 'Short note',
            'placeholders' => ['Full name', '+994501234567', 'info@example.com', 'Briefly describe your request'],
            'options' => ['Select a service', 'Home internet', 'Business internet', 'Technical support', 'Network and system solution'],
            'area_default' => 'Select area',
            'submit' => 'Send',
            'submitting' => 'Sending...',
        ],
        'portal_box' => [
            'title' => 'Portal access',
            'text' => 'Customers will use the portal for billing data, while employees will use it for role-based billing and OLT handoffs.',
            'points' => ['Customer: traffic, payments, statements and requests', 'Employee: billing handoff and OLT management'],
            'action' => 'Open portal login page',
        ],
        'tariffs' => [
            'eyebrow' => 'Tariffs',
            'title' => 'Home and business internet packages',
            'subtitle' => 'Choose home and business tariffs separately. In home plans TV adds 6 AZN and phone adds 4 AZN; in business plans TV and phone add 10 AZN each.',
            'audiences' => ['All packages', 'Home tariffs', 'Business tariffs'],
            'filters' => ['All tariffs', 'Internet', 'Internet + TV', 'Internet + Phone', 'Internet + TV + Phone line'],
            'segment_home' => 'Home',
            'segment_business' => 'Business',
            'per_month' => '/month',
            'order' => 'Order',
            'modems' => [
                ['title' => 'HSGQ GPON modem', 'text' => 'Stable option for standard fiber subscribers'],
                ['title' => 'Huawei dual-band modem', 'text' => 'Good fit for both 2.4 GHz and 5 GHz usage'],
                ['title' => 'TP-Link router', 'text' => 'Extra Wi-Fi coverage for larger homes and offices'],
            ],
        ],
        'services' => [
            'eyebrow' => 'Home and business services',
            'title' => 'Solutions that create value beyond connectivity',
            'subtitle' => 'We provide not only internet access, but also practical support for networks, systems, IT support, software, telephony and security.',
            'items' => [
                ['icon' => 'fa-solid fa-house-signal', 'title' => 'Home internet', 'summary' => 'Fiber internet, TV and phone bundles for families and home users.', 'details' => 'Installation, modem selection, Wi-Fi guidance and payment routing are offered in one flow.'],
                ['icon' => 'fa-solid fa-building', 'title' => 'Business internet', 'summary' => 'More stable connectivity and priority support for offices and sites.', 'details' => 'Business tariffs start 5 AZN above home internet, while TV and phone are added separately.'],
                ['icon' => 'fa-solid fa-diagram-project', 'title' => 'Network design', 'summary' => 'LAN/WAN topology, switch and router configuration.', 'details' => 'From small offices to multi-floor sites, internal and remote network projects can be built.'],
                ['icon' => 'fa-solid fa-server', 'title' => 'Server and monitoring', 'summary' => 'File servers, backup, monitoring and centralized management.', 'details' => 'Server, backup and monitoring solutions can be planned to keep services stable.'],
                ['icon' => 'fa-solid fa-phone-volume', 'title' => 'IP telephony', 'summary' => 'Internal numbering, call routing and IVR solutions.', 'details' => 'Phone-enabled packages can support a smoother communication flow for businesses.'],
                ['icon' => 'fa-solid fa-shield-halved', 'title' => 'Security and licensing', 'summary' => 'Network security, licensing and basic audit support.', 'details' => 'Access control, update planning and security rules can be built for cameras, servers and office networks.'],
            ],
        ],
        'coverage' => [
            'eyebrow' => 'Coverage',
            'title' => 'Areas where we provide service across Baku and the regions',
            'subtitle' => 'You can review the service area both as a list and on the map.',
            'note_title' => 'If your area is new',
            'note_text' => 'Leave a short request and our team will respond with technical availability and connection timing.',
            'areas' => ['Astara', 'Masalli', 'Lankaran', 'Neftchala', 'Ismayilli', 'Nizami', 'Sabunchu', 'Surakhani', 'Absheron', 'Khatai', 'Binagadi', 'Kurdakhani', 'Pirshagi', 'Mehdiabad', 'Mammadli', 'Zabrat', 'Garachukhur', 'Bakikhanov', 'Ahmadli', 'Bilajari', 'Gunashli'],
            'points' => ['Astara', 'Masalli', 'Lankaran', 'Neftchala', 'Ismayilli', 'Absheron', 'Baku', 'Surakhani', 'Sabunchu', 'Khatai', 'Binagadi', 'Gunashli'],
        ],
        'support' => [
            'eyebrow' => 'Support center',
            'title' => 'Detailed explanations and self-service blocks',
            'subtitle' => 'Password reset guides by modem brand, an expanded FAQ and direct contact channels are gathered here.',
            'wifi_title' => 'Change Wi-Fi password',
            'wifi_text' => 'The control panel differs by modem brand, but the main steps are usually similar.',
            'faq_title' => 'Frequently asked questions',
            'contact_title' => 'Direct contact',
            'guides' => [
                ['title' => 'HSGQ modems', 'summary' => 'Open `192.168.1.1` or the address printed on the device in your browser.', 'steps' => ['Open the Wireless or Wi-Fi section.', 'Check 2.4 GHz and 5 GHz SSID names separately.', 'Set a new password using `WPA2/WPA3` and save it.', 'Reconnect your devices using the new password.']],
                ['title' => 'Huawei modems', 'summary' => 'The most common login address is `192.168.100.1` or `192.168.1.1`.', 'steps' => ['Open the WLAN section.', 'Check the 2.4G and 5G networks separately.', 'Update the SSID Name and WPA PreSharedKey fields.', 'The modem may briefly refresh after saving.']],
                ['title' => 'TP-Link modems and routers', 'summary' => 'Log in through `tplinkwifi.net` or `192.168.0.1` / `192.168.1.1`.', 'steps' => ['Open Wireless Settings or Basic Wireless.', 'If Smart Connect is enabled, 2.4 and 5 GHz may share the same name.', 'Update the Wi-Fi name and password.', 'If distant rooms have weak coverage, prefer 2.4 GHz or use an extra router.']],
                ['title' => 'BDCOM modems', 'summary' => 'Wi-Fi settings are usually inside the WLAN or Network section.', 'steps' => ['Select the current SSID profile.', 'Keep the security mode as `WPA2-PSK` or `WPA2/WPA3`.', 'Enter the new password and apply the settings.', 'If you use a TV Box, only change the Wi-Fi part and do not touch VLAN settings.']],
            ],
            'faq' => [
                ['q' => 'What should I do if my internet speed is low?', 'a' => 'First restart the modem for 10-15 seconds and then test the speed over cable if possible. If Wi-Fi looks weak, thick walls, crowding on 2.4 GHz, too many active devices or modem placement can be the reason. If the wired result is normal, the issue is usually in the Wi-Fi environment.'],
                ['q' => 'What is the difference between 2.4 GHz and 5 GHz?', 'a' => '2.4 GHz reaches farther and passes through walls more easily, but it is slower and usually more crowded. 5 GHz delivers higher speed and better latency, but the signal weakens faster with distance. Use 5 GHz in nearby rooms and 2.4 GHz in distant rooms.'],
                ['q' => 'How can I pay and what happens if payment is delayed?', 'a' => 'Payments can be made through the online payment page, banks and partner channels. If payment is delayed, service may be limited or suspended. After payment, restoration is usually completed within a short time. If delay continues, share the receipt or payment date with the support team.'],
                ['q' => 'What should I do if I forgot the Wi-Fi password or the signal is weak?', 'a' => 'First check the original data on the modem sticker. If the password was changed, the best way is to set a new one from the modem panel. If the signal is weak, place the modem in a central location and keep it away from metal surfaces or behind a TV. In larger homes, an access point or mesh solution is usually better.'],
            ],
        ],
        'news' => [
            'eyebrow' => 'News and updates',
            'title' => 'Useful information feed for customers',
            'subtitle' => 'A clean structure that separates internet, system security and crypto market topics.',
            'tabs' => ['Internet and network', 'Systems and security', 'Crypto market review'],
            'disclaimer' => 'The crypto section is for information only and is not investment advice.',
            'items' => [
                ['category' => 'network', 'category_label' => 'Internet and network', 'source' => 'Super Optik editorial', 'title' => '3 important in-home checks for stable speed on fiber service', 'summary' => 'Modem placement, cable condition and Wi-Fi channel choice directly affect user experience. A simple checklist is shared for customers.', 'date' => '22.04.2026'],
                ['category' => 'network', 'category_label' => 'Internet and network', 'source' => 'Super Optik editorial', 'title' => 'FTTH vs classic connection: which model fits which user?', 'summary' => 'The practical benefits of fiber infrastructure are explained in simple language to help customers choose more easily.', 'date' => '20.04.2026'],
                ['category' => 'systems', 'category_label' => 'Systems and security', 'source' => 'Super Optik editorial', 'title' => 'A minimal security package to reduce weak points in office networks', 'summary' => 'Strong password policies, backup discipline and controlled access form the first protective layer for small businesses.', 'date' => '21.04.2026'],
                ['category' => 'systems', 'category_label' => 'Systems and security', 'source' => 'Super Optik editorial', 'title' => 'Why are licensing and update plans important for cameras, servers and network devices?', 'summary' => 'Not only setup, but also updates and licensing control are essential for stable system operation.', 'date' => '18.04.2026'],
                ['category' => 'crypto', 'category_label' => 'Crypto market review', 'source' => 'Market note', 'title' => 'Why should risk management come first when crypto volatility rises?', 'summary' => 'Instead of giving price predictions, the review reminds readers of key principles such as risk, news flow and decision discipline.', 'date' => '22.04.2026'],
                ['category' => 'crypto', 'category_label' => 'Crypto market review', 'source' => 'Market note', 'title' => 'How do macro headlines affect crypto sentiment?', 'summary' => 'Interest rates, regulation news and liquidity conditions can be summarized for readers in a short market overview.', 'date' => '19.04.2026'],
            ],
        ],
        'contact' => [
            'eyebrow' => 'Simple request and contact',
            'title' => 'Talk to us directly for connection, support and business proposals',
            'text' => 'You can use the same channel for new connection, technical issues, business internet and system projects.',
        ],
        'footer' => 'A service model that brings fiber internet, technical support and system solutions together on one platform.',
        'popup' => ['label' => 'Recommended package', 'title' => 'Faster internet, smoother usage', 'offer' => 'Internet', 'text' => 'Choose a tariff that fits your usage style and get a more stable connection.', 'dont_show' => 'Do not show again'],
    ],
    'ru' => [
        'brand_suffix' => 'Tech',
        'nav' => ['Главная', 'Тарифы', 'Услуги', 'Покрытие', 'Поддержка', 'Новости', 'Контакты'],
        'pay_now' => 'Оплатить',
        'portal' => 'Портал',
        'hero_badge' => 'Оптоволоконный интернет и системные решения',
        'hero_title' => 'Надежный оптоволоконный интернет для дома и бизнеса',
        'hero_text' => 'Мы предлагаем оптоволоконный интернет, ТВ, телефонную линию, выбор модема и сетевые решения для частных пользователей и объектов в одном месте.',
        'hero_actions' => ['Смотреть тарифы', 'Центр поддержки'],
        'highlights' => [
            ['icon' => 'fa-solid fa-gauge-high', 'title' => '100-1000 Mb/s', 'text' => 'Разные тарифы скорости для дома и бизнеса'],
            ['icon' => 'fa-solid fa-headset', 'title' => 'Поддержка 24/7', 'text' => 'Быстрый отклик технической команды'],
            ['icon' => 'fa-solid fa-network-wired', 'title' => 'Системные решения', 'text' => 'LAN/WAN, серверы, IP-телефония и безопасность'],
        ],
        'panel_tabs' => ['Заявка', 'Личный кабинет'],
        'lead' => [
            'title' => 'Подключение и связь',
            'subtitle' => 'Заполните простую форму, и наша команда свяжется с вами в ближайшее время.',
            'name' => 'Имя и фамилия',
            'phone' => 'Телефон',
            'email' => 'E-mail',
            'service' => 'Интересующая услуга',
            'area' => 'Территория',
            'note' => 'Краткая заметка',
            'placeholders' => ['Имя и фамилия', '+994501234567', 'info@example.com', 'Кратко опишите обращение'],
            'options' => ['Выберите услугу', 'Домашний интернет', 'Бизнес интернет', 'Техническая поддержка', 'Сетевое и системное решение'],
            'area_default' => 'Выберите территорию',
            'submit' => 'Отправить',
            'submitting' => 'Отправка...',
        ],
        'portal_box' => [
            'title' => 'Доступ в портал',
            'text' => 'Клиенты будут использовать портал для данных billing, а сотрудники — для ролевых переходов в billing и OLT.',
            'points' => ['Клиент: трафик, оплаты, выписки и обращения', 'Сотрудник: переход в billing и управление OLT'],
            'action' => 'Открыть страницу входа в портал',
        ],
        'tariffs' => [
            'eyebrow' => 'Тарифы',
            'title' => 'Домашние и бизнес интернет-пакеты',
            'subtitle' => 'Выбирайте домашние и бизнес-тарифы отдельно. В домашних тарифах ТВ +6 AZN и телефон +4 AZN, а в бизнес-тарифах ТВ и телефон добавляются по 10 AZN.',
            'audiences' => ['Все пакеты', 'Домашние тарифы', 'Бизнес тарифы'],
            'filters' => ['Все тарифы', 'Интернет', 'Интернет + ТВ', 'Интернет + Телефон', 'Интернет + ТВ + Телефонная линия'],
            'segment_home' => 'Дом',
            'segment_business' => 'Бизнес',
            'per_month' => '/месяц',
            'order' => 'Заказать',
            'modems' => [
                ['title' => 'HSGQ GPON модем', 'text' => 'Стабильное решение для стандартных fiber-абонентов'],
                ['title' => 'Huawei dual-band модем', 'text' => 'Удобный выбор для 2.4 GHz и 5 GHz'],
                ['title' => 'TP-Link router', 'text' => 'Дополнительное Wi-Fi покрытие для больших домов и офисов'],
            ],
        ],
        'services' => [
            'eyebrow' => 'Услуги для дома и бизнеса',
            'title' => 'Решения, которые дают больше, чем просто интернет',
            'subtitle' => 'Мы предлагаем не только доступ в интернет, но и практическую поддержку по сетям, системам, IT-поддержке, программному обеспечению, телефонии и безопасности.',
            'items' => [
                ['icon' => 'fa-solid fa-house-signal', 'title' => 'Домашний интернет', 'summary' => 'Оптоволоконный интернет, ТВ и телефонные пакеты для семьи и дома.', 'details' => 'Подключение, выбор модема, рекомендации по Wi-Fi и оплата предлагаются в одном потоке.'],
                ['icon' => 'fa-solid fa-building', 'title' => 'Бизнес интернет', 'summary' => 'Более стабильная связь и приоритетная поддержка для офисов и объектов.', 'details' => 'В бизнес-тарифах базовая цена интернета на 5 AZN выше домашней, а ТВ и телефон добавляются отдельно.'],
                ['icon' => 'fa-solid fa-diagram-project', 'title' => 'Проектирование сети', 'summary' => 'Топология LAN/WAN, настройка switch и router.', 'details' => 'Можно строить внутренние и удаленные сетевые проекты от малого офиса до многоэтажного объекта.'],
                ['icon' => 'fa-solid fa-server', 'title' => 'Серверы и мониторинг', 'summary' => 'Файловые серверы, backup, мониторинг и централизованное управление.', 'details' => 'Серверные, backup и мониторинговые решения можно планировать отдельно для устойчивой работы сервисов.'],
                ['icon' => 'fa-solid fa-phone-volume', 'title' => 'IP-телефония', 'summary' => 'Внутренняя нумерация, маршрутизация звонков и IVR.', 'details' => 'Пакеты с телефонной линией помогают выстроить более удобный поток связи для бизнеса.'],
                ['icon' => 'fa-solid fa-shield-halved', 'title' => 'Безопасность и лицензии', 'summary' => 'Сетевая безопасность, лицензирование и базовый аудит.', 'details' => 'Для камер, серверов и офисных сетей можно выстроить контроль доступа, план обновлений и правила безопасности.'],
            ],
        ],
        'coverage' => [
            'eyebrow' => 'Покрытие',
            'title' => 'Территории, где мы оказываем услуги в Баку и регионах',
            'subtitle' => 'Зону обслуживания можно посмотреть и в виде списка, и на карте.',
            'note_title' => 'Если ваша территория новая',
            'note_text' => 'Оставьте короткую заявку, и команда сообщит вам о технической возможности и сроке подключения.',
            'areas' => ['Астара', 'Масаллы', 'Ленкорань', 'Нефтчала', 'Исмаиллы', 'Низами', 'Сабунчи', 'Сураханы', 'Абшерон', 'Хатаи', 'Бинагади', 'Кюрдаханы', 'Пиршаги', 'Мехдиабад', 'Мамедли', 'Забрат', 'Гарачухур', 'Бакиханов', 'Ахмедлы', 'Биляджари', 'Гюнешли'],
            'points' => ['Астара', 'Масаллы', 'Ленкорань', 'Нефтчала', 'Исмаиллы', 'Абшерон', 'Баку', 'Сураханы', 'Сабунчи', 'Хатаи', 'Бинагади', 'Гюнешли'],
        ],
        'support' => [
            'eyebrow' => 'Центр поддержки',
            'title' => 'Подробные объяснения и блоки самообслуживания',
            'subtitle' => 'Инструкции по смене пароля для разных модемов, расширенный FAQ и прямые каналы связи собраны здесь.',
            'wifi_title' => 'Сменить пароль Wi-Fi',
            'wifi_text' => 'Панель управления зависит от марки модема, но основные шаги обычно одинаковы.',
            'faq_title' => 'Часто задаваемые вопросы',
            'contact_title' => 'Прямая связь',
            'guides' => [
                ['title' => 'Модемы HSGQ', 'summary' => 'Откройте в браузере `192.168.1.1` или адрес, указанный на устройстве.', 'steps' => ['Откройте раздел Wireless или Wi-Fi.', 'Проверьте отдельно имена SSID для 2.4 GHz и 5 GHz.', 'Задайте новый пароль в формате `WPA2/WPA3` и сохраните его.', 'Подключите устройства заново с новым паролем.']],
                ['title' => 'Модемы Huawei', 'summary' => 'Самый частый адрес входа — `192.168.100.1` или `192.168.1.1`.', 'steps' => ['Перейдите в раздел WLAN.', 'Проверьте отдельно сети 2.4G и 5G.', 'Обновите поля SSID Name и WPA PreSharedKey.', 'После сохранения модем может кратко обновиться.']],
                ['title' => 'Модемы и роутеры TP-Link', 'summary' => 'Войдите через `tplinkwifi.net` или `192.168.0.1` / `192.168.1.1`.', 'steps' => ['Откройте раздел Wireless Settings или Basic Wireless.', 'Если включен Smart Connect, 2.4 и 5 GHz могут иметь одно имя.', 'Обновите имя Wi-Fi и пароль.', 'Если в дальних комнатах слабый сигнал, используйте 2.4 GHz или дополнительный роутер.']],
                ['title' => 'Модемы BDCOM', 'summary' => 'Настройки Wi-Fi обычно находятся в разделе WLAN или Network.', 'steps' => ['Выберите текущий профиль SSID.', 'Оставьте тип безопасности `WPA2-PSK` или `WPA2/WPA3`.', 'Введите новый пароль и примените настройки.', 'Если используется TV Box, меняйте только Wi-Fi часть и не трогайте VLAN.']],
            ],
            'faq' => [
                ['q' => 'Что делать, если скорость интернета снизилась?', 'a' => 'Сначала перезапустите модем на 10-15 секунд, а затем по возможности проверьте скорость по кабелю. Если по Wi-Fi скорость слабая, причиной могут быть толстые стены, загруженный диапазон 2.4 GHz, большое количество устройств или неудачное расположение модема. Если по кабелю все нормально, проблема обычно находится в Wi-Fi среде.'],
                ['q' => 'В чем разница между 2.4 GHz и 5 GHz?', 'a' => '2.4 GHz добивает дальше и легче проходит через стены, но он медленнее и обычно более загружен. 5 GHz дает более высокую скорость и лучшую задержку, но сигнал быстрее слабеет на расстоянии. В ближних комнатах лучше 5 GHz, в дальних — 2.4 GHz.'],
                ['q' => 'Как можно оплатить и что происходит при задержке оплаты?', 'a' => 'Оплату можно внести через онлайн-страницу, банки и партнерские каналы. При задержке оплата услуги может быть ограничена или приостановлена. После оплаты восстановление обычно завершается быстро. Если задержка продолжается, стоит передать команде поддержки чек или дату оплаты.'],
                ['q' => 'Что делать, если я забыл пароль Wi-Fi или сигнал слабый?', 'a' => 'Сначала проверьте исходные данные на наклейке модема. Если пароль был изменен, правильнее задать новый через панель модема. При слабом сигнале модем лучше поставить в центральной точке, подальше от металлических поверхностей и телевизора. В больших домах лучше использовать access point или mesh.'],
            ],
        ],
        'news' => [
            'eyebrow' => 'Новости и обновления',
            'title' => 'Полезная информационная лента для клиентов',
            'subtitle' => 'Аккуратная структура, где отдельно показываются темы интернета, системной безопасности и крипторынка.',
            'tabs' => ['Интернет и сеть', 'Системы и безопасность', 'Обзор крипторынка'],
            'disclaimer' => 'Крипто-раздел носит информационный характер и не является инвестиционной рекомендацией.',
            'items' => [
                ['category' => 'network', 'category_label' => 'Интернет и сеть', 'source' => 'Редакция Super Optik', 'title' => '3 важных проверки дома для стабильной скорости на fiber-подключении', 'summary' => 'Расположение модема, состояние кабеля и выбор канала Wi-Fi напрямую влияют на пользовательский опыт. Для клиентов подготовлен простой чек-лист.', 'date' => '22.04.2026'],
                ['category' => 'network', 'category_label' => 'Интернет и сеть', 'source' => 'Редакция Super Optik', 'title' => 'FTTH и классическое подключение: какой вариант подходит какому пользователю?', 'summary' => 'Практические преимущества fiber-инфраструктуры объясняются простым языком, чтобы клиентам было легче выбрать.', 'date' => '20.04.2026'],
                ['category' => 'systems', 'category_label' => 'Системы и безопасность', 'source' => 'Редакция Super Optik', 'title' => 'Минимальный пакет безопасности для снижения слабых мест в офисной сети', 'summary' => 'Сильная политика паролей, дисциплина резервного копирования и ограничение доступа формируют первый защитный слой для малого бизнеса.', 'date' => '21.04.2026'],
                ['category' => 'systems', 'category_label' => 'Системы и безопасность', 'source' => 'Редакция Super Optik', 'title' => 'Почему план лицензий и обновлений важен для камер, серверов и сетевых устройств?', 'summary' => 'Для устойчивой работы системы важны не только установка, но и обновления с контролем лицензий.', 'date' => '18.04.2026'],
                ['category' => 'crypto', 'category_label' => 'Обзор крипторынка', 'source' => 'Заметка рынка', 'title' => 'Почему при росте волатильности на крипторынке управление рисками должно быть на первом месте?', 'summary' => 'Вместо ценовых прогнозов пользователю напоминают о рисках, новостном фоне и дисциплине решений.', 'date' => '22.04.2026'],
                ['category' => 'crypto', 'category_label' => 'Обзор крипторынка', 'source' => 'Заметка рынка', 'title' => 'Как макро-новости влияют на настроение крипторынка?', 'summary' => 'Ставки, регуляторные новости и условия ликвидности можно показать читателю в виде краткого обзора.', 'date' => '19.04.2026'],
            ],
        ],
        'contact' => [
            'eyebrow' => 'Простая заявка и контакт',
            'title' => 'Свяжитесь с нами напрямую по вопросам подключения, поддержки и бизнес-предложений',
            'text' => 'Один и тот же канал можно использовать для нового подключения, технических проблем, бизнес-интернета и системных проектов.',
        ],
        'footer' => 'Модель сервиса, которая объединяет оптоволоконный интернет, техническую поддержку и системные решения на одной платформе.',
        'popup' => ['label' => 'Рекомендуемый пакет', 'title' => 'Быстрее интернет, комфортнее использование', 'offer' => 'Интернет', 'text' => 'Выберите тариф под ваш сценарий использования и получите более стабильное соединение.', 'dont_show' => 'Больше не показывать'],
    ],
];

$newsItemsOverride = [
    'az' => [
        [
            'category' => 'network',
            'category_label' => 'İnternet və şəbəkə',
            'source' => 'Super Optik redaksiyası',
            'title' => 'Fiber qoşulmada stabil sürət üçün ev daxilində 3 vacib yoxlama',
            'summary' => 'Modem yeri, kabel vəziyyəti və Wi‑Fi kanal seçimi istifadəçi təcrübəsinə birbaşa təsir edir. Sadə yoxlama siyahısı ilə problemi tez ayırd etmək olur.',
            'date' => '24.04.2026',
            'image' => 'images/news-network-expansion.png',
            'tone' => 'teal',
            'featured' => true,
        ],
        [
            'category' => 'network',
            'category_label' => 'İnternet və şəbəkə',
            'source' => 'Super Optik redaksiyası',
            'title' => 'Evdə Wi‑Fi routerini hara qoymaq daha düzgündür?',
            'summary' => 'Routeri döşəməyə, küncə və ya televizor arxasına qoymaq siqnalı zəiflədir. Açıq və mərkəzi nöqtə seçimi eyni paketin real sürətinə ciddi təsir edir.',
            'date' => '24.04.2026',
            'image' => 'images/news-router-placement.svg',
            'tone' => 'blue',
        ],
        [
            'category' => 'network',
            'category_label' => 'İnternet və şəbəkə',
            'source' => 'Super Optik redaksiyası',
            'title' => 'Beton divarlar Wi‑Fi siqnalını niyə zəiflədir?',
            'summary' => 'Dəmir-beton və qalın arakəsmələr 5 GHz siqnalını daha tez saxlayır. Bəzən problem sürətdə yox, fiziki mühitdə olur və həll access point ilə gəlir.',
            'date' => '23.04.2026',
            'image' => 'images/news-concrete-wifi.svg',
            'tone' => 'amber',
        ],
        [
            'category' => 'network',
            'category_label' => 'İnternet və şəbəkə',
            'source' => 'Super Optik redaksiyası',
            'title' => 'Televizor, mikrodalğalı soba və digər cihazlar Wi‑Fi-yə necə mane olur?',
            'summary' => 'Bluetooth cihazları, mikrodalğalı soba, IP kamera və bəzi smart TV-lər 2.4 GHz mühitini yükləyə bilər. Router kanalı və yerləşmə düzgün seçilməlidir.',
            'date' => '23.04.2026',
            'image' => 'images/news-device-interference.svg',
            'tone' => 'violet',
        ],
        [
            'category' => 'network',
            'category_label' => 'İnternet və şəbəkə',
            'source' => 'Super Optik redaksiyası',
            'title' => 'Neçə cihaz üçün neçə Mbit/s kifayətdir?',
            'summary' => '2 telefon, 1 smart TV və 1 noutbuk olan ev ilə 10 cihazlı ailənin ehtiyacı eyni deyil. Paket seçərkən yalnız qiymətə yox, eyni anda işləyən cihaz sayına baxmaq lazımdır.',
            'date' => '22.04.2026',
            'image' => 'images/news-speed-capacity.svg',
            'tone' => 'teal',
        ],
        [
            'category' => 'network',
            'category_label' => 'İnternet və şəbəkə',
            'source' => 'Super Optik redaksiyası',
            'title' => 'FTTH və klassik qoşulma arasında fərq: hansı istifadəçiyə hansı model uyğundur?',
            'summary' => 'Fiber xəttin evə qədər gəlməsi gecikmə, stabilik və gələcək sürət artımı baxımından üstünlük verir. Praktik fərqləri sadə dildə müqayisə edirik.',
            'date' => '20.04.2026',
            'image' => 'images/optical.jpg',
            'tone' => 'amber',
        ],
        [
            'category' => 'systems',
            'category_label' => 'Sistem və təhlükəsizlik',
            'source' => 'Super Optik redaksiyası',
            'title' => 'Ofis şəbəkəsində zəif nöqtələri azaltmaq üçün minimal təhlükəsizlik paketi',
            'summary' => 'Güclü parol siyasəti, ehtiyat nüsxə intizamı və girişlərin məhdudlaşdırılması kiçik biznes üçün ilk qoruma qatını yaradır.',
            'date' => '21.04.2026',
            'image' => 'images/news-systems-security.png',
            'tone' => 'blue',
            'featured' => true,
        ],
        [
            'category' => 'systems',
            'category_label' => 'Sistem və təhlükəsizlik',
            'source' => 'Super Optik redaksiyası',
            'title' => 'Kamera, server və şəbəkə cihazları üçün lisenziya və yeniləmə planı niyə vacibdir?',
            'summary' => 'Yalnız qurulum deyil, müntəzəm yenilənmə və lisenziya nəzarəti də dayanıqılı sistem işinin vacib hissəsidir.',
            'date' => '18.04.2026',
            'image' => 'images/serverinstall.jpg',
            'tone' => 'violet',
        ],
        [
            'category' => 'systems',
            'category_label' => 'Sistem və təhlükəsizlik',
            'source' => 'Super Optik redaksiyası',
            'title' => 'Router admin panelində şifrəni dəyişmək niyə vacibdir?',
            'summary' => 'Defolt admin şifrəsi dəyişmədikdə ev və ofis şəbəkəsi açıq risk altında qalır. Bir neçə sadə addım həm routeri, həm də Wi‑Fi ayarlarını qoruyur.',
            'date' => '17.04.2026',
            'image' => 'images/licensing.jpg',
            'tone' => 'amber',
        ],
    ],
    'en' => [
        [
            'category' => 'network',
            'category_label' => 'Internet and network',
            'source' => 'Super Optik editorial',
            'title' => '3 important in-home checks for stable speed on fiber service',
            'summary' => 'Modem placement, cable condition and Wi‑Fi channel choice directly affect user experience. A short checklist helps separate line issues from home setup issues.',
            'date' => '24.04.2026',
            'image' => 'images/news-network-expansion.png',
            'tone' => 'teal',
            'featured' => true,
        ],
        [
            'category' => 'network',
            'category_label' => 'Internet and network',
            'source' => 'Super Optik editorial',
            'title' => 'Where is the best place to put a Wi‑Fi router at home?',
            'summary' => 'Putting the router on the floor, in a corner or behind the TV reduces coverage. A central and open placement often improves the real speed of the same package.',
            'date' => '24.04.2026',
            'image' => 'images/news-router-placement.svg',
            'tone' => 'blue',
        ],
        [
            'category' => 'network',
            'category_label' => 'Internet and network',
            'source' => 'Super Optik editorial',
            'title' => 'Why do concrete walls weaken Wi‑Fi signal?',
            'summary' => 'Reinforced concrete and dense walls absorb 5 GHz signal much faster. In many homes the real issue is not the tariff, but the physical environment around the router.',
            'date' => '23.04.2026',
            'image' => 'images/news-concrete-wifi.svg',
            'tone' => 'amber',
        ],
        [
            'category' => 'network',
            'category_label' => 'Internet and network',
            'source' => 'Super Optik editorial',
            'title' => 'How do TVs, microwaves and other devices interfere with Wi‑Fi?',
            'summary' => 'Bluetooth accessories, microwave ovens, wireless cameras and some smart TVs can crowd the 2.4 GHz space. Router channel and placement choices matter more than many users expect.',
            'date' => '23.04.2026',
            'image' => 'images/news-device-interference.svg',
            'tone' => 'violet',
        ],
        [
            'category' => 'network',
            'category_label' => 'Internet and network',
            'source' => 'Super Optik editorial',
            'title' => 'How many Mbps are enough for how many devices?',
            'summary' => 'A flat with two phones and one TV does not need the same plan as a busy home with ten devices. Choosing the right speed depends on simultaneous usage, not just on price.',
            'date' => '22.04.2026',
            'image' => 'images/news-speed-capacity.svg',
            'tone' => 'teal',
        ],
        [
            'category' => 'network',
            'category_label' => 'Internet and network',
            'source' => 'Super Optik editorial',
            'title' => 'FTTH vs classic connection: which model fits which user?',
            'summary' => 'Fiber to the home gives better stability, lower latency and more room for future speed upgrades. We compare the practical differences in plain language.',
            'date' => '20.04.2026',
            'image' => 'images/optical.jpg',
            'tone' => 'amber',
        ],
        [
            'category' => 'systems',
            'category_label' => 'Systems and security',
            'source' => 'Super Optik editorial',
            'title' => 'A minimal security package to reduce weak points in office networks',
            'summary' => 'Strong password policy, backup discipline and limited access create the first practical protection layer for small businesses.',
            'date' => '21.04.2026',
            'image' => 'images/news-systems-security.png',
            'tone' => 'blue',
            'featured' => true,
        ],
        [
            'category' => 'systems',
            'category_label' => 'Systems and security',
            'source' => 'Super Optik editorial',
            'title' => 'Why are licensing and update plans important for cameras, servers and network devices?',
            'summary' => 'Stable system operation depends not only on installation, but also on updates, patch cycles and controlled licensing.',
            'date' => '18.04.2026',
            'image' => 'images/serverinstall.jpg',
            'tone' => 'violet',
        ],
        [
            'category' => 'systems',
            'category_label' => 'Systems and security',
            'source' => 'Super Optik editorial',
            'title' => 'Why should you change the default password in the router admin panel?',
            'summary' => 'Leaving the default admin password unchanged keeps both home and office networks exposed. A few simple steps protect Wi‑Fi settings and device access.',
            'date' => '17.04.2026',
            'image' => 'images/licensing.jpg',
            'tone' => 'amber',
        ],
    ],
    'ru' => [
        [
            'category' => 'network',
            'category_label' => 'Интернет и сеть',
            'source' => 'Редакция Super Optik',
            'title' => '3 важные домашние проверки для стабильной скорости на fiber-подключении',
            'summary' => 'Расположение модема, состояние кабеля и выбор канала Wi‑Fi напрямую влияют на пользовательский опыт. Короткий чек-лист помогает быстро понять источник проблемы.',
            'date' => '24.04.2026',
            'image' => 'images/news-network-expansion.png',
            'tone' => 'teal',
            'featured' => true,
        ],
        [
            'category' => 'network',
            'category_label' => 'Интернет и сеть',
            'source' => 'Редакция Super Optik',
            'title' => 'Где дома лучше поставить Wi‑Fi роутер?',
            'summary' => 'Если роутер стоит на полу, в углу или за телевизором, покрытие заметно падает. Открытая и центральная точка часто дает реальный прирост качества без смены тарифа.',
            'date' => '24.04.2026',
            'image' => 'images/news-router-placement.svg',
            'tone' => 'blue',
        ],
        [
            'category' => 'network',
            'category_label' => 'Интернет и сеть',
            'source' => 'Редакция Super Optik',
            'title' => 'Почему бетонные стены ослабляют сигнал Wi‑Fi?',
            'summary' => 'Железобетон и плотные перегородки особенно быстро гасят 5 GHz сигнал. Во многих квартирах проблема не в тарифе, а в физической среде вокруг роутера.',
            'date' => '23.04.2026',
            'image' => 'images/news-concrete-wifi.svg',
            'tone' => 'amber',
        ],
        [
            'category' => 'network',
            'category_label' => 'Интернет и сеть',
            'source' => 'Редакция Super Optik',
            'title' => 'Как телевизоры, микроволновки и другие устройства мешают Wi‑Fi?',
            'summary' => 'Bluetooth-аксессуары, микроволновки, беспроводные камеры и некоторые smart TV загружают диапазон 2.4 GHz. Канал роутера и его расположение здесь критичны.',
            'date' => '23.04.2026',
            'image' => 'images/news-device-interference.svg',
            'tone' => 'violet',
        ],
        [
            'category' => 'network',
            'category_label' => 'Интернет и сеть',
            'source' => 'Редакция Super Optik',
            'title' => 'Сколько Mbps достаточно для какого количества устройств?',
            'summary' => 'Квартира с двумя телефонами и одним телевизором не требует того же пакета, что и дом с десятью активными устройствами. Скорость нужно выбирать по одновременной нагрузке.',
            'date' => '22.04.2026',
            'image' => 'images/news-speed-capacity.svg',
            'tone' => 'teal',
        ],
        [
            'category' => 'network',
            'category_label' => 'Интернет и сеть',
            'source' => 'Редакция Super Optik',
            'title' => 'FTTH и классическое подключение: какой вариант кому подходит?',
            'summary' => 'Fiber до квартиры дает лучшую стабильность, меньшую задержку и запас по будущим скоростям. Сравниваем практические различия простым языком.',
            'date' => '20.04.2026',
            'image' => 'images/optical.jpg',
            'tone' => 'amber',
        ],
        [
            'category' => 'systems',
            'category_label' => 'Системы и безопасность',
            'source' => 'Редакция Super Optik',
            'title' => 'Минимальный пакет безопасности для снижения слабых мест в офисной сети',
            'summary' => 'Сильная парольная политика, резервное копирование и ограничение доступа создают первый практический слой защиты для малого бизнеса.',
            'date' => '21.04.2026',
            'image' => 'images/news-systems-security.png',
            'tone' => 'blue',
            'featured' => true,
        ],
        [
            'category' => 'systems',
            'category_label' => 'Системы и безопасность',
            'source' => 'Редакция Super Optik',
            'title' => 'Почему важен план лицензий и обновлений для камер, серверов и сетевых устройств?',
            'summary' => 'Для устойчивой работы системы нужны не только установка, но и обновления, патчи и контроль лицензий.',
            'date' => '18.04.2026',
            'image' => 'images/serverinstall.jpg',
            'tone' => 'violet',
        ],
        [
            'category' => 'systems',
            'category_label' => 'Системы и безопасность',
            'source' => 'Редакция Super Optik',
            'title' => 'Почему важно менять пароль по умолчанию в панели роутера?',
            'summary' => 'Если оставить стандартный admin-пароль, и домашняя, и офисная сеть остаются под лишним риском. Простая замена защищает настройки и доступ к устройству.',
            'date' => '17.04.2026',
            'image' => 'images/licensing.jpg',
            'tone' => 'amber',
        ],
    ],
];

foreach ($newsItemsOverride as $locale => $items) {
    $content[$locale]['news']['items'] = $items;
}

$copy = $content[$lang] ?? $content['az'];
$siteUrl = rtrim((string) app_config('site_url', 'https://superoptic.net'), '/');
$currentPath = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
$canonicalUrl = $siteUrl . ($currentPath === '/' ? '' : $currentPath);
$seoMeta = [
    'az' => [
        'title' => 'Super Optik | İnternet, İnternet TV, telefon və yüksək sürət',
        'description' => 'Super Optik Bakıda və bölgələrdə yüksək sürətli fiber internet, internet TV, telefon xidməti, biznes interneti, IT dəstək, server və şəbəkə həlləri təqdim edir.',
        'keywords' => 'internet, internet tv, telefon, yüksək sürət, yüksək sürətli internet, sürətli internet, fiber internet, ev interneti, biznes interneti, tv xidməti, ip telefoniya, telefon xidməti, it dəstək, it support, proqram təminatı, software, server, şəbəkə, lan wan, super optik, super optik tech, superoptic, superoptic.net',
        'service_type' => 'İnternet, internet TV, telefon və yüksək sürətli fiber həllər',
        'locale' => 'az_AZ',
    ],
    'en' => [
        'title' => 'Super Optik | Internet, Internet TV, phone and high speed',
        'description' => 'Super Optik provides high-speed fiber internet, internet TV, phone service, business connectivity, IT support, server and network solutions in Baku and the regions.',
        'keywords' => 'internet, internet tv, phone, telephone, high speed, high speed internet, fast internet, fiber internet, home internet, business internet, tv service, VoIP, IP telephony, IT support, software, server, network solutions, LAN WAN, Super Optik, Super Optik Tech, superoptic, superoptic.net',
        'service_type' => 'Internet, internet TV, phone and high-speed fiber solutions',
        'locale' => 'en_US',
    ],
    'ru' => [
        'title' => 'Super Optik | Интернет, интернет ТВ, телефон и высокая скорость',
        'description' => 'Super Optik предоставляет высокоскоростной оптоволоконный интернет, интернет ТВ, телефонию, бизнес-интернет, IT-поддержку, серверные и сетевые решения в Баку и регионах.',
        'keywords' => 'интернет, интернет тв, телефон, высокая скорость, высокоскоростной интернет, быстрый интернет, оптоволоконный интернет, домашний интернет, бизнес интернет, тв, ip телефония, телефония, it поддержка, программное обеспечение, сервер, сеть, Super Optik, Super Optik Tech, superoptic, superoptic.net',
        'service_type' => 'Интернет, интернет ТВ, телефон и высокоскоростные оптоволоконные решения',
        'locale' => 'ru_RU',
    ],
];
$seo = $seoMeta[$lang] ?? $seoMeta['az'];
$seoImage = $siteUrl . '/images/news-network-expansion.png';
$schemaGraph = [
    [
        '@type' => 'Organization',
        '@id' => $siteUrl . '/#organization',
        'name' => 'Super Optik',
        'url' => $siteUrl,
        'logo' => $siteUrl . '/images/favicon.png',
        'sameAs' => ['https://www.instagram.com/superoptiktech/'],
    ],
    [
        '@type' => 'WebSite',
        '@id' => $siteUrl . '/#website',
        'name' => 'Super Optik',
        'url' => $siteUrl,
        'inLanguage' => $lang,
    ],
    [
        '@type' => 'TelecommunicationsService',
        '@id' => $siteUrl . '/#service',
        'name' => 'Super Optik Services',
        'serviceType' => $seo['service_type'],
        'provider' => ['@id' => $siteUrl . '/#organization'],
        'areaServed' => 'Azerbaijan',
        'description' => $seo['description'],
        'keywords' => $seo['keywords'],
    ],
    [
        '@type' => 'FAQPage',
        '@id' => $siteUrl . '/#faq',
        'mainEntity' => array_map(static function (array $faq): array {
            return [
                '@type' => 'Question',
                'name' => (string) $faq['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => (string) $faq['a'],
                ],
            ];
        }, $copy['support']['faq']),
    ],
];
$flashMap = [
    'az' => [
        'request_sent' => ['type' => 'success', 'message' => 'Müraciətiniz qəbul edildi. Komandamız sizinlə qısa zamanda əlaqə saxlayacaq.'],
        'request_failed' => ['type' => 'error', 'message' => 'Müraciət göndərilərkən problem yarandı. Zəhmət olmasa məlumatları yenidən yoxlayın.'],
        'captcha_failed' => ['type' => 'error', 'message' => 'Robot yoxlaması tamamlanmadı və ya uğursuz oldu.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Sessiya yoxlaması uğursuz oldu. Səhifəni yeniləyib yenidən cəhd edin.'],
        'rate_limited' => ['type' => 'error', 'message' => 'Çox qısa vaxtda həddən artıq müraciət göndərildi. Bir neçə dəqiqə sonra yenidən cəhd edin.'],
        'missing_config' => ['type' => 'error', 'message' => 'Server konfiqurasiyası tamamlanmayıb. Config faylını doldurmaq lazımdır.'],
    ],
    'en' => [
        'request_sent' => ['type' => 'success', 'message' => 'Your request was received. Our team will contact you shortly.'],
        'request_failed' => ['type' => 'error', 'message' => 'There was a problem sending your request. Please review the details and try again.'],
        'captcha_failed' => ['type' => 'error', 'message' => 'Robot verification was not completed or failed.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Session verification failed. Refresh the page and try again.'],
        'rate_limited' => ['type' => 'error', 'message' => 'Too many requests were sent in a short time. Please wait a few minutes and try again.'],
        'missing_config' => ['type' => 'error', 'message' => 'Server configuration is incomplete. Please fill in the config file.'],
    ],
    'ru' => [
        'request_sent' => ['type' => 'success', 'message' => 'Ваше обращение принято. Наша команда свяжется с вами в ближайшее время.'],
        'request_failed' => ['type' => 'error', 'message' => 'При отправке обращения возникла проблема. Пожалуйста, проверьте данные и попробуйте снова.'],
        'captcha_failed' => ['type' => 'error', 'message' => 'Проверка робота не была завершена или завершилась неуспешно.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Проверка сессии не прошла. Обновите страницу и попробуйте снова.'],
        'rate_limited' => ['type' => 'error', 'message' => 'За короткое время отправлено слишком много запросов. Подождите несколько минут и попробуйте снова.'],
        'missing_config' => ['type' => 'error', 'message' => 'Конфигурация сервера не завершена. Необходимо заполнить config-файл.'],
    ],
];
if (isset($flashMap[$lang][$status])) {
    $flash = $flashMap[$lang][$status];
}
if ($status === 'area_interest_saved') {
    $flash = [
        'type' => 'success',
        'message' => [
            'az' => 'Seçdiyiniz rayon qeyd edildi. Aylıq maraq statistikası yeniləndi.',
            'en' => 'Your selected district was saved. Monthly interest statistics were updated.',
            'ru' => 'Выбранный район сохранен. Ежемесячная статистика интереса обновлена.',
        ][$lang] ?? 'District saved.',
    ];
} elseif ($status === 'area_interest_failed') {
    $flash = [
        'type' => 'error',
        'message' => [
            'az' => 'Rayon seçimi qeydə alınmadı. Zəhmət olmasa yenidən cəhd edin.',
            'en' => 'The district selection could not be saved. Please try again.',
            'ru' => 'Не удалось сохранить выбранный район. Пожалуйста, попробуйте снова.',
        ][$lang] ?? 'District save failed.',
    ];
}
$districtOptions = app_azerbaijan_districts();
if (!in_array('Marağa', $districtOptions, true)) {
    $districtOptions[] = 'Marağa';
}
$areaInterestSummary = app_area_interest_monthly_summary();
$tvChannels = app_tv_channels();
$coveragePanel = [
    'az' => [
        'service_tag' => 'Aktiv xidmət nöqtəsi',
        'search_label' => 'Rayon axtarışı',
        'search_placeholder' => 'Hansı rayonda xidmət var?',
        'search_empty' => 'Axtarışa uyğun aktiv rayon tapılmadı.',
        'interest_title' => 'Yeni əhatə planı üçün rayon qeyd et',
        'interest_text' => 'Əhatədə olmayan rayonu seçin. Toplanan sorğular aylıq planlamaya düşür və texniki imkan uyğun olduqda yeni qoşulma prioritetinə daxil edilir.',
        'interest_label' => 'Rayon seçimi',
        'interest_placeholder' => 'Azərbaycan rayonunu seçin',
        'interest_action' => 'Qeyd saxla',
        'stats_title' => 'Bu ay ən çox soruşulan rayonlar',
        'stats_total' => 'Toplam müraciət',
        'stats_empty' => 'Bu ay üçün hələ qeyd yoxdur.',
    ],
    'en' => [
        'service_tag' => 'Active service point',
        'search_label' => 'District search',
        'search_placeholder' => 'Where are we available?',
        'search_empty' => 'No active district matched your search.',
        'interest_title' => 'Add a district to the rollout plan',
        'interest_text' => 'Select a district outside current coverage. Collected requests are tracked monthly and added to expansion priorities when technical conditions allow.',
        'interest_label' => 'District selection',
        'interest_placeholder' => 'Select a district in Azerbaijan',
        'interest_action' => 'Save request',
        'stats_title' => 'Most requested districts this month',
        'stats_total' => 'Total requests',
        'stats_empty' => 'No district requests have been recorded this month yet.',
    ],
    'ru' => [
        'service_tag' => 'Активная точка услуги',
        'search_label' => 'Поиск района',
        'search_placeholder' => 'Где есть покрытие?',
        'search_empty' => 'Активный район по запросу не найден.',
        'interest_title' => 'Добавьте район в план расширения',
        'interest_text' => 'Выберите район вне текущего покрытия. Собранные запросы учитываются в ежемесячном плане и добавляются в приоритет при наличии технической возможности.',
        'interest_label' => 'Выбор района',
        'interest_placeholder' => 'Выберите район Азербайджана',
        'interest_action' => 'Сохранить',
        'stats_title' => 'Самые запрашиваемые районы в этом месяце',
        'stats_total' => 'Всего запросов',
        'stats_empty' => 'В этом месяце пока нет сохраненных запросов.',
    ],
];
$supportGuides = [
    [
        'title' => ['az' => 'BDCOM modemləri', 'en' => 'BDCOM modems', 'ru' => 'Модемы BDCOM'],
        'summary' => [
            'az' => 'BDCOM modemlərində Wi-Fi ayarına daxil olmaq üçün `192.168.123.1` ünvanından istifadə edin.',
            'en' => 'Use `192.168.123.1` to access Wi-Fi settings on BDCOM modems.',
            'ru' => 'Для входа в настройки Wi-Fi на модемах BDCOM используйте `192.168.123.1`.',
        ],
        'steps' => [
            ['az' => 'Brauzerdə `192.168.123.1` ünvanını açın.', 'en' => 'Open `192.168.123.1` in your browser.', 'ru' => 'Откройте `192.168.123.1` в браузере.'],
            ['az' => 'İstifadəçi adı olaraq `admin`, şifrə olaraq `super&123` daxil edin.', 'en' => 'Enter `admin` as username and `super&123` as password.', 'ru' => 'Введите логин `admin` и пароль `super&123`.'],
            ['az' => 'WLAN və ya Wi-Fi bölməsinə keçin.', 'en' => 'Open the WLAN or Wi-Fi section.', 'ru' => 'Перейдите в раздел WLAN или Wi-Fi.'],
            ['az' => 'SSID və şifrə hissəsində yeni Wi-Fi adını və şifrəsini yazın.', 'en' => 'Update the SSID and password fields with the new Wi-Fi name and password.', 'ru' => 'Обновите поля SSID и пароля новым именем и паролем Wi-Fi.'],
            ['az' => '`Apply` və ya `Save` düyməsini sıxın və cihazları yenidən qoşun.', 'en' => 'Click `Apply` or `Save` and reconnect your devices.', 'ru' => 'Нажмите `Apply` или `Save` и заново подключите устройства.'],
        ],
    ],
    [
        'title' => ['az' => 'HSGQ modemləri', 'en' => 'HSGQ modems', 'ru' => 'Модемы HSGQ'],
        'summary' => [
            'az' => 'HSGQ modemlərində giriş məlumatları sabitdir və bir neçə addımda Wi-Fi şifrəsini dəyişmək olur.',
            'en' => 'HSGQ modems use fixed login details and the Wi-Fi password can be changed in a few steps.',
            'ru' => 'В модемах HSGQ используются фиксированные данные входа, а пароль Wi-Fi меняется за несколько шагов.',
        ],
        'steps' => [
            ['az' => 'Brauzerdə `192.168.1.1` ünvanını açın.', 'en' => 'Open `192.168.1.1` in your browser.', 'ru' => 'Откройте `192.168.1.1` в браузере.'],
            ['az' => 'İstifadəçi adı `telecomadmin`, şifrə `admintelecom` olaraq daxil olun.', 'en' => 'Log in with username `telecomadmin` and password `admintelecom`.', 'ru' => 'Войдите с логином `telecomadmin` и паролем `admintelecom`.'],
            ['az' => 'Wireless və ya WLAN bölməsini açın.', 'en' => 'Open the Wireless or WLAN section.', 'ru' => 'Откройте раздел Wireless или WLAN.'],
            ['az' => '2.4 GHz və 5 GHz üçün ayrıca SSID və WPA açarını yeniləyin.', 'en' => 'Update the SSID and WPA key separately for 2.4 GHz and 5 GHz.', 'ru' => 'Обновите SSID и WPA-ключ отдельно для 2.4 GHz и 5 GHz.'],
            ['az' => '`Apply` düyməsini sıxdıqdan sonra cihazları yeni şifrə ilə yenidən qoşun.', 'en' => 'After pressing `Apply`, reconnect devices with the new password.', 'ru' => 'После нажатия `Apply` подключите устройства заново с новым паролем.'],
        ],
    ],
    [
        'title' => ['az' => 'TP-Link modem və routerlər', 'en' => 'TP-Link modems and routers', 'ru' => 'Модемы и роутеры TP-Link'],
        'summary' => [
            'az' => 'TP-Link cihazlarında qısa panel yolu ilə Wi-Fi adı və şifrəsi dəyişdirilir.',
            'en' => 'TP-Link devices let you change the Wi-Fi name and password through a short control path.',
            'ru' => 'На устройствах TP-Link имя и пароль Wi-Fi меняются через короткий путь в панели управления.',
        ],
        'steps' => [
            ['az' => 'Brauzerdə `192.168.1.1` ünvanını açın.', 'en' => 'Open `192.168.1.1` in your browser.', 'ru' => 'Откройте `192.168.1.1` в браузере.'],
            ['az' => 'İstifadəçi adı `admin`, şifrə `admin` olaraq daxil olun.', 'en' => 'Log in with `admin / admin`.', 'ru' => 'Войдите с данными `admin / admin`.'],
            ['az' => 'Wireless Settings və ya Basic Wireless bölməsinə keçin.', 'en' => 'Go to Wireless Settings or Basic Wireless.', 'ru' => 'Перейдите в Wireless Settings или Basic Wireless.'],
            ['az' => 'Wi-Fi adı və şifrəsini yeniləyin.', 'en' => 'Update the Wi-Fi name and password.', 'ru' => 'Обновите имя и пароль Wi-Fi.'],
            ['az' => '`Save` düyməsini sıxın və cihazları yenidən qoşun.', 'en' => 'Click `Save` and reconnect your devices.', 'ru' => 'Нажмите `Save` и подключите устройства заново.'],
        ],
    ],
    [
        'title' => ['az' => 'Huawei modemləri', 'en' => 'Huawei modems', 'ru' => 'Модемы Huawei'],
        'summary' => [
            'az' => 'Huawei modemlərində həm 2.4 GHz, həm də 5 GHz üçün ayrıca bölmədən Wi-Fi adı və şifrəsi dəyişdirilir.',
            'en' => 'On Huawei modems, the Wi-Fi name and password are updated from separate 2.4 GHz and 5 GHz sections.',
            'ru' => 'На модемах Huawei имя и пароль Wi-Fi меняются из отдельных разделов для 2.4 GHz и 5 GHz.',
        ],
        'steps' => [
            ['az' => 'Wi-Fi-ya qoşulu cihazla brauzeri açıb `192.168.100.1` ünvanına daxil olun.', 'en' => 'Open a browser on a device connected to Wi-Fi and go to `192.168.100.1`.', 'ru' => 'На устройстве, подключенном к Wi-Fi, откройте браузер и перейдите на `192.168.100.1`.'],
            ['az' => 'İstifadəçi adı `telecomadmin`, şifrə `admintelecom` yazın.', 'en' => 'Use `telecomadmin` as username and `admintelecom` as password.', 'ru' => 'Введите логин `telecomadmin` и пароль `admintelecom`.'],
            ['az' => 'Sol menyudan `Advanced`, sonra `WLAN`, daha sonra `2.4G Basic Network` bölməsinə keçin.', 'en' => 'Open `Advanced`, then `WLAN`, and then `2.4G Basic Network` from the left menu.', 'ru' => 'Откройте `Advanced`, затем `WLAN`, а после этого `2.4G Basic Network` в левом меню.'],
            ['az' => '`SSID Name` sətirində Wi-Fi adını, `WPA PreShareKey` sətirində şifrəni dəyişin və `Apply` düyməsini sıxın.', 'en' => 'Change the Wi-Fi name in `SSID Name`, update the password in `WPA PreShareKey`, and click `Apply`.', 'ru' => 'Измените имя Wi-Fi в `SSID Name`, пароль в `WPA PreShareKey` и нажмите `Apply`.'],
            ['az' => 'Cihaz ayrıldıqdan sonra yenidən qoşulun və eyni addımla `5G Basic Network` bölməsində 5 GHz ayarını da yeniləyin.', 'en' => 'After the device reconnects, repeat the same steps in `5G Basic Network` for the 5 GHz settings.', 'ru' => 'После повторного подключения выполните те же шаги в разделе `5G Basic Network` для настроек 5 GHz.'],
        ],
    ],
];
$tvSection = [
    'az' => [
        'eyebrow' => 'TVNET kanalları',
        'title' => 'TVNET vasitəsilə təqdim olunan TV kanalları',
        'subtitle' => 'Kanal siyahısı TVNET-in açıq kanal səhifəsinə əsasən yığılır. Lent avtomatik hərəkət edir, siz isə istədiyiniz kanalı axtarıb görə bilərsiniz.',
        'search_label' => 'Kanal axtarışı',
        'search_placeholder' => 'Məsələn: CBC Sport, AzTV, Setanta...',
        'package_label' => 'Paket',
        'source_note' => 'Kanallar TVNET vasitəsilə təqdim olunur.',
        'empty' => 'Axtarışa uyğun kanal tapılmadı.',
    ],
    'en' => [
        'eyebrow' => 'TVNET channels',
        'title' => 'TV channels delivered through TVNET',
        'subtitle' => 'The list is seeded from TVNET’s public channel page. The carousel moves automatically and you can search any channel by name.',
        'search_label' => 'Channel search',
        'search_placeholder' => 'For example: CBC Sport, AzTV, Setanta...',
        'package_label' => 'Package',
        'source_note' => 'Channels are provided via TVNET.',
        'empty' => 'No channels matched your search.',
    ],
    'ru' => [
        'eyebrow' => 'Каналы TVNET',
        'title' => 'ТВ-каналы, предоставляемые через TVNET',
        'subtitle' => 'Список основан на открытой странице каналов TVNET. Лента движется автоматически, а нужный канал можно найти через поиск.',
        'search_label' => 'Поиск канала',
        'search_placeholder' => 'Например: CBC Sport, AzTV, Setanta...',
        'package_label' => 'Пакет',
        'source_note' => 'Каналы предоставляются через TVNET.',
        'empty' => 'По вашему запросу каналы не найдены.',
    ],
];
$sectionLabels = [
    'tv' => ['az' => 'TV', 'en' => 'TV', 'ru' => 'ТВ'],
    'payments' => ['az' => 'Ödəniş', 'en' => 'Pay', 'ru' => 'Оплата'],
];
$paymentSection = [
    'az' => [
        'eyebrow' => 'Onlayn ödənişlər',
        'title' => 'Müştərilərimiz bu ödəniş sistemlərindən rahat istifadə edə bilir',
        'subtitle' => 'Onlayn ödəniş, tətbiq və terminal tərəfdaşlarımızı bir yerdə göstəririk ki, abonent ödənişi daha rahat seçsin.',
    ],
    'en' => [
        'eyebrow' => 'Online payments',
        'title' => 'Our customers can pay comfortably through these systems',
        'subtitle' => 'We show our online, app and terminal payment partners together so subscribers can choose the easiest payment path.',
    ],
    'ru' => [
        'eyebrow' => 'Онлайн-оплаты',
        'title' => 'Наши клиенты могут удобно оплачивать через эти системы',
        'subtitle' => 'Мы показываем онлайн, мобильных и терминальных партнеров в одном месте, чтобы абоненту было проще выбрать оплату.',
    ],
];
$newsVisuals = [
    ['image' => 'images/news-network-expansion.png', 'tone' => 'teal'],
    ['image' => 'images/news-systems-security.png', 'tone' => 'amber'],
    ['image' => 'images/news-crypto-update.png', 'tone' => 'violet'],
    ['image' => 'images/news-wifi-support.png', 'tone' => 'blue'],
    ['image' => 'images/news-business-connectivity.png', 'tone' => 'teal'],
    ['image' => 'images/news-support-updates.png', 'tone' => 'amber'],
];
$paymentProviders = [
    ['name' => 'eManat+', 'accent' => 'light', 'logo' => 'images/payment-emanat.svg', 'href' => 'https://emanat.az/', 'domain' => 'emanat.az'],
    ['name' => 'HESAB.AZ', 'accent' => 'cyan', 'logo' => 'images/payment-hesab.svg', 'href' => 'https://hesab.az/', 'domain' => 'hesab.az'],
    ['name' => 'million', 'accent' => 'red', 'logo' => 'images/payment-million.svg', 'href' => app_config('payment_url', 'https://www.million.az/services/internet/Super%20Optik'), 'domain' => 'million.az'],
    ['name' => 'BirBank', 'accent' => 'magenta', 'logo' => 'images/payment-birbank.png', 'href' => 'https://birbank.az/', 'domain' => 'birbank.az'],
    ['name' => 'IBA mobile', 'accent' => 'blue', 'logo' => 'images/payment-iba.svg', 'href' => 'https://ibam.az/', 'domain' => 'ibam.az'],
];
$paymentLinkCopy = [
    'az' => ['cta' => 'Sayta keç', 'note' => 'Rəsmi ödəniş keçidi'],
    'en' => ['cta' => 'Open site', 'note' => 'Official payment link'],
    'ru' => ['cta' => 'Перейти на сайт', 'note' => 'Официальная ссылка на оплату'],
];
$salesHero = [
    'az' => [
        'eyebrow' => 'Fiber internet, TV və IT dəstək',
        'title' => '100 Mbit fiber internet 25 AZN-dən',
        'text' => 'Ev üçün 25 AZN, biznes üçün 30 AZN. Əhatəni yoxlayın, telefon nömrəsini göndərin, qoşulma vaxtını geri bildirək.',
        'actions' => ['Əhatəni yoxla', 'Tarif seç'],
        'proofs' => [
            ['value' => '1 gün', 'label' => 'qoşulma planı'],
            ['value' => '24/7', 'label' => 'texniki dəstək'],
            ['value' => 'TV +6', 'label' => 'fərdi əlavə'],
        ],
        'quick_title' => '1 klik qoşulma yoxlaması',
        'quick_text' => 'Ərazi və telefonu göndərin. Komanda sizə texniki imkan və qoşulma vaxtını geri bildirsin.',
        'default_message' => 'Yeni qoşulma və ya xidmət sorğusu',
        'form_hint' => 'Qısa forma: əvvəlcə ərazi və telefon, sonra komanda sizi yönləndirsin.',
    ],
    'en' => [
        'eyebrow' => 'Fiber internet, TV and IT support',
        'title' => '100 Mbps fiber internet from 25 AZN',
        'text' => '25 AZN for home and 30 AZN for business. Check coverage, send your number and get the connection timing back from our team.',
        'actions' => ['Check coverage', 'Choose tariff'],
        'proofs' => [
            ['value' => '1 day', 'label' => 'connection plan'],
            ['value' => '24/7', 'label' => 'technical support'],
            ['value' => 'TV +6', 'label' => 'home add-on'],
        ],
        'quick_title' => '1-click connection check',
        'quick_text' => 'Send your area and phone number. Our team will return the technical fit and connection timing.',
        'default_message' => 'New connection or service request',
        'form_hint' => 'Short form first: area and phone, then our team guides the next step.',
    ],
    'ru' => [
        'eyebrow' => 'Оптоволоконный интернет, ТВ и IT-поддержка',
        'title' => '100 Мбит fiber-интернета от 25 AZN',
        'text' => '25 AZN для дома и 30 AZN для бизнеса. Проверьте покрытие, отправьте номер и получите срок подключения от нашей команды.',
        'actions' => ['Проверить покрытие', 'Выбрать тариф'],
        'proofs' => [
            ['value' => '1 день', 'label' => 'план подключения'],
            ['value' => '24/7', 'label' => 'техподдержка'],
            ['value' => 'TV +6', 'label' => 'домашняя доплата'],
        ],
        'quick_title' => 'Проверка подключения в 1 клик',
        'quick_text' => 'Отправьте район и номер телефона. Команда ответит по технической возможности и сроку подключения.',
        'default_message' => 'Новая заявка на подключение или услугу',
        'form_hint' => 'Сначала короткая форма: район и телефон, затем команда проведет дальше.',
    ],
];
$featuredOfferSection = [
    'az' => [
        'eyebrow' => 'Əsas seçimlər',
        'title' => 'Qərarı uzatmayan 3 əsas paket',
        'text' => 'Əvvəlcə ən çox satılan paketləri göstəririk. Tam tarif cədvəli aşağıda qalır.',
        'cta' => 'Tam tarif cədvəlinə keç',
        'steps_title' => 'Qoşulma axını',
        'steps' => [
            ['title' => '1. Paket seç', 'text' => 'Ev, ailə və ya ofis üçün uyğun əsas paketi seçin.'],
            ['title' => '2. Ərazini göndər', 'text' => 'Əhatə yoxlanışı üçün rayon və telefon nömrəsini buraxın.'],
            ['title' => '3. Təsdiq al', 'text' => 'Komanda texniki imkan və qoşulma vaxtını dəqiqləşdirsin.'],
            ['title' => '4. Qoşul', 'text' => 'Razılaşmadan sonra quraşdırma və aktivləşmə tamamlanır.'],
        ],
    ],
    'en' => [
        'eyebrow' => 'Core offers',
        'title' => '3 main plans that keep the decision simple',
        'text' => 'We surface the most practical plans first. The full tariff table stays below.',
        'cta' => 'Jump to full tariff table',
        'steps_title' => 'Connection flow',
        'steps' => [
            ['title' => '1. Choose a plan', 'text' => 'Pick the base plan that fits home, family or office usage.'],
            ['title' => '2. Send your area', 'text' => 'Leave your district and phone number for a coverage check.'],
            ['title' => '3. Get confirmation', 'text' => 'Our team confirms technical availability and connection timing.'],
            ['title' => '4. Connect', 'text' => 'After approval, installation and activation are completed.'],
        ],
    ],
    'ru' => [
        'eyebrow' => 'Основные предложения',
        'title' => '3 основных пакета без лишней сложности',
        'text' => 'Сначала показываем самые понятные и продаваемые пакеты. Полная таблица тарифов остается ниже.',
        'cta' => 'Перейти к полной таблице тарифов',
        'steps_title' => 'Сценарий подключения',
        'steps' => [
            ['title' => '1. Выберите пакет', 'text' => 'Подберите базовый пакет для дома, семьи или офиса.'],
            ['title' => '2. Отправьте район', 'text' => 'Оставьте район и номер телефона для проверки покрытия.'],
            ['title' => '3. Получите подтверждение', 'text' => 'Команда подтвердит техвозможность и срок подключения.'],
            ['title' => '4. Подключитесь', 'text' => 'После согласования выполняются установка и активация.'],
        ],
    ],
];
$featuredOffers = [
    [
        'name' => ['az' => 'Fərdi internet', 'en' => 'Home internet', 'ru' => 'Домашний интернет'],
        'speed' => 100,
        'price' => 25,
        'tag' => ['az' => 'Başlanğıc', 'en' => 'Starter', 'ru' => 'Старт'],
        'description' => [
            'az' => 'Gündəlik istifadə, video və ev Wi-Fi üçün baza paket.',
            'en' => 'Base package for daily usage, video and home Wi-Fi.',
            'ru' => 'Базовый пакет для ежедневного использования, видео и домашнего Wi‑Fi.',
        ],
    ],
    [
        'name' => ['az' => 'Fərdi tam paket', 'en' => 'Home full bundle', 'ru' => 'Домашний полный пакет'],
        'speed' => 150,
        'price' => 40,
        'tag' => ['az' => 'Ailə üçün', 'en' => 'Family fit', 'ru' => 'Для семьи'],
        'description' => [
            'az' => 'İnternet, TV və telefonu bir yerdə istəyən ailələr üçün.',
            'en' => 'For families that want internet, TV and phone in one bundle.',
            'ru' => 'Для семей, которым нужен интернет, ТВ и телефон в одном пакете.',
        ],
    ],
    [
        'name' => ['az' => 'Biznes tam paket', 'en' => 'Business full bundle', 'ru' => 'Бизнес полный пакет'],
        'speed' => 100,
        'price' => 50,
        'tag' => ['az' => 'Ofis üçün', 'en' => 'Office ready', 'ru' => 'Для офиса'],
        'description' => [
            'az' => 'Gözləmə zonası TV-si və sabit telefon xətti olan ofis paketi.',
            'en' => 'Office package with waiting-area TV and fixed phone line.',
            'ru' => 'Офисный пакет с ТВ для зоны ожидания и стационарной телефонией.',
        ],
    ],
];
$tvChannelRows = [[], [], []];
foreach ($tvChannels as $index => $channel) {
    $tvChannelRows[$index % 3][] = $channel;
}
$copyrightLine = [
    'az' => 'MÜƏLLİFLİK HÜQUQU 2026 ©SUPEROPTIKTECH BÜTÜN HÜQUQLAR QORUNUR',
    'en' => 'COPYRIGHT 2026 ©SUPEROPTIKTECH ALL RIGHTS RESERVED',
    'ru' => 'АВТОРСКОЕ ПРАВО 2026 ©SUPEROPTIKTECH ВСЕ ПРАВА ЗАЩИЩЕНЫ',
];
$footerLabels = [
    'az' => [
        'about' => 'Haqqımızda',
        'media' => 'Media üçün',
        'legal' => 'Hüquqi',
        'mission' => 'Missiya və məqsədlər',
        'quality' => 'Keyfiyyət göstəriciləri',
        'privacy' => 'Gizlilik siyasəti',
        'rules' => 'Qaydalar və şərtlər',
        'devices' => 'Cihazlar',
        'speedtest' => 'Sürətin yoxlanılması',
        'contact' => 'Bizimlə əlaqə',
    ],
    'en' => [
        'about' => 'About us',
        'media' => 'Media',
        'legal' => 'Legal',
        'mission' => 'Mission and goals',
        'quality' => 'Quality metrics',
        'privacy' => 'Privacy policy',
        'rules' => 'Terms and conditions',
        'devices' => 'Devices',
        'speedtest' => 'Speed test',
        'contact' => 'Contact us',
    ],
    'ru' => [
        'about' => 'О компании',
        'media' => 'Для медиа',
        'legal' => 'Юридическое',
        'mission' => 'Миссия и цели',
        'quality' => 'Показатели качества',
        'privacy' => 'Политика конфиденциальности',
        'rules' => 'Правила и условия',
        'devices' => 'Устройства',
        'speedtest' => 'Проверка скорости',
        'contact' => 'Связаться с нами',
    ],
];
$tariffNavLabels = [
    'prev' => [
        'az' => 'Əvvəlki tariflər',
        'en' => 'Previous tariffs',
        'ru' => 'Предыдущие тарифы',
    ],
    'next' => [
        'az' => 'Növbəti tariflər',
        'en' => 'Next tariffs',
        'ru' => 'Следующие тарифы',
    ],
];
$mapPoints = [
    ['id' => 'dot-astara', 'top' => '92.8%', 'left' => '68.0%', 'label' => ['az' => 'Astara', 'en' => 'Astara', 'ru' => 'Астара']],
    ['id' => 'dot-lankaran', 'top' => '86.7%', 'left' => '68.8%', 'label' => ['az' => 'Lənkəran', 'en' => 'Lankaran', 'ru' => 'Ленкорань']],
    ['id' => 'dot-masalli', 'top' => '82.2%', 'left' => '67.2%', 'label' => ['az' => 'Masallı', 'en' => 'Masalli', 'ru' => 'Масаллы']],
    ['id' => 'dot-neftcala', 'top' => '70.5%', 'left' => '75.2%', 'label' => ['az' => 'Neftçala', 'en' => 'Neftchala', 'ru' => 'Нефтчала']],
    ['id' => 'dot-ismayilli', 'top' => '33.0%', 'left' => '58.6%', 'label' => ['az' => 'İsmayıllı', 'en' => 'Ismayilli', 'ru' => 'Исмаиллы']],
    ['id' => 'dot-bineqedi', 'top' => '41.8%', 'left' => '84.8%', 'label' => ['az' => 'Binəqədi', 'en' => 'Binagadi', 'ru' => 'Бинагади']],
    ['id' => 'dot-abseron', 'top' => '43.0%', 'left' => '83.2%', 'label' => ['az' => 'Abşeron', 'en' => 'Absheron', 'ru' => 'Абшерон']],
    ['id' => 'dot-sabunchu', 'top' => '42.6%', 'left' => '87.4%', 'label' => ['az' => 'Sabunçu', 'en' => 'Sabunchu', 'ru' => 'Сабунчи']],
    ['id' => 'dot-xetai', 'top' => '45.0%', 'left' => '88.2%', 'label' => ['az' => 'Xətai', 'en' => 'Khatai', 'ru' => 'Хатаи']],
    ['id' => 'dot-suraxani', 'top' => '44.2%', 'left' => '86.8%', 'label' => ['az' => 'Suraxanı', 'en' => 'Surakhani', 'ru' => 'Сураханы']],
    ['id' => 'dot-nizami', 'top' => '45.2%', 'left' => '85.5%', 'label' => ['az' => 'Nizami', 'en' => 'Nizami', 'ru' => 'Низами']],
    ['id' => 'dot-pirsagi', 'top' => '41.0%', 'left' => '83.6%', 'label' => ['az' => 'Pirşağı', 'en' => 'Pirshagi', 'ru' => 'Пиршаги']],
    ['id' => 'dot-kurdexani', 'top' => '41.2%', 'left' => '84.6%', 'label' => ['az' => 'Kürdəxanı', 'en' => 'Kurdakhani', 'ru' => 'Кюрдаханы']],
    ['id' => 'dot-zabrat', 'top' => '42.0%', 'left' => '85.7%', 'label' => ['az' => 'Zabrat', 'en' => 'Zabrat', 'ru' => 'Забрат']],
    ['id' => 'dot-bakixanov', 'top' => '43.2%', 'left' => '86.7%', 'label' => ['az' => 'Bakıxanov', 'en' => 'Bakikhanov', 'ru' => 'Бакиханов']],
    ['id' => 'dot-mehdiabad', 'top' => '41.5%', 'left' => '85.0%', 'label' => ['az' => 'Mehdiabad', 'en' => 'Mehdiabad', 'ru' => 'Мехдиабад']],
    ['id' => 'dot-memmedli', 'top' => '41.8%', 'left' => '86.5%', 'label' => ['az' => 'Məmmədli', 'en' => 'Mammadli', 'ru' => 'Мамедли']],
    ['id' => 'dot-qarachuxur', 'top' => '44.8%', 'left' => '86.2%', 'label' => ['az' => 'Qaraçuxur', 'en' => 'Garachukhur', 'ru' => 'Гарачухур']],
    ['id' => 'dot-guneshli', 'top' => '45.0%', 'left' => '87.0%', 'label' => ['az' => 'Günəşli', 'en' => 'Gunashli', 'ru' => 'Гюнешли']],
    ['id' => 'dot-ehmedli', 'top' => '44.3%', 'left' => '87.6%', 'label' => ['az' => 'Əhmədli', 'en' => 'Ahmadli', 'ru' => 'Ахмедлы']],
    ['id' => 'dot-bileceri', 'top' => '42.7%', 'left' => '83.9%', 'label' => ['az' => 'Biləcəri', 'en' => 'Bilajari', 'ru' => 'Биляджари']],
];

$tariffs = [
    ['audience' => 'home', 'filter' => 'internet', 'type' => ['az' => 'Fərdi internet', 'en' => 'Home internet', 'ru' => 'Домашний интернет'], 'speed' => 100, 'price' => 25, 'features' => ['az' => ['Fiber optik xətt', 'Wi-Fi router dəstəyi', 'Standart quraşdırma'], 'en' => ['Fiber line', 'Wi-Fi router support', 'Standard installation'], 'ru' => ['Оптоволоконная линия', 'Поддержка Wi-Fi роутера', 'Стандартная установка']], 'badge' => ['az' => 'Yeni qoşulma', 'en' => 'New connection', 'ru' => 'Новое подключение']],
    ['audience' => 'home', 'filter' => 'internet-tv', 'type' => ['az' => 'Fərdi internet + TV', 'en' => 'Home internet + TV', 'ru' => 'Домашний интернет + ТВ'], 'speed' => 100, 'price' => 31, 'features' => ['az' => ['Fiber optik xətt', 'TV xidməti', 'TV Box dəstəyi'], 'en' => ['Fiber line', 'TV service', 'TV Box support'], 'ru' => ['Оптоволоконная линия', 'ТВ сервис', 'Поддержка TV Box']]],
    ['audience' => 'home', 'filter' => 'internet-phone', 'type' => ['az' => 'Fərdi internet + Telefon', 'en' => 'Home internet + Phone', 'ru' => 'Домашний интернет + Телефон'], 'speed' => 100, 'price' => 29, 'features' => ['az' => ['Fiber optik xətt', 'Sabit telefon xətti', 'Ev üçün rahat istifadə'], 'en' => ['Fiber line', 'Fixed phone line', 'Comfortable for home use'], 'ru' => ['Оптоволоконная линия', 'Стационарная линия', 'Удобно для дома']]],
    ['audience' => 'home', 'filter' => 'bundle', 'type' => ['az' => 'Fərdi tam paket', 'en' => 'Home full bundle', 'ru' => 'Домашний полный пакет'], 'speed' => 100, 'price' => 35, 'features' => ['az' => ['İnternet', 'TV xidməti', 'Telefon xətti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Интернет', 'ТВ сервис', 'Телефонная линия']]],
    ['audience' => 'home', 'filter' => 'internet', 'type' => ['az' => 'Fərdi internet', 'en' => 'Home internet', 'ru' => 'Домашний интернет'], 'speed' => 150, 'price' => 30, 'features' => ['az' => ['Fiber optik xətt', 'Wi-Fi router dəstəyi', 'Ailə istifadəsi üçün rahat sürət'], 'en' => ['Fiber line', 'Wi-Fi router support', 'Comfortable speed for family use'], 'ru' => ['Оптоволоконная линия', 'Поддержка Wi-Fi роутера', 'Комфортная скорость для семьи']]],
    ['audience' => 'home', 'filter' => 'bundle', 'type' => ['az' => 'Fərdi tam paket', 'en' => 'Home full bundle', 'ru' => 'Домашний полный пакет'], 'speed' => 150, 'price' => 40, 'features' => ['az' => ['İnternet', 'TV xidməti', 'Telefon xətti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Интернет', 'ТВ сервис', 'Телефонная линия']], 'badge' => ['az' => 'Ən çox seçilən', 'en' => 'Most chosen', 'ru' => 'Самый популярный']],
    ['audience' => 'home', 'filter' => 'internet', 'type' => ['az' => 'Fərdi internet', 'en' => 'Home internet', 'ru' => 'Домашний интернет'], 'speed' => 250, 'price' => 36, 'features' => ['az' => ['4K streaming üçün uyğun', 'Wi-Fi router dəstəyi', 'Sabit fiber xətt'], 'en' => ['Suitable for 4K streaming', 'Wi-Fi router support', 'Stable fiber line'], 'ru' => ['Подходит для 4K streaming', 'Поддержка Wi-Fi роутера', 'Стабильная fiber-линия']]],
    ['audience' => 'home', 'filter' => 'bundle', 'type' => ['az' => 'Fərdi tam paket', 'en' => 'Home full bundle', 'ru' => 'Домашний полный пакет'], 'speed' => 250, 'price' => 46, 'features' => ['az' => ['İnternet', 'TV xidməti', 'Telefon xətti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Интернет', 'ТВ сервис', 'Телефонная линия']]],
    ['audience' => 'home', 'filter' => 'internet', 'type' => ['az' => 'Fərdi internet', 'en' => 'Home internet', 'ru' => 'Домашний интернет'], 'speed' => 500, 'price' => 45, 'features' => ['az' => ['Çoxcihazlı ev üçün yüksək sürət', '4K/8K streaming və oyun', 'Wi-Fi router dəstəyi'], 'en' => ['High speed for multi-device homes', '4K/8K streaming and gaming', 'Wi-Fi router support'], 'ru' => ['Высокая скорость для дома с многими устройствами', '4K/8K streaming и игры', 'Поддержка Wi-Fi роутера']], 'badge' => ['az' => 'Sürət seçimi', 'en' => 'Speed pick', 'ru' => 'Выбор скорости']],
    ['audience' => 'home', 'filter' => 'internet', 'type' => ['az' => 'Fərdi internet', 'en' => 'Home internet', 'ru' => 'Домашний интернет'], 'speed' => 1000, 'price' => 70, 'features' => ['az' => ['1 Gbit maksimal sürət', 'Ağır yükləmə və peşəkar istifadə', 'Sabit fiber xətt'], 'en' => ['1 Gbit maximum speed', 'Heavy downloads and professional use', 'Stable fiber line'], 'ru' => ['Максимальная скорость 1 Гбит', 'Тяжелые загрузки и профессиональное использование', 'Стабильная fiber-линия']], 'badge' => ['az' => 'Maksimal sürət', 'en' => 'Max speed', 'ru' => 'Максимальная скорость']],
    ['audience' => 'business', 'filter' => 'internet', 'type' => ['az' => 'Biznes internet', 'en' => 'Business internet', 'ru' => 'Бизнес интернет'], 'speed' => 100, 'price' => 30, 'features' => ['az' => ['Fərdi paketdən 5 AZN yuxarı baza', 'Prioritet dəstək', 'Ofis üçün sabit xətt'], 'en' => ['Base price 5 AZN above home plan', 'Priority support', 'Stable line for office use'], 'ru' => ['Базовая цена на 5 AZN выше домашнего тарифа', 'Приоритетная поддержка', 'Стабильная линия для офиса']]],
    ['audience' => 'business', 'filter' => 'internet-tv', 'type' => ['az' => 'Biznes internet + TV', 'en' => 'Business internet + TV', 'ru' => 'Бизнес интернет + ТВ'], 'speed' => 100, 'price' => 40, 'features' => ['az' => ['Baza qiymət + TV 10 AZN', 'Gözləmə zonası üçün TV', 'Fiber optik xətt'], 'en' => ['Base price + TV 10 AZN', 'TV for waiting areas', 'Fiber line'], 'ru' => ['Базовая цена + ТВ 10 AZN', 'ТВ для зоны ожидания', 'Оптоволоконная линия']]],
    ['audience' => 'business', 'filter' => 'internet-phone', 'type' => ['az' => 'Biznes internet + Telefon', 'en' => 'Business internet + Phone', 'ru' => 'Бизнес интернет + Телефон'], 'speed' => 100, 'price' => 40, 'features' => ['az' => ['Baza qiymət + Telefon 10 AZN', 'Sabit əlaqə xətti', 'Ofis üçün uyğun'], 'en' => ['Base price + Phone 10 AZN', 'Fixed communication line', 'Suitable for office use'], 'ru' => ['Базовая цена + Телефон 10 AZN', 'Стационарная линия связи', 'Подходит для офиса']]],
    ['audience' => 'business', 'filter' => 'bundle', 'type' => ['az' => 'Biznes tam paket', 'en' => 'Business full bundle', 'ru' => 'Бизнес полный пакет'], 'speed' => 100, 'price' => 50, 'features' => ['az' => ['İnternet 30 AZN', 'TV +10 AZN', 'Telefon +10 AZN'], 'en' => ['Internet 30 AZN', 'TV +10 AZN', 'Phone +10 AZN'], 'ru' => ['Интернет 30 AZN', 'ТВ +10 AZN', 'Телефон +10 AZN']]],
    ['audience' => 'business', 'filter' => 'internet', 'type' => ['az' => 'Biznes internet', 'en' => 'Business internet', 'ru' => 'Бизнес интернет'], 'speed' => 150, 'price' => 35, 'features' => ['az' => ['Prioritet dəstək', 'Şəbəkə üçün daha geniş resurs', 'Fiber optik xətt'], 'en' => ['Priority support', 'More network capacity', 'Fiber line'], 'ru' => ['Приоритетная поддержка', 'Больше ресурса для сети', 'Оптоволоконная линия']]],
    ['audience' => 'business', 'filter' => 'bundle', 'type' => ['az' => 'Biznes tam paket', 'en' => 'Business full bundle', 'ru' => 'Бизнес полный пакет'], 'speed' => 150, 'price' => 55, 'features' => ['az' => ['İnternet', 'TV xidməti', 'Telefon xətti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Интернет', 'ТВ сервис', 'Телефонная линия']]],
    ['audience' => 'business', 'filter' => 'internet', 'type' => ['az' => 'Biznes internet', 'en' => 'Business internet', 'ru' => 'Бизнес интернет'], 'speed' => 250, 'price' => 41, 'features' => ['az' => ['Ofis və kamera sistemi üçün uyğun', 'Şəbəkə dayanıqlığı', 'Prioritet xidmət'], 'en' => ['Suitable for office and camera systems', 'Network stability', 'Priority service'], 'ru' => ['Подходит для офиса и камер', 'Стабильность сети', 'Приоритетный сервис']]],
    ['audience' => 'business', 'filter' => 'bundle', 'type' => ['az' => 'Biznes tam paket', 'en' => 'Business full bundle', 'ru' => 'Бизнес полный пакет'], 'speed' => 250, 'price' => 61, 'features' => ['az' => ['İnternet', 'TV xidməti', 'Telefon xətti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Интернет', 'ТВ сервис', 'Телефонная линия']]],
    ['audience' => 'business', 'filter' => 'internet', 'type' => ['az' => 'Biznes internet', 'en' => 'Business internet', 'ru' => 'Бизнес интернет'], 'speed' => 500, 'price' => 50, 'features' => ['az' => ['Böyük ofis və server yükü üçün', 'Prioritet dəstək', 'Şəbəkə dayanıqlığı'], 'en' => ['For large offices and server load', 'Priority support', 'Network stability'], 'ru' => ['Для крупных офисов и серверной нагрузки', 'Приоритетная поддержка', 'Стабильность сети']]],
    ['audience' => 'business', 'filter' => 'internet', 'type' => ['az' => 'Biznes internet', 'en' => 'Business internet', 'ru' => 'Бизнес интернет'], 'speed' => 1000, 'price' => 75, 'features' => ['az' => ['1 Gbit maksimal sürət', 'Kamera və bulud sistemləri üçün', 'Prioritet xidmət'], 'en' => ['1 Gbit maximum speed', 'For camera and cloud systems', 'Priority service'], 'ru' => ['Максимальная скорость 1 Гбит', 'Для камер и облачных систем', 'Приоритетный сервис']], 'badge' => ['az' => 'Maksimal sürət', 'en' => 'Max speed', 'ru' => 'Максимальная скорость']],
];
$supportPhoneDisplay = '+994 12 525 88 11';
$supportPhoneHref = 'tel:+994125258811';
$supportWhatsappHref = 'https://wa.me/994512055566?text=Super%20Optik%20Tech';
$showLanguageMenu = true;
$publicCta = [
    'az' => [
        'call_note' => 'Satış və dəstək',
        'portal_label' => 'Kabinet',
        'hero_eyebrow' => 'Ev və biznes üçün fiber internet',
        'hero_title' => '100 Mbit fiber internet 25 AZN-dən',
        'hero_text' => 'Ev üçün 25 AZN, biznes üçün 30 AZN. Ünvanınızı yoxlayın, telefon nömrənizi yazın və qoşulma vaxtını sizə geri bildirək.',
        'hero_primary' => 'İndi müraciət et',
        'hero_secondary' => 'Əhatəni yoxla',
        'hero_note' => 'Qoşulma üçün ərazi və telefon kifayətdir. Adətən 15 dəqiqə ərzində geri dönüş edirik.',
        'form_title' => '1 dəqiqədə qoşulma müraciəti',
        'form_text' => 'Ərazi və telefon nömrəsini yazın. Komandamız texniki imkanı və qoşulma vaxtını sizinlə dəqiqləşdirsin.',
        'form_hint' => '1 dəqiqə çəkir. Əvvəlcə ərazi və nömrəni yazın, qalan hissəni komandamız sizinlə dəqiqləşdirsin.',
        'portal_title' => 'Mövcud müştəri və əməkdaş girişi',
        'portal_text' => 'Ödəniş, trafik və müraciətlər üçün kabinetə keçin. Support və NOC əməkdaşları da bu girişdən istifadə edir.',
        'portal_points' => ['Müştəri: ödəniş, trafik və müraciətlər', 'Əməkdaş: billing və OLT keçidi'],
        'portal_action' => 'Kabinetə keç',
        'whatsapp_label' => 'WhatsApp',
        'whatsapp_note' => 'Bizə yaz',
        'news_subtitle' => 'İnternet və sistem təhlükəsizliyi ilə bağlı faydalı yenilikləri qısa və aydın formada paylaşırıq.',
    ],
    'en' => [
        'call_note' => 'Sales and support',
        'portal_label' => 'Portal',
        'hero_eyebrow' => 'Fiber internet for home and business',
        'hero_title' => '100 Mbps fiber internet from 25 AZN',
        'hero_text' => '25 AZN for home and 30 AZN for business. Check your coverage, send your phone number and get connection timing from our team.',
        'hero_primary' => 'Apply now',
        'hero_secondary' => 'Check coverage',
        'hero_note' => 'Area and phone number are enough to start. We usually call back within 15 minutes.',
        'form_title' => 'Connection request in 1 minute',
        'form_text' => 'Send your area and phone number. Our team will confirm technical availability and connection timing with you.',
        'form_hint' => 'Takes about 1 minute. First send your area and number, then our team confirms the rest with you.',
        'portal_title' => 'Existing customer and employee access',
        'portal_text' => 'Open the portal for payments, traffic and requests. Support and NOC staff also use this login.',
        'portal_points' => ['Customer: payments, traffic and requests', 'Employee: billing and OLT handoff'],
        'portal_action' => 'Open portal',
        'whatsapp_label' => 'WhatsApp',
        'whatsapp_note' => 'Write to us',
        'news_subtitle' => 'We share practical updates around internet service and system security in a shorter, cleaner format.',
    ],
    'ru' => [
        'call_note' => 'Продажи и поддержка',
        'portal_label' => 'Кабинет',
        'hero_eyebrow' => 'Оптоволоконный интернет для дома и бизнеса',
        'hero_title' => '100 Мбит fiber-интернета от 25 AZN',
        'hero_text' => '25 AZN для дома и 30 AZN для бизнеса. Проверьте покрытие, отправьте номер телефона и получите срок подключения от нашей команды.',
        'hero_primary' => 'Оставить заявку',
        'hero_secondary' => 'Проверить покрытие',
        'hero_note' => 'Для старта достаточно района и номера телефона. Обычно мы перезваниваем в течение 15 минут.',
        'form_title' => 'Заявка на подключение за 1 минуту',
        'form_text' => 'Укажите район и номер телефона. Наша команда уточнит техническую возможность и срок подключения.',
        'form_hint' => 'Это займет около 1 минуты. Сначала отправьте район и номер, остальное команда уточнит вместе с вами.',
        'portal_title' => 'Вход для клиентов и сотрудников',
        'portal_text' => 'Через кабинет можно посмотреть платежи, трафик и обращения. Support и NOC тоже используют этот вход.',
        'portal_points' => ['Клиент: платежи, трафик и обращения', 'Сотрудник: биллинг и переход в OLT'],
        'portal_action' => 'Открыть кабинет',
        'whatsapp_label' => 'WhatsApp',
        'whatsapp_note' => 'Напишите нам',
        'news_subtitle' => 'Мы коротко и понятно делимся полезными обновлениями по интернет-сервису и системной безопасности.',
    ],
];
$toSearchKey = static function (string $value): string {
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
    $normalized = strtr($normalized, [
        'É™' => 'e',
        'ı' => 'i',
        'ö' => 'o',
        'ü' => 'u',
        'ÅŸ' => 's',
        'ç' => 'c',
        'ÄŸ' => 'g',
        'ñ' => 'n',
    ]);
    $normalized = preg_replace('/[^a-z0-9\s-]/u', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);
    return $normalized;
};
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($seo['title']); ?></title>
    <meta name="description" content="<?= e($seo['description']); ?>">
    <meta name="keywords" content="<?= e($seo['keywords']); ?>">
    <meta name="author" content="Super Optik Tech">
    <meta name="application-name" content="Super Optik">
    <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">
    <meta name="theme-color" content="#10233f">
    <meta name="geo.region" content="AZ">
    <meta name="geo.placename" content="Bakı, Azərbaycan">
    <link rel="canonical" href="<?= e($canonicalUrl); ?>">
    <?php
    $seoBase = $siteUrl . ($currentPath === '/' ? '/' : $currentPath);
    $seoAlt = static function (string $l) use ($seoBase): string {
        return $l === 'az' ? $seoBase : $seoBase . (strpos($seoBase, '?') !== false ? '&' : '?') . 'lang=' . $l;
    };
    ?>
    <link rel="alternate" hreflang="az" href="<?= e($seoAlt('az')); ?>">
    <link rel="alternate" hreflang="en" href="<?= e($seoAlt('en')); ?>">
    <link rel="alternate" hreflang="ru" href="<?= e($seoAlt('ru')); ?>">
    <link rel="alternate" hreflang="x-default" href="<?= e($seoAlt('az')); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Super Optik">
    <meta property="og:locale" content="<?= e($seo['locale']); ?>">
    <meta property="og:title" content="<?= e($seo['title']); ?>">
    <meta property="og:description" content="<?= e($seo['description']); ?>">
    <meta property="og:url" content="<?= e($canonicalUrl); ?>">
    <meta property="og:image" content="<?= e($seoImage); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($seo['title']); ?>">
    <meta name="twitter:description" content="<?= e($seo['description']); ?>">
    <meta name="twitter:image" content="<?= e($seoImage); ?>">
    <link rel="shortcut icon" href="images/favicon.png">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/fiber-site.css')); ?>">
    <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@graph' => $schemaGraph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
</head>
    <body>
    <div id="fiber-loader"><div><div class="loader-mark">Super <span>Optik</span> Tech</div><div class="loader-line"><i></i></div></div></div>
    <script>try{var _t=localStorage.getItem('so-theme');if(_t)document.documentElement.setAttribute('data-theme',_t);}catch(e){}</script>
    <canvas id="fiber-bg" aria-hidden="true"></canvas>
    <canvas id="cursor-trail" aria-hidden="true"></canvas>
    <div class="fiber-veil" aria-hidden="true"></div>
    <div class="cursor-ring" aria-hidden="true"></div><div class="cursor-dot" aria-hidden="true"></div>
    <?php
    $qs = $lang !== 'az' ? '?lang=' . rawurlencode($lang) : '';
    // Yeni bölmələr üçün tərcümələr (dil dəyişəndə hamısı dəyişsin).
    $fiberTr = [
        'az' => [
            'adv_title' => 'Niyə Super Optik?',
            'adv' => [['Öz fiber şəbəkəmiz','Uçdan-uca öz infrastrukturumuz — stabil sürət, aşağı gecikmə.'],['TV + İnternet + Telefon','Hər şey bir xətdə, bir hesabda, bir ödənişdə.'],['7/24 texniki dəstək','Yerli komanda, sürətli çıxış, uzaqdan diaqnostika.'],['Etibarlı ödəniş','Terminal, onlayn və avtomatik ödəniş — istədiyin kimi.']],
            'cov_title' => 'Fiber şəbəkəmiz genişlənir', 'cov_text' => 'Bakı və regionlar üzrə aktiv xidmət. Ünvanını yaz — əhatəni yoxlayaq.',
            'pay_title' => 'Rahat ödəniş kanalları', 'pay_text' => 'Ödənişinizi istədiyiniz platformadan edin — sistem avtomatik oturur.',
            'con_title' => 'Bizimlə əlaqə', 'con_text' => 'Sual, qoşulma və ya texniki dəstək — qısa müddətdə cavab veririk.',
            'c_phone' => 'Qaynar xətt', 'c_mail' => 'E-poçt', 'c_portal' => 'Müştəri paneli',
            'sup_help' => 'Problemi həll edə bilmədinizsə, birbaşa komandamızla əlaqə saxlayın.',
            'news_all' => 'Hamısı', 'f_home' => 'Ev interneti', 'f_biz' => 'Biznes', 'f_tv' => 'TV + İnternet',
        ],
        'en' => [
            'adv_title' => 'Why Super Optik?',
            'adv' => [['Our own fiber network','End-to-end own infrastructure — stable speed, low latency.'],['TV + Internet + Phone','Everything on one line, one account, one payment.'],['24/7 technical support','Local team, fast dispatch, remote diagnostics.'],['Reliable payment','Terminal, online and automatic payment — your choice.']],
            'cov_title' => 'Our fiber network is expanding', 'cov_text' => 'Active service across Baku and regions. Type your address — we will check coverage.',
            'pay_title' => 'Convenient payment channels', 'pay_text' => 'Pay from any platform you prefer — it posts to the system automatically.',
            'con_title' => 'Contact us', 'con_text' => 'Questions, connection or technical support — we reply quickly.',
            'c_phone' => 'Hotline', 'c_mail' => 'Email', 'c_portal' => 'Customer panel',
            'sup_help' => 'If you could not resolve the issue, contact our team directly.',
            'news_all' => 'All', 'f_home' => 'Home internet', 'f_biz' => 'Business', 'f_tv' => 'TV + Internet',
        ],
        'ru' => [
            'adv_title' => 'Почему Super Optik?',
            'adv' => [['Собственная оптоволоконная сеть','Собственная инфраструктура — стабильная скорость, низкая задержка.'],['ТВ + Интернет + Телефон','Всё на одной линии, одном счёте, одном платеже.'],['Поддержка 24/7','Местная команда, быстрый выезд, удалённая диагностика.'],['Надёжная оплата','Терминал, онлайн и автоплатёж — как удобно вам.']],
            'cov_title' => 'Наша оптоволоконная сеть расширяется', 'cov_text' => 'Активный сервис по Баку и регионам. Укажите адрес — проверим покрытие.',
            'pay_title' => 'Удобные каналы оплаты', 'pay_text' => 'Оплачивайте с любой удобной платформы — платёж поступает автоматически.',
            'con_title' => 'Связаться с нами', 'con_text' => 'Вопросы, подключение или техподдержка — отвечаем быстро.',
            'c_phone' => 'Горячая линия', 'c_mail' => 'Эл. почта', 'c_portal' => 'Панель клиента',
            'sup_help' => 'Если не удалось решить проблему, свяжитесь с командой напрямую.',
            'news_all' => 'Все', 'f_home' => 'Домашний интернет', 'f_biz' => 'Бизнес', 'f_tv' => 'ТВ + Интернет',
        ],
    ];
    $T = $fiberTr[$lang] ?? $fiberTr['az'];
    require_once __DIR__ . '/page-helpers.php';
    $PL = page_labels()[$lang] ?? page_labels()['az'];
    // Inline SVG icons — fontawesome-lite məhdud olduğu üçün (internetsiz serverdə etibarlı).
    if (!function_exists('svgi')) {
        function svgi(string $name, int $size = 20): string {
            $p = [
                'bolt' => '<path d="M13 2 4 14h6l-1 8 9-12h-6l1-8z"/>',
                'tv' => '<rect x="2" y="4" width="20" height="14" rx="2"/><path d="M8 21h8M12 18v3"/>',
                'headset' => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="2" y="13" width="4" height="7" rx="2"/><rect x="18" y="13" width="4" height="7" rx="2"/><path d="M20 20a4 4 0 0 1-4 4h-2"/>',
                'shield' => '<path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5l-8-3z"/><path d="m9 12 2 2 4-4"/>',
                'phone' => '<path d="M4 4h4l2 5-3 2a12 12 0 0 0 6 6l2-3 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 2 6a2 2 0 0 1 2-2z"/>',
                'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 6 9 7 9-7"/>',
                'user-shield' => '<circle cx="9" cy="8" r="4"/><path d="M3 20a6 6 0 0 1 9-5"/><path d="M18 13l3 1v3c0 2-1.5 3.5-3 4-1.5-.5-3-2-3-4v-3l3-1z"/>',
                'chevron' => '<path d="m6 9 6 6 6-6"/>',
                'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.8.4-1 .8-1 1.7M12 17h.01"/>',
                'wifi' => '<path d="M2 8a16 16 0 0 1 20 0M5 12a11 11 0 0 1 14 0M8.5 15.5a6 6 0 0 1 7 0M12 19h.01"/>',
                'server' => '<rect x="3" y="4" width="18" height="6" rx="1"/><rect x="3" y="14" width="18" height="6" rx="1"/><path d="M7 7h.01M7 17h.01"/>',
                'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/>',
                'router' => '<rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 17h.01M11 17h6M12 9V6M12 6a4 4 0 0 0-4 4M12 6a4 4 0 0 1 4 4"/>',
                'house' => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>',
                'house-signal' => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-4h4v4"/><path d="M16 6a4 4 0 0 1 0 6"/>',
                'download' => '<path d="M12 3v12M7 11l5 5 5-5M4 21h16"/>',
                'upload' => '<path d="M12 21V9M7 13l5-5 5 5M4 3h16"/>',
                'gamepad' => '<rect x="2" y="7" width="20" height="10" rx="5"/><path d="M7 11v2M6 12h2M15 11h.01M18 13h.01"/>',
                'video' => '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="m16 10 6-3v10l-6-3z"/>',
                'satellite' => '<path d="M4 11a9 9 0 0 1 9 9M4 16a4 4 0 0 1 4 4"/><circle cx="5" cy="19" r="1"/><path d="M14 4l6 6-3 3-6-6z"/>',
                'coins' => '<ellipse cx="9" cy="7" rx="6" ry="3"/><path d="M3 7v5c0 1.7 2.7 3 6 3M3 12v5c0 1.7 2.7 3 6 3"/><ellipse cx="16" cy="14" rx="5" ry="2.5"/><path d="M11 14v5c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5v-5"/>',
                'bars' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
                'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/>',
                'zap' => '<path d="M4 12h4l2-4 4 8 2-4h4"/>',
                'check' => '<circle cx="12" cy="12" r="10" fill="rgba(0,229,255,.16)"/><path d="m8 12 2.5 2.5L16 9"/>',
                'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M6.3 17.7l-1.4 1.4M19.1 4.9l-1.4 1.4"/>',
                'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
            ];
            $d = $p[$name] ?? '<circle cx="12" cy="12" r="9"/>';
            return '<svg class="svgi" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
        }
    }
    ?>

    <?php if ($flash !== null): ?>
    <div class="notice notice-<?= e($flash['type']); ?>" data-flash-notice>
        <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <span><?= e($flash['message']); ?></span>
    </div>
    <?php endif; ?>

    <header class="site-header">
        <div class="container header-inner">
            <a href="index.php<?= $qs; ?>" class="brand" aria-label="Home">
                <img src="images/favicon.png" alt="Super Optik Tech logo">
                <span class="brand-mark"><span class="brand-word-super">Super</span><span class="brand-word-optik">Optik</span></span>
            </a>
            <button class="mobile-nav-toggle" type="button" aria-label="Menu" data-mobile-nav-toggle><?= svgi("bars") ?></button>
            <nav class="main-nav" data-mobile-nav>
                <a href="#tariffs"><?= e($copy['nav'][1]); ?></a>
                <a href="#services"><?= e($copy['nav'][2]); ?></a>
                <a href="#coverage"><?= e($copy['nav'][3]); ?></a>
                <a href="#support"><?= e($copy['support']['eyebrow']); ?></a>
                <a href="#tvnet"><?= e($sectionLabels['tv'][$lang] ?? $sectionLabels['tv']['az']); ?></a>
                <a href="#news"><?= e($copy['nav'][5] ?? 'Xəbərlər'); ?></a>
                <a href="#payments"><?= e($sectionLabels['payments'][$lang] ?? $sectionLabels['payments']['az']); ?></a>
                <a href="#contact"><?= e($copy['nav'][4]); ?></a>
            </nav>
            <div class="header-actions" data-mobile-actions>
                <a class="header-link header-link-phone" href="<?= e($supportPhoneHref); ?>">
                    <?= svgi("phone") ?>
                    <span class="header-link-stack"><strong><?= e($supportPhoneDisplay); ?></strong><small><?= e($publicCta[$lang]['call_note'] ?? $publicCta['az']['call_note']); ?></small></span>
                </a>
                <a class="header-link header-link-portal" href="<?= e($portalLoginUrl . $qs); ?>"><?= e($publicCta[$lang]['portal_label'] ?? $publicCta['az']['portal_label']); ?></a>
                <?php if ($showLanguageMenu): ?>
                <button class="theme-toggle" type="button" data-theme-toggle aria-label="Tema">
                    <span class="tt-moon"><?= svgi('moon',18); ?></span>
                    <span class="tt-sun"><?= svgi('sun',18); ?></span>
                </button>
                <div class="language-dropdown" data-lang-dropdown>
                    <button class="language-button" type="button" aria-label="Language" data-lang-toggle>
                        <img src="images/<?= e($flagMap[$lang] ?? 'azerbaijan.png'); ?>" alt="<?= e(strtoupper($lang)); ?>">
                        <span><?= e(strtoupper($lang)); ?></span><?= svgi("chevron",14) ?>
                    </button>
                    <div class="language-menu">
                        <a class="lang-option" data-lang="az" href="?lang=az"><img src="images/azerbaijan.png" alt="AZ"><span>AZ</span></a>
                        <a class="lang-option" data-lang="en" href="?lang=en"><img src="images/eng.png" alt="EN"><span>EN</span></a>
                        <a class="lang-option" data-lang="ru" href="?lang=ru"><img src="images/rus.png" alt="RU"><span>RU</span></a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
        <!-- HERO -->
        <section class="hero-section" id="home">
            <div class="container hero-grid">
                <div class="hero-copy">
                    <span class="eyebrow"><?= e($publicCta[$lang]['hero_eyebrow'] ?? $publicCta['az']['hero_eyebrow']); ?></span>
                    <h1><?= e($publicCta[$lang]['hero_title'] ?? $publicCta['az']['hero_title']); ?></h1>
                    <p class="hero-text"><?= e($publicCta[$lang]['hero_text'] ?? $publicCta['az']['hero_text']); ?></p>
                    <div class="hero-points">
                        <?php foreach (($salesHero[$lang]['proofs'] ?? $salesHero['az']['proofs']) as $proof): ?>
                        <div class="hero-point"><span class="hp-check"><?= svgi("check",20) ?></span><div><strong><?= e($proof['value']); ?></strong><span><?= e($proof['label']); ?></span></div></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="hero-actions">
                        <a href="#connection-form" class="button button-primary"><?= e($publicCta[$lang]['hero_primary'] ?? $publicCta['az']['hero_primary']); ?></a>
                        <a href="#coverage" class="button button-secondary"><?= e($publicCta[$lang]['hero_secondary'] ?? $publicCta['az']['hero_secondary']); ?></a>
                    </div>
                    <p class="hero-copy-note"><?= e($publicCta[$lang]['hero_note'] ?? $publicCta['az']['hero_note']); ?></p>
                </div>
                <div class="hero-panel">
                    <div class="panel-content" id="connection-form">
                        <div class="lead-gauge">
                            <svg width="60" height="60" viewBox="0 0 60 60"><circle cx="30" cy="30" r="25" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="6"/><circle cx="30" cy="30" r="25" fill="none" stroke="url(#gg)" stroke-width="6" stroke-linecap="round" stroke-dasharray="157" stroke-dashoffset="26" transform="rotate(-90 30 30)"/><defs><linearGradient id="gg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2ee6e0"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient></defs></svg>
                            <div><div class="g-val grad-txt" id="live-speed">940</div><div class="g-lab">Mbit/s — canlı fiber sürəti</div></div>
                        </div>
                        <div class="lead-box-title"><h2><?= e($publicCta[$lang]['form_title'] ?? $publicCta['az']['form_title']); ?></h2><p><?= e($publicCta[$lang]['form_text'] ?? $publicCta['az']['form_text']); ?></p></div>
                        <form class="lead-form" action="muraciet-gonder.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="lang" value="<?= e($lang); ?>">
                            <input type="hidden" name="message" value="<?= e($salesHero[$lang]['default_message'] ?? $salesHero['az']['default_message']); ?>">
                            <div class="form-row honeypot-row" aria-hidden="true"><label for="website">Website</label><input id="website" type="text" name="website" tabindex="-1" autocomplete="off"></div>
                            <p class="lead-form-hint"><?= e($publicCta[$lang]['form_hint'] ?? $publicCta['az']['form_hint']); ?></p>
                            <div class="form-row two-columns">
                                <div><label for="area"><?= e($copy['lead']['area']); ?></label>
                                    <select id="area" name="area" required><option value=""><?= e($copy['lead']['area_default']); ?></option>
                                    <?php foreach ($copy['coverage']['areas'] as $area): ?><option value="<?= e($area); ?>"><?= e($area); ?></option><?php endforeach; ?></select></div>
                                <div><label for="phone"><?= e($copy['lead']['phone']); ?></label><input id="phone" type="tel" name="phone" inputmode="numeric" placeholder="<?= e($copy['lead']['placeholders'][1]); ?>" required></div>
                            </div>
                            <div class="form-row"><label for="fullName"><?= e($copy['lead']['name']); ?></label><input id="fullName" type="text" name="full_name" placeholder="<?= e($copy['lead']['placeholders'][0]); ?>" required></div>
                            <div class="form-row"><label for="serviceType"><?= e($copy['lead']['service']); ?></label>
                                <select id="serviceType" name="service_type" required><?php foreach ($copy['lead']['options'] as $index => $option): ?><option value="<?= $index === 0 ? '' : e($option); ?>"><?= e($option); ?></option><?php endforeach; ?></select></div>
                            <div class="form-row"><label for="email"><?= e($copy['lead']['email']); ?></label><input id="email" type="email" name="email" placeholder="<?= e($copy['lead']['placeholders'][2]); ?>"></div>
                            <?php if ($hasRecaptcha): ?><div class="form-row"><div class="g-recaptcha" data-sitekey="<?= e(app_config('recaptcha_site_key')); ?>"></div></div><?php endif; ?>
                            <button class="button button-primary button-block" type="submit" data-submit-label data-submitting-label="<?= e($copy['lead']['submitting']); ?>"><?= e($copy['lead']['submit']); ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </section>

        <!-- LIVE STATS -->
        <?php $statLabels = ['az'=>['Uptime (dayanıqlılıq)','Orta ping','Şəbəkə tutumu','Aktiv abunəçi'],'en'=>['Uptime','Average ping','Network capacity','Active subscribers'],'ru'=>['Аптайм','Средний пинг','Ёмкость сети','Активные абоненты']]; $sl = $statLabels[$lang] ?? $statLabels['az']; ?>
        <section class="section" id="stats" style="padding-top:14px">
            <div class="container">
                <div class="stats-band" data-stats>
                    <div class="stat"><div class="stat-num" data-count="99.98" data-suffix="%">0</div><div class="stat-lab"><?= e($sl[0]); ?></div></div>
                    <div class="stat"><div class="stat-num" data-count="8" data-suffix=" ms">0</div><div class="stat-lab"><?= e($sl[1]); ?></div></div>
                    <div class="stat"><div class="stat-num" data-count="120" data-suffix=" Gbps">0</div><div class="stat-lab"><?= e($sl[2]); ?></div></div>
                    <div class="stat"><div class="stat-num" data-count="18450" data-suffix="+">0</div><div class="stat-lab"><?= e($sl[3]); ?></div></div>
                </div>
            </div>
        </section>

        <!-- PACKAGES -->
        <section class="section" id="tariffs">
            <div class="container">
                <div class="section-heading"><span class="eyebrow"><?= e($featuredOfferSection[$lang]['eyebrow'] ?? $featuredOfferSection['az']['eyebrow'] ?? 'Tariflər'); ?></span>
                    <h2><?= e($featuredOfferSection[$lang]['title'] ?? $featuredOfferSection['az']['title'] ?? 'Sürətini seç — qalanını biz quraq'); ?></h2>
                    <p><?= e($featuredOfferSection[$lang]['text'] ?? $featuredOfferSection['az']['text'] ?? 'Bütün paketlərə fiber qoşulma, modem quraşdırma və texniki dəstək daxildir.'); ?></p></div>
                <div class="plans-grid">
                    <?php foreach ($featuredOffers as $i => $offer): ?>
                    <div class="plan<?= $i === 1 ? ' feat' : ''; ?>">
                        <?php if ($i === 1): ?><span class="plan-tag"><?= e($offer['tag'][$lang] ?? $offer['tag']['az']); ?></span><?php endif; ?>
                        <div class="plan-name"><?= e($offer['name'][$lang] ?? $offer['name']['az']); ?></div>
                        <div class="plan-speed grad-txt"><?= (int) $offer['speed']; ?><small> Mbit</small></div>
                        <div class="plan-price"><?= (int) $offer['price']; ?> AZN<span class="cur"> / ay</span></div>
                        <p class="plan-desc"><?= e($offer['description'][$lang] ?? $offer['description']['az']); ?></p>
                        <?php $sp = (int) $offer['speed']; $dl = min(100, 40 + $sp / 12); $ul = min(100, 30 + $sp / 16); ?>
                        <div class="plan-speedbars spd-fill" data-spd>
                            <div class="spd-row"><?= svgi("download",16) ?><div class="spd-bar"><b style="--w:<?= round($dl); ?>%"></b></div><span>Download</span></div>
                            <div class="spd-row"><?= svgi("upload",16) ?><div class="spd-bar"><b style="--w:<?= round($ul); ?>%"></b></div><span>Upload</span></div>
                        </div>
                        <div class="plan-usecases">
                            <span class="usecase <?= $sp >= 100 ? 'good' : ''; ?>"><?= svgi("tv",16) ?> 4K TV</span>
                            <span class="usecase <?= $sp >= 300 ? 'good' : ''; ?>"><?= svgi("gamepad",16) ?> Gaming</span>
                            <span class="usecase <?= $sp >= 500 ? 'good' : ''; ?>"><?= svgi("video",16) ?> Streaming</span>
                        </div>
                        <a class="button <?= $i === 1 ? 'button-primary' : 'button-secondary'; ?>" href="#connection-form"><?= e($copy['lead']['submit']); ?></a>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Full tariff table (home + business) -->
                <?php
                $tHome = array_values(array_filter($tariffs, static fn ($t) => ($t['audience'] ?? '') === 'home'));
                $tBiz  = array_values(array_filter($tariffs, static fn ($t) => ($t['audience'] ?? '') === 'business'));
                $tLabel = ['az' => ['all' => 'Bütün tariflər', 'home' => 'Ev', 'business' => 'Biznes', 'title' => 'Tam tarif cədvəli', 'speed' => 'Sürət', 'price' => 'Qiymət', 'select' => 'Seç'], 'en' => ['all' => 'All plans', 'home' => 'Home', 'business' => 'Business', 'title' => 'Full tariff table', 'speed' => 'Speed', 'price' => 'Price', 'select' => 'Select'], 'ru' => ['all' => 'Все тарифы', 'home' => 'Дом', 'business' => 'Бизнес', 'title' => 'Полная таблица тарифов', 'speed' => 'Скорость', 'price' => 'Цена', 'select' => 'Выбрать']];
                $tl = $tLabel[$lang] ?? $tLabel['az'];
                ?>
                <div class="tariff-full" style="margin-top:46px">
                    <div class="section-heading" style="margin-bottom:24px"><h2 style="font-size:1.5rem"><?= e($tl['title']); ?></h2></div>
                    <div class="tariff-filters">
                        <button type="button" class="tariff-filter active" data-tf="all"><?= e($tl['all']); ?></button>
                        <button type="button" class="tariff-filter" data-tf="home"><?= svgi('house-signal',16); ?> <?= e($tl['home']); ?></button>
                        <button type="button" class="tariff-filter" data-tf="business"><?= svgi('server',16); ?> <?= e($tl['business']); ?></button>
                    </div>
                    <div class="tariff-carousel">
                        <button type="button" class="tc-nav tc-prev" data-tc="prev" aria-label="Əvvəlki"><?= svgi('chevron',22); ?></button>
                        <div class="tariff-rows" id="tariff-track">
                            <?php foreach ($tariffs as $t): $aud = (string) ($t['audience'] ?? 'home'); ?>
                            <div class="tariff-row" data-aud="<?= e($aud); ?>">
                                <span class="tr-badge tr-<?= e($aud); ?>"><?= $aud === 'business' ? svgi('server',15) : svgi('house-signal',15); ?> <?= e($tl[$aud] ?? $aud); ?></span>
                                <span class="tr-name"><?= e($t['type'][$lang] ?? $t['type']['az'] ?? ''); ?></span>
                                <span class="tr-speed"><?= (int) ($t['speed'] ?? 0); ?> <small>Mbit</small></span>
                                <span class="tr-price"><?= (int) ($t['price'] ?? 0); ?> <small>AZN/ay</small></span>
                                <a class="button button-secondary tr-btn" href="#connection-form"><?= e($tl['select']); ?></a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="tc-nav tc-next" data-tc="next" aria-label="Növbəti"><?= svgi('chevron',22); ?></button>
                    </div>
                </div>
            </div>
        </section>

        <!-- ADVANTAGES -->
        <section class="section section-alt" id="services">
            <div class="container">
                <div class="section-heading"><span class="eyebrow"><?= e($copy['nav'][2]); ?></span><h2><?= e($T['adv_title']); ?></h2></div>
                <div class="feat-grid">
                    <?php $advIcons = ['bolt','tv','headset','shield']; foreach ($T['adv'] as $ai => $adv): ?>
                    <div class="feat"><div class="ic"><?= svgi($advIcons[$ai] ?? 'zap', 24); ?></div><h3><?= e($adv[0]); ?></h3><p><?= e($adv[1]); ?></p></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- SIGNAL PATH (scroll story) -->
        <?php $spStory = ['az'=>['Fiber işıq siqnalının səyahəti','Data mərkəzindən evinizə — hər addım öz infrastrukturumuzda.'],'en'=>['The journey of the fiber light signal','From the data center to your home — every step on our own infrastructure.'],'ru'=>['Путь оптического сигнала','От дата-центра до вашего дома — каждый шаг на нашей инфраструктуре.']]; $sps = $spStory[$lang] ?? $spStory['az'];
        $spNodes = [['globe','Backbone','Magistral'],['server','POP','Regional qovşaq'],['router','OLT','Optik terminal'],['satellite','Splitter','Bölücü'],['house-signal','ONT','Ev modemi'],['house','Ev','Siz']]; ?>
        <section class="section section-alt" id="path">
            <div class="container">
                <div class="section-heading"><span class="eyebrow">Fiber axını</span><h2><?= e($sps[0]); ?></h2><p><?= e($sps[1]); ?></p></div>
                <div class="signal-path" data-signal>
                    <div class="sp-line"></div>
                    <?php foreach ($spNodes as $nd): ?>
                    <div class="sp-node"><div class="sp-dot"><?= svgi($nd[0], 22); ?></div><b><?= e($nd[1]); ?></b><small><?= e($nd[2]); ?></small></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- COVERAGE -->
        <section class="section" id="coverage">
            <div class="container">
                <div class="cov-grid">
                    <div>
                        <span class="eyebrow"><?= e($copy['nav'][3]); ?></span>
                        <h2 style="font-size:clamp(1.7rem,3.4vw,2.4rem);margin:12px 0 14px"><?= e($T['cov_title']); ?></h2>
                        <p style="color:var(--muted);margin-bottom:22px"><?= e($T['cov_text']); ?></p>
                        <div class="chips">
                            <?php foreach (array_slice($copy['coverage']['areas'], 0, 14) as $area): ?><span class="chip"><?= e($area); ?></span><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="cov-visual"><img class="cov-map" src="images/az.svg" alt="Azərbaycan əhatə xəritəsi" loading="lazy"><canvas id="net-map"></canvas></div>
                </div>
            </div>
        </section>

        <!-- SUPPORT / EQUIPMENT (modem self-config + FAQ) -->
        <section class="section section-alt" id="support">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow"><?= e($copy['support']['eyebrow']); ?></span>
                    <h2><?= e($copy['support']['title']); ?></h2>
                    <p><?= e($copy['support']['subtitle']); ?></p>
                </div>
                <div class="support-grid">
                    <div>
                        <h3 style="margin-bottom:14px;font-size:1.15rem"><span style="color:var(--cyan)"><?= svgi("wifi") ?></span> <?= e($copy['support']['wifi_title']); ?></h3>
                        <p style="color:var(--muted);margin:0 0 16px;font-size:.9rem"><?= e($copy['support']['wifi_text']); ?></p>
                        <?php
                        $codeify = static fn (string $s): string => preg_replace('/`([^`]+)`/u', '<code>$1</code>', e($s));
                        $sg = isset($supportGuides) && is_array($supportGuides) ? $supportGuides : [];
                        foreach ($sg as $guide): ?>
                        <details class="modem-guide">
                            <summary class="mg-head"><span class="mg-ic"><?= svgi('router'); ?></span><span><?= e($guide['title'][$lang] ?? $guide['title']['az'] ?? ''); ?></span><span class="mg-chev"><?= svgi('chevron', 18); ?></span></summary>
                            <div class="mg-body">
                                <p class="mg-sum"><?= $codeify((string) ($guide['summary'][$lang] ?? $guide['summary']['az'] ?? '')); ?></p>
                                <ol><?php foreach (($guide['steps'] ?? []) as $step): ?><li><?= $codeify((string) ($step[$lang] ?? $step['az'] ?? '')); ?></li><?php endforeach; ?></ol>
                            </div>
                        </details>
                        <?php endforeach; ?>
                    </div>
                    <div>
                        <h3 style="margin-bottom:14px;font-size:1.15rem"><span style="color:var(--cyan)"><?= svgi("help") ?></span> <?= e($copy['support']['faq_title']); ?></h3>
                        <?php foreach ($copy['support']['faq'] as $item): ?>
                        <details class="acc-item">
                            <summary class="acc-head"><span><?= e($item['q']); ?></span><span class="chev"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span></summary>
                            <div class="acc-body"><?= e($item['a']); ?></div>
                        </details>
                        <?php endforeach; ?>
                        <div class="support-side" style="margin-top:16px">
                            <h3><?= e($copy['support']['contact_title']); ?></h3>
                            <p><?= e($T['sup_help']); ?></p>
                            <a class="button button-secondary" href="<?= e($supportPhoneHref); ?>"><?= svgi("phone") ?> <?= e($supportPhoneDisplay); ?></a>
                            <a class="button button-primary" href="#connection-form"><?= svgi("headset") ?> <?= e($publicCta[$lang]['hero_primary'] ?? $publicCta['az']['hero_primary']); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TV CHANNELS (3-row moving marquee + search) -->
        <section class="section section-tv" id="tvnet">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow"><?= e($tvSection[$lang]['eyebrow'] ?? $tvSection['az']['eyebrow']); ?></span>
                    <h2><?= e($tvSection[$lang]['title'] ?? $tvSection['az']['title']); ?></h2>
                    <p><?= e($tvSection[$lang]['subtitle'] ?? $tvSection['az']['subtitle']); ?></p>
                </div>
                <div class="tv-layout">
                    <div class="tv-showcase">
                        <div class="tv-marquee-stack">
                            <?php foreach ($tvChannelRows as $rowIndex => $rowChannels): ?>
                            <div class="tv-marquee-row tv-marquee-row-<?= (int) $rowIndex + 1; ?>">
                                <div class="tv-track">
                                    <?php foreach (array_merge($rowChannels, $rowChannels, $rowChannels) as $channel): ?>
                                        <?php $name = (string) ($channel['name'] ?? ''); if ($name === '') continue; ?>
                                        <article class="tv-chip">
                                            <span class="tv-chip-logo"><?= e(mb_strtoupper(mb_substr($name, 0, 2))); ?></span>
                                            <span class="tv-chip-copy"><strong><?= e($name); ?></strong><small><?= e((string) ($channel['package'] ?? 'Standart')); ?></small></span>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <aside class="tv-search-panel">
                        <label class="tv-search-label" for="channelSearch"><?= e($tvSection[$lang]['search_label'] ?? $tvSection['az']['search_label']); ?></label>
                        <input id="channelSearch" type="search" placeholder="<?= e($tvSection[$lang]['search_placeholder'] ?? $tvSection['az']['search_placeholder']); ?>" data-channel-search>
                        <p class="tv-source-note"><span id="tv-count"><?= count($tvChannels); ?></span> kanal · <?= e($tvSection[$lang]['source_note'] ?? $tvSection['az']['source_note']); ?></p>
                        <div class="tv-results-list" id="tv-results">
                            <?php foreach ($tvChannels as $channel): ?>
                                <?php $name = (string) ($channel['name'] ?? ''); if ($name === '') continue; ?>
                                <article class="tv-result-card" data-channel-name="<?= e(mb_strtolower($name)); ?>">
                                    <span class="tv-result-logo"><?= e(mb_strtoupper(mb_substr($name, 0, 2))); ?></span>
                                    <div class="tv-result-copy"><strong><?= e($name); ?></strong><small><?= e($tvSection[$lang]['package_label'] ?? $tvSection['az']['package_label']); ?>: <?= e((string) ($channel['package'] ?? 'Standart')); ?></small></div>
                                </article>
                            <?php endforeach; ?>
                            <p class="tv-empty" id="tv-empty" hidden><?= e($tvSection[$lang]['empty'] ?? $tvSection['az']['empty']); ?></p>
                        </div>
                    </aside>
                </div>
            </div>
        </section>

        <!-- NEWS FEED -->
        <section class="section" id="news">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow"><?= e($copy['news']['eyebrow']); ?></span>
                    <h2><?= e($copy['news']['title']); ?></h2>
                    <p><?= e($copy['news']['subtitle']); ?></p>
                </div>
                <div class="news-filters">
                    <button type="button" class="news-filter active" data-news-filter="all"><?= e($T['news_all']); ?></button>
                    <button type="button" class="news-filter" data-news-filter="network"><?= e($copy['news']['tabs'][0] ?? 'İnternet'); ?></button>
                    <button type="button" class="news-filter" data-news-filter="systems"><?= e($copy['news']['tabs'][1] ?? 'Sistem'); ?></button>
                    <button type="button" class="news-filter" data-news-filter="crypto"><?= e($copy['news']['tabs'][2] ?? 'Kripto'); ?></button>
                </div>
                <div class="news-grid" id="news-grid">
                    <?php foreach ($copy['news']['items'] as $item): ?>
                    <article class="news-card" data-news-cat="<?= e((string) ($item['category'] ?? 'network')); ?>">
                        <span class="news-cat"><?= e((string) ($item['category_label'] ?? '')); ?></span>
                        <h3><?= e((string) ($item['title'] ?? '')); ?></h3>
                        <p><?= e((string) ($item['summary'] ?? '')); ?></p>
                        <div class="news-meta"><span><?= e((string) ($item['source'] ?? '')); ?></span><span><?= e((string) ($item['date'] ?? '')); ?></span></div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($copy['news']['disclaimer'])): ?><p class="news-disclaimer">* <?= e($copy['news']['disclaimer']); ?></p><?php endif; ?>
            </div>
        </section>

        <!-- PAYMENTS -->
        <section class="section section-alt" id="payments">
            <div class="container">
                <div class="section-heading"><span class="eyebrow"><?= e($sectionLabels['payments'][$lang] ?? $sectionLabels['payments']['az']); ?></span><h2><?= e($T['pay_title']); ?></h2><p><?= e($T['pay_text']); ?></p></div>
                <div class="pay-grid">
                    <?php foreach ($paymentProviders as $pp): ?>
                    <a class="pay-card pay-<?= e((string) ($pp['accent'] ?? 'light')); ?>" href="<?= e($pp['href']); ?>" target="_blank" rel="noopener" title="<?= e($pp['name']); ?>">
                        <img src="<?= e($pp['logo']); ?>" alt="<?= e($pp['name']); ?>" loading="lazy">
                        <span class="pay-name"><?= e($pp['name']); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- CONTACT -->
        <section class="section" id="contact">
            <div class="container">
                <div class="section-heading"><span class="eyebrow"><?= e($copy['nav'][4]); ?></span><h2><?= e($T['con_title']); ?></h2><p><?= e($T['con_text']); ?></p></div>
                <div class="contact-grid">
                    <div class="contact-card"><div class="ic"><?= svgi("phone") ?></div><h3><?= e($T['c_phone']); ?></h3><a href="<?= e($supportPhoneHref); ?>"><?= e($supportPhoneDisplay); ?></a></div>
                    <div class="contact-card"><div class="ic"><?= svgi("mail") ?></div><h3><?= e($T['c_mail']); ?></h3><a href="mailto:info@superoptic.net">info@superoptic.net</a></div>
                    <div class="contact-card"><div class="ic"><?= svgi("user-shield") ?></div><h3><?= e($T['c_portal']); ?></h3><a href="<?= e($portalLoginUrl . $qs); ?>"><?= e($publicCta[$lang]['portal_label'] ?? $publicCta['az']['portal_label']); ?></a></div>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer site-footer-detailed">
        <div class="container">
            <div class="footer-brand-block">
                <a href="index.php<?= $qs; ?>" class="brand brand-footer"><img src="images/favicon.png" alt="Super Optik"><span class="brand-mark"><span class="brand-word-super">Super</span> <span class="brand-word-optik">Optik</span></span></a>
                <p><?= e($PL['footer_text']); ?></p>
                <div class="footer-socials">
                    <a href="https://www.instagram.com/superoptiktech/" target="_blank" rel="noreferrer" aria-label="Instagram"><?= svgi('globe',18); ?></a>
                    <a href="mailto:info@superoptic.net" aria-label="Email"><?= svgi('mail',18); ?></a>
                    <a href="<?= e($supportPhoneHref); ?>" aria-label="Phone"><?= svgi('phone',18); ?></a>
                    <a href="https://e-complaint.icta.az/" target="_blank" rel="noreferrer" aria-label="ICT">ICT</a>
                </div>
            </div>
            <div class="footer-column">
                <h4><?= e($PL['footer_about']); ?></h4>
                <a href="haqqimizda.php<?= $qs; ?>"><?= e($PL['about']); ?></a>
                <a href="haqqimizda.php<?= $qs; ?>#mission"><?= e($PL['mission']); ?></a>
                <a href="#contact"><?= e($PL['contact']); ?></a>
            </div>
            <div class="footer-column">
                <h4><?= e($PL['footer_media']); ?></h4>
                <a href="#news"><?= e($PL['news']); ?></a>
                <a href="#tvnet"><?= e($PL['tv']); ?></a>
                <a href="https://www.speedtest.net/" target="_blank" rel="noreferrer"><?= e($PL['speedtest']); ?></a>
            </div>
            <div class="footer-column">
                <h4><?= e($PL['footer_legal']); ?></h4>
                <a href="gizlilik-siyaseti.php<?= $qs; ?>"><?= e($PL['privacy']); ?></a>
                <a href="qaydalar-ve-sertler.php<?= $qs; ?>"><?= e($PL['rules']); ?></a>
                <a href="cihazlar.php<?= $qs; ?>"><?= e($PL['devices']); ?></a>
                <a href="keyfiyyet-gostericileri.php<?= $qs; ?>"><?= e($PL['quality']); ?></a>
            </div>
            <div class="footer-bottom-bar"><?= e($PL['copyright']); ?></div>
        </div>
    </footer>

    <?php if ($hasRecaptcha): ?><script src="https://www.google.com/recaptcha/api.js" async defer></script><?php endif; ?>
    <a class="whatsapp-float" href="https://wa.me/994512055566?text=Super%20Optik%20Tech" target="_blank" rel="noreferrer" aria-label="WhatsApp"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.5 15.3L2 22l4.8-1.5A10 10 0 1 0 12 2zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .1-1.7-.1-.4-.1-.9-.3-1.5-.5-2.6-1.1-4.3-3.7-4.4-3.9-.1-.2-1-1.4-1-2.6s.6-1.8.9-2.1c.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 1.9c.1.1.1.3 0 .5l-.3.5-.4.4c-.1.1-.3.3-.1.5.1.3.6 1 1.3 1.6.9.8 1.6 1 1.9 1.2.2.1.4.1.5-.1l.6-.8c.2-.2.3-.2.6-.1l1.8.9c.3.1.4.2.5.3.1.2.1.7-.1 1.3z"/></svg></a>
    <script src="<?= e(app_asset('script/fiber-bg.js')); ?>" defer></script>
    <script>
    (function(){
      var RM=matchMedia('(prefers-reduced-motion: reduce)').matches, fine=matchMedia('(hover:hover) and (pointer:fine)').matches;
      // Loading ekranı (logonu göstərib açılır)
      var ldr=document.getElementById('fiber-loader');
      if(ldr){window.addEventListener('load',function(){setTimeout(function(){ldr.classList.add('done');},RM?0:900);});
        setTimeout(function(){ldr.classList.add('done');},2600);}
      // Custom cursor (enerji nöqtəsi + halqa)
      if(fine&&!RM){var dot=document.querySelector('.cursor-dot'),ring=document.querySelector('.cursor-ring'),rx=0,ry=0,x=0,y=0;
        window.addEventListener('mousemove',function(e){x=e.clientX;y=e.clientY;if(dot){dot.style.left=x+'px';dot.style.top=y+'px';}});
        (function loop(){rx+=(x-rx)*0.18;ry+=(y-ry)*0.18;if(ring){ring.style.left=rx+'px';ring.style.top=ry+'px';}requestAnimationFrame(loop);})();
        document.addEventListener('mouseover',function(e){if(ring)ring.classList.toggle('is-hover',!!e.target.closest('a,button,.plan,.tv-chip,.pay-card,input,select'));});}
      // Stat sayğacları + scroll-reveal (IntersectionObserver)
      if('IntersectionObserver' in window){
        var io=new IntersectionObserver(function(es){es.forEach(function(en){ if(!en.isIntersecting)return;var el=en.target;
          if(el.hasAttribute('data-stats')){[].slice.call(el.querySelectorAll('.stat-num')).forEach(function(n){
            var to=parseFloat(n.getAttribute('data-count')),suf=n.getAttribute('data-suffix')||'',dec=(to%1!==0),t0=performance.now(),D=RM?0:1400;
            (function tick(t){var p=Math.min(1,(t-t0)/(D||1)),v=to*(1-Math.pow(1-p,3));n.textContent=(dec?v.toFixed(2):Math.round(v).toLocaleString('en-US'))+suf;if(p<1)requestAnimationFrame(tick);})(t0);});}
          if(el.hasAttribute('data-spd'))el.classList.add('on');
          if(el.hasAttribute('data-signal'))el.classList.add('on');
          io.unobserve(el);});},{threshold:.35});
        [].slice.call(document.querySelectorAll('[data-stats],[data-spd],[data-signal]')).forEach(function(el){io.observe(el);});
      } else {[].slice.call(document.querySelectorAll('[data-spd],[data-signal]')).forEach(function(el){el.classList.add('on');});}
      // Mobile nav (aç/bağla + linkə basanda bağlan + kənara basanda bağlan)
      var t=document.querySelector('[data-mobile-nav-toggle]'),n=document.querySelector('[data-mobile-nav]');
      if(t&&n){
        t.addEventListener('click',function(e){e.stopPropagation();n.classList.toggle('is-open');});
        n.querySelectorAll('a').forEach(function(a){a.addEventListener('click',function(){n.classList.remove('is-open');});});
        document.addEventListener('click',function(e){if(n.classList.contains('is-open')&&!n.contains(e.target)&&!t.contains(e.target)){n.classList.remove('is-open');}});
      }
      // Language dropdown
      var ld=document.querySelector('[data-lang-dropdown]'),lt=document.querySelector('[data-lang-toggle]');
      if(ld&&lt){lt.addEventListener('click',function(e){e.stopPropagation();ld.classList.toggle('open');});
        document.addEventListener('click',function(){ld.classList.remove('open');});}
      // TV channel search (right-side results list)
      var tvS=document.getElementById('channelSearch'),tvR=document.getElementById('tv-results'),tvC=document.getElementById('tv-count'),tvE=document.getElementById('tv-empty');
      if(tvS&&tvR){var cards=[].slice.call(tvR.querySelectorAll('.tv-result-card'));
        tvS.addEventListener('input',function(){var q=tvS.value.trim().toLowerCase(),n=0;
          cards.forEach(function(c){var ok=c.getAttribute('data-channel-name').indexOf(q)>-1;c.style.display=ok?'':'none';if(ok)n++;});
          if(tvC)tvC.textContent=n; if(tvE)tvE.hidden=n>0;});}
      // Flash mesajı: URL-dən status-u təmizlə (təzələmə təkrar göstərməsin) + avto-gizlət
      var flashEl=document.querySelector("[data-flash-notice]");
      if(flashEl){
        try{var u=new URL(window.location.href);if(u.searchParams.has("status")){u.searchParams.delete("status");history.replaceState(null,"",u.pathname+(u.search?u.search:"")+u.hash);}}catch(e){}
        setTimeout(function(){flashEl.style.transition="opacity .5s,transform .5s";flashEl.style.opacity="0";flashEl.style.transform="translateY(-8px)";setTimeout(function(){flashEl.style.display="none";},500);},6000);
      }
      // Theme toggle (dark/light)
      var tt=document.querySelector("[data-theme-toggle]");
      if(tt)tt.addEventListener("click",function(){var cur=document.documentElement.getAttribute("data-theme")==="light"?"dark":"light";document.documentElement.setAttribute("data-theme",cur);try{localStorage.setItem("so-theme",cur);}catch(e){}});
      // Tariff carousel nav
      var track=document.getElementById("tariff-track");
      if(track){document.querySelectorAll("[data-tc]").forEach(function(b){b.addEventListener("click",function(){var card=track.querySelector(".tariff-row");var step=card?card.offsetWidth+16:280;track.scrollBy({left:b.getAttribute("data-tc")==="next"?step*2:-step*2,behavior:"smooth"});});});}
      // Tariff filter
      var tfB=[].slice.call(document.querySelectorAll("[data-tf]")),tfR=[].slice.call(document.querySelectorAll(".tariff-row"));
      tfB.forEach(function(b){b.addEventListener("click",function(){tfB.forEach(function(x){x.classList.remove("active");});b.classList.add("active");var f=b.getAttribute("data-tf");tfR.forEach(function(r){r.style.display=(f==="all"||r.getAttribute("data-aud")===f)?"":"none";});});});
      // News category filter
      var nf=[].slice.call(document.querySelectorAll('[data-news-filter]')),nc=[].slice.call(document.querySelectorAll('[data-news-cat]'));
      nf.forEach(function(b){b.addEventListener('click',function(){nf.forEach(function(x){x.classList.remove('active');});b.classList.add('active');
        var f=b.getAttribute('data-news-filter');nc.forEach(function(c){c.style.display=(f==='all'||c.getAttribute('data-news-cat')===f)?'':'none';});});});
      // Live speed flicker
      var s=document.getElementById('live-speed');
      if(s&&!matchMedia('(prefers-reduced-motion: reduce)').matches)setInterval(function(){s.textContent=930+Math.floor(Math.random()*18);},900);
      // Coverage network map
      var nm=document.getElementById('net-map');
      if(nm&&nm.getContext&&!matchMedia('(prefers-reduced-motion: reduce)').matches){
        var c=nm.getContext('2d'),DPR=Math.min(2,devicePixelRatio||1),w,h,nodes=[];
        for(var i=0;i<13;i++)nodes.push({x:Math.random(),y:Math.random(),r:1.4+Math.random()*2.6});
        function draw(t){var r=nm.getBoundingClientRect();w=nm.width=r.width*DPR;h=nm.height=r.height*DPR;c.clearRect(0,0,w,h);
          for(var i=0;i<nodes.length;i++)for(var j=i+1;j<nodes.length;j++){var a=nodes[i],b=nodes[j],d=Math.hypot(a.x-b.x,a.y-b.y);
            if(d<0.36){c.strokeStyle='rgba(46,230,224,'+(0.24*(1-d/0.36))+')';c.lineWidth=DPR;c.beginPath();c.moveTo(a.x*w,a.y*h);c.lineTo(b.x*w,b.y*h);c.stroke();}}
          for(var k=0;k<nodes.length;k++){var nd=nodes[k],px=nd.x*w,py=nd.y*h,g=c.createRadialGradient(px,py,0,px,py,nd.r*4*DPR);
            g.addColorStop(0,'#2ee6e0');g.addColorStop(1,'transparent');c.fillStyle=g;c.beginPath();c.arc(px,py,nd.r*4*DPR,0,6.28);c.fill();
            c.fillStyle='#c9f7f3';c.beginPath();c.arc(px,py,nd.r*DPR,0,6.28);c.fill();
            nd.x+=Math.sin(t/2400+nd.y*9)*0.00035;nd.y+=Math.cos(t/2600+nd.x*9)*0.00035;}
          requestAnimationFrame(draw);}
        requestAnimationFrame(draw);
      }
    })();
    </script>
</body>
</html>
