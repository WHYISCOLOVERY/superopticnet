<?php
declare(strict_types=1);

$hrTab = (string) ($hrContext['tab'] ?? 'overview');
$hrTabs = (array) ($hrContext['tabs'] ?? app_hr_tabs());
$hrLang = (string) ($hrContext['lang'] ?? 'az');
$hrTabTitle = (string) ($hrTabs[$hrTab] ?? 'HR');
?>
<section class="portal-card portal-reveal hr-module" id="hr" data-hr-module data-hr-tab="<?= e($hrTab); ?>">
    <div class="portal-card-head hr-module-head">
        <div>
            <div class="hr-breadcrumb"><span>Portal</span><i class="fa-solid fa-chevron-right"></i><span>HR</span><i class="fa-solid fa-chevron-right"></i><strong><?= e($hrTabTitle); ?></strong></div>
            <h2><?= e($hrTabTitle); ?></h2>
            <small>Əməkdaş, davamiyyət, maaş, sənəd və performans əməliyyatları ayrı iş axınlarında idarə olunur.</small>
        </div>
        <div class="portal-badge-list">
            <?php if (!empty($hrContext['data']['can_write'])): ?><a class="portal-primary-btn portal-inline-link" data-hr-nav href="<?= e(app_hr_url('employees', ['open'=>'employee-create'], $hrLang)); ?>"><i class="fa-solid fa-user-plus"></i> Yeni əməkdaş</a><?php endif; ?>
        </div>
    </div>
    <?php
    $hrLink = static fn (string $key): array => ['key' => $key, 'label' => (string) ($hrTabs[$key] ?? $key), 'href' => app_hr_url($key, [], $hrLang)];
    $moduleNavGroups = [
        ['label' => 'Ümumi baxış', 'icon' => 'fa-gauge-high', 'links' => [$hrLink('overview')]],
        ['label' => 'Əməkdaş və sənədlər', 'icon' => 'fa-users', 'links' => [$hrLink('employees'), $hrLink('documents')]],
        ['label' => 'İş və performans', 'icon' => 'fa-id-card-clip', 'links' => [$hrLink('attendance'), $hrLink('leave-travel'), $hrLink('performance')]],
        ['label' => 'Statistika', 'icon' => 'fa-chart-line', 'links' => [$hrLink('statistics')]],
    ];
    $moduleNavActive = $hrTab;
    $moduleNavAria = 'HR bölmələri';
    $moduleNavName = 'hr-nav';
    $moduleNavLinkAttribute = 'data-hr-nav';
    require dirname(__DIR__) . '/_module-group-nav.php';
    ?>
    <div data-hr-content><?php require __DIR__ . '/' . $hrTab . '.php'; ?></div>
</section>
