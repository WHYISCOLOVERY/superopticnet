<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function page_flag_map(): array
{
    return [
        'az' => 'azerbaijan.png',
        'en' => 'eng.png',
        'ru' => 'rus.png',
    ];
}

function page_labels(): array
{
    return [
        'az' => [
            'skip' => 'Əsas məzmuna keç',
            'tariffs' => 'Tariflər',
            'services' => 'Xidmət',
            'coverage' => 'Əhatə',
            'support' => 'Dəstək',
            'news' => 'Xəbər',
            'tv' => 'TV',
            'payments' => 'Ödəniş',
            'cabinet' => 'Kabinet',
            'call_note' => 'Satış və dəstək',
            'footer_about' => 'Haqqımızda',
            'footer_media' => 'Media üçün',
            'footer_legal' => 'Hüquqi',
            'about' => 'Haqqımızda',
            'mission' => 'Missiya və məqsədlər',
            'privacy' => 'Gizlilik siyasəti',
            'rules' => 'Qaydalar və şərtlər',
            'devices' => 'Cihazlar',
            'quality' => 'Keyfiyyət göstəriciləri',
            'speedtest' => 'Sürətin yoxlanılması',
            'contact' => 'Bizimlə əlaqə',
            'footer_text' => 'Fiber internet, TV, texniki dəstək və sistem həllərini bir platformada birləşdirən xidmət modeli.',
            'copyright' => 'MÜƏLLİFLİK HÜQUQU 2026 ©SUPEROPTIKTECH BÜTÜN HÜQUQLAR QORUNUR',
            'whatsapp_label' => 'WhatsApp',
            'whatsapp_note' => 'Bizə yaz',
        ],
        'en' => [
            'tariffs' => 'Tariffs',
            'services' => 'Services',
            'coverage' => 'Coverage',
            'support' => 'Support',
            'news' => 'News',
            'tv' => 'TV',
            'payments' => 'Payments',
            'cabinet' => 'Portal',
            'call_note' => 'Sales and support',
            'footer_about' => 'About us',
            'footer_media' => 'Media',
            'footer_legal' => 'Legal',
            'about' => 'About',
            'mission' => 'Mission and goals',
            'privacy' => 'Privacy policy',
            'rules' => 'Terms and conditions',
            'devices' => 'Devices',
            'quality' => 'Quality metrics',
            'speedtest' => 'Speed test',
            'contact' => 'Contact us',
            'footer_text' => 'A service model combining fiber internet, TV, support and system solutions on one platform.',
            'copyright' => 'COPYRIGHT 2026 ©SUPEROPTIKTECH ALL RIGHTS RESERVED',
            'whatsapp_label' => 'WhatsApp',
            'whatsapp_note' => 'Write to us',
        ],
        'ru' => [
            'tariffs' => 'Тарифы',
            'services' => 'Услуги',
            'coverage' => 'Покрытие',
            'support' => 'Поддержка',
            'news' => 'Новости',
            'tv' => 'ТВ',
            'payments' => 'Оплата',
            'cabinet' => 'Кабинет',
            'call_note' => 'Продажи и поддержка',
            'footer_about' => 'О компании',
            'footer_media' => 'Для медиа',
            'footer_legal' => 'Юридическое',
            'about' => 'О компании',
            'mission' => 'Миссия и цели',
            'privacy' => 'Политика конфиденциальности',
            'rules' => 'Правила и условия',
            'devices' => 'Устройства',
            'quality' => 'Показатели качества',
            'speedtest' => 'Проверка скорости',
            'contact' => 'Связаться с нами',
            'footer_text' => 'Сервисная модель, объединяющая fiber-интернет, ТВ, поддержку и системные решения на одной платформе.',
            'copyright' => 'АВТОРСКОЕ ПРАВО 2026 ©SUPEROPTIKTECH ВСЕ ПРАВА ЗАЩИЩЕНЫ',
            'whatsapp_label' => 'WhatsApp',
            'whatsapp_note' => 'Напишите нам',
        ],
    ];
}

function page_url(string $path, string $lang, string $fragment = ''): string
{
    $url = $path;
    if ($lang !== 'az') {
        $url .= (str_contains($path, '?') ? '&' : '?') . 'lang=' . rawurlencode($lang);
    }

    return $fragment !== '' ? $url . '#' . ltrim($fragment, '#') : $url;
}

function page_render_header(string $lang, string $title, string $description, string $active = ''): void
{
    $labels = page_labels()[$lang] ?? page_labels()['az'];
    $flagMap = page_flag_map();
    $currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php'));
    $supportPhoneDisplay = '+994 12 525 88 11';
    $supportPhoneHref = 'tel:+994125258811';
    $portalLoginUrl = rtrim(app_config('portal_url', 'https://mgt.superoptic.net/portal-login.php'), '/');
    $portalLangQuery = $lang !== 'az' ? '?lang=' . rawurlencode($lang) : '';
    ?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title); ?></title>
    <meta name="description" content="<?= e($description); ?>">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <?php
    $siteUrl = rtrim((string) app_config('site_url', 'https://superoptic.net'), '/');
    $pagePath = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
    $pageBase = $siteUrl . $pagePath;
    $pageAlt = static fn (string $l): string => $l === 'az' ? $pageBase : $pageBase . '?lang=' . $l;
    ?>
    <link rel="canonical" href="<?= e($pageBase . ($lang !== 'az' ? '?lang=' . $lang : '')); ?>">
    <link rel="alternate" hreflang="az" href="<?= e($pageAlt('az')); ?>">
    <link rel="alternate" hreflang="en" href="<?= e($pageAlt('en')); ?>">
    <link rel="alternate" hreflang="ru" href="<?= e($pageAlt('ru')); ?>">
    <link rel="alternate" hreflang="x-default" href="<?= e($pageAlt('az')); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Super Optik">
    <meta property="og:title" content="<?= e($title); ?>">
    <meta property="og:description" content="<?= e($description); ?>">
    <meta property="og:url" content="<?= e($pageBase); ?>">
    <meta property="og:image" content="<?= e($siteUrl . '/images/news-network-expansion.png'); ?>">
    <link rel="shortcut icon" href="images/favicon.png">
    <link rel="stylesheet" href="<?= e(app_asset('css/fiber-site.css')); ?>">
    <script>try{var _t=localStorage.getItem('so-theme');if(_t)document.documentElement.setAttribute('data-theme',_t);}catch(e){}</script>
</head>
<body>
    <a class="skip-link" href="#main"><?= e($labels['skip'] ?? 'Skip to content'); ?></a>
    <canvas id="fiber-bg" aria-hidden="true"></canvas>
    <canvas id="cursor-trail" aria-hidden="true"></canvas>
    <div class="fiber-veil" aria-hidden="true"></div>
    <header class="site-header">
        <div class="container header-inner">
            <a href="<?= e(page_url('index.php', $lang)); ?>" class="brand" aria-label="Home">
                <img src="images/favicon.png" alt="Super Optik Tech logo">
                <span class="brand-mark">
                    <span class="brand-word brand-word-super">Super</span>
                    <span class="brand-word brand-word-optik">Optik</span>
                </span>
            </a>

            <button class="mobile-nav-toggle" type="button" aria-label="Menu" data-mobile-nav-toggle>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
            </button>

            <nav class="main-nav" data-mobile-nav>
                <a href="<?= e(page_url('index.php', $lang, 'tariffs')); ?>"><?= e($labels['tariffs']); ?></a>
                <a href="<?= e(page_url('index.php', $lang, 'services')); ?>"><?= e($labels['services']); ?></a>
                <a href="<?= e(page_url('index.php', $lang, 'coverage')); ?>"><?= e($labels['coverage']); ?></a>
                <a href="<?= e(page_url('index.php', $lang, 'support')); ?>"><?= e($labels['support']); ?></a>
                <a href="<?= e(page_url('index.php', $lang, 'news')); ?>"><?= e($labels['news']); ?></a>
                <a href="<?= e(page_url('index.php', $lang, 'tvnet')); ?>"><?= e($labels['tv']); ?></a>
                <a href="<?= e(page_url('index.php', $lang, 'payments')); ?>"><?= e($labels['payments']); ?></a>
            </nav>

            <div class="header-actions" data-mobile-actions>
                <a class="header-link header-link-phone" href="<?= e($supportPhoneHref); ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h4l2 5-3 2a12 12 0 0 0 6 6l2-3 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 2 6a2 2 0 0 1 2-2z"/></svg>
                    <span class="header-link-stack">
                        <strong><?= e($supportPhoneDisplay); ?></strong>
                        <small><?= e($labels['call_note']); ?></small>
                    </span>
                </a>
                <a class="header-link header-link-portal header-link-muted" href="<?= e($portalLoginUrl . $portalLangQuery); ?>"><?= e($labels['cabinet']); ?></a>

                <button class="theme-toggle" type="button" data-theme-toggle aria-label="Theme">
                    <span class="tt-moon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg></span>
                    <span class="tt-sun"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></span>
                </button>

                <div class="language-dropdown">
                    <button class="language-button" type="button" aria-label="Language">
                        <img src="images/<?= e($flagMap[$lang] ?? 'azerbaijan.png'); ?>" alt="<?= e(strtoupper($lang)); ?>">
                        <span><?= e(strtoupper($lang)); ?></span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="language-menu">
                        <a href="<?= e(page_url($currentPage, 'az')); ?>" class="lang-option" data-lang="az"><img src="images/azerbaijan.png" alt="AZ"> <span>AZ</span></a>
                        <a href="<?= e(page_url($currentPage, 'en')); ?>" class="lang-option" data-lang="en"><img src="images/eng.png" alt="EN"> <span>EN</span></a>
                        <a href="<?= e(page_url($currentPage, 'ru')); ?>" class="lang-option" data-lang="ru"><img src="images/rus.png" alt="RU"> <span>RU</span></a>
                    </div>
                </div>
            </div>
        </div>
    </header>
<?php
}

function page_render_footer(string $lang): void
{
    $labels = page_labels()[$lang] ?? page_labels()['az'];
    ?>
    <footer class="site-footer site-footer-detailed">
        <div class="container footer-detailed-grid">
            <div class="footer-brand-block">
                <a href="<?= e(page_url('index.php', $lang)); ?>" class="brand brand-footer">
                    <img src="images/favicon.png" alt="Super Optik Tech logo">
                    <span class="brand-mark">
                        <span class="brand-word brand-word-super">Super</span>
                        <span class="brand-word brand-word-optik">Optik</span>
                    </span>
                </a>
                <p><?= e($labels['footer_text']); ?></p>
                <div class="footer-socials">
                    <a href="https://www.instagram.com/superoptiktech/" target="_blank" rel="noreferrer" aria-label="Instagram"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17" cy="7" r="1" fill="currentColor"/></svg></a>
                    <a href="mailto:info@superoptic.net" aria-label="Email"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 6 9 7 9-7"/></svg></a>
                    <a href="tel:+994125258811" aria-label="Phone"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h4l2 5-3 2a12 12 0 0 0 6 6l2-3 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 2 6a2 2 0 0 1 2-2z"/></svg></a>
                    <a href="https://e-complaint.icta.az/" target="_blank" rel="noreferrer" aria-label="ICT complaint">ICT</a>
                </div>
            </div>

            <div class="footer-column">
                <strong><?= e($labels['footer_about']); ?></strong>
                <a href="<?= e(page_url('haqqimizda.php', $lang)); ?>"><?= e($labels['about']); ?></a>
                <a href="<?= e(page_url('haqqimizda.php', $lang, 'mission')); ?>"><?= e($labels['mission']); ?></a>
                <a href="<?= e(page_url('index.php', $lang, 'contact')); ?>"><?= e($labels['contact']); ?></a>
            </div>

            <div class="footer-column">
                <strong><?= e($labels['footer_media']); ?></strong>
                <a href="<?= e(page_url('index.php', $lang, 'news')); ?>"><?= e($labels['news']); ?></a>
                <a href="<?= e(page_url('index.php', $lang, 'tvnet')); ?>"><?= e($labels['tv']); ?></a>
                <a href="https://www.speedtest.net/" target="_blank" rel="noreferrer"><?= e($labels['speedtest']); ?></a>
            </div>

            <div class="footer-column">
                <strong><?= e($labels['footer_legal']); ?></strong>
                <a href="<?= e(page_url('gizlilik-siyaseti.php', $lang)); ?>"><?= e($labels['privacy']); ?></a>
                <a href="<?= e(page_url('qaydalar-ve-sertler.php', $lang)); ?>"><?= e($labels['rules']); ?></a>
                <a href="<?= e(page_url('cihazlar.php', $lang)); ?>"><?= e($labels['devices']); ?></a>
                <a href="<?= e(page_url('keyfiyyet-gostericileri.php', $lang)); ?>"><?= e($labels['quality']); ?></a>
            </div>
        </div>

        <div class="container footer-bottom footer-bottom-detailed">
            <p><?= e($labels['copyright']); ?></p>
        </div>
    </footer>

    <a class="whatsapp-float" href="https://wa.me/994512055566?text=Super%20Optik%20Tech" target="_blank" rel="noreferrer" aria-label="WhatsApp">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21l1.5-5A9 9 0 1 1 12 21a9 9 0 0 1-4.5-1.2L3 21z"/><path d="M9 10c0 3 2 5 5 5 1 0 1.5-1 1.5-1.5L14 12l-1.5 1c-1-.5-2-1.5-2.5-2.5L11 9l-.5-1.5C10 7 9 7.5 9 8.5z"/></svg>
        <span class="whatsapp-float-text">
            <strong><?= e($labels['whatsapp_label']); ?></strong>
            <small><?= e($labels['whatsapp_note']); ?></small>
        </span>
    </a>

    <script src="<?= e(app_asset('script/main.js')); ?>"></script>
    <script src="<?= e(app_asset("script/fiber-bg.js")); ?>" defer></script>
    <script>(function(){var t=document.querySelector("[data-theme-toggle]");if(t)t.addEventListener("click",function(){var c=document.documentElement.getAttribute("data-theme")==="light"?"dark":"light";document.documentElement.setAttribute("data-theme",c);try{localStorage.setItem("so-theme",c);}catch(e){}});})();</script>
</body>
</html>
<?php
}

function page_device_schedule(int $basePrice): array
{
    $factors = [1, 0.75, 0.625, 0.5, 0.375, 0.25, 0.125];
    return array_map(
        static fn (float $factor): int => (int) round($basePrice * $factor, 0, PHP_ROUND_HALF_UP),
        $factors
    );
}

/** Daxili səhifələr üçün sətir ikonları (fontawesome-lite yalnız yer tutucudur). */
function page_icon(string $name, int $size = 24): string
{
    $paths = [
        'handshake' => '<path d="m11 17 2 2a1 1 0 0 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 0 0 3-3l-3.9-3.9a3 3 0 0 0-4.2 0l-.5.5a2 2 0 0 1-2.8 0"/><path d="m21 3 1 11h-2M3 3 2 14l6.5 6.5a1 1 0 0 0 3-3"/><path d="M3 4h8"/>',
        'shield' => '<path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5l-8-3z"/><path d="m9 12 2 2 4-4"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'bolt' => '<path d="M13 2 4 14h6l-1 8 9-12h-6l1-8z"/>',
    ];
    $p = $paths[$name] ?? $paths['shield'];

    return '<svg class="svgi" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Daxili səhifələrin sonunda qoşulma çağırışı. */
function page_render_cta(string $lang): void
{
    $t = [
        'az' => ['Qoşulmaq istəyirsiniz?', 'Ünvanı və telefonu yazın, komandamız texniki imkanı yoxlayıb sizinlə əlaqə saxlasın.', 'Müraciət et', 'Zəng et'],
        'en' => ['Want to connect?', 'Leave your address and phone and our team will check availability and contact you.', 'Apply now', 'Call us'],
        'ru' => ['Хотите подключиться?', 'Оставьте адрес и телефон, команда проверит возможность подключения и свяжется с вами.', 'Оставить заявку', 'Позвонить'],
    ][$lang] ?? null;
    $t ??= ['Qoşulmaq istəyirsiniz?', 'Ünvanı və telefonu yazın, komandamız texniki imkanı yoxlayıb sizinlə əlaqə saxlasın.', 'Müraciət et', 'Zəng et'];
    ?>
    <div class="cta-band">
        <div><h2><?= e($t[0]); ?></h2><p><?= e($t[1]); ?></p></div>
        <div class="cta-actions">
            <a class="button button-primary" href="<?= e(page_url('index.php', $lang, 'connection-form')); ?>"><?= e($t[2]); ?></a>
            <a class="button button-secondary" href="tel:+994125258811">+994 12 525 88 11</a>
        </div>
    </div>
    <?php
}
