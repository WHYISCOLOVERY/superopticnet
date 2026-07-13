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
        'nav' => ['Ana', 'TariflÉ™r', 'XidmÉ™t', 'ÆhatÉ™', 'DÉ™stÉ™k', 'XÉ™bÉ™r', 'ÆlaqÉ™'],
        'pay_now' => 'Ã–dÉ™niÅŸ et',
        'portal' => 'Portal',
        'hero_badge' => 'Fiber internet vÉ™ sistem hÉ™llÉ™ri',
        'hero_title' => 'Ev vÉ™ biznes Ã¼Ã§Ã¼n dayanÄ±qlÄ± fiber internet',
        'hero_text' => 'FÉ™rdi istifadÉ™Ã§ilÉ™r vÉ™ obyektlÉ™r Ã¼Ã§Ã¼n fiber internet, TV, telefon xÉ™tti, modem seÃ§imi vÉ™ ÅŸÉ™bÉ™kÉ™ hÉ™llÉ™rini bir yerdÉ™ tÉ™qdim edirik.',
        'hero_actions' => ['TariflÉ™rÉ™ bax', 'DÉ™stÉ™k mÉ™rkÉ™zi'],
        'highlights' => [
            ['icon' => 'fa-solid fa-gauge-high', 'title' => '100-1000 Mb/s', 'text' => 'Ev vÉ™ biznes Ã¼Ã§Ã¼n fÉ™rqli sÃ¼rÉ™t planlarÄ±'],
            ['icon' => 'fa-solid fa-headset', 'title' => '7/24 dÉ™stÉ™k', 'text' => 'Texniki komandadan operativ geri dÃ¶nÃ¼ÅŸ'],
            ['icon' => 'fa-solid fa-network-wired', 'title' => 'Sistem hÉ™llÉ™ri', 'text' => 'LAN/WAN, server, IP telefoniya vÉ™ tÉ™hlÃ¼kÉ™sizlik'],
        ],
        'panel_tabs' => ['MÃ¼raciÉ™t', 'ÅÉ™xsi kabinet'],
        'lead' => [
            'title' => 'QoÅŸulma vÉ™ É™laqÉ™',
            'subtitle' => 'SadÉ™ formu doldurun, komandamÄ±z sizinlÉ™ qÄ±sa zamanda É™laqÉ™ saxlasÄ±n.',
            'name' => 'Ad vÉ™ soyad',
            'phone' => 'Telefon',
            'email' => 'E-mail',
            'service' => 'MaraqlandÄ±ÄŸÄ±nÄ±z xidmÉ™t',
            'area' => 'Ærazi',
            'note' => 'QÄ±sa qeyd',
            'placeholders' => ['Ad vÉ™ soyad', '+994501234567', 'info@example.com', 'MÃ¼raciÉ™tinizi qÄ±sa ÅŸÉ™kildÉ™ yazÄ±n'],
            'options' => ['XidmÉ™ti seÃ§in', 'Ev interneti', 'Biznes interneti', 'Texniki dÉ™stÉ™k', 'ÅÉ™bÉ™kÉ™ vÉ™ sistem hÉ™lli'],
            'area_default' => 'Ærazini seÃ§in',
            'submit' => 'GÃ¶ndÉ™r',
            'submitting' => 'GÃ¶ndÉ™rilir...',
        ],
        'portal_box' => [
            'title' => 'Portal giriÅŸi',
            'text' => 'MÃ¼ÅŸtÉ™ri billing mÉ™lumatlarÄ±nÄ±, É™mÉ™kdaÅŸ isÉ™ roluna uyÄŸun billing vÉ™ OLT keÃ§idlÉ™rini bu portaldan istifadÉ™ edÉ™cÉ™k.',
            'points' => ['MÃ¼ÅŸtÉ™ri: trafik, Ã¶dÉ™niÅŸ, Ã§Ä±xarÄ±ÅŸ vÉ™ mÃ¼raciÉ™tlÉ™r', 'ÆmÉ™kdaÅŸ: billing keÃ§idi vÉ™ OLT idarÉ™etmÉ™si'],
            'action' => 'Portal giriÅŸ sÉ™hifÉ™sini aÃ§',
        ],
        'tariffs' => [
            'eyebrow' => 'TariflÉ™r',
            'title' => 'FÉ™rdi vÉ™ biznes internet paketlÉ™ri',
            'subtitle' => 'Ev vÉ™ biznes tariflÉ™rini ayrÄ±ca seÃ§in. FÉ™rdi tariflÉ™rdÉ™ TV +6 AZN, telefon +4 AZN; biznes tariflÉ™rindÉ™ isÉ™ TV +10 AZN vÉ™ telefon +10 AZN É™lavÉ™ olunur.',
            'audiences' => ['BÃ¼tÃ¼n paketlÉ™r', 'FÉ™rdi tariflÉ™r', 'Biznes tariflÉ™ri'],
            'filters' => ['BÃ¼tÃ¼n tariflÉ™r', 'Ä°nternet', 'Ä°nternet + TV', 'Ä°nternet + Telefon', 'Ä°nternet + TV + Telefon xÉ™tti'],
            'segment_home' => 'FÉ™rdi',
            'segment_business' => 'Biznes',
            'per_month' => '/ay',
            'order' => 'SifariÅŸ et',
            'modems' => [
                ['title' => 'HSGQ GPON modem', 'text' => 'Standart fiber abonÉ™lÉ™r Ã¼Ã§Ã¼n stabil hÉ™ll'],
                ['title' => 'Huawei dual-band modem', 'text' => '2.4 GHz vÉ™ 5 GHz istifadÉ™si Ã¼Ã§Ã¼n uyÄŸun seÃ§im'],
                ['title' => 'TP-Link router', 'text' => 'BÃ¶yÃ¼k ev vÉ™ ofislÉ™r Ã¼Ã§Ã¼n É™lavÉ™ Wi-Fi paylanmasÄ±'],
            ],
        ],
        'services' => [
            'eyebrow' => 'Ev vÉ™ biznes Ã¼Ã§Ã¼n xidmÉ™tlÉ™r',
            'title' => 'Ä°nternetdÉ™n kÉ™nar dÉ™yÉ™r yaradan hÉ™llÉ™r',
            'subtitle' => 'SadÉ™cÉ™ internet satÄ±ÅŸÄ± yox, hÉ™m dÉ™ ÅŸÉ™bÉ™kÉ™, sistem, IT dÉ™stÉ™k, proqram tÉ™minatÄ±, telefoniya vÉ™ tÉ™hlÃ¼kÉ™sizlik Ã¼zrÉ™ É™mÉ™li dÉ™stÉ™k tÉ™qdim edirik.',
            'items' => [
                ['icon' => 'fa-solid fa-house-signal', 'title' => 'Ev interneti', 'summary' => 'AilÉ™ vÉ™ fÉ™rdi istifadÉ™ Ã¼Ã§Ã¼n fiber internet, TV vÉ™ telefon paketlÉ™ri.', 'details' => 'QuraÅŸdÄ±rma, modem seÃ§imi, Wi-Fi tÃ¶vsiyÉ™si vÉ™ Ã¶dÉ™niÅŸ yÃ¶nlÉ™ndirilmÉ™si bir axÄ±nda tÉ™qdim olunur.'],
                ['icon' => 'fa-solid fa-building', 'title' => 'Biznes interneti', 'summary' => 'Ofis vÉ™ obyektlÉ™r Ã¼Ã§Ã¼n daha sabit É™laqÉ™ vÉ™ prioritet dÉ™stÉ™k.', 'details' => 'Biznes tariflÉ™rindÉ™ internet baza qiymÉ™ti fÉ™rdi tarifdÉ™n 5 AZN yuxarÄ± olur, TV vÉ™ telefon isÉ™ ayrÄ±ca É™lavÉ™ olunur.'],
                ['icon' => 'fa-solid fa-diagram-project', 'title' => 'ÅÉ™bÉ™kÉ™ layihÉ™lÉ™ndirilmÉ™si', 'summary' => 'LAN/WAN topologiyasÄ±, switch vÉ™ router konfiqurasiyasÄ±.', 'details' => 'KiÃ§ik ofisdÉ™n Ã§oxmÉ™rtÉ™bÉ™li obyektÉ™ qÉ™dÉ™r daxili vÉ™ uzaq ÅŸÉ™bÉ™kÉ™ layihÉ™lÉ™ri qurula bilÉ™r.'],
                ['icon' => 'fa-solid fa-server', 'title' => 'Server vÉ™ monitorinq', 'summary' => 'Fayl serveri, backup, monitorinq vÉ™ mÉ™rkÉ™zlÉ™ÅŸdirilmiÅŸ idarÉ™etmÉ™.', 'details' => 'XidmÉ™tlÉ™rin dayanÄ±qlÄ± iÅŸlÉ™mÉ™si Ã¼Ã§Ã¼n server, backup vÉ™ monitorinq hÉ™llÉ™ri ayrÄ±ca planlana bilÉ™r.'],
                ['icon' => 'fa-solid fa-phone-volume', 'title' => 'IP telefoniya', 'summary' => 'Daxili nÃ¶mrÉ™lÉ™mÉ™, Ã§aÄŸrÄ± yÃ¶nlÉ™ndirmÉ™ vÉ™ IVR hÉ™llÉ™ri.', 'details' => 'Telefon xÉ™tti É™lavÉ™ olunan paketlÉ™rdÉ™ biznes Ã¼Ã§Ã¼n daha rahat É™laqÉ™ axÄ±nÄ± qurmaq mÃ¼mkÃ¼ndÃ¼r.'],
                ['icon' => 'fa-solid fa-shield-halved', 'title' => 'TÉ™hlÃ¼kÉ™sizlik vÉ™ lisenziya', 'summary' => 'ÅÉ™bÉ™kÉ™ tÉ™hlÃ¼kÉ™sizliyi, lisenziyalaÅŸdÄ±rma vÉ™ É™sas audit dÉ™stÉ™yi.', 'details' => 'Kamera, server vÉ™ ofis ÅŸÉ™bÉ™kÉ™lÉ™rindÉ™ giriÅŸ nÉ™zarÉ™ti, yenilÉ™nmÉ™ planÄ± vÉ™ tÉ™hlÃ¼kÉ™sizlik qaydalarÄ± qurula bilÉ™r.'],
            ],
        ],
        'coverage' => [
            'eyebrow' => 'ÆhatÉ™ dairÉ™si',
            'title' => 'BakÄ± vÉ™ bÃ¶lgÉ™lÉ™r Ã¼zrÉ™ xidmÉ™t gÃ¶stÉ™rdiyimiz É™razilÉ™r',
            'subtitle' => 'ÆhatÉ™ dairÉ™sini hÉ™m siyahÄ±, hÉ™m dÉ™ xÉ™ritÉ™ Ã¼zÉ™rindÉ™ gÃ¶rÉ™ bilÉ™rsiniz.',
            'note_title' => 'Yeni É™razi É™lavÉ™ olunursa',
            'note_text' => 'QÄ±sa mÃ¼raciÉ™t buraxÄ±n, komanda texniki imkan vÉ™ qoÅŸulma mÃ¼ddÉ™tini sizÉ™ geri dÃ¶nÃ¼ÅŸdÉ™ tÉ™qdim etsin.',
            'areas' => ['Astara', 'MasallÄ±', 'LÉ™nkÉ™ran', 'NeftÃ§ala', 'Ä°smayÄ±llÄ±', 'Nizami', 'SabunÃ§u', 'SuraxanÄ±', 'AbÅŸeron', 'XÉ™tai', 'BinÉ™qÉ™di', 'KÃ¼rdÉ™xanÄ±', 'PirÅŸaÄŸÄ±', 'Mehdiabad', 'MÉ™mmÉ™dli', 'Zabrat', 'QaraÃ§uxur', 'BakÄ±xanov', 'ÆhmÉ™dli', 'BilÉ™cÉ™ri', 'GÃ¼nÉ™ÅŸli'],
            'points' => ['Astara', 'MasallÄ±', 'LÉ™nkÉ™ran', 'NeftÃ§ala', 'Ä°smayÄ±llÄ±', 'AbÅŸeron', 'BakÄ±', 'SuraxanÄ±', 'SabunÃ§u', 'XÉ™tai', 'BinÉ™qÉ™di', 'GÃ¼nÉ™ÅŸli'],
        ],
        'support' => [
            'eyebrow' => 'DÉ™stÉ™k mÉ™rkÉ™zi',
            'title' => 'ÆtraflÄ± izah vÉ™ Ã¶zÃ¼nÉ™xidmÉ™t bloklarÄ±',
            'subtitle' => 'ModemlÉ™r Ã¼zrÉ™ ÅŸifrÉ™ yenilÉ™mÉ™ tÉ™limatÄ±, geniÅŸ FAQ vÉ™ birbaÅŸa É™laqÉ™ kanallarÄ± burada toplanÄ±r.',
            'wifi_title' => 'Wi-Fi ÅŸifrÉ™sini dÉ™yiÅŸmÉ™k',
            'wifi_text' => 'Modem markasÄ±na gÃ¶rÉ™ fÉ™rqli idarÉ™etmÉ™ paneli olsa da, É™sas addÄ±mlar aÅŸaÄŸÄ±dakÄ± kimidir.',
            'faq_title' => 'Tez-tez verilÉ™n suallar',
            'contact_title' => 'BirbaÅŸa É™laqÉ™',
            'guides' => [
                ['title' => 'HSGQ modemlÉ™ri', 'summary' => 'BrauzerdÉ™ `192.168.1.1` vÉ™ ya cihazÄ±n Ã¼zÉ™rindÉ™ yazÄ±lan Ã¼nvana daxil olun.', 'steps' => ['Wireless vÉ™ ya Wi-Fi bÃ¶lmÉ™sini aÃ§Ä±n.', '2.4 GHz vÉ™ 5 GHz Ã¼Ã§Ã¼n SSID adlarÄ±nÄ± ayrÄ±ca yoxlayÄ±n.', 'Yeni ÅŸifrÉ™ni `WPA2/WPA3` formatÄ±nda tÉ™yin edib yadda saxlayÄ±n.', 'DÉ™yiÅŸiklikdÉ™n sonra cihazlarÄ± yeni ÅŸifrÉ™ ilÉ™ yenidÉ™n qoÅŸun.']],
                ['title' => 'Huawei modemlÉ™ri', 'summary' => 'Æn Ã§ox giriÅŸ Ã¼nvanÄ± `192.168.100.1` vÉ™ ya `192.168.1.1` olur.', 'steps' => ['WLAN bÃ¶lmÉ™sinÉ™ keÃ§in.', '2.4G vÉ™ 5G ÅŸÉ™bÉ™kÉ™lÉ™rini ayrÄ±ca yoxlayÄ±n.', 'SSID Name vÉ™ WPA PreSharedKey sahÉ™lÉ™rini yenilÉ™yin.', 'Yadda saxladÄ±qdan sonra modem qÄ±sa yenilÉ™nmÉ™ edÉ™ bilÉ™r.']],
                ['title' => 'TP-Link modem vÉ™ routerlÉ™r', 'summary' => '`tplinkwifi.net` vÉ™ ya `192.168.0.1` / `192.168.1.1` Ã¼zÉ™rindÉ™n idarÉ™etmÉ™yÉ™ daxil olun.', 'steps' => ['Wireless Settings vÉ™ ya Basic Wireless bÃ¶lmÉ™sini aÃ§Ä±n.', 'Smart Connect aktivdirsÉ™ 2.4 vÉ™ 5 GHz eyni adda gÃ¶rÃ¼nÉ™ bilÉ™r.', 'Wi-Fi adÄ±nÄ± vÉ™ ÅŸifrÉ™sini yenilÉ™yin.', 'Uzaq otaqlarda zÉ™if siqnal varsa 2.4 GHz vÉ™ ya É™lavÉ™ router istifadÉ™ edin.']],
                ['title' => 'BDCOM modemlÉ™ri', 'summary' => 'WLAN vÉ™ ya Network bÃ¶lmÉ™sindÉ™ Wi-Fi ayarlarÄ± yerlÉ™ÅŸir.', 'steps' => ['MÃ¶vcud SSID profilini seÃ§in.', 'TÉ™hlÃ¼kÉ™sizlik nÃ¶vÃ¼nÃ¼ `WPA2-PSK` vÉ™ ya `WPA2/WPA3` olaraq saxlayÄ±n.', 'Yeni ÅŸifrÉ™ni daxil edib apply edin.', 'TV Box istifadÉ™ olunursa yalnÄ±z Wi-Fi hissÉ™sini dÉ™yiÅŸin.']],
            ],
            'faq' => [
                ['q' => 'Ä°nternet sÃ¼rÉ™tim aÅŸaÄŸÄ±dÄ±rsa nÉ™ etmÉ™liyÉ™m?', 'a' => 'ÆvvÉ™lcÉ™ modemi 10-15 saniyÉ™ sÃ¶ndÃ¼rÃ¼b yenidÉ™n baÅŸladÄ±n. Sonra sÃ¼rÉ™ti mÃ¼mkÃ¼n olduqda kabel ilÉ™ yoxlayÄ±n. Wi-Fi Ã¼zÉ™rindÉ™n sÃ¼rÉ™t zÉ™ifdirsÉ™ buna qalÄ±n divarlar, 2.4 GHz-dÉ™ sÄ±xlÄ±q, Ã§ox cihazÄ±n eyni anda iÅŸlÉ™mÉ™si vÉ™ ya modem yerlÉ™ÅŸimi sÉ™bÉ™b ola bilÉ™r. KabeldÉ™ nÉ™ticÉ™ normaldÄ±rsa problem É™sasÉ™n Wi-Fi mÃ¼hitindÉ™ olur.'],
                ['q' => '2.4 GHz ilÉ™ 5 GHz arasÄ±ndakÄ± fÉ™rq nÉ™dir?', 'a' => '2.4 GHz daha uzaÄŸa Ã§atÄ±r vÉ™ divarlardan daha rahat keÃ§ir, amma sÃ¼rÉ™t baxÄ±mÄ±ndan daha zÉ™if vÉ™ daha sÄ±x istifadÉ™ olunan diapazondur. 5 GHz isÉ™ daha yÃ¼ksÉ™k sÃ¼rÉ™t vÉ™ daha yaxÅŸÄ± gecikmÉ™ verir, amma mÉ™safÉ™ artdÄ±qca siqnal tez zÉ™iflÉ™yir. ModemÉ™ yaxÄ±n otaqlarda 5 GHz, uzaq otaqlarda isÉ™ 2.4 GHz daha uyÄŸun olur.'],
                ['q' => 'Ã–dÉ™niÅŸi necÉ™ edÉ™ bilÉ™rÉ™m vÉ™ gecikmÉ™ olanda nÉ™ baÅŸ verir?', 'a' => 'Ã–dÉ™niÅŸi onlayn Ã¶dÉ™niÅŸ sÉ™hifÉ™si, bank vÉ™ partnyor kanallar Ã¼zÉ™rindÉ™n etmÉ™k mÃ¼mkÃ¼ndÃ¼r. GecikmÉ™ olduqda xidmÉ™t mÉ™hdudlaÅŸa vÉ™ ya dayana bilÉ™r. Ã–dÉ™niÅŸ etdikdÉ™n sonra bÉ™rpa adÉ™tÉ™n qÄ±sa mÃ¼ddÉ™tdÉ™ tamamlanÄ±r. GecikmÉ™ davam edirsÉ™, qÉ™bz vÉ™ ya Ã¶dÉ™niÅŸ tarixini dÉ™stÉ™k komandasÄ± ilÉ™ paylaÅŸmaq lazÄ±mdÄ±r.'],
                ['q' => 'Wi-Fi ÅŸifrÉ™sini unutmuÅŸamsa vÉ™ ya siqnal zÉ™ifdirsÉ™ nÉ™ etmÉ™liyÉ™m?', 'a' => 'ÆvvÉ™lcÉ™ modem stikerindÉ™ki ilkin mÉ™lumatlarÄ± yoxlayÄ±n. ÆgÉ™r ÅŸifrÉ™ dÉ™yiÅŸdirilibsÉ™, modem panelindÉ™n yeni ÅŸifrÉ™ tÉ™yin etmÉ™k daha doÄŸrudur. Siqnal zÉ™ifdirsÉ™ modem mÉ™rkÉ™zi nÃ¶qtÉ™yÉ™ yerlÉ™ÅŸdirilmÉ™li, metal sÉ™thlÉ™rdÉ™n vÉ™ TV arxasÄ±ndan uzaq tutulmalÄ±dÄ±r. BÃ¶yÃ¼k evlÉ™rdÉ™ É™lavÉ™ access point vÉ™ ya mesh hÉ™lli daha uyÄŸun olur.'],
            ],
        ],
        'news' => [
            'eyebrow' => 'XÉ™bÉ™rlÉ™r vÉ™ yeniliklÉ™r',
            'title' => 'MÃ¼ÅŸtÉ™rilÉ™r Ã¼Ã§Ã¼n faydalÄ± informasiya lenti',
            'subtitle' => 'Ä°nternet, sistem tÉ™hlÃ¼kÉ™sizliyi vÉ™ kripto bazar mÃ¶vzularÄ±nÄ± ayrÄ±ca lentdÉ™ gÃ¶stÉ™rÉ™n sÉ™liqÉ™li struktur.',
            'tabs' => ['Ä°nternet vÉ™ ÅŸÉ™bÉ™kÉ™', 'Sistem vÉ™ tÉ™hlÃ¼kÉ™sizlik', 'Kripto bazar icmalÄ±'],
            'disclaimer' => 'Kripto bÃ¶lmÉ™si mÉ™lumat xarakterlidir, investisiya mÉ™slÉ™hÉ™ti deyil.',
            'items' => [
                ['category' => 'network', 'category_label' => 'Ä°nternet vÉ™ ÅŸÉ™bÉ™kÉ™', 'source' => 'Super Optik redaksiyasÄ±', 'title' => 'Fiber qoÅŸulmada stabil sÃ¼rÉ™t Ã¼Ã§Ã¼n ev daxilindÉ™ 3 vacib yoxlama', 'summary' => 'Modem yeri, kabel vÉ™ziyyÉ™ti vÉ™ Wi-Fi kanal seÃ§imi istifadÉ™Ã§i tÉ™crÃ¼bÉ™sinÉ™ birbaÅŸa tÉ™sir edir. MÃ¼ÅŸtÉ™rilÉ™r Ã¼Ã§Ã¼n sadÉ™ yoxlama siyahÄ±sÄ± ayrÄ±ca paylaÅŸÄ±lÄ±r.', 'date' => '22.04.2026'],
                ['category' => 'network', 'category_label' => 'Ä°nternet vÉ™ ÅŸÉ™bÉ™kÉ™', 'source' => 'Super Optik redaksiyasÄ±', 'title' => 'FTTH vÉ™ klassik qoÅŸulma arasÄ±nda fÉ™rq: hansÄ± istifadÉ™Ã§iyÉ™ hansÄ± model uyÄŸundur?', 'summary' => 'Fiber infrastrukturun praktik Ã¼stÃ¼nlÃ¼klÉ™ri sadÉ™ dildÉ™ izah olunur ki, mÃ¼ÅŸtÉ™rilÉ™r daha rahat seÃ§im edÉ™ bilsin.', 'date' => '20.04.2026'],
                ['category' => 'systems', 'category_label' => 'Sistem vÉ™ tÉ™hlÃ¼kÉ™sizlik', 'source' => 'Super Optik redaksiyasÄ±', 'title' => 'Ofis ÅŸÉ™bÉ™kÉ™sindÉ™ zÉ™if nÃ¶qtÉ™lÉ™ri azaltmaq Ã¼Ã§Ã¼n minimal tÉ™hlÃ¼kÉ™sizlik paketi', 'summary' => 'GÃ¼clÃ¼ parol siyasÉ™ti, ehtiyat nÃ¼sxÉ™ intizamÄ± vÉ™ giriÅŸlÉ™rin mÉ™hdudlaÅŸdÄ±rÄ±lmasÄ± kiÃ§ik bizneslÉ™r Ã¼Ã§Ã¼n ilkin qoruma qatÄ±nÄ± formalaÅŸdÄ±rÄ±r.', 'date' => '21.04.2026'],
                ['category' => 'systems', 'category_label' => 'Sistem vÉ™ tÉ™hlÃ¼kÉ™sizlik', 'source' => 'Super Optik redaksiyasÄ±', 'title' => 'Kamera, server vÉ™ ÅŸÉ™bÉ™kÉ™ cihazlarÄ± Ã¼Ã§Ã¼n lisenziya vÉ™ yenilÉ™mÉ™ planÄ± niyÉ™ vacibdir?', 'summary' => 'YalnÄ±z qurulum deyil, yenilÉ™nmÉ™ vÉ™ lisenziya nÉ™zarÉ™ti dÉ™ dayanÄ±qlÄ± sistem iÅŸinin vacib hissÉ™sidir.', 'date' => '18.04.2026'],
                ['category' => 'crypto', 'category_label' => 'Kripto bazar icmalÄ±', 'source' => 'Bazar qeydi', 'title' => 'Kripto bazarda volatillik artanda risk idarÉ™etmÉ™si niyÉ™ birinci yerdÉ™ olmalÄ±dÄ±r?', 'summary' => 'QiymÉ™t proqnozu vermÉ™k É™vÉ™zinÉ™ risk, xÉ™bÉ™r axÄ±nÄ± vÉ™ qÉ™rar intizamÄ± kimi É™sas prinsiplÉ™r xatÄ±rladÄ±lÄ±r.', 'date' => '22.04.2026'],
                ['category' => 'crypto', 'category_label' => 'Kripto bazar icmalÄ±', 'source' => 'Bazar qeydi', 'title' => 'Makro xÉ™bÉ™rlÉ™r kripto sentimentinÉ™ necÉ™ tÉ™sir edir?', 'summary' => 'Faiz dÉ™rÉ™cÉ™lÉ™ri, tÉ™nzimlÉ™mÉ™ xÉ™bÉ™rlÉ™ri vÉ™ likvidlik mÃ¼hiti qÄ±sa icmal ÅŸÉ™klindÉ™ tÉ™qdim olunur.', 'date' => '19.04.2026'],
            ],
        ],
        'contact' => [
            'eyebrow' => 'SadÉ™ mÃ¼raciÉ™t vÉ™ É™laqÉ™',
            'title' => 'QoÅŸulma, dÉ™stÉ™k vÉ™ biznes tÉ™kliflÉ™ri Ã¼Ã§Ã¼n birbaÅŸa bizimlÉ™ danÄ±ÅŸÄ±n',
            'text' => 'Yeni qoÅŸulma, texniki problem, biznes interneti vÉ™ sistem layihÉ™lÉ™ri Ã¼Ã§Ã¼n eyni kanaldan mÃ¼raciÉ™t edÉ™ bilÉ™rsiniz.',
        ],
        'footer' => 'Fiber internet, texniki dÉ™stÉ™k vÉ™ sistem hÉ™llÉ™rini bir platformada birlÉ™ÅŸdirÉ™n xidmÉ™t modeli.',
        'popup' => ['label' => 'TÃ¶vsiyÉ™ olunan paket', 'title' => 'SÃ¼rÉ™tli internet, daha rahat istifadÉ™', 'offer' => 'Ä°nternet', 'text' => 'Ä°stifadÉ™ vÉ™rdiÅŸinizÉ™ uyÄŸun tarifi seÃ§ib daha sabit baÄŸlantÄ± É™ldÉ™ edin.', 'dont_show' => 'Bir daha gÃ¶stÉ™rmÉ™'],
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
        'nav' => ['Ğ“Ğ»Ğ°Ğ²Ğ½Ğ°Ñ', 'Ğ¢Ğ°Ñ€Ğ¸Ñ„Ñ‹', 'Ğ£ÑĞ»ÑƒĞ³Ğ¸', 'ĞŸĞ¾ĞºÑ€Ñ‹Ñ‚Ğ¸Ğµ', 'ĞŸĞ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ°', 'ĞĞ¾Ğ²Ğ¾ÑÑ‚Ğ¸', 'ĞšĞ¾Ğ½Ñ‚Ğ°ĞºÑ‚Ñ‹'],
        'pay_now' => 'ĞĞ¿Ğ»Ğ°Ñ‚Ğ¸Ñ‚ÑŒ',
        'portal' => 'ĞŸĞ¾Ñ€Ñ‚Ğ°Ğ»',
        'hero_badge' => 'ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ñ‹Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ¸ ÑĞ¸ÑÑ‚ĞµĞ¼Ğ½Ñ‹Ğµ Ñ€ĞµÑˆĞµĞ½Ğ¸Ñ',
        'hero_title' => 'ĞĞ°Ğ´ĞµĞ¶Ğ½Ñ‹Ğ¹ Ğ¾Ğ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ñ‹Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ´Ğ»Ñ Ğ´Ğ¾Ğ¼Ğ° Ğ¸ Ğ±Ğ¸Ğ·Ğ½ĞµÑĞ°',
        'hero_text' => 'ĞœÑ‹ Ğ¿Ñ€ĞµĞ´Ğ»Ğ°Ğ³Ğ°ĞµĞ¼ Ğ¾Ğ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ñ‹Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ğ¢Ğ’, Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ½ÑƒÑ Ğ»Ğ¸Ğ½Ğ¸Ñ, Ğ²Ñ‹Ğ±Ğ¾Ñ€ Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ° Ğ¸ ÑĞµÑ‚ĞµĞ²Ñ‹Ğµ Ñ€ĞµÑˆĞµĞ½Ğ¸Ñ Ğ´Ğ»Ñ Ñ‡Ğ°ÑÑ‚Ğ½Ñ‹Ñ… Ğ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ñ‚ĞµĞ»ĞµĞ¹ Ğ¸ Ğ¾Ğ±ÑŠĞµĞºÑ‚Ğ¾Ğ² Ğ² Ğ¾Ğ´Ğ½Ğ¾Ğ¼ Ğ¼ĞµÑÑ‚Ğµ.',
        'hero_actions' => ['Ğ¡Ğ¼Ğ¾Ñ‚Ñ€ĞµÑ‚ÑŒ Ñ‚Ğ°Ñ€Ğ¸Ñ„Ñ‹', 'Ğ¦ĞµĞ½Ñ‚Ñ€ Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ¸'],
        'highlights' => [
            ['icon' => 'fa-solid fa-gauge-high', 'title' => '100-1000 Mb/s', 'text' => 'Ğ Ğ°Ğ·Ğ½Ñ‹Ğµ Ñ‚Ğ°Ñ€Ğ¸Ñ„Ñ‹ ÑĞºĞ¾Ñ€Ğ¾ÑÑ‚Ğ¸ Ğ´Ğ»Ñ Ğ´Ğ¾Ğ¼Ğ° Ğ¸ Ğ±Ğ¸Ğ·Ğ½ĞµÑĞ°'],
            ['icon' => 'fa-solid fa-headset', 'title' => 'ĞŸĞ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ° 24/7', 'text' => 'Ğ‘Ñ‹ÑÑ‚Ñ€Ñ‹Ğ¹ Ğ¾Ñ‚ĞºĞ»Ğ¸Ğº Ñ‚ĞµÑ…Ğ½Ğ¸Ñ‡ĞµÑĞºĞ¾Ğ¹ ĞºĞ¾Ğ¼Ğ°Ğ½Ğ´Ñ‹'],
            ['icon' => 'fa-solid fa-network-wired', 'title' => 'Ğ¡Ğ¸ÑÑ‚ĞµĞ¼Ğ½Ñ‹Ğµ Ñ€ĞµÑˆĞµĞ½Ğ¸Ñ', 'text' => 'LAN/WAN, ÑĞµÑ€Ğ²ĞµÑ€Ñ‹, IP-Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ¸Ñ Ğ¸ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚ÑŒ'],
        ],
        'panel_tabs' => ['Ğ—Ğ°ÑĞ²ĞºĞ°', 'Ğ›Ğ¸Ñ‡Ğ½Ñ‹Ğ¹ ĞºĞ°Ğ±Ğ¸Ğ½ĞµÑ‚'],
        'lead' => [
            'title' => 'ĞŸĞ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ğµ Ğ¸ ÑĞ²ÑĞ·ÑŒ',
            'subtitle' => 'Ğ—Ğ°Ğ¿Ğ¾Ğ»Ğ½Ğ¸Ñ‚Ğµ Ğ¿Ñ€Ğ¾ÑÑ‚ÑƒÑ Ñ„Ğ¾Ñ€Ğ¼Ñƒ, Ğ¸ Ğ½Ğ°ÑˆĞ° ĞºĞ¾Ğ¼Ğ°Ğ½Ğ´Ğ° ÑĞ²ÑĞ¶ĞµÑ‚ÑÑ Ñ Ğ²Ğ°Ğ¼Ğ¸ Ğ² Ğ±Ğ»Ğ¸Ğ¶Ğ°Ğ¹ÑˆĞµĞµ Ğ²Ñ€ĞµĞ¼Ñ.',
            'name' => 'Ğ˜Ğ¼Ñ Ğ¸ Ñ„Ğ°Ğ¼Ğ¸Ğ»Ğ¸Ñ',
            'phone' => 'Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½',
            'email' => 'E-mail',
            'service' => 'Ğ˜Ğ½Ñ‚ĞµÑ€ĞµÑÑƒÑÑ‰Ğ°Ñ ÑƒÑĞ»ÑƒĞ³Ğ°',
            'area' => 'Ğ¢ĞµÑ€Ñ€Ğ¸Ñ‚Ğ¾Ñ€Ğ¸Ñ',
            'note' => 'ĞšÑ€Ğ°Ñ‚ĞºĞ°Ñ Ğ·Ğ°Ğ¼ĞµÑ‚ĞºĞ°',
            'placeholders' => ['Ğ˜Ğ¼Ñ Ğ¸ Ñ„Ğ°Ğ¼Ğ¸Ğ»Ğ¸Ñ', '+994501234567', 'info@example.com', 'ĞšÑ€Ğ°Ñ‚ĞºĞ¾ Ğ¾Ğ¿Ğ¸ÑˆĞ¸Ñ‚Ğµ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ğµ'],
            'options' => ['Ğ’Ñ‹Ğ±ĞµÑ€Ğ¸Ñ‚Ğµ ÑƒÑĞ»ÑƒĞ³Ñƒ', 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚', 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚', 'Ğ¢ĞµÑ…Ğ½Ğ¸Ñ‡ĞµÑĞºĞ°Ñ Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ°', 'Ğ¡ĞµÑ‚ĞµĞ²Ğ¾Ğµ Ğ¸ ÑĞ¸ÑÑ‚ĞµĞ¼Ğ½Ğ¾Ğµ Ñ€ĞµÑˆĞµĞ½Ğ¸Ğµ'],
            'area_default' => 'Ğ’Ñ‹Ğ±ĞµÑ€Ğ¸Ñ‚Ğµ Ñ‚ĞµÑ€Ñ€Ğ¸Ñ‚Ğ¾Ñ€Ğ¸Ñ',
            'submit' => 'ĞÑ‚Ğ¿Ñ€Ğ°Ğ²Ğ¸Ñ‚ÑŒ',
            'submitting' => 'ĞÑ‚Ğ¿Ñ€Ğ°Ğ²ĞºĞ°...',
        ],
        'portal_box' => [
            'title' => 'Ğ”Ğ¾ÑÑ‚ÑƒĞ¿ Ğ² Ğ¿Ğ¾Ñ€Ñ‚Ğ°Ğ»',
            'text' => 'ĞšĞ»Ğ¸ĞµĞ½Ñ‚Ñ‹ Ğ±ÑƒĞ´ÑƒÑ‚ Ğ¸ÑĞ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ñ‚ÑŒ Ğ¿Ğ¾Ñ€Ñ‚Ğ°Ğ» Ğ´Ğ»Ñ Ğ´Ğ°Ğ½Ğ½Ñ‹Ñ… billing, Ğ° ÑĞ¾Ñ‚Ñ€ÑƒĞ´Ğ½Ğ¸ĞºĞ¸ â€” Ğ´Ğ»Ñ Ñ€Ğ¾Ğ»ĞµĞ²Ñ‹Ñ… Ğ¿ĞµÑ€ĞµÑ…Ğ¾Ğ´Ğ¾Ğ² Ğ² billing Ğ¸ OLT.',
            'points' => ['ĞšĞ»Ğ¸ĞµĞ½Ñ‚: Ñ‚Ñ€Ğ°Ñ„Ğ¸Ğº, Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ñ‹, Ğ²Ñ‹Ğ¿Ğ¸ÑĞºĞ¸ Ğ¸ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ñ', 'Ğ¡Ğ¾Ñ‚Ñ€ÑƒĞ´Ğ½Ğ¸Ğº: Ğ¿ĞµÑ€ĞµÑ…Ğ¾Ğ´ Ğ² billing Ğ¸ ÑƒĞ¿Ñ€Ğ°Ğ²Ğ»ĞµĞ½Ğ¸Ğµ OLT'],
            'action' => 'ĞÑ‚ĞºÑ€Ñ‹Ñ‚ÑŒ ÑÑ‚Ñ€Ğ°Ğ½Ğ¸Ñ†Ñƒ Ğ²Ñ…Ğ¾Ğ´Ğ° Ğ² Ğ¿Ğ¾Ñ€Ñ‚Ğ°Ğ»',
        ],
        'tariffs' => [
            'eyebrow' => 'Ğ¢Ğ°Ñ€Ğ¸Ñ„Ñ‹',
            'title' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğµ Ğ¸ Ğ±Ğ¸Ğ·Ğ½ĞµÑ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚-Ğ¿Ğ°ĞºĞµÑ‚Ñ‹',
            'subtitle' => 'Ğ’Ñ‹Ğ±Ğ¸Ñ€Ğ°Ğ¹Ñ‚Ğµ Ğ´Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğµ Ğ¸ Ğ±Ğ¸Ğ·Ğ½ĞµÑ-Ñ‚Ğ°Ñ€Ğ¸Ñ„Ñ‹ Ğ¾Ñ‚Ğ´ĞµĞ»ÑŒĞ½Ğ¾. Ğ’ Ğ´Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ñ… Ñ‚Ğ°Ñ€Ğ¸Ñ„Ğ°Ñ… Ğ¢Ğ’ +6 AZN Ğ¸ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½ +4 AZN, Ğ° Ğ² Ğ±Ğ¸Ğ·Ğ½ĞµÑ-Ñ‚Ğ°Ñ€Ğ¸Ñ„Ğ°Ñ… Ğ¢Ğ’ Ğ¸ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½ Ğ´Ğ¾Ğ±Ğ°Ğ²Ğ»ÑÑÑ‚ÑÑ Ğ¿Ğ¾ 10 AZN.',
            'audiences' => ['Ğ’ÑĞµ Ğ¿Ğ°ĞºĞµÑ‚Ñ‹', 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğµ Ñ‚Ğ°Ñ€Ğ¸Ñ„Ñ‹', 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ñ‚Ğ°Ñ€Ğ¸Ñ„Ñ‹'],
            'filters' => ['Ğ’ÑĞµ Ñ‚Ğ°Ñ€Ğ¸Ñ„Ñ‹', 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚', 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ + Ğ¢Ğ’', 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ + Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½', 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ + Ğ¢Ğ’ + Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ'],
            'segment_home' => 'Ğ”Ğ¾Ğ¼',
            'segment_business' => 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ',
            'per_month' => '/Ğ¼ĞµÑÑÑ†',
            'order' => 'Ğ—Ğ°ĞºĞ°Ğ·Ğ°Ñ‚ÑŒ',
            'modems' => [
                ['title' => 'HSGQ GPON Ğ¼Ğ¾Ğ´ĞµĞ¼', 'text' => 'Ğ¡Ñ‚Ğ°Ğ±Ğ¸Ğ»ÑŒĞ½Ğ¾Ğµ Ñ€ĞµÑˆĞµĞ½Ğ¸Ğµ Ğ´Ğ»Ñ ÑÑ‚Ğ°Ğ½Ğ´Ğ°Ñ€Ñ‚Ğ½Ñ‹Ñ… fiber-Ğ°Ğ±Ğ¾Ğ½ĞµĞ½Ñ‚Ğ¾Ğ²'],
                ['title' => 'Huawei dual-band Ğ¼Ğ¾Ğ´ĞµĞ¼', 'text' => 'Ğ£Ğ´Ğ¾Ğ±Ğ½Ñ‹Ğ¹ Ğ²Ñ‹Ğ±Ğ¾Ñ€ Ğ´Ğ»Ñ 2.4 GHz Ğ¸ 5 GHz'],
                ['title' => 'TP-Link router', 'text' => 'Ğ”Ğ¾Ğ¿Ğ¾Ğ»Ğ½Ğ¸Ñ‚ĞµĞ»ÑŒĞ½Ğ¾Ğµ Wi-Fi Ğ¿Ğ¾ĞºÑ€Ñ‹Ñ‚Ğ¸Ğµ Ğ´Ğ»Ñ Ğ±Ğ¾Ğ»ÑŒÑˆĞ¸Ñ… Ğ´Ğ¾Ğ¼Ğ¾Ğ² Ğ¸ Ğ¾Ñ„Ğ¸ÑĞ¾Ğ²'],
            ],
        ],
        'services' => [
            'eyebrow' => 'Ğ£ÑĞ»ÑƒĞ³Ğ¸ Ğ´Ğ»Ñ Ğ´Ğ¾Ğ¼Ğ° Ğ¸ Ğ±Ğ¸Ğ·Ğ½ĞµÑĞ°',
            'title' => 'Ğ ĞµÑˆĞµĞ½Ğ¸Ñ, ĞºĞ¾Ñ‚Ğ¾Ñ€Ñ‹Ğµ Ğ´Ğ°ÑÑ‚ Ğ±Ğ¾Ğ»ÑŒÑˆĞµ, Ñ‡ĞµĞ¼ Ğ¿Ñ€Ğ¾ÑÑ‚Ğ¾ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚',
            'subtitle' => 'ĞœÑ‹ Ğ¿Ñ€ĞµĞ´Ğ»Ğ°Ğ³Ğ°ĞµĞ¼ Ğ½Ğµ Ñ‚Ğ¾Ğ»ÑŒĞºĞ¾ Ğ´Ğ¾ÑÑ‚ÑƒĞ¿ Ğ² Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ğ½Ğ¾ Ğ¸ Ğ¿Ñ€Ğ°ĞºÑ‚Ğ¸Ñ‡ĞµÑĞºÑƒÑ Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºÑƒ Ğ¿Ğ¾ ÑĞµÑ‚ÑĞ¼, ÑĞ¸ÑÑ‚ĞµĞ¼Ğ°Ğ¼, IT-Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞµ, Ğ¿Ñ€Ğ¾Ğ³Ñ€Ğ°Ğ¼Ğ¼Ğ½Ğ¾Ğ¼Ñƒ Ğ¾Ğ±ĞµÑĞ¿ĞµÑ‡ĞµĞ½Ğ¸Ñ, Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ¸Ğ¸ Ğ¸ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚Ğ¸.',
            'items' => [
                ['icon' => 'fa-solid fa-house-signal', 'title' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚', 'summary' => 'ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ñ‹Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ğ¢Ğ’ Ğ¸ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ½Ñ‹Ğµ Ğ¿Ğ°ĞºĞµÑ‚Ñ‹ Ğ´Ğ»Ñ ÑĞµĞ¼ÑŒĞ¸ Ğ¸ Ğ´Ğ¾Ğ¼Ğ°.', 'details' => 'ĞŸĞ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ğµ, Ğ²Ñ‹Ğ±Ğ¾Ñ€ Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ°, Ñ€ĞµĞºĞ¾Ğ¼ĞµĞ½Ğ´Ğ°Ñ†Ğ¸Ğ¸ Ğ¿Ğ¾ Wi-Fi Ğ¸ Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ğ° Ğ¿Ñ€ĞµĞ´Ğ»Ğ°Ğ³Ğ°ÑÑ‚ÑÑ Ğ² Ğ¾Ğ´Ğ½Ğ¾Ğ¼ Ğ¿Ğ¾Ñ‚Ğ¾ĞºĞµ.'],
                ['icon' => 'fa-solid fa-building', 'title' => 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚', 'summary' => 'Ğ‘Ğ¾Ğ»ĞµĞµ ÑÑ‚Ğ°Ğ±Ğ¸Ğ»ÑŒĞ½Ğ°Ñ ÑĞ²ÑĞ·ÑŒ Ğ¸ Ğ¿Ñ€Ğ¸Ğ¾Ñ€Ğ¸Ñ‚ĞµÑ‚Ğ½Ğ°Ñ Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ° Ğ´Ğ»Ñ Ğ¾Ñ„Ğ¸ÑĞ¾Ğ² Ğ¸ Ğ¾Ğ±ÑŠĞµĞºÑ‚Ğ¾Ğ².', 'details' => 'Ğ’ Ğ±Ğ¸Ğ·Ğ½ĞµÑ-Ñ‚Ğ°Ñ€Ğ¸Ñ„Ğ°Ñ… Ğ±Ğ°Ğ·Ğ¾Ğ²Ğ°Ñ Ñ†ĞµĞ½Ğ° Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚Ğ° Ğ½Ğ° 5 AZN Ğ²Ñ‹ÑˆĞµ Ğ´Ğ¾Ğ¼Ğ°ÑˆĞ½ĞµĞ¹, Ğ° Ğ¢Ğ’ Ğ¸ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½ Ğ´Ğ¾Ğ±Ğ°Ğ²Ğ»ÑÑÑ‚ÑÑ Ğ¾Ñ‚Ğ´ĞµĞ»ÑŒĞ½Ğ¾.'],
                ['icon' => 'fa-solid fa-diagram-project', 'title' => 'ĞŸÑ€Ğ¾ĞµĞºÑ‚Ğ¸Ñ€Ğ¾Ğ²Ğ°Ğ½Ğ¸Ğµ ÑĞµÑ‚Ğ¸', 'summary' => 'Ğ¢Ğ¾Ğ¿Ğ¾Ğ»Ğ¾Ğ³Ğ¸Ñ LAN/WAN, Ğ½Ğ°ÑÑ‚Ñ€Ğ¾Ğ¹ĞºĞ° switch Ğ¸ router.', 'details' => 'ĞœĞ¾Ğ¶Ğ½Ğ¾ ÑÑ‚Ñ€Ğ¾Ğ¸Ñ‚ÑŒ Ğ²Ğ½ÑƒÑ‚Ñ€ĞµĞ½Ğ½Ğ¸Ğµ Ğ¸ ÑƒĞ´Ğ°Ğ»ĞµĞ½Ğ½Ñ‹Ğµ ÑĞµÑ‚ĞµĞ²Ñ‹Ğµ Ğ¿Ñ€Ğ¾ĞµĞºÑ‚Ñ‹ Ğ¾Ñ‚ Ğ¼Ğ°Ğ»Ğ¾Ğ³Ğ¾ Ğ¾Ñ„Ğ¸ÑĞ° Ğ´Ğ¾ Ğ¼Ğ½Ğ¾Ğ³Ğ¾ÑÑ‚Ğ°Ğ¶Ğ½Ğ¾Ğ³Ğ¾ Ğ¾Ğ±ÑŠĞµĞºÑ‚Ğ°.'],
                ['icon' => 'fa-solid fa-server', 'title' => 'Ğ¡ĞµÑ€Ğ²ĞµÑ€Ñ‹ Ğ¸ Ğ¼Ğ¾Ğ½Ğ¸Ñ‚Ğ¾Ñ€Ğ¸Ğ½Ğ³', 'summary' => 'Ğ¤Ğ°Ğ¹Ğ»Ğ¾Ğ²Ñ‹Ğµ ÑĞµÑ€Ğ²ĞµÑ€Ñ‹, backup, Ğ¼Ğ¾Ğ½Ğ¸Ñ‚Ğ¾Ñ€Ğ¸Ğ½Ğ³ Ğ¸ Ñ†ĞµĞ½Ñ‚Ñ€Ğ°Ğ»Ğ¸Ğ·Ğ¾Ğ²Ğ°Ğ½Ğ½Ğ¾Ğµ ÑƒĞ¿Ñ€Ğ°Ğ²Ğ»ĞµĞ½Ğ¸Ğµ.', 'details' => 'Ğ¡ĞµÑ€Ğ²ĞµÑ€Ğ½Ñ‹Ğµ, backup Ğ¸ Ğ¼Ğ¾Ğ½Ğ¸Ñ‚Ğ¾Ñ€Ğ¸Ğ½Ğ³Ğ¾Ğ²Ñ‹Ğµ Ñ€ĞµÑˆĞµĞ½Ğ¸Ñ Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ Ğ¿Ğ»Ğ°Ğ½Ğ¸Ñ€Ğ¾Ğ²Ğ°Ñ‚ÑŒ Ğ¾Ñ‚Ğ´ĞµĞ»ÑŒĞ½Ğ¾ Ğ´Ğ»Ñ ÑƒÑÑ‚Ğ¾Ğ¹Ñ‡Ğ¸Ğ²Ğ¾Ğ¹ Ñ€Ğ°Ğ±Ğ¾Ñ‚Ñ‹ ÑĞµÑ€Ğ²Ğ¸ÑĞ¾Ğ².'],
                ['icon' => 'fa-solid fa-phone-volume', 'title' => 'IP-Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ¸Ñ', 'summary' => 'Ğ’Ğ½ÑƒÑ‚Ñ€ĞµĞ½Ğ½ÑÑ Ğ½ÑƒĞ¼ĞµÑ€Ğ°Ñ†Ğ¸Ñ, Ğ¼Ğ°Ñ€ÑˆÑ€ÑƒÑ‚Ğ¸Ğ·Ğ°Ñ†Ğ¸Ñ Ğ·Ğ²Ğ¾Ğ½ĞºĞ¾Ğ² Ğ¸ IVR.', 'details' => 'ĞŸĞ°ĞºĞµÑ‚Ñ‹ Ñ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ½Ğ¾Ğ¹ Ğ»Ğ¸Ğ½Ğ¸ĞµĞ¹ Ğ¿Ğ¾Ğ¼Ğ¾Ğ³Ğ°ÑÑ‚ Ğ²Ñ‹ÑÑ‚Ñ€Ğ¾Ğ¸Ñ‚ÑŒ Ğ±Ğ¾Ğ»ĞµĞµ ÑƒĞ´Ğ¾Ğ±Ğ½Ñ‹Ğ¹ Ğ¿Ğ¾Ñ‚Ğ¾Ğº ÑĞ²ÑĞ·Ğ¸ Ğ´Ğ»Ñ Ğ±Ğ¸Ğ·Ğ½ĞµÑĞ°.'],
                ['icon' => 'fa-solid fa-shield-halved', 'title' => 'Ğ‘ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚ÑŒ Ğ¸ Ğ»Ğ¸Ñ†ĞµĞ½Ğ·Ğ¸Ğ¸', 'summary' => 'Ğ¡ĞµÑ‚ĞµĞ²Ğ°Ñ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚ÑŒ, Ğ»Ğ¸Ñ†ĞµĞ½Ğ·Ğ¸Ñ€Ğ¾Ğ²Ğ°Ğ½Ğ¸Ğµ Ğ¸ Ğ±Ğ°Ğ·Ğ¾Ğ²Ñ‹Ğ¹ Ğ°ÑƒĞ´Ğ¸Ñ‚.', 'details' => 'Ğ”Ğ»Ñ ĞºĞ°Ğ¼ĞµÑ€, ÑĞµÑ€Ğ²ĞµÑ€Ğ¾Ğ² Ğ¸ Ğ¾Ñ„Ğ¸ÑĞ½Ñ‹Ñ… ÑĞµÑ‚ĞµĞ¹ Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ Ğ²Ñ‹ÑÑ‚Ñ€Ğ¾Ğ¸Ñ‚ÑŒ ĞºĞ¾Ğ½Ñ‚Ñ€Ğ¾Ğ»ÑŒ Ğ´Ğ¾ÑÑ‚ÑƒĞ¿Ğ°, Ğ¿Ğ»Ğ°Ğ½ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½Ğ¸Ğ¹ Ğ¸ Ğ¿Ñ€Ğ°Ğ²Ğ¸Ğ»Ğ° Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚Ğ¸.'],
            ],
        ],
        'coverage' => [
            'eyebrow' => 'ĞŸĞ¾ĞºÑ€Ñ‹Ñ‚Ğ¸Ğµ',
            'title' => 'Ğ¢ĞµÑ€Ñ€Ğ¸Ñ‚Ğ¾Ñ€Ğ¸Ğ¸, Ğ³Ğ´Ğµ Ğ¼Ñ‹ Ğ¾ĞºĞ°Ğ·Ñ‹Ğ²Ğ°ĞµĞ¼ ÑƒÑĞ»ÑƒĞ³Ğ¸ Ğ² Ğ‘Ğ°ĞºÑƒ Ğ¸ Ñ€ĞµĞ³Ğ¸Ğ¾Ğ½Ğ°Ñ…',
            'subtitle' => 'Ğ—Ğ¾Ğ½Ñƒ Ğ¾Ğ±ÑĞ»ÑƒĞ¶Ğ¸Ğ²Ğ°Ğ½Ğ¸Ñ Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ Ğ¿Ğ¾ÑĞ¼Ğ¾Ñ‚Ñ€ĞµÑ‚ÑŒ Ğ¸ Ğ² Ğ²Ğ¸Ğ´Ğµ ÑĞ¿Ğ¸ÑĞºĞ°, Ğ¸ Ğ½Ğ° ĞºĞ°Ñ€Ñ‚Ğµ.',
            'note_title' => 'Ğ•ÑĞ»Ğ¸ Ğ²Ğ°ÑˆĞ° Ñ‚ĞµÑ€Ñ€Ğ¸Ñ‚Ğ¾Ñ€Ğ¸Ñ Ğ½Ğ¾Ğ²Ğ°Ñ',
            'note_text' => 'ĞÑÑ‚Ğ°Ğ²ÑŒÑ‚Ğµ ĞºĞ¾Ñ€Ğ¾Ñ‚ĞºÑƒÑ Ğ·Ğ°ÑĞ²ĞºÑƒ, Ğ¸ ĞºĞ¾Ğ¼Ğ°Ğ½Ğ´Ğ° ÑĞ¾Ğ¾Ğ±Ñ‰Ğ¸Ñ‚ Ğ²Ğ°Ğ¼ Ğ¾ Ñ‚ĞµÑ…Ğ½Ğ¸Ñ‡ĞµÑĞºĞ¾Ğ¹ Ğ²Ğ¾Ğ·Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ÑÑ‚Ğ¸ Ğ¸ ÑÑ€Ğ¾ĞºĞµ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ.',
            'areas' => ['ĞÑÑ‚Ğ°Ñ€Ğ°', 'ĞœĞ°ÑĞ°Ğ»Ğ»Ñ‹', 'Ğ›ĞµĞ½ĞºĞ¾Ñ€Ğ°Ğ½ÑŒ', 'ĞĞµÑ„Ñ‚Ñ‡Ğ°Ğ»Ğ°', 'Ğ˜ÑĞ¼Ğ°Ğ¸Ğ»Ğ»Ñ‹', 'ĞĞ¸Ğ·Ğ°Ğ¼Ğ¸', 'Ğ¡Ğ°Ğ±ÑƒĞ½Ñ‡Ğ¸', 'Ğ¡ÑƒÑ€Ğ°Ñ…Ğ°Ğ½Ñ‹', 'ĞĞ±ÑˆĞµÑ€Ğ¾Ğ½', 'Ğ¥Ğ°Ñ‚Ğ°Ğ¸', 'Ğ‘Ğ¸Ğ½Ğ°Ğ³Ğ°Ğ´Ğ¸', 'ĞšÑÑ€Ğ´Ğ°Ñ…Ğ°Ğ½Ñ‹', 'ĞŸĞ¸Ñ€ÑˆĞ°Ğ³Ğ¸', 'ĞœĞµÑ…Ğ´Ğ¸Ğ°Ğ±Ğ°Ğ´', 'ĞœĞ°Ğ¼ĞµĞ´Ğ»Ğ¸', 'Ğ—Ğ°Ğ±Ñ€Ğ°Ñ‚', 'Ğ“Ğ°Ñ€Ğ°Ñ‡ÑƒÑ…ÑƒÑ€', 'Ğ‘Ğ°ĞºĞ¸Ñ…Ğ°Ğ½Ğ¾Ğ²', 'ĞÑ…Ğ¼ĞµĞ´Ğ»Ñ‹', 'Ğ‘Ğ¸Ğ»ÑĞ´Ğ¶Ğ°Ñ€Ğ¸', 'Ğ“ÑĞ½ĞµÑˆĞ»Ğ¸'],
            'points' => ['ĞÑÑ‚Ğ°Ñ€Ğ°', 'ĞœĞ°ÑĞ°Ğ»Ğ»Ñ‹', 'Ğ›ĞµĞ½ĞºĞ¾Ñ€Ğ°Ğ½ÑŒ', 'ĞĞµÑ„Ñ‚Ñ‡Ğ°Ğ»Ğ°', 'Ğ˜ÑĞ¼Ğ°Ğ¸Ğ»Ğ»Ñ‹', 'ĞĞ±ÑˆĞµÑ€Ğ¾Ğ½', 'Ğ‘Ğ°ĞºÑƒ', 'Ğ¡ÑƒÑ€Ğ°Ñ…Ğ°Ğ½Ñ‹', 'Ğ¡Ğ°Ğ±ÑƒĞ½Ñ‡Ğ¸', 'Ğ¥Ğ°Ñ‚Ğ°Ğ¸', 'Ğ‘Ğ¸Ğ½Ğ°Ğ³Ğ°Ğ´Ğ¸', 'Ğ“ÑĞ½ĞµÑˆĞ»Ğ¸'],
        ],
        'support' => [
            'eyebrow' => 'Ğ¦ĞµĞ½Ñ‚Ñ€ Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ¸',
            'title' => 'ĞŸĞ¾Ğ´Ñ€Ğ¾Ğ±Ğ½Ñ‹Ğµ Ğ¾Ğ±ÑŠÑÑĞ½ĞµĞ½Ğ¸Ñ Ğ¸ Ğ±Ğ»Ğ¾ĞºĞ¸ ÑĞ°Ğ¼Ğ¾Ğ¾Ğ±ÑĞ»ÑƒĞ¶Ğ¸Ğ²Ğ°Ğ½Ğ¸Ñ',
            'subtitle' => 'Ğ˜Ğ½ÑÑ‚Ñ€ÑƒĞºÑ†Ğ¸Ğ¸ Ğ¿Ğ¾ ÑĞ¼ĞµĞ½Ğµ Ğ¿Ğ°Ñ€Ğ¾Ğ»Ñ Ğ´Ğ»Ñ Ñ€Ğ°Ğ·Ğ½Ñ‹Ñ… Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ¾Ğ², Ñ€Ğ°ÑÑˆĞ¸Ñ€ĞµĞ½Ğ½Ñ‹Ğ¹ FAQ Ğ¸ Ğ¿Ñ€ÑĞ¼Ñ‹Ğµ ĞºĞ°Ğ½Ğ°Ğ»Ñ‹ ÑĞ²ÑĞ·Ğ¸ ÑĞ¾Ğ±Ñ€Ğ°Ğ½Ñ‹ Ğ·Ğ´ĞµÑÑŒ.',
            'wifi_title' => 'Ğ¡Ğ¼ĞµĞ½Ğ¸Ñ‚ÑŒ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ Wi-Fi',
            'wifi_text' => 'ĞŸĞ°Ğ½ĞµĞ»ÑŒ ÑƒĞ¿Ñ€Ğ°Ğ²Ğ»ĞµĞ½Ğ¸Ñ Ğ·Ğ°Ğ²Ğ¸ÑĞ¸Ñ‚ Ğ¾Ñ‚ Ğ¼Ğ°Ñ€ĞºĞ¸ Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ°, Ğ½Ğ¾ Ğ¾ÑĞ½Ğ¾Ğ²Ğ½Ñ‹Ğµ ÑˆĞ°Ğ³Ğ¸ Ğ¾Ğ±Ñ‹Ñ‡Ğ½Ğ¾ Ğ¾Ğ´Ğ¸Ğ½Ğ°ĞºĞ¾Ğ²Ñ‹.',
            'faq_title' => 'Ğ§Ğ°ÑÑ‚Ğ¾ Ğ·Ğ°Ğ´Ğ°Ğ²Ğ°ĞµĞ¼Ñ‹Ğµ Ğ²Ğ¾Ğ¿Ñ€Ğ¾ÑÑ‹',
            'contact_title' => 'ĞŸÑ€ÑĞ¼Ğ°Ñ ÑĞ²ÑĞ·ÑŒ',
            'guides' => [
                ['title' => 'ĞœĞ¾Ğ´ĞµĞ¼Ñ‹ HSGQ', 'summary' => 'ĞÑ‚ĞºÑ€Ğ¾Ğ¹Ñ‚Ğµ Ğ² Ğ±Ñ€Ğ°ÑƒĞ·ĞµÑ€Ğµ `192.168.1.1` Ğ¸Ğ»Ğ¸ Ğ°Ğ´Ñ€ĞµÑ, ÑƒĞºĞ°Ğ·Ğ°Ğ½Ğ½Ñ‹Ğ¹ Ğ½Ğ° ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²Ğµ.', 'steps' => ['ĞÑ‚ĞºÑ€Ğ¾Ğ¹Ñ‚Ğµ Ñ€Ğ°Ğ·Ğ´ĞµĞ» Wireless Ğ¸Ğ»Ğ¸ Wi-Fi.', 'ĞŸÑ€Ğ¾Ğ²ĞµÑ€ÑŒÑ‚Ğµ Ğ¾Ñ‚Ğ´ĞµĞ»ÑŒĞ½Ğ¾ Ğ¸Ğ¼ĞµĞ½Ğ° SSID Ğ´Ğ»Ñ 2.4 GHz Ğ¸ 5 GHz.', 'Ğ—Ğ°Ğ´Ğ°Ğ¹Ñ‚Ğµ Ğ½Ğ¾Ğ²Ñ‹Ğ¹ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ Ğ² Ñ„Ğ¾Ñ€Ğ¼Ğ°Ñ‚Ğµ `WPA2/WPA3` Ğ¸ ÑĞ¾Ñ…Ñ€Ğ°Ğ½Ğ¸Ñ‚Ğµ ĞµĞ³Ğ¾.', 'ĞŸĞ¾Ğ´ĞºĞ»ÑÑ‡Ğ¸Ñ‚Ğµ ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²Ğ° Ğ·Ğ°Ğ½Ğ¾Ğ²Ğ¾ Ñ Ğ½Ğ¾Ğ²Ñ‹Ğ¼ Ğ¿Ğ°Ñ€Ğ¾Ğ»ĞµĞ¼.']],
                ['title' => 'ĞœĞ¾Ğ´ĞµĞ¼Ñ‹ Huawei', 'summary' => 'Ğ¡Ğ°Ğ¼Ñ‹Ğ¹ Ñ‡Ğ°ÑÑ‚Ñ‹Ğ¹ Ğ°Ğ´Ñ€ĞµÑ Ğ²Ñ…Ğ¾Ğ´Ğ° â€” `192.168.100.1` Ğ¸Ğ»Ğ¸ `192.168.1.1`.', 'steps' => ['ĞŸĞµÑ€ĞµĞ¹Ğ´Ğ¸Ñ‚Ğµ Ğ² Ñ€Ğ°Ğ·Ğ´ĞµĞ» WLAN.', 'ĞŸÑ€Ğ¾Ğ²ĞµÑ€ÑŒÑ‚Ğµ Ğ¾Ñ‚Ğ´ĞµĞ»ÑŒĞ½Ğ¾ ÑĞµÑ‚Ğ¸ 2.4G Ğ¸ 5G.', 'ĞĞ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚Ğµ Ğ¿Ğ¾Ğ»Ñ SSID Name Ğ¸ WPA PreSharedKey.', 'ĞŸĞ¾ÑĞ»Ğµ ÑĞ¾Ñ…Ñ€Ğ°Ğ½ĞµĞ½Ğ¸Ñ Ğ¼Ğ¾Ğ´ĞµĞ¼ Ğ¼Ğ¾Ğ¶ĞµÑ‚ ĞºÑ€Ğ°Ñ‚ĞºĞ¾ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚ÑŒÑÑ.']],
                ['title' => 'ĞœĞ¾Ğ´ĞµĞ¼Ñ‹ Ğ¸ Ñ€Ğ¾ÑƒÑ‚ĞµÑ€Ñ‹ TP-Link', 'summary' => 'Ğ’Ğ¾Ğ¹Ğ´Ğ¸Ñ‚Ğµ Ñ‡ĞµÑ€ĞµĞ· `tplinkwifi.net` Ğ¸Ğ»Ğ¸ `192.168.0.1` / `192.168.1.1`.', 'steps' => ['ĞÑ‚ĞºÑ€Ğ¾Ğ¹Ñ‚Ğµ Ñ€Ğ°Ğ·Ğ´ĞµĞ» Wireless Settings Ğ¸Ğ»Ğ¸ Basic Wireless.', 'Ğ•ÑĞ»Ğ¸ Ğ²ĞºĞ»ÑÑ‡ĞµĞ½ Smart Connect, 2.4 Ğ¸ 5 GHz Ğ¼Ğ¾Ğ³ÑƒÑ‚ Ğ¸Ğ¼ĞµÑ‚ÑŒ Ğ¾Ğ´Ğ½Ğ¾ Ğ¸Ğ¼Ñ.', 'ĞĞ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚Ğµ Ğ¸Ğ¼Ñ Wi-Fi Ğ¸ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ.', 'Ğ•ÑĞ»Ğ¸ Ğ² Ğ´Ğ°Ğ»ÑŒĞ½Ğ¸Ñ… ĞºĞ¾Ğ¼Ğ½Ğ°Ñ‚Ğ°Ñ… ÑĞ»Ğ°Ğ±Ñ‹Ğ¹ ÑĞ¸Ğ³Ğ½Ğ°Ğ», Ğ¸ÑĞ¿Ğ¾Ğ»ÑŒĞ·ÑƒĞ¹Ñ‚Ğµ 2.4 GHz Ğ¸Ğ»Ğ¸ Ğ´Ğ¾Ğ¿Ğ¾Ğ»Ğ½Ğ¸Ñ‚ĞµĞ»ÑŒĞ½Ñ‹Ğ¹ Ñ€Ğ¾ÑƒÑ‚ĞµÑ€.']],
                ['title' => 'ĞœĞ¾Ğ´ĞµĞ¼Ñ‹ BDCOM', 'summary' => 'ĞĞ°ÑÑ‚Ñ€Ğ¾Ğ¹ĞºĞ¸ Wi-Fi Ğ¾Ğ±Ñ‹Ñ‡Ğ½Ğ¾ Ğ½Ğ°Ñ…Ğ¾Ğ´ÑÑ‚ÑÑ Ğ² Ñ€Ğ°Ğ·Ğ´ĞµĞ»Ğµ WLAN Ğ¸Ğ»Ğ¸ Network.', 'steps' => ['Ğ’Ñ‹Ğ±ĞµÑ€Ğ¸Ñ‚Ğµ Ñ‚ĞµĞºÑƒÑ‰Ğ¸Ğ¹ Ğ¿Ñ€Ğ¾Ñ„Ğ¸Ğ»ÑŒ SSID.', 'ĞÑÑ‚Ğ°Ğ²ÑŒÑ‚Ğµ Ñ‚Ğ¸Ğ¿ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚Ğ¸ `WPA2-PSK` Ğ¸Ğ»Ğ¸ `WPA2/WPA3`.', 'Ğ’Ğ²ĞµĞ´Ğ¸Ñ‚Ğµ Ğ½Ğ¾Ğ²Ñ‹Ğ¹ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ Ğ¸ Ğ¿Ñ€Ğ¸Ğ¼ĞµĞ½Ğ¸Ñ‚Ğµ Ğ½Ğ°ÑÑ‚Ñ€Ğ¾Ğ¹ĞºĞ¸.', 'Ğ•ÑĞ»Ğ¸ Ğ¸ÑĞ¿Ğ¾Ğ»ÑŒĞ·ÑƒĞµÑ‚ÑÑ TV Box, Ğ¼ĞµĞ½ÑĞ¹Ñ‚Ğµ Ñ‚Ğ¾Ğ»ÑŒĞºĞ¾ Wi-Fi Ñ‡Ğ°ÑÑ‚ÑŒ Ğ¸ Ğ½Ğµ Ñ‚Ñ€Ğ¾Ğ³Ğ°Ğ¹Ñ‚Ğµ VLAN.']],
            ],
            'faq' => [
                ['q' => 'Ğ§Ñ‚Ğ¾ Ğ´ĞµĞ»Ğ°Ñ‚ÑŒ, ĞµÑĞ»Ğ¸ ÑĞºĞ¾Ñ€Ğ¾ÑÑ‚ÑŒ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚Ğ° ÑĞ½Ğ¸Ğ·Ğ¸Ğ»Ğ°ÑÑŒ?', 'a' => 'Ğ¡Ğ½Ğ°Ñ‡Ğ°Ğ»Ğ° Ğ¿ĞµÑ€ĞµĞ·Ğ°Ğ¿ÑƒÑÑ‚Ğ¸Ñ‚Ğµ Ğ¼Ğ¾Ğ´ĞµĞ¼ Ğ½Ğ° 10-15 ÑĞµĞºÑƒĞ½Ğ´, Ğ° Ğ·Ğ°Ñ‚ĞµĞ¼ Ğ¿Ğ¾ Ğ²Ğ¾Ğ·Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ÑÑ‚Ğ¸ Ğ¿Ñ€Ğ¾Ğ²ĞµÑ€ÑŒÑ‚Ğµ ÑĞºĞ¾Ñ€Ğ¾ÑÑ‚ÑŒ Ğ¿Ğ¾ ĞºĞ°Ğ±ĞµĞ»Ñ. Ğ•ÑĞ»Ğ¸ Ğ¿Ğ¾ Wi-Fi ÑĞºĞ¾Ñ€Ğ¾ÑÑ‚ÑŒ ÑĞ»Ğ°Ğ±Ğ°Ñ, Ğ¿Ñ€Ğ¸Ñ‡Ğ¸Ğ½Ğ¾Ğ¹ Ğ¼Ğ¾Ğ³ÑƒÑ‚ Ğ±Ñ‹Ñ‚ÑŒ Ñ‚Ğ¾Ğ»ÑÑ‚Ñ‹Ğµ ÑÑ‚ĞµĞ½Ñ‹, Ğ·Ğ°Ğ³Ñ€ÑƒĞ¶ĞµĞ½Ğ½Ñ‹Ğ¹ Ğ´Ğ¸Ğ°Ğ¿Ğ°Ğ·Ğ¾Ğ½ 2.4 GHz, Ğ±Ğ¾Ğ»ÑŒÑˆĞ¾Ğµ ĞºĞ¾Ğ»Ğ¸Ñ‡ĞµÑÑ‚Ğ²Ğ¾ ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ² Ğ¸Ğ»Ğ¸ Ğ½ĞµÑƒĞ´Ğ°Ñ‡Ğ½Ğ¾Ğµ Ñ€Ğ°ÑĞ¿Ğ¾Ğ»Ğ¾Ğ¶ĞµĞ½Ğ¸Ğµ Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ°. Ğ•ÑĞ»Ğ¸ Ğ¿Ğ¾ ĞºĞ°Ğ±ĞµĞ»Ñ Ğ²ÑĞµ Ğ½Ğ¾Ñ€Ğ¼Ğ°Ğ»ÑŒĞ½Ğ¾, Ğ¿Ñ€Ğ¾Ğ±Ğ»ĞµĞ¼Ğ° Ğ¾Ğ±Ñ‹Ñ‡Ğ½Ğ¾ Ğ½Ğ°Ñ…Ğ¾Ğ´Ğ¸Ñ‚ÑÑ Ğ² Wi-Fi ÑÑ€ĞµĞ´Ğµ.'],
                ['q' => 'Ğ’ Ñ‡ĞµĞ¼ Ñ€Ğ°Ğ·Ğ½Ğ¸Ñ†Ğ° Ğ¼ĞµĞ¶Ğ´Ñƒ 2.4 GHz Ğ¸ 5 GHz?', 'a' => '2.4 GHz Ğ´Ğ¾Ğ±Ğ¸Ğ²Ğ°ĞµÑ‚ Ğ´Ğ°Ğ»ÑŒÑˆĞµ Ğ¸ Ğ»ĞµĞ³Ñ‡Ğµ Ğ¿Ñ€Ğ¾Ñ…Ğ¾Ğ´Ğ¸Ñ‚ Ñ‡ĞµÑ€ĞµĞ· ÑÑ‚ĞµĞ½Ñ‹, Ğ½Ğ¾ Ğ¾Ğ½ Ğ¼ĞµĞ´Ğ»ĞµĞ½Ğ½ĞµĞµ Ğ¸ Ğ¾Ğ±Ñ‹Ñ‡Ğ½Ğ¾ Ğ±Ğ¾Ğ»ĞµĞµ Ğ·Ğ°Ğ³Ñ€ÑƒĞ¶ĞµĞ½. 5 GHz Ğ´Ğ°ĞµÑ‚ Ğ±Ğ¾Ğ»ĞµĞµ Ğ²Ñ‹ÑĞ¾ĞºÑƒÑ ÑĞºĞ¾Ñ€Ğ¾ÑÑ‚ÑŒ Ğ¸ Ğ»ÑƒÑ‡ÑˆÑƒÑ Ğ·Ğ°Ğ´ĞµÑ€Ğ¶ĞºÑƒ, Ğ½Ğ¾ ÑĞ¸Ğ³Ğ½Ğ°Ğ» Ğ±Ñ‹ÑÑ‚Ñ€ĞµĞµ ÑĞ»Ğ°Ğ±ĞµĞµÑ‚ Ğ½Ğ° Ñ€Ğ°ÑÑÑ‚Ğ¾ÑĞ½Ğ¸Ğ¸. Ğ’ Ğ±Ğ»Ğ¸Ğ¶Ğ½Ğ¸Ñ… ĞºĞ¾Ğ¼Ğ½Ğ°Ñ‚Ğ°Ñ… Ğ»ÑƒÑ‡ÑˆĞµ 5 GHz, Ğ² Ğ´Ğ°Ğ»ÑŒĞ½Ğ¸Ñ… â€” 2.4 GHz.'],
                ['q' => 'ĞšĞ°Ğº Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ğ¸Ñ‚ÑŒ Ğ¸ Ñ‡Ñ‚Ğ¾ Ğ¿Ñ€Ğ¾Ğ¸ÑÑ…Ğ¾Ğ´Ğ¸Ñ‚ Ğ¿Ñ€Ğ¸ Ğ·Ğ°Ğ´ĞµÑ€Ğ¶ĞºĞµ Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ñ‹?', 'a' => 'ĞĞ¿Ğ»Ğ°Ñ‚Ñƒ Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ Ğ²Ğ½ĞµÑÑ‚Ğ¸ Ñ‡ĞµÑ€ĞµĞ· Ğ¾Ğ½Ğ»Ğ°Ğ¹Ğ½-ÑÑ‚Ñ€Ğ°Ğ½Ğ¸Ñ†Ñƒ, Ğ±Ğ°Ğ½ĞºĞ¸ Ğ¸ Ğ¿Ğ°Ñ€Ñ‚Ğ½ĞµÑ€ÑĞºĞ¸Ğµ ĞºĞ°Ğ½Ğ°Ğ»Ñ‹. ĞŸÑ€Ğ¸ Ğ·Ğ°Ğ´ĞµÑ€Ğ¶ĞºĞµ Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ğ° ÑƒÑĞ»ÑƒĞ³Ğ¸ Ğ¼Ğ¾Ğ¶ĞµÑ‚ Ğ±Ñ‹Ñ‚ÑŒ Ğ¾Ğ³Ñ€Ğ°Ğ½Ğ¸Ñ‡ĞµĞ½Ğ° Ğ¸Ğ»Ğ¸ Ğ¿Ñ€Ğ¸Ğ¾ÑÑ‚Ğ°Ğ½Ğ¾Ğ²Ğ»ĞµĞ½Ğ°. ĞŸĞ¾ÑĞ»Ğµ Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ñ‹ Ğ²Ğ¾ÑÑÑ‚Ğ°Ğ½Ğ¾Ğ²Ğ»ĞµĞ½Ğ¸Ğµ Ğ¾Ğ±Ñ‹Ñ‡Ğ½Ğ¾ Ğ·Ğ°Ğ²ĞµÑ€ÑˆĞ°ĞµÑ‚ÑÑ Ğ±Ñ‹ÑÑ‚Ñ€Ğ¾. Ğ•ÑĞ»Ğ¸ Ğ·Ğ°Ğ´ĞµÑ€Ğ¶ĞºĞ° Ğ¿Ñ€Ğ¾Ğ´Ğ¾Ğ»Ğ¶Ğ°ĞµÑ‚ÑÑ, ÑÑ‚Ğ¾Ğ¸Ñ‚ Ğ¿ĞµÑ€ĞµĞ´Ğ°Ñ‚ÑŒ ĞºĞ¾Ğ¼Ğ°Ğ½Ğ´Ğµ Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ¸ Ñ‡ĞµĞº Ğ¸Ğ»Ğ¸ Ğ´Ğ°Ñ‚Ñƒ Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ñ‹.'],
                ['q' => 'Ğ§Ñ‚Ğ¾ Ğ´ĞµĞ»Ğ°Ñ‚ÑŒ, ĞµÑĞ»Ğ¸ Ñ Ğ·Ğ°Ğ±Ñ‹Ğ» Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ Wi-Fi Ğ¸Ğ»Ğ¸ ÑĞ¸Ğ³Ğ½Ğ°Ğ» ÑĞ»Ğ°Ğ±Ñ‹Ğ¹?', 'a' => 'Ğ¡Ğ½Ğ°Ñ‡Ğ°Ğ»Ğ° Ğ¿Ñ€Ğ¾Ğ²ĞµÑ€ÑŒÑ‚Ğµ Ğ¸ÑÑ…Ğ¾Ğ´Ğ½Ñ‹Ğµ Ğ´Ğ°Ğ½Ğ½Ñ‹Ğµ Ğ½Ğ° Ğ½Ğ°ĞºĞ»ĞµĞ¹ĞºĞµ Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ°. Ğ•ÑĞ»Ğ¸ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ Ğ±Ñ‹Ğ» Ğ¸Ğ·Ğ¼ĞµĞ½ĞµĞ½, Ğ¿Ñ€Ğ°Ğ²Ğ¸Ğ»ÑŒĞ½ĞµĞµ Ğ·Ğ°Ğ´Ğ°Ñ‚ÑŒ Ğ½Ğ¾Ğ²Ñ‹Ğ¹ Ñ‡ĞµÑ€ĞµĞ· Ğ¿Ğ°Ğ½ĞµĞ»ÑŒ Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ°. ĞŸÑ€Ğ¸ ÑĞ»Ğ°Ğ±Ğ¾Ğ¼ ÑĞ¸Ğ³Ğ½Ğ°Ğ»Ğµ Ğ¼Ğ¾Ğ´ĞµĞ¼ Ğ»ÑƒÑ‡ÑˆĞµ Ğ¿Ğ¾ÑÑ‚Ğ°Ğ²Ğ¸Ñ‚ÑŒ Ğ² Ñ†ĞµĞ½Ñ‚Ñ€Ğ°Ğ»ÑŒĞ½Ğ¾Ğ¹ Ñ‚Ğ¾Ñ‡ĞºĞµ, Ğ¿Ğ¾Ğ´Ğ°Ğ»ÑŒÑˆĞµ Ğ¾Ñ‚ Ğ¼ĞµÑ‚Ğ°Ğ»Ğ»Ğ¸Ñ‡ĞµÑĞºĞ¸Ñ… Ğ¿Ğ¾Ğ²ĞµÑ€Ñ…Ğ½Ğ¾ÑÑ‚ĞµĞ¹ Ğ¸ Ñ‚ĞµĞ»ĞµĞ²Ğ¸Ğ·Ğ¾Ñ€Ğ°. Ğ’ Ğ±Ğ¾Ğ»ÑŒÑˆĞ¸Ñ… Ğ´Ğ¾Ğ¼Ğ°Ñ… Ğ»ÑƒÑ‡ÑˆĞµ Ğ¸ÑĞ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ñ‚ÑŒ access point Ğ¸Ğ»Ğ¸ mesh.'],
            ],
        ],
        'news' => [
            'eyebrow' => 'ĞĞ¾Ğ²Ğ¾ÑÑ‚Ğ¸ Ğ¸ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½Ğ¸Ñ',
            'title' => 'ĞŸĞ¾Ğ»ĞµĞ·Ğ½Ğ°Ñ Ğ¸Ğ½Ñ„Ğ¾Ñ€Ğ¼Ğ°Ñ†Ğ¸Ğ¾Ğ½Ğ½Ğ°Ñ Ğ»ĞµĞ½Ñ‚Ğ° Ğ´Ğ»Ñ ĞºĞ»Ğ¸ĞµĞ½Ñ‚Ğ¾Ğ²',
            'subtitle' => 'ĞĞºĞºÑƒÑ€Ğ°Ñ‚Ğ½Ğ°Ñ ÑÑ‚Ñ€ÑƒĞºÑ‚ÑƒÑ€Ğ°, Ğ³Ğ´Ğµ Ğ¾Ñ‚Ğ´ĞµĞ»ÑŒĞ½Ğ¾ Ğ¿Ğ¾ĞºĞ°Ğ·Ñ‹Ğ²Ğ°ÑÑ‚ÑÑ Ñ‚ĞµĞ¼Ñ‹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚Ğ°, ÑĞ¸ÑÑ‚ĞµĞ¼Ğ½Ğ¾Ğ¹ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚Ğ¸ Ğ¸ ĞºÑ€Ğ¸Ğ¿Ñ‚Ğ¾Ñ€Ñ‹Ğ½ĞºĞ°.',
            'tabs' => ['Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ¸ ÑĞµÑ‚ÑŒ', 'Ğ¡Ğ¸ÑÑ‚ĞµĞ¼Ñ‹ Ğ¸ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚ÑŒ', 'ĞĞ±Ğ·Ğ¾Ñ€ ĞºÑ€Ğ¸Ğ¿Ñ‚Ğ¾Ñ€Ñ‹Ğ½ĞºĞ°'],
            'disclaimer' => 'ĞšÑ€Ğ¸Ğ¿Ñ‚Ğ¾-Ñ€Ğ°Ğ·Ğ´ĞµĞ» Ğ½Ğ¾ÑĞ¸Ñ‚ Ğ¸Ğ½Ñ„Ğ¾Ñ€Ğ¼Ğ°Ñ†Ğ¸Ğ¾Ğ½Ğ½Ñ‹Ğ¹ Ñ…Ğ°Ñ€Ğ°ĞºÑ‚ĞµÑ€ Ğ¸ Ğ½Ğµ ÑĞ²Ğ»ÑĞµÑ‚ÑÑ Ğ¸Ğ½Ğ²ĞµÑÑ‚Ğ¸Ñ†Ğ¸Ğ¾Ğ½Ğ½Ğ¾Ğ¹ Ñ€ĞµĞºĞ¾Ğ¼ĞµĞ½Ğ´Ğ°Ñ†Ğ¸ĞµĞ¹.',
            'items' => [
                ['category' => 'network', 'category_label' => 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ¸ ÑĞµÑ‚ÑŒ', 'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik', 'title' => '3 Ğ²Ğ°Ğ¶Ğ½Ñ‹Ñ… Ğ¿Ñ€Ğ¾Ğ²ĞµÑ€ĞºĞ¸ Ğ´Ğ¾Ğ¼Ğ° Ğ´Ğ»Ñ ÑÑ‚Ğ°Ğ±Ğ¸Ğ»ÑŒĞ½Ğ¾Ğ¹ ÑĞºĞ¾Ñ€Ğ¾ÑÑ‚Ğ¸ Ğ½Ğ° fiber-Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ğ¸', 'summary' => 'Ğ Ğ°ÑĞ¿Ğ¾Ğ»Ğ¾Ğ¶ĞµĞ½Ğ¸Ğµ Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ°, ÑĞ¾ÑÑ‚Ğ¾ÑĞ½Ğ¸Ğµ ĞºĞ°Ğ±ĞµĞ»Ñ Ğ¸ Ğ²Ñ‹Ğ±Ğ¾Ñ€ ĞºĞ°Ğ½Ğ°Ğ»Ğ° Wi-Fi Ğ½Ğ°Ğ¿Ñ€ÑĞ¼ÑƒÑ Ğ²Ğ»Ğ¸ÑÑÑ‚ Ğ½Ğ° Ğ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ñ‚ĞµĞ»ÑŒÑĞºĞ¸Ğ¹ Ğ¾Ğ¿Ñ‹Ñ‚. Ğ”Ğ»Ñ ĞºĞ»Ğ¸ĞµĞ½Ñ‚Ğ¾Ğ² Ğ¿Ğ¾Ğ´Ğ³Ğ¾Ñ‚Ğ¾Ğ²Ğ»ĞµĞ½ Ğ¿Ñ€Ğ¾ÑÑ‚Ğ¾Ğ¹ Ñ‡ĞµĞº-Ğ»Ğ¸ÑÑ‚.', 'date' => '22.04.2026'],
                ['category' => 'network', 'category_label' => 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ¸ ÑĞµÑ‚ÑŒ', 'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik', 'title' => 'FTTH Ğ¸ ĞºĞ»Ğ°ÑÑĞ¸Ñ‡ĞµÑĞºĞ¾Ğµ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ğµ: ĞºĞ°ĞºĞ¾Ğ¹ Ğ²Ğ°Ñ€Ğ¸Ğ°Ğ½Ñ‚ Ğ¿Ğ¾Ğ´Ñ…Ğ¾Ğ´Ğ¸Ñ‚ ĞºĞ°ĞºĞ¾Ğ¼Ñƒ Ğ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ñ‚ĞµĞ»Ñ?', 'summary' => 'ĞŸÑ€Ğ°ĞºÑ‚Ğ¸Ñ‡ĞµÑĞºĞ¸Ğµ Ğ¿Ñ€ĞµĞ¸Ğ¼ÑƒÑ‰ĞµÑÑ‚Ğ²Ğ° fiber-Ğ¸Ğ½Ñ„Ñ€Ğ°ÑÑ‚Ñ€ÑƒĞºÑ‚ÑƒÑ€Ñ‹ Ğ¾Ğ±ÑŠÑÑĞ½ÑÑÑ‚ÑÑ Ğ¿Ñ€Ğ¾ÑÑ‚Ñ‹Ğ¼ ÑĞ·Ñ‹ĞºĞ¾Ğ¼, Ñ‡Ñ‚Ğ¾Ğ±Ñ‹ ĞºĞ»Ğ¸ĞµĞ½Ñ‚Ğ°Ğ¼ Ğ±Ñ‹Ğ»Ğ¾ Ğ»ĞµĞ³Ñ‡Ğµ Ğ²Ñ‹Ğ±Ñ€Ğ°Ñ‚ÑŒ.', 'date' => '20.04.2026'],
                ['category' => 'systems', 'category_label' => 'Ğ¡Ğ¸ÑÑ‚ĞµĞ¼Ñ‹ Ğ¸ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚ÑŒ', 'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik', 'title' => 'ĞœĞ¸Ğ½Ğ¸Ğ¼Ğ°Ğ»ÑŒĞ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚Ğ¸ Ğ´Ğ»Ñ ÑĞ½Ğ¸Ğ¶ĞµĞ½Ğ¸Ñ ÑĞ»Ğ°Ğ±Ñ‹Ñ… Ğ¼ĞµÑÑ‚ Ğ² Ğ¾Ñ„Ğ¸ÑĞ½Ğ¾Ğ¹ ÑĞµÑ‚Ğ¸', 'summary' => 'Ğ¡Ğ¸Ğ»ÑŒĞ½Ğ°Ñ Ğ¿Ğ¾Ğ»Ğ¸Ñ‚Ğ¸ĞºĞ° Ğ¿Ğ°Ñ€Ğ¾Ğ»ĞµĞ¹, Ğ´Ğ¸ÑÑ†Ğ¸Ğ¿Ğ»Ğ¸Ğ½Ğ° Ñ€ĞµĞ·ĞµÑ€Ğ²Ğ½Ğ¾Ğ³Ğ¾ ĞºĞ¾Ğ¿Ğ¸Ñ€Ğ¾Ğ²Ğ°Ğ½Ğ¸Ñ Ğ¸ Ğ¾Ğ³Ñ€Ğ°Ğ½Ğ¸Ñ‡ĞµĞ½Ğ¸Ğµ Ğ´Ğ¾ÑÑ‚ÑƒĞ¿Ğ° Ñ„Ğ¾Ñ€Ğ¼Ğ¸Ñ€ÑƒÑÑ‚ Ğ¿ĞµÑ€Ğ²Ñ‹Ğ¹ Ğ·Ğ°Ñ‰Ğ¸Ñ‚Ğ½Ñ‹Ğ¹ ÑĞ»Ğ¾Ğ¹ Ğ´Ğ»Ñ Ğ¼Ğ°Ğ»Ğ¾Ğ³Ğ¾ Ğ±Ğ¸Ğ·Ğ½ĞµÑĞ°.', 'date' => '21.04.2026'],
                ['category' => 'systems', 'category_label' => 'Ğ¡Ğ¸ÑÑ‚ĞµĞ¼Ñ‹ Ğ¸ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚ÑŒ', 'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik', 'title' => 'ĞŸĞ¾Ñ‡ĞµĞ¼Ñƒ Ğ¿Ğ»Ğ°Ğ½ Ğ»Ğ¸Ñ†ĞµĞ½Ğ·Ğ¸Ğ¹ Ğ¸ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½Ğ¸Ğ¹ Ğ²Ğ°Ğ¶ĞµĞ½ Ğ´Ğ»Ñ ĞºĞ°Ğ¼ĞµÑ€, ÑĞµÑ€Ğ²ĞµÑ€Ğ¾Ğ² Ğ¸ ÑĞµÑ‚ĞµĞ²Ñ‹Ñ… ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²?', 'summary' => 'Ğ”Ğ»Ñ ÑƒÑÑ‚Ğ¾Ğ¹Ñ‡Ğ¸Ğ²Ğ¾Ğ¹ Ñ€Ğ°Ğ±Ğ¾Ñ‚Ñ‹ ÑĞ¸ÑÑ‚ĞµĞ¼Ñ‹ Ğ²Ğ°Ğ¶Ğ½Ñ‹ Ğ½Ğµ Ñ‚Ğ¾Ğ»ÑŒĞºĞ¾ ÑƒÑÑ‚Ğ°Ğ½Ğ¾Ğ²ĞºĞ°, Ğ½Ğ¾ Ğ¸ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½Ğ¸Ñ Ñ ĞºĞ¾Ğ½Ñ‚Ñ€Ğ¾Ğ»ĞµĞ¼ Ğ»Ğ¸Ñ†ĞµĞ½Ğ·Ğ¸Ğ¹.', 'date' => '18.04.2026'],
                ['category' => 'crypto', 'category_label' => 'ĞĞ±Ğ·Ğ¾Ñ€ ĞºÑ€Ğ¸Ğ¿Ñ‚Ğ¾Ñ€Ñ‹Ğ½ĞºĞ°', 'source' => 'Ğ—Ğ°Ğ¼ĞµÑ‚ĞºĞ° Ñ€Ñ‹Ğ½ĞºĞ°', 'title' => 'ĞŸĞ¾Ñ‡ĞµĞ¼Ñƒ Ğ¿Ñ€Ğ¸ Ñ€Ğ¾ÑÑ‚Ğµ Ğ²Ğ¾Ğ»Ğ°Ñ‚Ğ¸Ğ»ÑŒĞ½Ğ¾ÑÑ‚Ğ¸ Ğ½Ğ° ĞºÑ€Ğ¸Ğ¿Ñ‚Ğ¾Ñ€Ñ‹Ğ½ĞºĞµ ÑƒĞ¿Ñ€Ğ°Ğ²Ğ»ĞµĞ½Ğ¸Ğµ Ñ€Ğ¸ÑĞºĞ°Ğ¼Ğ¸ Ğ´Ğ¾Ğ»Ğ¶Ğ½Ğ¾ Ğ±Ñ‹Ñ‚ÑŒ Ğ½Ğ° Ğ¿ĞµÑ€Ğ²Ğ¾Ğ¼ Ğ¼ĞµÑÑ‚Ğµ?', 'summary' => 'Ğ’Ğ¼ĞµÑÑ‚Ğ¾ Ñ†ĞµĞ½Ğ¾Ğ²Ñ‹Ñ… Ğ¿Ñ€Ğ¾Ğ³Ğ½Ğ¾Ğ·Ğ¾Ğ² Ğ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ñ‚ĞµĞ»Ñ Ğ½Ğ°Ğ¿Ğ¾Ğ¼Ğ¸Ğ½Ğ°ÑÑ‚ Ğ¾ Ñ€Ğ¸ÑĞºĞ°Ñ…, Ğ½Ğ¾Ğ²Ğ¾ÑÑ‚Ğ½Ğ¾Ğ¼ Ñ„Ğ¾Ğ½Ğµ Ğ¸ Ğ´Ğ¸ÑÑ†Ğ¸Ğ¿Ğ»Ğ¸Ğ½Ğµ Ñ€ĞµÑˆĞµĞ½Ğ¸Ğ¹.', 'date' => '22.04.2026'],
                ['category' => 'crypto', 'category_label' => 'ĞĞ±Ğ·Ğ¾Ñ€ ĞºÑ€Ğ¸Ğ¿Ñ‚Ğ¾Ñ€Ñ‹Ğ½ĞºĞ°', 'source' => 'Ğ—Ğ°Ğ¼ĞµÑ‚ĞºĞ° Ñ€Ñ‹Ğ½ĞºĞ°', 'title' => 'ĞšĞ°Ğº Ğ¼Ğ°ĞºÑ€Ğ¾-Ğ½Ğ¾Ğ²Ğ¾ÑÑ‚Ğ¸ Ğ²Ğ»Ğ¸ÑÑÑ‚ Ğ½Ğ° Ğ½Ğ°ÑÑ‚Ñ€Ğ¾ĞµĞ½Ğ¸Ğµ ĞºÑ€Ğ¸Ğ¿Ñ‚Ğ¾Ñ€Ñ‹Ğ½ĞºĞ°?', 'summary' => 'Ğ¡Ñ‚Ğ°Ğ²ĞºĞ¸, Ñ€ĞµĞ³ÑƒĞ»ÑÑ‚Ğ¾Ñ€Ğ½Ñ‹Ğµ Ğ½Ğ¾Ğ²Ğ¾ÑÑ‚Ğ¸ Ğ¸ ÑƒÑĞ»Ğ¾Ğ²Ğ¸Ñ Ğ»Ğ¸ĞºĞ²Ğ¸Ğ´Ğ½Ğ¾ÑÑ‚Ğ¸ Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ Ğ¿Ğ¾ĞºĞ°Ğ·Ğ°Ñ‚ÑŒ Ñ‡Ğ¸Ñ‚Ğ°Ñ‚ĞµĞ»Ñ Ğ² Ğ²Ğ¸Ğ´Ğµ ĞºÑ€Ğ°Ñ‚ĞºĞ¾Ğ³Ğ¾ Ğ¾Ğ±Ğ·Ğ¾Ñ€Ğ°.', 'date' => '19.04.2026'],
            ],
        ],
        'contact' => [
            'eyebrow' => 'ĞŸÑ€Ğ¾ÑÑ‚Ğ°Ñ Ğ·Ğ°ÑĞ²ĞºĞ° Ğ¸ ĞºĞ¾Ğ½Ñ‚Ğ°ĞºÑ‚',
            'title' => 'Ğ¡Ğ²ÑĞ¶Ğ¸Ñ‚ĞµÑÑŒ Ñ Ğ½Ğ°Ğ¼Ğ¸ Ğ½Ğ°Ğ¿Ñ€ÑĞ¼ÑƒÑ Ğ¿Ğ¾ Ğ²Ğ¾Ğ¿Ñ€Ğ¾ÑĞ°Ğ¼ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ, Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ¸ Ğ¸ Ğ±Ğ¸Ğ·Ğ½ĞµÑ-Ğ¿Ñ€ĞµĞ´Ğ»Ğ¾Ğ¶ĞµĞ½Ğ¸Ğ¹',
            'text' => 'ĞĞ´Ğ¸Ğ½ Ğ¸ Ñ‚Ğ¾Ñ‚ Ğ¶Ğµ ĞºĞ°Ğ½Ğ°Ğ» Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ Ğ¸ÑĞ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ñ‚ÑŒ Ğ´Ğ»Ñ Ğ½Ğ¾Ğ²Ğ¾Ğ³Ğ¾ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ, Ñ‚ĞµÑ…Ğ½Ğ¸Ñ‡ĞµÑĞºĞ¸Ñ… Ğ¿Ñ€Ğ¾Ğ±Ğ»ĞµĞ¼, Ğ±Ğ¸Ğ·Ğ½ĞµÑ-Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚Ğ° Ğ¸ ÑĞ¸ÑÑ‚ĞµĞ¼Ğ½Ñ‹Ñ… Ğ¿Ñ€Ğ¾ĞµĞºÑ‚Ğ¾Ğ².',
        ],
        'footer' => 'ĞœĞ¾Ğ´ĞµĞ»ÑŒ ÑĞµÑ€Ğ²Ğ¸ÑĞ°, ĞºĞ¾Ñ‚Ğ¾Ñ€Ğ°Ñ Ğ¾Ğ±ÑŠĞµĞ´Ğ¸Ğ½ÑĞµÑ‚ Ğ¾Ğ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ñ‹Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ñ‚ĞµÑ…Ğ½Ğ¸Ñ‡ĞµÑĞºÑƒÑ Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºÑƒ Ğ¸ ÑĞ¸ÑÑ‚ĞµĞ¼Ğ½Ñ‹Ğµ Ñ€ĞµÑˆĞµĞ½Ğ¸Ñ Ğ½Ğ° Ğ¾Ğ´Ğ½Ğ¾Ğ¹ Ğ¿Ğ»Ğ°Ñ‚Ñ„Ğ¾Ñ€Ğ¼Ğµ.',
        'popup' => ['label' => 'Ğ ĞµĞºĞ¾Ğ¼ĞµĞ½Ğ´ÑƒĞµĞ¼Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚', 'title' => 'Ğ‘Ñ‹ÑÑ‚Ñ€ĞµĞµ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, ĞºĞ¾Ğ¼Ñ„Ğ¾Ñ€Ñ‚Ğ½ĞµĞµ Ğ¸ÑĞ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ğ½Ğ¸Ğµ', 'offer' => 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚', 'text' => 'Ğ’Ñ‹Ğ±ĞµÑ€Ğ¸Ñ‚Ğµ Ñ‚Ğ°Ñ€Ğ¸Ñ„ Ğ¿Ğ¾Ğ´ Ğ²Ğ°Ñˆ ÑÑ†ĞµĞ½Ğ°Ñ€Ğ¸Ğ¹ Ğ¸ÑĞ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ğ½Ğ¸Ñ Ğ¸ Ğ¿Ğ¾Ğ»ÑƒÑ‡Ğ¸Ñ‚Ğµ Ğ±Ğ¾Ğ»ĞµĞµ ÑÑ‚Ğ°Ğ±Ğ¸Ğ»ÑŒĞ½Ğ¾Ğµ ÑĞ¾ĞµĞ´Ğ¸Ğ½ĞµĞ½Ğ¸Ğµ.', 'dont_show' => 'Ğ‘Ğ¾Ğ»ÑŒÑˆĞµ Ğ½Ğµ Ğ¿Ğ¾ĞºĞ°Ğ·Ñ‹Ğ²Ğ°Ñ‚ÑŒ'],
    ],
];

$newsItemsOverride = [
    'az' => [
        [
            'category' => 'network',
            'category_label' => 'Ä°nternet vÉ™ ÅŸÉ™bÉ™kÉ™',
            'source' => 'Super Optik redaksiyasÄ±',
            'title' => 'Fiber qoÅŸulmada stabil sÃ¼rÉ™t Ã¼Ã§Ã¼n ev daxilindÉ™ 3 vacib yoxlama',
            'summary' => 'Modem yeri, kabel vÉ™ziyyÉ™ti vÉ™ Wiâ€‘Fi kanal seÃ§imi istifadÉ™Ã§i tÉ™crÃ¼bÉ™sinÉ™ birbaÅŸa tÉ™sir edir. SadÉ™ yoxlama siyahÄ±sÄ± ilÉ™ problemi tez ayÄ±rd etmÉ™k olur.',
            'date' => '24.04.2026',
            'image' => 'images/news-network-expansion.png',
            'tone' => 'teal',
            'featured' => true,
        ],
        [
            'category' => 'network',
            'category_label' => 'Ä°nternet vÉ™ ÅŸÉ™bÉ™kÉ™',
            'source' => 'Super Optik redaksiyasÄ±',
            'title' => 'EvdÉ™ Wiâ€‘Fi routerini hara qoymaq daha dÃ¼zgÃ¼ndÃ¼r?',
            'summary' => 'Routeri dÃ¶ÅŸÉ™mÉ™yÉ™, kÃ¼ncÉ™ vÉ™ ya televizor arxasÄ±na qoymaq siqnalÄ± zÉ™iflÉ™dir. AÃ§Ä±q vÉ™ mÉ™rkÉ™zi nÃ¶qtÉ™ seÃ§imi eyni paketin real sÃ¼rÉ™tinÉ™ ciddi tÉ™sir edir.',
            'date' => '24.04.2026',
            'image' => 'images/news-router-placement.svg',
            'tone' => 'blue',
        ],
        [
            'category' => 'network',
            'category_label' => 'Ä°nternet vÉ™ ÅŸÉ™bÉ™kÉ™',
            'source' => 'Super Optik redaksiyasÄ±',
            'title' => 'Beton divarlar Wiâ€‘Fi siqnalÄ±nÄ± niyÉ™ zÉ™iflÉ™dir?',
            'summary' => 'DÉ™mir-beton vÉ™ qalÄ±n arakÉ™smÉ™lÉ™r 5 GHz siqnalÄ±nÄ± daha tez saxlayÄ±r. BÉ™zÉ™n problem sÃ¼rÉ™tdÉ™ yox, fiziki mÃ¼hitdÉ™ olur vÉ™ hÉ™ll access point ilÉ™ gÉ™lir.',
            'date' => '23.04.2026',
            'image' => 'images/news-concrete-wifi.svg',
            'tone' => 'amber',
        ],
        [
            'category' => 'network',
            'category_label' => 'Ä°nternet vÉ™ ÅŸÉ™bÉ™kÉ™',
            'source' => 'Super Optik redaksiyasÄ±',
            'title' => 'Televizor, mikrodalÄŸalÄ± soba vÉ™ digÉ™r cihazlar Wiâ€‘Fi-yÉ™ necÉ™ mane olur?',
            'summary' => 'Bluetooth cihazlarÄ±, mikrodalÄŸalÄ± soba, IP kamera vÉ™ bÉ™zi smart TV-lÉ™r 2.4 GHz mÃ¼hitini yÃ¼klÉ™yÉ™ bilÉ™r. Router kanalÄ± vÉ™ yerlÉ™ÅŸmÉ™ dÃ¼zgÃ¼n seÃ§ilmÉ™lidir.',
            'date' => '23.04.2026',
            'image' => 'images/news-device-interference.svg',
            'tone' => 'violet',
        ],
        [
            'category' => 'network',
            'category_label' => 'Ä°nternet vÉ™ ÅŸÉ™bÉ™kÉ™',
            'source' => 'Super Optik redaksiyasÄ±',
            'title' => 'NeÃ§É™ cihaz Ã¼Ã§Ã¼n neÃ§É™ Mbit/s kifayÉ™tdir?',
            'summary' => '2 telefon, 1 smart TV vÉ™ 1 noutbuk olan ev ilÉ™ 10 cihazlÄ± ailÉ™nin ehtiyacÄ± eyni deyil. Paket seÃ§É™rkÉ™n yalnÄ±z qiymÉ™tÉ™ yox, eyni anda iÅŸlÉ™yÉ™n cihaz sayÄ±na baxmaq lazÄ±mdÄ±r.',
            'date' => '22.04.2026',
            'image' => 'images/news-speed-capacity.svg',
            'tone' => 'teal',
        ],
        [
            'category' => 'network',
            'category_label' => 'Ä°nternet vÉ™ ÅŸÉ™bÉ™kÉ™',
            'source' => 'Super Optik redaksiyasÄ±',
            'title' => 'FTTH vÉ™ klassik qoÅŸulma arasÄ±nda fÉ™rq: hansÄ± istifadÉ™Ã§iyÉ™ hansÄ± model uyÄŸundur?',
            'summary' => 'Fiber xÉ™ttin evÉ™ qÉ™dÉ™r gÉ™lmÉ™si gecikmÉ™, stabilik vÉ™ gÉ™lÉ™cÉ™k sÃ¼rÉ™t artÄ±mÄ± baxÄ±mÄ±ndan Ã¼stÃ¼nlÃ¼k verir. Praktik fÉ™rqlÉ™ri sadÉ™ dildÉ™ mÃ¼qayisÉ™ edirik.',
            'date' => '20.04.2026',
            'image' => 'images/optical.jpg',
            'tone' => 'amber',
        ],
        [
            'category' => 'systems',
            'category_label' => 'Sistem vÉ™ tÉ™hlÃ¼kÉ™sizlik',
            'source' => 'Super Optik redaksiyasÄ±',
            'title' => 'Ofis ÅŸÉ™bÉ™kÉ™sindÉ™ zÉ™if nÃ¶qtÉ™lÉ™ri azaltmaq Ã¼Ã§Ã¼n minimal tÉ™hlÃ¼kÉ™sizlik paketi',
            'summary' => 'GÃ¼clÃ¼ parol siyasÉ™ti, ehtiyat nÃ¼sxÉ™ intizamÄ± vÉ™ giriÅŸlÉ™rin mÉ™hdudlaÅŸdÄ±rÄ±lmasÄ± kiÃ§ik biznes Ã¼Ã§Ã¼n ilk qoruma qatÄ±nÄ± yaradÄ±r.',
            'date' => '21.04.2026',
            'image' => 'images/news-systems-security.png',
            'tone' => 'blue',
            'featured' => true,
        ],
        [
            'category' => 'systems',
            'category_label' => 'Sistem vÉ™ tÉ™hlÃ¼kÉ™sizlik',
            'source' => 'Super Optik redaksiyasÄ±',
            'title' => 'Kamera, server vÉ™ ÅŸÉ™bÉ™kÉ™ cihazlarÄ± Ã¼Ã§Ã¼n lisenziya vÉ™ yenilÉ™mÉ™ planÄ± niyÉ™ vacibdir?',
            'summary' => 'YalnÄ±z qurulum deyil, mÃ¼ntÉ™zÉ™m yenilÉ™nmÉ™ vÉ™ lisenziya nÉ™zarÉ™ti dÉ™ dayanÄ±qÄ±lÄ± sistem iÅŸinin vacib hissÉ™sidir.',
            'date' => '18.04.2026',
            'image' => 'images/serverinstall.jpg',
            'tone' => 'violet',
        ],
        [
            'category' => 'systems',
            'category_label' => 'Sistem vÉ™ tÉ™hlÃ¼kÉ™sizlik',
            'source' => 'Super Optik redaksiyasÄ±',
            'title' => 'Router admin panelindÉ™ ÅŸifrÉ™ni dÉ™yiÅŸmÉ™k niyÉ™ vacibdir?',
            'summary' => 'Defolt admin ÅŸifrÉ™si dÉ™yiÅŸmÉ™dikdÉ™ ev vÉ™ ofis ÅŸÉ™bÉ™kÉ™si aÃ§Ä±q risk altÄ±nda qalÄ±r. Bir neÃ§É™ sadÉ™ addÄ±m hÉ™m routeri, hÉ™m dÉ™ Wiâ€‘Fi ayarlarÄ±nÄ± qoruyur.',
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
            'summary' => 'Modem placement, cable condition and Wiâ€‘Fi channel choice directly affect user experience. A short checklist helps separate line issues from home setup issues.',
            'date' => '24.04.2026',
            'image' => 'images/news-network-expansion.png',
            'tone' => 'teal',
            'featured' => true,
        ],
        [
            'category' => 'network',
            'category_label' => 'Internet and network',
            'source' => 'Super Optik editorial',
            'title' => 'Where is the best place to put a Wiâ€‘Fi router at home?',
            'summary' => 'Putting the router on the floor, in a corner or behind the TV reduces coverage. A central and open placement often improves the real speed of the same package.',
            'date' => '24.04.2026',
            'image' => 'images/news-router-placement.svg',
            'tone' => 'blue',
        ],
        [
            'category' => 'network',
            'category_label' => 'Internet and network',
            'source' => 'Super Optik editorial',
            'title' => 'Why do concrete walls weaken Wiâ€‘Fi signal?',
            'summary' => 'Reinforced concrete and dense walls absorb 5 GHz signal much faster. In many homes the real issue is not the tariff, but the physical environment around the router.',
            'date' => '23.04.2026',
            'image' => 'images/news-concrete-wifi.svg',
            'tone' => 'amber',
        ],
        [
            'category' => 'network',
            'category_label' => 'Internet and network',
            'source' => 'Super Optik editorial',
            'title' => 'How do TVs, microwaves and other devices interfere with Wiâ€‘Fi?',
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
            'summary' => 'Leaving the default admin password unchanged keeps both home and office networks exposed. A few simple steps protect Wiâ€‘Fi settings and device access.',
            'date' => '17.04.2026',
            'image' => 'images/licensing.jpg',
            'tone' => 'amber',
        ],
    ],
    'ru' => [
        [
            'category' => 'network',
            'category_label' => 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ¸ ÑĞµÑ‚ÑŒ',
            'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik',
            'title' => '3 Ğ²Ğ°Ğ¶Ğ½Ñ‹Ğµ Ğ´Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğµ Ğ¿Ñ€Ğ¾Ğ²ĞµÑ€ĞºĞ¸ Ğ´Ğ»Ñ ÑÑ‚Ğ°Ğ±Ğ¸Ğ»ÑŒĞ½Ğ¾Ğ¹ ÑĞºĞ¾Ñ€Ğ¾ÑÑ‚Ğ¸ Ğ½Ğ° fiber-Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ğ¸',
            'summary' => 'Ğ Ğ°ÑĞ¿Ğ¾Ğ»Ğ¾Ğ¶ĞµĞ½Ğ¸Ğµ Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ°, ÑĞ¾ÑÑ‚Ğ¾ÑĞ½Ğ¸Ğµ ĞºĞ°Ğ±ĞµĞ»Ñ Ğ¸ Ğ²Ñ‹Ğ±Ğ¾Ñ€ ĞºĞ°Ğ½Ğ°Ğ»Ğ° Wiâ€‘Fi Ğ½Ğ°Ğ¿Ñ€ÑĞ¼ÑƒÑ Ğ²Ğ»Ğ¸ÑÑÑ‚ Ğ½Ğ° Ğ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ñ‚ĞµĞ»ÑŒÑĞºĞ¸Ğ¹ Ğ¾Ğ¿Ñ‹Ñ‚. ĞšĞ¾Ñ€Ğ¾Ñ‚ĞºĞ¸Ğ¹ Ñ‡ĞµĞº-Ğ»Ğ¸ÑÑ‚ Ğ¿Ğ¾Ğ¼Ğ¾Ğ³Ğ°ĞµÑ‚ Ğ±Ñ‹ÑÑ‚Ñ€Ğ¾ Ğ¿Ğ¾Ğ½ÑÑ‚ÑŒ Ğ¸ÑÑ‚Ğ¾Ñ‡Ğ½Ğ¸Ğº Ğ¿Ñ€Ğ¾Ğ±Ğ»ĞµĞ¼Ñ‹.',
            'date' => '24.04.2026',
            'image' => 'images/news-network-expansion.png',
            'tone' => 'teal',
            'featured' => true,
        ],
        [
            'category' => 'network',
            'category_label' => 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ¸ ÑĞµÑ‚ÑŒ',
            'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik',
            'title' => 'Ğ“Ğ´Ğµ Ğ´Ğ¾Ğ¼Ğ° Ğ»ÑƒÑ‡ÑˆĞµ Ğ¿Ğ¾ÑÑ‚Ğ°Ğ²Ğ¸Ñ‚ÑŒ Wiâ€‘Fi Ñ€Ğ¾ÑƒÑ‚ĞµÑ€?',
            'summary' => 'Ğ•ÑĞ»Ğ¸ Ñ€Ğ¾ÑƒÑ‚ĞµÑ€ ÑÑ‚Ğ¾Ğ¸Ñ‚ Ğ½Ğ° Ğ¿Ğ¾Ğ»Ñƒ, Ğ² ÑƒĞ³Ğ»Ñƒ Ğ¸Ğ»Ğ¸ Ğ·Ğ° Ñ‚ĞµĞ»ĞµĞ²Ğ¸Ğ·Ğ¾Ñ€Ğ¾Ğ¼, Ğ¿Ğ¾ĞºÑ€Ñ‹Ñ‚Ğ¸Ğµ Ğ·Ğ°Ğ¼ĞµÑ‚Ğ½Ğ¾ Ğ¿Ğ°Ğ´Ğ°ĞµÑ‚. ĞÑ‚ĞºÑ€Ñ‹Ñ‚Ğ°Ñ Ğ¸ Ñ†ĞµĞ½Ñ‚Ñ€Ğ°Ğ»ÑŒĞ½Ğ°Ñ Ñ‚Ğ¾Ñ‡ĞºĞ° Ñ‡Ğ°ÑÑ‚Ğ¾ Ğ´Ğ°ĞµÑ‚ Ñ€ĞµĞ°Ğ»ÑŒĞ½Ñ‹Ğ¹ Ğ¿Ñ€Ğ¸Ñ€Ğ¾ÑÑ‚ ĞºĞ°Ñ‡ĞµÑÑ‚Ğ²Ğ° Ğ±ĞµĞ· ÑĞ¼ĞµĞ½Ñ‹ Ñ‚Ğ°Ñ€Ğ¸Ñ„Ğ°.',
            'date' => '24.04.2026',
            'image' => 'images/news-router-placement.svg',
            'tone' => 'blue',
        ],
        [
            'category' => 'network',
            'category_label' => 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ¸ ÑĞµÑ‚ÑŒ',
            'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik',
            'title' => 'ĞŸĞ¾Ñ‡ĞµĞ¼Ñƒ Ğ±ĞµÑ‚Ğ¾Ğ½Ğ½Ñ‹Ğµ ÑÑ‚ĞµĞ½Ñ‹ Ğ¾ÑĞ»Ğ°Ğ±Ğ»ÑÑÑ‚ ÑĞ¸Ğ³Ğ½Ğ°Ğ» Wiâ€‘Fi?',
            'summary' => 'Ğ–ĞµĞ»ĞµĞ·Ğ¾Ğ±ĞµÑ‚Ğ¾Ğ½ Ğ¸ Ğ¿Ğ»Ğ¾Ñ‚Ğ½Ñ‹Ğµ Ğ¿ĞµÑ€ĞµĞ³Ğ¾Ñ€Ğ¾Ğ´ĞºĞ¸ Ğ¾ÑĞ¾Ğ±ĞµĞ½Ğ½Ğ¾ Ğ±Ñ‹ÑÑ‚Ñ€Ğ¾ Ğ³Ğ°ÑÑÑ‚ 5 GHz ÑĞ¸Ğ³Ğ½Ğ°Ğ». Ğ’Ğ¾ Ğ¼Ğ½Ğ¾Ğ³Ğ¸Ñ… ĞºĞ²Ğ°Ñ€Ñ‚Ğ¸Ñ€Ğ°Ñ… Ğ¿Ñ€Ğ¾Ğ±Ğ»ĞµĞ¼Ğ° Ğ½Ğµ Ğ² Ñ‚Ğ°Ñ€Ğ¸Ñ„Ğµ, Ğ° Ğ² Ñ„Ğ¸Ğ·Ğ¸Ñ‡ĞµÑĞºĞ¾Ğ¹ ÑÑ€ĞµĞ´Ğµ Ğ²Ğ¾ĞºÑ€ÑƒĞ³ Ñ€Ğ¾ÑƒÑ‚ĞµÑ€Ğ°.',
            'date' => '23.04.2026',
            'image' => 'images/news-concrete-wifi.svg',
            'tone' => 'amber',
        ],
        [
            'category' => 'network',
            'category_label' => 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ¸ ÑĞµÑ‚ÑŒ',
            'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik',
            'title' => 'ĞšĞ°Ğº Ñ‚ĞµĞ»ĞµĞ²Ğ¸Ğ·Ğ¾Ñ€Ñ‹, Ğ¼Ğ¸ĞºÑ€Ğ¾Ğ²Ğ¾Ğ»Ğ½Ğ¾Ğ²ĞºĞ¸ Ğ¸ Ğ´Ñ€ÑƒĞ³Ğ¸Ğµ ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²Ğ° Ğ¼ĞµÑˆĞ°ÑÑ‚ Wiâ€‘Fi?',
            'summary' => 'Bluetooth-Ğ°ĞºÑĞµÑÑÑƒĞ°Ñ€Ñ‹, Ğ¼Ğ¸ĞºÑ€Ğ¾Ğ²Ğ¾Ğ»Ğ½Ğ¾Ğ²ĞºĞ¸, Ğ±ĞµÑĞ¿Ñ€Ğ¾Ğ²Ğ¾Ğ´Ğ½Ñ‹Ğµ ĞºĞ°Ğ¼ĞµÑ€Ñ‹ Ğ¸ Ğ½ĞµĞºĞ¾Ñ‚Ğ¾Ñ€Ñ‹Ğµ smart TV Ğ·Ğ°Ğ³Ñ€ÑƒĞ¶Ğ°ÑÑ‚ Ğ´Ğ¸Ğ°Ğ¿Ğ°Ğ·Ğ¾Ğ½ 2.4 GHz. ĞšĞ°Ğ½Ğ°Ğ» Ñ€Ğ¾ÑƒÑ‚ĞµÑ€Ğ° Ğ¸ ĞµĞ³Ğ¾ Ñ€Ğ°ÑĞ¿Ğ¾Ğ»Ğ¾Ğ¶ĞµĞ½Ğ¸Ğµ Ğ·Ğ´ĞµÑÑŒ ĞºÑ€Ğ¸Ñ‚Ğ¸Ñ‡Ğ½Ñ‹.',
            'date' => '23.04.2026',
            'image' => 'images/news-device-interference.svg',
            'tone' => 'violet',
        ],
        [
            'category' => 'network',
            'category_label' => 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ¸ ÑĞµÑ‚ÑŒ',
            'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik',
            'title' => 'Ğ¡ĞºĞ¾Ğ»ÑŒĞºĞ¾ Mbps Ğ´Ğ¾ÑÑ‚Ğ°Ñ‚Ğ¾Ñ‡Ğ½Ğ¾ Ğ´Ğ»Ñ ĞºĞ°ĞºĞ¾Ğ³Ğ¾ ĞºĞ¾Ğ»Ğ¸Ñ‡ĞµÑÑ‚Ğ²Ğ° ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²?',
            'summary' => 'ĞšĞ²Ğ°Ñ€Ñ‚Ğ¸Ñ€Ğ° Ñ Ğ´Ğ²ÑƒĞ¼Ñ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ°Ğ¼Ğ¸ Ğ¸ Ğ¾Ğ´Ğ½Ğ¸Ğ¼ Ñ‚ĞµĞ»ĞµĞ²Ğ¸Ğ·Ğ¾Ñ€Ğ¾Ğ¼ Ğ½Ğµ Ñ‚Ñ€ĞµĞ±ÑƒĞµÑ‚ Ñ‚Ğ¾Ğ³Ğ¾ Ğ¶Ğµ Ğ¿Ğ°ĞºĞµÑ‚Ğ°, Ñ‡Ñ‚Ğ¾ Ğ¸ Ğ´Ğ¾Ğ¼ Ñ Ğ´ĞµÑÑÑ‚ÑŒÑ Ğ°ĞºÑ‚Ğ¸Ğ²Ğ½Ñ‹Ğ¼Ğ¸ ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²Ğ°Ğ¼Ğ¸. Ğ¡ĞºĞ¾Ñ€Ğ¾ÑÑ‚ÑŒ Ğ½ÑƒĞ¶Ğ½Ğ¾ Ğ²Ñ‹Ğ±Ğ¸Ñ€Ğ°Ñ‚ÑŒ Ğ¿Ğ¾ Ğ¾Ğ´Ğ½Ğ¾Ğ²Ñ€ĞµĞ¼ĞµĞ½Ğ½Ğ¾Ğ¹ Ğ½Ğ°Ğ³Ñ€ÑƒĞ·ĞºĞµ.',
            'date' => '22.04.2026',
            'image' => 'images/news-speed-capacity.svg',
            'tone' => 'teal',
        ],
        [
            'category' => 'network',
            'category_label' => 'Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ¸ ÑĞµÑ‚ÑŒ',
            'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik',
            'title' => 'FTTH Ğ¸ ĞºĞ»Ğ°ÑÑĞ¸Ñ‡ĞµÑĞºĞ¾Ğµ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ğµ: ĞºĞ°ĞºĞ¾Ğ¹ Ğ²Ğ°Ñ€Ğ¸Ğ°Ğ½Ñ‚ ĞºĞ¾Ğ¼Ñƒ Ğ¿Ğ¾Ğ´Ñ…Ğ¾Ğ´Ğ¸Ñ‚?',
            'summary' => 'Fiber Ğ´Ğ¾ ĞºĞ²Ğ°Ñ€Ñ‚Ğ¸Ñ€Ñ‹ Ğ´Ğ°ĞµÑ‚ Ğ»ÑƒÑ‡ÑˆÑƒÑ ÑÑ‚Ğ°Ğ±Ğ¸Ğ»ÑŒĞ½Ğ¾ÑÑ‚ÑŒ, Ğ¼ĞµĞ½ÑŒÑˆÑƒÑ Ğ·Ğ°Ğ´ĞµÑ€Ğ¶ĞºÑƒ Ğ¸ Ğ·Ğ°Ğ¿Ğ°Ñ Ğ¿Ğ¾ Ğ±ÑƒĞ´ÑƒÑ‰Ğ¸Ğ¼ ÑĞºĞ¾Ñ€Ğ¾ÑÑ‚ÑĞ¼. Ğ¡Ñ€Ğ°Ğ²Ğ½Ğ¸Ğ²Ğ°ĞµĞ¼ Ğ¿Ñ€Ğ°ĞºÑ‚Ğ¸Ñ‡ĞµÑĞºĞ¸Ğµ Ñ€Ğ°Ğ·Ğ»Ğ¸Ñ‡Ğ¸Ñ Ğ¿Ñ€Ğ¾ÑÑ‚Ñ‹Ğ¼ ÑĞ·Ñ‹ĞºĞ¾Ğ¼.',
            'date' => '20.04.2026',
            'image' => 'images/optical.jpg',
            'tone' => 'amber',
        ],
        [
            'category' => 'systems',
            'category_label' => 'Ğ¡Ğ¸ÑÑ‚ĞµĞ¼Ñ‹ Ğ¸ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚ÑŒ',
            'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik',
            'title' => 'ĞœĞ¸Ğ½Ğ¸Ğ¼Ğ°Ğ»ÑŒĞ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚Ğ¸ Ğ´Ğ»Ñ ÑĞ½Ğ¸Ğ¶ĞµĞ½Ğ¸Ñ ÑĞ»Ğ°Ğ±Ñ‹Ñ… Ğ¼ĞµÑÑ‚ Ğ² Ğ¾Ñ„Ğ¸ÑĞ½Ğ¾Ğ¹ ÑĞµÑ‚Ğ¸',
            'summary' => 'Ğ¡Ğ¸Ğ»ÑŒĞ½Ğ°Ñ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒĞ½Ğ°Ñ Ğ¿Ğ¾Ğ»Ğ¸Ñ‚Ğ¸ĞºĞ°, Ñ€ĞµĞ·ĞµÑ€Ğ²Ğ½Ğ¾Ğµ ĞºĞ¾Ğ¿Ğ¸Ñ€Ğ¾Ğ²Ğ°Ğ½Ğ¸Ğµ Ğ¸ Ğ¾Ğ³Ñ€Ğ°Ğ½Ğ¸Ñ‡ĞµĞ½Ğ¸Ğµ Ğ´Ğ¾ÑÑ‚ÑƒĞ¿Ğ° ÑĞ¾Ğ·Ğ´Ğ°ÑÑ‚ Ğ¿ĞµÑ€Ğ²Ñ‹Ğ¹ Ğ¿Ñ€Ğ°ĞºÑ‚Ğ¸Ñ‡ĞµÑĞºĞ¸Ğ¹ ÑĞ»Ğ¾Ğ¹ Ğ·Ğ°Ñ‰Ğ¸Ñ‚Ñ‹ Ğ´Ğ»Ñ Ğ¼Ğ°Ğ»Ğ¾Ğ³Ğ¾ Ğ±Ğ¸Ğ·Ğ½ĞµÑĞ°.',
            'date' => '21.04.2026',
            'image' => 'images/news-systems-security.png',
            'tone' => 'blue',
            'featured' => true,
        ],
        [
            'category' => 'systems',
            'category_label' => 'Ğ¡Ğ¸ÑÑ‚ĞµĞ¼Ñ‹ Ğ¸ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚ÑŒ',
            'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik',
            'title' => 'ĞŸĞ¾Ñ‡ĞµĞ¼Ñƒ Ğ²Ğ°Ğ¶ĞµĞ½ Ğ¿Ğ»Ğ°Ğ½ Ğ»Ğ¸Ñ†ĞµĞ½Ğ·Ğ¸Ğ¹ Ğ¸ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½Ğ¸Ğ¹ Ğ´Ğ»Ñ ĞºĞ°Ğ¼ĞµÑ€, ÑĞµÑ€Ğ²ĞµÑ€Ğ¾Ğ² Ğ¸ ÑĞµÑ‚ĞµĞ²Ñ‹Ñ… ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²?',
            'summary' => 'Ğ”Ğ»Ñ ÑƒÑÑ‚Ğ¾Ğ¹Ñ‡Ğ¸Ğ²Ğ¾Ğ¹ Ñ€Ğ°Ğ±Ğ¾Ñ‚Ñ‹ ÑĞ¸ÑÑ‚ĞµĞ¼Ñ‹ Ğ½ÑƒĞ¶Ğ½Ñ‹ Ğ½Ğµ Ñ‚Ğ¾Ğ»ÑŒĞºĞ¾ ÑƒÑÑ‚Ğ°Ğ½Ğ¾Ğ²ĞºĞ°, Ğ½Ğ¾ Ğ¸ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½Ğ¸Ñ, Ğ¿Ğ°Ñ‚Ñ‡Ğ¸ Ğ¸ ĞºĞ¾Ğ½Ñ‚Ñ€Ğ¾Ğ»ÑŒ Ğ»Ğ¸Ñ†ĞµĞ½Ğ·Ğ¸Ğ¹.',
            'date' => '18.04.2026',
            'image' => 'images/serverinstall.jpg',
            'tone' => 'violet',
        ],
        [
            'category' => 'systems',
            'category_label' => 'Ğ¡Ğ¸ÑÑ‚ĞµĞ¼Ñ‹ Ğ¸ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚ÑŒ',
            'source' => 'Ğ ĞµĞ´Ğ°ĞºÑ†Ğ¸Ñ Super Optik',
            'title' => 'ĞŸĞ¾Ñ‡ĞµĞ¼Ñƒ Ğ²Ğ°Ğ¶Ğ½Ğ¾ Ğ¼ĞµĞ½ÑÑ‚ÑŒ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ Ğ¿Ğ¾ ÑƒĞ¼Ğ¾Ğ»Ñ‡Ğ°Ğ½Ğ¸Ñ Ğ² Ğ¿Ğ°Ğ½ĞµĞ»Ğ¸ Ñ€Ğ¾ÑƒÑ‚ĞµÑ€Ğ°?',
            'summary' => 'Ğ•ÑĞ»Ğ¸ Ğ¾ÑÑ‚Ğ°Ğ²Ğ¸Ñ‚ÑŒ ÑÑ‚Ğ°Ğ½Ğ´Ğ°Ñ€Ñ‚Ğ½Ñ‹Ğ¹ admin-Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ, Ğ¸ Ğ´Ğ¾Ğ¼Ğ°ÑˆĞ½ÑÑ, Ğ¸ Ğ¾Ñ„Ğ¸ÑĞ½Ğ°Ñ ÑĞµÑ‚ÑŒ Ğ¾ÑÑ‚Ğ°ÑÑ‚ÑÑ Ğ¿Ğ¾Ğ´ Ğ»Ğ¸ÑˆĞ½Ğ¸Ğ¼ Ñ€Ğ¸ÑĞºĞ¾Ğ¼. ĞŸÑ€Ğ¾ÑÑ‚Ğ°Ñ Ğ·Ğ°Ğ¼ĞµĞ½Ğ° Ğ·Ğ°Ñ‰Ğ¸Ñ‰Ğ°ĞµÑ‚ Ğ½Ğ°ÑÑ‚Ñ€Ğ¾Ğ¹ĞºĞ¸ Ğ¸ Ğ´Ğ¾ÑÑ‚ÑƒĞ¿ Ğº ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²Ñƒ.',
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
        'title' => 'Super Optik | Fiber internet, TV, IT dÉ™stÉ™k vÉ™ proqram tÉ™minatÄ±',
        'description' => 'Super Optik BakÄ±da vÉ™ bÃ¶lgÉ™lÉ™rdÉ™ fiber internet, TV xidmÉ™ti, biznes interneti, IT dÉ™stÉ™k, server, ÅŸÉ™bÉ™kÉ™ vÉ™ proqram tÉ™minatÄ± hÉ™llÉ™ri tÉ™qdim edir.',
        'keywords' => 'internet, internet tv, fiber internet, biznes interneti, tv xidmÉ™ti, it dÉ™stÉ™k, it support, proqram tÉ™minatÄ±, software, server, ÅŸÉ™bÉ™kÉ™, lan wan, super optik',
        'service_type' => 'Fiber internet, TV, IT dÉ™stÉ™k vÉ™ proqram tÉ™minatÄ±',
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
        'title' => 'Super Optik | ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ñ‹Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ğ¢Ğ’, IT-Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ° Ğ¸ ĞŸĞ',
        'description' => 'Super Optik Ğ¿Ñ€ĞµĞ´Ğ¾ÑÑ‚Ğ°Ğ²Ğ»ÑĞµÑ‚ Ğ¾Ğ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ñ‹Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ğ¢Ğ’, Ğ±Ğ¸Ğ·Ğ½ĞµÑ-Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, IT-Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºÑƒ, ÑĞµÑ€Ğ²ĞµÑ€Ğ½Ñ‹Ğµ, ÑĞµÑ‚ĞµĞ²Ñ‹Ğµ Ğ¸ Ğ¿Ñ€Ğ¾Ğ³Ñ€Ğ°Ğ¼Ğ¼Ğ½Ñ‹Ğµ Ñ€ĞµÑˆĞµĞ½Ğ¸Ñ Ğ² Ğ‘Ğ°ĞºÑƒ Ğ¸ Ñ€ĞµĞ³Ğ¸Ğ¾Ğ½Ğ°Ñ….',
        'keywords' => 'Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ñ‚Ğ², Ğ¾Ğ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ñ‹Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ğ±Ğ¸Ğ·Ğ½ĞµÑ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ñ‚Ğ², it Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ°, Ğ¿Ñ€Ğ¾Ğ³Ñ€Ğ°Ğ¼Ğ¼Ğ½Ğ¾Ğµ Ğ¾Ğ±ĞµÑĞ¿ĞµÑ‡ĞµĞ½Ğ¸Ğµ, ÑĞµÑ€Ğ²ĞµÑ€, ÑĞµÑ‚ÑŒ, Super Optik',
        'service_type' => 'ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ñ‹Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ğ¢Ğ’, IT-Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ° Ğ¸ Ğ¿Ñ€Ğ¾Ğ³Ñ€Ğ°Ğ¼Ğ¼Ğ½Ğ¾Ğµ Ğ¾Ğ±ĞµÑĞ¿ĞµÑ‡ĞµĞ½Ğ¸Ğµ',
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
        'request_sent' => ['type' => 'success', 'message' => 'MÃ¼raciÉ™tiniz qÉ™bul edildi. KomandamÄ±z sizinlÉ™ qÄ±sa zamanda É™laqÉ™ saxlayacaq.'],
        'request_failed' => ['type' => 'error', 'message' => 'MÃ¼raciÉ™t gÃ¶ndÉ™rilÉ™rkÉ™n problem yarandÄ±. ZÉ™hmÉ™t olmasa mÉ™lumatlarÄ± yenidÉ™n yoxlayÄ±n.'],
        'captcha_failed' => ['type' => 'error', 'message' => 'Robot yoxlamasÄ± tamamlanmadÄ± vÉ™ ya uÄŸursuz oldu.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Sessiya yoxlamasÄ± uÄŸursuz oldu. SÉ™hifÉ™ni yenilÉ™yib yenidÉ™n cÉ™hd edin.'],
        'rate_limited' => ['type' => 'error', 'message' => 'Ã‡ox qÄ±sa vaxtda hÉ™ddÉ™n artÄ±q mÃ¼raciÉ™t gÃ¶ndÉ™rildi. Bir neÃ§É™ dÉ™qiqÉ™ sonra yenidÉ™n cÉ™hd edin.'],
        'missing_config' => ['type' => 'error', 'message' => 'Server konfiqurasiyasÄ± tamamlanmayÄ±b. Config faylÄ±nÄ± doldurmaq lazÄ±mdÄ±r.'],
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
        'request_sent' => ['type' => 'success', 'message' => 'Ğ’Ğ°ÑˆĞµ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ğµ Ğ¿Ñ€Ğ¸Ğ½ÑÑ‚Ğ¾. ĞĞ°ÑˆĞ° ĞºĞ¾Ğ¼Ğ°Ğ½Ğ´Ğ° ÑĞ²ÑĞ¶ĞµÑ‚ÑÑ Ñ Ğ²Ğ°Ğ¼Ğ¸ Ğ² Ğ±Ğ»Ğ¸Ğ¶Ğ°Ğ¹ÑˆĞµĞµ Ğ²Ñ€ĞµĞ¼Ñ.'],
        'request_failed' => ['type' => 'error', 'message' => 'ĞŸÑ€Ğ¸ Ğ¾Ñ‚Ğ¿Ñ€Ğ°Ğ²ĞºĞµ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ñ Ğ²Ğ¾Ğ·Ğ½Ğ¸ĞºĞ»Ğ° Ğ¿Ñ€Ğ¾Ğ±Ğ»ĞµĞ¼Ğ°. ĞŸĞ¾Ğ¶Ğ°Ğ»ÑƒĞ¹ÑÑ‚Ğ°, Ğ¿Ñ€Ğ¾Ğ²ĞµÑ€ÑŒÑ‚Ğµ Ğ´Ğ°Ğ½Ğ½Ñ‹Ğµ Ğ¸ Ğ¿Ğ¾Ğ¿Ñ€Ğ¾Ğ±ÑƒĞ¹Ñ‚Ğµ ÑĞ½Ğ¾Ğ²Ğ°.'],
        'captcha_failed' => ['type' => 'error', 'message' => 'ĞŸÑ€Ğ¾Ğ²ĞµÑ€ĞºĞ° Ñ€Ğ¾Ğ±Ğ¾Ñ‚Ğ° Ğ½Ğµ Ğ±Ñ‹Ğ»Ğ° Ğ·Ğ°Ğ²ĞµÑ€ÑˆĞµĞ½Ğ° Ğ¸Ğ»Ğ¸ Ğ·Ğ°Ğ²ĞµÑ€ÑˆĞ¸Ğ»Ğ°ÑÑŒ Ğ½ĞµÑƒÑĞ¿ĞµÑˆĞ½Ğ¾.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'ĞŸÑ€Ğ¾Ğ²ĞµÑ€ĞºĞ° ÑĞµÑÑĞ¸Ğ¸ Ğ½Ğµ Ğ¿Ñ€Ğ¾ÑˆĞ»Ğ°. ĞĞ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚Ğµ ÑÑ‚Ñ€Ğ°Ğ½Ğ¸Ñ†Ñƒ Ğ¸ Ğ¿Ğ¾Ğ¿Ñ€Ğ¾Ğ±ÑƒĞ¹Ñ‚Ğµ ÑĞ½Ğ¾Ğ²Ğ°.'],
        'rate_limited' => ['type' => 'error', 'message' => 'Ğ—Ğ° ĞºĞ¾Ñ€Ğ¾Ñ‚ĞºĞ¾Ğµ Ğ²Ñ€ĞµĞ¼Ñ Ğ¾Ñ‚Ğ¿Ñ€Ğ°Ğ²Ğ»ĞµĞ½Ğ¾ ÑĞ»Ğ¸ÑˆĞºĞ¾Ğ¼ Ğ¼Ğ½Ğ¾Ğ³Ğ¾ Ğ·Ğ°Ğ¿Ñ€Ğ¾ÑĞ¾Ğ². ĞŸĞ¾Ğ´Ğ¾Ğ¶Ğ´Ğ¸Ñ‚Ğµ Ğ½ĞµÑĞºĞ¾Ğ»ÑŒĞºĞ¾ Ğ¼Ğ¸Ğ½ÑƒÑ‚ Ğ¸ Ğ¿Ğ¾Ğ¿Ñ€Ğ¾Ğ±ÑƒĞ¹Ñ‚Ğµ ÑĞ½Ğ¾Ğ²Ğ°.'],
        'missing_config' => ['type' => 'error', 'message' => 'ĞšĞ¾Ğ½Ñ„Ğ¸Ğ³ÑƒÑ€Ğ°Ñ†Ğ¸Ñ ÑĞµÑ€Ğ²ĞµÑ€Ğ° Ğ½Ğµ Ğ·Ğ°Ğ²ĞµÑ€ÑˆĞµĞ½Ğ°. ĞĞµĞ¾Ğ±Ñ…Ğ¾Ğ´Ğ¸Ğ¼Ğ¾ Ğ·Ğ°Ğ¿Ğ¾Ğ»Ğ½Ğ¸Ñ‚ÑŒ config-Ñ„Ğ°Ğ¹Ğ».'],
    ],
];
if (isset($flashMap[$lang][$status])) {
    $flash = $flashMap[$lang][$status];
}
if ($status === 'area_interest_saved') {
    $flash = [
        'type' => 'success',
        'message' => [
            'az' => 'SeÃ§diyiniz rayon qeyd edildi. AylÄ±q maraq statistikasÄ± yenilÉ™ndi.',
            'en' => 'Your selected district was saved. Monthly interest statistics were updated.',
            'ru' => 'Ğ’Ñ‹Ğ±Ñ€Ğ°Ğ½Ğ½Ñ‹Ğ¹ Ñ€Ğ°Ğ¹Ğ¾Ğ½ ÑĞ¾Ñ…Ñ€Ğ°Ğ½ĞµĞ½. Ğ•Ğ¶ĞµĞ¼ĞµÑÑÑ‡Ğ½Ğ°Ñ ÑÑ‚Ğ°Ñ‚Ğ¸ÑÑ‚Ğ¸ĞºĞ° Ğ¸Ğ½Ñ‚ĞµÑ€ĞµÑĞ° Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½Ğ°.',
        ][$lang] ?? 'District saved.',
    ];
} elseif ($status === 'area_interest_failed') {
    $flash = [
        'type' => 'error',
        'message' => [
            'az' => 'Rayon seÃ§imi qeydÉ™ alÄ±nmadÄ±. ZÉ™hmÉ™t olmasa yenidÉ™n cÉ™hd edin.',
            'en' => 'The district selection could not be saved. Please try again.',
            'ru' => 'ĞĞµ ÑƒĞ´Ğ°Ğ»Ğ¾ÑÑŒ ÑĞ¾Ñ…Ñ€Ğ°Ğ½Ğ¸Ñ‚ÑŒ Ğ²Ñ‹Ğ±Ñ€Ğ°Ğ½Ğ½Ñ‹Ğ¹ Ñ€Ğ°Ğ¹Ğ¾Ğ½. ĞŸĞ¾Ğ¶Ğ°Ğ»ÑƒĞ¹ÑÑ‚Ğ°, Ğ¿Ğ¾Ğ¿Ñ€Ğ¾Ğ±ÑƒĞ¹Ñ‚Ğµ ÑĞ½Ğ¾Ğ²Ğ°.',
        ][$lang] ?? 'District save failed.',
    ];
}
$districtOptions = app_azerbaijan_districts();
if (!in_array('MaraÄŸa', $districtOptions, true)) {
    $districtOptions[] = 'MaraÄŸa';
}
$areaInterestSummary = app_area_interest_monthly_summary();
$tvChannels = app_tv_channels();
$coveragePanel = [
    'az' => [
        'service_tag' => 'Aktiv xidmÉ™t nÃ¶qtÉ™si',
        'search_label' => 'Rayon axtarÄ±ÅŸÄ±',
        'search_placeholder' => 'HansÄ± rayonda xidmÉ™t var?',
        'search_empty' => 'AxtarÄ±ÅŸa uyÄŸun aktiv rayon tapÄ±lmadÄ±.',
        'interest_title' => 'Yeni É™hatÉ™ planÄ± Ã¼Ã§Ã¼n rayon qeyd et',
        'interest_text' => 'ÆhatÉ™dÉ™ olmayan rayonu seÃ§in. Toplanan sorÄŸular aylÄ±q planlamaya dÃ¼ÅŸÃ¼r vÉ™ texniki imkan uyÄŸun olduqda yeni qoÅŸulma prioritetinÉ™ daxil edilir.',
        'interest_label' => 'Rayon seÃ§imi',
        'interest_placeholder' => 'AzÉ™rbaycan rayonunu seÃ§in',
        'interest_action' => 'Qeyd saxla',
        'stats_title' => 'Bu ay É™n Ã§ox soruÅŸulan rayonlar',
        'stats_total' => 'Toplam mÃ¼raciÉ™t',
        'stats_empty' => 'Bu ay Ã¼Ã§Ã¼n hÉ™lÉ™ qeyd yoxdur.',
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
        'service_tag' => 'ĞĞºÑ‚Ğ¸Ğ²Ğ½Ğ°Ñ Ñ‚Ğ¾Ñ‡ĞºĞ° ÑƒÑĞ»ÑƒĞ³Ğ¸',
        'search_label' => 'ĞŸĞ¾Ğ¸ÑĞº Ñ€Ğ°Ğ¹Ğ¾Ğ½Ğ°',
        'search_placeholder' => 'Ğ“Ğ´Ğµ ĞµÑÑ‚ÑŒ Ğ¿Ğ¾ĞºÑ€Ñ‹Ñ‚Ğ¸Ğµ?',
        'search_empty' => 'ĞĞºÑ‚Ğ¸Ğ²Ğ½Ñ‹Ğ¹ Ñ€Ğ°Ğ¹Ğ¾Ğ½ Ğ¿Ğ¾ Ğ·Ğ°Ğ¿Ñ€Ğ¾ÑÑƒ Ğ½Ğµ Ğ½Ğ°Ğ¹Ğ´ĞµĞ½.',
        'interest_title' => 'Ğ”Ğ¾Ğ±Ğ°Ğ²ÑŒÑ‚Ğµ Ñ€Ğ°Ğ¹Ğ¾Ğ½ Ğ² Ğ¿Ğ»Ğ°Ğ½ Ñ€Ğ°ÑÑˆĞ¸Ñ€ĞµĞ½Ğ¸Ñ',
        'interest_text' => 'Ğ’Ñ‹Ğ±ĞµÑ€Ğ¸Ñ‚Ğµ Ñ€Ğ°Ğ¹Ğ¾Ğ½ Ğ²Ğ½Ğµ Ñ‚ĞµĞºÑƒÑ‰ĞµĞ³Ğ¾ Ğ¿Ğ¾ĞºÑ€Ñ‹Ñ‚Ğ¸Ñ. Ğ¡Ğ¾Ğ±Ñ€Ğ°Ğ½Ğ½Ñ‹Ğµ Ğ·Ğ°Ğ¿Ñ€Ğ¾ÑÑ‹ ÑƒÑ‡Ğ¸Ñ‚Ñ‹Ğ²Ğ°ÑÑ‚ÑÑ Ğ² ĞµĞ¶ĞµĞ¼ĞµÑÑÑ‡Ğ½Ğ¾Ğ¼ Ğ¿Ğ»Ğ°Ğ½Ğµ Ğ¸ Ğ´Ğ¾Ğ±Ğ°Ğ²Ğ»ÑÑÑ‚ÑÑ Ğ² Ğ¿Ñ€Ğ¸Ğ¾Ñ€Ğ¸Ñ‚ĞµÑ‚ Ğ¿Ñ€Ğ¸ Ğ½Ğ°Ğ»Ğ¸Ñ‡Ğ¸Ğ¸ Ñ‚ĞµÑ…Ğ½Ğ¸Ñ‡ĞµÑĞºĞ¾Ğ¹ Ğ²Ğ¾Ğ·Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ÑÑ‚Ğ¸.',
        'interest_label' => 'Ğ’Ñ‹Ğ±Ğ¾Ñ€ Ñ€Ğ°Ğ¹Ğ¾Ğ½Ğ°',
        'interest_placeholder' => 'Ğ’Ñ‹Ğ±ĞµÑ€Ğ¸Ñ‚Ğµ Ñ€Ğ°Ğ¹Ğ¾Ğ½ ĞĞ·ĞµÑ€Ğ±Ğ°Ğ¹Ğ´Ğ¶Ğ°Ğ½Ğ°',
        'interest_action' => 'Ğ¡Ğ¾Ñ…Ñ€Ğ°Ğ½Ğ¸Ñ‚ÑŒ',
        'stats_title' => 'Ğ¡Ğ°Ğ¼Ñ‹Ğµ Ğ·Ğ°Ğ¿Ñ€Ğ°ÑˆĞ¸Ğ²Ğ°ĞµĞ¼Ñ‹Ğµ Ñ€Ğ°Ğ¹Ğ¾Ğ½Ñ‹ Ğ² ÑÑ‚Ğ¾Ğ¼ Ğ¼ĞµÑÑÑ†Ğµ',
        'stats_total' => 'Ğ’ÑĞµĞ³Ğ¾ Ğ·Ğ°Ğ¿Ñ€Ğ¾ÑĞ¾Ğ²',
        'stats_empty' => 'Ğ’ ÑÑ‚Ğ¾Ğ¼ Ğ¼ĞµÑÑÑ†Ğµ Ğ¿Ğ¾ĞºĞ° Ğ½ĞµÑ‚ ÑĞ¾Ñ…Ñ€Ğ°Ğ½ĞµĞ½Ğ½Ñ‹Ñ… Ğ·Ğ°Ğ¿Ñ€Ğ¾ÑĞ¾Ğ².',
    ],
];
$supportGuides = [
    [
        'title' => ['az' => 'BDCOM modemlÉ™ri', 'en' => 'BDCOM modems', 'ru' => 'ĞœĞ¾Ğ´ĞµĞ¼Ñ‹ BDCOM'],
        'summary' => [
            'az' => 'BDCOM modemlÉ™rindÉ™ Wi-Fi ayarÄ±na daxil olmaq Ã¼Ã§Ã¼n `192.168.123.1` Ã¼nvanÄ±ndan istifadÉ™ edin.',
            'en' => 'Use `192.168.123.1` to access Wi-Fi settings on BDCOM modems.',
            'ru' => 'Ğ”Ğ»Ñ Ğ²Ñ…Ğ¾Ğ´Ğ° Ğ² Ğ½Ğ°ÑÑ‚Ñ€Ğ¾Ğ¹ĞºĞ¸ Wi-Fi Ğ½Ğ° Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ°Ñ… BDCOM Ğ¸ÑĞ¿Ğ¾Ğ»ÑŒĞ·ÑƒĞ¹Ñ‚Ğµ `192.168.123.1`.',
        ],
        'steps' => [
            ['az' => 'BrauzerdÉ™ `192.168.123.1` Ã¼nvanÄ±nÄ± aÃ§Ä±n.', 'en' => 'Open `192.168.123.1` in your browser.', 'ru' => 'ĞÑ‚ĞºÑ€Ğ¾Ğ¹Ñ‚Ğµ `192.168.123.1` Ğ² Ğ±Ñ€Ğ°ÑƒĞ·ĞµÑ€Ğµ.'],
            ['az' => 'Ä°stifadÉ™Ã§i adÄ± olaraq `admin`, ÅŸifrÉ™ olaraq `super&123` daxil edin.', 'en' => 'Enter `admin` as username and `super&123` as password.', 'ru' => 'Ğ’Ğ²ĞµĞ´Ğ¸Ñ‚Ğµ Ğ»Ğ¾Ğ³Ğ¸Ğ½ `admin` Ğ¸ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ `super&123`.'],
            ['az' => 'WLAN vÉ™ ya Wi-Fi bÃ¶lmÉ™sinÉ™ keÃ§in.', 'en' => 'Open the WLAN or Wi-Fi section.', 'ru' => 'ĞŸĞµÑ€ĞµĞ¹Ğ´Ğ¸Ñ‚Ğµ Ğ² Ñ€Ğ°Ğ·Ğ´ĞµĞ» WLAN Ğ¸Ğ»Ğ¸ Wi-Fi.'],
            ['az' => 'SSID vÉ™ ÅŸifrÉ™ hissÉ™sindÉ™ yeni Wi-Fi adÄ±nÄ± vÉ™ ÅŸifrÉ™sini yazÄ±n.', 'en' => 'Update the SSID and password fields with the new Wi-Fi name and password.', 'ru' => 'ĞĞ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚Ğµ Ğ¿Ğ¾Ğ»Ñ SSID Ğ¸ Ğ¿Ğ°Ñ€Ğ¾Ğ»Ñ Ğ½Ğ¾Ğ²Ñ‹Ğ¼ Ğ¸Ğ¼ĞµĞ½ĞµĞ¼ Ğ¸ Ğ¿Ğ°Ñ€Ğ¾Ğ»ĞµĞ¼ Wi-Fi.'],
            ['az' => '`Apply` vÉ™ ya `Save` dÃ¼ymÉ™sini sÄ±xÄ±n vÉ™ cihazlarÄ± yenidÉ™n qoÅŸun.', 'en' => 'Click `Apply` or `Save` and reconnect your devices.', 'ru' => 'ĞĞ°Ğ¶Ğ¼Ğ¸Ñ‚Ğµ `Apply` Ğ¸Ğ»Ğ¸ `Save` Ğ¸ Ğ·Ğ°Ğ½Ğ¾Ğ²Ğ¾ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡Ğ¸Ñ‚Ğµ ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²Ğ°.'],
        ],
    ],
    [
        'title' => ['az' => 'HSGQ modemlÉ™ri', 'en' => 'HSGQ modems', 'ru' => 'ĞœĞ¾Ğ´ĞµĞ¼Ñ‹ HSGQ'],
        'summary' => [
            'az' => 'HSGQ modemlÉ™rindÉ™ giriÅŸ mÉ™lumatlarÄ± sabitdir vÉ™ bir neÃ§É™ addÄ±mda Wi-Fi ÅŸifrÉ™sini dÉ™yiÅŸmÉ™k olur.',
            'en' => 'HSGQ modems use fixed login details and the Wi-Fi password can be changed in a few steps.',
            'ru' => 'Ğ’ Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ°Ñ… HSGQ Ğ¸ÑĞ¿Ğ¾Ğ»ÑŒĞ·ÑƒÑÑ‚ÑÑ Ñ„Ğ¸ĞºÑĞ¸Ñ€Ğ¾Ğ²Ğ°Ğ½Ğ½Ñ‹Ğµ Ğ´Ğ°Ğ½Ğ½Ñ‹Ğµ Ğ²Ñ…Ğ¾Ğ´Ğ°, Ğ° Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ Wi-Fi Ğ¼ĞµĞ½ÑĞµÑ‚ÑÑ Ğ·Ğ° Ğ½ĞµÑĞºĞ¾Ğ»ÑŒĞºĞ¾ ÑˆĞ°Ğ³Ğ¾Ğ².',
        ],
        'steps' => [
            ['az' => 'BrauzerdÉ™ `192.168.1.1` Ã¼nvanÄ±nÄ± aÃ§Ä±n.', 'en' => 'Open `192.168.1.1` in your browser.', 'ru' => 'ĞÑ‚ĞºÑ€Ğ¾Ğ¹Ñ‚Ğµ `192.168.1.1` Ğ² Ğ±Ñ€Ğ°ÑƒĞ·ĞµÑ€Ğµ.'],
            ['az' => 'Ä°stifadÉ™Ã§i adÄ± `telecomadmin`, ÅŸifrÉ™ `admintelecom` olaraq daxil olun.', 'en' => 'Log in with username `telecomadmin` and password `admintelecom`.', 'ru' => 'Ğ’Ğ¾Ğ¹Ğ´Ğ¸Ñ‚Ğµ Ñ Ğ»Ğ¾Ğ³Ğ¸Ğ½Ğ¾Ğ¼ `telecomadmin` Ğ¸ Ğ¿Ğ°Ñ€Ğ¾Ğ»ĞµĞ¼ `admintelecom`.'],
            ['az' => 'Wireless vÉ™ ya WLAN bÃ¶lmÉ™sini aÃ§Ä±n.', 'en' => 'Open the Wireless or WLAN section.', 'ru' => 'ĞÑ‚ĞºÑ€Ğ¾Ğ¹Ñ‚Ğµ Ñ€Ğ°Ğ·Ğ´ĞµĞ» Wireless Ğ¸Ğ»Ğ¸ WLAN.'],
            ['az' => '2.4 GHz vÉ™ 5 GHz Ã¼Ã§Ã¼n ayrÄ±ca SSID vÉ™ WPA aÃ§arÄ±nÄ± yenilÉ™yin.', 'en' => 'Update the SSID and WPA key separately for 2.4 GHz and 5 GHz.', 'ru' => 'ĞĞ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚Ğµ SSID Ğ¸ WPA-ĞºĞ»ÑÑ‡ Ğ¾Ñ‚Ğ´ĞµĞ»ÑŒĞ½Ğ¾ Ğ´Ğ»Ñ 2.4 GHz Ğ¸ 5 GHz.'],
            ['az' => '`Apply` dÃ¼ymÉ™sini sÄ±xdÄ±qdan sonra cihazlarÄ± yeni ÅŸifrÉ™ ilÉ™ yenidÉ™n qoÅŸun.', 'en' => 'After pressing `Apply`, reconnect devices with the new password.', 'ru' => 'ĞŸĞ¾ÑĞ»Ğµ Ğ½Ğ°Ğ¶Ğ°Ñ‚Ğ¸Ñ `Apply` Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡Ğ¸Ñ‚Ğµ ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²Ğ° Ğ·Ğ°Ğ½Ğ¾Ğ²Ğ¾ Ñ Ğ½Ğ¾Ğ²Ñ‹Ğ¼ Ğ¿Ğ°Ñ€Ğ¾Ğ»ĞµĞ¼.'],
        ],
    ],
    [
        'title' => ['az' => 'TP-Link modem vÉ™ routerlÉ™r', 'en' => 'TP-Link modems and routers', 'ru' => 'ĞœĞ¾Ğ´ĞµĞ¼Ñ‹ Ğ¸ Ñ€Ğ¾ÑƒÑ‚ĞµÑ€Ñ‹ TP-Link'],
        'summary' => [
            'az' => 'TP-Link cihazlarÄ±nda qÄ±sa panel yolu ilÉ™ Wi-Fi adÄ± vÉ™ ÅŸifrÉ™si dÉ™yiÅŸdirilir.',
            'en' => 'TP-Link devices let you change the Wi-Fi name and password through a short control path.',
            'ru' => 'ĞĞ° ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²Ğ°Ñ… TP-Link Ğ¸Ğ¼Ñ Ğ¸ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ Wi-Fi Ğ¼ĞµĞ½ÑÑÑ‚ÑÑ Ñ‡ĞµÑ€ĞµĞ· ĞºĞ¾Ñ€Ğ¾Ñ‚ĞºĞ¸Ğ¹ Ğ¿ÑƒÑ‚ÑŒ Ğ² Ğ¿Ğ°Ğ½ĞµĞ»Ğ¸ ÑƒĞ¿Ñ€Ğ°Ğ²Ğ»ĞµĞ½Ğ¸Ñ.',
        ],
        'steps' => [
            ['az' => 'BrauzerdÉ™ `192.168.1.1` Ã¼nvanÄ±nÄ± aÃ§Ä±n.', 'en' => 'Open `192.168.1.1` in your browser.', 'ru' => 'ĞÑ‚ĞºÑ€Ğ¾Ğ¹Ñ‚Ğµ `192.168.1.1` Ğ² Ğ±Ñ€Ğ°ÑƒĞ·ĞµÑ€Ğµ.'],
            ['az' => 'Ä°stifadÉ™Ã§i adÄ± `admin`, ÅŸifrÉ™ `admin` olaraq daxil olun.', 'en' => 'Log in with `admin / admin`.', 'ru' => 'Ğ’Ğ¾Ğ¹Ğ´Ğ¸Ñ‚Ğµ Ñ Ğ´Ğ°Ğ½Ğ½Ñ‹Ğ¼Ğ¸ `admin / admin`.'],
            ['az' => 'Wireless Settings vÉ™ ya Basic Wireless bÃ¶lmÉ™sinÉ™ keÃ§in.', 'en' => 'Go to Wireless Settings or Basic Wireless.', 'ru' => 'ĞŸĞµÑ€ĞµĞ¹Ğ´Ğ¸Ñ‚Ğµ Ğ² Wireless Settings Ğ¸Ğ»Ğ¸ Basic Wireless.'],
            ['az' => 'Wi-Fi adÄ± vÉ™ ÅŸifrÉ™sini yenilÉ™yin.', 'en' => 'Update the Wi-Fi name and password.', 'ru' => 'ĞĞ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚Ğµ Ğ¸Ğ¼Ñ Ğ¸ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ Wi-Fi.'],
            ['az' => '`Save` dÃ¼ymÉ™sini sÄ±xÄ±n vÉ™ cihazlarÄ± yenidÉ™n qoÅŸun.', 'en' => 'Click `Save` and reconnect your devices.', 'ru' => 'ĞĞ°Ğ¶Ğ¼Ğ¸Ñ‚Ğµ `Save` Ğ¸ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡Ğ¸Ñ‚Ğµ ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²Ğ° Ğ·Ğ°Ğ½Ğ¾Ğ²Ğ¾.'],
        ],
    ],
    [
        'title' => ['az' => 'Huawei modemlÉ™ri', 'en' => 'Huawei modems', 'ru' => 'ĞœĞ¾Ğ´ĞµĞ¼Ñ‹ Huawei'],
        'summary' => [
            'az' => 'Huawei modemlÉ™rindÉ™ hÉ™m 2.4 GHz, hÉ™m dÉ™ 5 GHz Ã¼Ã§Ã¼n ayrÄ±ca bÃ¶lmÉ™dÉ™n Wi-Fi adÄ± vÉ™ ÅŸifrÉ™si dÉ™yiÅŸdirilir.',
            'en' => 'On Huawei modems, the Wi-Fi name and password are updated from separate 2.4 GHz and 5 GHz sections.',
            'ru' => 'ĞĞ° Ğ¼Ğ¾Ğ´ĞµĞ¼Ğ°Ñ… Huawei Ğ¸Ğ¼Ñ Ğ¸ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ Wi-Fi Ğ¼ĞµĞ½ÑÑÑ‚ÑÑ Ğ¸Ğ· Ğ¾Ñ‚Ğ´ĞµĞ»ÑŒĞ½Ñ‹Ñ… Ñ€Ğ°Ğ·Ğ´ĞµĞ»Ğ¾Ğ² Ğ´Ğ»Ñ 2.4 GHz Ğ¸ 5 GHz.',
        ],
        'steps' => [
            ['az' => 'Wi-Fi-ya qoÅŸulu cihazla brauzeri aÃ§Ä±b `192.168.100.1` Ã¼nvanÄ±na daxil olun.', 'en' => 'Open a browser on a device connected to Wi-Fi and go to `192.168.100.1`.', 'ru' => 'ĞĞ° ÑƒÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²Ğµ, Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ½Ğ¾Ğ¼ Ğº Wi-Fi, Ğ¾Ñ‚ĞºÑ€Ğ¾Ğ¹Ñ‚Ğµ Ğ±Ñ€Ğ°ÑƒĞ·ĞµÑ€ Ğ¸ Ğ¿ĞµÑ€ĞµĞ¹Ğ´Ğ¸Ñ‚Ğµ Ğ½Ğ° `192.168.100.1`.'],
            ['az' => 'Ä°stifadÉ™Ã§i adÄ± `telecomadmin`, ÅŸifrÉ™ `admintelecom` yazÄ±n.', 'en' => 'Use `telecomadmin` as username and `admintelecom` as password.', 'ru' => 'Ğ’Ğ²ĞµĞ´Ğ¸Ñ‚Ğµ Ğ»Ğ¾Ğ³Ğ¸Ğ½ `telecomadmin` Ğ¸ Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ `admintelecom`.'],
            ['az' => 'Sol menyudan `Advanced`, sonra `WLAN`, daha sonra `2.4G Basic Network` bÃ¶lmÉ™sinÉ™ keÃ§in.', 'en' => 'Open `Advanced`, then `WLAN`, and then `2.4G Basic Network` from the left menu.', 'ru' => 'ĞÑ‚ĞºÑ€Ğ¾Ğ¹Ñ‚Ğµ `Advanced`, Ğ·Ğ°Ñ‚ĞµĞ¼ `WLAN`, Ğ° Ğ¿Ğ¾ÑĞ»Ğµ ÑÑ‚Ğ¾Ğ³Ğ¾ `2.4G Basic Network` Ğ² Ğ»ĞµĞ²Ğ¾Ğ¼ Ğ¼ĞµĞ½Ñ.'],
            ['az' => '`SSID Name` sÉ™tirindÉ™ Wi-Fi adÄ±nÄ±, `WPA PreShareKey` sÉ™tirindÉ™ ÅŸifrÉ™ni dÉ™yiÅŸin vÉ™ `Apply` dÃ¼ymÉ™sini sÄ±xÄ±n.', 'en' => 'Change the Wi-Fi name in `SSID Name`, update the password in `WPA PreShareKey`, and click `Apply`.', 'ru' => 'Ğ˜Ğ·Ğ¼ĞµĞ½Ğ¸Ñ‚Ğµ Ğ¸Ğ¼Ñ Wi-Fi Ğ² `SSID Name`, Ğ¿Ğ°Ñ€Ğ¾Ğ»ÑŒ Ğ² `WPA PreShareKey` Ğ¸ Ğ½Ğ°Ğ¶Ğ¼Ğ¸Ñ‚Ğµ `Apply`.'],
            ['az' => 'Cihaz ayrÄ±ldÄ±qdan sonra yenidÉ™n qoÅŸulun vÉ™ eyni addÄ±mla `5G Basic Network` bÃ¶lmÉ™sindÉ™ 5 GHz ayarÄ±nÄ± da yenilÉ™yin.', 'en' => 'After the device reconnects, repeat the same steps in `5G Basic Network` for the 5 GHz settings.', 'ru' => 'ĞŸĞ¾ÑĞ»Ğµ Ğ¿Ğ¾Ğ²Ñ‚Ğ¾Ñ€Ğ½Ğ¾Ğ³Ğ¾ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ Ğ²Ñ‹Ğ¿Ğ¾Ğ»Ğ½Ğ¸Ñ‚Ğµ Ñ‚Ğµ Ğ¶Ğµ ÑˆĞ°Ğ³Ğ¸ Ğ² Ñ€Ğ°Ğ·Ğ´ĞµĞ»Ğµ `5G Basic Network` Ğ´Ğ»Ñ Ğ½Ğ°ÑÑ‚Ñ€Ğ¾ĞµĞº 5 GHz.'],
        ],
    ],
];
$tvSection = [
    'az' => [
        'eyebrow' => 'TVNET kanallarÄ±',
        'title' => 'TVNET vasitÉ™silÉ™ tÉ™qdim olunan TV kanallarÄ±',
        'subtitle' => 'Kanal siyahÄ±sÄ± TVNET-in aÃ§Ä±q kanal sÉ™hifÉ™sinÉ™ É™sasÉ™n yÄ±ÄŸÄ±lÄ±r. Lent avtomatik hÉ™rÉ™kÉ™t edir, siz isÉ™ istÉ™diyiniz kanalÄ± axtarÄ±b gÃ¶rÉ™ bilÉ™rsiniz.',
        'search_label' => 'Kanal axtarÄ±ÅŸÄ±',
        'search_placeholder' => 'MÉ™sÉ™lÉ™n: CBC Sport, AzTV, Setanta...',
        'package_label' => 'Paket',
        'source_note' => 'Kanallar TVNET vasitÉ™silÉ™ tÉ™qdim olunur.',
        'empty' => 'AxtarÄ±ÅŸa uyÄŸun kanal tapÄ±lmadÄ±.',
    ],
    'en' => [
        'eyebrow' => 'TVNET channels',
        'title' => 'TV channels delivered through TVNET',
        'subtitle' => 'The list is seeded from TVNETâ€™s public channel page. The carousel moves automatically and you can search any channel by name.',
        'search_label' => 'Channel search',
        'search_placeholder' => 'For example: CBC Sport, AzTV, Setanta...',
        'package_label' => 'Package',
        'source_note' => 'Channels are provided via TVNET.',
        'empty' => 'No channels matched your search.',
    ],
    'ru' => [
        'eyebrow' => 'ĞšĞ°Ğ½Ğ°Ğ»Ñ‹ TVNET',
        'title' => 'Ğ¢Ğ’-ĞºĞ°Ğ½Ğ°Ğ»Ñ‹, Ğ¿Ñ€ĞµĞ´Ğ¾ÑÑ‚Ğ°Ğ²Ğ»ÑĞµĞ¼Ñ‹Ğµ Ñ‡ĞµÑ€ĞµĞ· TVNET',
        'subtitle' => 'Ğ¡Ğ¿Ğ¸ÑĞ¾Ğº Ğ¾ÑĞ½Ğ¾Ğ²Ğ°Ğ½ Ğ½Ğ° Ğ¾Ñ‚ĞºÑ€Ñ‹Ñ‚Ğ¾Ğ¹ ÑÑ‚Ñ€Ğ°Ğ½Ğ¸Ñ†Ğµ ĞºĞ°Ğ½Ğ°Ğ»Ğ¾Ğ² TVNET. Ğ›ĞµĞ½Ñ‚Ğ° Ğ´Ğ²Ğ¸Ğ¶ĞµÑ‚ÑÑ Ğ°Ğ²Ñ‚Ğ¾Ğ¼Ğ°Ñ‚Ğ¸Ñ‡ĞµÑĞºĞ¸, Ğ° Ğ½ÑƒĞ¶Ğ½Ñ‹Ğ¹ ĞºĞ°Ğ½Ğ°Ğ» Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ Ğ½Ğ°Ğ¹Ñ‚Ğ¸ Ñ‡ĞµÑ€ĞµĞ· Ğ¿Ğ¾Ğ¸ÑĞº.',
        'search_label' => 'ĞŸĞ¾Ğ¸ÑĞº ĞºĞ°Ğ½Ğ°Ğ»Ğ°',
        'search_placeholder' => 'ĞĞ°Ğ¿Ñ€Ğ¸Ğ¼ĞµÑ€: CBC Sport, AzTV, Setanta...',
        'package_label' => 'ĞŸĞ°ĞºĞµÑ‚',
        'source_note' => 'ĞšĞ°Ğ½Ğ°Ğ»Ñ‹ Ğ¿Ñ€ĞµĞ´Ğ¾ÑÑ‚Ğ°Ğ²Ğ»ÑÑÑ‚ÑÑ Ñ‡ĞµÑ€ĞµĞ· TVNET.',
        'empty' => 'ĞŸĞ¾ Ğ²Ğ°ÑˆĞµĞ¼Ñƒ Ğ·Ğ°Ğ¿Ñ€Ğ¾ÑÑƒ ĞºĞ°Ğ½Ğ°Ğ»Ñ‹ Ğ½Ğµ Ğ½Ğ°Ğ¹Ğ´ĞµĞ½Ñ‹.',
    ],
];
$sectionLabels = [
    'tv' => ['az' => 'TV', 'en' => 'TV', 'ru' => 'Ğ¢Ğ’'],
    'payments' => ['az' => 'Ã–dÉ™niÅŸ', 'en' => 'Pay', 'ru' => 'ĞĞ¿Ğ»Ğ°Ñ‚Ğ°'],
];
$paymentSection = [
    'az' => [
        'eyebrow' => 'Onlayn Ã¶dÉ™niÅŸlÉ™r',
        'title' => 'MÃ¼ÅŸtÉ™rilÉ™rimiz bu Ã¶dÉ™niÅŸ sistemlÉ™rindÉ™n rahat istifadÉ™ edÉ™ bilir',
        'subtitle' => 'Onlayn Ã¶dÉ™niÅŸ, tÉ™tbiq vÉ™ terminal tÉ™rÉ™fdaÅŸlarÄ±mÄ±zÄ± bir yerdÉ™ gÃ¶stÉ™ririk ki, abonent Ã¶dÉ™niÅŸi daha rahat seÃ§sin.',
    ],
    'en' => [
        'eyebrow' => 'Online payments',
        'title' => 'Our customers can pay comfortably through these systems',
        'subtitle' => 'We show our online, app and terminal payment partners together so subscribers can choose the easiest payment path.',
    ],
    'ru' => [
        'eyebrow' => 'ĞĞ½Ğ»Ğ°Ğ¹Ğ½-Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ñ‹',
        'title' => 'ĞĞ°ÑˆĞ¸ ĞºĞ»Ğ¸ĞµĞ½Ñ‚Ñ‹ Ğ¼Ğ¾Ğ³ÑƒÑ‚ ÑƒĞ´Ğ¾Ğ±Ğ½Ğ¾ Ğ¾Ğ¿Ğ»Ğ°Ñ‡Ğ¸Ğ²Ğ°Ñ‚ÑŒ Ñ‡ĞµÑ€ĞµĞ· ÑÑ‚Ğ¸ ÑĞ¸ÑÑ‚ĞµĞ¼Ñ‹',
        'subtitle' => 'ĞœÑ‹ Ğ¿Ğ¾ĞºĞ°Ğ·Ñ‹Ğ²Ğ°ĞµĞ¼ Ğ¾Ğ½Ğ»Ğ°Ğ¹Ğ½, Ğ¼Ğ¾Ğ±Ğ¸Ğ»ÑŒĞ½Ñ‹Ñ… Ğ¸ Ñ‚ĞµÑ€Ğ¼Ğ¸Ğ½Ğ°Ğ»ÑŒĞ½Ñ‹Ñ… Ğ¿Ğ°Ñ€Ñ‚Ğ½ĞµÑ€Ğ¾Ğ² Ğ² Ğ¾Ğ´Ğ½Ğ¾Ğ¼ Ğ¼ĞµÑÑ‚Ğµ, Ñ‡Ñ‚Ğ¾Ğ±Ñ‹ Ğ°Ğ±Ğ¾Ğ½ĞµĞ½Ñ‚Ñƒ Ğ±Ñ‹Ğ»Ğ¾ Ğ¿Ñ€Ğ¾Ñ‰Ğµ Ğ²Ñ‹Ğ±Ñ€Ğ°Ñ‚ÑŒ Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ñƒ.',
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
    'az' => ['cta' => 'Sayta keÃ§', 'note' => 'RÉ™smi Ã¶dÉ™niÅŸ keÃ§idi'],
    'en' => ['cta' => 'Open site', 'note' => 'Official payment link'],
    'ru' => ['cta' => 'ĞŸĞµÑ€ĞµĞ¹Ñ‚Ğ¸ Ğ½Ğ° ÑĞ°Ğ¹Ñ‚', 'note' => 'ĞÑ„Ğ¸Ñ†Ğ¸Ğ°Ğ»ÑŒĞ½Ğ°Ñ ÑÑÑ‹Ğ»ĞºĞ° Ğ½Ğ° Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ñƒ'],
];
$salesHero = [
    'az' => [
        'eyebrow' => 'Fiber internet, TV vÉ™ IT dÉ™stÉ™k',
        'title' => '100 Mbit fiber internet 25 AZN-dÉ™n',
        'text' => 'Ev Ã¼Ã§Ã¼n 25 AZN, biznes Ã¼Ã§Ã¼n 30 AZN. ÆhatÉ™ni yoxlayÄ±n, telefon nÃ¶mrÉ™sini gÃ¶ndÉ™rin, qoÅŸulma vaxtÄ±nÄ± geri bildirÉ™k.',
        'actions' => ['ÆhatÉ™ni yoxla', 'Tarif seÃ§'],
        'proofs' => [
            ['value' => '1 gÃ¼n', 'label' => 'qoÅŸulma planÄ±'],
            ['value' => '24/7', 'label' => 'texniki dÉ™stÉ™k'],
            ['value' => 'TV +6', 'label' => 'fÉ™rdi É™lavÉ™'],
        ],
        'quick_title' => '1 klik qoÅŸulma yoxlamasÄ±',
        'quick_text' => 'Ærazi vÉ™ telefonu gÃ¶ndÉ™rin. Komanda sizÉ™ texniki imkan vÉ™ qoÅŸulma vaxtÄ±nÄ± geri bildirsin.',
        'default_message' => 'Yeni qoÅŸulma vÉ™ ya xidmÉ™t sorÄŸusu',
        'form_hint' => 'QÄ±sa forma: É™vvÉ™lcÉ™ É™razi vÉ™ telefon, sonra komanda sizi yÃ¶nlÉ™ndirsin.',
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
        'eyebrow' => 'ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ñ‹Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ğ¢Ğ’ Ğ¸ IT-Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ°',
        'title' => '100 ĞœĞ±Ğ¸Ñ‚ fiber-Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚Ğ° Ğ¾Ñ‚ 25 AZN',
        'text' => '25 AZN Ğ´Ğ»Ñ Ğ´Ğ¾Ğ¼Ğ° Ğ¸ 30 AZN Ğ´Ğ»Ñ Ğ±Ğ¸Ğ·Ğ½ĞµÑĞ°. ĞŸÑ€Ğ¾Ğ²ĞµÑ€ÑŒÑ‚Ğµ Ğ¿Ğ¾ĞºÑ€Ñ‹Ñ‚Ğ¸Ğµ, Ğ¾Ñ‚Ğ¿Ñ€Ğ°Ğ²ÑŒÑ‚Ğµ Ğ½Ğ¾Ğ¼ĞµÑ€ Ğ¸ Ğ¿Ğ¾Ğ»ÑƒÑ‡Ğ¸Ñ‚Ğµ ÑÑ€Ğ¾Ğº Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ Ğ¾Ñ‚ Ğ½Ğ°ÑˆĞµĞ¹ ĞºĞ¾Ğ¼Ğ°Ğ½Ğ´Ñ‹.',
        'actions' => ['ĞŸÑ€Ğ¾Ğ²ĞµÑ€Ğ¸Ñ‚ÑŒ Ğ¿Ğ¾ĞºÑ€Ñ‹Ñ‚Ğ¸Ğµ', 'Ğ’Ñ‹Ğ±Ñ€Ğ°Ñ‚ÑŒ Ñ‚Ğ°Ñ€Ğ¸Ñ„'],
        'proofs' => [
            ['value' => '1 Ğ´ĞµĞ½ÑŒ', 'label' => 'Ğ¿Ğ»Ğ°Ğ½ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ'],
            ['value' => '24/7', 'label' => 'Ñ‚ĞµÑ…Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ°'],
            ['value' => 'TV +6', 'label' => 'Ğ´Ğ¾Ğ¼Ğ°ÑˆĞ½ÑÑ Ğ´Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ğ°'],
        ],
        'quick_title' => 'ĞŸÑ€Ğ¾Ğ²ĞµÑ€ĞºĞ° Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ Ğ² 1 ĞºĞ»Ğ¸Ğº',
        'quick_text' => 'ĞÑ‚Ğ¿Ñ€Ğ°Ğ²ÑŒÑ‚Ğµ Ñ€Ğ°Ğ¹Ğ¾Ğ½ Ğ¸ Ğ½Ğ¾Ğ¼ĞµÑ€ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ°. ĞšĞ¾Ğ¼Ğ°Ğ½Ğ´Ğ° Ğ¾Ñ‚Ğ²ĞµÑ‚Ğ¸Ñ‚ Ğ¿Ğ¾ Ñ‚ĞµÑ…Ğ½Ğ¸Ñ‡ĞµÑĞºĞ¾Ğ¹ Ğ²Ğ¾Ğ·Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ÑÑ‚Ğ¸ Ğ¸ ÑÑ€Ğ¾ĞºÑƒ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ.',
        'default_message' => 'ĞĞ¾Ğ²Ğ°Ñ Ğ·Ğ°ÑĞ²ĞºĞ° Ğ½Ğ° Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ğµ Ğ¸Ğ»Ğ¸ ÑƒÑĞ»ÑƒĞ³Ñƒ',
        'form_hint' => 'Ğ¡Ğ½Ğ°Ñ‡Ğ°Ğ»Ğ° ĞºĞ¾Ñ€Ğ¾Ñ‚ĞºĞ°Ñ Ñ„Ğ¾Ñ€Ğ¼Ğ°: Ñ€Ğ°Ğ¹Ğ¾Ğ½ Ğ¸ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½, Ğ·Ğ°Ñ‚ĞµĞ¼ ĞºĞ¾Ğ¼Ğ°Ğ½Ğ´Ğ° Ğ¿Ñ€Ğ¾Ğ²ĞµĞ´ĞµÑ‚ Ğ´Ğ°Ğ»ÑŒÑˆĞµ.',
    ],
];
$featuredOfferSection = [
    'az' => [
        'eyebrow' => 'Æsas seÃ§imlÉ™r',
        'title' => 'QÉ™rarÄ± uzatmayan 3 É™sas paket',
        'text' => 'ÆvvÉ™lcÉ™ É™n Ã§ox satÄ±lan paketlÉ™ri gÃ¶stÉ™ririk. Tam tarif cÉ™dvÉ™li aÅŸaÄŸÄ±da qalÄ±r.',
        'cta' => 'Tam tarif cÉ™dvÉ™linÉ™ keÃ§',
        'steps_title' => 'QoÅŸulma axÄ±nÄ±',
        'steps' => [
            ['title' => '1. Paket seÃ§', 'text' => 'Ev, ailÉ™ vÉ™ ya ofis Ã¼Ã§Ã¼n uyÄŸun É™sas paketi seÃ§in.'],
            ['title' => '2. Ærazini gÃ¶ndÉ™r', 'text' => 'ÆhatÉ™ yoxlanÄ±ÅŸÄ± Ã¼Ã§Ã¼n rayon vÉ™ telefon nÃ¶mrÉ™sini buraxÄ±n.'],
            ['title' => '3. TÉ™sdiq al', 'text' => 'Komanda texniki imkan vÉ™ qoÅŸulma vaxtÄ±nÄ± dÉ™qiqlÉ™ÅŸdirsin.'],
            ['title' => '4. QoÅŸul', 'text' => 'RazÄ±laÅŸmadan sonra quraÅŸdÄ±rma vÉ™ aktivlÉ™ÅŸmÉ™ tamamlanÄ±r.'],
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
        'eyebrow' => 'ĞÑĞ½Ğ¾Ğ²Ğ½Ñ‹Ğµ Ğ¿Ñ€ĞµĞ´Ğ»Ğ¾Ğ¶ĞµĞ½Ğ¸Ñ',
        'title' => '3 Ğ¾ÑĞ½Ğ¾Ğ²Ğ½Ñ‹Ñ… Ğ¿Ğ°ĞºĞµÑ‚Ğ° Ğ±ĞµĞ· Ğ»Ğ¸ÑˆĞ½ĞµĞ¹ ÑĞ»Ğ¾Ğ¶Ğ½Ğ¾ÑÑ‚Ğ¸',
        'text' => 'Ğ¡Ğ½Ğ°Ñ‡Ğ°Ğ»Ğ° Ğ¿Ğ¾ĞºĞ°Ğ·Ñ‹Ğ²Ğ°ĞµĞ¼ ÑĞ°Ğ¼Ñ‹Ğµ Ğ¿Ğ¾Ğ½ÑÑ‚Ğ½Ñ‹Ğµ Ğ¸ Ğ¿Ñ€Ğ¾Ğ´Ğ°Ğ²Ğ°ĞµĞ¼Ñ‹Ğµ Ğ¿Ğ°ĞºĞµÑ‚Ñ‹. ĞŸĞ¾Ğ»Ğ½Ğ°Ñ Ñ‚Ğ°Ğ±Ğ»Ğ¸Ñ†Ğ° Ñ‚Ğ°Ñ€Ğ¸Ñ„Ğ¾Ğ² Ğ¾ÑÑ‚Ğ°ĞµÑ‚ÑÑ Ğ½Ğ¸Ğ¶Ğµ.',
        'cta' => 'ĞŸĞµÑ€ĞµĞ¹Ñ‚Ğ¸ Ğº Ğ¿Ğ¾Ğ»Ğ½Ğ¾Ğ¹ Ñ‚Ğ°Ğ±Ğ»Ğ¸Ñ†Ğµ Ñ‚Ğ°Ñ€Ğ¸Ñ„Ğ¾Ğ²',
        'steps_title' => 'Ğ¡Ñ†ĞµĞ½Ğ°Ñ€Ğ¸Ğ¹ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ',
        'steps' => [
            ['title' => '1. Ğ’Ñ‹Ğ±ĞµÑ€Ğ¸Ñ‚Ğµ Ğ¿Ğ°ĞºĞµÑ‚', 'text' => 'ĞŸĞ¾Ğ´Ğ±ĞµÑ€Ğ¸Ñ‚Ğµ Ğ±Ğ°Ğ·Ğ¾Ğ²Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚ Ğ´Ğ»Ñ Ğ´Ğ¾Ğ¼Ğ°, ÑĞµĞ¼ÑŒĞ¸ Ğ¸Ğ»Ğ¸ Ğ¾Ñ„Ğ¸ÑĞ°.'],
            ['title' => '2. ĞÑ‚Ğ¿Ñ€Ğ°Ğ²ÑŒÑ‚Ğµ Ñ€Ğ°Ğ¹Ğ¾Ğ½', 'text' => 'ĞÑÑ‚Ğ°Ğ²ÑŒÑ‚Ğµ Ñ€Ğ°Ğ¹Ğ¾Ğ½ Ğ¸ Ğ½Ğ¾Ğ¼ĞµÑ€ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ° Ğ´Ğ»Ñ Ğ¿Ñ€Ğ¾Ğ²ĞµÑ€ĞºĞ¸ Ğ¿Ğ¾ĞºÑ€Ñ‹Ñ‚Ğ¸Ñ.'],
            ['title' => '3. ĞŸĞ¾Ğ»ÑƒÑ‡Ğ¸Ñ‚Ğµ Ğ¿Ğ¾Ğ´Ñ‚Ğ²ĞµÑ€Ğ¶Ğ´ĞµĞ½Ğ¸Ğµ', 'text' => 'ĞšĞ¾Ğ¼Ğ°Ğ½Ğ´Ğ° Ğ¿Ğ¾Ğ´Ñ‚Ğ²ĞµÑ€Ğ´Ğ¸Ñ‚ Ñ‚ĞµÑ…Ğ²Ğ¾Ğ·Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ÑÑ‚ÑŒ Ğ¸ ÑÑ€Ğ¾Ğº Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ.'],
            ['title' => '4. ĞŸĞ¾Ğ´ĞºĞ»ÑÑ‡Ğ¸Ñ‚ĞµÑÑŒ', 'text' => 'ĞŸĞ¾ÑĞ»Ğµ ÑĞ¾Ğ³Ğ»Ğ°ÑĞ¾Ğ²Ğ°Ğ½Ğ¸Ñ Ğ²Ñ‹Ğ¿Ğ¾Ğ»Ğ½ÑÑÑ‚ÑÑ ÑƒÑÑ‚Ğ°Ğ½Ğ¾Ğ²ĞºĞ° Ğ¸ Ğ°ĞºÑ‚Ğ¸Ğ²Ğ°Ñ†Ğ¸Ñ.'],
        ],
    ],
];
$featuredOffers = [
    [
        'name' => ['az' => 'FÉ™rdi internet', 'en' => 'Home internet', 'ru' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚'],
        'speed' => 100,
        'price' => 25,
        'tag' => ['az' => 'BaÅŸlanÄŸÄ±c', 'en' => 'Starter', 'ru' => 'Ğ¡Ñ‚Ğ°Ñ€Ñ‚'],
        'description' => [
            'az' => 'GÃ¼ndÉ™lik istifadÉ™, video vÉ™ ev Wi-Fi Ã¼Ã§Ã¼n baza paket.',
            'en' => 'Base package for daily usage, video and home Wi-Fi.',
            'ru' => 'Ğ‘Ğ°Ğ·Ğ¾Ğ²Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚ Ğ´Ğ»Ñ ĞµĞ¶ĞµĞ´Ğ½ĞµĞ²Ğ½Ğ¾Ğ³Ğ¾ Ğ¸ÑĞ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ğ½Ğ¸Ñ, Ğ²Ğ¸Ğ´ĞµĞ¾ Ğ¸ Ğ´Ğ¾Ğ¼Ğ°ÑˆĞ½ĞµĞ³Ğ¾ Wiâ€‘Fi.',
        ],
    ],
    [
        'name' => ['az' => 'FÉ™rdi tam paket', 'en' => 'Home full bundle', 'ru' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¿Ğ¾Ğ»Ğ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚'],
        'speed' => 150,
        'price' => 40,
        'tag' => ['az' => 'AilÉ™ Ã¼Ã§Ã¼n', 'en' => 'Family fit', 'ru' => 'Ğ”Ğ»Ñ ÑĞµĞ¼ÑŒĞ¸'],
        'description' => [
            'az' => 'Ä°nternet, TV vÉ™ telefonu bir yerdÉ™ istÉ™yÉ™n ailÉ™lÉ™r Ã¼Ã§Ã¼n.',
            'en' => 'For families that want internet, TV and phone in one bundle.',
            'ru' => 'Ğ”Ğ»Ñ ÑĞµĞ¼ĞµĞ¹, ĞºĞ¾Ñ‚Ğ¾Ñ€Ñ‹Ğ¼ Ğ½ÑƒĞ¶ĞµĞ½ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚, Ğ¢Ğ’ Ğ¸ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½ Ğ² Ğ¾Ğ´Ğ½Ğ¾Ğ¼ Ğ¿Ğ°ĞºĞµÑ‚Ğµ.',
        ],
    ],
    [
        'name' => ['az' => 'Biznes tam paket', 'en' => 'Business full bundle', 'ru' => 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ğ¿Ğ¾Ğ»Ğ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚'],
        'speed' => 100,
        'price' => 50,
        'tag' => ['az' => 'Ofis Ã¼Ã§Ã¼n', 'en' => 'Office ready', 'ru' => 'Ğ”Ğ»Ñ Ğ¾Ñ„Ğ¸ÑĞ°'],
        'description' => [
            'az' => 'GÃ¶zlÉ™mÉ™ zonasÄ± TV-si vÉ™ sabit telefon xÉ™tti olan ofis paketi.',
            'en' => 'Office package with waiting-area TV and fixed phone line.',
            'ru' => 'ĞÑ„Ğ¸ÑĞ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚ Ñ Ğ¢Ğ’ Ğ´Ğ»Ñ Ğ·Ğ¾Ğ½Ñ‹ Ğ¾Ğ¶Ğ¸Ğ´Ğ°Ğ½Ğ¸Ñ Ğ¸ ÑÑ‚Ğ°Ñ†Ğ¸Ğ¾Ğ½Ğ°Ñ€Ğ½Ğ¾Ğ¹ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ¸ĞµĞ¹.',
        ],
    ],
];
$tvChannelRows = [[], [], []];
foreach ($tvChannels as $index => $channel) {
    $tvChannelRows[$index % 3][] = $channel;
}
$copyrightLine = [
    'az' => 'MÃœÆLLÄ°FLÄ°K HÃœQUQU 2026 Â©SUPEROPTIKTECH BÃœTÃœN HÃœQUQLAR QORUNUR',
    'en' => 'COPYRIGHT 2026 Â©SUPEROPTIKTECH ALL RIGHTS RESERVED',
    'ru' => 'ĞĞ’Ğ¢ĞĞ Ğ¡ĞšĞĞ• ĞŸĞ ĞĞ’Ğ 2026 Â©SUPEROPTIKTECH Ğ’Ğ¡Ğ• ĞŸĞ ĞĞ’Ğ Ğ—ĞĞ©Ğ˜Ğ©Ğ•ĞĞ«',
];
$footerLabels = [
    'az' => [
        'about' => 'HaqqÄ±mÄ±zda',
        'media' => 'Media Ã¼Ã§Ã¼n',
        'legal' => 'HÃ¼quqi',
        'mission' => 'Missiya vÉ™ mÉ™qsÉ™dlÉ™r',
        'quality' => 'KeyfiyyÉ™t gÃ¶stÉ™ricilÉ™ri',
        'privacy' => 'Gizlilik siyasÉ™ti',
        'rules' => 'Qaydalar vÉ™ ÅŸÉ™rtlÉ™r',
        'devices' => 'Cihazlar',
        'speedtest' => 'SÃ¼rÉ™tin yoxlanÄ±lmasÄ±',
        'contact' => 'BizimlÉ™ É™laqÉ™',
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
        'about' => 'Ğ ĞºĞ¾Ğ¼Ğ¿Ğ°Ğ½Ğ¸Ğ¸',
        'media' => 'Ğ”Ğ»Ñ Ğ¼ĞµĞ´Ğ¸Ğ°',
        'legal' => 'Ğ®Ñ€Ğ¸Ğ´Ğ¸Ñ‡ĞµÑĞºĞ¾Ğµ',
        'mission' => 'ĞœĞ¸ÑÑĞ¸Ñ Ğ¸ Ñ†ĞµĞ»Ğ¸',
        'quality' => 'ĞŸĞ¾ĞºĞ°Ğ·Ğ°Ñ‚ĞµĞ»Ğ¸ ĞºĞ°Ñ‡ĞµÑÑ‚Ğ²Ğ°',
        'privacy' => 'ĞŸĞ¾Ğ»Ğ¸Ñ‚Ğ¸ĞºĞ° ĞºĞ¾Ğ½Ñ„Ğ¸Ğ´ĞµĞ½Ñ†Ğ¸Ğ°Ğ»ÑŒĞ½Ğ¾ÑÑ‚Ğ¸',
        'rules' => 'ĞŸÑ€Ğ°Ğ²Ğ¸Ğ»Ğ° Ğ¸ ÑƒÑĞ»Ğ¾Ğ²Ğ¸Ñ',
        'devices' => 'Ğ£ÑÑ‚Ñ€Ğ¾Ğ¹ÑÑ‚Ğ²Ğ°',
        'speedtest' => 'ĞŸÑ€Ğ¾Ğ²ĞµÑ€ĞºĞ° ÑĞºĞ¾Ñ€Ğ¾ÑÑ‚Ğ¸',
        'contact' => 'Ğ¡Ğ²ÑĞ·Ğ°Ñ‚ÑŒÑÑ Ñ Ğ½Ğ°Ğ¼Ğ¸',
    ],
];
$tariffNavLabels = [
    'prev' => [
        'az' => 'ÆvvÉ™lki tariflÉ™r',
        'en' => 'Previous tariffs',
        'ru' => 'ĞŸÑ€ĞµĞ´Ñ‹Ğ´ÑƒÑ‰Ğ¸Ğµ Ñ‚Ğ°Ñ€Ğ¸Ñ„Ñ‹',
    ],
    'next' => [
        'az' => 'NÃ¶vbÉ™ti tariflÉ™r',
        'en' => 'Next tariffs',
        'ru' => 'Ğ¡Ğ»ĞµĞ´ÑƒÑÑ‰Ğ¸Ğµ Ñ‚Ğ°Ñ€Ğ¸Ñ„Ñ‹',
    ],
];
$mapPoints = [
    ['id' => 'dot-astara', 'top' => '92.8%', 'left' => '68.0%', 'label' => ['az' => 'Astara', 'en' => 'Astara', 'ru' => 'ĞÑÑ‚Ğ°Ñ€Ğ°']],
    ['id' => 'dot-lankaran', 'top' => '86.7%', 'left' => '68.8%', 'label' => ['az' => 'LÉ™nkÉ™ran', 'en' => 'Lankaran', 'ru' => 'Ğ›ĞµĞ½ĞºĞ¾Ñ€Ğ°Ğ½ÑŒ']],
    ['id' => 'dot-masalli', 'top' => '82.2%', 'left' => '67.2%', 'label' => ['az' => 'MasallÄ±', 'en' => 'Masalli', 'ru' => 'ĞœĞ°ÑĞ°Ğ»Ğ»Ñ‹']],
    ['id' => 'dot-neftcala', 'top' => '70.5%', 'left' => '75.2%', 'label' => ['az' => 'NeftÃ§ala', 'en' => 'Neftchala', 'ru' => 'ĞĞµÑ„Ñ‚Ñ‡Ğ°Ğ»Ğ°']],
    ['id' => 'dot-ismayilli', 'top' => '33.0%', 'left' => '58.6%', 'label' => ['az' => 'Ä°smayÄ±llÄ±', 'en' => 'Ismayilli', 'ru' => 'Ğ˜ÑĞ¼Ğ°Ğ¸Ğ»Ğ»Ñ‹']],
    ['id' => 'dot-bineqedi', 'top' => '41.8%', 'left' => '84.8%', 'label' => ['az' => 'BinÉ™qÉ™di', 'en' => 'Binagadi', 'ru' => 'Ğ‘Ğ¸Ğ½Ğ°Ğ³Ğ°Ğ´Ğ¸']],
    ['id' => 'dot-abseron', 'top' => '43.0%', 'left' => '83.2%', 'label' => ['az' => 'AbÅŸeron', 'en' => 'Absheron', 'ru' => 'ĞĞ±ÑˆĞµÑ€Ğ¾Ğ½']],
    ['id' => 'dot-sabunchu', 'top' => '42.6%', 'left' => '87.4%', 'label' => ['az' => 'SabunÃ§u', 'en' => 'Sabunchu', 'ru' => 'Ğ¡Ğ°Ğ±ÑƒĞ½Ñ‡Ğ¸']],
    ['id' => 'dot-xetai', 'top' => '45.0%', 'left' => '88.2%', 'label' => ['az' => 'XÉ™tai', 'en' => 'Khatai', 'ru' => 'Ğ¥Ğ°Ñ‚Ğ°Ğ¸']],
    ['id' => 'dot-suraxani', 'top' => '44.2%', 'left' => '86.8%', 'label' => ['az' => 'SuraxanÄ±', 'en' => 'Surakhani', 'ru' => 'Ğ¡ÑƒÑ€Ğ°Ñ…Ğ°Ğ½Ñ‹']],
    ['id' => 'dot-nizami', 'top' => '45.2%', 'left' => '85.5%', 'label' => ['az' => 'Nizami', 'en' => 'Nizami', 'ru' => 'ĞĞ¸Ğ·Ğ°Ğ¼Ğ¸']],
    ['id' => 'dot-pirsagi', 'top' => '41.0%', 'left' => '83.6%', 'label' => ['az' => 'PirÅŸaÄŸÄ±', 'en' => 'Pirshagi', 'ru' => 'ĞŸĞ¸Ñ€ÑˆĞ°Ğ³Ğ¸']],
    ['id' => 'dot-kurdexani', 'top' => '41.2%', 'left' => '84.6%', 'label' => ['az' => 'KÃ¼rdÉ™xanÄ±', 'en' => 'Kurdakhani', 'ru' => 'ĞšÑÑ€Ğ´Ğ°Ñ…Ğ°Ğ½Ñ‹']],
    ['id' => 'dot-zabrat', 'top' => '42.0%', 'left' => '85.7%', 'label' => ['az' => 'Zabrat', 'en' => 'Zabrat', 'ru' => 'Ğ—Ğ°Ğ±Ñ€Ğ°Ñ‚']],
    ['id' => 'dot-bakixanov', 'top' => '43.2%', 'left' => '86.7%', 'label' => ['az' => 'BakÄ±xanov', 'en' => 'Bakikhanov', 'ru' => 'Ğ‘Ğ°ĞºĞ¸Ñ…Ğ°Ğ½Ğ¾Ğ²']],
    ['id' => 'dot-mehdiabad', 'top' => '41.5%', 'left' => '85.0%', 'label' => ['az' => 'Mehdiabad', 'en' => 'Mehdiabad', 'ru' => 'ĞœĞµÑ…Ğ´Ğ¸Ğ°Ğ±Ğ°Ğ´']],
    ['id' => 'dot-memmedli', 'top' => '41.8%', 'left' => '86.5%', 'label' => ['az' => 'MÉ™mmÉ™dli', 'en' => 'Mammadli', 'ru' => 'ĞœĞ°Ğ¼ĞµĞ´Ğ»Ğ¸']],
    ['id' => 'dot-qarachuxur', 'top' => '44.8%', 'left' => '86.2%', 'label' => ['az' => 'QaraÃ§uxur', 'en' => 'Garachukhur', 'ru' => 'Ğ“Ğ°Ñ€Ğ°Ñ‡ÑƒÑ…ÑƒÑ€']],
    ['id' => 'dot-guneshli', 'top' => '45.0%', 'left' => '87.0%', 'label' => ['az' => 'GÃ¼nÉ™ÅŸli', 'en' => 'Gunashli', 'ru' => 'Ğ“ÑĞ½ĞµÑˆĞ»Ğ¸']],
    ['id' => 'dot-ehmedli', 'top' => '44.3%', 'left' => '87.6%', 'label' => ['az' => 'ÆhmÉ™dli', 'en' => 'Ahmadli', 'ru' => 'ĞÑ…Ğ¼ĞµĞ´Ğ»Ñ‹']],
    ['id' => 'dot-bileceri', 'top' => '42.7%', 'left' => '83.9%', 'label' => ['az' => 'BilÉ™cÉ™ri', 'en' => 'Bilajari', 'ru' => 'Ğ‘Ğ¸Ğ»ÑĞ´Ğ¶Ğ°Ñ€Ğ¸']],
];

$tariffs = [
    ['audience' => 'home', 'filter' => 'internet', 'type' => ['az' => 'FÉ™rdi internet', 'en' => 'Home internet', 'ru' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚'], 'speed' => 100, 'price' => 25, 'features' => ['az' => ['Fiber optik xÉ™tt', 'Wi-Fi router dÉ™stÉ™yi', 'Standart quraÅŸdÄ±rma'], 'en' => ['Fiber line', 'Wi-Fi router support', 'Standard installation'], 'ru' => ['ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ', 'ĞŸĞ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ° Wi-Fi Ñ€Ğ¾ÑƒÑ‚ĞµÑ€Ğ°', 'Ğ¡Ñ‚Ğ°Ğ½Ğ´Ğ°Ñ€Ñ‚Ğ½Ğ°Ñ ÑƒÑÑ‚Ğ°Ğ½Ğ¾Ğ²ĞºĞ°']], 'badge' => ['az' => 'Yeni qoÅŸulma', 'en' => 'New connection', 'ru' => 'ĞĞ¾Ğ²Ğ¾Ğµ Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ğµ']],
    ['audience' => 'home', 'filter' => 'internet-tv', 'type' => ['az' => 'FÉ™rdi internet + TV', 'en' => 'Home internet + TV', 'ru' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ + Ğ¢Ğ’'], 'speed' => 100, 'price' => 31, 'features' => ['az' => ['Fiber optik xÉ™tt', 'TV xidmÉ™ti', 'TV Box dÉ™stÉ™yi'], 'en' => ['Fiber line', 'TV service', 'TV Box support'], 'ru' => ['ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ', 'Ğ¢Ğ’ ÑĞµÑ€Ğ²Ğ¸Ñ', 'ĞŸĞ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ° TV Box']]],
    ['audience' => 'home', 'filter' => 'internet-phone', 'type' => ['az' => 'FÉ™rdi internet + Telefon', 'en' => 'Home internet + Phone', 'ru' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ + Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½'], 'speed' => 100, 'price' => 29, 'features' => ['az' => ['Fiber optik xÉ™tt', 'Sabit telefon xÉ™tti', 'Ev Ã¼Ã§Ã¼n rahat istifadÉ™'], 'en' => ['Fiber line', 'Fixed phone line', 'Comfortable for home use'], 'ru' => ['ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ', 'Ğ¡Ñ‚Ğ°Ñ†Ğ¸Ğ¾Ğ½Ğ°Ñ€Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ', 'Ğ£Ğ´Ğ¾Ğ±Ğ½Ğ¾ Ğ´Ğ»Ñ Ğ´Ğ¾Ğ¼Ğ°']]],
    ['audience' => 'home', 'filter' => 'bundle', 'type' => ['az' => 'FÉ™rdi tam paket', 'en' => 'Home full bundle', 'ru' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¿Ğ¾Ğ»Ğ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚'], 'speed' => 100, 'price' => 35, 'features' => ['az' => ['Ä°nternet', 'TV xidmÉ™ti', 'Telefon xÉ™tti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚', 'Ğ¢Ğ’ ÑĞµÑ€Ğ²Ğ¸Ñ', 'Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ']]],
    ['audience' => 'home', 'filter' => 'internet', 'type' => ['az' => 'FÉ™rdi internet', 'en' => 'Home internet', 'ru' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚'], 'speed' => 150, 'price' => 30, 'features' => ['az' => ['Fiber optik xÉ™tt', 'Wi-Fi router dÉ™stÉ™yi', 'AilÉ™ istifadÉ™si Ã¼Ã§Ã¼n rahat sÃ¼rÉ™t'], 'en' => ['Fiber line', 'Wi-Fi router support', 'Comfortable speed for family use'], 'ru' => ['ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ', 'ĞŸĞ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ° Wi-Fi Ñ€Ğ¾ÑƒÑ‚ĞµÑ€Ğ°', 'ĞšĞ¾Ğ¼Ñ„Ğ¾Ñ€Ñ‚Ğ½Ğ°Ñ ÑĞºĞ¾Ñ€Ğ¾ÑÑ‚ÑŒ Ğ´Ğ»Ñ ÑĞµĞ¼ÑŒĞ¸']]],
    ['audience' => 'home', 'filter' => 'bundle', 'type' => ['az' => 'FÉ™rdi tam paket', 'en' => 'Home full bundle', 'ru' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¿Ğ¾Ğ»Ğ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚'], 'speed' => 150, 'price' => 40, 'features' => ['az' => ['Ä°nternet', 'TV xidmÉ™ti', 'Telefon xÉ™tti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚', 'Ğ¢Ğ’ ÑĞµÑ€Ğ²Ğ¸Ñ', 'Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ']], 'badge' => ['az' => 'Æn Ã§ox seÃ§ilÉ™n', 'en' => 'Most chosen', 'ru' => 'Ğ¡Ğ°Ğ¼Ñ‹Ğ¹ Ğ¿Ğ¾Ğ¿ÑƒĞ»ÑÑ€Ğ½Ñ‹Ğ¹']],
    ['audience' => 'home', 'filter' => 'internet', 'type' => ['az' => 'FÉ™rdi internet', 'en' => 'Home internet', 'ru' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚'], 'speed' => 250, 'price' => 36, 'features' => ['az' => ['4K streaming Ã¼Ã§Ã¼n uyÄŸun', 'Wi-Fi router dÉ™stÉ™yi', 'Sabit fiber xÉ™tt'], 'en' => ['Suitable for 4K streaming', 'Wi-Fi router support', 'Stable fiber line'], 'ru' => ['ĞŸĞ¾Ğ´Ñ…Ğ¾Ğ´Ğ¸Ñ‚ Ğ´Ğ»Ñ 4K streaming', 'ĞŸĞ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ° Wi-Fi Ñ€Ğ¾ÑƒÑ‚ĞµÑ€Ğ°', 'Ğ¡Ñ‚Ğ°Ğ±Ğ¸Ğ»ÑŒĞ½Ğ°Ñ fiber-Ğ»Ğ¸Ğ½Ğ¸Ñ']]],
    ['audience' => 'home', 'filter' => 'bundle', 'type' => ['az' => 'FÉ™rdi tam paket', 'en' => 'Home full bundle', 'ru' => 'Ğ”Ğ¾Ğ¼Ğ°ÑˆĞ½Ğ¸Ğ¹ Ğ¿Ğ¾Ğ»Ğ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚'], 'speed' => 250, 'price' => 46, 'features' => ['az' => ['Ä°nternet', 'TV xidmÉ™ti', 'Telefon xÉ™tti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚', 'Ğ¢Ğ’ ÑĞµÑ€Ğ²Ğ¸Ñ', 'Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ']]],
    ['audience' => 'business', 'filter' => 'internet', 'type' => ['az' => 'Biznes internet', 'en' => 'Business internet', 'ru' => 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚'], 'speed' => 100, 'price' => 30, 'features' => ['az' => ['FÉ™rdi paketdÉ™n 5 AZN yuxarÄ± baza', 'Prioritet dÉ™stÉ™k', 'Ofis Ã¼Ã§Ã¼n sabit xÉ™tt'], 'en' => ['Base price 5 AZN above home plan', 'Priority support', 'Stable line for office use'], 'ru' => ['Ğ‘Ğ°Ğ·Ğ¾Ğ²Ğ°Ñ Ñ†ĞµĞ½Ğ° Ğ½Ğ° 5 AZN Ğ²Ñ‹ÑˆĞµ Ğ´Ğ¾Ğ¼Ğ°ÑˆĞ½ĞµĞ³Ğ¾ Ñ‚Ğ°Ñ€Ğ¸Ñ„Ğ°', 'ĞŸÑ€Ğ¸Ğ¾Ñ€Ğ¸Ñ‚ĞµÑ‚Ğ½Ğ°Ñ Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ°', 'Ğ¡Ñ‚Ğ°Ğ±Ğ¸Ğ»ÑŒĞ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ Ğ´Ğ»Ñ Ğ¾Ñ„Ğ¸ÑĞ°']]],
    ['audience' => 'business', 'filter' => 'internet-tv', 'type' => ['az' => 'Biznes internet + TV', 'en' => 'Business internet + TV', 'ru' => 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ + Ğ¢Ğ’'], 'speed' => 100, 'price' => 40, 'features' => ['az' => ['Baza qiymÉ™t + TV 10 AZN', 'GÃ¶zlÉ™mÉ™ zonasÄ± Ã¼Ã§Ã¼n TV', 'Fiber optik xÉ™tt'], 'en' => ['Base price + TV 10 AZN', 'TV for waiting areas', 'Fiber line'], 'ru' => ['Ğ‘Ğ°Ğ·Ğ¾Ğ²Ğ°Ñ Ñ†ĞµĞ½Ğ° + Ğ¢Ğ’ 10 AZN', 'Ğ¢Ğ’ Ğ´Ğ»Ñ Ğ·Ğ¾Ğ½Ñ‹ Ğ¾Ğ¶Ğ¸Ğ´Ğ°Ğ½Ğ¸Ñ', 'ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ']]],
    ['audience' => 'business', 'filter' => 'internet-phone', 'type' => ['az' => 'Biznes internet + Telefon', 'en' => 'Business internet + Phone', 'ru' => 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ + Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½'], 'speed' => 100, 'price' => 40, 'features' => ['az' => ['Baza qiymÉ™t + Telefon 10 AZN', 'Sabit É™laqÉ™ xÉ™tti', 'Ofis Ã¼Ã§Ã¼n uyÄŸun'], 'en' => ['Base price + Phone 10 AZN', 'Fixed communication line', 'Suitable for office use'], 'ru' => ['Ğ‘Ğ°Ğ·Ğ¾Ğ²Ğ°Ñ Ñ†ĞµĞ½Ğ° + Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½ 10 AZN', 'Ğ¡Ñ‚Ğ°Ñ†Ğ¸Ğ¾Ğ½Ğ°Ñ€Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ ÑĞ²ÑĞ·Ğ¸', 'ĞŸĞ¾Ğ´Ñ…Ğ¾Ğ´Ğ¸Ñ‚ Ğ´Ğ»Ñ Ğ¾Ñ„Ğ¸ÑĞ°']]],
    ['audience' => 'business', 'filter' => 'bundle', 'type' => ['az' => 'Biznes tam paket', 'en' => 'Business full bundle', 'ru' => 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ğ¿Ğ¾Ğ»Ğ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚'], 'speed' => 100, 'price' => 50, 'features' => ['az' => ['Ä°nternet 30 AZN', 'TV +10 AZN', 'Telefon +10 AZN'], 'en' => ['Internet 30 AZN', 'TV +10 AZN', 'Phone +10 AZN'], 'ru' => ['Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ 30 AZN', 'Ğ¢Ğ’ +10 AZN', 'Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½ +10 AZN']]],
    ['audience' => 'business', 'filter' => 'internet', 'type' => ['az' => 'Biznes internet', 'en' => 'Business internet', 'ru' => 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚'], 'speed' => 150, 'price' => 35, 'features' => ['az' => ['Prioritet dÉ™stÉ™k', 'ÅÉ™bÉ™kÉ™ Ã¼Ã§Ã¼n daha geniÅŸ resurs', 'Fiber optik xÉ™tt'], 'en' => ['Priority support', 'More network capacity', 'Fiber line'], 'ru' => ['ĞŸÑ€Ğ¸Ğ¾Ñ€Ğ¸Ñ‚ĞµÑ‚Ğ½Ğ°Ñ Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ°', 'Ğ‘Ğ¾Ğ»ÑŒÑˆĞµ Ñ€ĞµÑÑƒÑ€ÑĞ° Ğ´Ğ»Ñ ÑĞµÑ‚Ğ¸', 'ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ']]],
    ['audience' => 'business', 'filter' => 'bundle', 'type' => ['az' => 'Biznes tam paket', 'en' => 'Business full bundle', 'ru' => 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ğ¿Ğ¾Ğ»Ğ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚'], 'speed' => 150, 'price' => 55, 'features' => ['az' => ['Ä°nternet', 'TV xidmÉ™ti', 'Telefon xÉ™tti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚', 'Ğ¢Ğ’ ÑĞµÑ€Ğ²Ğ¸Ñ', 'Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ']]],
    ['audience' => 'business', 'filter' => 'internet', 'type' => ['az' => 'Biznes internet', 'en' => 'Business internet', 'ru' => 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚'], 'speed' => 250, 'price' => 41, 'features' => ['az' => ['Ofis vÉ™ kamera sistemi Ã¼Ã§Ã¼n uyÄŸun', 'ÅÉ™bÉ™kÉ™ dayanÄ±qlÄ±ÄŸÄ±', 'Prioritet xidmÉ™t'], 'en' => ['Suitable for office and camera systems', 'Network stability', 'Priority service'], 'ru' => ['ĞŸĞ¾Ğ´Ñ…Ğ¾Ğ´Ğ¸Ñ‚ Ğ´Ğ»Ñ Ğ¾Ñ„Ğ¸ÑĞ° Ğ¸ ĞºĞ°Ğ¼ĞµÑ€', 'Ğ¡Ñ‚Ğ°Ğ±Ğ¸Ğ»ÑŒĞ½Ğ¾ÑÑ‚ÑŒ ÑĞµÑ‚Ğ¸', 'ĞŸÑ€Ğ¸Ğ¾Ñ€Ğ¸Ñ‚ĞµÑ‚Ğ½Ñ‹Ğ¹ ÑĞµÑ€Ğ²Ğ¸Ñ']]],
    ['audience' => 'business', 'filter' => 'bundle', 'type' => ['az' => 'Biznes tam paket', 'en' => 'Business full bundle', 'ru' => 'Ğ‘Ğ¸Ğ·Ğ½ĞµÑ Ğ¿Ğ¾Ğ»Ğ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚'], 'speed' => 250, 'price' => 61, 'features' => ['az' => ['Ä°nternet', 'TV xidmÉ™ti', 'Telefon xÉ™tti'], 'en' => ['Internet', 'TV service', 'Phone line'], 'ru' => ['Ğ˜Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚', 'Ğ¢Ğ’ ÑĞµÑ€Ğ²Ğ¸Ñ', 'Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ½Ğ°Ñ Ğ»Ğ¸Ğ½Ğ¸Ñ']]],
];
$supportPhoneDisplay = '+994 12 525 88 11';
$supportPhoneHref = 'tel:+994125258811';
$supportWhatsappHref = 'https://wa.me/994512055566?text=Super%20Optik%20Tech';
$showLanguageMenu = true;
$publicCta = [
    'az' => [
        'call_note' => 'SatÄ±ÅŸ vÉ™ dÉ™stÉ™k',
        'portal_label' => 'Kabinet',
        'hero_eyebrow' => 'Ev vÉ™ biznes Ã¼Ã§Ã¼n fiber internet',
        'hero_title' => '100 Mbit fiber internet 25 AZN-dÉ™n',
        'hero_text' => 'Ev Ã¼Ã§Ã¼n 25 AZN, biznes Ã¼Ã§Ã¼n 30 AZN. ÃœnvanÄ±nÄ±zÄ± yoxlayÄ±n, telefon nÃ¶mrÉ™nizi yazÄ±n vÉ™ qoÅŸulma vaxtÄ±nÄ± sizÉ™ geri bildirÉ™k.',
        'hero_primary' => 'Ä°ndi mÃ¼raciÉ™t et',
        'hero_secondary' => 'ÆhatÉ™ni yoxla',
        'hero_note' => 'QoÅŸulma Ã¼Ã§Ã¼n É™razi vÉ™ telefon kifayÉ™tdir. AdÉ™tÉ™n 15 dÉ™qiqÉ™ É™rzindÉ™ geri dÃ¶nÃ¼ÅŸ edirik.',
        'form_title' => '1 dÉ™qiqÉ™dÉ™ qoÅŸulma mÃ¼raciÉ™ti',
        'form_text' => 'Ærazi vÉ™ telefon nÃ¶mrÉ™sini yazÄ±n. KomandamÄ±z texniki imkanÄ± vÉ™ qoÅŸulma vaxtÄ±nÄ± sizinlÉ™ dÉ™qiqlÉ™ÅŸdirsin.',
        'form_hint' => '1 dÉ™qiqÉ™ Ã§É™kir. ÆvvÉ™lcÉ™ É™razi vÉ™ nÃ¶mrÉ™ni yazÄ±n, qalan hissÉ™ni komandamÄ±z sizinlÉ™ dÉ™qiqlÉ™ÅŸdirsin.',
        'portal_title' => 'MÃ¶vcud mÃ¼ÅŸtÉ™ri vÉ™ É™mÉ™kdaÅŸ giriÅŸi',
        'portal_text' => 'Ã–dÉ™niÅŸ, trafik vÉ™ mÃ¼raciÉ™tlÉ™r Ã¼Ã§Ã¼n kabinetÉ™ keÃ§in. Support vÉ™ NOC É™mÉ™kdaÅŸlarÄ± da bu giriÅŸdÉ™n istifadÉ™ edir.',
        'portal_points' => ['MÃ¼ÅŸtÉ™ri: Ã¶dÉ™niÅŸ, trafik vÉ™ mÃ¼raciÉ™tlÉ™r', 'ÆmÉ™kdaÅŸ: billing vÉ™ OLT keÃ§idi'],
        'portal_action' => 'KabinetÉ™ keÃ§',
        'whatsapp_label' => 'WhatsApp',
        'whatsapp_note' => 'BizÉ™ yaz',
        'news_subtitle' => 'Ä°nternet vÉ™ sistem tÉ™hlÃ¼kÉ™sizliyi ilÉ™ baÄŸlÄ± faydalÄ± yeniliklÉ™ri qÄ±sa vÉ™ aydÄ±n formada paylaÅŸÄ±rÄ±q.',
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
        'call_note' => 'ĞŸÑ€Ğ¾Ğ´Ğ°Ğ¶Ğ¸ Ğ¸ Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºĞ°',
        'portal_label' => 'ĞšĞ°Ğ±Ğ¸Ğ½ĞµÑ‚',
        'hero_eyebrow' => 'ĞĞ¿Ñ‚Ğ¾Ğ²Ğ¾Ğ»Ğ¾ĞºĞ¾Ğ½Ğ½Ñ‹Ğ¹ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚ Ğ´Ğ»Ñ Ğ´Ğ¾Ğ¼Ğ° Ğ¸ Ğ±Ğ¸Ğ·Ğ½ĞµÑĞ°',
        'hero_title' => '100 ĞœĞ±Ğ¸Ñ‚ fiber-Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚Ğ° Ğ¾Ñ‚ 25 AZN',
        'hero_text' => '25 AZN Ğ´Ğ»Ñ Ğ´Ğ¾Ğ¼Ğ° Ğ¸ 30 AZN Ğ´Ğ»Ñ Ğ±Ğ¸Ğ·Ğ½ĞµÑĞ°. ĞŸÑ€Ğ¾Ğ²ĞµÑ€ÑŒÑ‚Ğµ Ğ¿Ğ¾ĞºÑ€Ñ‹Ñ‚Ğ¸Ğµ, Ğ¾Ñ‚Ğ¿Ñ€Ğ°Ğ²ÑŒÑ‚Ğµ Ğ½Ğ¾Ğ¼ĞµÑ€ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ° Ğ¸ Ğ¿Ğ¾Ğ»ÑƒÑ‡Ğ¸Ñ‚Ğµ ÑÑ€Ğ¾Ğº Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ Ğ¾Ñ‚ Ğ½Ğ°ÑˆĞµĞ¹ ĞºĞ¾Ğ¼Ğ°Ğ½Ğ´Ñ‹.',
        'hero_primary' => 'ĞÑÑ‚Ğ°Ğ²Ğ¸Ñ‚ÑŒ Ğ·Ğ°ÑĞ²ĞºÑƒ',
        'hero_secondary' => 'ĞŸÑ€Ğ¾Ğ²ĞµÑ€Ğ¸Ñ‚ÑŒ Ğ¿Ğ¾ĞºÑ€Ñ‹Ñ‚Ğ¸Ğµ',
        'hero_note' => 'Ğ”Ğ»Ñ ÑÑ‚Ğ°Ñ€Ñ‚Ğ° Ğ´Ğ¾ÑÑ‚Ğ°Ñ‚Ğ¾Ñ‡Ğ½Ğ¾ Ñ€Ğ°Ğ¹Ğ¾Ğ½Ğ° Ğ¸ Ğ½Ğ¾Ğ¼ĞµÑ€Ğ° Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ°. ĞĞ±Ñ‹Ñ‡Ğ½Ğ¾ Ğ¼Ñ‹ Ğ¿ĞµÑ€ĞµĞ·Ğ²Ğ°Ğ½Ğ¸Ğ²Ğ°ĞµĞ¼ Ğ² Ñ‚ĞµÑ‡ĞµĞ½Ğ¸Ğµ 15 Ğ¼Ğ¸Ğ½ÑƒÑ‚.',
        'form_title' => 'Ğ—Ğ°ÑĞ²ĞºĞ° Ğ½Ğ° Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ğµ Ğ·Ğ° 1 Ğ¼Ğ¸Ğ½ÑƒÑ‚Ñƒ',
        'form_text' => 'Ğ£ĞºĞ°Ğ¶Ğ¸Ñ‚Ğµ Ñ€Ğ°Ğ¹Ğ¾Ğ½ Ğ¸ Ğ½Ğ¾Ğ¼ĞµÑ€ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ°. ĞĞ°ÑˆĞ° ĞºĞ¾Ğ¼Ğ°Ğ½Ğ´Ğ° ÑƒÑ‚Ğ¾Ñ‡Ğ½Ğ¸Ñ‚ Ñ‚ĞµÑ…Ğ½Ğ¸Ñ‡ĞµÑĞºÑƒÑ Ğ²Ğ¾Ğ·Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ÑÑ‚ÑŒ Ğ¸ ÑÑ€Ğ¾Ğº Ğ¿Ğ¾Ğ´ĞºĞ»ÑÑ‡ĞµĞ½Ğ¸Ñ.',
        'form_hint' => 'Ğ­Ñ‚Ğ¾ Ğ·Ğ°Ğ¹Ğ¼ĞµÑ‚ Ğ¾ĞºĞ¾Ğ»Ğ¾ 1 Ğ¼Ğ¸Ğ½ÑƒÑ‚Ñ‹. Ğ¡Ğ½Ğ°Ñ‡Ğ°Ğ»Ğ° Ğ¾Ñ‚Ğ¿Ñ€Ğ°Ğ²ÑŒÑ‚Ğµ Ñ€Ğ°Ğ¹Ğ¾Ğ½ Ğ¸ Ğ½Ğ¾Ğ¼ĞµÑ€, Ğ¾ÑÑ‚Ğ°Ğ»ÑŒĞ½Ğ¾Ğµ ĞºĞ¾Ğ¼Ğ°Ğ½Ğ´Ğ° ÑƒÑ‚Ğ¾Ñ‡Ğ½Ğ¸Ñ‚ Ğ²Ğ¼ĞµÑÑ‚Ğµ Ñ Ğ²Ğ°Ğ¼Ğ¸.',
        'portal_title' => 'Ğ’Ñ…Ğ¾Ğ´ Ğ´Ğ»Ñ ĞºĞ»Ğ¸ĞµĞ½Ñ‚Ğ¾Ğ² Ğ¸ ÑĞ¾Ñ‚Ñ€ÑƒĞ´Ğ½Ğ¸ĞºĞ¾Ğ²',
        'portal_text' => 'Ğ§ĞµÑ€ĞµĞ· ĞºĞ°Ğ±Ğ¸Ğ½ĞµÑ‚ Ğ¼Ğ¾Ğ¶Ğ½Ğ¾ Ğ¿Ğ¾ÑĞ¼Ğ¾Ñ‚Ñ€ĞµÑ‚ÑŒ Ğ¿Ğ»Ğ°Ñ‚ĞµĞ¶Ğ¸, Ñ‚Ñ€Ğ°Ñ„Ğ¸Ğº Ğ¸ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ñ. Support Ğ¸ NOC Ñ‚Ğ¾Ğ¶Ğµ Ğ¸ÑĞ¿Ğ¾Ğ»ÑŒĞ·ÑƒÑÑ‚ ÑÑ‚Ğ¾Ñ‚ Ğ²Ñ…Ğ¾Ğ´.',
        'portal_points' => ['ĞšĞ»Ğ¸ĞµĞ½Ñ‚: Ğ¿Ğ»Ğ°Ñ‚ĞµĞ¶Ğ¸, Ñ‚Ñ€Ğ°Ñ„Ğ¸Ğº Ğ¸ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ñ', 'Ğ¡Ğ¾Ñ‚Ñ€ÑƒĞ´Ğ½Ğ¸Ğº: Ğ±Ğ¸Ğ»Ğ»Ğ¸Ğ½Ğ³ Ğ¸ Ğ¿ĞµÑ€ĞµÑ…Ğ¾Ğ´ Ğ² OLT'],
        'portal_action' => 'ĞÑ‚ĞºÑ€Ñ‹Ñ‚ÑŒ ĞºĞ°Ğ±Ğ¸Ğ½ĞµÑ‚',
        'whatsapp_label' => 'WhatsApp',
        'whatsapp_note' => 'ĞĞ°Ğ¿Ğ¸ÑˆĞ¸Ñ‚Ğµ Ğ½Ğ°Ğ¼',
        'news_subtitle' => 'ĞœÑ‹ ĞºĞ¾Ñ€Ğ¾Ñ‚ĞºĞ¾ Ğ¸ Ğ¿Ğ¾Ğ½ÑÑ‚Ğ½Ğ¾ Ğ´ĞµĞ»Ğ¸Ğ¼ÑÑ Ğ¿Ğ¾Ğ»ĞµĞ·Ğ½Ñ‹Ğ¼Ğ¸ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½Ğ¸ÑĞ¼Ğ¸ Ğ¿Ğ¾ Ğ¸Ğ½Ñ‚ĞµÑ€Ğ½ĞµÑ‚-ÑĞµÑ€Ğ²Ğ¸ÑÑƒ Ğ¸ ÑĞ¸ÑÑ‚ĞµĞ¼Ğ½Ğ¾Ğ¹ Ğ±ĞµĞ·Ğ¾Ğ¿Ğ°ÑĞ½Ğ¾ÑÑ‚Ğ¸.',
    ],
];
$toSearchKey = static function (string $value): string {
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
    $normalized = strtr($normalized, [
        'É™' => 'e',
        'Ä±' => 'i',
        'Ã¶' => 'o',
        'Ã¼' => 'u',
        'ÅŸ' => 's',
        'Ã§' => 'c',
        'ÄŸ' => 'g',
        'Ã±' => 'n',
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
