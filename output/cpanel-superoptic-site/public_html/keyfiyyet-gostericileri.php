<?php
declare(strict_types=1);

require __DIR__ . '/page-helpers.php';

$lang = app_lang();

$copy = [
    'az' => [
        'title' => 'Keyfiyyət göstəriciləri - Super Optik',
        'description' => 'Super Optik üzrə keyfiyyət göstəriciləri, qoşulma və texniki dəstək yanaşması.',
        'eyebrow' => 'Keyfiyyət',
        'heading' => 'Keyfiyyət göstəricilərinə dair məlumatlar',
        'intro' => 'Abonentə verilən vədlər ölçülə bilən və əməliyyat axınına uyğun olmalıdır. Aşağıdakı maddələr xidmət keyfiyyətinə dair praktiki çərçivəni göstərir.',
        'items' => [
            'Çağrı mərkəzinə daxil olan zənglər və rəqəmsal dəstək müraciətləri mümkün operativ müddətdə cavablandırılır.',
            'Şikayətlərin araşdırılması və nasazlığın aradan qaldırılması minimal praktik vaxt çərçivəsində təmin olunur.',
            'Standart qoşulma üçün hədəf müddət 1 təqvim günüdür; hava şəraiti və texniki faktorlar ayrıca qiymətləndirilir.',
            'Şəbəkənin modernizasiya prosesi mərhələli şəkildə davam etdirilir və 1000 Mb/s-ə qədər sürətlər təqdim edilir.',
            'Müştərilərə daha sabit Wi‑Fi təcrübəsi üçün dual-band və müasir avadanlıq seçimi təklif olunur.',
        ],
    ],
    'en' => [
        'title' => 'Quality metrics - Super Optik',
        'description' => 'Quality metrics, connection practice and support approach of Super Optik.',
        'eyebrow' => 'Quality',
        'heading' => 'Information on quality metrics',
        'intro' => 'Service commitments must be measurable and operationally realistic. The following points define the practical service-quality frame.',
        'items' => [
            'Calls and digital support requests are answered as quickly as practical.',
            'Complaint review and fault resolution are handled within the shortest workable timeframe.',
            'For standard connections, the target completion time is 1 calendar day, subject to weather and technical constraints.',
            'Network modernization continues in phases and speeds up to 1000 Mb/s are offered.',
            'Customers are offered modern dual-band equipment for a more stable Wi‑Fi experience.',
        ],
    ],
    'ru' => [
        'title' => 'Показатели качества - Super Optik',
        'description' => 'Показатели качества, подход к подключению и поддержке Super Optik.',
        'eyebrow' => 'Качество',
        'heading' => 'Информация о показателях качества',
        'intro' => 'Обещания по сервису должны быть измеримыми и операционно реалистичными. Ниже указан практический контур качества обслуживания.',
        'items' => [
            'Звонки и цифровые обращения в поддержку обрабатываются в максимально оперативные сроки.',
            'Жалобы и неисправности исследуются и устраняются в минимально возможные рабочие сроки.',
            'Для стандартных подключений целевой срок составляет 1 календарный день с учетом погоды и технических ограничений.',
            'Сеть модернизируется поэтапно, клиентам доступны скорости до 1000 Мбит/с.',
            'Для более стабильного Wi‑Fi клиентам предлагается современное dual-band оборудование.',
        ],
    ],
];

$page = $copy[$lang] ?? $copy['az'];

page_render_header($lang, $page['title'], $page['description'], 'legal');
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
            <div class="policy-card">
                <ul class="policy-list">
                    <?php foreach ($page['items'] as $item): ?>
                        <li><?= e($item); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </section>
</main>
<?php page_render_footer($lang); ?>
