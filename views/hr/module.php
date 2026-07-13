<?php
declare(strict_types=1);

$hrTab = (string) ($hrContext['tab'] ?? 'overview');
$hrTabs = (array) ($hrContext['tabs'] ?? app_hr_tabs());
$hrLang = (string) ($hrContext['lang'] ?? 'az');
$hrTabTitle = (string) ($hrTabs[$hrTab] ?? 'HR');
?>
<section class="portal-card portal-reveal hr-module" id="hr" data-hr-module data-hr-tab="<?= e($hrTab); ?>">
    <style>
        .hr-module{position:relative}.hr-module-head{align-items:flex-start}.hr-breadcrumb{display:flex;gap:7px;align-items:center;color:var(--portal-muted);font-size:.78rem;margin-bottom:6px}.hr-tabs{display:flex;gap:8px;overflow-x:auto;padding:4px 0 12px;margin:10px 0 14px;scrollbar-width:thin}.hr-tabs a{flex:0 0 auto}.hr-tabs .is-active{background:var(--portal-text);color:#fff;border-color:var(--portal-text)}.hr-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.hr-kpi{border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:14px;background:#fff}.hr-kpi span{display:block;color:var(--portal-muted);font-size:.78rem}.hr-kpi strong{display:block;margin-top:6px;font-size:1.2rem}.hr-shortcuts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.hr-shortcut{display:block;border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:13px;color:var(--portal-text);background:#f8fafc}.hr-shortcut strong,.hr-shortcut small{display:block}.hr-shortcut small{margin-top:5px;color:var(--portal-muted)}.hr-filter{display:grid;grid-template-columns:minmax(180px,1fr) repeat(2,minmax(145px,.55fr)) auto;gap:10px;align-items:end;margin:12px 0}.hr-subnav{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.hr-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px}.hr-page-links{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.hr-page-links a,.hr-page-links span{min-width:34px;text-align:center}.hr-empty{padding:24px;text-align:center;color:var(--portal-muted)}.hr-score{font-size:1rem;font-weight:800}.hr-dialog[hidden]{display:none}.hr-dialog{position:fixed;inset:0;z-index:10020;display:grid;place-items:center;padding:18px}.hr-dialog-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.56)}.hr-dialog-panel{position:relative;width:min(720px,calc(100vw - 28px));max-height:calc(100vh - 36px);display:flex;flex-direction:column;overflow:hidden;background:#fff;border-radius:8px;box-shadow:0 24px 70px rgba(15,23,42,.28)}.hr-dialog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:18px;border-bottom:1px solid rgba(148,163,184,.28);flex:0 0 auto}.hr-dialog-body{padding:18px;overflow-y:auto;overflow-x:hidden;min-width:0}.hr-dialog-close{width:38px;height:38px;flex:0 0 38px;border:0;border-radius:8px;background:#e9eef5;color:var(--portal-text);cursor:pointer}.hr-dialog input,.hr-dialog select,.hr-dialog textarea{min-width:0;max-width:100%;width:100%}.hr-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.hr-chart{border:1px solid rgba(148,163,184,.28);border-radius:8px;padding:14px;background:#fff}.hr-chart-row{display:grid;grid-template-columns:minmax(90px,.7fr) minmax(100px,1.6fr) auto;gap:9px;align-items:center;margin-top:9px}.hr-chart-track{height:9px;border-radius:999px;background:#e8edf3;overflow:hidden}.hr-chart-track b{display:block;height:100%;background:#0e848d}body.hr-dialog-open{overflow:hidden}
        @media(max-width:900px){.hr-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.hr-shortcuts{grid-template-columns:repeat(2,minmax(0,1fr))}.hr-filter{grid-template-columns:1fr 1fr}.hr-chart-grid{grid-template-columns:1fr}}
        @media(max-width:620px){.hr-kpis,.hr-shortcuts,.hr-filter{grid-template-columns:1fr}.hr-module .portal-table thead{display:none}.hr-module .portal-table,.hr-module .portal-table tbody,.hr-module .portal-table tr,.hr-module .portal-table td{display:block;width:100%}.hr-module .portal-table tr{border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:8px;margin-bottom:10px}.hr-module .portal-table td{border:0;padding:6px 8px}.hr-chart-row{grid-template-columns:1fr}.hr-dialog{padding:8px}.hr-dialog-panel{width:calc(100vw - 16px);max-height:calc(100vh - 16px)}.hr-dialog-head,.hr-dialog-body{padding:14px}.hr-dialog .portal-grid-slim{grid-template-columns:1fr}}
        @media print{.hr-tabs,.hr-module-head .portal-ghost-btn,.hr-filter,.hr-pagination,.hr-dialog,[data-hr-dialog-open]{display:none!important}.hr-module{box-shadow:none!important;border:0!important}}
        .hr-module,.hr-kpi{min-width:0}.hr-kpi strong{min-width:0;font-size:clamp(1rem,1.7vw,1.2rem);line-height:1.18;overflow-wrap:anywhere;word-break:break-word}
    </style>
    <div class="portal-card-head hr-module-head">
        <div>
            <div class="hr-breadcrumb"><span>Portal</span><i class="fa-solid fa-chevron-right"></i><span>HR</span><i class="fa-solid fa-chevron-right"></i><strong><?= e($hrTabTitle); ?></strong></div>
            <h2><?= e($hrTabTitle); ?></h2>
            <small>Emekdas, davamiyyet, maas, sened ve performans emeliyyatlari ayri is axinlarinda idare olunur.</small>
        </div>
        <div class="portal-badge-list">
            <?php if (!empty($hrContext['data']['can_write'])): ?><a class="portal-primary-btn portal-inline-link" data-hr-nav href="<?= e(app_hr_url('employees', ['open'=>'employee-create'], $hrLang)); ?>"><i class="fa-solid fa-user-plus"></i> Yeni emekdas</a><?php endif; ?>
            <a class="portal-ghost-btn portal-inline-link" href="portal.php"><i class="fa-solid fa-arrow-left"></i> Esas portal</a>
        </div>
    </div>
    <?php
    $hrLink = static fn (string $key): array => ['key' => $key, 'label' => (string) ($hrTabs[$key] ?? $key), 'href' => app_hr_url($key, [], $hrLang)];
    $moduleNavGroups = [
        ['label' => 'Umumi baxis', 'icon' => 'fa-gauge-high', 'links' => [$hrLink('overview')]],
        ['label' => 'Emekdas ve senedler', 'icon' => 'fa-users', 'links' => [$hrLink('employees'), $hrLink('documents')]],
        ['label' => 'Is ve performans', 'icon' => 'fa-id-card-clip', 'links' => [$hrLink('attendance'), $hrLink('leave-travel'), $hrLink('performance')]],
        ['label' => 'Maas ve statistika', 'icon' => 'fa-chart-line', 'links' => [$hrLink('payroll'), $hrLink('statistics')]],
    ];
    $moduleNavActive = $hrTab;
    $moduleNavAria = 'HR bolmeleri';
    $moduleNavName = 'hr-nav';
    $moduleNavLinkAttribute = 'data-hr-nav';
    require dirname(__DIR__) . '/_module-group-nav.php';
    ?>
    <div data-hr-content><?php require __DIR__ . '/' . $hrTab . '.php'; ?></div>
</section>
