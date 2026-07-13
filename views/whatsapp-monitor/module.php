<?php
declare(strict_types=1);

$monitorView = (string) ($context['view'] ?? 'overview');
$monitorTabs = (array) ($context['tabs'] ?? whatsapp_monitor_tabs());
$monitorLang = (string) ($context['lang'] ?? 'az');
$monitorTitle = (string) ($monitorTabs[$monitorView] ?? 'WhatsApp Monitorinq');
$monitorLink = static fn (string $key): array => [
    'key' => $key,
    'label' => (string) ($monitorTabs[$key] ?? $key),
    'href' => whatsapp_monitor_url($key, [], $monitorLang),
];
?>
<section class="portal-card portal-reveal whatsapp-monitor-module" data-whatsapp-monitor-module data-monitor-view="<?= e($monitorView); ?>">
    <div class="portal-card-head wa-monitor-head">
        <div>
            <div class="wa-breadcrumb"><span>Portal</span><i class="fa-solid fa-chevron-right"></i><span>CRM</span><i class="fa-solid fa-chevron-right"></i><strong><?= e($monitorTitle); ?></strong></div>
            <h2><?= e($monitorTitle); ?></h2>
            <small>Sirket nomreleri ve cihazlari ucun raziliqli, auditli ve permission-lu monitorinq.</small>
        </div>
        <div class="portal-badge-list">
            <a class="portal-ghost-btn portal-inline-link" href="portal.php"><i class="fa-solid fa-arrow-left"></i> Esas portal</a>
        </div>
    </div>
    <div class="wa-command-strip" aria-label="WhatsApp Monitor emeliyyatlari">
        <div class="wa-command-title">
            <strong>Tez emeliyyatlar</strong>
            <small>Cihaz, nomre, canli monitor ve case axinlari ayridir.</small>
        </div>
        <a class="portal-primary-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('screen-wall', [], $monitorLang)); ?>"><i class="fa-solid fa-table-cells-large"></i> Canli monitor</a>
        <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('chats', [], $monitorLang)); ?>"><i class="fa-solid fa-comments"></i> Catlar</a>
        <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('cases', [], $monitorLang)); ?>"><i class="fa-solid fa-triangle-exclamation"></i> Case yarat</a>
        <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('pairing', [], $monitorLang)); ?>"><i class="fa-solid fa-qrcode"></i> QR bagla</a>
        <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('devices', [], $monitorLang)); ?>"><i class="fa-solid fa-mobile-screen"></i> Cihazlar</a>
        <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('numbers', [], $monitorLang)); ?>"><i class="fa-brands fa-whatsapp"></i> Nomreler</a>
    </div>
    <?php
    $moduleNavGroups = [
        ['label' => 'Umumi baxis', 'icon' => 'fa-gauge-high', 'links' => [$monitorLink('overview')]],
        ['label' => 'Monitorinq', 'icon' => 'fa-display', 'links' => [$monitorLink('screen-wall'), $monitorLink('chats'), $monitorLink('cases')]],
        ['label' => 'Reyestr', 'icon' => 'fa-list-check', 'links' => [$monitorLink('numbers'), $monitorLink('pairing'), $monitorLink('devices')]],
        ['label' => 'Hesabat ve nezaret', 'icon' => 'fa-chart-line', 'links' => [$monitorLink('performance'), $monitorLink('settings'), $monitorLink('audit')]],
    ];
    $moduleNavActive = $monitorView;
    $moduleNavAria = 'WhatsApp Monitor bolmeleri';
    $moduleNavName = 'whatsapp-monitor-nav';
    $moduleNavLinkAttribute = '';
    require dirname(__DIR__) . '/_module-group-nav.php';
    require __DIR__ . '/' . $monitorView . '.php';
    ?>
</section>
