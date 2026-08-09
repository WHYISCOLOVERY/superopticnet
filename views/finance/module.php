<?php
declare(strict_types=1);

$financeTab = (string) ($financeContext['tab'] ?? 'overview');
$financeTabs = (array) ($financeContext['tabs'] ?? app_finance_tabs());
$financeLang = (string) ($financeContext['lang'] ?? 'az');
$financeTabTitle = (string) ($financeTabs[$financeTab] ?? 'Maliyyə');
?>
<section class="portal-card portal-reveal finance-module" id="finance" data-finance-module data-finance-tab="<?= e($financeTab); ?>">
    <div class="portal-card-head finance-module-head">
        <div>
            <div class="finance-breadcrumb"><span>Portal</span><i class="fa-solid fa-chevron-right"></i><span>Maliyyə</span><i class="fa-solid fa-chevron-right"></i><strong><?= e($financeTabTitle); ?></strong></div>
            <h2><?= e($financeTabTitle); ?></h2>
            <small>Maliyyə əməliyyatları ayrıca iş axınları və kontekstual hesabatlarla idarə olunur.</small>
        </div>
        <div class="portal-badge-list">
            <?php if (!empty($financeContext['data']['can_write'])): ?>
                <a class="portal-primary-btn portal-inline-link" data-finance-nav href="<?= e(app_finance_url('income', ['open'=>'income-create'], $financeLang)); ?>"><i class="fa-solid fa-plus"></i> Yeni gəlir</a>
                <a class="portal-ghost-btn portal-inline-link" data-finance-nav href="<?= e(app_finance_url('companies', ['open'=>'counterparty-create'], $financeLang)); ?>"><i class="fa-solid fa-building"></i> Yeni qarşı tərəf</a>
            <?php endif; ?>
        </div>
    </div>
    <?php
    $financeLink = static fn (string $key): array => ['key' => $key, 'label' => (string) ($financeTabs[$key] ?? $key), 'href' => app_finance_url($key, [], $financeLang)];
    $moduleNavGroups = [
        ['label' => 'Ümumi baxış', 'icon' => 'fa-gauge-high', 'links' => [$financeLink('overview')]],
        ['label' => 'Maliyyə əməliyyatları', 'icon' => 'fa-wallet', 'links' => [$financeLink('income'), $financeLink('invoices'), $financeLink('payments'), $financeLink('companies'), $financeLink('expenses')]],
        ['label' => 'Kassa, vəsait və bonus', 'icon' => 'fa-building-columns', 'links' => [$financeLink('cash-bank'), $financeLink('reconciliation'), $financeLink('assets'), $financeLink('employee-bonuses')]],
        ['label' => 'Maaş və vergilər', 'icon' => 'fa-money-check-dollar', 'links' => [$financeLink('payroll')]],
        ['label' => 'Hesabat və statistika', 'icon' => 'fa-chart-line', 'links' => [$financeLink('statistics'), $financeLink('ai-assistant')]],
    ];
    $moduleNavActive = $financeTab;
    $moduleNavAria = 'Maliyyə bölmələri';
    $moduleNavName = 'finance-nav';
    $moduleNavLinkAttribute = 'data-finance-nav';
    require dirname(__DIR__) . '/_module-group-nav.php';
    ?>
    <div data-finance-content>
        <?php require __DIR__ . '/' . $financeTab . '.php'; ?>
    </div>
</section>
