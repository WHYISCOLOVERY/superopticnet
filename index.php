<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$lang = app_lang();
$flash = app_flash_message();
$hasRecaptcha = app_config('recaptcha_site_key') !== '';
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
        'title' => 'Super Optik | Fiber internet, TV, IT dəstək və proqram təminatı',
        'description' => 'Super Optik Bakıda və bölgələrdə fiber internet, TV xidməti, biznes interneti, IT dəstək, server, şəbəkə və proqram təminatı həlləri təqdim edir.',
        'keywords' => 'internet, internet tv, fiber internet, biznes interneti, tv xidməti, it dəstək, it support, proqram təminatı, software, server, şəbəkə, lan wan, super optik',
        'service_type' => 'Fiber internet, TV, IT dəstək və proqram təminatı',
        'locale' => 'az_AZ',
    ],
    'en' => [
        'title' => 'Super Optik | Fiber internet, TV, IT support and software',
        'description' => 'Super Optik provides fiber internet, TV service, business connectivity, IT support, server, network and software solutions in Baku and regional areas.',
        'keywords' => 'fiber internet, internet tv, business internet, tv service, IT support, software, server, network solutions, LAN WAN, Super Optik',
        'service_type' => 'Fiber internet, TV, IT support and software',
        'locale' => 'en_US',
    ],
    'ru' => [
        'title' => 'Super Optik | Оптоволоконный интернет, ТВ, IT-поддержка и ПО',
        'description' => 'Super Optik предоставляет оптоволоконный интернет, ТВ, бизнес-интернет, IT-поддержку, серверные, сетевые и программные решения в Баку и регионах.',
        'keywords' => 'интернет, интернет тв, оптоволоконный интернет, бизнес интернет, тв, it поддержка, программное обеспечение, сервер, сеть, Super Optik',
        'service_type' => 'Оптоволоконный интернет, ТВ, IT-поддержка и программное обеспечение',
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
    ['audience' => 'business', 'filter' => 'internet', 'type' => ['az' => 'Biznes internet', 'en' => 'Business internet', 'ru' => 'Бизнес интернет'], 'speed' => 100, 'price' => 30, 'features' => ['az' => ['Fərdi paketdən 5 AZN yuxarı baza', 'Prioritet dəstək', 'Ofis üçün sabit xətt'], 'en' => ['Base price 5 AZN above home plan', 'Priority support', 'Stable line for office use'], 'ru' => ['Базовая цена на 5 AZN выше домашнего тарифа', 'Приоритетная поддержка', 'Стабильная линия для офиса']]],
    ['audience' => 'business', 'filter' => 'internet-tv', 'type' => ['az' => 'Biznes internet + TV', 'en' => 'Business internet + TV', 'ru' => 'Бизнес интернет + ТВ'], 'speed' => 100, 'price' => 40, 'features' => ['az' => ['Baza qiymət + TV 10 AZN', 'Gözləmə zonası üçün TV', 'Fiber optik xətt'], 'en' => ['Base price + TV 10 AZN', 'TV for waiting areas', 'Fiber line'], 'ru' => ['Базовая цена + ТВ 10 AZN', 'ТВ для зоны ожидания', 'Оптоволоконная линия']]],
    ['audience' => 'business', 'filter' => 'internet-phone', 'type' => ['az' => 'Biznes internet + Telefon', 'en' => 'Business internet + Phone', 'ru' => 'Бизнес интернет + Телефон'], 'speed' => 100, 'price' => 40, 'features' => ['az' => ['Baza qiymət + Telefon 10 AZN', 'Sabit əlaqə xətti', 'Ofis üçün uyğun'], 'en' => ['Base price + Phone 10 AZN', 'Fixed communication line', 'Suitable for office use'], 'ru' => ['Базовая цена + Телефон 10 AZN', 'Стационарная линия связи', 'Подходит для офиса']]],
    ['audience' => 'business', 'filter' => 'bundle', 'type' => ['az' => 'Biznes tam paket', 'en' => 'Business full bundle', 'ru' => 'Бизнес полный пакет'], 'speed' => 100, 'price' => 50, 'features' => ['az' => ['İnternet 30 AZN', 'TV +10 AZN', 'Telefon +10 AZN'], 'en' => ['Internet 30 AZN', 'TV +10 AZN', 'Phone +10 AZN'], 'ru' => ['Интернет 30 AZN', 'ТВ +10 AZN', 'Телефон +10 AZN']]],
    ['audience' => 'business', 'filter' => 'internet', 'type' => ['az' => 'Biznes internet', 'en' => 'Business internet', 'ru' => 'Бизнес интернет'], 'speed' => 150, 'price' => 35, 'features' => ['az' => ['Prioritet dəstək', 'Şəbəkə üçün daha geniş resurs', 'Fiber optik xətt'], 'en' => ['Priority support', 'More network capacity', 'Fiber line'], 'ru' => ['Приоритетная поддержка', 'Больше ресурса для сети', 'Оптоволоконная линия']]],
    ['audience' => 'business', 'filter' => 'bundle', 'type' => ['az' => 'Biznes tam paket', 'en' => 'Business full bundle', 'ru' => 'Бизнес полный пакет'], 'speed' => 150, 'price' => 55, 'features' => ['az' => ['İnternet', 'TV xidməti', 'Telefon xətti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Интернет', 'ТВ сервис', 'Телефонная линия']]],
    ['audience' => 'business', 'filter' => 'internet', 'type' => ['az' => 'Biznes internet', 'en' => 'Business internet', 'ru' => 'Бизнес интернет'], 'speed' => 250, 'price' => 41, 'features' => ['az' => ['Ofis və kamera sistemi üçün uyğun', 'Şəbəkə dayanıqlığı', 'Prioritet xidmət'], 'en' => ['Suitable for office and camera systems', 'Network stability', 'Priority service'], 'ru' => ['Подходит для офиса и камер', 'Стабильность сети', 'Приоритетный сервис']]],
    ['audience' => 'business', 'filter' => 'bundle', 'type' => ['az' => 'Biznes tam paket', 'en' => 'Business full bundle', 'ru' => 'Бизнес полный пакет'], 'speed' => 250, 'price' => 61, 'features' => ['az' => ['İnternet', 'TV xidməti', 'Telefon xətti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Интернет', 'ТВ сервис', 'Телефонная линия']]],
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
    <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">
    <meta name="theme-color" content="#10233f">
    <link rel="canonical" href="<?= e($canonicalUrl); ?>">
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
    <link rel="stylesheet" href="<?= e(app_asset('css/style.css')); ?>">
    <link rel="stylesheet" href="<?= e(app_asset('css/stylemedia.css')); ?>">
    <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@graph' => $schemaGraph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
</head>
    <body>
        <?php if ($flash !== null): ?>
        <div class="notice notice-<?= e($flash['type']); ?>" data-flash-notice>
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?= e($flash['message']); ?></span>
        </div>
    <?php endif; ?>

    <header class="site-header">
        <div class="container header-inner">
            <a href="index.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" class="brand" aria-label="Home">
                <img src="images/favicon.png" alt="Super Optik Tech logo">
                <span class="brand-mark">
                    <span class="brand-word brand-word-super">Super</span>
                    <span class="brand-word brand-word-optik">Optik</span>
                </span>
            </a>

            <button class="mobile-nav-toggle" type="button" aria-label="Menu" data-mobile-nav-toggle>
                <i class="fa-solid fa-bars"></i>
            </button>

            <nav class="main-nav" data-mobile-nav>
                <a href="#tariffs"><?= e($copy['nav'][1]); ?></a>
                <a href="#services"><?= e($copy['nav'][2]); ?></a>
                <a href="#coverage"><?= e($copy['nav'][3]); ?></a>
                <a href="#support"><?= e($copy['nav'][4]); ?></a>
                <a href="#news"><?= e($copy['nav'][5]); ?></a>
                <a href="#tvnet"><?= e($sectionLabels['tv'][$lang] ?? $sectionLabels['tv']['az']); ?></a>
                <a href="#payments"><?= e($sectionLabels['payments'][$lang] ?? $sectionLabels['payments']['az']); ?></a>
            </nav>

            <div class="header-actions" data-mobile-actions>
                <a class="header-link header-link-phone" href="<?= e($supportPhoneHref); ?>">
                    <i class="fa-solid fa-phone-volume"></i>
                    <span class="header-link-stack">
                        <strong><?= e($supportPhoneDisplay); ?></strong>
                        <small><?= e($publicCta[$lang]['call_note'] ?? $publicCta['az']['call_note']); ?></small>
                    </span>
                </a>
                <a class="header-link header-link-portal header-link-muted" href="portal-login.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>"><?= e($publicCta[$lang]['portal_label'] ?? $publicCta['az']['portal_label']); ?></a>

                <?php if ($showLanguageMenu): ?>
                <div class="language-dropdown">
                    <button class="language-button" type="button" aria-label="Language">
                        <img id="selectedLangFlag" src="images/<?= e($flagMap[$lang] ?? 'azerbaijan.png'); ?>" alt="<?= e(strtoupper($lang)); ?>">
                        <span id="selectedLangName"><?= e(strtoupper($lang)); ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="language-menu">
                        <a href="#" class="lang-option" data-lang="az"><img src="images/azerbaijan.png" alt="AZ"> <span>AZ</span></a>
                        <a href="#" class="lang-option" data-lang="en"><img src="images/eng.png" alt="EN"> <span>EN</span></a>
                        <a href="#" class="lang-option" data-lang="ru"><img src="images/rus.png" alt="RU"> <span>RU</span></a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
        <section class="hero-section" id="home">
            <div class="hero-backdrop"></div>
            <div class="container hero-grid">
                <div class="hero-copy">
                    <span class="eyebrow"><?= e($publicCta[$lang]['hero_eyebrow'] ?? $publicCta['az']['hero_eyebrow']); ?></span>
                    <h1><?= e($publicCta[$lang]['hero_title'] ?? $publicCta['az']['hero_title']); ?></h1>
                    <p class="hero-text"><?= e($publicCta[$lang]['hero_text'] ?? $publicCta['az']['hero_text']); ?></p>

                    <div class="hero-points">
                        <?php foreach (($salesHero[$lang]['proofs'] ?? $salesHero['az']['proofs']) as $proof): ?>
                            <div class="hero-point">
                                <i class="fa-solid fa-circle-check"></i>
                                <div>
                                    <strong><?= e($proof['value']); ?></strong>
                                    <span><?= e($proof['label']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="hero-actions">
                        <a href="#connection-form" class="button button-primary"><?= e($publicCta[$lang]['hero_primary'] ?? $publicCta['az']['hero_primary']); ?></a>
                        <a href="#coverage" class="button button-secondary"><?= e($publicCta[$lang]['hero_secondary'] ?? $publicCta['az']['hero_secondary']); ?></a>
                    </div>
                    <p class="hero-copy-note"><?= e($publicCta[$lang]['hero_note'] ?? $publicCta['az']['hero_note']); ?></p>
                </div>

                <div class="hero-panel">
                    <div class="panel-content is-active panel-content-primary" id="connection-form">
                        <div class="lead-box-title">
                            <h2><?= e($publicCta[$lang]['form_title'] ?? $publicCta['az']['form_title']); ?></h2>
                            <p><?= e($publicCta[$lang]['form_text'] ?? $publicCta['az']['form_text']); ?></p>
                        </div>

                        <form class="lead-form" action="form-telegram.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="lang" value="<?= e($lang); ?>">
                            <input type="hidden" name="message" value="<?= e($salesHero[$lang]['default_message'] ?? $salesHero['az']['default_message']); ?>">

                            <div class="form-row honeypot-row" aria-hidden="true">
                                <label for="website">Website</label>
                                <input id="website" type="text" name="website" tabindex="-1" autocomplete="off">
                            </div>

                            <p class="lead-form-hint"><?= e($publicCta[$lang]['form_hint'] ?? $publicCta['az']['form_hint']); ?></p>

                            <div class="form-row two-columns">
                                <div>
                                    <label for="area"><?= e($copy['lead']['area']); ?></label>
                                    <select id="area" name="area" required>
                                        <option value=""><?= e($copy['lead']['area_default']); ?></option>
                                        <?php foreach ($copy['coverage']['areas'] as $area): ?>
                                            <option value="<?= e($area); ?>"><?= e($area); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="phone"><?= e($copy['lead']['phone']); ?></label>
                                    <input id="phone" type="tel" name="phone" inputmode="numeric" placeholder="<?= e($copy['lead']['placeholders'][1]); ?>" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <label for="fullName"><?= e($copy['lead']['name']); ?></label>
                                <input id="fullName" type="text" name="full_name" placeholder="<?= e($copy['lead']['placeholders'][0]); ?>" required>
                            </div>

                            <div class="form-row">
                                <label for="serviceType"><?= e($copy['lead']['service']); ?></label>
                                <select id="serviceType" name="service_type" required>
                                    <?php foreach ($copy['lead']['options'] as $index => $option): ?>
                                        <option value="<?= $index === 0 ? '' : e($option); ?>"><?= e($option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-row">
                                <label for="email"><?= e($copy['lead']['email']); ?></label>
                                <input id="email" type="email" name="email" placeholder="<?= e($copy['lead']['placeholders'][2]); ?>">
                            </div>

                            <?php if ($hasRecaptcha): ?>
                                <div class="recaptcha-row">
                                    <div class="g-recaptcha" data-sitekey="<?= e(app_config('recaptcha_site_key')); ?>"></div>
                                </div>
                            <?php endif; ?>

                            <button class="button button-primary button-block" type="submit" data-submit-label data-submitting-label="<?= e($copy['lead']['submitting']); ?>"><?= e($copy['lead']['submit']); ?></button>
                        </form>
                    </div>

                </div>
            </div>
        </section>

        <section class="section section-featured-offers">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow"><?= e($featuredOfferSection[$lang]['eyebrow'] ?? $featuredOfferSection['az']['eyebrow']); ?></span>
                    <h2><?= e($featuredOfferSection[$lang]['title'] ?? $featuredOfferSection['az']['title']); ?></h2>
                    <p><?= e($featuredOfferSection[$lang]['text'] ?? $featuredOfferSection['az']['text']); ?></p>
                </div>

                <div class="featured-offers-grid">
                    <?php foreach ($featuredOffers as $offer): ?>
                        <article class="featured-offer-card">
                            <span class="featured-offer-tag"><?= e($offer['tag'][$lang] ?? $offer['tag']['az']); ?></span>
                            <h3><?= e($offer['name'][$lang] ?? $offer['name']['az']); ?></h3>
                            <div class="featured-offer-metrics">
                                <strong><?= e((string) $offer['speed']); ?> <small>Mb/s</small></strong>
                                <span><?= e((string) $offer['price']); ?> AZN <small><?= e($copy['tariffs']['per_month']); ?></small></span>
                            </div>
                            <p><?= e($offer['description'][$lang] ?? $offer['description']['az']); ?></p>
                            <a href="#tariffs" class="button button-secondary"><?= e($featuredOfferSection[$lang]['cta'] ?? $featuredOfferSection['az']['cta']); ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="order-flow-grid">
                    <div class="order-flow-copy">
                        <span class="eyebrow"><?= e($featuredOfferSection[$lang]['eyebrow'] ?? $featuredOfferSection['az']['eyebrow']); ?></span>
                        <h3><?= e($featuredOfferSection[$lang]['steps_title'] ?? $featuredOfferSection['az']['steps_title']); ?></h3>
                    </div>
                    <div class="order-flow-steps">
                        <?php foreach (($featuredOfferSection[$lang]['steps'] ?? $featuredOfferSection['az']['steps']) as $step): ?>
                            <article class="order-step-card">
                                <strong><?= e($step['title']); ?></strong>
                                <p><?= e($step['text']); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="section" id="tariffs">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow"><?= e($copy['tariffs']['eyebrow']); ?></span>
                    <h2><?= e($copy['tariffs']['title']); ?></h2>
                    <p><?= e($copy['tariffs']['subtitle']); ?></p>
                </div>

                <div class="audience-pills">
                    <button type="button" class="audience-pill is-active" data-audience="all"><?= e($copy['tariffs']['audiences'][0]); ?></button>
                    <button type="button" class="audience-pill" data-audience="home"><?= e($copy['tariffs']['audiences'][1]); ?></button>
                    <button type="button" class="audience-pill" data-audience="business"><?= e($copy['tariffs']['audiences'][2]); ?></button>
                </div>

                <div class="filter-pills">
                    <button type="button" class="filter-pill is-active" data-filter="all"><?= e($copy['tariffs']['filters'][0]); ?></button>
                    <button type="button" class="filter-pill" data-filter="internet"><?= e($copy['tariffs']['filters'][1]); ?></button>
                    <button type="button" class="filter-pill" data-filter="internet-tv"><?= e($copy['tariffs']['filters'][2]); ?></button>
                    <button type="button" class="filter-pill" data-filter="internet-phone"><?= e($copy['tariffs']['filters'][3]); ?></button>
                    <button type="button" class="filter-pill" data-filter="bundle"><?= e($copy['tariffs']['filters'][4]); ?></button>
                </div>

                <div class="tariff-slider-shell">
                    <button
                        type="button"
                        class="tariff-slider-btn tariff-slider-btn-prev"
                        data-tariff-scroll="prev"
                        aria-label="<?= e($tariffNavLabels['prev'][$lang] ?? $tariffNavLabels['prev']['az']); ?>"
                    >
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>

                    <div class="tariff-slider-track" data-tariff-track>
                        <div class="tariff-grid">
                            <?php foreach ($tariffs as $tariff): ?>
                                <article
                                    class="tariff-card<?= isset($tariff['badge']) ? ' tariff-card-badged' : ''; ?>"
                                    data-filter-item="<?= e($tariff['filter']); ?>"
                                    data-audience-item="<?= e($tariff['audience']); ?>"
                                    data-tariff-speed="<?= e((string) $tariff['speed']); ?>"
                                    data-popup-theme="<?= e($tariff['audience'] === 'business' ? 'business' : ($tariff['speed'] >= 250 ? 'gaming' : 'family')); ?>"
                                    data-tariff-card
                                >
                                    <div class="tariff-topline">
                                        <span class="tariff-segment tariff-segment-<?= e($tariff['audience']); ?>"><?= e($tariff['audience'] === 'business' ? $copy['tariffs']['segment_business'] : $copy['tariffs']['segment_home']); ?></span>
                                        <?php if (isset($tariff['badge'])): ?>
                                            <span class="tariff-badge"><?= e($tariff['badge'][$lang] ?? $tariff['badge']['az']); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="tariff-top">
                                        <span class="tariff-type"><?= e($tariff['type'][$lang] ?? $tariff['type']['az']); ?></span>
                                        <strong class="tariff-speed"><?= e((string) $tariff['speed']); ?> <small>Mb/s</small></strong>
                                    </div>

                                    <ul class="tariff-features">
                                        <?php foreach (($tariff['features'][$lang] ?? $tariff['features']['az']) as $feature): ?>
                                            <li><?= e($feature); ?></li>
                                        <?php endforeach; ?>
                                    </ul>

                                    <div class="tariff-bottom">
                                        <div class="tariff-price"><?= e((string) $tariff['price']); ?> AZN <span><?= e($copy['tariffs']['per_month']); ?></span></div>
                                        <a href="#contact" class="button button-secondary"><?= e($copy['tariffs']['order']); ?></a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="tariff-slider-btn tariff-slider-btn-next"
                        data-tariff-scroll="next"
                        aria-label="<?= e($tariffNavLabels['next'][$lang] ?? $tariffNavLabels['next']['az']); ?>"
                    >
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>

                <div class="modem-strip">
                    <?php foreach ($copy['tariffs']['modems'] as $modem): ?>
                        <article class="modem-card">
                            <strong><?= e($modem['title']); ?></strong>
                            <span><?= e($modem['text']); ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section section-alt" id="services">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow"><?= e($copy['services']['eyebrow']); ?></span>
                    <h2><?= e($copy['services']['title']); ?></h2>
                    <p><?= e($copy['services']['subtitle']); ?></p>
                </div>

                <div class="service-grid">
                    <?php foreach ($copy['services']['items'] as $service): ?>
                        <details class="service-card">
                            <summary>
                                <div class="service-icon"><i class="<?= e($service['icon']); ?>"></i></div>
                                <div>
                                    <h3><?= e($service['title']); ?></h3>
                                    <p><?= e($service['summary']); ?></p>
                                </div>
                                <i class="fa-solid fa-plus"></i>
                            </summary>
                            <div class="service-body">
                                <p><?= e($service['details']); ?></p>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section" id="coverage">
            <div class="container coverage-grid">
                <div class="coverage-copy">
                    <div class="section-heading left-aligned">
                        <span class="eyebrow"><?= e($copy['coverage']['eyebrow']); ?></span>
                        <h2><?= e($copy['coverage']['title']); ?></h2>
                        <p><?= e($copy['coverage']['subtitle']); ?></p>
                    </div>

                    <div class="coverage-directory">
                        <div class="coverage-search">
                            <label for="coverageSearch"><?= e($coveragePanel[$lang]['search_label']); ?></label>
                            <input id="coverageSearch" type="search" placeholder="<?= e($coveragePanel[$lang]['search_placeholder']); ?>" data-coverage-search>
                        </div>
                        <div class="coverage-list-scroll">
                            <ul class="coverage-list">
                                <?php foreach ($copy['coverage']['areas'] as $area): ?>
                                    <li data-coverage-item data-coverage-name="<?= e($area); ?>" data-coverage-key="<?= e($toSearchKey($area)); ?>">
                                        <strong><?= e($area); ?></strong>
                                        <small><?= e($coveragePanel[$lang]['service_tag']); ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <p class="coverage-list-empty" data-coverage-empty hidden><?= e($coveragePanel[$lang]['search_empty']); ?></p>
                    </div>
                </div>

                <div class="coverage-visual">
                    <div class="coverage-map">
                        <img src="images/az.svg" alt="Coverage map">
                        <?php foreach ($mapPoints as $point): ?>
                            <?php $pointLabel = $point['label'][$lang] ?? $point['label']['az']; ?>
                            <button type="button" id="<?= e($point['id']); ?>" class="coverage-point" style="top: calc(<?= e($point['top']); ?> - 2%); left: calc(<?= e($point['left']); ?> - 3%);" aria-label="<?= e($pointLabel); ?>">
                                <span><?= e($pointLabel); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="coverage-note coverage-interest-card">
                        <div class="coverage-interest-copy">
                            <strong><?= e($coveragePanel[$lang]['interest_title']); ?></strong>
                            <p><?= e($coveragePanel[$lang]['interest_text']); ?></p>
                        </div>
                        <form class="coverage-interest-form" action="area-interest.php" method="post">
                            <input type="hidden" name="lang" value="<?= e($lang); ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <label for="districtInterest"><?= e($coveragePanel[$lang]['interest_label']); ?></label>
                            <select id="districtInterest" name="district" required>
                                <option value=""><?= e($coveragePanel[$lang]['interest_placeholder']); ?></option>
                                <?php foreach ($districtOptions as $district): ?>
                                    <option value="<?= e($district); ?>"><?= e($district); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-primary"><?= e($coveragePanel[$lang]['interest_action']); ?></button>
                        </form>
                        <div class="coverage-stats">
                            <div class="coverage-stats-head">
                                <strong><?= e($coveragePanel[$lang]['stats_title']); ?></strong>
                                <span><?= e($coveragePanel[$lang]['stats_total']); ?>: <?= e((string) $areaInterestSummary['total']); ?></span>
                            </div>
                            <?php if ($areaInterestSummary['items'] !== []): ?>
                                <div class="coverage-stats-list">
                                    <?php foreach ($areaInterestSummary['items'] as $item): ?>
                                        <div class="coverage-stat-item">
                                            <span><?= e($item['district']); ?></span>
                                            <strong><?= e((string) $item['count']); ?></strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="coverage-stats-empty"><?= e($coveragePanel[$lang]['stats_empty']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section section-alt" id="support">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow"><?= e($copy['support']['eyebrow']); ?></span>
                    <h2><?= e($copy['support']['title']); ?></h2>
                    <p><?= e($copy['support']['subtitle']); ?></p>
                </div>

                <div class="support-grid">
                    <article class="support-card">
                        <div class="support-icon"><i class="fa-solid fa-wifi"></i></div>
                        <h3><?= e($copy['support']['wifi_title']); ?></h3>
                        <p><?= e($copy['support']['wifi_text']); ?></p>
                        <div class="modem-guide-list">
                            <?php foreach ($supportGuides as $guide): ?>
                                <details class="modem-guide">
                                    <summary><?= e($guide['title'][$lang] ?? $guide['title']['az']); ?></summary>
                                    <p><?= e($guide['summary'][$lang] ?? $guide['summary']['az']); ?></p>
                                    <ol>
                                        <?php foreach ($guide['steps'] as $step): ?>
                                            <li><?= e($step[$lang] ?? $step['az']); ?></li>
                                        <?php endforeach; ?>
                                    </ol>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </article>

                    <article class="support-card">
                        <div class="support-icon"><i class="fa-solid fa-circle-question"></i></div>
                        <h3><?= e($copy['support']['faq_title']); ?></h3>
                        <div class="faq-list">
                            <?php foreach ($copy['support']['faq'] as $faq): ?>
                                <details>
                                    <summary><?= e($faq['q']); ?></summary>
                                    <p><?= e($faq['a']); ?></p>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </article>

                    <article class="support-card">
                        <div class="support-icon"><i class="fa-solid fa-headset"></i></div>
                        <h3><?= e($copy['support']['contact_title']); ?></h3>
                        <div class="contact-list">
                            <a href="tel:+994125258811"><i class="fa-solid fa-phone"></i> +994 12 525 88 11</a>
                            <a href="mailto:info@superoptic.net"><i class="fa-solid fa-envelope"></i> info@superoptic.net</a>
                            <a href="https://wa.me/994512055566?text=Super%20Optik%20Tech" target="_blank" rel="noreferrer"><i class="fa-brands fa-whatsapp"></i> +994 51 205 55 66</a>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <section class="section" id="news">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow"><?= e($copy['news']['eyebrow']); ?></span>
                    <h2><?= e($copy['news']['title']); ?></h2>
                    <p><?= e($publicCta[$lang]['news_subtitle'] ?? $publicCta['az']['news_subtitle']); ?></p>
                </div>

                <div class="news-tabs">
                    <button class="news-tab is-active" type="button" data-news-tab="network"><?= e($copy['news']['tabs'][0]); ?></button>
                    <button class="news-tab" type="button" data-news-tab="systems"><?= e($copy['news']['tabs'][1]); ?></button>
                </div>

                <div class="news-grid">
                    <?php foreach ($copy['news']['items'] as $index => $item): ?>
                        <?php if (($item['category'] ?? '') === 'crypto') { continue; } ?>
                        <?php $visual = $newsVisuals[$index % count($newsVisuals)]; ?>
                        <?php $newsTone = $item['tone'] ?? $visual['tone']; ?>
                        <?php $newsImage = $item['image'] ?? $visual['image']; ?>
                        <article class="news-card news-card-<?= e($newsTone); ?><?= $item['category'] === 'network' ? ' is-visible' : ''; ?><?= !empty($item['featured']) ? ' news-card-featured' : ''; ?>" data-news-category="<?= e($item['category']); ?>">
                            <div class="news-media">
                                <img src="<?= e(app_asset($newsImage)); ?>" alt="<?= e($item['title']); ?>">
                                <div class="news-media-overlay">
                                    <span class="news-kicker"><?= e($item['source']); ?></span>
                                    <span class="news-category-badge"><?= e($item['category_label']); ?></span>
                                </div>
                            </div>
                            <div class="news-body">
                                <h3><?= e($item['title']); ?></h3>
                                <p><?= e($item['summary']); ?></p>
                                <div class="news-meta">
                                    <span><?= e($item['date']); ?></span>
                                    <span><?= e($item['category_label']); ?></span>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section section-tv" id="tvnet">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow"><?= e($tvSection[$lang]['eyebrow']); ?></span>
                    <h2><?= e($tvSection[$lang]['title']); ?></h2>
                    <p><?= e($tvSection[$lang]['subtitle']); ?></p>
                </div>

                <div class="tv-layout">
                    <div class="tv-showcase">
                        <div class="tv-marquee-stack">
                            <?php foreach ($tvChannelRows as $rowIndex => $rowChannels): ?>
                                <div class="tv-marquee-row tv-marquee-row-<?= e((string) ($rowIndex + 1)); ?>">
                                    <div class="tv-track">
                                        <?php foreach (array_merge($rowChannels, $rowChannels, $rowChannels) as $channel): ?>
                                            <?php $name = (string) $channel['name']; ?>
                                            <article class="tv-card" data-channel-card data-channel-name="<?= e($name); ?>">
                                                <span class="tv-card-logo"><?= e(mb_strtoupper(mb_substr($name, 0, 2))); ?></span>
                                                <div>
                                                    <strong><?= e($name); ?></strong>
                                                    <small><?= e($tvSection[$lang]['package_label']); ?>: <?= e((string) $channel['package']); ?></small>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <aside class="tv-search-panel">
                        <div class="tv-toolbar">
                            <div class="form-row">
                                <label for="channelSearch"><?= e($tvSection[$lang]['search_label']); ?></label>
                                <input id="channelSearch" type="search" placeholder="<?= e($tvSection[$lang]['search_placeholder']); ?>" data-channel-search>
                            </div>
                            <p class="tv-source-note"><?= e($tvSection[$lang]['source_note']); ?></p>
                        </div>

                        <div class="tv-results-list">
                            <?php foreach ($tvChannels as $channel): ?>
                                <?php $name = (string) $channel['name']; ?>
                                <article class="tv-result-card" data-channel-result data-channel-name="<?= e($name); ?>">
                                    <span class="tv-result-logo"><?= e(mb_strtoupper(mb_substr($name, 0, 2))); ?></span>
                                    <div class="tv-result-copy">
                                        <strong><?= e($name); ?></strong>
                                        <small><?= e($tvSection[$lang]['package_label']); ?>: <?= e((string) $channel['package']); ?></small>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <p class="tv-empty" data-channel-empty hidden><?= e($tvSection[$lang]['empty']); ?></p>
                    </aside>
                </div>
            </div>
        </section>

        <section class="section section-payments" id="payments">
            <div class="container">
                <div class="section-heading left-aligned">
                    <span class="eyebrow"><?= e($paymentSection[$lang]['eyebrow']); ?></span>
                    <h2><?= e($paymentSection[$lang]['title']); ?></h2>
                    <p><?= e($paymentSection[$lang]['subtitle']); ?></p>
                </div>

                <div class="payment-grid">
                    <?php foreach ($paymentProviders as $provider): ?>
                        <article class="payment-card payment-card-<?= e($provider['accent']); ?>">
                            <div class="payment-card-brand">
                                <img src="<?= e(app_asset($provider['logo'])); ?>" alt="<?= e($provider['name']); ?>">
                            </div>
                            <span class="payment-card-name"><?= e($provider['name']); ?></span>
                            <div class="payment-card-actions">
                                <span class="payment-card-note"><?= e(($paymentLinkCopy[$lang]['note'] ?? $paymentLinkCopy['az']['note']) . ': ' . $provider['domain']); ?></span>
                                <a class="payment-card-link" href="<?= e($provider['href']); ?>" target="_blank" rel="noreferrer"><?= e($paymentLinkCopy[$lang]['cta'] ?? $paymentLinkCopy['az']['cta']); ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section section-accent" id="contact">
            <div class="container contact-cta">
                <div>
                    <span class="eyebrow"><?= e($copy['contact']['eyebrow']); ?></span>
                    <h2><?= e($copy['contact']['title']); ?></h2>
                    <p><?= e($copy['contact']['text']); ?></p>
                </div>
                <div class="contact-actions">
                    <a class="button button-primary" href="tel:+994125258811">+994 12 525 88 11</a>
                    <a class="button button-secondary" href="https://wa.me/994512055566?text=Super%20Optik%20Tech" target="_blank" rel="noreferrer">WhatsApp</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer site-footer-detailed">
        <div class="container footer-detailed-grid">
            <div class="footer-brand-block">
                <a href="#home" class="brand brand-footer">
                    <img src="images/favicon.png" alt="Super Optik Tech logo">
                    <span class="brand-mark">
                        <span class="brand-word brand-word-super">Super</span>
                        <span class="brand-word brand-word-optik">Optik</span>
                    </span>
                </a>
                <p><?= e($copy['footer']); ?></p>
                <div class="footer-links">
                    <a href="https://www.instagram.com/superoptiktech/" target="_blank" rel="noreferrer"><i class="fa-brands fa-instagram"></i></a>
                    <a href="mailto:info@superoptic.net"><i class="fa-solid fa-envelope"></i></a>
                    <a href="tel:+994125258811"><i class="fa-solid fa-phone"></i></a>
                    <a href="https://e-complaint.icta.az/" target="_blank" rel="noreferrer">ICT</a>
                </div>
            </div>

            <div class="footer-column">
                <strong><?= e($footerLabels[$lang]['about'] ?? $footerLabels['az']['about']); ?></strong>
                <a href="haqqimizda.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>"><?= e($footerLabels[$lang]['about'] ?? $footerLabels['az']['about']); ?></a>
                <a href="haqqimizda.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>#mission"><?= e($footerLabels[$lang]['mission'] ?? $footerLabels['az']['mission']); ?></a>
                <a href="#contact"><?= e($footerLabels[$lang]['contact'] ?? $footerLabels['az']['contact']); ?></a>
            </div>

            <div class="footer-column">
                <strong><?= e($footerLabels[$lang]['media'] ?? $footerLabels['az']['media']); ?></strong>
                <a href="#news"><?= e($copy['news']['eyebrow']); ?></a>
                <a href="#tvnet"><?= e($sectionLabels['tv'][$lang] ?? $sectionLabels['tv']['az']); ?></a>
                <a href="https://www.speedtest.net/" target="_blank" rel="noreferrer"><?= e($footerLabels[$lang]['speedtest'] ?? $footerLabels['az']['speedtest']); ?></a>
            </div>

            <div class="footer-column">
                <strong><?= e($footerLabels[$lang]['legal'] ?? $footerLabels['az']['legal']); ?></strong>
                <a href="gizlilik-siyaseti.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>"><?= e($footerLabels[$lang]['privacy'] ?? $footerLabels['az']['privacy']); ?></a>
                <a href="qaydalar-ve-sertler.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>"><?= e($footerLabels[$lang]['rules'] ?? $footerLabels['az']['rules']); ?></a>
                <a href="cihazlar.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>"><?= e($footerLabels[$lang]['devices'] ?? $footerLabels['az']['devices']); ?></a>
                <a href="keyfiyyet-gostericileri.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>"><?= e($footerLabels[$lang]['quality'] ?? $footerLabels['az']['quality']); ?></a>
            </div>
        </div>
        <div class="container footer-bottom footer-bottom-detailed">
            <p><?= e($copyrightLine[$lang] ?? $copyrightLine['az']); ?></p>
        </div>
    </footer>

    <a class="whatsapp-float" href="<?= e($supportWhatsappHref); ?>" target="_blank" rel="noreferrer" aria-label="WhatsApp">
        <i class="fa-brands fa-whatsapp"></i>
        <span class="whatsapp-float-text">
            <strong><?= e($publicCta[$lang]['whatsapp_label'] ?? $publicCta['az']['whatsapp_label']); ?></strong>
            <small><?= e($publicCta[$lang]['whatsapp_note'] ?? $publicCta['az']['whatsapp_note']); ?></small>
        </span>
    </a>

    <div class="promo-popup-overlay" id="tariffPopup" hidden>
        <div class="promo-popup" data-popup-card data-theme="family">
            <button class="promo-close" type="button" data-popup-close aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div class="promo-visual-shell">
                <img
                    id="popupVisual"
                    class="promo-visual"
                    src="<?= e(app_asset('images/promoaile.jfif')); ?>"
                    alt="<?= e($copy['popup']['title']); ?>"
                >
            </div>
            <div class="promo-meta">
                <span class="eyebrow"><?= e($copy['popup']['label']); ?></span>
                <span class="promo-theme-tag" id="popupThemeLabel"><?= e($copy['popup']['offer']); ?></span>
            </div>
            <h3 id="popupTitle"><?= e($copy['popup']['title']); ?></h3>
            <p class="promo-type" id="popupPackageType"><?= e($copy['popup']['text']); ?></p>
            <div class="promo-metrics">
                <strong><span id="popupSpeed">100</span> Mb/s</strong>
                <span><span id="popupPrice">30</span> AZN <small><?= e($copy['tariffs']['per_month']); ?></small></span>
            </div>
            <p id="popupText"><?= e($copy['popup']['text']); ?></p>
            <a href="#connection-form" class="button button-primary" data-popup-action><?= e($copy['tariffs']['order']); ?></a>
        </div>
    </div>

    <?php if ($hasRecaptcha): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <script src="<?= e(app_asset('script/translations.js')); ?>"></script>
    <script src="<?= e(app_asset('script/main.js')); ?>"></script>
</body>
</html>
