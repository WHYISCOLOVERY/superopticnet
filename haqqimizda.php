<?php
declare(strict_types=1);

require __DIR__ . '/page-helpers.php';

$lang = app_lang();

$copy = [
    'az' => [
        'title' => 'Haqqımızda - Super Optik',
        'description' => 'Super Optik haqqında, missiya və məqsədlər və əsas dəyərlər.',
        'eyebrow' => 'Haqqımızda',
        'heading' => 'Missiya və məqsədlər',
        'intro' => 'Super Optik Tech müştəri məmnuniyyətini, sabit infrastrukturun qurulmasını və texniki dəstəyin operativ təşkilini əsas fəaliyyət prinsipi kimi saxlayır. Məqsədimiz abonentlərə və biznes tərəfdaşlarına dayanıqlı internet, TV və sistem həlləri təqdim etməkdir.',
        'values' => [
            [
                'icon' => 'handshake',
                'title' => 'Müştəriyönümlülük',
                'text' => 'Qərarlarımızı istifadə rahatlığı, xidmətin aydınlığı və operativ geridönüş prinsipi ilə qururuq. Sadə qoşulma prosesi və şəffaf xidmət qaydaları əsas prioritetdir.',
            ],
            [
                'icon' => 'shield',
                'title' => 'Yenilikçilik',
                'text' => 'Fiber infrastruktur, dual-band modemlər, TV xidmətləri və biznes üçün şəbəkə həllərini praktik ehtiyaca görə yeniləyirik. Məqsəd texnologiyanı sadəcə göstərmək yox, işlək etməkdir.',
            ],
            [
                'icon' => 'eye',
                'title' => 'Etibarlılıq',
                'text' => 'Şəbəkə sabitliyi, keyfiyyətli dəstək, aydın ödəniş qaydası və hüquqi şəffaflıq brendin etibar hissəsini formalaşdırır. Super Optik bu xətti qoruyur.',
            ],
        ],
    ],
    'en' => [
        'title' => 'About - Super Optik',
        'description' => 'About Super Optik, mission and goals and core values.',
        'eyebrow' => 'About',
        'heading' => 'Mission and goals',
        'intro' => 'Super Optik Tech keeps customer satisfaction, stable infrastructure and responsive technical support as core operating principles. Our goal is to provide reliable internet, TV and system solutions for subscribers and business clients.',
        'values' => [
            [
                'icon' => 'handshake',
                'title' => 'Customer focus',
                'text' => 'We build decisions around ease of use, service clarity and fast response. A simple connection process and transparent service rules remain core priorities.',
            ],
            [
                'icon' => 'shield',
                'title' => 'Innovation',
                'text' => 'We update fiber infrastructure, dual-band modem choices, TV services and business network solutions based on practical demand. The goal is not to show technology, but to make it work.',
            ],
            [
                'icon' => 'eye',
                'title' => 'Reliability',
                'text' => 'Network stability, quality support, clear payment rules and legal transparency build brand trust. Super Optik keeps that line.',
            ],
        ],
    ],
    'ru' => [
        'title' => 'О компании - Super Optik',
        'description' => 'О компании Super Optik, миссия, цели и ключевые ценности.',
        'eyebrow' => 'О компании',
        'heading' => 'Миссия и цели',
        'intro' => 'Super Optik Tech рассматривает удовлетворенность клиентов, стабильную инфраструктуру и оперативную техническую поддержку как базовые принципы работы. Наша цель — предоставлять надежный интернет, ТВ и системные решения для абонентов и бизнеса.',
        'values' => [
            [
                'icon' => 'handshake',
                'title' => 'Клиентоориентированность',
                'text' => 'Мы строим решения вокруг удобства использования, понятности сервиса и быстрого ответа. Простое подключение и прозрачные правила обслуживания остаются приоритетом.',
            ],
            [
                'icon' => 'shield',
                'title' => 'Инновационность',
                'text' => 'Мы обновляем fiber-инфраструктуру, выбор dual-band модемов, ТВ-сервисы и сетевые решения для бизнеса по практической потребности. Цель — не показывать технологию, а делать ее рабочей.',
            ],
            [
                'icon' => 'eye',
                'title' => 'Надежность',
                'text' => 'Стабильность сети, качественная поддержка, понятные правила оплаты и юридическая прозрачность формируют доверие к бренду. Super Optik держит эту линию.',
            ],
        ],
    ],
];

$page = $copy[$lang] ?? $copy['az'];

page_render_header($lang, $page['title'], $page['description'], 'about');
?>
<main id="main">
    <section class="section inner-page-hero">
        <div class="container inner-page-hero-content">
            <span class="eyebrow"><?= e($page['eyebrow']); ?></span>
            <h1><?= e($page['heading']); ?></h1>
            <p><?= e($page['intro']); ?></p>
        </div>
    </section>

    <section class="section" id="mission">
        <div class="container">
            <div class="info-card-grid">
                <?php foreach ($page['values'] as $item): ?>
                    <article class="info-card">
                        <div class="info-card-icon"><?= page_icon((string) $item['icon']); ?></div>
                        <h2><?= e($item['title']); ?></h2>
                        <p><?= e($item['text']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php page_render_cta($lang); ?>
        </div>
    </section>
</main>
<?php page_render_footer($lang); ?>
