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
    <style>
        .inventory-module{position:relative;min-width:0}.inventory-module-head{align-items:flex-start}.inventory-breadcrumb{display:flex;gap:7px;align-items:center;color:var(--portal-muted);font-size:.78rem;margin-bottom:6px}.inventory-command-strip{display:grid;grid-template-columns:minmax(180px,.75fr) repeat(4,minmax(0,1fr));gap:10px;align-items:stretch;margin:10px 0 14px;padding:10px;border:1px solid rgba(14,132,141,.18);border-radius:8px;background:linear-gradient(135deg,rgba(238,248,247,.94),rgba(255,248,242,.94))}.inventory-command-title{display:grid;gap:3px;align-content:center;padding:0 4px;color:var(--portal-text)}.inventory-command-title strong{font-size:.96rem}.inventory-command-title small{color:var(--portal-muted);line-height:1.3}.inventory-command-strip .portal-inline-link{min-width:0;justify-content:center;text-align:center;white-space:normal;line-height:1.25}.inventory-tabs{display:flex;gap:8px;overflow-x:auto;padding:4px 0 12px;margin:10px 0 14px;scrollbar-width:thin}.inventory-tabs a{flex:0 0 auto}.inventory-tabs .is-active,.inventory-subnav .is-active{background:var(--portal-text);color:#fff;border-color:var(--portal-text)}.inventory-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.inventory-kpi{min-width:0;border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:14px;background:#fff}.inventory-kpi span{display:block;color:var(--portal-muted);font-size:.78rem}.inventory-kpi strong{display:block;margin-top:6px;min-width:0;font-size:clamp(1rem,1.7vw,1.2rem);line-height:1.18;overflow-wrap:anywhere;word-break:break-word}.inventory-action-panel{margin-top:14px;border:1px solid rgba(14,132,141,.2);border-radius:8px;background:linear-gradient(135deg,rgba(14,132,141,.08),rgba(255,148,77,.08));padding:14px}.inventory-action-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}.inventory-action-panel-head strong,.inventory-action-card strong{display:block}.inventory-action-panel-head small,.inventory-action-card small{display:block;color:var(--portal-muted)}.inventory-action-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.inventory-action-card{display:grid;gap:8px;align-content:start;min-height:118px;border:1px solid rgba(148,163,184,.34);border-radius:8px;background:#fff;color:var(--portal-text);padding:14px;box-shadow:0 12px 26px rgba(15,23,42,.06)}.inventory-action-card i{width:34px;height:34px;display:inline-grid;place-items:center;border-radius:8px;background:#e7f5f6;color:#0e848d}.inventory-action-card.is-primary{border-color:rgba(255,148,77,.45);box-shadow:0 14px 30px rgba(255,148,77,.14)}.inventory-action-card.is-primary i{background:#fff0e3;color:#cc5d1b}.inventory-shortcuts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.inventory-shortcut{display:block;border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:13px;color:var(--portal-text);background:#f8fafc}.inventory-shortcut strong,.inventory-shortcut small{display:block}.inventory-shortcut small{margin-top:5px;color:var(--portal-muted)}.inventory-filter{display:grid;grid-template-columns:minmax(180px,1fr) repeat(2,minmax(145px,.55fr)) auto;gap:10px;align-items:end;margin:12px 0}.inventory-subnav{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.inventory-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px}.inventory-page-links{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.inventory-page-links a,.inventory-page-links span{min-width:34px;text-align:center}.inventory-empty{padding:24px;text-align:center;color:var(--portal-muted)}.inventory-dialog[hidden]{display:none}.inventory-dialog{position:fixed;inset:0;z-index:10020;display:grid;place-items:center;padding:18px}.inventory-dialog-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.56)}.inventory-dialog-panel{position:relative;width:min(760px,calc(100vw - 28px));max-height:calc(100vh - 36px);display:flex;flex-direction:column;overflow:hidden;background:#fff;border-radius:8px;box-shadow:0 24px 70px rgba(15,23,42,.28)}.inventory-dialog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:18px;border-bottom:1px solid rgba(148,163,184,.28);flex:0 0 auto}.inventory-dialog-body{padding:18px;overflow-y:auto;overflow-x:hidden;min-width:0}.inventory-dialog-close{width:38px;height:38px;flex:0 0 38px;border:0;border-radius:8px;background:#e9eef5;color:var(--portal-text);cursor:pointer}.inventory-dialog input,.inventory-dialog select,.inventory-dialog textarea{min-width:0;max-width:100%;width:100%}.inventory-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.inventory-chart{border:1px solid rgba(148,163,184,.28);border-radius:8px;padding:14px;background:#fff}.inventory-chart-row{display:grid;grid-template-columns:minmax(90px,.7fr) minmax(100px,1.6fr) auto;gap:9px;align-items:center;margin-top:9px}.inventory-chart-track{height:9px;border-radius:999px;background:#e8edf3;overflow:hidden}.inventory-chart-track b{display:block;height:100%;background:#0e848d}body.inventory-dialog-open{overflow:hidden}
        @media(max-width:1100px){.inventory-action-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.inventory-command-strip{grid-template-columns:repeat(2,minmax(0,1fr))}.inventory-command-title{grid-column:1/-1}}
        @media(max-width:900px){.inventory-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.inventory-shortcuts{grid-template-columns:repeat(2,minmax(0,1fr))}.inventory-filter{grid-template-columns:1fr 1fr}.inventory-chart-grid{grid-template-columns:1fr}}
        @media(max-width:620px){.inventory-kpis,.inventory-shortcuts,.inventory-filter,.inventory-action-grid,.inventory-command-strip{grid-template-columns:1fr}.inventory-module .portal-table thead{display:none}.inventory-module .portal-table,.inventory-module .portal-table tbody,.inventory-module .portal-table tr,.inventory-module .portal-table td{display:block;width:100%}.inventory-module .portal-table tr{border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:8px;margin-bottom:10px}.inventory-module .portal-table td{border:0;padding:6px 8px}.inventory-chart-row{grid-template-columns:1fr}.inventory-dialog{padding:8px}.inventory-dialog-panel{width:calc(100vw - 16px);max-height:calc(100vh - 16px)}.inventory-dialog-head,.inventory-dialog-body{padding:14px}.inventory-dialog .portal-grid-slim{grid-template-columns:1fr}}
    </style>
    <div class="portal-card-head inventory-module-head">
        <div><div class="inventory-breadcrumb"><span>Portal</span><i class="fa-solid fa-chevron-right"></i><span>Anbar</span><i class="fa-solid fa-chevron-right"></i><strong><?= e($inventoryTabTitle); ?></strong></div><h2><?= e($inventoryTabTitle); ?></h2><small>Stok, cihaz, kabel, tehvil ve lifecycle emeliyyatlari ayri is axinlarinda idare olunur.</small></div>
        <div class="portal-badge-list">
            <?php if (!empty($inventoryContext['data']['can_write'])): ?>
                <a class="portal-primary-btn portal-inline-link" data-inventory-nav href="<?= e(app_inventory_url('movements', ['open'=>'stock-intake'], $inventoryLang)); ?>"><i class="fa-solid fa-arrow-down"></i> Mal medaxil et</a>
                <a class="portal-ghost-btn portal-inline-link" data-inventory-nav href="<?= e(app_inventory_url('movements', ['open'=>'branch-transfer'], $inventoryLang)); ?>"><i class="fa-solid fa-right-left"></i> Filiala transfer et</a>
                <a class="portal-ghost-btn portal-inline-link" data-inventory-nav href="<?= e(app_inventory_url('approvals', ['open'=>'external-return'], $inventoryLang)); ?>"><i class="fa-solid fa-rotate-left"></i> Qaytarma yarat</a>
            <?php endif; ?>
            <a class="portal-ghost-btn portal-inline-link" href="portal.php"><i class="fa-solid fa-arrow-left"></i> Esas portal</a>
        </div>
    </div>
    <?php if (!empty($inventoryContext['data']['can_write'])): ?>
        <div class="inventory-command-strip" data-inventory-command-strip aria-label="Anbar emeliyyatlari">
            <div class="inventory-command-title">
                <strong>Anbar emeliyyatlari</strong>
                <small>Mal qebulu, transfer, tehvil ve qaytarma bir klikle acilir.</small>
            </div>
            <a class="portal-primary-btn portal-inline-link" data-inventory-nav href="<?= e(app_inventory_url('movements', ['open'=>'stock-intake'], $inventoryLang)); ?>"><i class="fa-solid fa-arrow-down"></i> Mal medaxil et</a>
            <a class="portal-ghost-btn portal-inline-link" data-inventory-nav href="<?= e(app_inventory_url('movements', ['open'=>'branch-transfer'], $inventoryLang)); ?>"><i class="fa-solid fa-right-left"></i> Filiala transfer et</a>
            <a class="portal-ghost-btn portal-inline-link" data-inventory-nav href="<?= e(app_inventory_url('handover', ['open'=>'handover-create'], $inventoryLang)); ?>"><i class="fa-solid fa-screwdriver-wrench"></i> Texnike tehvil ver</a>
            <a class="portal-ghost-btn portal-inline-link" data-inventory-nav href="<?= e(app_inventory_url('approvals', ['open'=>'external-return'], $inventoryLang)); ?>"><i class="fa-solid fa-rotate-left"></i> Qaytarma yarat</a>
        </div>
    <?php endif; ?>
    <?php
    $inventoryLink = static fn (string $key): array => ['key' => $key, 'label' => (string) ($inventoryTabs[$key] ?? $key), 'href' => app_inventory_url($key, [], $inventoryLang)];
    $moduleNavGroups = [
        ['label' => 'Umumi baxis', 'icon' => 'fa-gauge-high', 'links' => [$inventoryLink('overview')]],
        ['label' => 'Kataloq ve cihazlar', 'icon' => 'fa-boxes-stacked', 'links' => [$inventoryLink('catalog'), $inventoryLink('devices'), $inventoryLink('materials'), $inventoryLink('cable-drums')]],
        ['label' => 'Medaxil ve transfer', 'icon' => 'fa-right-left', 'links' => [$inventoryLink('movements'), $inventoryLink('handover'), $inventoryLink('approvals')]],
        ['label' => 'Tehvil ve assignment', 'icon' => 'fa-users', 'links' => [$inventoryLink('technicians'), $inventoryLink('customers')]],
        ['label' => 'Lifecycle ve hesabat', 'icon' => 'fa-chart-line', 'links' => [$inventoryLink('returns-inspection'), $inventoryLink('repair-rma'), $inventoryLink('statistics')]],
    ];
    $moduleNavActive = $inventoryTab;
    $moduleNavAria = 'Anbar bolmeleri';
    $moduleNavName = 'inventory-nav';
    $moduleNavLinkAttribute = 'data-inventory-nav';
    require dirname(__DIR__) . '/_module-group-nav.php';
    ?>
    <div data-inventory-content><?php require __DIR__ . '/' . $inventoryTab . '.php'; ?></div>
</section>
