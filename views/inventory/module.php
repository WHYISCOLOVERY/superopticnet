<?php
declare(strict_types=1);

$inventoryTab = (string) ($inventoryContext['tab'] ?? 'overview');
$inventoryTabs = (array) ($inventoryContext['tabs'] ?? app_inventory_tabs());
$inventoryLang = (string) ($inventoryContext['lang'] ?? 'az');
$inventoryTabTitle = (string) ($inventoryTabs[$inventoryTab] ?? 'Anbar');
$inventoryFormat = static function (mixed $value, string $kind = 'quantity'): string {
    $number = (float) $value;
    if ($kind === 'count') {
        return number_format($number, 0, '.', ' ');
    }
    if ($kind === 'meters') {
        return number_format($number, 2, '.', ' ') . ' m';
    }
    return number_format($number, 2, '.', ' ');
};
?>
<section class="portal-card portal-reveal inventory-module" id="inventory" data-inventory-module data-inventory-tab="<?= e($inventoryTab); ?>">
    <div class="portal-card-head inventory-module-head">
        <div><div class="inventory-breadcrumb"><span>Portal</span><i class="fa-solid fa-chevron-right"></i><span>Anbar</span><i class="fa-solid fa-chevron-right"></i><strong><?= e($inventoryTabTitle); ?></strong></div><h2><?= e($inventoryTabTitle); ?></h2><small>Stok, cihaz, kabel, təhvil və istismar dövrü əməliyyatları ayrı iş axınlarında idarə olunur.</small></div>
    </div>
    <?php
    $inventoryLink = static fn (string $key): array => ['key' => $key, 'label' => (string) ($inventoryTabs[$key] ?? $key), 'href' => app_inventory_url($key, [], $inventoryLang)];
    $moduleNavGroups = [
        ['label' => 'Ümumi baxış', 'icon' => 'fa-gauge-high', 'links' => [$inventoryLink('overview')]],
        ['label' => 'Kataloq və cihazlar', 'icon' => 'fa-boxes-stacked', 'links' => [$inventoryLink('catalog'), $inventoryLink('devices'), $inventoryLink('materials'), $inventoryLink('cable-drums')]],
        ['label' => 'Mədaxil və transfer', 'icon' => 'fa-right-left', 'links' => [$inventoryLink('movements'), $inventoryLink('handover'), $inventoryLink('approvals')]],
        ['label' => 'Təhvil və təyinat', 'icon' => 'fa-users', 'links' => [$inventoryLink('technicians'), $inventoryLink('customers')]],
        ['label' => 'İstismar dövrü və hesabat', 'icon' => 'fa-chart-line', 'links' => [$inventoryLink('returns-inspection'), $inventoryLink('repair-rma'), $inventoryLink('statistics')]],
    ];
    $moduleNavActive = $inventoryTab;
    $moduleNavAria = 'Anbar bölmələri';
    $moduleNavName = 'inventory-nav';
    $moduleNavLinkAttribute = 'data-inventory-nav';
    require dirname(__DIR__) . '/_module-group-nav.php';
    ?>
    <div data-inventory-content><?php require __DIR__ . '/' . $inventoryTab . '.php'; ?></div>
</section>
