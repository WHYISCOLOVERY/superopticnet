<?php
declare(strict_types=1);

$financeTab = (string) ($financeContext['tab'] ?? 'overview');
$financeTabs = (array) ($financeContext['tabs'] ?? app_finance_tabs());
$financeLang = (string) ($financeContext['lang'] ?? 'az');
$financeTabTitle = (string) ($financeTabs[$financeTab] ?? 'Maliyye');
?>
<section class="portal-card portal-reveal finance-module" id="finance" data-finance-module data-finance-tab="<?= e($financeTab); ?>">
    <style>
        .finance-module{position:relative}.finance-module-head{align-items:flex-start}.finance-breadcrumb{display:flex;gap:7px;align-items:center;color:var(--portal-muted);font-size:.78rem;margin-bottom:6px}.finance-tabs{display:flex;gap:8px;overflow-x:auto;padding:4px 0 12px;margin:10px 0 14px;scrollbar-width:thin}.finance-tabs a{flex:0 0 auto}.finance-tabs .is-active{background:var(--portal-text);color:#fff;border-color:var(--portal-text)}.finance-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.finance-kpi{border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:14px;background:#fff}.finance-kpi span{display:block;color:var(--portal-muted);font-size:.78rem}.finance-kpi strong{display:block;margin-top:6px;font-size:1.25rem}.finance-shortcuts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.finance-shortcut{display:block;border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:13px;color:var(--portal-text);background:#f8fafc}.finance-shortcut strong,.finance-shortcut small{display:block}.finance-shortcut small{margin-top:5px;color:var(--portal-muted)}.finance-filter{display:grid;grid-template-columns:minmax(180px,1fr) repeat(2,minmax(150px,.5fr)) auto;gap:10px;align-items:end;margin:12px 0}.finance-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px}.finance-page-links{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.finance-page-links a,.finance-page-links span{min-width:34px;text-align:center}.finance-dialog[hidden]{display:none}.finance-dialog{position:fixed;inset:0;z-index:10020;display:grid;place-items:center;padding:18px}.finance-dialog-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.56)}.finance-dialog-panel{position:relative;width:min(680px,calc(100vw - 28px));max-height:calc(100vh - 36px);overflow:auto;background:#fff;border-radius:8px;padding:18px;box-shadow:0 24px 70px rgba(15,23,42,.28)}.finance-dialog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}.finance-dialog-close{width:38px;height:38px;border:0;border-radius:8px;background:#e9eef5;color:var(--portal-text);cursor:pointer}.finance-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.finance-chart{border:1px solid rgba(148,163,184,.28);border-radius:8px;padding:14px;background:#fff}.finance-chart-row{display:grid;grid-template-columns:minmax(90px,.7fr) minmax(100px,1.6fr) auto;gap:9px;align-items:center;margin-top:9px}.finance-chart-track{height:9px;border-radius:999px;background:#e8edf3;overflow:hidden}.finance-chart-track b{display:block;height:100%;background:#0e848d}.finance-subnav{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.finance-empty{padding:24px;text-align:center;color:var(--portal-muted)}body.finance-dialog-open{overflow:hidden}
        @media(max-width:900px){.finance-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-shortcuts{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-filter{grid-template-columns:1fr 1fr}.finance-chart-grid{grid-template-columns:1fr}}
        @media(max-width:620px){.finance-kpis,.finance-shortcuts,.finance-filter{grid-template-columns:1fr}.finance-module .portal-table thead{display:none}.finance-module .portal-table,.finance-module .portal-table tbody,.finance-module .portal-table tr,.finance-module .portal-table td{display:block;width:100%}.finance-module .portal-table tr{border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:8px;margin-bottom:10px}.finance-module .portal-table td{border:0;padding:6px 8px}.finance-chart-row{grid-template-columns:1fr}.finance-dialog{padding:8px}.finance-dialog-panel{width:calc(100vw - 16px);max-height:calc(100vh - 16px)}}
        .finance-module,.finance-kpi{min-width:0}.finance-kpi strong{min-width:0;font-size:clamp(1rem,1.7vw,1.25rem);line-height:1.18;overflow-wrap:anywhere;word-break:break-word}
    </style>
    <div class="portal-card-head finance-module-head">
        <div>
            <div class="finance-breadcrumb"><span>Portal</span><i class="fa-solid fa-chevron-right"></i><span>Maliyye</span><i class="fa-solid fa-chevron-right"></i><strong><?= e($financeTabTitle); ?></strong></div>
            <h2><?= e($financeTabTitle); ?></h2>
            <small>Maliyye emeliyyatlari ayrica is axinlari ve kontekstual hesabatlarla idare olunur.</small>
        </div>
        <div class="portal-badge-list">
            <?php if (!empty($financeContext['data']['can_write'])): ?>
                <a class="portal-primary-btn portal-inline-link" data-finance-nav href="<?= e(app_finance_url('income', ['open'=>'income-create'], $financeLang)); ?>"><i class="fa-solid fa-plus"></i> Yeni gelir</a>
                <a class="portal-ghost-btn portal-inline-link" data-finance-nav href="<?= e(app_finance_url('companies', ['open'=>'counterparty-create'], $financeLang)); ?>"><i class="fa-solid fa-building"></i> Yeni qarsi teref</a>
            <?php endif; ?>
            <a class="portal-ghost-btn portal-inline-link" href="portal.php"><i class="fa-solid fa-arrow-left"></i> Esas portal</a>
        </div>
    </div>
    <?php
    $financeLink = static fn (string $key): array => ['key' => $key, 'label' => (string) ($financeTabs[$key] ?? $key), 'href' => app_finance_url($key, [], $financeLang)];
    $moduleNavGroups = [
        ['label' => 'Umumi baxis', 'icon' => 'fa-gauge-high', 'links' => [$financeLink('overview')]],
        ['label' => 'Maliyye emeliyyatlari', 'icon' => 'fa-wallet', 'links' => [$financeLink('income'), $financeLink('invoices'), $financeLink('payments'), $financeLink('companies'), $financeLink('expenses')]],
        ['label' => 'Kassa, vesait ve bonus', 'icon' => 'fa-building-columns', 'links' => [$financeLink('cash-bank'), $financeLink('reconciliation'), $financeLink('assets'), $financeLink('employee-bonuses')]],
        ['label' => 'Hesabat ve statistika', 'icon' => 'fa-chart-line', 'links' => [$financeLink('statistics')]],
    ];
    $moduleNavActive = $financeTab;
    $moduleNavAria = 'Maliyye bolmeleri';
    $moduleNavName = 'finance-nav';
    $moduleNavLinkAttribute = 'data-finance-nav';
    require dirname(__DIR__) . '/_module-group-nav.php';
    ?>
    <div data-finance-content>
        <?php require __DIR__ . '/' . $financeTab . '.php'; ?>
    </div>
</section>
