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
    ?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title); ?></title>
    <meta name="description" content="<?= e($description); ?>">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <link rel="shortcut icon" href="images/favicon.png">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/style.css')); ?>">
    <link rel="stylesheet" href="<?= e(app_asset('css/stylemedia.css')); ?>">
</head>
<body>
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
                <i class="fa-solid fa-bars"></i>
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
                    <i class="fa-solid fa-phone-volume"></i>
                    <span class="header-link-stack">
                        <strong><?= e($supportPhoneDisplay); ?></strong>
                        <small><?= e($labels['call_note']); ?></small>
                    </span>
                </a>
                <a class="header-link header-link-portal header-link-muted" href="<?= e(page_url('portal-login.php', $lang)); ?>"><?= e($labels['cabinet']); ?></a>

                <div class="language-dropdown">
                    <button class="language-button" type="button" aria-label="Language">
                        <img src="images/<?= e($flagMap[$lang] ?? 'azerbaijan.png'); ?>" alt="<?= e(strtoupper($lang)); ?>">
                        <span><?= e(strtoupper($lang)); ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
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
                <div class="footer-links">
                    <a href="https://www.instagram.com/superoptiktech/" target="_blank" rel="noreferrer" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                    <a href="mailto:info@superoptic.net" aria-label="Email"><i class="fa-solid fa-envelope"></i></a>
                    <a href="tel:+994125258811" aria-label="Phone"><i class="fa-solid fa-phone"></i></a>
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
        <i class="fa-brands fa-whatsapp"></i>
        <span class="whatsapp-float-text">
            <strong><?= e($labels['whatsapp_label']); ?></strong>
            <small><?= e($labels['whatsapp_note']); ?></small>
        </span>
    </a>

    <script src="<?= e(app_asset('script/main.js')); ?>"></script>
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
