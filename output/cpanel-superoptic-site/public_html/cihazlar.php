<?php
declare(strict_types=1);

require __DIR__ . '/page-helpers.php';

$lang = app_lang();

$devices = [
    [
        'name' => ['az' => 'Huawei GPON modem', 'en' => 'Huawei GPON modem', 'ru' => 'GPON модем Huawei'],
        'price' => 100,
        'badge' => ['az' => 'GPON', 'en' => 'GPON', 'ru' => 'GPON'],
        'text' => [
            'az' => 'Fiber xətt üçün stabil baza cihaz. Standart quraşdırma ssenariləri üçün uyğundur.',
            'en' => 'Stable base device for fiber lines. Suitable for standard installation scenarios.',
            'ru' => 'Стабильное базовое устройство для fiber-линий. Подходит для стандартных сценариев установки.',
        ],
    ],
    [
        'name' => ['az' => 'TP-Link dual-band router', 'en' => 'TP-Link dual-band router', 'ru' => 'Dual-band роутер TP-Link'],
        'price' => 90,
        'badge' => ['az' => 'Dual-band', 'en' => 'Dual-band', 'ru' => 'Dual-band'],
        'text' => [
            'az' => 'Geniş ev və ofislərdə daha stabil Wi‑Fi paylanması üçün uyğundur.',
            'en' => 'Suitable for more stable Wi‑Fi distribution in larger homes and offices.',
            'ru' => 'Подходит для более стабильного Wi‑Fi в больших домах и офисах.',
        ],
    ],
    [
        'name' => ['az' => 'HSGQ 2.4 GHz modem', 'en' => 'HSGQ 2.4 GHz modem', 'ru' => 'Модем HSGQ 2.4 GHz'],
        'price' => 65,
        'badge' => ['az' => '2.4 GHz', 'en' => '2.4 GHz', 'ru' => '2.4 GHz'],
        'text' => [
            'az' => 'Əsas istifadəyə yönəlik daha sərfəli giriş səviyyəli modemdir.',
            'en' => 'A more affordable entry-level modem focused on basic usage.',
            'ru' => 'Более доступный модем начального уровня для базового использования.',
        ],
    ],
    [
        'name' => ['az' => 'HSGQ dual-band modem', 'en' => 'HSGQ dual-band modem', 'ru' => 'Dual-band модем HSGQ'],
        'price' => 90,
        'badge' => ['az' => 'Dual-band', 'en' => 'Dual-band', 'ru' => 'Dual-band'],
        'text' => [
            'az' => '2.4 GHz və 5 GHz rejimlərini birlikdə istifadə edən abonentlər üçün uyğundur.',
            'en' => 'Suitable for subscribers using both 2.4 GHz and 5 GHz bands.',
            'ru' => 'Подходит для абонентов, использующих одновременно 2.4 GHz и 5 GHz.',
        ],
    ],
    [
        'name' => ['az' => 'BDCOM dual-band modem', 'en' => 'BDCOM dual-band modem', 'ru' => 'Dual-band модем BDCOM'],
        'price' => 90,
        'badge' => ['az' => 'Dual-band', 'en' => 'Dual-band', 'ru' => 'Dual-band'],
        'text' => [
            'az' => 'Dual-band istifadə və TV ilə birlikdə ev ssenariləri üçün uyğun seçimdir.',
            'en' => 'A suitable option for dual-band home usage and TV-inclusive setups.',
            'ru' => 'Подходящий вариант для dual-band домашнего использования и сценариев с ТВ.',
        ],
    ],
];

$copy = [
    'az' => [
        'title' => 'Cihazlar - Super Optik',
        'description' => 'Super Optik tərəfindən təqdim olunan modem və router modelləri.',
        'eyebrow' => 'Cihazlar',
        'heading' => 'Modem və router modelləri',
        'intro' => 'Aşağıdakı cihazlar qoşulma və istifadə ssenarisinə uyğun olaraq təklif olunur.',
    ],
    'en' => [
        'title' => 'Devices - Super Optik',
        'description' => 'Modem and router models provided by Super Optik.',
        'eyebrow' => 'Devices',
        'heading' => 'Modem and router models',
        'intro' => 'The following devices are offered depending on the connection and usage scenario.',
    ],
    'ru' => [
        'title' => 'Устройства - Super Optik',
        'description' => 'Модемы и роутеры Super Optik.',
        'eyebrow' => 'Устройства',
        'heading' => 'Модемы и роутеры',
        'intro' => 'Ниже указаны устройства, предлагаемые в зависимости от сценария подключения и использования.',
    ],
];

$page = $copy[$lang] ?? $copy['az'];

page_render_header($lang, $page['title'], $page['description'], 'devices');
?>
<main>
    <section class="section inner-page-hero">
        <div class="container inner-page-hero-content">
            <span class="eyebrow"><?= e($page['eyebrow']); ?></span>
            <h1><?= e($page['heading']); ?></h1>
            <p><?= e($page['intro']); ?></p>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="device-card-grid">
                <?php foreach ($devices as $device): ?>
                    <article class="device-card">
                        <span class="featured-offer-tag"><?= e($device['badge'][$lang] ?? $device['badge']['az']); ?></span>
                        <h2><?= e($device['name'][$lang] ?? $device['name']['az']); ?></h2>
                        <strong><?= e((string) $device['price']); ?> AZN</strong>
                        <p><?= e($device['text'][$lang] ?? $device['text']['az']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</main>
<?php page_render_footer($lang); ?>
